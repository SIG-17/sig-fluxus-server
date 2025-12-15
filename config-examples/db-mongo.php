<?php
declare(strict_types=1);
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;

$mongo_base_config = [
    'host' => 'localhost',
    'port' => 27017
];
$mongo_configs = [
    'db-logs' => array_merge($mongo_base_config, [
        'dbname' => 'db-name',
        'username' => 'dbuser',
        'password' => 'dbpass',
        'collection' => 'db_collection'
    ])
];

return  ConnectionCollection::fromArray($mongo_configs);