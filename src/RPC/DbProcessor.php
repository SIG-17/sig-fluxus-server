<?php

namespace SIG\Server\RPC;

use JsonException;
use PDO;
use Psr\Log\LoggerInterface;
use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Config\MethodConfig;
use SIG\Server\Exception\InvalidArgumentException;
use SIG\Server\Fluxus;
use Swoole\Coroutine\Channel;
use Tabula17\Satelles\Omnia\Roga\Collection\StatementCollection;
use Tabula17\Satelles\Omnia\Roga\Database\Connector;
use Tabula17\Satelles\Omnia\Roga\Database\DbConfig;
use Tabula17\Satelles\Omnia\Roga\Database\DbConfigCollection;
use Tabula17\Satelles\Omnia\Roga\Database\RequestDescriptor;
use Tabula17\Satelles\Omnia\Roga\Exception\ConfigException;
use Tabula17\Satelles\Omnia\Roga\Exception\ConnectionException;
use Tabula17\Satelles\Omnia\Roga\Exception\ExceptionDefinitions;
use Tabula17\Satelles\Omnia\Roga\Exception\StatementExecutionException;
use Tabula17\Satelles\Omnia\Roga\LoaderStorageInterface;
use Tabula17\Satelles\Omnia\Roga\StatementBuilder;
use Tabula17\Satelles\Utilis\Connectable\HealthManagerInterface;
use Tabula17\Satelles\Utilis\Exception\RuntimeException;
use Throwable;

class DbProcessor implements RpcInternalPorcessorInterface
{
    /**
     * @param Connector $connector
     * @param DbConfigCollection $poolCollection
     * @param LoaderStorageInterface $loaderStorage
     * @param LoggerInterface|null $logger
     * @param LoggerInterface|null $db_logger
     * @param HealthManagerInterface|null $healthManager
     * @param string $methodPrefix
     */
    public function __construct(
        public Connector                  $connector,
        public DbConfigCollection         $poolCollection,
        public LoaderStorageInterface     $loaderStorage,
        public ?LoggerInterface           $logger = null,
        private readonly ?LoggerInterface $db_logger = null,
        public ?HealthManagerInterface    $healthManager = null,
        private readonly string           $methodPrefix = 'db'
    )
    {
    }

    /**
     * @throws ConnectionException
     * @throws ConfigException
     * @throws Throwable
     * @throws StatementExecutionException
     * @throws JsonException
     */
    public function process(string $method, array $params): array
    {
        $method = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z]+/', ' ', $method))));
        $this->logger?->debug('Searching for method ' . $method . ' in DbProcessor');
        if (method_exists($this, $method)) {
            return $this->{$method}($params);
        }
        throw new \InvalidArgumentException("Method $method not found");
    }

    /**
     * @throws JsonException
     */
    private function getRequestAndStatementBuilder(array $params): array
    {
        $request = new RequestDescriptor($params);
        $builder = new StatementBuilder(
            statementName: $request->cfg,
            loader: $this->loaderStorage->getLoader(),
            reload: $request->forceReload
        );
        return [$request, $builder];
    }

    /**
     * @throws ConnectionException
     * @throws ConfigException
     * @throws Throwable
     * @throws StatementExecutionException
     * @throws JsonException
     */
    private function dbProcessor(string $cfg, mixed $variant = "*", string $variantMember = 'variant', array $params = []): array
    {
        if (!in_array($variantMember, StatementCollection::$metadataVariantKeywords, true)) {
            throw new InvalidArgumentException('Variant member must be one of: ' . implode(', ', StatementCollection::$metadataVariantKeywords) . '.');
        }

        $params = [
            'cfg' => $cfg,
            'params' => $params,
            $variantMember => $variant
        ];
        /**
         * @var RequestDescriptor $request
         * @var StatementBuilder $builder
         */
        [$request, $builder] = $this->getRequestAndStatementBuilder($params);
        $identifier = $request->getFor();
        $this->logger?->debug('Buscando statement para ' . implode(': ', $identifier));
        $builder->loadStatementBy(...$identifier)?->setValues($request->params ?? []);
        $descriptor = $builder->getDescriptorBy(...$identifier);
        if ($descriptor === null) {
            throw new \InvalidArgumentException(sprintf(ExceptionDefinitions::STATEMENT_NOT_FOUND_FOR_VARIANT->value, implode(': ', $identifier), $request->cfg ?? ''));
        }
        $this->logger?->debug('Buscando conexión para ' . $builder->getMetadataValue('connection'));
        /** @var PDO $conn */
        $conn = $this->connector->getConnection($builder->getMetadataValue('connection'));
        if (!isset($conn)) {
            throw new ConnectionException(sprintf(ExceptionDefinitions::POOL_NOT_FOUND->value, $builder->getMetadataValue('connection')), 500);
        }
        try {
            $stmt = $conn->prepare($builder->getStatement());
            $this->logger?->debug('Statement generado: ' . $builder->getPrettyStatement());
            foreach ($builder->getBindings() as $key => $value) {
                $this->logger?->debug('Binding: ' . $key . ' => ' . $value);
                $stmt->bindValue($key, $value, $builder->getParamType($key)); //bindValue($key, $value);
            }
            try {
                $stmt->execute();
            } catch (Throwable $e) {
                $this->logger?->error('Error executing statement: ' . $e->getMessage());
                $server->db_logger?->error($builder->getPrettyStatement(), [
                    'error' => $e->getMessage(),
                    'connection' => $server->connector->getPoolStats($builder->getMetadataValue('connection')),
                    'bindings' => $builder->getBindings(),
                    'builderParams' => $builder->getParams() ?? [],
                    'request' => $request->toArray()
                ]);
                throw new StatementExecutionException($e->getMessage(), 500, $e);
            }
            $result = [];
            if ($descriptor->canHaveResultset() || $stmt->columnCount() > 0) {
                $this->logger?->debug('Statement have resultset: FETCHING');
                $result[] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Manejar múltiples resultsets (stored procedures)
                while ($stmt->nextRowset()) {
                    if ($stmt->columnCount() > 0) {
                        $result[] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }

                $multiRowset = count($result) > 1;
                if ($multiRowset) {
                    $this->logger?->debug('Statement have multiple resultsets: ' . count($result));
                }
                $result = $multiRowset ? $result : $result[0];
                $total = $multiRowset ? array_sum(array_map('count', $result)) : count($result);
                $this->logger?->debug('Statement have resultset with: ' . $total . ' rows');
            } else {
                $this->logger?->debug('Statement have no resultset: ' . $stmt->rowCount());
                // Para consultas sin resultados
                $affectedRows = $stmt->rowCount();

                if ($descriptor->isInsert()) {
                    $lastInsertId = $conn->lastInsertId();
                    if ($lastInsertId !== false && $lastInsertId !== '0') {
                        $result['lastInsertId'] = $lastInsertId;
                    }
                }

                $result['affectedRows'] = $affectedRows;
                $total = $affectedRows;
            }

            $this->db_logger?->info($builder->getPrettyStatement(), [
                    'connection' => $this->connector->getPoolStats($builder->getMetadataValue('connection')),
                    'bindings' => $builder->getBindings(),
                    'requiredParams' => $builder->getRequiredParams() ?? [],
                    'request' => $request->toArray(),
                    'total' => $total,
                    'multiRowset' => $multiRowset ?? false
                ]
            );
            // Respuesta
            $response = [
                'data' => $result,
                'status' => 200,
                'message' => $builder->getMetadataValue('operation') . ': OK',
                'total' => $total
            ];

            if (isset($multiRowset) && $multiRowset === true) {
                $response['multiRowset'] = true;
                $response['resultsets'] = count($result);
            }
            return $response;

        } catch (Throwable $e) {
            $env = [
                'error' => $e->getMessage(),
                'connection' => $this->connector->getPoolStats($builder->getMetadataValue('connection')),
                'bindings' => $builder->getBindings(),
                'requiredParams' => $builder->getRequiredParams() ?? [],
                'request' => $request->toArray()
            ];
            $this->db_logger?->error($builder->getPrettyStatement(), $env);
            throw $e;
        } finally {
            $this->connector->putConnection($conn);
        }
    }

    /**
     * @throws RuntimeException
     * @throws ConfigException
     * @throws JsonException
     */
    private function dbGetStatementFor(string $cfg, mixed $variant = "*", string $variantMember = 'variant', array $params = []): array
    {
        if (!in_array($variantMember, StatementCollection::$metadataVariantKeywords, true)) {
            throw new InvalidArgumentException('Variant member must be one of: ' . implode(', ', StatementCollection::$metadataVariantKeywords) . '.');
        }

        $params = [
            'cfg' => $cfg,
            'params' => $params,
            $variantMember => $variant
        ];

        /**
         * @var RequestDescriptor $request
         * @var StatementBuilder $builder
         */
        [$request, $builder] = $this->getRequestAndStatementBuilder($params);
        $identifier = $request->getFor();
        $this->logger?->debug('Buscando statement para ' . implode(': ', $identifier));
        $builder->loadStatementBy(...$identifier)?->setValues($request->params ?? []);
        $descriptor = $builder->getDescriptorBy(...$identifier);
        if ($descriptor === null) {
            throw new \InvalidArgumentException(sprintf(ExceptionDefinitions::STATEMENT_NOT_FOUND_FOR_VARIANT->value, implode(': ', $identifier), $request->cfg ?? ''));
        }
        $this->logger?->debug('Buscando conexión para ' . $builder->getMetadataValue('connection'));

        return [
            'cfg' => (string)$request->cfg,
            'statement' => $builder->getStatement(),
            'prettyStatement' => $builder->getPrettyStatement(),
            'bindings' => $builder->getBindings(),
            'requiredParams' => $builder->getRequiredParams() ?? [],
            'optionalParams' => $builder->getOptionalParams() ?? [],
            'sentParams' => $request->params ?? [],
            'descriptor' => $descriptor->toArray(),
            'connection' => $builder->getMetadataValue('connection'),
            'operation' => $builder->getMetadataValue('operation')

        ];
    }

    private function dbPoolList(): array
    {
        return $this->connector->getPoolStats();
    }

    private function dbPoolInfo(array $params): array
    {
        return $this->connector->getPoolStats($params['name']);
    }

    private function dbStatementList(): array
    {
        $statements = $this->loaderStorage->listAvailableStatements();
        return ['statements' => $statements, 'total' => count($statements)];
    }

    private function dbStatementInfo(string $cfg): array
    {
        return $this->loaderStorage->getStatementInfo($cfg);
    }

    public function init(Fluxus $server): void
    {
        $workerId = $server->getWorkerId();
        $prefix = $this->methodPrefix;
        try {
            $this->connector->loadConnections($this->poolCollection);
            foreach ($this->connector->getPoolGroupNames() as $poolGroupName) {
                $this->logger?->info("Pool group $poolGroupName loaded");
            }
            if ($this->connector->getUnreachableConnections()->count() > 0) {
                /** @var DbConfig $connection */
                foreach ($this->connector->getUnreachableConnections() as $connection) {
                    $this->logger?->notice("Connection {$connection->name} unreachable: " . $connection->lastConnectionError ?? 'Unknown error');
                }
            }
            $logger = $server->logger ?? $this->logger;
            //$this->healthManager?->startHealthCheckCycle($server, $server->getWorkerId());
            if ($workerId === false) {
                $logger?->info("Master process es el coordinador de Health. Iniciando ciclo.");
                $server->registerRpcMethod(new MethodConfig([
                        'method' => $prefix . '.failures.retry.task',
                        'handler' => function () use ($server, $prefix) {
                            $taskData = [
                                'type' => 'broadcast_task',
                                'method' => $prefix . '.failures.retry',
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
                        'requires_auth' => true,
                        'allowed_roles' => ['ws:admin'],
                        'description' => 'Forces retry of permanent failures in ALL workers'
                    ])
                );
                $this->healthManager?->registerNotifier(function ($changes) use ($server) {
                    $this->notifyToWorkers($changes, $server);
                });
                $this->healthManager?->startHealthCheckCycle($server, false);

            } else {
                $this->logger?->info("Worker #{$workerId} listo. Health checks a cargo del Master process.");
                // Podemos registrar un método para consultar el estado centralizado
            }
        } catch (Throwable $e) {
            $this->logger?->error('Error inicializando DB Processor: ' . $e->getMessage());
        }
    }

    private function notifyToWorkers(array $failures, Fluxus $server): void
    {
        $prefix = $this->methodPrefix;
        $changes = $failures['data']['recovery_result'];
        // $this->logger?->debug('Changes on DB connections detected: ' . implode(', ', ($failures['data'])));
        /*
         *
                [
                            'worker_id' => $workerId,
                            'recovery_result' => [
                                'recovered' => count($result['pools_up']),
                                'failed' => count($result['pools_down']),
                                'unchanged' => count($result['pools_unchanged'])
                            ],
                            'timestamp' => time()
                        ]
         */
        $total = $changes['recovered'] + $changes['failed'];
        $this->logger->info("🔄 Se detectaron cambios en el estado de $total conexiones. Notificando a todos los workers.");


        // **OPCIÓN A: Usar sendMessage (para workers normales) - RECOMENDADA**
        $message = json_encode([
            'action' => 'rpc',
            'method' => $prefix . '.failures.retry',
            'params' => [],
            'source_fd' => 0,
            'source_worker' => -1,
            'request_id' => uniqid('broadcast_', true),
            'timestamp' => time()
        ], JSON_THROW_ON_ERROR);

        // Enviar a todos los workers vía pipeMessage
        for ($i = 1; $i < $server->setting['worker_num']; $i++) {
            $server->sendMessage($message, $i);
        }

    }

    public function deInit(Fluxus $server): void
    {
        $workerId = $server->getWorkerId();
        $logger = $server->logger ?? $this->logger;
        if ($workerId === false) {
            $logger?->info('Stopping Health Check Cycle for Master process...');
            $logger?->info("Closing ALL DB Processor connections for Master process...");
            $this->healthManager?->stopHealthCheckCycle(1);
        } else {
            $logger?->info("Closing ALL DB Processor connections for Worker #$workerId...");

        }
        $this->connector->closeAllPools();
    }

    public function fetchRpcMethods(Fluxus $server): ?MethodsCollection
    {
        $logger = $server->logger ?? $this->logger;
        $connector = $this->connector;
        $prefix = $this->methodPrefix;
        $methods = MethodsCollection::fromArray(
            [
                [
                    'method' => $prefix . '.statement.list',
                    'description' => 'Db descriptors list',
                    'requires_auth' => false,
                    'allowed_roles' => ['ws:user'],
                    'only_internal' => false,
                    'handler' => function () {
                        return $this->dbStatementList();
                    },
                    'returns' => [
                        'type' => 'array',
                        'description' => 'Lista con los descriptores disponibles para genera consultas',
                    ]
                ],
                [
                    'method' => $prefix . '.statement.info',
                    'description' => 'Db statement info',
                    'requires_auth' => false,
                    'allowed_roles' => ['ws:admin', 'ws:user'],
                    'only_internal' => false,
                    'handler' => function (string $cfg) {
                        if (!isset($cfg)) {
                            throw new InvalidArgumentException('{db.statement.info}  "cfg" is required for this method');
                        }
                        return $this->dbStatementInfo($cfg);
                    },
                    'parameters' => [
                        [
                            'name' => 'cfg',
                            'type' => 'string',
                            'required' => true,
                            'description' => 'ID/Nombre del descriptor SQL',
                        ]
                    ],
                    'returns' => [
                        'type' => 'array',
                    ]
                ],

                [
                    'method' => $prefix . '.get.statement.for',
                    'description' => 'Info form DB Statement descriptor',
                    'requires_auth' => false,
                    'allowed_roles' => ['ws:user'],
                    'only_internal' => false,
                    'handler' => function (string $cfg, mixed $variant = "*", string $variantMember = 'variant', array $params = []) use ($prefix, $logger) {
                        if (!isset($cfg)) {
                            throw new InvalidArgumentException('{' . $prefix . '.get.statement.for}  "cfg" is required for this method');
                        }
                        $logger?->debug("Buscando statement para {$cfg}");
                        //db.get,statement.for
                        return $this->dbGetStatementFor($cfg, $variant, $variantMember, $params);
                    },
                    'parameters' => [
                        [
                            'name' => 'cfg',
                            'type' => 'string',
                            'required' => true,
                            'description' => 'ID/Nombre del descriptor SQL',
                        ],
                        [
                            'name' => 'variant',
                            'type' => 'int|string',
                            'default' => '*',
                            'required' => true,
                            'description' => 'Variant to use for statement (default: * [any])',
                        ],
                        [
                            'name' => 'variantMember',
                            'type' => 'string',
                            'default' => 'variant',
                            'required' => true,
                            'description' => 'Keyword to use for variant member (default: variant)',
                            'enum' => StatementCollection::$metadataVariantKeywords
                        ],
                        [
                            'name' => 'params',
                            'type' => 'array',
                            'required' => false,
                            'description' => 'ParamName => Value pairs to use for statement parameters'
                        ]
                    ]
                ],
                [
                    'method' => $prefix . '.processor',
                    'description' => 'Database processor for Statements',
                    'requires_auth' => false,
                    'allowed_roles' => ['ws:admin', 'ws:user'],
                    'only_internal' => false,
                    'handler' => function (int $fd, string $cfg, mixed $variant = "*", string $variantMember = 'variant', array $params = []) use ($logger) {
                        $logger->debug("DB request received from: " . $fd);
                        return $this->dbProcessor($cfg, $variant, $variantMember, $params);
                    },
                    'parameters' => [
                        [
                            'name' => 'fd',
                            'type' => 'int',
                            'required' => true,
                            'description' => 'Client FD',
                            'injected' => true
                        ],
                        [
                            'name' => 'cfg',
                            'type' => 'string',
                            'required' => true,
                            'description' => 'ID/Nombre del descriptor SQL',
                        ],
                        [
                            'name' => 'variant',
                            'type' => 'int|string',
                            'default' => '*',
                            'required' => true,
                            'description' => 'Variant to use for statement (default: * [any])',
                        ],
                        [
                            'name' => 'variantMember',
                            'type' => 'string',
                            'default' => 'variant',
                            'required' => true,
                            'description' => 'Keyword to use for variant member (default: variant)',
                            'enum' => StatementCollection::$metadataVariantKeywords
                        ],
                        [
                            'name' => 'params',
                            'type' => 'array',
                            'required' => false,
                            'description' => 'ParamName => Value pairs to use for statement parameters'
                        ]
                    ]
                ],
                [
                    'method' => $prefix . '.pool.stats',
                    'description' => 'DB Pool stats',
                    'requires_auth' => false,
                    'allowed_roles' => ['ws:admin', 'ws:user'],
                    'only_internal' => false,
                    'handler' => function (int $workerId) {
                        return [
                            //'status' => 'ok',
                            //'database_health' => $db?->connector->getHealthStatus() ?? [],
                            'pool_stats' => $this->connector->getPoolStats() ?? [],
                            'timestamp' => time(),
                            'worker_id' => $workerId
                        ];
                    },
                    'parameters' => [
                        [
                            'name' => 'workerId',
                            'type' => 'int',
                            'required' => true,
                            'description' => 'Worker ID',
                            'injected' => true
                        ]
                    ]
                ],
                [
                    'method' => $prefix . '.pool.stats.all',
                    'description' => 'DB Pool stats for all workers',
                    'requires_auth' => true,
                    'allowed_roles' => ['ws:admin'],
                    'only_internal' => false,
                    'handler' => static function (Fluxus $server, $fd, $timeout = 1500) use ($prefix) {
                        $workerId = $server->getWorkerId();
                        $requestId = uniqid('health_all_', true);

                        $server->logger->info("📡 Recolectando health de TODOS los workers (Request: $requestId)");

                        // Inicializar almacenamiento para respuestas
                        if (!isset($server->collectResponses)) {
                            $server->collectResponses = [];
                        }
                        $server->collectResponses[$requestId] = [];

                        // Canal para esperar respuestas
                        $responseChannel = new Channel(
                            $server->setting['worker_num'] ?? 4
                        );

                        // 1. Guardar respuesta local
                        $localHealth = [];
                        $handler = $prefix . '.pool.health';
                        if (isset($server->rpcHandlers[$handler])) {
                            try {
                                $localHealth = $server->rpcHandlers[$handler]($server, [], $fd);
                            } catch (\Exception $e) {
                                $localHealth = ['error' => $e->getMessage(), 'worker_id' => $workerId];
                            }
                        }
                        $server->collectResponses[$requestId][$workerId] = [
                            'data' => $localHealth,
                            //'success' => $localHealth['status'] === 'ok' ?? false,
                            'timestamp' => $data['timestamp'] ?? time(),
                            'source_worker' => $workerId
                        ];

                        $responseChannel->push($workerId);

                        // 2. Enviar broadcast a otros workers
                        $message = json_encode([
                            'action' => 'broadcast_collect',
                            'collect_action' => $handler,
                            'request_id' => $requestId,
                            'response_to_worker' => $workerId,
                            'need_response' => true,
                            'timestamp' => time()
                        ], JSON_THROW_ON_ERROR);

                        $totalWorkers = $server->setting['worker_num'] ?? 1;
                        $sentCount = 0;

                        for ($i = 0; $i < $totalWorkers; $i++) {
                            if (($i !== $workerId) && $server->sendMessage($message, $i)) {
                                $sentCount++;
                            }
                        }

                        // 3. Esperar respuestas con timeout
                        $startTime = microtime(true);
                        $expectedResponses = $sentCount;
                        $receivedResponses = 1; // Local

                        while ($receivedResponses < ($expectedResponses + 1)) {
                            $elapsed = (microtime(true) - $startTime) * 1000;

                            if ($elapsed > $timeout) {
                                $server->logger->warning("⏰ Timeout esperando respuestas ($elapsed ms)");
                                break;
                            }

                            // Esperar respuesta en el canal
                            $result = $responseChannel->pop(0.1); // 100ms timeout
                            if ($result !== false) {
                                $receivedResponses++;
                                $server->logger->debug("📥 Respuesta recibida, total: $receivedResponses");
                            }
                        }

                        // 4. Procesar resultados
                        $allResults = $server->collectResponses[$requestId] ?? [];
                        array_walk($allResults, static function (&$result) {
                            if (isset($result['data'])) {
                                $result = $result['data'];
                            }
                        });
                        // Limpiar
                        unset($server->collectResponses[$requestId]);

                        // Calcular estadísticas
                        $summary = [
                            'total_workers' => $totalWorkers,
                            'responded_workers' => count($allResults),
                            'successful' => 0,
                            'failed' => 0
                        ];

                        foreach ($allResults as $wId => $result) {
                            if (isset($result['status']) && $result['status'] === 'ok') {
                                $summary['successful']++;
                            } else {
                                $summary['failed']++;
                            }
                        }

                        return [
                            'status' => 'ok',
                            'message' => 'Pool Stats recolectado',
                            'request_id' => $requestId,
                            'summary' => $summary,
                            'data' => $allResults,
                            'current_worker' => $workerId,
                            'timestamp' => time()
                        ];
                    },
                    'parameters' => [
                        [
                            'name' => 'server',
                            'type' => 'Fluxus',
                            'required' => true,
                            'description' => 'Fluxus server instance',
                            'injected' => true
                        ],
                        [
                            'name' => 'fd',
                            'type' => 'int',
                            'required' => true,
                            'description' => 'Client FD',
                            'injected' => true
                        ],
                        [
                            'name' => 'timeout',
                            'type' => 'int',
                            'required' => false,
                            'default' => 1500,
                        ]
                    ]
                ],
                [
                    'method' => $prefix . '.failures.retry',
                    'description' => 'Forces retry of permanent failures in the database connection pool',
                    'requires_auth' => true,
                    'only_internal' => true,
                    'handler' => static function (int $fd) use ($logger, $connector) {
                        $logger->info("Forcing retry permanent db failures from client {$fd}");
                        return [
                            'status' => 'ok',
                            'message' => 'DB Connector retrying permanent failures: Executed!',
                            'results' => $connector->retryFailedConnections(),
                            'timestamp' => time()
                        ];
                    },
                    'parameters' => [
                        [
                            'name' => 'fd',
                            'type' => 'int',
                            'required' => true,
                            'description' => 'Client FD',
                            'injected' => true
                        ]
                    ]
                ],
                [
                    'method' => $prefix . '.failures.retry.task',
                    'description' => 'Forces retry of permanent failures in ALL workers',
                    'requires_auth' => true,
                    'allowed_roles' => ['ws:admin'],
                    'only_internal' => false,
                    'handler' => static function (Fluxus $server) use ($prefix) {
                        $taskData = [
                            'type' => 'broadcast_task',
                            'method' => $prefix . '.failures.retry',
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
                    'parameters' => [
                        [
                            'name' => 'server',
                            'type' => 'Fluxus',
                            'required' => true,
                            'description' => 'Fluxus server instance',
                            'injected' => true
                        ]
                    ]
                ],
                [

                    'method' => $prefix . '.failures.retry.broadcast',
                    'description' => 'Forces retry of permanent failures in ALL workers',
                    'requires_auth' => true,
                    'allowed_roles' => ['ws:admin'],
                    'only_internal' => false,
                    'handler' => static function ($server, $fd) use ($logger, $connector, $prefix) {
                        $workerId = $server->getWorkerId();
                        $logger->info("📡 Broadcast de reconexión iniciado por cliente {$fd} en worker #{$workerId}");
                        // Ejecutar localmente primero
                        $localResult = [];
                        try {
                            // $localResult = $server->rpcHandlers[$prefix.'.failures.retry']([], $fd);
                            $localResult = $connector->retryFailedConnections();
                            $logger->info("✅ Reconexión ejecutada localmente en worker #{$workerId}");
                        } catch (\Exception $e) {
                            $logger->error("❌ Error en reconexión local: " . $e->getMessage());
                        }

                        // **OPCIÓN A: Usar sendMessage (para workers normales) - RECOMENDADA**
                        $message = json_encode([
                            'action' => 'rpc',
                            'method' => $prefix . '.failures.retry',
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
                    'parameters' => [
                        [
                            'name' => 'server',
                            'type' => 'Fluxus',
                            'required' => true,
                            'description' => 'Fluxus server instance',
                            'injected' => true
                        ],
                        [
                            'name' => 'fd',
                            'type' => 'int',
                            'required' => true,
                            'description' => 'Client FD',
                            'injected' => true
                        ]
                    ]
                ]
            ]
        );
        if (isset($this->healthManager)) {
            $healthManager = $this->healthManager;
            $methods->add(
                [
                    'method' => $prefix . '.health.status',
                    'description' => 'DB Connector health status',
                    'handler' => static function (int $workerId) use ($healthManager) {
                        return [
                            'status' => 'ok',
                            'health' => $healthManager->getHealthStatus(),
                            'timestamp' => time(),
                            'worker_id' => $workerId
                        ];
                    },
                    'parameters' => [
                        [
                            'name' => 'workerId',
                            'type' => 'int',
                            'required' => true,
                            'description' => 'Worker ID',
                            'injected' => true
                        ]
                    ]
                ]);
            $methods->add([
                'method' => $prefix . '.health.history',
                'description' => 'DB Connector health checks history',
                'handler' => static function (int $workerId) use ($healthManager) {
                    return [
                        'status' => 'ok',
                        'health' => $healthManager->getCheckHistory(), //$healthManager->getCheckHistory(),
                        'timestamp' => time(),
                        'worker_id' => $workerId
                    ];
                },
                'parameters' => [
                    [
                        'name' => 'workerId',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Worker ID',
                        'injected' => true
                    ]
                ]
            ]);
            $methods->add([
                'method' => $prefix . '.health.check.now',
                'description' => 'DB Connector health check',
                'handler' => static function (int $workerId, int $fd) use ($healthManager, $logger) {
                    $logger->info("Forcing health check from client {$fd}");
                    // Ejecutar check inmediato
                    $poolHealth = $healthManager->performHealthChecks($workerId);
                    return [
                        'status' => 'ok',
                        'message' => 'Health check executed',
                        'results' => ['env' => $healthManager->getHealthStatus(), 'pool' => $poolHealth],
                        'timestamp' => time()
                    ];
                },
                'parameters' => [
                    [
                        'name' => 'workerId',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Worker ID',
                        'injected' => true
                    ],
                    [
                        'name' => 'fd',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Client FD',
                        'injected' => true
                    ]
                ]
            ]);
        }
        $workerId = $server->getWorkerId();
        $server->logger?->debug("Register RPC methods for worker #{$workerId}:");
        return !$workerId ? $methods : null;
    }
}