<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use Workerman\Timer;

// =================配置区域=================
define('WS_PORT', 8000);
// Factorio 的标准日志文件路径 (根据你的目录结构)
// 通常在 factorio 根目录下
define('LOG_FILE', dirname(__DIR__) . '/factorio-current.log');
// =========================================

// 创建 WebSocket 服务
$ws_worker = new Worker("websocket://0.0.0.0:" . WS_PORT);
$ws_worker->count = 1;

// 全局变量保存 tail 进程
$tailProcess = null;
$tailPipe = null;

$ws_worker->onWorkerStart = function($worker) {
    global $tailProcess, $tailPipe;

    // 1. 确保日志文件存在，如果不存在则创建（防止 tail 报错）
    if (!file_exists(LOG_FILE)) {
        touch(LOG_FILE);
        chmod(LOG_FILE, 0666); // 确保任何人可读写
    }

    // 2. 使用 tail -f 命令实时读取日志新增内容
    // -n 100 表示启动时先显示最后 100 行
    $cmd = "tail -F -n 100 " . escapeshellarg(LOG_FILE);
    
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'], // 标准输出
        2 => ['pipe', 'w']  // 错误输出
    ];

    $tailProcess = proc_open($cmd, $descriptorspec, $pipes);
    
    if (is_resource($tailProcess)) {
        $tailPipe = $pipes[1];
        stream_set_blocking($tailPipe, 0); // 设置非阻塞模式
        
        // 3. 开启定时器，每 0.1 秒读取一次 tail 的输出
        Timer::add(0.1, function() use ($worker, &$tailPipe) {
            if (feof($tailPipe)) return;
            $output = fread($tailPipe, 8192);
            if ($output) {
                // 将日志内容通过 WebSocket 发送给所有在线网页
                foreach ($worker->connections as $connection) {
                    $connection->send($output);
                }
            }
        });
    } else {
        echo "无法启动 tail 进程，请检查权限\n";
    }
};

$ws_worker->onClose = function($connection) {
    // 客户端断开，无需特殊处理
};

// 运行 worker
Worker::runAll();