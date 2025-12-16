<?php

namespace SIG\Server;

use Psr\Log\LoggerInterface;
use Redis;
use SIG\Server\Auth\AuthInterface;
use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Exception\AuthenticationException;
use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\Protocol\Response\Rpc;
use SIG\Server\Protocol\Response\Type;
use SIG\Server\Protocol\Status;
use SIG\Server\RPC\RpcInternalPorcessorInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response;
use Swoole\Server\Task;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;
use Tabula17\Satelles\Utilis\Config\RedisConfig;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;
use Tabula17\Satelles\Utilis\Trait\CoroutineHelper;

class Fluxus extends Server
{
    use CoroutineHelper;

    private array $privateEvents = [
        'start',
        'open',
        'message',
        'close',
        'request',
        'pipeMessage',
        'task',
        'finish',
        'workerStart',
        'workerStop',
    ];
    /**
     * @var array $eventHooks Array con propiedades: beforeAfter + evento
     */
    private array $eventHooks = [];
    private Table $subscribers;
    public Table $channels;
    public ?Redis $redis;
    public string $redisChannelPrefix = 'ws_channel:';
    public Table $rpcMethods;
    public Table $rpcRequests;
    private int $rpcRequestCounter = 0;
    public array $rpcHandlers = [];
    private array $rpcInternalProcessors = [];
    private ?Channel $redisMessageChannel = null;
    public string $serverId;
    private int $startTime;
    private(set) bool $initialized = false;
    public array $collectResponses = [];
    public array $collectChannels = [];
    public bool $isShuttingDown = false;
    protected ?Channel $shutdownChannel = null;
    private array $signalHandlers = [];

    public function __construct(
        TCPServerConfig                  $config,
        public readonly Action           $requestProtocol = new Action(),
        public readonly Type             $responseProtocol = new Type(),
        public readonly ?AuthInterface   $auth = null,
        private readonly ?RedisConfig    $redisConfig = null,
        public readonly ?LoggerInterface $logger = null
    )
    {
        // Tabla para suscriptores (fd -> channel)
        $this->subscribers = new Table(1024);
        $this->subscribers->column('fd', Table::TYPE_INT);
        $this->subscribers->column('channels', Table::TYPE_STRING, 4096);
        $this->subscribers->create();

        // Tabla para canales activos
        $this->channels = new Table(512);
        $this->channels->column('name', Table::TYPE_STRING, 255);
        $this->channels->column('auto_subscribe', Table::TYPE_INT, 1);
        $this->channels->column('subscriber_count', Table::TYPE_INT);
        $this->channels->column('created_at', Table::TYPE_INT);
        $this->channels->column('last_message_at', Table::TYPE_INT);
        $this->channels->column('last_message_fd', Table::TYPE_INT);
        $this->channels->column('requires_auth', Table::TYPE_INT, 1);
        $this->channels->column('requires_role', Table::TYPE_STRING, 255);
        $this->channels->create();

        $this->rpcMethods = new Table(512);
        $this->rpcMethods->column('name', Table::TYPE_STRING, 128);
        $this->rpcMethods->column('description', Table::TYPE_STRING, 255);
        $this->rpcMethods->column('requires_auth', Table::TYPE_INT, 1);
        $this->rpcMethods->column('registered_by_worker', Table::TYPE_INT);
        $this->rpcMethods->column('registered_at', Table::TYPE_INT);
        $this->rpcMethods->column('allowed_roles', Table::TYPE_STRING, 4096);
        $this->rpcMethods->column('only_internal', Table::TYPE_INT, 1);
        $this->rpcMethods->create();

        $this->rpcRequests = new Table(1024);
        $this->rpcRequests->column('request_id', Table::TYPE_STRING, 32);
        $this->rpcRequests->column('fd', Table::TYPE_INT);
        $this->rpcRequests->column('method', Table::TYPE_STRING, 128);
        $this->rpcRequests->column('params', Table::TYPE_STRING, 4096); // JSON
        $this->rpcRequests->column('created_at', Table::TYPE_INT);
        $this->rpcRequests->column('status', Table::TYPE_STRING, 20); // pending, processing, completed, failed
        $this->rpcRequests->create();

        parent::__construct($config->host, $config->port, $config->mode ?? SWOOLE_BASE, $config->type ?? SWOOLE_SOCK_TCP);

        $sslEnabled = isset($config->ssl) && $config->ssl->enabled;
        $options = $sslEnabled ? array_merge($config->options, $config->ssl->toArray()) : $config->options;
        if ($sslEnabled) {
            unset($options['enabled']);
        }
        $this->set($options);
        $this->setupPrivateEvents();
        $this->setupWorkerEvents();
        if ($this->isRedisEnabled()) {  // ← Ahora es correcto
            $this->redis = $this->redisClient();
            // Canal para comunicar mensajes Redis entre corutinas
            $this->redisMessageChannel = new Channel(1000);
        }
        $this->serverId = $this->getServerId();
    }

    public function on(string $event_name, callable $callback): bool
    {
        if (in_array($event_name, $this->privateEvents, true)) {
            if (!$this->onAfter($event_name, $callback)) {
                $this->logger?->warning("Evento privado $event_name, acción no permitido");
            }
            return false;
        }
        $callbackWrapper = function (...$args) use ($callback, $event_name) {
            $this->runEventsAction($event_name, $args, 'before');
            $callback(...$args);
            $this->runEventsAction($event_name, $args, 'after');
        };
        return parent::on($event_name, $callbackWrapper);
        //return parent::on($event_name, $callback);
    }

    public function off(string $event_name, callable $callback): bool
    {
        if (in_array($event_name, $this->privateEvents, true)) {
            if (!$this->onAfter($event_name, $callback)) {
                $this->logger?->warning("Evento privado $event_name, acción no permitida");
            }
            return false;
        }
        $this->offEventHook($event_name, $callback);
        foreach (['before', 'after'] as $when) {
            $prop = $when . ucfirst($event_name);
            if (isset($this->eventHooks[$prop])) {
                $this->eventHooks[$prop] = array_diff($this->eventHooks[$prop], [$callback]);
            }
        }
        return parent::on($event_name, static fn() => false);

    }

    private function onEventHook(string $event_name, callable $callback, string $when = 'after'): bool
    {
        $prop = $when . ucfirst($event_name);
        if (!isset($this->eventHooks[$prop])) {
            $this->eventHooks[$prop] = [];
        }
        if (!in_array($callback, $this->eventHooks[$prop], true)) {
            $this->eventHooks[$prop][] = $callback;
            return true;
        }
        return false;
    }

    private function offEventHook(string $event_name, callable $callback, string $when = 'after'): bool
    {
        $prop = $when . ucfirst($event_name);
        if (isset($this->eventHooks[$prop])) {
            $this->eventHooks[$prop] = array_diff($this->eventHooks[$prop], [$callback]);
            return true;
        }
        return false;
    }

    private function runEventsAction(string $event_name, ?array $args = [], string $when = 'after'): void
    {
        $this->logger?->debug('Running event ' . $event_name . ' at ' . $when . '');
        $prop = $when . ucfirst($event_name);
        if ($prop === 'beforeWorkerStart') {
            $this->initialized = false;
        }
        if (isset($this->eventHooks[$prop])) {
            foreach ($this->eventHooks[$prop] as $callback) {
                $callback(...$args);
            }
        }
        if ($prop === 'afterWorkerStart') {
            $this->initialized = true;
        }
        if ($prop === 'afterWorkerStop') {
            $this->initialized = false;
        }
    }

    public function onAfter(string $event_name, callable $callback): bool
    {
        return $this->onEventHook($event_name, $callback, 'after');
    }

    public function offAfter(string $event_name, callable $callback): bool
    {
        return $this->offEventHook($event_name, $callback, 'after');
    }

    public function onBefore(string $event_name, callable $callback): bool
    {
        return $this->onEventHook($event_name, $callback, 'before');
    }

    public function offBefore(string $event_name, callable $callback): bool
    {
        return $this->offEventHook($event_name, $callback, 'before');
    }

    private function onPrivateEvent(string $event_name, callable $callback): bool
    {
        $callbackWrapper = function (...$args) use ($callback, $event_name) {
            $this->runEventsAction($event_name, $args, 'before');
            $callback(...$args);
            $this->runEventsAction($event_name, $args, 'after');
        };
        return parent::on($event_name, $callbackWrapper);
    }

    public function isRedisEnabled(): bool
    {
        return $this->redisConfig instanceof RedisConfig && $this->redisConfig->canConnect() && extension_loaded('redis');
    }

    private function setupPrivateEvents(): void
    {
        $this->onPrivateEvent('start', [$this, 'startServices']);
        $this->onPrivateEvent('open', [$this, 'handleOpen']);
        $this->onPrivateEvent('message', [$this, 'handleMessage']);
        $this->onPrivateEvent('close', [$this, 'handleClose']);
        $this->onPrivateEvent('request', [$this, 'handleRequest']);
        $this->onPrivateEvent('pipeMessage', [$this, 'handlePipeMessage']);
        $this->onPrivateEvent('task', [$this, 'handleTask']);;

    }

    private function setupSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->logger?->warning('PCNTL extension not loaded, signal handlers disabled');
            return;
        }
        $this->logger?->notice('🏗️ Check PIDs to detect master process...');
        $this->logger?->info('👷🏿 PID: ' . posix_getpid());
        $this->logger?->info('👷🏽‍♀️ server master_pid: ' . $this->master_pid);
        $this->logger?->info('👷🏻‍♀️ myPID: ' . getmypid());

        // Solo configurar en el proceso maestro
        if ($this->master_pid !== posix_getpid()) {
            $this->logger?->warning('🚨 Signal handlers only supported in master process');
            return;
        }

        $this->logger?->info('🚧 Configurando manejadores de señales...');

        // Manejar SIGTERM y SIGINT para shutdown graceful
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function ($signo) {
            $this->handleShutdownSignal($signo, 'SIGTERM');
        });

        pcntl_signal(SIGINT, function ($signo) {
            $this->handleShutdownSignal($signo, 'SIGINT');
        });

        // SIGUSR1 para reinicio graceful (opcional)
        pcntl_signal(SIGUSR1, function ($signo) {
            $this->logger?->info("Señal SIGUSR1 recibida, reinicio graceful...");
            $this->reload();
        });
    }

    public function handleShutdownSignal(int $signo, string $signalName): void
    {
        static $handled = false;
        if ($handled || $this->isShuttingDown) {
            $this->logger?->info('Shutdown ya en progreso, ignorando señal...');
            return;
        }

        $handled = true;
        $this->isShuttingDown = true;
        $this->logger?->info("Señal $signalName recibida, iniciando shutdown graceful...");


        // Notificar a las corrutinas de Redis que deben detenerse
        $this->notifyRedisShutdown();

        // 2. Iniciar shutdown del servidor
        $this->shutdown();

        // 3. Configurar timeout para forzar terminación
        Timer::after(8000, function () use ($signalName) {
            $this->logger?->warning("Shutdown timeout después de 8 segundos, forzando terminación...");
            $this->stop();
        });
    }

    private function handleReloadSignal(): void
    {
        $this->logger?->info("Señal de reload recibida (SIGHUP)");
        $this->reload();
    }

    /**
     * Configura eventos específicos para workers
     */
    private function setupWorkerEvents(): void
    {
        // Evento cuando un worker inicia
        $this->onPrivateEvent('workerStart', [$this, 'handleWorkerStart']);

        // Evento cuando un worker para
        $this->onPrivateEvent('workerStop', [$this, 'handleWorkerStop']);
    }

    /**
     * Maneja el inicio de un worker
     */
    public function handleWorkerStart(Server $server, int $workerId): void
    {
        $this->logger?->info("👷 Worker #{$workerId} iniciado - PID: " . posix_getpid());

        // Inicializar procesadores RPC internos
        $this->initializeRpcInternalProcessors();
        // Inicializar tabla RPC (solo worker 0)
        //$this->initializeRpcMethodsTable();
        // Registrar handlers RPC en ESTE worker (todos los workers)
        //$this->registerRpcHandlersInWorker();
        // Si es el worker 0, también inicializar Redis
        if ($workerId === 0 && $this->isRedisEnabled()) {
            $this->startRedisServices();
        }
    }

    /**
     * Maneja la parada de un worker
     */
    public function handleWorkerStop(Server $server, int $workerId): void
    {
        $this->logger?->info("🙅🏼‍♂️ Worker #{$workerId} finalizado");
        // Limpiar handlers de ESTE worker
        $this->rpcHandlers = [];
    }

    public function registerInternalRpcProcessor(string $processorName, RpcInternalPorcessorInterface $processor): void
    {
        if (isset($this->rpcInternalProcessors[$processorName]) && $this->rpcInternalProcessors[$processorName] === $processor) {
            $this->logger?->warning('Processor ' . $processorName . ' already registered as internal RPC processor. Skipping...');
            return;
        }
        $this->rpcInternalProcessors[$processorName] = $processor;
    }

    public function getInternalRpcProcessor(string $processorName): ?RpcInternalPorcessorInterface
    {
        return $this->rpcInternalProcessors[$processorName] ?? null;
    }

    private function initializeRpcInternalProcessors(): void
    {
        foreach ($this->rpcInternalProcessors as $processor) {
            $processor->init($this);
        }
    }

    public function getRpcHandler(string $method): ?callable
    {
        return $this->rpcHandlers[$method] ?? null;
    }

    /**
     * Apaga el servidor gentilmente
     */
    public function gracefulShutdown(): bool
    {
        if ($this->isShuttingDown) {
            $this->logger?->warning("Shutdown already in progress. Ignoring...");
            return false;
        }

        $this->isShuttingDown = true;
        $this->logger?->info("Iniciando shutdown graceful del servidor $this->serverId...");

        // 1. Primero, detener el subscriber Redis (esto es crítico)
        if ($this->isRedisEnabled() && isset($this->redisMessageChannel)) {
            $this->logger?->info("Cerrando canal Redis...");
            $this->redisMessageChannel->close();

            // Si tenemos una conexión Redis activa, desconectarla
            if (isset($this->redis)) {
                try {
                    // Forzar desconexión del subscriber Redis
                    $this->redis->close();
                    $this->logger?->info("Conexión Redis cerrada");
                } catch (\Exception $e) {
                    $this->logger?->error("Error cerrando Redis: " . $e->getMessage());
                }
            }
        }

        // 2. Cerrar todas las conexiones de clientes
        if (isset($this->subscribers) && $this->subscribers->valid()) {
            $this->logger?->info("Cerrando conexiones de clientes...");
            $closedCount = 0;

            foreach ($this->subscribers as $subscriber) {
                $fd = $subscriber['fd'];
                if ($this->isEstablished($fd)) {
                    try {
                        $this->close($fd);
                        $closedCount++;
                    } catch (\Exception $e) {
                        // Ignorar errores de clientes ya desconectados
                    }
                }
            }
            $this->logger?->info("$closedCount conexiones de clientes cerradas");
        }

        // 3. Ejecutar hooks de before shutdown
        $this->runEventsAction('shutdown', [], 'before');

        $this->logger?->info("Shutdown graceful completado");
        return true;
    }

    public function __destruct()
    {
        $this->logger?->info("🧹 Ejecutando limpieza final del servidor $this->serverId...");
        // Cerrar conexión Redis si existe
        if (isset($this->redis)) {
            if (isset($this->redisMessageChannel)) {
                $this->redisMessageChannel->close();
            }
            try {
                $this->redis->close();
            } catch (\Exception $e) {
                // Ignorar errores en destructor
            }
        }

        // Limpiar tablas
        if (isset($this->subscribers)) {
            $this->subscribers->destroy();
        }
        if (isset($this->channels)) {
            $this->channels->destroy();
        }
        if (isset($this->rpcMethods)) {
            $this->rpcMethods->destroy();
        }
        if (isset($this->rpcRequests)) {
            $this->rpcRequests->destroy();
        }

        $this->logger?->info("✅ Limpieza completada");
        parent::__destruct();
    }

    /**
     * Override del método shutdown de Swoole para manejo graceful
     */
    public function shutdown(): bool
    {
        if ($this->isShuttingDown) {
            $this->logger?->warning("Shutdown already in progress. Ignoring...");
            return false;
        }
        $this->isShuttingDown = true;
        $workerId = $this->getWorkerId();
        $this->logger?->info("🛑 Worker #$workerId: Ejecutando shutdown del servidor $this->serverId...");
        $this->runEventsAction('shutdown', [], 'before');
        // 1. Ejecutar graceful shutdown primero
        $this->gracefulShutdown();
        // 2. Esperar un momento para que se completen las operaciones pendientes
        //Coroutine::sleep(0.1);
        $this->safeSleep(0.1);
        // 3. Ejecutar hooks de after shutdown
        $this->runEventsAction('shutdown', [], 'after');
        // 4. Llamar al shutdown de Swoole
        $result = parent::shutdown();
        if ($result) {
            $this->logger?->info("✅ Worker #$workerId: Shutdown completado");
        } else {
            $this->logger?->error("🚫 Worker #$workerId: Shutdown failed");
        }
        return $result;
    }

    private function redisClient(): Redis
    {
        $redis = new Redis();

        // Si no hay configuración Redis, retornar objeto vacío
        if (!$this->isRedisEnabled()) {
            return $redis;
        }

        $config = $this->redisConfig->toArray();

        try {
            $connected = $redis->connect(
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 6379,
                $config['timeout'] ?? 2.5
            );

            if (!$connected) {
                throw new \RuntimeException('No se pudo conectar a Redis');
            }

            if (isset($config['auth'])) {
                $redis->auth($config['auth']);
            }

            if (isset($config['database'])) {
                $redis->select($config['database']);
            }

            // Verificar que Redis realmente funciona
            $redis->ping();

            $this->logger?->info('Conexión a Redis establecida');
            return $redis;

        } catch (\Exception $e) {
            $this->logger?->error('Error conectando a Redis: ' . $e->getMessage());
            return $redis;
        }
    }

    /*   public function start(): bool
       {
           $this->logger?->info("Iniciando servidor $this->serverId...");
           return parent::start();
       }*/

    public function startServices(): void
    {
        $this->logger?->info("Iniciando servicios en servidor $this->serverId...");
        $this->setupSignalHandlers();
        //$this->registerDefaultRpcMethods();
        $this->startRedisServices();

        $this->logger?->info("Servicios WebSocket iniciados");
    }


// Método para notificar shutdown a Redis
    protected function notifyRedisShutdown(): void
    {
        try {
            // Si existe el canal de shutdown, enviar señal
            if ($this->shutdownChannel && !$this->shutdownChannel->isFull()) {
                $this->shutdownChannel->push(['shutdown' => true]);
            }

            // Cerrar conexión Redis si existe
            if (property_exists($this, 'redis') && $this->redis instanceof \Redis) {
                try {
                    @$this->redis->close();
                    $this->logger?->debug('Conexión Redis cerrada');
                } catch (\Throwable $e) {
                    // Ignorar errores de cierre
                }
            }

            // Si existe subscriber de Redis, desconectarlo
            if (property_exists($this, 'redisSubscriber') && $this->redisSubscriber) {
                try {
                    @$this->redisSubscriber->close();
                    $this->logger?->debug('Redis subscriber desconectado');
                } catch (\Throwable $e) {
                    // Ignorar errores
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('Error notificando shutdown a Redis: ' . $e->getMessage());
        }
    }

    /**
     * Inicia el subscriber de Redis en una corutina bloqueante
     */
    private function startRedisSubscriber(): void
    {
        if (!$this->isRedisEnabled()) {
            return;
        }

        Coroutine::create(function () {
            $this->logger?->info('Iniciando corutina de subscriber Redis...');
            $redisSub = $this->redisClient();
            while (!$this->isShuttingDown) {
                try {
                    if (!$redisSub->isConnected()) {
                        throw new \RuntimeException('Conexión Redis no establecida');
                    }

                    $redisSub->setOption(Redis::OPT_READ_TIMEOUT, 2);

                    $this->logger?->info('Subscriber Redis conectado y escuchando canales...');

                    // Usar un timeout para psubscribe para poder salir
                    $redisSub->psubscribe([$this->redisChannelPrefix . '*'],
                        function ($redis, $pattern, $channel, $message) {
                            if ($this->isShuttingDown) {
                                // Si estamos en shutdown, ignorar mensajes
                                return;
                            }
                            $this->redisMessageChannel?->push([
                                'channel' => $channel,
                                'message' => $message,
                                'timestamp' => microtime(true)
                            ]);
                        }
                    );

                    $this->logger?->warning('Subscriber Redis terminó inesperadamente');
                    // Si psubscribe retorna (normalmente no debería), reconectar
                    if (!$this->isShuttingDown) {
                        $this->logger?->warning('Redis psubscribe retornó, reconectando...');
                        $this->safeSleep(1);
                        $redisSub = $this->redisClient();
                    }

                } catch (\Exception $e) {
                    if (!$this->isShuttingDown) {
                        $this->logger?->error('Error en Redis subscriber: ' . $e->getMessage());
                        $this->safeSleep(2);
                        $redisSub = $this->redisClient();
                    }
                }
            }
            $redisSub->close();
            $this->logger?->debug('Redis subscriber finalizado por shutdown');
        });
    }

    /**
     * Inicia los servicios de Redis después de que el servidor esté corriendo
     * Debe llamarse después de start() en una corutina separada
     */
    public function startRedisServices(): void
    {
        if (!$this->isRedisEnabled()) {
            return;
        }

        $this->logger?->info("Iniciando servicios Redis en $this->serverId...");

        // Iniciar procesador de mensajes primero
        $this->startRedisMessageProcessor();

        // Luego iniciar subscriber en corutina separada
        $this->startRedisSubscriber();
    }

    /**
     * Procesa mensajes Redis de forma asincrónica
     */
    private function startRedisMessageProcessor(): void
    {
        while (!$this->isShuttingDown) {
            try {
                // Usar pop con timeout para poder verificar $this->isShuttingDown
                $message = $this->redisMessageChannel->pop(1.0);

                if ($message === false) {
                    // Timeout, verificar si debemos continuar
                    continue;
                }

                if (isset($message['shutdown'])) {
                    break;
                }

                $this->handleRedisMessage($message['channel'], $message['message']);

            } catch (\Throwable $e) {
                if (!$this->isShuttingDown) {
                    $this->logger?->error('Error procesando mensaje Redis: ' . $e->getMessage());
                }
            }
        }
/*
        $this->logger?->debug('Redis message processor finalizado por shutdown');
        Coroutine::create(function () {
            while (true) {
                $redisMessage = $this->redisMessageChannel->pop();
                if ($redisMessage === false) {
                    break; // Canal cerrado
                }

                try {
                    $this->handleRedisMessage($redisMessage['channel'], $redisMessage['message']);
                } catch (\Exception $e) {
                    $this->logger?->error('Error procesando mensaje Redis: ' . $e->getMessage());
                }
            }
        });*/
    }

    /**
     * Maneja mensajes recibidos de Redis
     */
    private function handleRedisMessage(string $channel, string $message): void
    {
        try {
            $data = json_decode($message, true);
            if (!$data) {
                $this->logger?->warning('Mensaje Redis no válido: ' . $message);
                return;
            }

            $channelName = str_replace($this->redisChannelPrefix, '', $channel);

            // Solo transmitir si el mensaje no viene de este servidor
            if (!isset($data['origin_server']) || $data['origin_server'] !== $this->getServerId()) {
                $this->broadcastToChannel($channelName, $data);
                $this->logger?->debug("Mensaje Redis transmitido a canal: $channelName");
            }

        } catch (\Exception $e) {
            $this->logger?->error('Error procesando mensaje Redis: ' . $e->getMessage());
        }
    }

    /**
     * Maneja requests HTTP (para health checks, etc.)
     */
    public function handleRequest(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'] ?? '/';

        switch ($path) {
            case '/health':
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'status' => Status::ok->value,
                    'channels' => $this->channels->count(),
                    'timestamp' => time(),
                    'pid' => getmypid(),
                    'workers' => $this->stats()['worker_num'] ?? 0,
                    'connections' => $this->connections?->count() ?? 0
                ], JSON_THROW_ON_ERROR));
                break;

            case '/stats':
                $channels = [];
                foreach ($this->channels as $channel) {
                    $channels[] = $channel;
                }

                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'channels' => $channels,
                    'total_subscribers' => $this->subscribers->count()
                ], JSON_THROW_ON_ERROR));
                break;

            default:
                $response->status(404);
                $response->end('Not Found');
        }
    }

    /**
     * Evento cuando un cliente se conecta
     */
    public function handleOpen(Server $server, Request $request): void
    {
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

    /**
     * Evento cuando se recibe un mensaje del cliente
     */
    public function handleMessage(Server $server, Frame $frame): void
    {
        try {
            $data = json_decode($frame->data, true);
            if (!$data || !isset($data['action'])) {
                $this->sendError($frame->fd, 'Mensaje no válido');
                return;
            }

            $this->logger?->debug("Mensaje recibido de FD {$frame->fd}: " . $frame->data);

            $protocol = $this->requestProtocol->getProtocolFor($data);
            $this->logger?->debug("Protocolo de solicitud: " . get_class($protocol));
            if ($protocol instanceof RequestHandlerInterface) {
                $protocol->handle($frame->fd, $this);
            } else {

                $this->sendError($frame->fd, 'Acción no reconocida: ' . $data['action']);
                /* switch ($data['action']) {
                    * case 'subscribe':
                          $this->handleSubscribe($frame->fd, $data['channel'] ?? '');
                          break;

                      case 'unsubscribe':
                          $this->handleUnsubscribe($frame->fd, $data['channel'] ?? '');
                          break;

                      case 'publish':
                          $this->handlePublish($frame->fd, $data['channel'] ?? '', $data['data'] ?? null);
                          break;

                     case 'rpc':
                         $this->handleRpc($frame->fd, $data);
                         break;
                   case 'list_channels':
                          $this->handleListChannels($frame->fd);
                          break;

                   case 'list_rpc_methods':
                          $this->handleListRpcMethods($frame->fd);
                          break;
                     case 'authenticate':
                          $this->handleAuthRequest($frame->fd, $data['token'] ?? '');;
                          break;

                    default:
                        $this->sendError($frame->fd, 'Acción no reconocida: ' . $data['action']);
                }*/
            }
        } catch (\Exception $e) {
            $this->logger?->error('Error procesando mensaje: ' . $e->getMessage());
            $this->sendError($frame->fd, 'Error interno del servidor');
        }
    }
    /**
     * Lista métodos RPC disponibles en ESTE worker
     * private function handleListRpcMethods(int $fd): void
     * {
     * $methods = [];
     * $workerId = $this->getWorkerId();
     * while ($this->initialized === false) {
     * $this->logger?->info('Worker #' . $workerId . ' initializing, waiting...');
     * //usleep(100000);
     * $this->safeSleep(0.1);
     * }
     *
     * $this->logger?->debug('🗂️ Propiedad rpcHandlers contiene ' . count($this->rpcHandlers) . ' métodos');
     * $this->logger?->debug('📟 Propiedad rpcMethods contiene ' . $this->rpcMethods->count());
     * // Listar métodos disponibles en ESTE worker
     * foreach ($this->rpcHandlers as $methodName => $handler) {
     * $requiresAuth = false;
     * $description = $this->getRpcMethodDescription($methodName);
     * $registeredAt = 0;
     * $auth_roles = [];
     *
     * // Verificar en tabla si existe metadata
     * if ($this->rpcMethods->exist($methodName)) {
     * $methodInfo = $this->rpcMethods->get($methodName);
     * $requiresAuth = (bool)$methodInfo['requires_auth'];
     * $auth_roles = !empty($methodInfo['allowed_roles']) ? explode(',', $methodInfo['allowed_roles']) : [];
     * $description = $methodInfo['description'];
     * $registeredAt = $methodInfo['registered_at'];
     * if (array_key_exists('only_internal', $methodInfo) && (bool)$methodInfo['only_internal'] === true) {
     * continue;
     * }
     * }
     *
     * $methods[] = [
     * 'name' => $methodName,
     * 'requires_auth' => $requiresAuth,
     * 'allowed_roles' => $auth_roles,
     * 'available_in_worker' => $workerId,
     * 'description' => $description,
     * 'registered_at' => $registeredAt
     * ];
     * }
     *
     * $this->sendToClient($fd, [
     * 'type' => 'rpc_methods_list',
     * 'methods' => $methods,
     * 'total' => count($methods),
     * 'worker_id' => $workerId,
     * 'timestamp' => time()
     * ]);
     * }
     */
    /**
     * Obtiene descripción de un método RPC
     */
    public function getRpcMethodDescription(string $method): string
    {
        $descriptions = [
            'ping' => 'Verifica que el servidor responde',
            'server.stats' => 'Obtiene estadísticas del servidor',
            'client.info' => 'Obtiene información del cliente conectado',
            'math.calculate' => 'Realiza cálculos matemáticos',
            'worker.info' => 'Obtiene información del worker actual',
            'channels.list' => 'Lista canales disponibles'
        ];
        return $descriptions[$method] ?? 'Método RPC personalizado';
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
    public function addChannel(string $channel, bool $requireAuth = false, ?string $requireRole = null, bool $autoSubscribe = false): void
    {
        $this->channels->set($channel, [
            'name' => $channel,
            'auto_subscribe' => $autoSubscribe ? 1 : 0,
            'subscriber_count' => 0,
            'created_at' => time(),
            'requires_auth' => $requireAuth ? 1 : 0,
            'requires_role' => $requireRole
        ]);
        if ($autoSubscribe) {
            foreach ($this->connections as $fd) {
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
        // Verificar si el canal existe, si no, crearlo
        if (!$this->channels->exist($channel)) {
            $this->addChannel($channel);
            $this->logger?->info("Canal creado: $channel");
        }

        // Agregar suscriptor al canal
        $channelInfo = $this->channels->get($channel);

        if ($channelInfo['requires_auth'] === 1) {
            if (!$this->isAuthenticated($fd)) {
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

        ]);

        // Agregar canal al cliente
        $subscriber = $this->subscribers->get($fd);
        $channels = json_decode($subscriber['channels'], true) ?? [];

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
     * Evento cuando un cliente se desconecta
     */
    public function handleClose(Server $server, int $fd): void
    {
        $this->logger?->info("Cliente desconectado: FD $fd");
        $this->cleanupClient($fd);
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

            if ($newCount === 0) {
                // Eliminar canal si no hay suscriptores
                $this->channels->del($channel);
                $this->logger?->info("Canal eliminado: $channel");
            } else {
                $this->channels->set($channel, [
                    'name' => $channel,
                    'auto_subscribe' => $channelInfo['auto_subscribe'],
                    'subscriber_count' => $newCount,
                    'created_at' => $channelInfo['created_at'],
                    'last_message_at' => $channelInfo['last_message_at'],
                    'last_message_fd' => $channelInfo['last_message_fd'],
                    'requires_auth' => $channelInfo['requires_auth'],
                    'requires_role' => $channelInfo['requires_role']
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
            $channels = json_decode($subscriber['channels'], true) ?? [];

            if (in_array($channel, $channels)) {
                $fd = $subscriber['fd'];
                if ($this->isEstablished($fd) && $this->push($fd, $messageJson)) {
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
        $subscriber = $this->subscribers->get($fd);
        if ($subscriber) {
            $channels = json_decode($subscriber['channels'], true) ?? [];

            foreach ($channels as $channel) {
                $this->unsubscribeFromChannel($fd, $channel);
            }

            $this->subscribers->del($fd);
        }
    }

    /**
     * Genera un ID único para este servidor
     */
    public function getServerId(): string
    {
        static $serverId = null;
        if ($serverId === null) {
            $serverId = gethostname() . ':' . (getmypid() ?? uniqid());
        }
        return $serverId;
    }

    /**
     * Envía mensaje de éxito al cliente
     * @throws UnexpectedValueException
     */
    public function sendSuccess(int $fd, string $message): void
    {
        $response = $this->responseProtocol->getProtocolFor([
            'type' => $this->responseProtocol->get('success'),
            'message' => $message,
            'stats' => [
                'worker_id' => $this->getWorkerId(),
                'timestamp' => time()
            ]
        ]);
        $this->sendToClient($fd, $response);
    }

    /**
     * Envía mensaje de error al cliente
     * @throws UnexpectedValueException
     */
    public function sendError(int $fd, string $error): void
    {
        $response = $this->responseProtocol->getProtocolFor([
            'type' => $this->responseProtocol->get('error'),
            'message' => $error,
            '_metadata' => [
                'worker_id' => $this->getWorkerId(),
                'timestamp' => time()
            ]
        ]);
        $this->sendToClient($fd, $response);
    }

    /**
     * Envía mensaje JSON al cliente
     */
    public function sendToClient(int $fd, array|AbstractDescriptor $data): void
    {
        if ($this->isEstablished($fd)) {
            $this->push($fd, json_encode($data));
        }
    }

    /**
     * Verifica si una conexión WebSocket está establecida
     */
    public function isEstablished(int $fd): bool
    {
        try {
            $info = $this->getClientInfo($fd);
            return $info && $info['websocket_status'] === WEBSOCKET_STATUS_ACTIVE;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtiene estadísticas del servidor
     */
    public function getStats(): array
    {
        return [
            'server_id' => $this->getServerId(),
            'clients' => count($this->connections ?? []),
            'channels' => $this->channels->count(),
            'subscribers' => $this->subscribers->count(),
            'redis_enabled' => $this->isRedisEnabled()
        ];
    }

    /**
     * Registra un método RPC (puede ser llamado por múltiples workers)
     */
    public function registerRpcMethod(
        string   $method,
        callable $handler,
        bool     $requires_auth = false,
        array    $allowed_roles = ['ws:general'],
        string   $description = '',
        bool     $only_internal = false
    ): bool
    {
        $workerId = $this->getWorkerId();

        // Verificar solo si YA ESTÁ REGISTRADO EN ESTE WORKER
        if (isset($this->rpcHandlers[$method])) {
            $this->logger?->debug("Método $method ya registrado en worker #$workerId");
            return false;
        }

        // Guardar handler en ESTE worker
        $this->rpcHandlers[$method] = $handler;
        $roles = !empty($allowed_roles) ? implode('|', $allowed_roles) : [];
        // Solo el primer worker que encuentre el método vacío en la tabla lo registra
        if (!$this->rpcMethods->exist($method)) {
            $this->rpcMethods->set($method, [
                'name' => $method,
                'description' => $description,
                'requires_auth' => $requires_auth ? 1 : 0,
                'allowed_roles' => $roles,
                'registered_by_worker' => $workerId,
                'only_internal' => $only_internal ? 1 : 0,
                'registered_at' => time()
            ]);
            $this->logger?->debug("✅ Método RPC registrado en tabla por worker #$workerId: $method");
        } else {
            $this->logger?->debug("📝 Método $method ya en tabla, solo registrando handler en worker #$workerId");
        }

        return true;
    }

    public function registerRpcMethods(MethodsCollection $collection): void
    {
        foreach ($collection as $method) {
            $this->registerRpcMethod(...$method->toArray());
        }
    }

    /**
     * Obtiene uptime del servidor
     */
    public function getUptime(): string
    {
        if (!isset($this->startTime)) {
            $this->startTime = time();
        }

        $uptime = time() - $this->startTime;
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    /**
     * Ejecuta un método RPC en una corutina
     */
    public function executeRpcMethod(int $fd, string $requestId, string $method, array $params, int $timeout, int $workerId): void
    {
        try {
            $this->logger?->debug("▶️  Ejecutando RPC en worker #{$workerId}: $method (ID: $requestId)");
            // Verificar que el método existe
            if (!isset($this->rpcHandlers[$method])) {
                $this->logger?->error("💥 Método desapareció durante ejecución en worker #{$workerId}: $method");
                $this->sendRpcError($fd, $requestId, "Error interno: método no disponible", 500);
                $this->updateRpcRequest($requestId, 'failed');
                return;
            }

            // Actualizar estado a procesando
            $this->updateRpcRequest($requestId, 'processing');

            $startTime = microtime(true);
            $handler = $this->rpcHandlers[$method];

            try {
                // Ejecutar handler
                $result = $handler($this, $params, $fd);
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                // Agregar metadata al resultado
                if (is_array($result)) {
                    if (!isset($result['_metadata'])) {
                        $result['_metadata'] = [];
                    }
                    $result['_metadata'] = array_merge($result ['_metadata'], [
                        'execution_time_ms' => $executionTime,
                        'worker_id' => $workerId,
                        'request_id' => $requestId
                    ]);
                }

                // Enviar resultado
                $this->sendRpcResult($fd, $requestId, $result, $executionTime);
                $this->updateRpcRequest($requestId, 'completed');

                $this->logger?->debug("✅ RPC completado en worker #{$workerId}: $method en {$executionTime}ms");

            } catch (\Exception $e) {
                $this->logger?->error("❌ Error ejecutando RPC en worker #{$workerId}: " . $e->getMessage());
                $this->sendRpcError($fd, $requestId, $e->getMessage());
                $this->updateRpcRequest($requestId, 'failed');
            }

        } catch (\Exception $e) {
            $this->logger?->error("💥 Error crítico en RPC worker #{$workerId}: " . $e->getMessage());
            $this->sendRpcError($fd, $requestId, 'Error interno del servidor');
            $this->updateRpcRequest($requestId, 'failed');
        }
    }


    /**
     * Maneja errores de RPC
     *
     * private function handleRpcError(int $fd, string $requestId, string $method, \Exception $e): void
     * {
     * $this->logger?->error("Error ejecutando RPC $method: " . $e->getMessage());
     * $this->sendRpcError($fd, $requestId, $e->getMessage());
     * $this->updateRpcRequest($requestId, 'failed');
     * }
     */
    /**
     * Envía resultado RPC al cliente
     */
    private function sendRpcResult(int $fd, string $requestId, $result, float $executionTime): void
    {
        $response = $this->responseProtocol->getProtocolFor([
            'type' => $this->responseProtocol->get('rpcResponse'),
            'id' => $requestId,
            'status' => Status::success,
            'result' => $result,
            '_metadata' => [
                'execution_time' => $executionTime,
                'timestamp' => time()
            ]
        ]);

        $this->sendToClient($fd, $response);
    }

    /**
     * Envía error RPC al cliente
     */
    public function sendRpcError(int $fd, string $requestId, string $error, int $code = 500): void
    {
        $response = $this->responseProtocol->getProtocolFor([
            'type' => $this->responseProtocol->get('rpcError'),
            'id' => $requestId,
            'status' => Status::error,
            'error' => [
                'code' => $code,
                'message' => $error
            ],
            '_metadata' => [
                'timestamp' => time()
            ]
        ]);
        $this->logger?->error("Error RPC: $requestId ($error)");

        $this->sendToClient($fd, $response);
        $this->updateRpcRequest($requestId, 'failed');
    }

    /**
     * Actualiza estado de solicitud RPC
     */
    private function updateRpcRequest(string $requestId, string $status): void
    {
        if ($this->rpcRequests->exist($requestId)) {
            $request = $this->rpcRequests->get($requestId);
            $request['status'] = $status;
            $this->rpcRequests->set($requestId, $request);
        }
    }

    /**
     * Genera ID único para RPC
     */
    public function generateRpcId(): string
    {
        return 'rpc_' . uniqid() . '_' . (++$this->rpcRequestCounter);
    }


    /**
     * Maneja mensajes entre workers vía sendMessage
     */
    public function handlePipeMessage(Server $server, int $srcWorkerId, string $message): void
    {
        $currentWorkerId = $this->getWorkerId();
        $this->logger?->debug("📨 PipeMessage recibido en worker #{$currentWorkerId} desde worker #{$srcWorkerId}");

        try {
            $data = json_decode($message, true);
            if (!$data || !isset($data['action'])) {
                return;
            }

            if ($data['action'] === 'rpc' && isset($data['method'])) {
                $this->handleBroadcastRpc($data, $srcWorkerId);
            }

            if ($data['action'] === 'broadcast_collect') {
                $this->handleBroadcastCollect($data, $srcWorkerId);
            }
            if ($data['action'] === 'collect_response') {
                $this->handleCollectResponse($server, $data, $srcWorkerId);
            }

        } catch (\Exception $e) {
            $this->logger?->error("❌ Error en pipeMessage: " . $e->getMessage());
        }
    }

    private function handleCollectResponse(Server $server, array $data, int $srcWorkerId): void
    {
        $requestId = $data['request_id'] ?? '';
        $workerId = $data['worker_id'] ?? $srcWorkerId;

        if (empty($requestId)) {
            return;
        }

        // Guardar respuesta
        if (!isset($server->collectResponses[$requestId])) {
            $server->collectResponses[$requestId] = [];
        }

        $server->collectResponses[$requestId][$workerId] = [
            'data' => $data['data'] ?? ($data['error'] ?? 'No data'),
            'success' => $data['success'] ?? false,
            'timestamp' => $data['timestamp'] ?? time(),
            'source_worker' => $srcWorkerId
        ];

        // Notificar al canal si existe
        if (isset($server->collectChannels[$requestId])) {
            $server->collectChannels[$requestId]->push($workerId);
        }

        $this->logger?->debug("📥 Collect response recibido y almacenado de worker #{$workerId}");
    }

    /**
     * Maneja solicitudes de recolección broadcast
     */
    private function handleBroadcastCollect(array $data, int $srcWorkerId): void
    {
        $action = $data['collect_action'] ?? '';
        $params = $data['params'] ?? [];
        $requestId = $data['request_id'] ?? '';
        $responseToWorker = $data['response_to_worker'] ?? $srcWorkerId;

        $currentWorkerId = $this->getWorkerId();

        $this->logger?->debug("📊 Collect request: {$action} para worker #{$currentWorkerId}");

        // Ejecutar la acción si existe
        if (isset($this->rpcHandlers[$action])) {
            try {
                $result = $this->rpcHandlers[$action]($this, $params, 0);
                $result['_collected_at'] = time();
                $result['_worker_id'] = $currentWorkerId;

                // Enviar respuesta al worker solicitante
                $response = json_encode([
                    'action' => 'collect_response',
                    'request_id' => $requestId,
                    'worker_id' => $currentWorkerId,
                    'data' => $result,
                    'success' => true,
                    'timestamp' => time()
                ]);

                $this->sendMessage($response, $responseToWorker);

                $this->logger?->debug("✅ Collect response enviado para {$action} en worker #{$currentWorkerId}");;

            } catch (\Exception $e) {
                // Enviar error
                $errorResponse = json_encode([
                    'action' => 'collect_response',
                    'request_id' => $requestId,
                    'worker_id' => $currentWorkerId,
                    'error' => $e->getMessage(),
                    'success' => false,
                    'timestamp' => time()
                ]);

                $this->sendMessage($errorResponse, $responseToWorker);
            }
        }
    }

    /**
     * Ejecuta RPC recibido por broadcast
     */
    private function handleBroadcastRpc(array $data, int $srcWorkerId): void
    {
        $method = $data['method'] ?? '';
        $params = $data['params'] ?? [];
        $currentWorkerId = $this->getWorkerId();

        $this->logger?->info("🔁 Ejecutando RPC broadcast: {$method} en worker #{$currentWorkerId}");

        // Verificar si el método existe en este worker
        if (!isset($this->rpcHandlers[$method])) {
            $this->logger?->warning("⚠️ Método {$method} no disponible en worker #{$currentWorkerId}");
            return;
        }

        try {
            // Ejecutar el método (usar FD 0 o null para indicar broadcast interno)
            $result = $this->rpcHandlers[$method]($this, $params, 0);

            $this->logger?->info("✅ RPC broadcast ejecutado: {$method} en worker #{$currentWorkerId}");

            // Opcional: Notificar al worker origen
            if (isset($data['need_response']) && $data['need_response']) {
                $response = json_encode([
                    'action' => 'rpc_response',
                    'request_id' => $data['request_id'] ?? '',
                    'worker_id' => $currentWorkerId,
                    'method' => $method,
                    'success' => true,
                    'timestamp' => time()
                ]);
                $this->sendMessage($response, $srcWorkerId);
            }

        } catch (\Exception $e) {
            $this->logger?->error("❌ Error ejecutando RPC broadcast {$method}: " . $e->getMessage());
        }
    }

    public function handleTask(Server $server, Task $task): void
    {
        $data = $task->data;

        if (isset($data['type'], $data['method'], $this->rpcHandlers[$data['method']]) && $data['type'] === 'broadcast_task') {
            // Este task worker debe notificar a todos los workers normales
            // usando sendMessage
            $message = json_encode([
                'action' => 'rpc',
                'method' => $data['method'],
                'params' => $data['params'] ?? [],
                'timestamp' => time()
            ], JSON_THROW_ON_ERROR);

            $totalWorkers = $server->setting['worker_num'] ?? 1;
            for ($i = 0; $i < $totalWorkers; $i++) {
                $server->sendMessage($message, $i);
            }
        }
        $task->finish("Task completed");
    }

    public function currentRoles(): array
    {
        $roles = [];
        foreach ($this->channels as $channel) {
            $roles[] = $channel['requires_role'];
        }
        $rpcRoles = [];
        foreach ($this->rpcMethods as $method) {
            $rpcRoles[] = !empty($method['allowed_roles']) ? explode('|', $method['allowed_roles']) : ['ws:general', 'ws:user'];;
        }

        return array_unique(array_filter(array_merge($roles, ...$rpcRoles)));
    }

    /**
     * Verifica si un cliente está autenticado
     */
    public function isAuthenticated(int $fd): bool
    {
        return $this->auth?->isAuthenticated($fd) ?? true;
    }

    public function userRoles(int $fd): ?array
    {
        return $this->auth?->getRoles($fd);
    }
}