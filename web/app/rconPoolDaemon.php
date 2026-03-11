<?php
/**
 * RCON 连接池守护进程
 * 
 * 维护一个持久的 RCON 连接，通过 Unix Socket 接收命令请求
 * 所有 Web API 请求都通过这个服务来执行 RCON 命令
 * 
 * 启动方式：php rcon_pool_daemon.php start
 * 停止方式：php rcon_pool_daemon.php stop
 */

define('SOCKET_PATH', dirname(__DIR__) . '/run/rconPool.sock');
define('PID_FILE', dirname(__DIR__) . '/run/rconPool.pid');
define('LOG_FILE', dirname(__DIR__) . '/logs/rconPool.log');
define('CONFIG_FILE', dirname(__DIR__) . '/config/system/rcon.php');

require_once __DIR__ . '/factorioRcon.php';
require_once __DIR__ . '/services/RconService.php';
require_once __DIR__ . '/core/LogConfig.php';

use App\Core\LogConfig;
use App\Services\RconService;

$rconConnection = null;
$rconConfig = null;

function logMessage($message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function loadConfig() {
    global $rconConfig;
    
    $configRaw = file_exists(CONFIG_FILE) ? require CONFIG_FILE : [];
    
    if (isset($configRaw['default'])) {
        $rconConfig = $configRaw['default'];
    } else {
        $rconConfig = $configRaw;
    }
    
    if (!isset($rconConfig['rcon_enabled'])) {
        $rconConfig['rcon_enabled'] = true;
    }
    if (!isset($rconConfig['rcon_port'])) {
        $rconConfig['rcon_port'] = 27015;
    }
    if (!isset($rconConfig['rcon_password'])) {
        $rconConfig['rcon_password'] = 'factorio_rcon_password';
    }
    if (!isset($rconConfig['rcon_host'])) {
        $rconConfig['rcon_host'] = '127.0.0.1';
    }
    
    return $rconConfig;
}

function getRconConnection() {
    global $rconConnection, $rconConfig;
    
    if ($rconConnection !== null) {
        return $rconConnection;
    }
    
    try {
        $rconConnection = new FactorioRCON(
            $rconConfig['rcon_host'],
            $rconConfig['rcon_port'],
            $rconConfig['rcon_password']
        );
        $rconConnection->connect();
        logMessage('RCON 连接已建立');
        return $rconConnection;
    } catch (Exception $e) {
        logMessage('RCON 连接失败: ' . $e->getMessage());
        $rconConnection = null;
        return null;
    }
}

function reconnectRcon() {
    global $rconConnection;
    
    if ($rconConnection !== null) {
        $rconConnection->disconnect();
        $rconConnection = null;
    }
    
    return getRconConnection();
}

function executeCommand($command) {
    $rcon = getRconConnection();
    
    if ($rcon === null) {
        $rcon = reconnectRcon();
        if ($rcon === null) {
            return ['success' => false, 'error' => 'RCON 连接失败'];
        }
    }
    
    try {
        $result = $rcon->sendCommand($command);
        return ['success' => true, 'result' => $result];
    } catch (Exception $e) {
        logMessage('RCON 执行失败: ' . $e->getMessage() . '，尝试重连');
        $rcon = reconnectRcon();
        if ($rcon === null) {
            return ['success' => false, 'error' => 'RCON 重连失败'];
        }
        
        try {
            $result = $rcon->sendCommand($command);
            return ['success' => true, 'result' => $result];
        } catch (Exception $e2) {
            return ['success' => false, 'error' => $e2->getMessage()];
        }
    }
}

function startDaemon() {
    $socketDir = dirname(SOCKET_PATH);
    if (!is_dir($socketDir)) {
        mkdir($socketDir, 0755, true);
    }
    
    if (file_exists(SOCKET_PATH)) {
        unlink(SOCKET_PATH);
    }
    
    if (file_exists(PID_FILE)) {
        $oldPid = file_get_contents(PID_FILE);
        if (posix_kill(intval($oldPid), 0)) {
            echo "守护进程已在运行中 (PID: $oldPid)\n";
            exit(0);
        }
        unlink(PID_FILE);
    }
    
    $pid = pcntl_fork();
    if ($pid === -1) {
        die("无法创建守护进程\n");
    } elseif ($pid > 0) {
        echo "守护进程已启动\n";
        exit(0);
    }
    
    posix_setsid();
    
    $pid = pcntl_fork();
    if ($pid === -1) {
        die("无法创建守护进程\n");
    } elseif ($pid > 0) {
        exit(0);
    }
    
    file_put_contents(PID_FILE, posix_getpid());
    
    loadConfig();
    logMessage('RCON 连接池守护进程启动');
    
    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($socket === false) {
        logMessage('创建 Socket 失败');
        exit(1);
    }
    
    if (!socket_bind($socket, SOCKET_PATH)) {
        logMessage('绑定 Socket 失败');
        exit(1);
    }
    
    chmod(SOCKET_PATH, 0777);
    
    if (!socket_listen($socket, 10)) {
        logMessage('监听 Socket 失败');
        exit(1);
    }
    
    socket_set_nonblock($socket);
    
    $lastPing = time();
    
    while (true) {
        $client = @socket_accept($socket);
        
        if ($client !== false) {
            $request = '';
            while (($chunk = socket_read($client, 4096)) !== false && $chunk !== '') {
                $request .= $chunk;
                if (strpos($request, "\n") !== false) {
                    break;
                }
            }
            
            $request = trim($request);
            
            if (!empty($request)) {
                $data = json_decode($request, true);
                
                if ($data && isset($data['action'])) {
                    switch ($data['action']) {
                        case 'execute':
                            $result = executeCommand($data['command'] ?? '');
                            break;
                        case 'ping':
                            $result = ['success' => true, 'pong' => true];
                            break;
                        case 'status':
                            $rcon = getRconConnection();
                            $result = [
                                'success' => true,
                                'connected' => $rcon !== null,
                                'pid' => posix_getpid()
                            ];
                            break;
                        case 'reload':
                            loadConfig();
                            reconnectRcon();
                            $result = ['success' => true];
                            break;
                        default:
                            $result = ['success' => false, 'error' => '未知操作'];
                    }
                } else {
                    $result = ['success' => false, 'error' => '无效请求'];
                }
                
                socket_write($client, json_encode($result) . "\n");
            }
            
            socket_close($client);
        }
        
        $currentTime = time();
        if ($currentTime - $lastPing > 30) {
            $rcon = getRconConnection();
            if ($rcon !== null) {
                try {
                    $rcon->sendCommand('/p');
                } catch (Exception $e) {
                    logMessage('心跳检测失败: ' . $e->getMessage());
                    $rconConnection = null;
                }
            }
            $lastPing = $currentTime;
        }
        
        usleep(10000);
    }
}

function stopDaemon() {
    if (!file_exists(PID_FILE)) {
        echo "守护进程未运行\n";
        return;
    }
    
    $pid = intval(file_get_contents(PID_FILE));
    
    if (posix_kill($pid, SIGTERM)) {
        unlink(PID_FILE);
        if (file_exists(SOCKET_PATH)) {
            unlink(SOCKET_PATH);
        }
        echo "守护进程已停止\n";
    } else {
        echo "停止失败\n";
    }
}

function getStatus() {
    if (!file_exists(PID_FILE)) {
        echo json_encode(['running' => false]);
        return;
    }
    
    $pid = intval(file_get_contents(PID_FILE));
    
    if (posix_kill($pid, 0)) {
        echo json_encode(['running' => true, 'pid' => $pid]);
    } else {
        echo json_encode(['running' => false]);
    }
}

$action = $argv[1] ?? 'status';

switch ($action) {
    case 'start':
        startDaemon();
        break;
    case 'stop':
        stopDaemon();
        break;
    case 'status':
        getStatus();
        break;
    default:
        echo "用法: php rcon_pool_daemon.php [start|stop|status]\n";
        break;
}
