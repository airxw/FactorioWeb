<?php
namespace Workerman;

spl_autoload_register(function($name){
    // 检查类名是否以 Workerman\ 开头
    if(strpos($name, 'Workerman\\') === 0){
        // 将命名空间转换为实际路径 (例如 Workerman\Worker -> src/Worker.php)
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($name, 10)) . '.php';
        if(file_exists($path)){
            require_once $path;
        }
    }
});
