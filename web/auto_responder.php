<?php
/**
 * Factorio Server 自动响应系统
 * 
 * 功能：
 * - 定时发送消息（周期性）
 * - 关键词自动回复
 * - 玩家事件响应（上线欢迎、下线告别）
 * 
 * 使用方法：
 * 1. 通过 Linux cron 定时执行此脚本（建议每分钟执行一次）
 * 2. 命令：* * * * * /usr/bin/php /path/to/web/auto_responder.php >> /path/to/web/auto_responder.log 2>&1
 */

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 配置文件路径
define('SETTINGS_FILE', __DIR__ . '/chat_settings.json');
define('STATE_FILE', __DIR__ . '/auto_responder_state.json');
define('LOG_FILE', dirname(__DIR__) . '/factorio-current.log');
define('MESSAGE_QUEUE_FILE', __DIR__ . '/message_queue.json');

// 消息发送间隔配置（秒）
define('MIN_MESSAGE_INTERVAL', 1);  // 两条消息之间的最小间隔
define('MESSAGE_DELAY', 1);         // 触发响应后的延迟时间

// 执行控制台命令
function executeConsoleCommand($command) {
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $command);
    $result = shell_exec("sudo -u www screen -S factorio_server -p 0 -X stuff \"$escaped\\n\" 2>&1");
    return $result !== null;
}

// 读取消息队列
function readMessageQueue() {
    if (!file_exists(MESSAGE_QUEUE_FILE)) {
        return [];
    }
    $content = file_get_contents(MESSAGE_QUEUE_FILE);
    return json_decode($content, true) ?: [];
}

// 保存消息队列
function saveMessageQueue($queue) {
    file_put_contents(MESSAGE_QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 添加消息到队列
function queueMessage($message, $type = 'public', $delay = 0) {
    $queue = readMessageQueue();
    $queue[] = [
        'message' => $message,
        'type' => $type,
        'schedule_time' => time() + $delay,
        'created_at' => time()
    ];
    saveMessageQueue($queue);
    logMessage("消息已加入队列: [$type] $message" . ($delay > 0 ? " (延迟 {$delay} 秒)" : ""));
}

// 处理消息队列
function processMessageQueue(&$state) {
    $queue = readMessageQueue();
    $now = time();
    $lastSent = isset($state['lastMessageSent']) ? intval($state['lastMessageSent']) : 0;
    $messagesSent = 0;
    
    // 按计划时间排序
    usort($queue, function($a, $b) {
        return $a['schedule_time'] - $b['schedule_time'];
    });
    
    $newQueue = [];
    foreach ($queue as $item) {
        // 检查是否到达发送时间
        if ($item['schedule_time'] <= $now) {
            // 检查是否满足最小间隔
            if ($now - $lastSent >= MIN_MESSAGE_INTERVAL) {
                // 发送消息
                sendChatMessageDirect($item['message']);
                $state['lastMessageSent'] = $now;
                $lastSent = $now;
                $messagesSent++;
            } else {
                // 重新放回队列，延迟发送
                $item['schedule_time'] = $lastSent + MIN_MESSAGE_INTERVAL;
                $newQueue[] = $item;
            }
        } else {
            // 还没到发送时间，保留在队列中
            $newQueue[] = $item;
        }
    }
    
    saveMessageQueue($newQueue);
    return $messagesSent;
}

// 直接发送聊天消息（不经过队列）
function sendChatMessageDirect($message) {
    $cmd = $message;
    
    $result = executeConsoleCommand($cmd);
    logMessage("发送消息: [public] $message");
    return $result;
}

// 发送聊天消息（加入队列）
function sendChatMessage($message, $type = 'public', $delay = 0) {
    queueMessage($message, $type, $delay);
    return true;
}

// 读取设置
function readSettings() {
    if (!file_exists(SETTINGS_FILE)) {
        return [
            'scheduledTasks' => [],
            'triggerResponses' => [],
            'periodicMessages' => [],
            'playerEvents' => [
                'welcome' => ['enabled' => false, 'message' => ''],
                'goodbye' => ['enabled' => false, 'message' => '']
            ]
        ];
    }
    $content = file_get_contents(SETTINGS_FILE);
    return json_decode($content, true) ?: [];
}

// 读取状态
function readState() {
    if (!file_exists(STATE_FILE)) {
        return [
            'lastCheckTime' => time(),
            'lastLogPosition' => 0,
            'processedTasks' => [],
            'onlinePlayers' => []
        ];
    }
    $content = file_get_contents(STATE_FILE);
    return json_decode($content, true) ?: [];
}

// 保存状态
function saveState($state) {
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 检查服务器是否运行
function isServerRunning() {
    // 尝试以 www 用户身份检查 screen 会话
    $result = shell_exec("sudo -u www screen -ls 2>/dev/null | grep factorio_server");
    return !empty($result);
}

// ==================== 定时任务处理 ====================
function processScheduledTasks(&$settings, &$state) {
    $now = new DateTime();
    $tasksExecuted = 0;
    
    if (!isset($settings['scheduledTasks'])) {
        return $tasksExecuted;
    }
    
    // 过滤已执行的任务
    $settings['scheduledTasks'] = array_filter($settings['scheduledTasks'], function($task) use ($now, &$tasksExecuted) {
        if (!isset($task['time'])) return true;
        
        $taskTime = new DateTime($task['time']);
        
        // 检查任务是否应该执行（时间已过且未执行过）
        if ($taskTime <= $now) {
            // 发送消息
            if (isset($task['message']) && !empty($task['message'])) {
                sendChatMessage($task['message']);
                logMessage("执行定时任务: " . $task['message']);
                $tasksExecuted++;
            }
            return false; // 删除已执行的任务
        }
        return true; // 保留未执行的任务
    });
    
    // 重新索引数组
    $settings['scheduledTasks'] = array_values($settings['scheduledTasks']);
    
    return $tasksExecuted;
}

// ==================== 周期性消息处理 ====================
function processPeriodicMessages(&$settings, &$state) {
    $now = time();
    $messagesSent = 0;
    
    if (!isset($settings['periodicMessages'])) {
        return $messagesSent;
    }
    
    foreach ($settings['periodicMessages'] as &$message) {
        if (!isset($message['enabled']) || !$message['enabled']) {
            continue;
        }
        
        $interval = isset($message['interval']) ? intval($message['interval']) : 60; // 默认60分钟
        $lastSent = isset($message['lastSent']) ? intval($message['lastSent']) : 0;
        
        // 检查是否到达发送时间
        if ($now - $lastSent >= $interval * 60) {
            if (!empty($message['content'])) {
                sendChatMessage($message['content']);
                logMessage("发送周期性消息: " . $message['content']);
                $message['lastSent'] = $now;
                $messagesSent++;
            }
        }
    }
    
    return $messagesSent;
}

// ==================== 关键词自动回复处理 ====================
function processTriggerResponses(&$settings, &$state) {
    $responsesSent = 0;
    
    if (!isset($settings['triggerResponses']) || empty($settings['triggerResponses'])) {
        return $responsesSent;
    }
    
    // 读取日志文件
    if (!file_exists(LOG_FILE)) {
        return $responsesSent;
    }
    
    $lastPosition = isset($state['lastLogPosition']) ? intval($state['lastLogPosition']) : 0;
    $currentSize = filesize(LOG_FILE);
    
    // 如果日志文件被重置，从头开始
    if ($currentSize < $lastPosition) {
        $lastPosition = 0;
    }
    
    // 读取新内容
    $handle = fopen(LOG_FILE, 'r');
    if (!$handle) {
        return $responsesSent;
    }
    
    fseek($handle, $lastPosition);
    $newContent = '';
    while (!feof($handle)) {
        $newContent .= fread($handle, 8192);
    }
    $newPosition = ftell($handle);
    fclose($handle);
    
    // 更新状态
    $state['lastLogPosition'] = $newPosition;
    
    // 解析聊天消息
    $lines = explode("\n", $newContent);
    foreach ($lines as $line) {
        // 匹配聊天消息格式
        if (preg_match('/\[CHAT\]\s*(.+?):\s*(.+)$/', $line, $matches)) {
            $player = trim($matches[1]);
            $message = trim($matches[2]);
            
            // 忽略服务器自己发送的消息，避免无限循环
            if (strtolower($player) === '<server>' || strtolower($player) === 'server') {
                continue;
            }
            
            // 检查是否匹配关键词
            foreach ($settings['triggerResponses'] as $trigger) {
                if (!isset($trigger['keyword']) || !isset($trigger['response'])) {
                    continue;
                }
                
                $keyword = $trigger['keyword'];
                $response = $trigger['response'];
                
                // 检查关键词是否匹配（支持包含匹配）
                if (stripos($message, $keyword) !== false) {
                    // 替换变量
                    $responseText = str_replace(['{player}', '{message}'], [$player, $message], $response);
                    
                    // 添加延迟发送（使用队列延迟，避免阻塞）
                    sendChatMessage($responseText, MESSAGE_DELAY);
                    logMessage("关键词回复已排队: [$keyword] -> [$responseText]");
                    $responsesSent++;
                    break; // 只触发第一个匹配的关键词
                }
            }
        }
    }
    
    return $responsesSent;
}

// ==================== 玩家事件处理 ====================
function processPlayerEvents(&$settings, &$state) {
    $eventsProcessed = 0;
    
    if (!isset($settings['playerEvents'])) {
        return $eventsProcessed;
    }
    
    $playerEvents = $settings['playerEvents'];
    
    // 读取日志文件
    if (!file_exists(LOG_FILE)) {
        return $eventsProcessed;
    }
    
    // 获取上次检查后的新日志内容
    $lastPosition = isset($state['lastLogPosition']) ? intval($state['lastLogPosition']) : 0;
    $currentSize = filesize(LOG_FILE);
    
    if ($currentSize < $lastPosition) {
        $lastPosition = 0;
    }
    
    $handle = fopen(LOG_FILE, 'r');
    if (!$handle) {
        return $eventsProcessed;
    }
    
    fseek($handle, $lastPosition);
    $newContent = '';
    while (!feof($handle)) {
        $newContent .= fread($handle, 8192);
    }
    fclose($handle);
    
    $lines = explode("\n", $newContent);
    
    // 初始化在线玩家列表
    if (!isset($state['onlinePlayers'])) {
        $state['onlinePlayers'] = [];
    }
    $onlinePlayers = &$state['onlinePlayers'];
    
    foreach ($lines as $line) {
        // 玩家加入游戏
        if (preg_match('/\[JOIN\]\s*(.+?)\s*joined the game/', $line, $matches)) {
            $player = trim($matches[1]);
            
            // 避免重复欢迎
            if (!in_array($player, $onlinePlayers)) {
                $onlinePlayers[] = $player;
                
                // 发送欢迎消息（延迟发送）
                if (isset($playerEvents['welcome']) && 
                    $playerEvents['welcome']['enabled'] && 
                    !empty($playerEvents['welcome']['message'])) {
                    
                    $welcomeMsg = str_replace('{player}', $player, $playerEvents['welcome']['message']);
                    sendChatMessage($welcomeMsg, MESSAGE_DELAY);
                    logMessage("玩家上线欢迎已排队: $player");
                    $eventsProcessed++;
                }
            }
        }
        
        // 玩家离开游戏
        if (preg_match('/\[LEAVE\]\s*(.+?)\s*left the game/', $line, $matches)) {
            $player = trim($matches[1]);
            
            // 从在线列表中移除
            $key = array_search($player, $onlinePlayers);
            if ($key !== false) {
                unset($onlinePlayers[$key]);
                $onlinePlayers = array_values($onlinePlayers);
                
                // 发送告别消息（延迟发送）
                if (isset($playerEvents['goodbye']) && 
                    $playerEvents['goodbye']['enabled'] && 
                    !empty($playerEvents['goodbye']['message'])) {
                    
                    $goodbyeMsg = str_replace('{player}', $player, $playerEvents['goodbye']['message']);
                    sendChatMessage($goodbyeMsg, MESSAGE_DELAY);
                    logMessage("玩家下线告别已排队: $player");
                    $eventsProcessed++;
                }
            }
        }
    }
    
    return $eventsProcessed;
}

// 日志记录
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents(__DIR__ . '/auto_responder.log', $logEntry, FILE_APPEND | LOCK_EX);
}

// 保存设置到文件
function saveSettings($settings) {
    file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ==================== 主程序 ====================
function main() {
    logMessage("========== 自动响应系统启动 ==========");
    
    // 检查服务器是否运行
    if (!isServerRunning()) {
        logMessage("服务器未运行，跳过本次检查");
        // 即使服务器未运行，也处理队列中的消息（可能服务器刚重启）
        $state = readState();
        $queueProcessed = processMessageQueue($state);
        saveState($state);
        
        echo json_encode(['status' => 'skipped', 'reason' => 'server_not_running', 'queue_processed' => $queueProcessed]);
        return;
    }
    
    // 读取配置和状态
    $settings = readSettings();
    $state = readState();
    
    $results = [
        'scheduledTasks' => 0,
        'periodicMessages' => 0,
        'triggerResponses' => 0,
        'playerEvents' => 0,
        'queueProcessed' => 0
    ];
    
    // 处理定时任务
    $results['scheduledTasks'] = processScheduledTasks($settings, $state);
    
    // 处理周期性消息
    $results['periodicMessages'] = processPeriodicMessages($settings, $state);
    
    // 处理关键词自动回复
    $results['triggerResponses'] = processTriggerResponses($settings, $state);
    
    // 处理玩家事件
    $results['playerEvents'] = processPlayerEvents($settings, $state);
    
    // 处理消息队列（发送排队的消息）
    $results['queueProcessed'] = processMessageQueue($state);
    
    // 更新检查时间
    $state['lastCheckTime'] = time();
    
    // 保存状态和设置
    saveState($state);
    saveSettings($settings);
    
    $total = $results['scheduledTasks'] + $results['periodicMessages'] + $results['triggerResponses'] + $results['playerEvents'];
    logMessage("处理完成: 定时任务({$results['scheduledTasks']}), 周期性消息({$results['periodicMessages']}), 关键词回复({$results['triggerResponses']}), 玩家事件({$results['playerEvents']}), 队列发送({$results['queueProcessed']})");
    
    echo json_encode([
        'status' => 'success',
        'results' => $results,
        'total' => $total,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// 运行主程序
main();
