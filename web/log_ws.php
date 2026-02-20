<?php
// log_ws.php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
ignore_user_abort(true);
set_time_limit(0);

// 使用相对路径
$logFile = __DIR__ . '/../factorio-current.log';

if (!file_exists($logFile)) {
    echo "data: [INFO] 等待日志文件生成...\n\n\n";
    flush();
}

$pos = 0;
echo "data: [CONNECTED] 实时日志已连接\n\n";
flush();

while (true) {
    if (connection_aborted()) break;

    clearstatcache();
    if (file_exists($logFile) && filesize($logFile) > $pos) {
        $f = fopen($logFile, 'r');
        fseek($f, $pos);
        while (!feof($f)) {
            $line = fgets($f);
            if ($line !== false) {
                $line = rtrim($line, "\r\n");           // 去掉原换行符
                if ($line !== '') {
                    echo "data: $line\n\n";             // 强制 SSE 标准换行
                    @ob_flush();
                    @flush();
                }
            }
        }
        $pos = ftell($f);
        fclose($f);
    }
    usleep(150000);   // 150ms 检查一次，CPU 几乎为 0
}
?>