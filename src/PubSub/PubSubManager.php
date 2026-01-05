<?php

namespace SIG\Server\PubSub;

use Psr\Log\LoggerInterface;
use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Config\MethodConfig;
use SIG\Server\Exception\AuthenticationException;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\ProtocolManagerInterface;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\Protocol\Response\Type;
use Swoole\Http\Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class PubSubManager implements ProtocolManagerInterface
{
    public Table $subscribers;
    public Table $channels;
    private array $channelsQueue = [];
    public function __construct(
        private readonly Fluxus           $server,
        public readonly Action            $protocol,
        public readonly Type              $responses,
        private readonly ?LoggerInterface $logger = null
    )
    {
        $this->initPubSubTables();
        $this->registerProtocolHandlers();

    }

    private function initPubSubTables(): void
    {
        // Tabla para suscriptores
        $this->subscribers = new Table(4096);
        $this->subscribers->column('fd', Table::TYPE_INT);
        $this->subscribers->column('channels', Table::TYPE_STRING, 255);
        $this->subscribers->create();
        $this->logger?->debug('Tabla de suscriptores creada');
        // Tabla para canales activos
        $this->channels = new Table(1024);
        $this->channels->column('name', Table::TYPE_STRING, 255);
        $this->channels->column('auto_subscribe', Table::TYPE_INT, 1);
        $this->channels->column('subscriber_count', Table::TYPE_INT);
        $this->channels->column('created_at', Table::TYPE_INT);
        $this->channels->column('last_message_at', Table::TYPE_INT);
        $this->channels->column('last_message_fd', Table::TYPE_INT);
        $this->channels->column('requires_auth', Table::TYPE_INT, 1);
        $this->channels->column('requires_role', Table::TYPE_STRING, 255);
        $this->channels->column('persists_on_empty', Table::TYPE_INT, 1);
        $this->channels->create();
        while ($arguments = array_shift($this->channelsQueue)) {
            $this->addChannel(...$arguments);
        }
        $this->logger?->debug('Tabla de canales creada');
    }
    public function registerProtocolHandlers(): void
    {
        $protocol = $this->protocol->toArray();
        foreach ($protocol as $action) {
            $this->logger->debug('❇️ ❇️ ❇️ ADDING Protocol Handler: ' . $action . ' ❇️ ❇️ ❇️');
            $this->server->registerMessageHandler($action, [$this, 'handleMessages']);
        }
    }
    public function initializeOnStart(): void
    {
        // TODO: Implement initializeOnStart() method.
    }

    public function initializeOnWorkers():void
    {
        // TODO: Implement initializeOnWorkers() method.
    }

    public function handleMessages(Server $server, Frame $frame): void
    {

        $data = json_decode($frame->data, true);
        if (!$data || !isset($data['action'])) {
            $server->sendError($frame->fd, 'El mensaje no puede procesarse: '.$frame->data);
            return;
        }
        $workerId = $server->getWorkerId();
        $this->logger?->debug("Mensaje recibido en worker #{$workerId} de FD {$frame->fd}: " . $frame->data);
        $protocol = $this->protocol->getProtocolFor($data);
        $this->logger?->debug("Protocolo de solicitud: " . get_class($protocol));
        if ($protocol instanceof RequestHandlerInterface) {
            $protocol->handle($frame->fd, $this->server);
        } else {
            $server->sendError($frame->fd, 'Acción no reconocida: ' . $data['action']);
        }
    }

    public function handleRedisMessage(string $channel, string $message): void
    {
        try {
            $data = json_decode($message, true);
            if (!$data) {
                $this->logger?->warning('Mensaje Redis no válido: ' . $message);
                return;
            }

            $channelName = $data['channel'] ?? str_replace($this->server->redisChannelPrefix . '.', '', $channel);
            $metadata = $data['_metadata'] ?? [];
            // Solo transmitir si el mensaje no viene de este servidor
            if (!isset($metadata['origin_server']) || $metadata['origin_server'] !== $this->server->getServerId()) {
                $this->broadcastToChannel($channelName, $data);
                $this->logger?->debug("Mensaje Redis transmitido a canal: $channelName");
            } else {
                $this->logger?->debug("Mensaje Redis no transmitido a canal: $channelName (mismo servidor?) [$message]");
            }

        } catch (\Exception $e) {
            $this->logger?->error('Error procesando mensaje Redis: ' . $e->getMessage());
        }
    }

    /**
     * Maneja suscripción a un canal
     * private function handleSubscribe(int $fd, string $channel): void
     * {
     * if (empty($channel)) {
     * $this->sendError($fd, 'Nombre de canal requerido');
     * return;
     * }
     * try {
     * $this->subscribeToChannel($fd, $channel);
     * $this->sendSuccess($fd, "Suscrito al canal: $channel");
     * } catch (\Throwable $e) {
     * $this->logger?->error("Error en conexión #$fd suscribiendo al canal $channel: {$e->getMessage()}");
     * $this->sendError($fd, $e->getMessage());
     * }
     * }
     */
    public function addChannel(string $channel, bool $requireAuth = false, ?string $requireRole = null, bool $autoSubscribe = false, bool $persists = false): void
    {
        if (!$this->server->isRunning()) {
            $this->channelsQueue[] = [$channel, $requireAuth, $requireRole, $autoSubscribe];
            return;
        }
        $this->channels->set($channel, [
            'name' => $channel,
            'auto_subscribe' => $autoSubscribe ? 1 : 0,
            'subscriber_count' => 0,
            'created_at' => time(),
            'requires_auth' => $requireAuth ? 1 : 0,
            'requires_role' => $requireRole,
            'persists_on_empty' => $persists ? 1 : 0,
        ]);
        if ($autoSubscribe) {
            foreach ($this->server->connections as $fd) {
                try {
                    $this->subscribeToChannel($fd, $channel);
                } catch (\Throwable $e) {
                    $this->logger?->error("Error (silent) al suscribir al canal $channel al cliente $fd: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Suscribe un cliente a un canal (crea el canal si no existe)
     * @throws AuthenticationException
     */
    public function subscribeToChannel(int $fd, string $channel): void
    {
        if($this->channels->getMemorySize() === 0) {
            $this->initPubSubTables();
        }
        // Verificar si el canal existe, si no, crearlo
        if (!$this->channels->exist($channel)) {
            $this->addChannel($channel);
            $this->logger?->info("Canal creado: $channel");
        }

        // Agregar suscriptor al canal
        $channelInfo = $this->channels->get($channel);

        if ($channelInfo['requires_auth'] === 1) {
            if (!$this->server->isAuthenticated($fd)) {
                throw new AuthenticationException('Channel is only for authenticated users');
            }
            if (!empty($channelInfo['requires_role']) && !in_array($channelInfo['requires_role'], $this->userRoles($fd))) {
                throw new AuthenticationException('User does not have required role');
            }
        }

        $this->channels->set($channel, [
            'name' => $channel,
            'auto_subscribe' => $channelInfo['auto_subscribe'],
            'subscriber_count' => $channelInfo['subscriber_count'] + 1,
            'created_at' => $channelInfo['created_at'],
            'last_message_at' => $channelInfo['last_message_at'] ?? null,
            'last_message_fd' => $channelInfo['last_message_fd'] ?? null,
            'requires_auth' => $channelInfo['requires_auth'] ?? false,
            'requires_role' => $channelInfo['requires_role'] ?? null,
            'persists_on_empty' => $channelInfo['persists_on_empty'] ?? false,

        ]);

        // Agregar canal al cliente
        $subscriber = $this->subscribers->get($fd);
        $this->logger?->debug("Suscribiendo FD $fd al canal: $channel. | Existing channels: " . var_export($subscriber, true));
        $channels = $subscriber ? json_decode($subscriber['channels'], true) : [];

        if (!in_array($channel, $channels)) {
            $channels[] = $channel;
            $this->subscribers->set($fd, [
                'fd' => $fd,
                'channels' => json_encode($channels)
            ]);
        }

        $this->logger?->info("FD $fd suscrito al canal: $channel");

        // NOTA: No necesitamos suscribirnos aquí porque el subscriber general
        // ya está escuchando todos los canales con el patrón 'ws_channel:*'
        // La suscripción en Redis se maneja automáticamente con psubscribe
    }

    /**
     * Desuscribe un cliente de un canal
     */
    public function unsubscribeFromChannel(int $fd, string $channel): void
    {
        // Remover canal del cliente
        $subscriber = $this->subscribers->get($fd);
        if ($subscriber) {
            $channels = json_decode($subscriber['channels'], true) ?? [];
            $channels = array_filter($channels, fn($c) => $c !== $channel);

            $this->subscribers->set($fd, [
                'fd' => $fd,
                'channels' => json_encode(array_values($channels))
            ]);
        }

        // Actualizar contador del canal
        if ($this->channels->exist($channel)) {
            $channelInfo = $this->channels->get($channel);
            $newCount = max(0, $channelInfo['subscriber_count'] - 1);

            if ($newCount === 0 && !($channelInfo['persists_on_empty'] ?? 0)) {
                // Eliminar canal si no hay suscriptores
                $this->channels->del($channel);
                $this->logger?->info("Canal eliminado: $channel (poe: {$channelInfo['persists_on_empty']})");
            } else {
                $this->channels->set($channel, [
                    'name' => $channel,
                    'auto_subscribe' => $channelInfo['auto_subscribe'],
                    'subscriber_count' => $newCount,
                    'created_at' => $channelInfo['created_at'],
                    'last_message_at' => $channelInfo['last_message_at'],
                    'last_message_fd' => $channelInfo['last_message_fd'],
                    'requires_auth' => $channelInfo['requires_auth'],
                    'requires_role' => $channelInfo['requires_role'],
                    'persists_on_empty' => $channelInfo['persists_on_empty'],
                ]);
            }
        }

        $this->logger?->info("FD $fd desuscrito del canal: $channel");
    }

    /**
     * Transmite un mensaje a todos los suscriptores de un canal
     * Retorna el número de clientes que recibieron el mensaje
     */
    public function broadcastToChannel(string $channel, array $message): int
    {
        $messageJson = json_encode($message);
        $sentCount = 0;

        foreach ($this->subscribers as $subscriber) {
            $channels = $subscriber ? json_decode($subscriber['channels'], true) : [];

            if (in_array($channel, $channels)) {
                $fd = $subscriber['fd'];
                if ($this->server->isEstablished($fd) && $this->server->push($fd, $messageJson)) {
                    $sentCount++;
                } else {
                    // Cliente desconectado, limpiar suscripciones
                    $this->cleanupClient($fd);
                }
            }
        }

        return $sentCount;
    }

    /**
     * Limpia las suscripciones de un cliente desconectado
     */
    private function cleanupClient(int $fd): void
    {
        if($this->subscribers->getMemorySize() === 0) {
            return;
        }
        $subscriber = $this->subscribers->get($fd);
        if ($subscriber) {
            $channels = json_decode($subscriber['channels'], true);
            foreach ($channels as $channel) {
                $this->unsubscribeFromChannel($fd, $channel);
            }

            $this->subscribers->del($fd);
        }
    }
    public function cleanUpResources(): void
    {
        $workerId = $this->server->getWorkerId();
        $this->logger?->info(" #$workerId Cerrando conexiones de clientes...");
        if (isset($this->subscribers)) {
            $closedCount = 0;
            foreach ($this->subscribers as $key => $subscriber) {
                $fd = $subscriber['fd'];
                if ($this->server->isEstablished($fd)) {
                    try {
                        $this->server->close($fd);
                        $closedCount++;
                        $this->subscribers->del($key);
                    } catch (\Exception $e) {
                        // Ignorar errores de clientes ya desconectados
                    }
                }
            }
            $this->logger?->info("#$workerId -> $closedCount conexiones de clientes cerradas");
        }


        //$this->subscribers->destroy();
        //$this->channels->destroy();
        $this->logger?->debug("#$workerId Tablas de canales y suscriptores eliminadas");
    }

    public function registerRpcMethods(MethodsCollection $collection): void
    {
        // TODO: Implement registerRpcMethods() method.
    }

    public function registerRpcMethod(MethodConfig $method): bool
    {
        // TODO: Implement registerRpcMethod() method.
    }

    public function runOnOpenConnection(Server $server, Request $request): void
    {
        if($this->subscribers->getMemorySize() === 0) {
            $this->initPubSubTables();
        }
        $fd = $request->fd;
        $this->logger?->info("Cliente conectado: FD $fd");
        // Inicializar suscriptor
        $this->subscribers->set($fd, [
            'fd' => $fd,
            'channels' => json_encode([])
        ]);

        foreach ($this->channels as $channel) {
            if ($channel['auto_subscribe'] === 1) {
                try {
                    $this->subscribeToChannel($fd, $channel['name']);
                } catch (\Exception $e) {
                    $this->logger?->error("Error al suscribir al canal {$channel['name']} al cliente $fd: " . $e->getMessage());
                }
            }
        }
    }

    public function runOnCloseConnection(Server $server, int $fd): void
    {
        $this->logger?->info("Cliente desconectado del procesador PUB/SUB: FD $fd");
        $this->cleanupClient($fd);
    }
}