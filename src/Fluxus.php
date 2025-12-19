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
use SIG\Server\Protocol\Response\Type;
use SIG\Server\Protocol\Status;
use SIG\Server\RPC\RpcInternalPorcessorInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response;
use Swoole\Server\Task;
use Swoole\Table;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;
use Tabula17\Satelles\Utilis\Config\RedisConfig;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;
use Tabula17\Satelles\Utilis\Trait\CoroutineHelper;

/**
 * Class Fluxus
 *
 * Extends the Server class, integrating features for handling events, Pub/Sub mechanisms, and managing hooks.
 * Implements a variety of event management methods, table initializations for subscribers and channels,
 * and custom event hooks for different triggers.
 */
class Fluxus extends Server
{
    use CoroutineHelper;

    /**
     * @var array|string[]
     *  Swoole events:
     * onStart
     * onBeforeShutdown
     * onShutdown
     * onWorkerStart
     * onWorkerStop
     * onWorkerExit
     * onConnect
     * onReceive
     * onPacket
     * onClose
     * onTask
     * onFinish
     * onPipeMessage
     * onWorkerError
     * onManagerStart
     * onManagerStop
     * onBeforeReload
     * onAfterReload
     * onBeforeHandshakeResponse
     * onHandShake
     * onOpen
     * onMessage
     * onRequest
     * onDisconnect
     *
     */
    private array $privateEvents = [
        'start',
        'onBeforeShutdown',
        'open',
        'message',
        'close',
        'request',
        'pipeMessage',
        'task',
        //'finish',
        'workerStart',
        'workerStop',
    ];
    private array $hookableEvents = [
        'start' => ['before', 'after'],
        'shutdown' => ['before', 'after'],
        'stop' => ['before', 'after'],
        'close' => ['before', 'after'],
        'pause' => ['before', 'after'],
        'resume' => ['before', 'after']


    ];
    /**
     * @var array $eventHooks Array con propiedades: beforeAfter + evento
     */
    private array $eventHooks = [];
    private array $cancelableCids = [];
    private ?Redis $redisClient;
    private ?Channel $redisMessageChannel = null;
    public string $redisChannelPrefix = 'ws_channel:';
    private bool $isShuttingDown = false;
    private bool $isStopped = true;

    private Table $subscribers;
    public Table $channels;


    public Table $rpcMethods;
    public Table $rpcRequests;
    private int $rpcRequestCounter = 0;
    public array $rpcHandlers = [];
    private array $rpcInternalProcessors = [];

    private string $serverId;
    private int $startTime;


    private array $rpcMethodsQueue = [];
    private array $rpcRequestsQueue = [];

    private array $channelsQueue = [];

    public array $collectResponses = [];
    public array $collectChannels = [];
    /**
     * @var bool $signalsConfigured
     */
    private bool $signalsConfigured = false;
    public int $workerId = -1;

    public function __construct(
        TCPServerConfig                  $config,
        public readonly Action           $requestProtocol = new Action(),
        public readonly Type             $responseProtocol = new Type(),
        public readonly ?AuthInterface   $auth = null,
        private readonly ?RedisConfig    $redisConfig = null,
        public readonly ?LoggerInterface $logger = null
    )
    {

        parent::__construct($config->host, $config->port, $config->mode ?? SWOOLE_BASE, $config->type ?? SWOOLE_SOCK_TCP);
        $sslEnabled = isset($config->ssl) && $config->ssl->enabled;
        $options = $sslEnabled ? array_merge($config->options, $config->ssl->toArray()) : $config->options;
        if ($sslEnabled) {
            unset($options['enabled']);
        }
        $this->set($options);
        $this->setupPrivateEvents();
        $this->setupSignals();
    }

    // EVENT MANAGEMENT RELATED METHODS
    private function eventIsPrivate(string $event_name): bool
    {
        return in_array($event_name, $this->privateEvents, true);
    }

    private function eventIsHookable(string $event_name, string $when): bool
    {
        return isset($this->hookableEvents[$event_name]) && in_array($when, $this->hookableEvents[$event_name], true);
    }

    private function onEventHook(string $event_name, callable $callback, string $when = 'after'): bool
    {
        if (!$this->eventIsHookable($event_name, $when)) {
            $this->logger?->warning("Evento $event_name no puede ser agregado en $when");
            return false;
        }
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

    public function on(string $event_name, callable $callback): bool
    {
        if ($this->eventIsPrivate($event_name)) {
            if ($this->eventIsHookable($event_name, 'after') && $this->onAfter($event_name, $callback)) {
                $this->logger?->warning("Evento privado $event_name, acción agregada en after::$event_name");
            } else {
                $this->logger?->warning("Evento privado $event_name, acción no permitido");
            }
            return false;
        }
        return parent::on($event_name, $callback);
    }

    public function off(string $event_name, callable $callback): bool
    {
        if ($this->eventIsPrivate($event_name)) {
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

    private function onPrivateEvent(string $event_name, callable $callback): bool
    {
        return parent::on($event_name, $callback);
    }

    public function runEventActions(string $event_name, array $args, string $when = 'after'): void
    {
        $this->logger?->debug("Buscando acciones para evento $event_name en $when");
        $prop = $when . ucfirst($event_name);
        if (isset($this->eventHooks[$prop])) {
            $this->logger?->debug("Ejecutando acciones para evento $event_name en $when (" . count($this->eventHooks[$prop]) . " acciones)");
            foreach ($this->eventHooks[$prop] as $callback) {
                $callback(...$args);
            }
        }
        $this->logger?->debug("Acciones para evento $event_name en $when ejecutadas");
    }
    // END EVENT MANAGEMENT RELATED METHODS

    //SETUP AND INIT RELATED METHODS
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

    private function initRpcTables(): void
    {

        $this->rpcMethods = new Table(512);
        $this->rpcMethods->column('name', Table::TYPE_STRING, 128);
        $this->rpcMethods->column('description', Table::TYPE_STRING, 255);
        $this->rpcMethods->column('requires_auth', Table::TYPE_INT, 1);
        $this->rpcMethods->column('registered_by_worker', Table::TYPE_INT);
        $this->rpcMethods->column('registered_at', Table::TYPE_INT);
        $this->rpcMethods->column('allowed_roles', Table::TYPE_STRING, 4096);
        $this->rpcMethods->column('only_internal', Table::TYPE_INT, 1);
        $this->rpcMethods->create();
        while ($config = array_shift($this->rpcMethodsQueue)) {
            $this->registerRpcMethod(...$config);
        }

        $this->rpcRequests = new Table(1024);
        $this->rpcRequests->column('request_id', Table::TYPE_STRING, 32);
        $this->rpcRequests->column('fd', Table::TYPE_INT);
        $this->rpcRequests->column('method', Table::TYPE_STRING, 128);
        $this->rpcRequests->column('params', Table::TYPE_STRING, 4096); // JSON
        $this->rpcRequests->column('created_at', Table::TYPE_INT);
        $this->rpcRequests->column('status', Table::TYPE_STRING, 20); // pending, processing, completed, failed
        $this->rpcRequests->create();
    }

    private function initializeOnWorkers(Server $server, int $workerId): void
    {
        $this->workerId = $workerId;
        $this->logger?->info("👷 Worker #{$workerId} iniciado - PID: " . posix_getpid());

        // Inicializar procesadores RPC internos
        $this->initializeRpcInternalProcessors();
    }

    private function setupSignals(): void
    {
        if ($this->signalsConfigured || !extension_loaded('pcntl')) {
            return;
        }

        $workerId = $this->getWorkerId();
        $this->logger?->info("🔧 Configurando handlers de señales en Worker #$workerId...");

        // Solo el proceso maestro debe configurar señales
        pcntl_async_signals(true);

        // SIGTERM - Shutdown graceful
        pcntl_signal(SIGTERM, function (int $signo) {
            $this->logger?->info("📡 Señal SIGTERM recibida, iniciando shutdown...");
            $this->shutdownOnSignal($signo);
        });

        // SIGINT - Ctrl+C
        pcntl_signal(SIGINT, function (int $signo) {
            $workerId = $this->workerId;
            $pid = posix_getpid();
            $this->logger?->info("📡 Señal SIGINT (Ctrl+C) recibida en Worker #$workerId (PID $pid), iniciando shutdown...");
            $this->shutdownOnSignal($signo);
        });

        // SIGUSR1 - Reload
        pcntl_signal(SIGUSR1, function (int $signo) {
            $this->logger?->info("🔄 Señal SIGUSR1 recibida, recargando workers...");
            $this->reload();
        });

        $this->signalsConfigured = true;
        $this->logger?->info('✅ Handlers de señales configurados');
    }

    private function initializeServices(): void
    {
        $this->logger?->debug('Inicializando servicios...');
        $this->startRedisServices();
        $this->initializeRpcInternalProcessors();
        $this->runEventActions('start', [], 'after');
    }

    private function setupPrivateEvents(): void
    {

        $this->onPrivateEvent('start', function () {
            $this->logger?->debug('Iniciando servicios...');
            $this->initializeServices();
        });
        $this->onPrivateEvent('beforeShutdown', function () {
            $this->isShuttingDown = true;
            $workerId = $this->getWorkerId() ?? $this->workerId;
            $this->logger?->debug("🛑 Deteniendo servicios en Worker #$this->workerId...");
            $this->cleanUpServer();
        });
        $this->onPrivateEvent('workerStart', function () {
            $this->initializeOnWorkers($this, $this->getWorkerId());
        });
        $this->onPrivateEvent('workerStop', function () {
            $this->cleanUpRpcProcessors();
        });
        $this->onPrivateEvent('open', [$this, 'handleOpen']);
        $this->onPrivateEvent('message', [$this, 'handleMessage']);
        $this->onPrivateEvent('close', [$this, 'handleClose']);
        $this->onPrivateEvent('request', [$this, 'handleRequest']);
        $this->onPrivateEvent('pipeMessage', [$this, 'handlePipeMessage']);
        $this->onPrivateEvent('task', [$this, 'handleTask']);;
    }

    // END SETUP AND INIT RELATED METHODS

    // SERVER HOOKED METHODS
    public function start(): bool
    {
        $this->serverId = $this->getServerId();
        $workerId = $this->getWorkerId();
        $pid = posix_getpid();
        $this->initRpcTables();
        $this->initPubSubTables();
        $this->isShuttingDown = false;
        $this->isStopped = false;
        $this->logger?->info("🏁 Iniciando servidor {$this->serverId}: Worker #$workerId (PID: $pid)...");
        $this->runEventActions('start', [], 'before');
        $started = parent::start();
        $this->logger?->info('Servidor iniciado');
        $this->runEventActions('start', [], 'after');
        return $started;
    }

    public function stop(int $workerId = -1, bool $waitEvent = false): bool
    {
        $this->isStopped = true;
        $this->logger?->info('Deteniendo servidor...');
        $args = func_get_args();
        $this->runEventActions('stop', $args, 'before');
        $stopped = parent::stop($workerId, $waitEvent);
        $this->logger?->info('Servidor detenido');
        $this->runEventActions('stop', $args, 'after');
        return $stopped;
    }

    public function close(int $fd, bool $reset = false): bool
    {
        $this->logger?->info('Reiniciando servidor...');
        $this->runEventActions('close', [$fd, $reset], 'before');
        $reloaded = parent::close($fd, $reset);
        $this->logger?->info('Servidor reiniciado');
        $this->runEventActions('close', [$fd, $reset], 'after');
        return $reloaded;
    }

    public function shutdown(): bool
    {
        $this->isShuttingDown = true;
        $this->logger?->info('Desconectando clientes...');
        $shutdown = parent::shutdown();
        $this->logger?->info('Servidor detenido');
        $this->runEventActions('shutdown', [], 'after');
        return $shutdown;
    }

    private function shutdownOnSignal(int $signal): void
    {
        static $handled = false;

        if ($handled || $this->isShuttingDown) {
            $this->logger?->info('Shutdown ya en progreso, ignorando señal...');
            return;
        }

        $handled = true;
        $this->logger?->info("Recibido signal {$signal}, cerrando servidor...");
        $this->shutdown();
    }

    public function pause(int $fd): bool
    {
        $this->logger?->info('Pausando servidor...');
        $this->runEventActions('pause', [$fd], 'before');
        $paused = parent::pause($fd);
        $this->logger?->info('Servidor pausado');
        $this->runEventActions('pause', [$fd], 'after');
        return $paused;
    }

    public function resume(int $fd): bool
    {
        $this->isStopped = false;
        $this->logger?->info('Reanudando servidor...');
        $this->runEventActions('resume', [$fd], 'before');
        $resumed = parent::resume($fd);
        $this->logger?->info('Servidor reanudado');
        $this->runEventActions('resume', [$fd], 'after');
        return $resumed;
    }
    // END SERVER HOOKED METHODS

    // SERVER RELATED METHODS
    public function isRunning(): bool
    {
        try {
            $workerId = $this->getWorkerId();
            $workerPid = $this->getWorkerPid($workerId);
            $posixPid = posix_getpid();
            // Verificar si podemos obtener estadísticas
            $stats = $this->stats();
            $running = isset($stats['start_time']) && $stats['start_time'] > 0 && !$this->isStopped && !$this->isShuttingDown;
            if (!$running) {
                $this->logger?->debug("#$workerId Comprobando estado del servidor: PID ={$this->master_pid}, wPID ={$workerPid}, pPID ={$posixPid}, stopped: {$this->isStopped}, shutting down: {$this->isShuttingDown}");
            }
            return $running;
        } catch (\Throwable $e) {
            return false;
        }
        //return $this->master_pid > 0 && posix_kill($this->master_pid, 0) && !$this->isStopped && !$this->isShuttingDown;
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

    private function cleanUpRpcProcessors(string $logPrefix = '🛑'): void
    {
        $workerId = $this->getWorkerId() ?? $this->workerId;
        $this->logger?->debug("$logPrefix Worker #$workerId: Limpiando procesadores RPC...".var_export($workerId, true));
        foreach ($this->rpcInternalProcessors as $processor) {
            if ($processor instanceof RpcInternalPorcessorInterface) {
                $processor->deInit($this);
            }
        }
    }

    private function cleanUpRpcTables(string $logPrefix = '🛑'): void
    {
        $this->rpcMethods->destroy();
        $this->rpcRequests->destroy();
        $this->logger?->info("$logPrefix Tablas RPC eliminadas");
    }

    private function cleanUpPubSub(string $logPrefix = '🛑'): void
    {
        $workerId = $this->getWorkerId();
        if (isset($this->subscribers) && $this->subscribers->valid()) {
            $this->logger?->info("$logPrefix #$workerId Cerrando conexiones de clientes...");
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
            $this->logger?->info("$logPrefix #$workerId -> $closedCount conexiones de clientes cerradas");
        }
        $this->subscribers->destroy();
        $this->channels->destroy();
        $this->logger?->debug("$logPrefix #$workerId Tablas de canales y suscriptores eliminadas");
    }

    private function cleanUpServer(): void
    {
        $workerId = $this->getWorkerId() ?? $this->workerId;
        $this->logger?->info("🧹 #$workerId Limpiando recursos...");
        $this->stopRedisServices("🧹");
        $this->cleanUpPubSub("🧹");
        $this->cleanUpRpcProcessors("🧹");
        //foreach ($this->cancelableCids as $cid) {
        while ($cid = array_shift($this->cancelableCids)) {
            if (Coroutine::exists($cid)) {
                $this->logger?->debug("🧹 #$workerId Cancelando corutina $cid");
                Coroutine::cancel($cid);
            }
        }
        $this->cleanUpRpcTables();
        $this->logger?->info("🧹 #$workerId Recursos limpiados");
    }

    /**
     * Genera un ID único para este servidor
     */
    public function getServerId(): string
    {
        static $serverId = null;
        if ($serverId === null) {
            $serverId = gethostname() . ':' . (getmypid() ?? uniqid(basename(str_replace('\\', '/', static::class)) . ':', false));
        }
        return $serverId;
    }
    // END SERVER RELATED METHODS

    // REDIS RELATED METHODS
    public function isRedisEnabled(): bool
    {
        return $this->redisConfig instanceof RedisConfig && $this->redisConfig->canConnect() && extension_loaded('redis');
    }

    /**
     * Obtiene una instancia de Redis
     * @param bool $replace
     * @return Redis
     */
    public function redis(bool $replace = false): Redis
    {
        if ($replace || !isset($this->redisClient) || !$this->redisClient instanceof Redis) {
            $this->redisClient = new Redis();
            // Si no hay configuración Redis, retornar objeto vacío
            if (!$this->isRedisEnabled()) {
                return $this->redisClient;
            }
            $config = $this->redisConfig->toArray();
            try {
                $connected = $this->redisClient->connect(
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? 6379,
                    $config['timeout'] ?? 2.5
                );

                if (!$connected) {
                    throw new \RuntimeException('No se pudo conectar a Redis');
                }

                if (isset($config['auth'])) {
                    $this->redisClient->auth($config['auth']);
                }

                if (isset($config['database'])) {
                    $this->redisClient->select($config['database']);
                }

                // Verificar que Redis realmente funciona
                if (!$this->redisClient->ping()) {
                    throw new \RuntimeException('No se pudo verificar la conexión a Redis');
                }

                $this->logger?->info('Conexión a Redis establecida');
                return $this->redisClient;

            } catch (\Exception $e) {
                $this->logger?->error('Error conectando a Redis: ' . $e->getMessage());
                return $this->redisClient;
            }
        }
        return $this->redisClient;
    }

    private function stopRedisServices(string $logPrefix = '🛑'): void
    {
        $workerId = $this->getWorkerId();
        if ($this->redisMessageChannel !== null) {
            $this->redisMessageChannel->close();
            $this->redisMessageChannel = null;
            $this->logger?->debug("$logPrefix #$workerId Canal de mensajes Redis cerrado");
        }
        if ($this->redisClient !== null) {
            $this->redisClient->close();
            $this->redisClient = null;
            $this->logger?->info("$logPrefix #$workerId Conexión a Redis cerrada.");
        }
    }

    private function startRedisServices(): bool
    {
        if (!$this->isRedisEnabled()) {
            $this->logger?->warning("No se pueden iniciar los servicios Redis, no se han configurado");
            return false;
        }

        $this->redis(true);
        // Canal para comunicar mensajes Redis entre corutinas
        $this->redisMessageChannel = new Channel(1000);

        $processor = $this->startRedisMessageProcessor();
        if ($processor !== false) {
            $this->cancelableCids[] = $processor;
        }
        $subscriber = $this->startRedisSubscriber();
        if ($subscriber !== false) {
            $this->cancelableCids[] = $subscriber;
        }

        return $processor && $subscriber;;
    }

    /**
     * Procesa mensajes Redis de forma asincrónica
     */
    private function startRedisMessageProcessor(): int|false
    {
        return Coroutine::create(function () {
            while (!$this->isShuttingDown && $this->redisMessageChannel !== null) {
                if ($this->isRunning()) {
                    $redisMessage = $this->redisMessageChannel->pop();
                    if ($redisMessage === false) {
                        continue; // Canal cerrado
                    }
                    try {
                        $this->logger?->debug("Procesando mensaje Redis: " . var_export($redisMessage, true));
                        $this->handleRedisMessage($redisMessage['channel'], $redisMessage['message']);
                    } catch (\Exception $e) {
                        $this->logger?->error('Error procesando mensaje Redis: ' . $e->getMessage());
                    }
                }
            }
        });
    }

    /**
     * Inicia el subscriber de Redis en una corutina bloqueante
     */
    private function startRedisSubscriber(): int|false
    {
        if (!$this->isRedisEnabled()) {
            $this->logger?->debug('Redis deshabilitado, no subscribimos al servicio. ');
            return false;
        }

        return Coroutine::create(function () {
            $this->logger?->info('Iniciando corutina de subscriber Redis...');
            while (!$this->isShuttingDown) {
                try {
                    if (!$this->redisClient->isConnected() || !$this->redisClient->ping()) {
                        $this->logger?->debug('Conexión Redis no establecida');
                        throw new \RuntimeException('Conexión Redis no establecida');
                    }
                    $this->redisClient->setOption(Redis::OPT_READ_TIMEOUT, -1);
                    $this->logger?->info('Subscriber Redis conectado y escuchando canales...');
                    // Usar un timeout para psubscribe para poder salir
                    $this->redisClient->psubscribe([$this->redisChannelPrefix . '*'],
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

                    $this->logger?->warning('Subscriber Redis terminó inesperadamente, reconectando');
                    // Si psubscribe retorna (normalmente no debería), reconectar
                    $this->safeSleep(1);
                    $this->redis(true);

                } catch (\Exception $e) {
                    if (!$this->isShuttingDown) {
                        $this->logger?->error('Error en Redis subscriber: ' . $e->getMessage());
                        $this->safeSleep(2);
                        $this->redis(true);
                    }
                }
            }
            if ($this->redisClient->isConnected()) {
                $this->redisClient->close();
                $this->logger?->debug('Redis subscriber finalizado por shutdown');
            }
        });
    }
    // END REDIS RELATED METHODS

    // WS/PUBSUB RELATED METHODS
    private function handleRedisMessage(string $channel, string $message): void
    {
        try {
            $data = json_decode($message, true);
            if (!$data) {
                $this->logger?->warning('Mensaje Redis no válido: ' . $message);
                return;
            }

            $channelName = $data['channel'] ?? str_replace($this->redisChannelPrefix . '.', '', $channel);
            $metadata = $data['_metadata'] ?? [];
            // Solo transmitir si el mensaje no viene de este servidor
            if (!isset($metadata['origin_server']) || $metadata['origin_server'] !== $this->getServerId()) {
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
        if (!$this->isRunning()) {
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
            $channels = $subscriber ? json_decode($subscriber['channels'], true) : [];

            foreach ($channels as $channel) {
                $this->unsubscribeFromChannel($fd, $channel);
            }

            $this->subscribers->del($fd);
        }
    }

    /**
     * Evento cuando un cliente se conecta
     */
    public function handleOpen(Server $server, Request $request): void
    {
        if ($this->isShuttingDown) {
            return;
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

    /**
     * Evento cuando un cliente se desconecta
     */
    public function handleClose(Server $server, int $fd): void
    {
        $this->logger?->info("Cliente desconectado: FD $fd");
        $this->cleanupClient($fd);
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
            }
        } catch (\Exception $e) {
            $this->logger?->error('Error procesando mensaje: ' . $e->getMessage());
            $this->sendError($frame->fd, 'Error interno del servidor');
        }
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
    // END WS/PUBSUB RELATED METHODS

    // RPC RELATED METHODS
    public function getRpcMethods(): Table
    {
        return $this->rpcMethods;
    }

    public function getRpcMethod(string $method): callable|null
    {
        return $this->rpcMethods->get($method);
    }

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
        return uniqid('rpc_', false) . '_' . (++$this->rpcRequestCounter);
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
                ], JSON_THROW_ON_ERROR);

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
                ], JSON_THROW_ON_ERROR);

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
                ], JSON_THROW_ON_ERROR);
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
        if (!isset($this->rpcHandlers)) {
            $this->rpcHandlers = [];
        }
        if (!isset($this->rpcMethods)) {
            $this->rpcMethodsQueue[] = func_get_args();
            return true;
        }

        // Verificar solo si YA ESTÁ REGISTRADO EN ESTE WORKER
        if (isset($this->rpcHandlers[$method])) {
            $this->logger?->debug("Método $method ya registrado en worker #$workerId");
            return false;
        }

        // Guardar handler en ESTE worker
        $this->rpcHandlers[$method] = $handler;
        $roles = !empty($allowed_roles) ? implode('|', $allowed_roles) : '';
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
            $this->logger?->debug("✅ Método RPC registrado en tabla por worker #$workerId: $method para los roles $roles");
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

    public function registerInternalRpcProcessor(string $processorName, RpcInternalPorcessorInterface $processor): void
    {
        if (isset($this->rpcInternalProcessors[$processorName]) && $this->rpcInternalProcessors[$processorName] === $processor) {
            $this->logger?->warning('Processor ' . $processorName . ' already registered as internal RPC processor. Skipping...');
            return;
        }
        $this->logger?->debug('🥌 Registering internal RPC processor ' . $processorName);
        if ($this->isRunning()) {
            $processor->init($this);
        }
        $this->rpcInternalProcessors[$processorName] = $processor;
        if ($processor->fetchRpcMethods($this)) {
            $this->logger?->debug('🥌 -> Registering RPC methods for internal RPC processor ' . $processorName);
            $this->registerRpcMethods($processor->fetchRpcMethods($this));
        }
    }

    public function getInternalRpcProcessor(string $processorName): ?RpcInternalPorcessorInterface
    {
        return $this->rpcInternalProcessors[$processorName] ?? null;
    }

    public function listInternalRpcProcessors(): array
    {
        return array_keys($this->rpcInternalProcessors);
    }

    private function initializeRpcInternalProcessors(): void
    {
        foreach ($this->rpcInternalProcessors as $processor) {
            $processor->init($this);
        }
    }
    // END RPC RELATED METHODS

    // AUTH RELATED METHODS
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
    // END AUTH RELATED METHODS

}