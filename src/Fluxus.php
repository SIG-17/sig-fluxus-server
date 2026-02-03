<?php

namespace SIG\Server;

use Psr\Log\LoggerInterface;
use Redis;
use SIG\Server\Auth\AuthInterface;
use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\File\FileProcessorInterface;
use SIG\Server\File\FileStorageInterface;
use SIG\Server\Protocol\ProtocolManagerInterface;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\PubSub\PubSubDefinition;
use SIG\Server\Protocol\Response\PubSub\PubSubType;
use SIG\Server\Protocol\Response\Type;
use SIG\Server\PubSub\PubSubManager;
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
use Tabula17\Satelles\Utilis\Exception\RuntimeException;
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
        //'reload',
        'workerStart',
        'workerStop',
    ];
    private array $hookableEvents = [
        'start' => ['before', 'after'],
        'reload' => ['before', 'after'],
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
    public array $cancelableCids = [];
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
    private array $rpcMetadata = [];
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
    private ?FileStorageInterface $fileStorage = null;
    private ?FileProcessorInterface $fileProcessor = null;
    private array $fileTransfers = [];
    //private FileManagerInterface $fileManager;
    private array $protocolsManager = [];

    private array $pipeMessageHandlers = [];
    private array $taskHandlers = [];
    private array $requestHandlers = [];
    private array $messageHandlers = [];
    private array $closeHandlers = [];

    public function __construct(
        TCPServerConfig                  $config,
        // public readonly PubSubDefinition $requestProtocol = new PubSubDefinition(),
        //public readonly Type             $responseProtocol = new Type(),
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
        //$this->addProtocol('pubsub', new PubSubManager($this, $requestProtocol, $responseProtocol, $this->logger));
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

    private function getAllProtocolManagers(): array
    {
        return array_values($this->protocolsManager);
    }

    private function initializeOnWorkers(Server $server, int $workerId): void
    {
        $this->workerId = $workerId;
        $this->logger?->info("👷 Worker #{$workerId} iniciado - PID: " . posix_getpid());

        // Inicializar procesadores RPC internos
        // $this->initializeRpcInternalProcessors();
        foreach ($this->getAllProtocolManagers() as $manager) {
            if ($manager instanceof ProtocolManagerInterface) {
                $manager->initializeOnWorkers();
            }
        }

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
        // $this->initializeRpcInternalProcessors();
        foreach ($this->getAllProtocolManagers() as $manager) {
            if ($manager instanceof ProtocolManagerInterface) {
                $manager->initializeOnStart();
            }
        }
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
            foreach ($this->getAllProtocolManagers() as $manager) {
                if ($manager instanceof ProtocolManagerInterface) {
                    $manager->cleanUpResources();
                }
            }
        });
        $this->onPrivateEvent('open', [$this, 'handleOpen']);
        $this->onPrivateEvent('message', [$this, 'handleMessage']);
        $this->onPrivateEvent('close', [$this, 'handleClose']);
        $this->onPrivateEvent('request', [$this, 'handleRequest']);
        $this->onPrivateEvent('pipeMessage', [$this, 'handlePipeMessage']);
        $this->onPrivateEvent('task', [$this, 'handleTask']);
    }

    /**
     * Configura el sistema de archivos
     */
    /*    public function setFileManager(FileManagerInterface $manager): void
        {
            $this->fileManager = $manager;
            $this->logger?->info('File manager configured');
        }

        public function getFileManager(): ?FileManagerInterface
        {
            return $this->fileManager;
        }*/

    public function addProtocol(string $protocol, ProtocolManagerInterface $manager): void
    {
        if ($this->isRunning()) {
            throw new RuntimeException('Protocols must be defined before starting the server');
        }
        $this->protocolsManager[$protocol] = $manager;

    }

    public function hasProtocol(string $protocol): bool
    {
        return isset($this->protocolsManager[$protocol]);
    }

    public function getProtocolManager(string $protocol): ?ProtocolManagerInterface
    {
        return $this->protocolsManager[$protocol] ?? null;
    }
    public function getProtocolManagers(): array
    {
        return $this->protocolsManager;
    }

    public function getRequestProtocol(string $protocol): ?Action
    {
        return $this->protocolsManager[$protocol]?->protocol;
    }

    public function getResponseTypes(string $protocol): ?Type
    {
        return $this->protocolsManager[$protocol]?->responses;
    }

    // END SETUP AND INIT RELATED METHODS

    // SERVER HOOKED METHODS
    /**
     * @throws RuntimeException
     */
    public function start(): bool
    {
        if (!$this->hasProtocol('pubsub')) {
            $this->addProtocol('pubsub', new PubSubManager($this, new PubSubDefinition(), new PubSubType(), $this->logger));
        }
        $this->serverId = $this->getServerId();
        $workerId = $this->getWorkerId();
        $pid = posix_getpid();
        //   $this->initRpcTables();
        //$this->initPubSubTables();
        $this->isShuttingDown = false;
        $this->isStopped = false;
        $this->logger?->info("🏁 Iniciando servidor {$this->serverId}: Worker #$workerId (PID: $pid)...");
        $this->runEventActions('start', [], 'before');
        $started = parent::start();
        $this->logger?->info('Servidor iniciado');
       // $this->runEventActions('start', [], 'after'); // esto corre en el método initializeServices() que esta programado para hacerlo en el evento 'start'
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

    public function reload(bool $only_reload_taskworker = false): bool
    {
        $this->isStopped = true;
        $this->logger?->info('Recargando servidor...');
        $this->runEventActions('reload', [], 'before');
        $reloaded = parent::reload($only_reload_taskworker);
        $this->runEventActions('reload', [], 'after');
        $this->isStopped = false;
        return $reloaded;
    }

    private function shutdownOnSignal(int $signal): void
    {
        static $handled = false;

        if ($handled || $this->isShuttingDown) {
            $this->logger?->info('Shutdown ya en progreso, ignorando señal...');
            return;
        }

        $handled = true;
        $this->logger?->info("Recibido signal {$signal}, cerrando servidor inmediatamente...");
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
            $stats = @$this->stats();
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
        /**@var $pubsub PubSubManager */
        $pubsub = $this->getProtocolManager('pubsub');
        return [
            'server_id' => $this->getServerId(),
            'clients' => count($this->connections ?? []),
            'channels' => $pubsub?->channels->count(),
            'subscribers' => $pubsub?->subscribers->count(),
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


    private function cleanUpServer(): void
    {
        $workerId = $this->getWorkerId() ?? $this->workerId;
        $this->logger?->info("🧹 #$workerId Limpiando recursos...");
        $this->stopRedisServices("🧹");
        // $this->cleanUpPubSub("🧹");
        //$this->cleanUpRpcProcessors("🧹");

        //foreach ($this->cancelableCids as $cid) {
        while ($cid = array_shift($this->cancelableCids)) {
            if (Coroutine::exists($cid)) {
                $this->logger?->debug("🧹 #$workerId Cancelando corutina $cid");
                Coroutine::cancel($cid);
            }
        }
        foreach ($this->getAllProtocolManagers() as $manager) {
            if ($manager instanceof ProtocolManagerInterface) {
                $manager->cleanUpResources();
            }
        }
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

    /**
     * Maneja mensajes entre workers vía sendMessage
     */
    public function handlePipeMessage(Server $server, int $srcWorkerId, string $message): void
    {
        $currentWorkerId = $this->getWorkerId();
        $this->logger?->debug("📨 PipeMessage recibido en worker #{$currentWorkerId} desde worker #{$srcWorkerId}");

        try {
            $data = json_decode($message, true);
            if (!$data || !isset($data['action'], $this->pipeMessageHandlers[$data['action']])) {
                return;
            }
            $this->pipeMessageHandlers[$data['action']]($server, $data);
            /*  if ($data['action'] === 'rpc' && isset($data['method'])) {
                  $this->handleBroadcastRpc($data, $srcWorkerId);
              }

              if ($data['action'] === 'broadcast_collect') {
                  $this->handleBroadcastCollect($data, $srcWorkerId);
              }
              if ($data['action'] === 'collect_response') {
                  $this->handleCollectResponse($server, $data, $srcWorkerId);
              }*/

        } catch (\Exception $e) {
            $this->logger?->error("❌ Error en pipeMessage: " . $e->getMessage());
        }
    }

    public function registerPipeMessageHandler(string $action, callable $handler): void
    {
        $this->pipeMessageHandlers[$action] = $handler;
    }

    public function handleTask(Server $server, Task $task): void
    {
        $data = $task->data;
        $endMessage = 'Task processed';

        $handler = $this->taskHandlers[$data['type']];
        if ($handler) {
            $endMessage = $handler($server, $data);
            if (!is_string($endMessage)) {
                $endMessage = 'Task processed in handler';
            }
        } else {
            $endMessage = 'Task not found, cannot execute';
        }
        $task->finish(['status' => 'processed', 'message' => $endMessage]);
        /*
                switch ($data['type'] ?? '') {
                    case 'broadcast_task':
                        if (isset($data['method']) && isset($this->rpcHandlers[$data['method']])) {
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
                            $endMessage = 'Broadst task processed. Sent to all workers (' . $totalWorkers . ')';
                        }
                        break;
                    /* case 'assemble_file':
                         $this->assembleFileTask($data['file_id']);
                         break;

                     case 'process_file':
                         $this->processFileTask(
                             $data['file_id'],
                             $data['file_info'],
                             $data['metadata']
                         );
                         break;
        }
        $task->finish(['status' => 'processed', 'message' => $endMessage]);*/
    }

    public function registerTaskHandler(string $type, callable $handler): void
    {
        $this->taskHandlers[$type] = $handler;
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

        return $processor && $subscriber;
    }

    /**
     * Procesa mensajes Redis de forma asincrónica
     */
    private function startRedisMessageProcessor(): int|false
    {
        /**
         * @var PubSubManager $manager
         */
        $manager = $this->getProtocolManager('pubsub');
        if (!isset($manager)) {
            return false;
        }
        return Coroutine::create(function () use ($manager) {
            while (!$this->isShuttingDown && $this->redisMessageChannel !== null) {
                if ($this->isRunning()) {
                    $redisMessage = $this->redisMessageChannel->pop();
                    if ($redisMessage === false) {
                        continue; // Canal cerrado
                    }
                    try {
                        $this->logger?->debug("Procesando mensaje Redis: " . var_export($redisMessage, true));
                        $manager->handleRedisMessage($redisMessage['channel'], $redisMessage['message']);
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

    // HTTP RELATED METHODS

    /**
     * Maneja requests HTTP (para health checks, etc.)
     */
    public function handleRequest(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'] ?? '/';
        if (isset($this->requestHandlers[$path])) {
            $this->requestHandlers[$path]($request, $response);
        } else {
            $response->status(404);
            $response->end('Not Found');
        }

        /* switch ($path) {
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
 cleanUpRpcProcessors
             default:
                 $response->status(404);
                 $response->end('Not Found');
         }*/
    }

    public function registerRequestHandler(string $path, callable $handler): void
    {
        $this->requestHandlers[$path] = $handler;
    }


    // END HTTP RELATED METHODS

    // WS/PUBSUB RELATED METHODS
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

        foreach ($this->getAllProtocolManagers() as $protocolManager) {
            $protocolManager->runOnOpenConnection($server, $request);
        }
    }

    /**
     * Evento cuando un cliente se desconecta
     */
    public function handleClose(Server $server, int $fd): void
    {
        $this->logger?->info("Cliente desconectado: FD $fd");
        foreach ($this->protocolsManager as $protocol => $protocolManager) {
            $this->logger?->debug("Desconectando cliente $fd de Protocolo #$protocol");
            $protocolManager->runOnCloseConnection($server, $fd);
        }

    }

    /**
     * Evento cuando se recibe un mensaje del cliente
     */
    public function handleMessage(Server $server, Frame $frame): void
    {
        try {
            $data = json_decode($frame->data, true);
            if (!$data || !isset($data['action'], $this->messageHandlers[$data['action']])) {
                $cause = $data['action'] ?? 'no action';
                if ($cause !== 'no action') {
                    $cause = !isset($this->messageHandlers[$data['action']]) ? 'no processor for ' . $data['action'] : 'unknow';
                }
                $this->sendError($frame->fd, 'El mensaje no puede procesarse ' . $cause);
                return;
            }

            $this->logger?->debug("Mensaje recibido de FD {$frame->fd}: " . $frame->data);
            $handler = $this->messageHandlers[$data['action']];
            $handler($server, $frame);

            /*
              $rpcProtocol = $this->requestProtocol->getProtocolFor($data);
              $this->logger?->debug("Protocolo de solicitud: " . get_class($rpcProtocol));
              if ($rpcProtocol instanceof RequestHandlerInterface) {
                  $rpcProtocol->handle($frame->fd, $this);
              } else {

                  $this->sendError($frame->fd, 'Acción no reconocida: ' . $data['action']);
              }
            */
        } catch (\Exception $e) {
            $this->logger?->error('Error procesando mensaje: ' . $e->getMessage());
            $this->sendError($frame->fd, 'Error interno del servidor');
        }
    }

    public function registerMessageHandler(string $action, callable $handler): void
    {
        $this->messageHandlers[$action] = $handler;
    }

    /**
     * Envía mensaje de éxito al cliente
     * @throws UnexpectedValueException
     */
    public function sendSuccess(int $fd, string $message): void
    {
        $responseTypes = new Type();
        $response = $responseTypes->getProtocolFor([
            'type' => $responseTypes->get('success'),
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
        $responseTypes = new Type();
        $response = $responseTypes->getProtocolFor([
            'type' => $responseTypes->get('error'),
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
    // END RPC RELATED METHODS

    // PROTOCOL RELATED METHODS
    /**
     *
     * @throws UnexpectedValueException
     */
    public function sendProtocolResponse(string $protocol, string $protocolResponse, int $fd, array $data): void
    {
        //$server->getResponseTypes($this->rpcProtocol::getProtocolName());
        $responseProtocol = $this->getResponseTypes($protocol);
        if (!$responseProtocol && !$responseProtocol->offsetExists($protocolResponse)) {
            $this->sendError($fd, 'Unknow response for rpcProtocol: ' . $protocolResponse);
        } else {
            $response = $responseProtocol->getProtocolFor(array_merge($data, ['type' => $responseProtocol->get($protocolResponse)]));
            if ($response->isValid()) {
                $this->sendToClient($fd, $response);
            } else {
                $this->sendError($fd, 'Invalid response for rpcProtocol: ' . $protocolResponse);
            }
        }
    }
    // END PROTOCOL RELATED METHODS

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