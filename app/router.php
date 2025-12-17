#!/usr/local/bin/php
<?php
declare(strict_types=1);

use SIG\Server\Protocol\Status;
use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Formatter\MongoDBFormatter;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use SIG\Server\Auth\FakeAuth;
use SIG\Server\Collection\ChannelSetCollection;
use SIG\Server\Collection\MethodsSetCollection;
use SIG\Server\Config\ChannelConfig;
use SIG\Server\RPC\DbProcessor;
use SIG\Server\Fluxus;
use Swoole\Runtime;
use Tabula17\Satelles\Omnia\Roga\Database\Connector;
use Tabula17\Satelles\Omnia\Roga\Database\HealthManager;
use Tabula17\Satelles\Omnia\Roga\Loader\XmlStatements;
use Tabula17\Satelles\Utilis\Cache\RedisStorage;
use Tabula17\Satelles\Utilis\Collection\TCPServerCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;
use Tabula17\Satelles\Utilis\Log\Handler\MongoDbHandler;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Argumentos:
 * -v       Modo verbose
 * --ws-config Instancia de configuración de los servicios de websocket
 * -h       IP en la cual escucha el servidor websocket (sobreescribe definido en config)
 * -p       Puerto en la cual escucha el servidor websocket (sobreescribe definido en config)
 *
 * Para matar todos los procesos del script php:
 * kill -9 $(ps aux | grep 'php.*router.php' | awk '{print $2}')
 *
 */
$arguments = getopt("v:h:p:", ["ws-config:", 'help']);

if (array_key_exists('help', $arguments)) {

    $logger = new Monolog\Logger('ws-server');
    $handler = new Monolog\Handler\StreamHandler('php://stderr', Level::Info);
    $handler->setFormatter(new ColoredLineFormatter(
            format: "%message%\n",
    ));
    $logger->pushHandler($handler);
    $logger->info("Servicios WebSocket del ecosistema SIG");
    $logger->error("Uso: php " . basename(__FILE__) . " [opciones]");
    $logger->notice("Opciones:");
    $logger->warning("\t--ws-config   Instancia de configuración de los servicios de websocket y redis. Por defecto 'default'.\rEl nombre final de la instancia agrega el prefijo 'ws-'");
    //$logger->warning("\t--ws-channel  Canal de Redis para las subscripciones entre servicios. Por defecto 'channel' ('ws-channel' en config)");
    $logger->warning("\t-h            IP en la cual escucha el servidor websocket (sobreescribe definido en config)");
    $logger->warning("\t-p            Puerto en la cual escucha el servidor websocket (sobreescribe definido en config)");
    $logger->warning("\t-v(v|vv|vvv)  Modo verbose");

    exit(0);
}

$prefix = 'ws-';
$instance = $arguments["ws-config"] ?? 'default';
$instance = $prefix . $instance;
$redisChannel = $arguments["ws-config"] ?? 'default';
$redisChannel = $prefix . $redisChannel;
//echo $arguments['v'] ?? 'no debug' . "\n";
$debugLevel = Level::Error;
if (array_key_exists('v', $arguments)) {
    $debugLevel = Level::Notice;
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    if ($arguments['v'] === 'v') {
        $debugLevel = Level::Info;
    }
    if ($arguments['v'] === 'vv') {
        $debugLevel = Level::Debug;
    }
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');
    ini_set('swoole.display_errors', 'On');
}

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
/** @var TCPServerCollection $wsConfig */
$wsConfig = require __DIR__ . '/../config/ws-config.php';
$redisConfig = require __DIR__ . '/../config/ws-redis.php';
$logger = new Monolog\Logger($instance);
$handler = new Monolog\Handler\StreamHandler('php://stderr', $debugLevel);
$handler->setFormatter(new ColoredLineFormatter());
$logger->pushHandler($handler);

global $server;

// DB MANAGER ->
$mongoConfigs = require __DIR__ . '/../config/db-mongo.php';
/** @var   ConnectionConfig $db_log_config */
$db_log_config = $mongoConfigs['db-logs'];
$db_logger = new Monolog\Logger('db-exec');
$db_handler = new MongoDbHandler(
        host: $db_log_config->host,
        port: $db_log_config->port,
        database: $db_log_config->dbname,
        user: $db_log_config->username ?? null,
        password: $db_log_config->password ?? null,
        collection: $db_log_config->collection,
        ttl: 3600,
        level: Level::Info

);
$db_handler->setFormatter(new MongoDBFormatter());
$db_logger->pushHandler($db_handler);

$poolCollection = require __DIR__ . '/../config/db-pool.php';
$xmlDir = __DIR__ . '/../db/xml';
$connector = new Connector(
        logger: $logger
);
$cacheManager = new RedisStorage($redisConfig['db-cache']);
$loaderStorage = new XmlStatements(
        baseDir: $xmlDir,
        cacheManager: $cacheManager,
        logger: $logger
);

$db = new DbProcessor(
        connector: $connector,
        poolCollection: $poolCollection,
        loaderStorage: $loaderStorage,
        logger: $logger,
        db_logger: $db_logger
);


$healthManager = null; /*new HealthManager(
        connector: $db->connector,
        checkInterval: 30000, // 30 segundos
        logger: $logger
);*/

$auth = new FakeAuth();

function handle_shutdown(LoggerInterface $logger, HealthManager $healthManager, Connector $connector): void
{
    static $handled = false;
    if ($handled) {
        return;
    }
    $handled = true;
    $connector->closeAllPools();
    $logger->info("🛑 Shutdown signal recibido");
    $healthManager?->stopGracefully();
    // $server?->shutdown();
    // Aquí puedes agregar limpieza personalizada si es necesaria
    $logger->info("✅ Limpieza completada");
}

//<-- DB MANAGER
try {
    $logger->info("🚀 Iniciando servidor WebSocket...");

    if ($wsConfig->isEmpty() || !$wsConfig->offsetExists($instance)) {
        throw new InvalidArgumentException('No se ha configurado el servidor WebSocket');
    }
    if (!$redisConfig->isEmpty() && $redisConfig->offsetExists($redisChannel)) {
        $redis = $redisConfig[$redisChannel];
        $logger->debug("Canal de Redis $redisChannel configurado: $redis->host:$redis->port");
    } else {
        $redis = null;
        $logger->warning("No se ha configurado el canal de Redis para el servidor WebSocket");
    }

    $override = [];

    if (isset($arguments['h'])) {
        $override['host'] = $arguments['h'];
    }
    if (isset($arguments['p'])) {
        $override['port'] = $arguments['p'];
    }

    if (!empty($override)) {
        $wsConfig[$instance]->loadProperties($override);
    }
    // Configurar manejadores de señales globales
    if (extension_loaded('pcntl')) {
        declare(ticks=1);

        /*pcntl_signal(SIGINT, function() use ($server, $logger) {
            $logger->info("SIGINT recibido, iniciando shutdown...");
            if (isset($server) && $server instanceof Ws) {
               // $server->gracefulShutdown();
                $server->shutdown();
            }
            exit(0);
        });

        pcntl_signal(SIGTERM, function() use ($server, $logger) {
            $logger->info("SIGTERM recibido, iniciando shutdown...");
            if (isset($server) && $server instanceof Ws) {
               // $server->gracefulShutdown();
                $server->shutdown();
            }
            exit(0);
        });

        pcntl_signal(SIGHUP, function() use ($server, $logger) {
            $logger->info("SIGHUP recibido, recargando...");
            if (isset($server) && $server instanceof Ws) {
                $server->reload();
            }
        });*/
    }
    $server = new Fluxus(
            config: $wsConfig[$instance],
            auth: $auth,
            redisConfig: $redis,
            logger: $logger
    );
    $server->redisChannelPrefix = $redisChannel;
    $logger->info("✅ Servidor WebSocket iniciado en {$server->host}:{$server->port}");
    $logger->info("🎛️ * PID: " . getmypid());
    $logger->info("💡 Use 'systemctl stop servicio-websocket' o Ctrl+C para detener");


    $server->registerInternalRpcProcessor('db', $db);


    $logger->info('🛣️ Buscando métodos RPC para inicializar, instancia: ' . $instance . '');
    $methodsCfgIterator = new RecursiveDirectoryIterator(__DIR__ . '/../config/rpc');
    $recursiveMethodsIterator = new RecursiveIteratorIterator($methodsCfgIterator);
    foreach ($recursiveMethodsIterator as $file) {
        if ($file->getExtension() === 'php') {
            $methodCfg = require $file->getPathname();
            if ($methodCfg instanceof MethodsSetCollection && $methodCfg->offsetExists($instance)) {
                $server->registerRpcMethods($methodCfg[$instance]);
            }
        }
    }

    // Handler RPC para shutdown
    $server->registerRpcMethod('ws.shutdown', function (Fluxus $server, $data, $fd) use ($logger) {
        $logger->info("Shutdown solicitado via RPC desde FD $fd");
/*
        if ($server->isShuttingDown) {
            return [
                    'status' => 'already_in_progress',
                    'message' => 'Shutdown ya en progreso'
            ];
        }*/

        // Enviar respuesta inmediata al cliente
        $response = $server->responseProtocol->getProtocolFor([
                'type' => $server->responseProtocol->get('success'),
                'message' => 'Shutdown iniciado',
                'timestamp' => time()
        ]);
        $server->sendToClient($fd, $response);

        // Pequeña pausa para que el mensaje llegue
        $server->safeSleep(0.1);

        // Iniciar shutdown
        $server->shutdown();

        return null; // Ya enviamos la respuesta
    });
    // Handler RPC para shutdown
    $server->registerRpcMethod('ws.reload', function (Fluxus $server, $data, $fd) use ($logger) {
        $server->logger->info("Forcing reload from client {$fd}");
        // Enviar respuesta
        $response = $server->responseProtocol->getProtocolFor(
                [
                        'type' => $server->responseProtocol->get('rpcResponse'),
                        'id' => $params['id'] ?? '',
                        'status' => Status::success,
                        'result' => [
                                'status' => Status::ok->value,
                                'message' => 'Reload executed',
                                'timestamp' => time()
                        ],
                        '_metadata' => [
                                'timestamp' => time()
                        ]
                ]
        );
        $server->sendToClient($fd, $response);
        // Pequeña pausa
        //Coroutine::sleep(0.05);
        $server->safeSleep(0.05);
        // Ejecutar reload
        $server->reload();
        return null; // Ya enviamos la respuesta
    });

    $server->registerRpcMethod(method: 'math.calculate', handler: function ($server, $params, $fd) {
        $operation = $params['operation'] ?? 'add';
        $numbers = $params['numbers'] ?? [];

        if (empty($numbers)) {
            throw new \InvalidArgumentException('Se requieren números para calcular');
        }

        if (($params['simulate_delay'] ?? false) === true) {
            //Coroutine::sleep(0.1);
            $server->safeSleep(0.1);
        }
        $result = match ($operation) {
            'add' => array_sum($numbers),
            'multiply' => array_product($numbers),
            'average' => array_sum($numbers) / count($numbers),
            'min' => min($numbers),
            'max' => max($numbers),
            default => throw new \InvalidArgumentException("Operación no soportada: $operation"),
        };

        return [
                'operation' => $operation,
                'numbers' => $numbers,
                'result' => $result,
                'calculated_at' => time(),
                'worker_id' => $this->getWorkerId()
        ];
    }, description: 'Math RPC method. Usage: math.calculate?operation=add&numbers=1,2,3. Available operations: add, multiply, average, min, max.');
    $server->registerRpcMethod('random.uuid', function ($server, $params, $fd) use ($instance) {
        return [
                'message' => uniqid($instance, true),
                'timestamp' => time(),
                'client_fd' => $fd,
                'server_time' => date('Y-m-d H:i:s'),
                'worker_id' => $server->getWorkerId(),
                'pid' => posix_getpid()
        ];
    });
    if ($server->setting['task_worker_num'] > 0) {
        $server->registerRpcMethod(
                method: 'db.failures.retry.task',
                handler: function ($params, $fd) use ($server, $logger) {
                    $workerId = $server->getWorkerId();

                    // Enviar task a UN task worker (no especificar ID, Swole lo distribuye)
                    $taskData = [
                            'type' => 'broadcast_task',
                            'method' => 'db.failures.retry',
                            'broadcast_to_all' => true,
                            'timestamp' => time()
                    ];

                    $taskId = $server->task($taskData);
                    return [
                            'status' => 'ok',
                            'message' => 'Task de reconexión enviada',
                            'task_id' => $taskId,
                            'timestamp' => time()
                    ];
                },
                requires_auth: true,
                allowed_roles: ['ws:admin'],
                description: 'Forces retry of permanent failures in ALL workers'
        );
    }
    $server->registerRpcMethod(
            method: 'db.failures.retry.broadcast',
            handler: function ($server, $params, $fd) use ($logger, $healthManager) {
                $workerId = $server->getWorkerId();
                $logger->info("📡 Broadcast de reconexión iniciado por cliente {$fd} en worker #{$workerId}");

                // Ejecutar localmente primero
                $localResult = [];
                try {
                    // $localResult = $server->rpcHandlers['db.failures.retry']([], $fd);
                    $localResult = $healthManager->retryPermanentFailures($workerId);
                    $logger->info("✅ Reconexión ejecutada localmente en worker #{$workerId}");
                } catch (\Exception $e) {
                    $logger->error("❌ Error en reconexión local: " . $e->getMessage());
                }

                // **OPCIÓN A: Usar sendMessage (para workers normales) - RECOMENDADA**
                $message = json_encode([
                        'action' => 'rpc',
                        'method' => 'db.failures.retry',
                        'params' => [],
                        'source_fd' => $fd,
                        'source_worker' => $workerId,
                        'request_id' => uniqid('broadcast_', true),
                        'timestamp' => time()
                ], JSON_THROW_ON_ERROR);

                $sentCount = 0;
                $totalWorkers = $server->setting['worker_num'] ?? 1;

                for ($i = 0; $i < $totalWorkers; $i++) {
                    if ($i !== $workerId) {
                        try {
                            if ($server->sendMessage($message, $i)) {
                                $sentCount++;
                                $logger->debug("📨 Mensaje enviado al worker #{$i}");
                            }
                        } catch (\Exception $e) {
                            $logger->warning("⚠️ No se pudo enviar al worker #{$i}: " . $e->getMessage());
                        }
                    }
                }

                return [
                        'status' => 'ok',
                        'message' => 'Comando de reconexión enviado a todos los workers',
                        'local_executed' => !empty($localResult),
                        'broadcasted_to' => $sentCount . ' workers',
                        'total_workers' => $totalWorkers,
                        'current_worker' => $workerId,
                        'timestamp' => time()
                ];
            },
            requires_auth: true,
            allowed_roles: ['ws:admin'],
            description: 'Forces retry of permanent failures in ALL workers'
    );
    $server->registerRpcMethod(
            method: 'db.failures.retry',
            handler: function ($server, $params, $fd) use ($healthManager, $logger) {
                $logger->info("Forcing retry permanent db failures from client {$fd}");
                return [
                        'status' => 'ok',
                        'message' => 'Health check executed',
                        'results' => $healthManager->retryPermanentFailures($server->getWorkerId()),
                        'timestamp' => time()
                ];
            },
            requires_auth: true,
            allowed_roles: ['ws:admin'],
            description: 'Forces retry of permanent failures in the database connection pool',
            only_internal: true
    );
    $server->registerRpcMethod('health.status', function ($server, $params, $fd) use ($healthManager) {
        return [
                'status' => 'ok',
                'health' => $healthManager->getHealthStatus(),
                'timestamp' => time(),
                'worker_id' => $server->getWorkerId()
        ];
    });
    $server->registerRpcMethod('health.check.now', function ($server, $params, $fd) use ($healthManager, $logger) {
        $logger->info("Forcing health check from client {$fd}");
        // Ejecutar check inmediato
        $healthManager->performHealthChecks($server->getWorkerId());
        return [
                'status' => 'ok',
                'message' => 'Health check executed',
                'results' => $healthManager->getHealthStatus(),
                'timestamp' => time()
        ];
    });


    $server->onAfter('start', function () use ($logger, $instance, $server) {
        $logger->info('🛣️ Buscando canales para inicializar, instancia: ' . $instance . '');
        $channelCfgIterator = new RecursiveDirectoryIterator(__DIR__ . '/../config/channels');
        $recursiveIterator = new RecursiveIteratorIterator($channelCfgIterator);
        foreach ($recursiveIterator as $file) {
            if ($file->getExtension() === 'php') {
                $channelCfg = require $file->getPathname();
                if ($channelCfg instanceof ChannelSetCollection && $channelCfg->offsetExists($instance)) {
                    foreach ($channelCfg[$instance] as $channel) {
                        if ($channel instanceof ChannelConfig) {
                            $logger->debug("Inicializando canal: {$channel->name}: " . json_encode($channel->toArray(), JSON_THROW_ON_ERROR));
                            $server->addChannel(
                                    channel: $channel->name,
                                    requireAuth: $channel->required_auth ?? false,
                                    requireRole: $channel->required_role ?? null,
                                    autoSubscribe: $channel->auto_subscribe ?? false,
                            );
                        }
                    }
                }
            }
        }
    });
    // **OPCIONAL: Callback para cuando se inicia el servidor**
   /* $server->on('start', function (Fluxus $server) use ($logger) {
        $logger->debug("📡 Servidor WebSocket ejecutándose...");
        foreach ($server->getStats() as $stat => $value) {
            $logger->debug("🚼 {$stat}: " . var_export($value, true));
        }
        // Aquí puedes iniciar servicios adicionales que necesiten correr después del start
        // Pero NO registres señales aquí
    });*/
    // **OPCIONAL: Callback para worker start (si usas modo process)**
    $server->onBefore('workerStart', function (Fluxus $server, int $workerId) use ($logger) {
        $logger->info("👷 Worker #{$workerId} iniciando PID: {$server->getWorkerPid($workerId)}");
    });
    $server->onAfter('workerStart', function (Fluxus $server, int $workerId) use ($logger, $healthManager) {
        $logger->debug("👷 --> Worker #{$workerId} status: " . $server->getWorkerStatus($workerId));
        // Solo workers principales (no task workers)
        if ($workerId < $server->setting['worker_num']) {
            // Iniciar health checks con escalonamiento automático
            //  $healthManager->setupServerTick($server, $workerId);
        }
    });

    $server->onBefore('workerStop', function (Fluxus $server, int $workerId) use ($logger, $connector) {
        $logger->debug("Worker #{$workerId} deteniéndose...");

        // Solo workers de aplicación (no task workers)
//        if ($workerId < $server->setting['worker_num']) {
//            $logger->debug('🔐 Worker #' . $workerId . ' Closing database connections...');
//            $connector->closeAllPools();
//        }

        $logger->info("🙅🏼‍♂️ Worker #$workerId finalizado");
    });

    $server->onAfter('workerStop', function (Fluxus $server, int $workerId) use ($logger) {
        $logger->debug("🙅🏼‍♂️ Worker #{$workerId} finalizado");
    });


    $server->onBefore('shutdown', function () use ($logger, $healthManager, $connector, $server) {
        $workerId = $server->getWorkerId();
        $logger->debug("🛑 Worker #$workerId: Graceful shutdown starting...");

        // 1. Cerrar conexiones de DB
        $logger->debug('🔐 Closing database connections...');
        $connector->closeAllPools();

        // 2. Pequeña pausa
        $server->safeSleep(0.05);
    });
    $server->on('shutdown', function () use ($logger) {
        $logger->debug("🛑 Servidor apagado gracefulmente");
    });

    $server->onAfter('finish', function (Fluxus $server, int $taskId, $data) {
        $server->logger?->debug("Task #{$taskId} completado");
    });
    // Iniciar el servidor
    $server->start();

} catch (Throwable $e) {
    $logger->error("❌ Error iniciando servidor: " . $e->getMessage());
    exit(1);
}