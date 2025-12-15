<?php

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Collection\MethodsSetCollection;
use SIG\Server\Fluxus;
$base = [
    [
        'method' => 'ping',
        'description' => 'Server response delay in ms.',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            return [
                'message' => 'pong',
                'timestamp' => time(),
                'client_fd' => $fd,
                'server_time' => date('Y-m-d H:i:s'),
                'worker_id' => $server->getWorkerId(),
                'pid' => posix_getpid()
            ];
        }
    ],
    [
        'method' => 'server.stats',
        'description' => 'Server Stats',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            return array_merge($server->getStats(), [
                'uptime' => $server->getUptime(),
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                'worker_id' => $server->getWorkerId(),
                'worker_pid' => posix_getpid()
            ]);
        }
    ],
    [
        'method' => 'client.info',
        'description' => 'Current client connection info',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin', 'ws:user'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            try {
                $info = $server->getClientInfo($fd);
                return [
                    'fd' => $fd,
                    'remote_ip' => $info['remote_ip'] ?? 'unknown',
                    'remote_port' => $info['remote_port'] ?? 0,
                    'connected_at' => $info['connect_time'] ?? 0,
                    'websocket_status' => $info['websocket_status'] ?? 0,
                    'last_time' => $info['last_time'] ?? 0,
                    'worker_id' => $server->getWorkerId()
                ];
            } catch (\Exception $e) {
                throw new \RuntimeException("No se pudo obtener información del cliente");
            }
        }
    ],
    [
        'method' => 'worker.info',
        'description' => 'Current server worker info',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin', 'ws:user'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            return [
                'worker_id' => $server->getWorkerId(),
                'worker_pid' => posix_getpid(),
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'handlers_count' => count($server->rpcHandlers),
                'initialized' => $server->initialized,
                'timestamp' => time()
            ];
        }
    ],
    [
        'method' => 'channels.list',
        'description' => 'Available channels info',
        'requires_auth' => false,
        'allowed_roles' => ['ws:admin', 'ws:user'],
        'only_internal' => false,
        'handler' => static function (Fluxus $server, $params, $fd) {
            $channels = [];
            foreach ($server->channels as $channel) {
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
