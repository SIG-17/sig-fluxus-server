<?php

use SIG\Server\Collection\ChannelCollection;
use SIG\Server\Collection\ChannelSetCollection;
$base =[
    [
        'name' => 'com.system.info',
        'description' => 'Distribución de información y estadísticas del sistema',
        'requires_auth' => true,
        'requires_role' => 'ws:admin'
    ],
    [
        'name' => 'com.system.updates',
        'auto_subscribe' => true,
        'description' => 'Notificaciones de actualizaciones del sistema'
    ]
];
$configs =[
    'ws-default' => $base,
    'ws-instance_1' => array_merge($base, [
        [
            'name' => 'com.ws.users',
            'auto_subscribe' => true,
            'requires_auth' => true,
            'requires_role' => 'ws:user'
        ]
    ]),
    'ws-instance_2' => $base,
    'ws-instance_3' => $base
];
$collections = array_map(static function ($channels) {
    return ChannelCollection::fromArray($channels);
}, $configs);
return ChannelSetCollection::fromArray($collections);