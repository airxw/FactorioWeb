<?php
/**
 * 自动响应守护进程
 * 
 * ⚠️⚠️⚠️ 警告 ⚠️⚠️⚠️
 * 
 * 请勿直接通过命令行启动此脚本！
 * 必须通过 Web 界面 (daemon_manager.php) 进行启动和停止。
 * 
 * 直接启动可能导致：
 * 1. 多个守护进程实例同时运行
 * 2. 状态文件冲突导致消息重复发送
 * 3. PID 文件不一致
 * 
 * 自动响应系统随服务器启停联动运行，无需手动管理。
 * 启动服务器时自动启动，停止服务器时自动停止。
 */

define('WEB_DIR', dirname(__DIR__));
define('BASE_DIR', dirname(__DIR__, 2));
define('DEFAULT_LOG_FILE', BASE_DIR . '/factorio-current.log');
define('SETTINGS_FILE', WEB_DIR . '/config/state/chatSettings.json');
define('PID_FILE', WEB_DIR . '/run/autoResponder.pid');
define('STATE_FILE', WEB_DIR . '/config/state/autoResponderState.json');
define('POSITION_FILE', WEB_DIR . '/config/state/autoResponderPosition.txt');
define('DAEMON_LOG_FILE', WEB_DIR . '/logs/autoResponderDaemon.log');
define('RUNTIME_CONFIG_FILE', BASE_DIR . '/logs/runtimeConfig.json');
define('VOTE_STATE_FILE', WEB_DIR . '/config/state/voteState.json');
define('VOTE_COOLDOWN_FILE', WEB_DIR . '/config/state/voteCooldown.json');
define('PLAYER_HISTORY_DIR', WEB_DIR . '/config/state/playerHistory');
define('PLAYER_HISTORY_FILE', WEB_DIR . '/config/state/playerHistory.json');
define('ITEM_REQUEST_CONFIRM_FILE', WEB_DIR . '/config/state/itemRequestConfirm.json');
define('RCON_CONFIG_FILE', WEB_DIR . '/config/system/rcon.php');
define('MIN_MESSAGE_INTERVAL', 1);
define('READ_INTERVAL', 100000);
define('DEFAULT_VOTE_TIMEOUT', 60);
define('DEFAULT_VOTE_COOLDOWN', 300);
define('DEFAULT_VOTE_REQUIRED', 3);
define('MAX_LOG_SIZE', 5 * 1024 * 1024);
define('MAX_LOG_FILES', 3);
define('LOG_LEVEL_DEBUG', 0);
define('LOG_LEVEL_INFO', 1);
define('LOG_LEVEL_WARNING', 2);
define('LOG_LEVEL_ERROR', 3);
define('CURRENT_LOG_LEVEL', LOG_LEVEL_INFO);

require_once __DIR__ . '/factorioRcon.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/services/StateService.php';
require_once __DIR__ . '/services/VoteService.php';
require_once __DIR__ . '/services/ItemService.php';
require_once __DIR__ . '/services/PlayerService.php';
require_once __DIR__ . '/services/RconService.php';
require_once __DIR__ . '/services/UserService.php';
require_once __DIR__ . '/services/ChatService.php';
require_once __DIR__ . '/core/LogConfig.php';

use App\Core\LogConfig;
use App\Core\Database;
use App\Services\StateService;
use App\Services\VoteService;
use App\Services\ItemService;
use App\Services\PlayerService;
use App\Services\RconService;
use App\Services\UserService;
use App\Services\ChatService;

$rconConfigRaw = file_exists(WEB_DIR . '/config/system/rcon.php') ? require WEB_DIR . '/config/system/rcon.php' : [];
if (isset($rconConfigRaw['default'])) {
    $rconConfig = $rconConfigRaw['default'];
} else {
    $rconConfig = $rconConfigRaw;
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

$rconConnection = null;
$currentLogFile = null;

function getCurrentLogFile() {
    global $currentLogFile;
    if ($currentLogFile !== null) {
        return $currentLogFile;
    }
    
    if (file_exists(RUNTIME_CONFIG_FILE)) {
        $runtimeConfig = json_decode(file_get_contents(RUNTIME_CONFIG_FILE), true);
        if ($runtimeConfig && !empty($runtimeConfig['config_file'])) {
            $configName = preg_replace('/\.json$/i', '', $runtimeConfig['config_file']);
            $logFile = BASE_DIR . "/logs/factorio-{$configName}.log";
            if (file_exists($logFile)) {
                return $logFile;
            }
        }
    }
    return DEFAULT_LOG_FILE;
}

function getCurrentScreenName() {
    if (file_exists(RUNTIME_CONFIG_FILE)) {
        $runtimeConfig = json_decode(file_get_contents(RUNTIME_CONFIG_FILE), true);
        if ($runtimeConfig && !empty($runtimeConfig['screen_name'])) {
            return $runtimeConfig['screen_name'];
        }
    }
    return 'factorio_server';
}

function logMessage($message, $level = LOG_LEVEL_INFO) {
    if ($level < CURRENT_LOG_LEVEL) {
        return;
    }
    
    if (file_exists(DAEMON_LOG_FILE)) {
        $fileSize = filesize(DAEMON_LOG_FILE);
        if ($fileSize > MAX_LOG_SIZE) {
            rotateLog();
        }
    }
    
    $levelNames = [
        LOG_LEVEL_DEBUG => 'DEBUG',
        LOG_LEVEL_INFO => 'INFO',
        LOG_LEVEL_WARNING => 'WARNING',
        LOG_LEVEL_ERROR => 'ERROR'
    ];
    
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    $logLine = '[' . date('Y-m-d H:i:s') . '] [' . $levelName . '] ' . $message . PHP_EOL;
    file_put_contents(DAEMON_LOG_FILE, $logLine, FILE_APPEND);
}

function rotateLog() {
    for ($i = MAX_LOG_FILES - 1; $i >= 1; $i--) {
        $oldFile = DAEMON_LOG_FILE . '.' . $i;
        $newFile = DAEMON_LOG_FILE . '.' . ($i + 1);
        if (file_exists($oldFile)) {
            if ($i === MAX_LOG_FILES - 1) {
                unlink($oldFile);
            } else {
                rename($oldFile, $newFile);
            }
        }
    }
    
    if (file_exists(DAEMON_LOG_FILE)) {
        rename(DAEMON_LOG_FILE, DAEMON_LOG_FILE . '.1');
    }
}

function logDebug($message) {
    logMessage($message, LOG_LEVEL_DEBUG);
}

function logInfo($message) {
    logMessage($message, LOG_LEVEL_INFO);
}

function logWarning($message) {
    logMessage($message, LOG_LEVEL_WARNING);
}

function logError($message) {
    logMessage($message, LOG_LEVEL_ERROR);
}

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

function getRconConnection()
{
    global $rconConfig, $rconConnection;
    
    if (!$rconConfig['rcon_enabled']) {
        return null;
    }
    
    if ($rconConnection !== null) {
        if ($rconConnection->isConnected()) {
            return $rconConnection;
        }
        $rconConnection->disconnect();
        $rconConnection = null;
    }
    
    try {
        $rconConnection = new FactorioRCON(
            $rconConfig['rcon_host'],
            $rconConfig['rcon_port'],
            $rconConfig['rcon_password']
        );
        $rconConnection->connect();
        logInfo('RCON 连接已建立');
        return $rconConnection;
    } catch (Exception $e) {
        logError('RCON 连接失败: ' . $e->getMessage());
        $rconConnection = null;
        return null;
    }
}

function executeCommand($command)
{
    global $rconConfig, $rconConnection;
    
    if ($rconConfig['rcon_enabled']) {
        static $poolClient = null;
        static $poolAvailable = null;
        
        if ($poolAvailable === null) {
            require_once __DIR__ . '/rconPoolClient.php';
            $poolClient = new RconPoolClient();
            $poolAvailable = $poolClient->ping()['success'] ?? false;
        }
        
        if ($poolAvailable) {
            $result = $poolClient->execute($command);
            if ($result['success']) {
                logDebug('RCON 连接池执行命令: ' . $command);
                return $result['result'] ?? '';
            }
            logWarning('RCON 连接池执行失败: ' . ($result['error'] ?? '未知错误'));
        } else {
            $rcon = getRconConnection();
            if ($rcon !== null) {
                try {
                    $result = $rcon->sendCommand($command);
                    logDebug('RCON 执行命令: ' . $command);
                    return $result;
                } catch (Exception $e) {
                    logError('RCON 执行失败: ' . $e->getMessage());
                    if ($rconConnection !== null) {
                        $rconConnection->disconnect();
                    }
                    $rconConnection = null;
                }
            }
        }
    }
    
    $screenName = getCurrentScreenName();
    $escapedCommand = str_replace('"', '\\"', $command);
    $fullCommand = sprintf('screen -S %s -p 0 -X stuff "%s\n"', $screenName, $escapedCommand);
    shell_exec($fullCommand);
    logDebug('Screen 执行命令: ' . $command);
    return null;
}

// 发送聊天消息
function sendChatMessage($message) {
    global $rconConfig;
    
    // 使用 Factorio 正确的命令格式发送消息
    $command = '/c game.print("' . str_replace('"', '\\"', $message) . '")';
    executeCommand($command);
    
    $logLine = date('Y-m-d H:i:s') . ' [CHAT] <server>: ' . $message . "\n";
    $logFile = getCurrentLogFile();
    if ($logFile) {
        file_put_contents($logFile, $logLine, FILE_APPEND);
    }
    logInfo('发送消息: [public] ' . $message);
}

// 处理游戏ID绑定
function handleGameIdBinding($playerName) {
    $db = Database::getInstance();
    $db->initialize();

    $result = $db->query(
        'SELECT id, binding_code, vip_level, game_id FROM users WHERE binding_code IS NOT NULL AND binding_code != ""',
        []
    );

    if (empty($result)) {
        return;
    }

    foreach ($result as $user) {
        if (!empty($user['binding_code']) && $playerName === $user['binding_code']) {
            $userId = (int)$user['id'];
            $newVipLevel = min((int)$user['vip_level'] + 1, 4);

            $db->execute(
                'UPDATE users SET game_id = ?, binding_code = NULL, vip_level = ?, updated_at = ? WHERE id = ?',
                [$playerName, $newVipLevel, time(), $userId]
            );

            logInfo("游戏ID绑定成功: 用户ID {$userId} 绑定游戏ID {$playerName}, VIP等级提升至 {$newVipLevel}");
            sendChatMessage("绑定成功！您的账号已绑定游戏ID: {$playerName}，VIP等级已提升至 " . getVipLevelName($newVipLevel));
            return;
        }
    }
}

function getVipLevelName($level) {
    $names = ['普通', '青铜', '白银', '黄金', '钻石'];
    return $names[$level] ?? '普通';
}

function getPlayerVipLevel($playerName) {
    try {
        $db = Database::getInstance();
        $db->initialize();

        $result = $db->query(
            'SELECT vip_level FROM users WHERE game_id = ?',
            [$playerName]
        );

        if (!empty($result)) {
            return (int)$result[0]['vip_level'];
        }
        return 0;
    } catch (\Exception $e) {
        logError('获取玩家VIP等级失败: ' . $e->getMessage());
        return 0;
    }
}

// 保存投票状态
function saveVoteState($state) {
    file_put_contents(VOTE_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

// 加载投票状态
function loadVoteState() {
    if (!file_exists(VOTE_STATE_FILE)) {
        return [
            'active' => false,
            'target' => '',
            'votes' => [],
            'required' => 3,
            'started_at' => 0
        ];
    }
    return json_decode(file_get_contents(VOTE_STATE_FILE), true);
}

// 保存投票冷却时间（按玩家）
function saveVoteCooldown($player) {
    $cooldownData = [];
    if (file_exists(VOTE_COOLDOWN_FILE)) {
        $cooldownData = json_decode(file_get_contents(VOTE_COOLDOWN_FILE), true) ?? [];
    }
    $cooldownData[$player] = time();
    file_put_contents(VOTE_COOLDOWN_FILE, json_encode($cooldownData, JSON_PRETTY_PRINT));
}

// 加载投票冷却时间（按玩家）
function loadVoteCooldown($player) {
    if (!file_exists(VOTE_COOLDOWN_FILE)) {
        return 0;
    }
    $cooldownData = json_decode(file_get_contents(VOTE_COOLDOWN_FILE), true) ?? [];
    return $cooldownData[$player] ?? 0;
}

// 检查投票冷却时间（按玩家）
function checkVoteCooldown($player) {
    $config = getVoteKickConfig();
    $lastVoteTime = loadVoteCooldown($player);
    $currentTime = time();
    
    if ($currentTime - $lastVoteTime < $config['cooldown']) {
        $timeLeft = $config['cooldown'] - ($currentTime - $lastVoteTime);
        return [
            'cooling' => true,
            'time_left' => $timeLeft
        ];
    }
    
    return [
        'cooling' => false,
        'time_left' => 0
    ];
}

define('REQUEST_ITEM_COOLDOWN_FILE', WEB_DIR . '/config/state/requestItemCooldown.json');

function saveRequestItemCooldown($player) {
    $cooldownData = [];
    if (file_exists(REQUEST_ITEM_COOLDOWN_FILE)) {
        $cooldownData = json_decode(file_get_contents(REQUEST_ITEM_COOLDOWN_FILE), true) ?? [];
    }
    $cooldownData[$player] = time();
    file_put_contents(REQUEST_ITEM_COOLDOWN_FILE, json_encode($cooldownData, JSON_PRETTY_PRINT));
}

// 保存物品请求确认状态
function saveItemRequestConfirm($player, $itemName, $count) {
    $confirmData = [];
    if (file_exists(ITEM_REQUEST_CONFIRM_FILE)) {
        $confirmData = json_decode(file_get_contents(ITEM_REQUEST_CONFIRM_FILE), true) ?? [];
    }
    $confirmData[$player] = [
        'item' => $itemName,
        'count' => $count,
        'timestamp' => time()
    ];
    file_put_contents(ITEM_REQUEST_CONFIRM_FILE, json_encode($confirmData, JSON_PRETTY_PRINT));
}

// 加载物品请求确认状态
function loadItemRequestConfirm($player) {
    if (!file_exists(ITEM_REQUEST_CONFIRM_FILE)) {
        return null;
    }
    $confirmData = json_decode(file_get_contents(ITEM_REQUEST_CONFIRM_FILE), true) ?? [];
    
    // 检查是否存在确认请求
    if (!isset($confirmData[$player])) {
        return null;
    }
    
    // 检查是否超时（60秒）
    $request = $confirmData[$player];
    if (time() - $request['timestamp'] > 60) {
        // 超时，删除确认请求
        unset($confirmData[$player]);
        file_put_contents(ITEM_REQUEST_CONFIRM_FILE, json_encode($confirmData, JSON_PRETTY_PRINT));
        return null;
    }
    
    return $request;
}

// 删除物品请求确认状态
function deleteItemRequestConfirm($player) {
    if (!file_exists(ITEM_REQUEST_CONFIRM_FILE)) {
        return;
    }
    $confirmData = json_decode(file_get_contents(ITEM_REQUEST_CONFIRM_FILE), true) ?? [];
    unset($confirmData[$player]);
    file_put_contents(ITEM_REQUEST_CONFIRM_FILE, json_encode($confirmData, JSON_PRETTY_PRINT));
}

function loadRequestItemCooldown($player) {
    if (!file_exists(REQUEST_ITEM_COOLDOWN_FILE)) {
        return 0;
    }
    $cooldownData = json_decode(file_get_contents(REQUEST_ITEM_COOLDOWN_FILE), true) ?? [];
    return $cooldownData[$player] ?? 0;
}

function checkRequestItemCooldown($player) {
    $settings = getSettings();
    $serverResponses = $settings['serverResponses'] ?? [];
    $cooldown = 60;
    
    foreach ($serverResponses as $response) {
        if ($response['type'] === 'request-item') {
            $configValue = $response['value'] ?? '';
            $config = is_string($configValue) ? json_decode($configValue, true) : $configValue;
            $cooldown = intval($config['cooldown'] ?? 60);
            break;
        }
    }
    
    $lastRequestTime = loadRequestItemCooldown($player);
    $currentTime = time();
    
    if ($currentTime - $lastRequestTime < $cooldown) {
        $timeLeft = $cooldown - ($currentTime - $lastRequestTime);
        return [
            'cooling' => true,
            'time_left' => $timeLeft
        ];
    }
    
    return [
        'cooling' => false,
        'time_left' => 0
    ];
}

function giveItemToPlayer($player, $itemName, $count, $quality = 1) {
    $suggestions = [];
    $resolvedName = resolveItemName($itemName, $suggestions);
    
    $itemsFile = WEB_DIR . '/config/game/items.json';
    $validItem = false;
    
    if (file_exists($itemsFile)) {
        $itemsData = json_decode(file_get_contents($itemsFile), true);
        if (is_array($itemsData)) {
            foreach ($itemsData as $category => $items) {
                if (isset($items[$resolvedName])) {
                    $validItem = true;
                    break;
                }
            }
        }
    }
    
    if (!$validItem) {
        $errorMsg = '未找到该物品';
        if (!empty($suggestions)) {
            $suggestionList = [];
            foreach (array_slice($suggestions, 0, 5) as $suggestion) {
                $suggestionList[] = "{$suggestion['name']} ({$suggestion['code']})";
            }
            $errorMsg .= '，可能的物品: ' . implode(', ', $suggestionList);
        }
        return ['success' => false, 'error' => $errorMsg, 'suggestions' => $suggestions];
    }
    
    $qualityName = getQualityName($quality);
    
    if ($quality > 1) {
        $command = sprintf('/c game.get_player("%s").insert{name="%s",count=%d,quality="%s"}', $player, $resolvedName, $count, $qualityName);
    } else {
        $command = sprintf('/c game.get_player("%s").insert{name="%s",count=%d}', $player, $resolvedName, $count);
    }
    $result = executeCommand($command);
    logInfo("给予物品: 玩家={$player}, 物品={$resolvedName}, 数量={$count}, 品质={$qualityName}");
    return ['success' => true, 'item' => $resolvedName, 'quality' => $qualityName];
}

function getQualityName($level) {
    $qualities = [
        1 => 'normal',
        2 => 'uncommon', 
        3 => 'rare',
        4 => 'epic',
        5 => 'legendary'
    ];
    return $qualities[$level] ?? 'normal';
}

function getQualityLevel($name) {
    $levels = [
        'normal' => 1,
        'uncommon' => 2,
        'rare' => 3,
        'epic' => 4,
        'legendary' => 5
    ];
    return $levels[$name] ?? 1;
}

function getPlayerHistoryFile() {
    if (file_exists(RUNTIME_CONFIG_FILE)) {
        $runtimeConfig = json_decode(file_get_contents(RUNTIME_CONFIG_FILE), true);
        if ($runtimeConfig && !empty($runtimeConfig['config_file'])) {
            $configName = preg_replace('/\.json$/i', '', $runtimeConfig['config_file']);
            $historyDir = PLAYER_HISTORY_DIR;
            if (!is_dir($historyDir)) {
                mkdir($historyDir, 0755, true);
            }
            return $historyDir . '/' . $configName . '.json';
        }
    }
    return PLAYER_HISTORY_FILE;
}

function loadPlayerHistory() {
    $historyFile = getPlayerHistoryFile();
    if (!file_exists($historyFile)) {
        return [];
    }
    $content = file_get_contents($historyFile);
    return json_decode($content, true) ?? [];
}

function savePlayerHistory($history) {
    $historyFile = getPlayerHistoryFile();
    $historyDir = dirname($historyFile);
    if (!is_dir($historyDir)) {
        mkdir($historyDir, 0755, true);
    }
    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
}

function isPlayerFirstJoin($player) {
    $history = loadPlayerHistory();
    return !isset($history[$player]);
}

function recordPlayerJoin($player) {
    $history = loadPlayerHistory();
    if (!isset($history[$player])) {
        $history[$player] = [
            'first_join' => time(),
            'join_count' => 1,
            'last_join' => time()
        ];
    } else {
        $history[$player]['join_count']++;
        $history[$player]['last_join'] = time();
    }
    savePlayerHistory($history);
}

function parseGiftItems($itemsText) {
    $items = [];
    $lines = explode("\n", trim($itemsText));
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        $parts = preg_split('/\s+/', $line, 3);
        if (count($parts) >= 1) {
            $itemName = $parts[0];
            $count = 1;
            $quality = 1;
            
            for ($i = 1; $i < count($parts); $i++) {
                $part = $parts[$i];
                if (is_numeric($part)) {
                    $count = intval($part);
                    if ($count < 1) $count = 1;
                } elseif (preg_match('/^q(\d)$/i', $part, $m)) {
                    $quality = intval($m[1]);
                    if ($quality < 1) $quality = 1;
                    if ($quality > 5) $quality = 5;
                } elseif (in_array(strtolower($part), ['normal', 'uncommon', 'rare', 'epic', 'legendary'])) {
                    $quality = getQualityLevel(strtolower($part));
                }
            }
            
            $items[] = ['name' => $itemName, 'count' => $count, 'quality' => $quality];
        }
    }
    return $items;
}

function giveGiftToPlayer($player, $itemsText) {
    $items = parseGiftItems($itemsText);
    if (empty($items)) {
        return ['success' => false, 'error' => '物品列表为空'];
    }
    
    $successCount = 0;
    $failedItems = [];
    
    foreach ($items as $item) {
        $result = giveItemToPlayer($player, $item['name'], $item['count'], $item['quality'] ?? 1);
        if ($result['success']) {
            $successCount++;
        } else {
            $failedItems[] = $item['name'];
        }
    }
    
    if ($successCount > 0) {
        logInfo("礼包派发: 玩家={$player}, 成功={$successCount}项");
        return ['success' => true, 'count' => $successCount, 'failed' => $failedItems];
    }
    
    return ['success' => false, 'error' => '所有物品派发失败', 'failed' => $failedItems];
}

function resolveItemName($input, &$suggestions = []) {
    $input = trim($input);
    if (empty($input)) {
        return null;
    }
    
    $itemsFile = WEB_DIR . '/config/game/items.json';
    if (!file_exists($itemsFile)) {
        return $input;
    }
    
    $itemsData = json_decode(file_get_contents($itemsFile), true);
    if (!is_array($itemsData)) {
        return $input;
    }
    
    $inputLower = mb_strtolower($input, 'UTF-8');
    
    foreach ($itemsData as $category => $items) {
        foreach ($items as $code => $name) {
            if ($input === $code || $inputLower === mb_strtolower($code, 'UTF-8')) {
                return $code;
            }
            if ($input === $name || $inputLower === mb_strtolower($name, 'UTF-8')) {
                return $code;
            }
            // 查找相似的物品名称
            if (strpos(mb_strtolower($code, 'UTF-8'), $inputLower) !== false || strpos(mb_strtolower($name, 'UTF-8'), $inputLower) !== false) {
                $suggestions[] = ['code' => $code, 'name' => $name];
            }
        }
    }
    
    return $input;
}

// 开始投票踢人
function startVoteKick($player, $target, $required) {
    $cooldown = checkVoteCooldown($player);
    if ($cooldown['cooling']) {
        $message = "投票踢人冷却中，还需 {$cooldown['time_left']} 秒才能发起新的投票。";
        sendChatMessage($message);
        logWarning('投票踢人冷却中');
        return;
    }
    
    $state = [
        'active' => true,
        'target' => $target,
        'votes' => [$player => 'yes'],
        'required' => $required,
        'started_at' => time()
    ];
    saveVoteState($state);
    
    $config = getVoteKickConfig();
    if (!is_array($config)) {
        $config = [
            'required' => DEFAULT_VOTE_REQUIRED,
            'timeout' => DEFAULT_VOTE_TIMEOUT,
            'cooldown' => DEFAULT_VOTE_COOLDOWN
        ];
    }
    sendChatMessage("$player 玩家建议将 $target 踢出游戏，投票时限为 " . $config['timeout'] . " 秒，请在时限内输入 -yes 或者 -no 进行支持或者否决。");
    sendChatMessage("需要 $required 票赞成才能成功踢人。");
    sendChatMessage("输入 !vote status 查看投票状态。");
    logInfo('开始投票踢人: ' . $target . '，需要 ' . $required . ' 票');
}

// 处理投票
function processVote($player, $vote) {
    $state = loadVoteState();
    
    if (!$state['active']) {
        return "当前没有进行中的投票";
    }
    
    $config = getVoteKickConfig();
    // 检查投票是否超时
    if (time() - $state['started_at'] > $config['timeout']) {
        $state['active'] = false;
        saveVoteState($state);
        return "投票已超时，未达到所需票数";
    }
    
    // 检查玩家是否已经投过票
    if (isset($state['votes'][$player])) {
        return "你已经投过票了";
    }
    
    // 记录投票
    $state['votes'][$player] = $vote;
    saveVoteState($state);
    
    // 计算赞成票数
    $yesVotes = count(array_filter($state['votes'], function($v) { return $v === 'yes'; }));
    $totalVotes = count($state['votes']);
    
    if ($yesVotes >= $state['required']) {
        // 投票通过，执行踢人
        kickPlayer($state['target']);
        $state['active'] = false;
        saveVoteState($state);
        // 保存投票冷却时间（对发起者）
        // 找到发起者（第一个投票的玩家）
        $initiator = array_key_first($state['votes']);
        if ($initiator) {
            saveVoteCooldown($initiator);
        }
        return "投票通过！$state[target] 已被踢出服务器";
    }
    
    return "投票已记录！当前赞成: $yesVotes/$state[required]，总票数: $totalVotes";
}

// 执行踢人操作
function kickPlayer($player) {
    $command = "/kick $player";
    executeCommand($command);
    logWarning('执行踢人: ' . $player);
}

// 检查投票状态
function checkVoteStatus($player = null) {
    $state = loadVoteState();
    
    if (!$state['active']) {
        if ($player !== null) {
            $cooldown = checkVoteCooldown($player);
            if ($cooldown['cooling']) {
                return "当前没有进行中的投票，投票踢人冷却中，还需 {$cooldown['time_left']} 秒才能发起新的投票。";
            }
        }
        return "当前没有进行中的投票。输入 !votekick <玩家名> 发起投票踢人。";
    }
    
    $config = getVoteKickConfig();
    // 检查投票是否超时
    if (time() - $state['started_at'] > $config['timeout']) {
        $state['active'] = false;
        saveVoteState($state);
        // 保存投票冷却时间（对发起者）
        // 找到发起者（第一个投票的玩家）
        $initiator = array_key_first($state['votes']);
        if ($initiator) {
            saveVoteCooldown($initiator);
        }
        return "投票已超时，未达到所需票数";
    }
    
    $yesVotes = count(array_filter($state['votes'], function($v) { return $v === 'yes'; }));
    $totalVotes = count($state['votes']);
    $timeLeft = $config['timeout'] - (time() - $state['started_at']);
    
    return "投票进行中！目标: $state[target]，赞成: $yesVotes/$state[required]，总票数: {$totalVotes}，剩余时间: {$timeLeft} 秒\n" .
           "输入 !vote yes 赞成，!vote no 反对。";
}

// 获取服务器性能信息
function getServerInfo() {
    $tzFile = WEB_DIR . '/tz.php';
    if (!file_exists($tzFile)) {
        return null;
    }
    
    $output = shell_exec('php ' . escapeshellarg($tzFile) . ' 2>&1');
    if ($output === null) {
        return null;
    }
    
    $data = json_decode($output, true);
    return $data;
}

// 格式化服务器信息为聊天消息
function formatServerInfo($data) {
    if (!$data) {
        return '无法获取服务器信息';
    }
    
    $messages = [];
    
    // CPU信息
    $messages[] = "CPU: {$data['cpu_usage']}% ({$data['cpu_cores']}核心)";
    
    // 内存信息
    $memTotal = round($data['mem']['total'] / (1024 * 1024 * 1024), 2);
    $memUsed = round($data['mem']['real_used'] / (1024 * 1024 * 1024), 2);
    $messages[] = "内存: {$memUsed}GB/{$memTotal}GB ({$data['mem']['percent']}%)";
    
    // 负载信息
    $load = implode(', ', $data['load']);
    $messages[] = "负载: {$load}";
    
    // 运行时间
    $uptimeHours = floor($data['uptime'] / 3600);
    $uptimeMins = floor(($data['uptime'] % 3600) / 60);
    $messages[] = "运行时间: {$uptimeHours}小时{$uptimeMins}分钟";
    
    // 磁盘信息
    $messages[] = "磁盘: {$data['disk']['used']}GB/{$data['disk']['total']}GB ({$data['disk']['percent']}%)";
    
    return implode(' | ', $messages);
}

// 获取配置
function getSettings() {
    static $chatService = null;
    if ($chatService === null) {
        try {
            $chatService = new ChatService();
        } catch (\Exception $e) {
            logError('ChatService 初始化失败: ' . $e->getMessage());
        }
    }

    if ($chatService !== null) {
        try {
            return $chatService->getSettings();
        } catch (\Exception $e) {
            logError('ChatService 获取设置失败: ' . $e->getMessage());
        }
    }

    if (!file_exists(SETTINGS_FILE)) {
        return [
            'scheduledTasks' => [],
            'triggerResponses' => [],
            'periodicMessages' => [],
            'serverResponses' => [],
            'playerEvents' => [
                'welcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'goodbye' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'firstJoinGift' => ['enabled' => false, 'items' => ''],
                'rejoinGift' => ['enabled' => false, 'items' => '']
            ]
        ];
    }
    return json_decode(file_get_contents(SETTINGS_FILE), true);
}

function saveSettings($settings) {
    file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function processScheduledTasks() {
    $settings = getSettings();
    $now = new DateTime();
    $tasksExecuted = 0;
    
    if (!isset($settings['scheduledTasks'])) {
        return $tasksExecuted;
    }
    
    $updatedTasks = [];
    
    foreach ($settings['scheduledTasks'] as $task) {
        if (!isset($task['time'])) {
            $updatedTasks[] = $task;
            continue;
        }
        
        $taskTime = new DateTime($task['time']);
        
        if ($taskTime <= $now) {
            if (isset($task['message']) && !empty($task['message'])) {
                sendChatMessage($task['message']);
                logInfo("执行定时任务: " . $task['message']);
                $tasksExecuted++;
            }
            
            if (isset($task['repeat']) && $task['repeat'] !== 'once') {
                $nextTask = $task;
                $nextTime = new DateTime($task['time']);
                
                switch ($task['repeat']) {
                    case 'daily':
                        $nextTime->modify('+1 day');
                        break;
                    case 'weekly':
                        $nextTime->modify('+1 week');
                        break;
                    case 'seconds':
                        $interval = isset($task['interval']) ? intval($task['interval']) : 60;
                        $nextTime->modify("+{$interval} seconds");
                        break;
                    case 'minutes':
                        $interval = isset($task['interval']) ? intval($task['interval']) : 1;
                        $nextTime->modify("+{$interval} minutes");
                        break;
                    case 'hours':
                        $interval = isset($task['interval']) ? intval($task['interval']) : 1;
                        $nextTime->modify("+{$interval} hours");
                        break;
                }
                
                $nextTask['time'] = $nextTime->format('Y-m-d H:i:s');
                $updatedTasks[] = $nextTask;
            }
        } else {
            $updatedTasks[] = $task;
        }
    }
    
    $settings['scheduledTasks'] = $updatedTasks;
    saveSettings($settings);
    
    return $tasksExecuted;
}

// 获取投票踢人配置
function getVoteKickConfig() {
    $settings = getSettings();
    $serverResponses = $settings['serverResponses'] ?? [];
    
    foreach ($serverResponses as $response) {
        if ($response['type'] === 'vote-kick') {
            // 检查value是否是JSON格式
            $value = $response['value'] ?? '';
            if (is_array($value)) {
                // 如果已经是数组，直接使用
                return [
                    'required' => intval($value['required'] ?? DEFAULT_VOTE_REQUIRED),
                    'timeout' => intval($value['timeout'] ?? DEFAULT_VOTE_TIMEOUT),
                    'cooldown' => intval($value['cooldown'] ?? DEFAULT_VOTE_COOLDOWN)
                ];
            } elseif (is_string($value)) {
                // 如果是字符串，尝试解析为JSON
                $config = json_decode($value, true);
                if (is_array($config)) {
                    return [
                        'required' => intval($config['required'] ?? DEFAULT_VOTE_REQUIRED),
                        'timeout' => intval($config['timeout'] ?? DEFAULT_VOTE_TIMEOUT),
                        'cooldown' => intval($config['cooldown'] ?? DEFAULT_VOTE_COOLDOWN)
                    ];
                }
            }
            // 如果解析失败，使用默认值
            return [
                'required' => DEFAULT_VOTE_REQUIRED,
                'timeout' => DEFAULT_VOTE_TIMEOUT,
                'cooldown' => DEFAULT_VOTE_COOLDOWN
            ];
        }
    }
    
    // 如果没有找到投票踢人配置，使用默认值
    return [
        'required' => DEFAULT_VOTE_REQUIRED,
        'timeout' => DEFAULT_VOTE_TIMEOUT,
        'cooldown' => DEFAULT_VOTE_COOLDOWN
    ];
}

// 执行延迟测试
function performPingTest($player) {
    // 从日志文件中获取玩家的IP地址
    $ip = getPlayerIP($player);
    if (!$ip) {
        return "无法获取 $player 的IP地址，请确保玩家已登录过服务器。";
    }
    
    // 执行ping操作
    $pingCount = 5;
    $pingResults = [];
    $successCount = 0;
    $totalTime = 0;
    
    for ($i = 0; $i < $pingCount; $i++) {
        $output = shell_exec("ping -c 1 -W 1 $ip 2>&1");
        if (preg_match('/time=(\d+\.\d+) ms/', $output, $matches)) {
            $time = floatval($matches[1]);
            $pingResults[] = $time;
            $successCount++;
            $totalTime += $time;
        } else {
            $pingResults[] = null;
        }
        // 等待100ms再进行下一次ping
        usleep(100000);
    }
    
    // 计算结果
    $packetLoss = (($pingCount - $successCount) / $pingCount) * 100;
    $avgTime = $successCount > 0 ? $totalTime / $successCount : 0;
    $minTime = $successCount > 0 ? min(array_filter($pingResults)) : 0;
    $maxTime = $successCount > 0 ? max(array_filter($pingResults)) : 0;
    
    // 格式化结果为单行消息
    $result = "$player 的网络延迟测试结果：";
    $result .= "丢包率: " . number_format($packetLoss, 1) . "%";
    if ($successCount > 0) {
        $result .= "，平均延迟: " . number_format($avgTime, 1) . "ms";
        $result .= "，最小延迟: " . number_format($minTime, 1) . "ms";
        $result .= "，最大延迟: " . number_format($maxTime, 1) . "ms";
    } else {
        $result .= "，无法连接到目标IP";
    }
    
    return $result;
}

// 从日志文件中获取玩家的IP地址
function getPlayerIP($player) {
    $logFile = getCurrentLogFile();
    if (!file_exists($logFile)) {
        return false;
    }
    
    // 读取日志文件的最后10000行，查找玩家的IP地址信息
    $lines = shell_exec("tail -n 10000 $logFile");
    $lines = explode("\n", $lines);
    
    // 倒序查找，找到包含玩家名和IP地址的记录
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = $lines[$i];
        // 查找包含玩家名和IP地址的行
        // 匹配多种格式：直接包含玩家名、username (玩家名) 格式、username(玩家名) 格式
        if ((strpos($line, $player) !== false || strpos($line, "username ({$player})") !== false || strpos($line, "username({$player})") !== false)) {
            // 匹配 IPv4 地址
            if (preg_match('/\d+\.\d+\.\d+\.\d+/', $line, $matches)) {
                return $matches[0];
            }
            // 匹配 IPv6 地址
            if (preg_match('/\[([0-9a-fA-F:.]+)\]/', $line, $matches)) {
                return $matches[1];
            }
        }
    }
    
    return false;
}

// 保存文件指针位置
function saveFilePosition($position, $inode = null) {
    $data = [
        'position' => $position,
        'inode' => $inode,
        'updated_at' => time()
    ];
    file_put_contents(POSITION_FILE, json_encode($data));
}

function loadFilePosition() {
    if (!file_exists(POSITION_FILE)) {
        return ['position' => 0, 'inode' => null];
    }
    $data = json_decode(file_get_contents(POSITION_FILE), true);
    if (!is_array($data)) {
        return ['position' => 0, 'inode' => null];
    }
    return [
        'position' => $data['position'] ?? 0,
        'inode' => $data['inode'] ?? null
    ];
}

function getFileInode($filepath) {
    $stat = @stat($filepath);
    return $stat ? $stat['ino'] : false;
}

function isFactorioRunning() {
    $screenName = getCurrentScreenName();
    
    $screenCheck = shell_exec("screen -ls 2>/dev/null | grep -E '\." . preg_quote($screenName, '/') . "\s' || echo ''");
    if (!empty(trim($screenCheck))) {
        return true;
    }
    
    $psCheck = shell_exec("ps aux | grep '[b]in/x64/factorio.*--start-server' 2>/dev/null");
    return !empty(trim($psCheck));
}

// 监控日志文件
function monitorLog() {
    global $rconConnection;
    
    $lastMessageTime = 0;
    $lastScheduledCheck = 0;
    $lastRconHeartbeat = 0;
    $scheduledCheckInterval = 1;
    $rconHeartbeatInterval = 30;
    $maxLinesPerRead = 500;
    $checkLogChangeInterval = 5;
    $lastLogCheck = 0;
    $currentLogFile = getCurrentLogFile();
    $currentScreenName = getCurrentScreenName();
    
    logInfo('监控日志文件: ' . $currentLogFile);
    logInfo('Screen 名称: ' . $currentScreenName);
    
    $currentInode = getFileInode($currentLogFile);
    $savedPosition = loadFilePosition();
    
    if ($savedPosition['inode'] !== null && $savedPosition['inode'] !== $currentInode) {
        $filePosition = 0;
        logInfo('检测到日志文件轮转（inode变更），从新文件开头开始处理');
    } elseif ($savedPosition['inode'] === null) {
        $handle = fopen($currentLogFile, 'r');
        if ($handle !== false) {
            fseek($handle, 0, SEEK_END);
            $filePosition = ftell($handle);
            fclose($handle);
        } else {
            $filePosition = 0;
        }
        logInfo('守护进程启动，从文件末尾开始处理（位置 ' . $filePosition . '）');
    } else {
        $filePosition = $savedPosition['position'];
        logInfo('守护进程启动，从上次位置继续处理（位置 ' . $filePosition . '）');
    }
    
    saveFilePosition($filePosition, $currentInode);
    
    $lastInode = $currentInode;
    
    while (true) {
        try {
            pcntl_signal_dispatch();
            
            if (!isFactorioRunning()) {
                logInfo('Factorio 服务器进程已停止，自动响应守护进程退出');
                @unlink(PID_FILE);
                saveState(['running' => false, 'stopped_at' => date('Y-m-d H:i:s'), 'reason' => 'server_stopped']);
                exit(0);
            }
            
            $currentTime = time();
            if ($currentTime - $lastScheduledCheck >= $scheduledCheckInterval) {
                processScheduledTasks();
                $lastScheduledCheck = $currentTime;
            }
            
            if ($currentTime - $lastRconHeartbeat >= $rconHeartbeatInterval) {
                if ($rconConnection !== null && $rconConnection->isConnected()) {
                    try {
                        $rconConnection->sendCommand('/p');
                        logDebug('RCON 心跳检测成功');
                    } catch (Exception $e) {
                        logWarning('RCON 心跳检测失败: ' . $e->getMessage());
                        $rconConnection->disconnect();
                        $rconConnection = null;
                    }
                }
                $lastRconHeartbeat = $currentTime;
            }
            
            if ($currentTime - $lastLogCheck >= $checkLogChangeInterval) {
                $newLogFile = getCurrentLogFile();
                $newScreenName = getCurrentScreenName();
                if ($newLogFile !== $currentLogFile) {
                    logInfo('检测到日志文件变更: ' . $currentLogFile . ' -> ' . $newLogFile);
                    $currentLogFile = $newLogFile;
                    $filePosition = 0;
                    $lastInode = 0;
                }
                if ($newScreenName !== $currentScreenName) {
                    logInfo('检测到 Screen 名称变更: ' . $currentScreenName . ' -> ' . $newScreenName);
                    $currentScreenName = $newScreenName;
                }
                $lastLogCheck = $currentTime;
            }
            
            if (!file_exists($currentLogFile)) {
                logWarning('日志文件不存在: ' . $currentLogFile);
                usleep(READ_INTERVAL);
                continue;
            }
            
            $currentInode = getFileInode($currentLogFile);
            
            if ($lastInode !== $currentInode) {
                logInfo('检测到日志文件轮转，从新文件开头开始处理');
                $filePosition = 0;
                $lastInode = $currentInode;
                saveFilePosition($filePosition, $currentInode);
            }
            
            clearstatcache(true, $currentLogFile);
            
            $handle = fopen($currentLogFile, 'r');
            if ($handle === false) {
                logError('无法打开日志文件: ' . $currentLogFile);
                usleep(READ_INTERVAL);
                continue;
            }
            
            $fileSize = filesize($currentLogFile);
            if ($filePosition > $fileSize) {
                $filePosition = $fileSize;
                saveFilePosition($filePosition, $currentInode);
            }
            
            if ($filePosition > 0) {
                fseek($handle, $filePosition);
            }
            
            $newLines = [];
            $linesRead = 0;
            while (($line = fgets($handle)) !== false && $linesRead < $maxLinesPerRead) {
                $newLines[] = trim($line);
                $linesRead++;
            }
            
            $filePosition = ftell($handle);
            saveFilePosition($filePosition, $currentInode);
            
            fclose($handle);
            
            if (!empty($newLines)) {
                logDebug('检测到新内容，开始处理');
                foreach ($newLines as $line) {
                    try {
                        processLogLine($line, $lastMessageTime, $currentScreenName);
                    } catch (Exception $e) {
                        logError('处理日志行失败: ' . $e->getMessage());
                    }
                }
                logDebug('处理完成，处理了 ' . count($newLines) . ' 行');
            }
            
            usleep(READ_INTERVAL);
        } catch (Exception $e) {
            logError('守护进程主循环错误: ' . $e->getMessage());
            usleep(1000000);
        }
    }
}

// 处理单行日志
function processLogLine($line, &$lastMessageTime, $screenName = 'factorio_server') {
    $settings = getSettings();
    $currentTime = time();
    
    logDebug('处理日志行: ' . trim($line));
    
    if (strpos($line, '[CHAT]') !== false) {
            $chatMatch = [];
            if (preg_match('/\[CHAT\]\s*(.+?):\s*(.+)$/', $line, $chatMatch)) {
                $player = trim($chatMatch[1]);
                $message = trim($chatMatch[2]);
                
                // 处理物品请求确认
                $confirmRequest = loadItemRequestConfirm($player);
                if ($confirmRequest) {
                    $messageLower = strtolower($message);
                    if ($messageLower === 'yes' || $messageLower === '是') {
                        // 确认给予物品
                        $command = sprintf('/c game.get_player("%s").insert{name="%s",count=%d}', $player, $confirmRequest['item'], $confirmRequest['count']);
                        executeCommand($command);
                        saveRequestItemCooldown($player);
                        sendChatMessage("已给予 {$player} {$confirmRequest['count']} 个 {$confirmRequest['item']}");
                        deleteItemRequestConfirm($player);
                    } elseif ($messageLower === 'no' || $messageLower === '否') {
                        // 取消请求
                        sendChatMessage("{$player}，物品请求已取消");
                        deleteItemRequestConfirm($player);
                    }
                    return;
                }
                
                logDebug('解析聊天消息: 玩家=' . $player . ', 消息=' . $message);
            
            if (strtolower($player) === '<server>' || strtolower($player) === 'server') {
                logDebug('忽略服务器消息');
                return;
            }
            
            logDebug('开始检查关键词');
            
            if (preg_match('/^!votekick\s+(.+)$/', $message, $match)) {
                $target = trim($match[1]);
                $config = getVoteKickConfig();
                $required = $config['required'];
                
                startVoteKick($player, $target, $required);
                $lastMessageTime = $currentTime;
                return;
            } elseif (preg_match('/^!vote\s+(yes|no)$/', $message, $match)) {
                $vote = $match[1];
                $response = processVote($player, $vote);
                sendChatMessage($response);
                $lastMessageTime = $currentTime;
                return;
            } elseif (preg_match('/^-(yes|no)$/', $message, $match)) {
                $vote = $match[1];
                $response = processVote($player, $vote);
                sendChatMessage($response);
                $lastMessageTime = $currentTime;
                return;
            } elseif (preg_match('/^!vote\s+status$/', $message)) {
                $response = checkVoteStatus($player);
                sendChatMessage($response);
                $lastMessageTime = $currentTime;
                return;
            }
            
            $responseSent = false;
            foreach ($settings['serverResponses'] ?? [] as $serverResponse) {
                logDebug('检查服务器响应: ' . $serverResponse['keyword']);
                $keyword = $serverResponse['keyword'];
                // 匹配命令格式，支持关键词后面有空格或直接跟参数
                $exactMatch = ($message === $keyword);
                $commandMatchWithSpace = preg_match('/^' . preg_quote($keyword) . '\s+(.+)$/', $message, $matches1);
                $commandMatchWithoutSpace = preg_match('/^' . preg_quote($keyword) . '(.+)$/', $message, $matches2);
                
                // 确定是否匹配以及获取参数
                $matched = $exactMatch || $commandMatchWithSpace || $commandMatchWithoutSpace;
                $param = '';
                if ($commandMatchWithSpace) {
                    $param = $matches1[1];
                } elseif ($commandMatchWithoutSpace) {
                    $param = $matches2[1];
                }
                
                if ($matched) {
                    logDebug('匹配服务器响应: ' . $serverResponse['keyword']);
                    if ($currentTime - $lastMessageTime >= MIN_MESSAGE_INTERVAL) {
                        $response = '';
                        
                        $skipResponse = false;
                        switch ($serverResponse['type']) {
                            case 'server-info':
                                $serverInfo = getServerInfo();
                                $response = formatServerInfo($serverInfo);
                                break;
                            case 'ping-test':
                                if (!empty($param)) {
                                    $target = trim($param);
                                    // 测试指定玩家的延迟
                                    $pingResult = performPingTest($target);
                                    $response = $pingResult;
                                } else {
                                    // 测试自己的延迟
                                    $pingResult = performPingTest($player);
                                    $response = $pingResult;
                                }
                                break;
                            case 'vote-kick':
                                if (!empty($param)) {
                                    $target = trim($param);
                                    $config = getVoteKickConfig();
                                    $required = $config['required'];
                                    startVoteKick($player, $target, $required);
                                    $skipResponse = true;
                                } else {
                                    $config = getVoteKickConfig();
                                    $response = "投票踢人功能配置: 需要{$config['required']}票，超时{$config['timeout']}秒，冷却{$config['cooldown']}秒";
                                }
                                break;
                            case 'restart-warning':
                                $response = "服务器将在{$serverResponse['value']}分钟后重启";
                                break;
                            case 'custom':
                                $response = $serverResponse['value'];
                                break;
                            case 'request-item':
                                if (!empty($param)) {
                                    $itemName = trim($param);
                                    $configValue = $serverResponse['value'] ?? '';
                                    $config = is_string($configValue) ? json_decode($configValue, true) : $configValue;
                                    $count = intval($config['count'] ?? 100);
                                    $cooldown = intval($config['cooldown'] ?? 60);
                                    
                                    $cooldownCheck = checkRequestItemCooldown($player);
                                    if ($cooldownCheck['cooling']) {
                                        $response = "{$player}，讨要物品冷却中，还需 {$cooldownCheck['time_left']} 秒";
                                    } else {
                                        $giveResult = giveItemToPlayer($player, $itemName, $count);
                                        if ($giveResult['success']) {
                                            // 保存确认请求
                                            saveItemRequestConfirm($player, $giveResult['item'], $count);
                                            $response = "{$player}，确定要 {$count} 个 {$giveResult['item']} 吗？请回复 yes 确认，no 取消";
                                        } else {
                                            $response = "给予物品失败: " . $giveResult['error'];
                                        }
                                    }
                                } else {
                                    $response = "使用方法: {$keyword} <物品名称>，支持中英文名称";
                                }
                                break;
                            default:
                                $response = '未知的服务器响应类型';
                        }
                        
                        if (!$skipResponse) {
                            logDebug('发送服务器响应: ' . $response);
                            sendChatMessage($response);
                            $lastMessageTime = $currentTime;
                            $responseSent = true;
                        }
                    } else {
                        logDebug('消息间隔不足，跳过发送');
                    }
                    break;
                }
            }
            
            if ($responseSent) {
                return;
            }
            
            foreach ($settings['triggerResponses'] ?? [] as $trigger) {
                logDebug('检查关键词: ' . $trigger['keyword']);
                if (strpos($message, $trigger['keyword']) !== false) {
                    logDebug('匹配关键词: ' . $trigger['keyword']);
                    if ($currentTime - $lastMessageTime >= MIN_MESSAGE_INTERVAL) {
                        $response = str_replace(['{player}', '{message}'], [$player, $message], $trigger['response']);
                        logDebug('发送回复: ' . $response);
                        sendChatMessage($response);
                        $lastMessageTime = $currentTime;
                    } else {
                        logDebug('消息间隔不足，跳过发送');
                    }
                    break;
                }
            }
        }
    }
    
    if (strpos($line, '[JOIN]') !== false) {
        $joinMatch = [];
        if (preg_match('/\[JOIN\]\s*(.+)\s+joined/', $line, $joinMatch)) {
            $player = trim($joinMatch[1]);

            // 检查是否首次上线
            $isFirstJoin = isPlayerFirstJoin($player);

            // 记录玩家上线
            recordPlayerJoin($player);

            // 处理游戏ID绑定
            handleGameIdBinding($player);

            // 发送欢迎消息
            $vipLevel = getPlayerVipLevel($player);
            $isVip = $vipLevel > 0;

            if ($isVip) {
                // VIP玩家欢迎消息
                $vipWelcome = $settings['playerEvents']['vipWelcome'] ?? null;
                if ($vipWelcome && $vipWelcome['enabled'] && !empty($vipWelcome['message'])) {
                    if ($currentTime - $lastMessageTime >= MIN_MESSAGE_INTERVAL) {
                        $message = str_replace('{player}', $player, $vipWelcome['message']);
                        sendChatMessage($message);
                        $lastMessageTime = $currentTime;
                    }
                }
            } else {
                // 普通玩家欢迎消息
                $welcome = $settings['playerEvents']['welcome'] ?? null;
                if ($welcome && $welcome['enabled'] && !empty($welcome['message'])) {
                    if ($currentTime - $lastMessageTime >= MIN_MESSAGE_INTERVAL) {
                        $message = str_replace('{player}', $player, $welcome['message']);
                        sendChatMessage($message);
                        $lastMessageTime = $currentTime;
                    }
                }
            }

            // 派发礼包
            if ($isFirstJoin) {
                // 首次上线礼包
                $firstJoinGift = $settings['playerEvents']['firstJoinGift'] ?? null;
                if ($firstJoinGift && $firstJoinGift['enabled'] && !empty($firstJoinGift['items'])) {
                    $result = giveGiftToPlayer($player, $firstJoinGift['items']);
                    if ($result['success']) {
                        logInfo("首次上线礼包派发成功: {$player}, {$result['count']}项物品");
                        sleep(1);
                        sendChatMessage("欢迎新玩家 {$player}！已获得新手礼包");
                    }
                }
            } else {
                // 再次上线礼包
                $rejoinGift = $settings['playerEvents']['rejoinGift'] ?? null;
                if ($rejoinGift && $rejoinGift['enabled'] && !empty($rejoinGift['items'])) {
                    $result = giveGiftToPlayer($player, $rejoinGift['items']);
                    if ($result['success']) {
                        logInfo("再次上线礼包派发成功: {$player}, {$result['count']}项物品");
                    }
                }
            }
        }
    }

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
        
        // 确保必要的目录存在
        $runDir = dirname(PID_FILE);
        if (!is_dir($runDir)) {
            mkdir($runDir, 0755, true);
        }
        
        $stateDir = dirname(STATE_FILE);
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }
        
        $logDir = dirname(DAEMON_LOG_FILE);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
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
    case '--run-once':
        // 检查是否已有守护进程在运行
        $status = checkStatus();
        if ($status['running']) {
            echo json_encode(['success' => false, 'message' => '守护进程已在运行中 (PID: ' . $status['pid'] . ')']);
            exit(0);
        }
        
        // 守护进程模式
        echo json_encode(['success' => true, 'message' => '守护进程已启动']);
        flush();
        
        // 保存 PID 并开始监控
        file_put_contents(PID_FILE, posix_getpid());
        saveState(['running' => true, 'started_at' => date('Y-m-d H:i:s')]);
        
        // 开始监控日志
        monitorLog();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作: ' . $action]);
        break;
}
