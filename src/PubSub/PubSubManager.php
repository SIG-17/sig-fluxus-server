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
use SIG\Server\RPC\RpcPublisherInterface;
use Swoole\Http\Request;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Tabula17\Satelles\Utilis\Exception\UnexpectedValueException;

class PubSubManager implements ProtocolManagerInterface, RpcPublisherInterface
{
    public Table $subscribers;
    public Table $channels;
    public Table $subscriptions;
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
        $this->subscribers->column('channels', Table::TYPE_INT);
        $this->subscribers->column('started_at', Table::TYPE_INT);
        $this->subscribers->create();
        $this->logger?->debug('Tabla de suscriptores creada');

        $this->subscriptions = new Table(4096);
        $this->subscriptions->column('id', Table::TYPE_STRING, 255);
        $this->subscriptions->column('channel', Table::TYPE_STRING, 255);
        $this->subscriptions->column('subscriber_fd', Table::TYPE_INT);
        $this->subscriptions->create();
        $this->logger?->debug('Tabla de suscripciones creada');

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
        $this->logger?->debug('🛍️ Tabla de canales creada');
        while ($arguments = array_shift($this->channelsQueue)) {
            $this->addChannel(...$arguments);
        }
    }

    public function registerProtocolHandlers(): void
    {
        $protocol = $this->protocol->toArray();
        foreach ($protocol as $action) {
            $this->server->registerMessageHandler($action, [$this, 'handleMessages']);
        }
    }

    public function initializeOnStart(): void
    {
        while ($arguments = array_shift($this->channelsQueue)) {
            $this->addChannel(...$arguments);
        }
    }

    public function initializeOnWorkers(): void
    {
        while ($arguments = array_shift($this->channelsQueue)) {
            $this->addChannel(...$arguments);
        }
    }

    public function handleMessages(Server $server, Frame $frame): void
    {

        $data = json_decode($frame->data, true);
        if (!$data || !isset($data['action'])) {
            $server->sendError($frame->fd, 'El mensaje no puede procesarse: ' . $frame->data);
            return;
        }
        $workerId = $server->getWorkerId();
        $this->logger?->debug("🛍️ Mensaje recibido en worker #{$workerId} de FD {$frame->fd}: " . $frame->data);
        $protocol = $this->protocol->getProtocolFor($data);
        $this->logger?->debug("🛍️ Protocolo de solicitud: " . get_class($protocol));
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
                $this->logger?->warning('🛍️ Mensaje Redis no válido: ' . $message);
                return;
            }

            $channelName = $data['channel'] ?? str_replace($this->server->redisChannelPrefix . '.', '', $channel);
            $metadata = $data['_metadata'] ?? [];
            // Solo transmitir si el mensaje no viene de este servidor
            if (!isset($metadata['origin_server']) || $metadata['origin_server'] !== $this->server->getServerId()) {
                $this->broadcastToChannel($channelName, $data);
                $this->logger?->debug("🛍️ Mensaje Redis transmitido a canal: $channelName");
            } else {
                $this->logger?->debug("🛍️ Mensaje Redis no transmitido a canal: $channelName (mismo servidor?) [$message]");
            }

        } catch (\Exception $e) {
            $this->logger?->error('🛍️ Error procesando mensaje Redis: ' . $e->getMessage());
        }
    }

    public function addChannel(string $channel, bool $requireAuth = false, ?string $requireRole = null, bool $autoSubscribe = false, bool $persists = false): array
    {
        $channelInfo = [
            'name' => $channel,
            'auto_subscribe' => $autoSubscribe ? 1 : 0,
            'subscriber_count' => 0,
            'created_at' => time(),
            'last_message_at' => null,
            'last_message_fd' => null,
            'requires_auth' => $requireAuth ? 1 : 0,
            'requires_role' => $requireRole,
            'persists_on_empty' => $persists ? 1 : 0,
        ];
        $this->channels->set($channel, $channelInfo);
        $this->logger?->info("🛍️ Canal creado: $channel");
        if ($autoSubscribe && $this->server->isRunning()) {
            foreach ($this->server->connections as $fd) {
                try {
                    $this->logger?->debug("🛍️ Auto-suscribiendo FD $fd al canal: $channel");
                    $this->subscribeToChannel($fd, $channel);
                } catch (\Throwable $e) {
                    $this->logger?->error("🛍️ Error (silent) al suscribir al canal $channel al cliente $fd: {$e->getMessage()}");
                }
            }
        }
        return $channelInfo;
    }

    /**
     * Suscribe un cliente a un canal (crea el canal si no existe)
     * @throws AuthenticationException
     */
    public function subscribeToChannel(int $fd, string $channel): void
    {
        if ($this->channels->getMemorySize() === 0) {
            $this->initPubSubTables();
        }
        // Verificar si el canal existe, si no, crearlo
        if (!$this->channels->exist($channel)) {
            $this->logger?->debug("🛍️ El canal #$channel no existe, creando");
            $channelInfo = $this->addChannel($channel);
        } else {
            // Agregar suscriptor al canal
            $channelInfo = $this->channels->get($channel);
        }
        if ($channelInfo['requires_auth'] === 1) {
            if (!$this->server->isAuthenticated($fd)) {
                throw new AuthenticationException('Channel is only for authenticated users');
            }
            if (!empty($channelInfo['requires_role']) && !in_array($channelInfo['requires_role'], $this->server->userRoles($fd))) {
                throw new AuthenticationException('User does not have required role');
            }
        }
        $this->logger?->debug("🛍️  El canal #$channel tiene {$channelInfo['subscriber_count']} suscriptores, debemos sumar 1");
        // ++$channelInfo['subscriber_count'];
        //$this->channels->set($channel, $channelInfo);
        $this->channels->incr($channel, 'subscriber_count');

        // Agregar canal al cliente
        if (!$this->subscribers->exist($fd)) {
            $this->initializeClient($fd);
        }
        $id_subscription = $fd . ':' . $channel;
        if (!$this->subscriptions->exist($id_subscription)) {
            $this->subscriptions->set($id_subscription, ['id' => $id_subscription, 'channel' => $channel, 'subscriber_fd' => $fd]);
        }
        $clientChannels = [];
        foreach ($this->subscriptions as $id => $subscription) {
            if (str_starts_with($id, $fd . ':')) {
                $clientChannels[] = $subscription['channel'];
            }
        }
        $this->subscribers->incr($fd, 'channels');
        // $subscriber = $this->subscribers->get($fd);
        $this->logger?->debug("🛍️ Suscribiendo FD $fd al canal: $channel. | Existing channels: " . var_export($clientChannels, true));
        //$channels = $subscriber ? json_decode($subscriber['channels'], true) : [];
        /*  if (!$subscriber || !str_contains($subscriber['channels'] ?: '', $channel)) {
              $this->logger?->debug("🛍️ 🛍️ Agregando canal al cliente $fd: $channel ");
              $channels = explode(',', $subscriber['channels']) ?: [];
              $channels[] = $channel;
              $this->subscribers->set($fd, [
                  'fd' => $fd,
                  'channels' => implode(',', array_filter($channels))
              ]);
          }*/
        // $this->logger?->debug("🛍️ FD $fd: " . var_export($clientChannels, true));
        $this->logger?->info("🛍️ FD $fd suscrito al canal: $channel");

    }

    /**
     * Desuscribe un cliente de un canal
     */
    public function unsubscribeFromChannel(int $fd, string $channel): void
    {
        // Remover canal del cliente
        //$subscriber = $this->subscribers->get($fd);
        //$channels = json_decode($subscriber['channels'], true) ?? [];
        //$channels = array_filter($channels, static fn($c) => $c !== $channel);


        $id_subscription = $fd . ':' . $channel;
        $this->logger?->debug("🛍️ Desuscribiendo FD $fd del canal: $channel ($id_subscription)");
        $this->subscriptions->del($id_subscription);
        $clientChannels = [];
        $channelCount = 0;
        foreach ($this->subscriptions as $id => $subscription) {
            if (str_starts_with($id, $fd . ':')) {
                $clientChannels[] = $subscription['channel'];
            }
            if (str_ends_with($id, $channel)) {
                $channelCount++;
            }
        }
        if ($this->subscribers->get($channel, 'channels') > 0) {
            $this->subscribers->decr($fd, 'channels');
        }
        /* $channels = explode(',', $subscriber['channels']) ?: [];
         unset($channels[array_search($channel, $channels)]);
         $this->subscribers->set($fd, [
             'fd' => $fd,
             'channels' => implode(',', array_filter($channels))
         ]);*/
        // Actualizar contador del canal
        if ($this->channels->exist($channel)) {
            $channelInfo = $this->channels->get($channel);
            $this->logger?->debug("🛍️  El canal #$channel tiene {$channelInfo['subscriber_count']} suscriptores, debemos restar 1");
            if ($this->channels->get($channel, 'subscriber_count') > 0) {
                $this->channels->decr($channel, 'subscriber_count');
            }
            if ($this->channels->get($channel, 'subscriber_count') === 0 && !($this->channels->get($channel, 'persists_on_empty') ?? 0)) {
                // Eliminar canal si no hay suscriptoresλ
                $this->channels->del($channel);
                $this->logger?->info("🛍️ Canal eliminado: $channel (poe: {$channelInfo['persists_on_empty']})");
            } else {
                $this->logger?->debug("🛍️ Nuevo contador de suscriptores del canal $channel: " . $this->channels->get($channel, 'subscriber_count'));
            }
        }
        $this->logger?->info("🛍️ FD $fd desuscrito del canal: $channel ($channelCount)");
        $this->logger?->debug("🛍️ FD $fd: " . var_export($clientChannels, true));

    }

    public function getChannelInfo(string $channel): array
    {
        return $this->channels->get($channel) ?? [];
    }

    public function getChannelSubscribers(string $channel): array
    {
        $subscribers = [];
        foreach ($this->subscriptions as $subscription) {
            if ($subscription['channel'] === $channel) {
                $subscribers[] = $subscription['subscriber_fd'];
            }
        }
        return $subscribers;
    }

    public function getClientChannels(int $fd): array
    {
        $channels = [];
        foreach ($this->subscriptions as $subscription) {
            if ($subscription['subscriber_fd'] === $fd) {
                $channels[] = $subscription['channel'];
            }
        }
        return $channels;
    }

    /**
     * Transmite un mensaje a todos los suscriptores de un canal
     * Retorna el número de clientes que recibieron el mensaje
     */
    public function broadcastToChannel(string $channel, array $message): int
    {
        $messageJson = json_encode($message);
        $sentCount = 0;
        $subscribers = $this->getChannelSubscribers($channel);

        foreach ($subscribers as $fd) {
            //if (str_contains($subscriber['channels'] ?: '', $channel)) {
            //$channels = $subscriber ? explode(',', $subscriber['channels']) : [];
            //  $fd = $subscriber['fd'];
            if ($this->server->isEstablished($fd) && $this->server->push($fd, $messageJson)) {
                $sentCount++;
            } else {
                // Cliente desconectado, limpiar suscripciones
                $this->cleanupClient($fd);
            }
            //}
        }

        return $sentCount;
    }

    /**
     * Limpia las suscripciones de un cliente desconectado
     */
    private function cleanupClient(int $fd): void
    {
        $this->logger?->debug("🛍️ Iniciando limpieza de suscripciones de cliente $fd");
        if ($this->subscribers->getMemorySize() === 0) {
            return;
        }
        $channels = $this->getClientChannels($fd);
        $this->logger?->info("🛍️ Client $fd desconectado. Eliminando suscripciones: " . var_export($channels, true));
        foreach ($channels as $channel) {
            $this->logger?->debug("🛍️ Desuscribiendo FD $fd del canal: $channel");
            $this->unsubscribeFromChannel($fd, $channel);
        }
        $this->subscribers->del($fd);
        /*
                $subscriber = $this->subscribers->get($fd);
                if ($subscriber) {
                    $channels = explode(',', $subscriber['channels']) ?: [];
                    $this->logger?->info("🛍️ Client $fd desconectado. Eliminando suscripciones: " . var_export($channels, true));
                    foreach ($channels as $channel) {
                        $this->logger?->debug("🛍️ Desuscribiendo FD $fd del canal: $channel");
                        $this->unsubscribeFromChannel($fd, $channel);
                    }

                    $this->subscribers->del($fd);
                }*/
    }

    public function cleanUpResources(): void
    {
        $workerId = $this->server->getWorkerId();
        $this->logger?->info("🛍️  #$workerId Cerrando conexiones de clientes...");
        foreach ($this->subscriptions as $key => $subscriber) {
            $this->subscriptions->del($key);
        }

        if (isset($this->subscribers)) {
            $closedCount = 0;
            foreach ($this->subscribers as $key => $subscriber) {
                $fd = $subscriber['fd'];
                if ($this->server->isEstablished($fd)) {
                    try {
                        //$this->server->close($fd);
                        $closedCount++;
                        $this->subscribers->del($key);
                    } catch (\Exception $e) {
                        // Ignorar errores de clientes ya desconectados
                    }
                }
            }
            foreach ($this->channels as $channel) {
                $channel['subscriber_count'] = 0;
            }
            $this->logger?->info("🛍️ #$workerId -> $closedCount conexiones de clientes cerradas");
        }


        //$this->subscribers->destroy();
        //$this->channels->destroy();
        $this->logger?->debug("🛍️ #$workerId Tablas de canales y suscriptores limpias");
    }

    private function initializeClient(int $fd): void
    {
        // Inicializar suscriptor
        $this->subscribers->set($fd, [
            'fd' => $fd,
            'channels' => "",
            'started_at' => time(),
        ]);

        foreach ($this->channels as $channel) {
            if ($channel['auto_subscribe'] === 1) {
                try {
                    $this->subscribeToChannel($fd, $channel['name']);
                } catch (\Exception $e) {
                    $this->logger?->error("🛍️ Error al suscribir al canal {$channel['name']} al cliente $fd: " . $e->getMessage());
                }
            }
        }
    }

    public function runOnOpenConnection(Server $server, Request $request): void
    {
        if ($this->subscribers->getMemorySize() === 0) {
            $this->initPubSubTables();
        }
        $fd = $request->fd;
        $this->logger?->info("🛍️ Cliente conectado procesador PUB/SUB: FD $fd");
        $this->initializeClient($fd);

    }

    public function runOnCloseConnection(Server $server, int $fd): void
    {
        $this->logger?->info("🛍️ Cliente desconectado del procesador PUB/SUB: FD $fd");
        $this->cleanupClient($fd);
    }

    /**
     * @throws UnexpectedValueException
     */
    public function publishRpcMethods(Fluxus $server): ?MethodsCollection
    {
        return MethodsCollection::fromArray(
            [
                [
                    'method' => 'channels.list',
                    'description' => 'Available channels info',
                    'requires_auth' => false,
                    'allowed_roles' => ['ws:admin', 'ws:user'],
                    'only_internal' => false,
                    'handler' => function (Fluxus $server) {
                        $channels = [];
                        foreach ($this->channels as $channel) {
                            $channels[] = [
                                'name' => $channel['name'],
                                'subscribers' => $channel['subscriber_count'],
                                'created_at' => $channel['created_at']
                            ];
                        }

                        return [
                            'channels' => $channels,
                            'total' => count($channels),
                            'worker_id' => $server->getWorkerId()
                        ];
                    },
                    'parameters' => [
                        ['name' => 'server', 'type' => 'Fluxus', 'required' => true, 'injected' => true]
                    ]
                ],
                [
                    'method' => 'channels.subscribers',
                    'description' => 'List of subscribers for a channel',
                    'requires_auth' => false,
                    'allowed_roles' => ['ws:admin', 'ws:user'],
                    'only_internal' => false,
                    'handler' => function (Fluxus $server) {
                        $out = [
                            'channels' => [],
                            'total' => 0,
                            'worker_id' => $server->getWorkerId(),
                            'raw_data' => []
                        ];

                        foreach ($this->subscriptions as $subscriber) {
                            $out['raw_data'][] = $subscriber;
                            $channel = $subscriber['channel'];
                            $fd = $subscriber['subscriber_fd'];
                            if (!isset($out['channels'][$channel])) {
                                $out['channels'][$channel] = [
                                    'subscribers' => [],
                                    'total' => 0,
                                ];
                            }
                            $info = $server->getClientInfo($fd);
                            $out['channels'][$channel]['subscribers'][] = [
                                'fd' => $fd,
                                'remote_ip' => $info['remote_ip'] ?? 'unknown',
                                'remote_port' => $info['remote_port'] ?? 0,
                                'connected_at' => $info['connect_time'] ?? 0,
                                'websocket_status' => $info['websocket_status'] ?? 0,
                                'last_time' => $info['last_time'] ?? 0,
                                'worker_id' => $server->getWorkerId()
                            ];
                            $out['channels'][$channel]['total']++;
                            $out['total']++;
                        }
                        return $out;

                    },
                    'parameters' => [
                        ['name' => 'server', 'type' => 'Fluxus', 'required' => true, 'injected' => true]
                    ]
                ]
            ]
        );
    }
}