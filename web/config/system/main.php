<?php

return [
    'app' => [
        'name' => 'FactorioWeb',
        'version' => '2.0',
        'debug' => false,
        'timezone' => 'Asia/Shanghai',
    ],
    
    'session' => [
        'lifetime' => 7200,
        'name' => 'factorio_session',
    ],
    
    'paths' => [
        'factorio_root' => dirname(__DIR__),
        'versions_dir' => dirname(__DIR__) . '/versions',
        'server_dir' => dirname(__DIR__) . '/server',
        'mods_dir' => dirname(__DIR__) . '/mods',
        'saves_dir' => dirname(__DIR__) . '/server/saves',
    ],
    
    'websocket' => [
        'enabled' => true,
        'port' => 8000,
        'host' => '0.0.0.0',
    ],
    
    'autoResponder' => [
        'enabled' => true,
        'mode' => 'daemon',
        'min_message_interval' => 1,
    ],
    
    'rconPool' => [
        'enabled' => true,
        'socket_path' => dirname(__DIR__) . '/run/rconPool.sock',
    ],
];
