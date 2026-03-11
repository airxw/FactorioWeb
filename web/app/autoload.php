<?php

spl_autoload_register(function ($class) {
    $prefixes = [
        'App\\Core\\' => __DIR__ . '/core/',
        'App\\Controllers\\' => __DIR__ . '/controllers/',
        'App\\Services\\' => __DIR__ . '/services/',
        'App\\Helpers\\' => __DIR__ . '/helpers/',
        'Modules\\AutoResponder\\' => __DIR__ . '/modules/autoResponder/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

require_once __DIR__ . '/helpers/functions.php';

define('APP_ROOT', __DIR__);
define('LIB_ROOT', dirname(__DIR__) . '/lib');
