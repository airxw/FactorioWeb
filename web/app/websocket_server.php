<?php
require_once __DIR__ . '/../lib/workerman/src/Worker.php';
require_once __DIR__ . '/../lib/workerman/src/Timer.php';
require_once __DIR__ . '/../lib/workerman/src/Connection/TcpConnection.php';
require_once __DIR__ . '/../lib/workerman/src/Protocols/Ws.php';

use Workerman\Worker;
use Workerman\Timer;

define('WS_PORT', 8000);
define('LOG_FILE', dirname(__DIR__, 2) . '/factorio-current.log');

/** @var Worker $wsWorker */
$wsWorker = new Worker("websocket://0.0.0.0:" . WS_PORT);
$wsWorker->count = 1;

$tailProcess = null;
$tailPipe = null;

$wsWorker->onWorkerStart = function($worker) {
    global $tailProcess, $tailPipe;

    if (!file_exists(LOG_FILE)) {
        touch(LOG_FILE);
        chmod(LOG_FILE, 0666);
    }

    $cmd = "tail -F -n 100 " . escapeshellarg(LOG_FILE);
    
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $tailProcess = proc_open($cmd, $descriptorspec, $pipes);
    
    if (is_resource($tailProcess)) {
        $tailPipe = $pipes[1];
        stream_set_blocking($tailPipe, 0);
        
        Timer::add(0.1, function() use ($worker, &$tailPipe) {
            if (feof($tailPipe)) return;
            $output = fread($tailPipe, 8192);
            if ($output) {
                foreach ($worker->connections as $connection) {
                    $connection->send($output);
                }
            }
        });
    } else {
        echo "无法启动 tail 进程，请检查权限\n";
    }
};

$wsWorker->onClose = function($connection) {
};

Worker::runAll();
