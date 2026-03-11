<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
ignore_user_abort(true);
set_time_limit(0);

function getLogFilePath($configName = null) {
    $baseDir = dirname(__DIR__, 2);
    
    if (!empty($configName)) {
        $configName = preg_replace('/\.json$/i', '', $configName);
        $logFile = "$baseDir/logs/factorio-{$configName}.log";
        if (file_exists($logFile)) {
            return $logFile;
        }
    }
    
    $logsDir = "$baseDir/logs";
    if (is_dir($logsDir)) {
        $logFiles = glob("$logsDir/factorio-*.log");
        if (!empty($logFiles)) {
            usort($logFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            return $logFiles[0];
        }
    }
    
    return "$baseDir/factorio-current.log";
}

$configName = $_GET['config'] ?? null;
$logFile = getLogFilePath($configName);
$stateFile = dirname(__DIR__) . '/config/state/logStreamState.json';
$lastPos = 0;
$lastInode = 0;

if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true);
    if ($state) {
        $lastPos = $state['pos'] ?? 0;
        $lastInode = $state['inode'] ?? 0;
    }
}

if (!file_exists($logFile)) {
    echo "data: " . json_encode(['type' => 'system', 'message' => '等待日志文件生成...']) . "\n\n";
    flush();
}

echo "data: " . json_encode(['type' => 'connected']) . "\n\n";
flush();

function parseLogLine($line) {
    $result = [
        'raw' => $line,
        'type' => 'system',
        'timestamp' => date('H:i:s'),
        'message' => $line
    ];
    
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([A-Z]+)\] (.+)$/', $line, $matches)) {
        $result['timestamp'] = $matches[1];
        $result['level'] = $matches[2];
        $result['message'] = $matches[3];
    }
    
    $msg = $result['message'];
    $level = $result['level'] ?? '';
    
    if ($level === 'CHAT' || strpos($msg, '[CHAT]') !== false) {
        $result['type'] = 'chat';
        if (preg_match('/\[CHAT\] (.+?): (.+)/', $line, $m)) {
            $result['player'] = $m[1];
            $result['message'] = $m[2];
        } elseif (preg_match('/^(.+?): (.+)$/', $msg, $m)) {
            $result['player'] = $m[1];
            $result['message'] = $m[2];
        }
    } elseif ($level === 'JOIN' || strpos($msg, 'joined the game') !== false) {
        $result['type'] = 'login';
        if (preg_match('/^([A-Za-z0-9_]+) joined the game/', $msg, $m)) {
            $result['player'] = $m[1];
        } elseif (preg_match('/([A-Za-z0-9_]+) joined the game/', $line, $m)) {
            $result['player'] = $m[1];
        }
    } elseif ($level === 'LEAVE' || strpos($msg, 'left the game') !== false) {
        $result['type'] = 'logout';
        if (preg_match('/^([A-Za-z0-9_]+) left the game/', $msg, $m)) {
            $result['player'] = $m[1];
        } elseif (preg_match('/([A-Za-z0-9_]+) left the game/', $line, $m)) {
            $result['player'] = $m[1];
        }
    } elseif (preg_match('/Saving game as/i', $msg)) {
        $result['type'] = 'save';
    }
    
    return $result;
}

$batchData = [];
$batchSize = 50;

while (true) {
    if (connection_aborted()) break;
    
    clearstatcache();
    
    if (file_exists($logFile)) {
        $stat = stat($logFile);
        
        if ($stat['size'] > $lastPos || $stat['ino'] !== $lastInode) {
            if ($stat['ino'] !== $lastInode) {
                $lastPos = 0;
            }
            
            $f = fopen($logFile, 'r');
            if ($f) {
                fseek($f, $lastPos);
                
                while (!feof($f)) {
                    $line = fgets($f);
                    if ($line !== false) {
                        $line = rtrim($line, "\r\n");
                        if ($line !== '') {
                            $parsed = parseLogLine($line);
                            $batchData[] = $parsed;
                            
                            if (count($batchData) >= $batchSize) {
                                echo "data: " . json_encode(['entries' => $batchData]) . "\n\n";
                                @ob_flush();
                                @flush();
                                $batchData = [];
                            }
                        }
                    }
                }
                
                $lastPos = ftell($f);
                $lastInode = $stat['ino'];
                fclose($f);
                
                $stateDir = dirname($stateFile);
                if (!is_dir($stateDir)) {
                    mkdir($stateDir, 0755, true);
                }
                file_put_contents($stateFile, json_encode([
                    'pos' => $lastPos,
                    'inode' => $lastInode
                ]));
            }
        }
    }
    
    if (count($batchData) > 0) {
        echo "data: " . json_encode(['entries' => $batchData]) . "\n\n";
        @ob_flush();
        @flush();
        $batchData = [];
    }
    
    usleep(200000);
}
