<?php
/**
 * 自动响应系统守护进程
 * 实时监控日志文件，即时响应关键词和玩家事件
 */

// 配置 - 使用相对路径，方便部署
define('WEB_DIR', __DIR__);
define('BASE_DIR', dirname(__DIR__));
define('LOG_FILE', BASE_DIR . '/factorio-current.log');
define('SETTINGS_FILE', WEB_DIR . '/chat_settings.json');
define('PID_FILE', WEB_DIR . '/auto_responder.pid');
define('STATE_FILE', WEB_DIR . '/auto_responder_state.json');
define('DAEMON_LOG_FILE', WEB_DIR . '/auto_responder_daemon.log');
define('MIN_MESSAGE_INTERVAL', 1);
define('READ_INTERVAL', 100000);

// 守护进程模式运行
function runAsDaemon() {
    // 创建子进程
    $pid = pcntl_fork();
    
    if ($pid === -1) {
        die("无法创建守护进程\n");
    } elseif ($pid > 0) {
        // 父进程退出
        exit(0);
    }
    
    // 子进程成为会话组长
    posix_setsid();
    
    // 再次 fork 防止获取控制终端
    $pid = pcntl_fork();
    if ($pid === -1) {
        die("无法创建守护进程\n");
    } elseif ($pid > 0) {
        exit(0);
    }
    
    // 保存 PID
    file_put_contents(PID_FILE, posix_getpid());
    
    // 保存状态
    saveState(['running' => true, 'started_at' => date('Y-m-d H:i:s')]);
    
    // 开始监控
    monitorLog();
}

// 保存状态
function saveState($state) {
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

// 获取状态
function getState() {
    if (!file_exists(STATE_FILE)) {
        return ['running' => false];
    }
    return json_decode(file_get_contents(STATE_FILE), true);
}

// 停止守护进程
function stopDaemon() {
    if (!file_exists(PID_FILE)) {
        return ['success' => false, 'message' => '守护进程未运行'];
    }
    
    $pid = intval(file_get_contents(PID_FILE));
    
    if ($pid > 0) {
        posix_kill($pid, SIGTERM);
        unlink(PID_FILE);
        saveState(['running' => false, 'stopped_at' => date('Y-m-d H:i:s')]);
        return ['success' => true, 'message' => '守护进程已停止'];
    }
    
    return ['success' => false, 'message' => '无效的进程ID'];
}

// 检查守护进程状态
function checkStatus() {
    if (!file_exists(PID_FILE)) {
        return ['running' => false, 'mode' => null];
    }
    
    $pid = intval(file_get_contents(PID_FILE));
    
    // 检查进程是否存在
    if ($pid > 0 && posix_kill($pid, 0)) {
        $state = getState();
        return [
            'running' => true,
            'mode' => 'daemon',
            'pid' => $pid,
            'started_at' => $state['started_at'] ?? null
        ];
    }
    
    // PID 文件存在但进程不存在，清理
    unlink(PID_FILE);
    saveState(['running' => false]);
    return ['running' => false, 'mode' => null];
}

// 执行控制台命令
function executeCommand($command) {
    $screenName = 'factorio_server';
    $fullCommand = sprintf('screen -S %s -p 0 -X stuff "%s\n"', $screenName, $command);
    shell_exec($fullCommand);
}

// 发送聊天消息
function sendChatMessage($message) {
    $command = ' ' . $message;
    executeCommand($command);
    file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 发送消息: [public] ' . $message . PHP_EOL, FILE_APPEND);
}

// 获取配置
function getSettings() {
    if (!file_exists(SETTINGS_FILE)) {
        return [
            'scheduledTasks' => [],
            'triggerResponses' => [],
            'periodicMessages' => [],
            'playerEvents' => [
                'welcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'goodbye' => ['enabled' => false, 'message' => '', 'type' => 'public']
            ]
        ];
    }
    return json_decode(file_get_contents(SETTINGS_FILE), true);
}

// 监控日志文件
function monitorLog() {
    $lastMessageTime = 0;
    $processedLines = [];
    
    file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 守护进程启动' . PHP_EOL, FILE_APPEND);
    
    while (true) {
        pcntl_signal_dispatch();
        
        if (!file_exists(LOG_FILE)) {
            file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 日志文件不存在' . PHP_EOL, FILE_APPEND);
            usleep(READ_INTERVAL);
            continue;
        }
        
        $handle = fopen(LOG_FILE, 'r');
        if ($handle === false) {
            file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 无法打开日志文件' . PHP_EOL, FILE_APPEND);
            usleep(READ_INTERVAL);
            continue;
        }
        
        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $lines[] = trim($line);
        }
        fclose($handle);
        
        $newLines = array_diff($lines, $processedLines);
        if (!empty($newLines)) {
            file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 检测到新内容，开始处理' . PHP_EOL, FILE_APPEND);
            foreach ($newLines as $line) {
                processLogLine($line, $lastMessageTime);
            }
            $processedLines = $lines;
            file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 处理完成，处理了 ' . count($newLines) . ' 行' . PHP_EOL, FILE_APPEND);
        }
        
        usleep(READ_INTERVAL);
    }
}

// 处理单行日志
function processLogLine($line, &$lastMessageTime) {
    $settings = getSettings();
    $currentTime = time();
    
    file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 处理日志行: ' . trim($line) . PHP_EOL, FILE_APPEND);
    
    if (strpos($line, '[CHAT]') !== false) {
        $chatMatch = [];
        if (preg_match('/\[CHAT\]\s*(.+?):\s*(.+)$/', $line, $chatMatch)) {
            $player = trim($chatMatch[1]);
            $message = trim($chatMatch[2]);
            
            file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 解析聊天消息: 玩家=' . $player . ', 消息=' . $message . PHP_EOL, FILE_APPEND);
            
            if (strtolower($player) === '<server>' || strtolower($player) === 'server') {
                file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 忽略服务器消息' . PHP_EOL, FILE_APPEND);
                return;
            }
            
            file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 开始检查关键词' . PHP_EOL, FILE_APPEND);
            foreach ($settings['triggerResponses'] ?? [] as $trigger) {
                file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 检查关键词: ' . $trigger['keyword'] . PHP_EOL, FILE_APPEND);
                if (strpos($message, $trigger['keyword']) !== false) {
                    file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 匹配关键词: ' . $trigger['keyword'] . PHP_EOL, FILE_APPEND);
                    if ($currentTime - $lastMessageTime >= MIN_MESSAGE_INTERVAL) {
                        $response = str_replace(['{player}', '{message}'], [$player, $message], $trigger['response']);
                        file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 发送回复: ' . $response . PHP_EOL, FILE_APPEND);
                        sendChatMessage($response);
                        $lastMessageTime = $currentTime;
                    } else {
                        file_put_contents(DAEMON_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] 消息间隔不足，跳过发送' . PHP_EOL, FILE_APPEND);
                    }
                    break;
                }
            }
        }
    }
    
    // 检查玩家加入
    if (strpos($line, '[JOIN]') !== false) {
        $joinMatch = [];
        if (preg_match('/\[JOIN\]\s*(.+)\s+joined/', $line, $joinMatch)) {
            $player = trim($joinMatch[1]);
            $welcome = $settings['playerEvents']['welcome'] ?? null;
            
            if ($welcome && $welcome['enabled'] && !empty($welcome['message'])) {
                if ($currentTime - $lastMessageTime >= MIN_MESSAGE_INTERVAL) {
                    $message = str_replace('{player}', $player, $welcome['message']);
                    sendChatMessage($message);
                    $lastMessageTime = $currentTime;
                }
            }
        }
    }
    
    // 检查玩家离开
    if (strpos($line, '[LEAVE]') !== false) {
        $leaveMatch = [];
        if (preg_match('/\[LEAVE\]\s*(.+)\s+left/', $line, $leaveMatch)) {
            $player = trim($leaveMatch[1]);
            $goodbye = $settings['playerEvents']['goodbye'] ?? null;
            
            if ($goodbye && $goodbye['enabled'] && !empty($goodbye['message'])) {
                if ($currentTime - $lastMessageTime >= MIN_MESSAGE_INTERVAL) {
                    $message = str_replace('{player}', $player, $goodbye['message']);
                    sendChatMessage($message);
                    $lastMessageTime = $currentTime;
                }
            }
        }
    }
}

// 处理命令行参数
$action = $argv[1] ?? 'status';

switch ($action) {
    case 'start':
        $status = checkStatus();
        if ($status['running']) {
            echo json_encode(['success' => false, 'message' => '守护进程已在运行中 (PID: ' . $status['pid'] . ')']);
            exit(0);
        }
        
        // 在 fork 前输出成功消息
        $pid = pcntl_fork();
        if ($pid === -1) {
            echo json_encode(['success' => false, 'message' => '无法创建守护进程']);
            exit(1);
        } elseif ($pid > 0) {
            // 父进程输出成功并退出
            echo json_encode(['success' => true, 'message' => '守护进程已启动']);
            exit(0);
        }
        
        // 子进程继续执行守护进程逻辑
        posix_setsid();
        
        // 再次 fork
        $pid = pcntl_fork();
        if ($pid === -1) {
            die("无法创建守护进程\n");
        } elseif ($pid > 0) {
            exit(0);
        }
        
        // 保存 PID 并开始监控
        file_put_contents(PID_FILE, posix_getpid());
        saveState(['running' => true, 'started_at' => date('Y-m-d H:i:s')]);
        monitorLog();
        break;
        
    case 'stop':
        echo json_encode(stopDaemon());
        break;
        
    case 'status':
        echo json_encode(checkStatus());
        break;
        
    case 'run':
        // 前台运行模式（用于调试）
        monitorLog();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作: ' . $action]);
        break;
}
