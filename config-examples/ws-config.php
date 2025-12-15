<?php

//TCPServerConfig
use Tabula17\Satelles\Utilis\Collection\TCPServerCollection;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

$base_config = [
    'host' => '127.0.0.1',
    'port' => 9800,
    'options' =>[
        'enable_coroutine' => true,
        'task_enable_coroutine' => true,  // Para usar tasks en corutinas
        'task_worker_num' => 2,  // Opcional: workers para tareas
        'dispatch_mode' => 2,
    ]
];
$configs = [
    'ws-default' => array_merge($base_config, ['mode' => SWOOLE_PROCESS]),
    'ws-sigsj' => array_merge($base_config, ['port' => 9801, 'mode' => SWOOLE_PROCESS]),
    'ws-sigpch' => array_merge($base_config, ['port' => 9802, 'mode' => SWOOLE_PROCESS])
];

return TCPServerCollection::fromArray($configs, TCPServerConfig::class);