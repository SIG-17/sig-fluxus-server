<?php
declare(strict_types=1);

use Tabula17\Satelles\Utilis\Config\RedisConfig;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;

$redisBase = [
    'host' => '127.0.0.1',
    'port' => 6379,
];
$configs = [
    'ws-default' => array_merge($redisBase, ['database' => 7]),
    'db-cache' => array_merge($redisBase, ['database' => 0]),
];

return ConnectionCollection::fromArray($configs, RedisConfig::class);