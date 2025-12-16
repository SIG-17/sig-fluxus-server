<?php

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Collection\MethodsSetCollection;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\Status;

$base = [
    [
        'method' => 'ws.reload',
        'description' => 'Forces reload of the WebSocket server',
        'requires_auth' => true,
        'allowed_roles' => ['ws:admin'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            $server->logger->info("Forcing reload from client {$fd}");
            // Enviar respuesta
            $response = $server->responseProtocol->getProtocolFor(
                [
                    'type' => $server->responseProtocol->get('rpcResponse'),
                    'id' => $params['id'] ?? '',
                    'status' => Status::success,
                    'result' => [
                        'status' => Status::ok->value ,
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
        }
    ],
    [
        'method' => 'ws.shutdown',
        'description' => 'Server Stats',
        'requires_auth' => true,
        'allowed_roles' => ['ws:admin'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            $server->logger->notice("🛑 Shutdown called from client {$fd}");
            // Ejecutar en corutina para no bloquear
            $server->runInCoroutineIfPossible(function () use ($server, $params, $fd) {
                // Enviar respuesta al cliente antes de shutdown
                $response = $server->responseProtocol->getProtocolFor(
                    [
                        'type' => $server->responseProtocol->get('rpcResponse'),
                        'id' => $params['id'] ?? '',
                        'status' => Status::success,
                        'result' => [
                            'status' => Status::ok->value ,
                            'message' => '🛑 Shutdown initiated',
                        ],
                        '_metadata' => [
                            'timestamp' => time()
                        ]
                    ]
                );
                $server->sendToClient($fd, $response);
                // Pequeña pausa para que el mensaje llegue
                $server->safeSleep(0.05);
                // Ejecutar shutdown
                $server->logger->info("🛑 🔜 Enviando petición de shutdown al servidor...");
                //$server->gracefulShutdown();
                $server->handleShutdownSignal();
            });

            // Retornar inmediatamente
            return [
                'status' => Status::accepted->value,
                'message' => 'Shutdown iniciado',
                'stats' => ['timestamp' => time()]
            ];
        }
    ],
    [
        'method' => 'auth.current.roles',
        'description' => 'Server active roles',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            return [
                'roles' => $server->currentRoles(),
                'stats' => ['timestamp' => time(),
                    'worker_id' => $server->getWorkerId()]
            ];
        }
    ],
    [
        'method' => 'auth.user.roles',
        'description' => 'User assigned roles',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            return $server->userRoles($fd);
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
