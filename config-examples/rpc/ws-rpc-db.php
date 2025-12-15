<?php

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Collection\MethodsSetCollection;
use SIG\Server\Fluxus;

$base = [
    [
        'method' => 'db.statement.list',
        'description' => 'Db descriptors list',
        'requires_auth' => false,
        'allowed_roles' => ['ws:user'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            return $server->getInternalRpcProcessor('db')?->process('db.statement.list', $params);
        }
    ],
    [
        'method' => 'db.get.statement.for',
        'description' => 'Info form DB Statement descriptor',
        'requires_auth' => false,
        'allowed_roles' => ['ws:user'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            if (!isset($params['cfg'])) {
                throw new InvalidArgumentException('{db.get.statement.for}  "cfg" is required for this method');
            }
            //db.get,statement.for
            return $server->getInternalRpcProcessor('db')?->process('db.get.statement.for', $params);
        }
    ],
    [
        'method' => 'db.statement.info',
        'description' => 'Db statement info',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin', 'ws:user'],
        'only_internal' => true,
        'handler' => static function (Fluxus $server, $params, $fd) {
            if (!isset($params['cfg'])) {
                throw new InvalidArgumentException('{db.statement.info}  "cfg" is required for this method');
            }
            return $server->getInternalRpcProcessor('db')?->process('db.statement.info', $params);
        }
    ],
    [
        'method' => 'db.processor',
        'description' => 'Database processor for Statements',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin', 'ws:user'],
        'only_internal' => true,
        'handler' => static function (Fluxus $server, $params, $fd) {
            $server->logger->debug("DB request received from: " . $fd);
            return $server->getInternalRpcProcessor('db')?->process('db.processor', $params);
        }
    ],
    [
        'method' => 'db.pool.health',
        'description' => 'DB Pool health status',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin', 'ws:user'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            $db = $server->getInternalRpcProcessor('db');
            return [
                'status' => 'ok',
                'database_health' => $db?->connector->getHealthStatus() ?? [],
                'pool_stats' => $db?->connector->getPoolStats() ?? [],
                'timestamp' => time(),
                'worker_id' => $server->getWorkerId()
            ];
        }
    ],
    [
        'method' => 'db.pool.health.all',
        'description' => 'DB Pool health status for all workers',
        'requires_auth' => true,
        'allowed_roles' => ['ws:admin'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            $workerId = $server->getWorkerId();
            $requestId = uniqid('health_all_', true);
            $timeout = $params['timeout'] ?? 1500;

            $server->logger->info("📡 Recolectando health de TODOS los workers (Request: $requestId)");

            // Inicializar almacenamiento para respuestas
            if (!isset($server->collectResponses)) {
                $server->collectResponses = [];
            }
            $server->collectResponses[$requestId] = [];

            // Canal para esperar respuestas
            $responseChannel = new \Swoole\Coroutine\Channel(
                $server->setting['worker_num'] ?? 4
            );

            // 1. Guardar respuesta local
            $localHealth = [];
            if (isset($server->rpcHandlers['db.pool.health'])) {
                try {
                    $localHealth = $server->rpcHandlers['db.pool.health']($server, [], $fd);
                } catch (\Exception $e) {
                    $localHealth = ['error' => $e->getMessage(), 'worker_id' => $workerId];
                }
            }
            $server->collectResponses[$requestId][$workerId] = [
                'data' => $localHealth,
                'success' => $localHealth['status'] === 'ok' ?? false,
                'timestamp' => $data['timestamp'] ?? time(),
                'source_worker' => $workerId
            ];

            $responseChannel->push($workerId);

            // 2. Enviar broadcast a otros workers
            $message = json_encode([
                'action' => 'broadcast_collect',
                'collect_action' => 'db.pool.health',
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
                'message' => 'Health recolectado',
                'request_id' => $requestId,
                'summary' => $summary,
                'data' => $allResults,
                'current_worker' => $workerId,
                'timestamp' => time()
            ];
        }
    ]
];
$configs = [
    'ws-default' => $base,
    'ws-sigsj' => $base,
    'ws-sigpch' => $base,
    'ws-sigtuc' => $base
];
$collections = array_map(static function ($methods) {
    return MethodsCollection::fromArray($methods);
}, $configs);
return MethodsSetCollection::fromArray($collections);
