<?php
declare(strict_types=1);

use Tabula17\Satelles\Omnia\Roga\Database\DbConfigCollection;
use Tabula17\Satelles\Omnia\Roga\Database\DriversEnum;

include __DIR__ . '/../vendor/autoload.php';


$baseConfigs = [
    DriversEnum::SQLSRV->value => [
        'description' => 'SQLSRV development',
        'driver' => DriversEnum::SQLSRV,
        'host' => '0.0.0.0',
        'port' => 1433,
        'username' => 'dbuser',
        'password' => 'dbpass',
        'dbname' => 'DB',
        'options' => [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            PDO::SQLSRV_ATTR_FETCHES_DATETIME_TYPE => true,

            PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
            PDO::SQLSRV_ATTR_DIRECT_QUERY => false, // Importante para SP
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,


        ],
        'maxConnections' => 3
    ],
    DriversEnum::MYSQL->value => [
        'description' => 'MYSQL development',
        'driver' => DriversEnum::MYSQL,
        'host' => '0.0.0.0',
        'port' => 3306,
        'username' => 'dbuser',
        'password' => 'dbpass',
        'dbname' => 'DB',
        'charset' => 'utf8mb4',
        'options' => [PDO::ATTR_EMULATE_PREPARES => false],
        'maxConnections' => 3
    ],
    DriversEnum::ORACLE->value => [
        'description' => 'ORACLE development',
        'driver' => DriversEnum::ORACLE,
        'host' => '0.0.0.0',
        'port' => 1521,
        'username' => 'dbuser',
        'password' => 'dbpass',
        'dbname' => 'DBINSTANCE',
        'charset' => 'AL32UTF8',
        'options' => [PDO::ATTR_EMULATE_PREPARES => false], // PDO::OCI_ATTR_CLIENT_INFO para org_id?
        'maxConnections' => 3
    ]
];

$configs = [
    array_merge($baseConfigs[DriversEnum::SQLSRV->value], [
            'name' => 'instance_0',
            'description' => 'SQLSRV development X'
        ]
    ),
    array_merge($baseConfigs[DriversEnum::SQLSRV->value], [
            'name' => 'instance_1',
            'description' => 'SQLSRV XY',
            'host' => 'xx.xx.xx.xx',
        ]
    ),
    array_merge($baseConfigs[DriversEnum::SQLSRV->value], [
            'name' => 'instance_2',
            'description' => 'SQLSRV X',
            'host' => 'xx.xx.xx.xx',
        ]
    ),
    array_merge($baseConfigs[DriversEnum::SQLSRV->value], [
            'name' => 'instance_3',
            'description' => 'SQLSRV XX',
            'host' => 'xx.xx.xx.xx',
        ]
    ),
    array_merge($baseConfigs[DriversEnum::MYSQL->value], [
        'name' => 'instance_1_mysql',
        'description' => 'MySQL development XX'
    ]),
    array_merge($baseConfigs[DriversEnum::ORACLE->value], [
        'name' => 'instance_1_ora',
        'description' => 'ORACLE XX'
    ])
];



return  DbConfigCollection::fromArray($configs);