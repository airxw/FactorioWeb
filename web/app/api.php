<?php
/**
 * ============================================================================
 * Factorio Server Pro - API 主入口文件
 * 
 * ⚠️⚠️⚠️ 重要安全警告 ⚠️⚠️⚠️
 * 
 * 【严禁使用非 web 用户权限启动 Factorio 服务端】
 * 
 * 本系统设计为通过 Web 界面管理 Factorio 服务端。
 * 所有服务端启动、停止、重启操作必须通过以下方式进行：
 * 
 * 1. Web 管理界面（推荐）
 * 2. 以 www/web 用户身份运行的相关脚本
 * 
 * 禁止行为：
 * - 禁止使用 root 用户直接启动 factorio 服务端
 * - 禁止使用其他非 web 用户启动 factorio 服务端
 * - 禁止直接在命令行运行 factorio 可执行文件
 * 
 * 违反此规定可能导致：
 * - 文件权限混乱
 * - Web 界面无法正常管理服务端
 * - 存档文件损坏或无法访问
 * - 安全风险
 * 
 * 正确启动方式：通过 Web 界面 -> 服务端管理 -> 启动服务端
 * ============================================================================
 * 
 * 功能说明:
 * - 处理所有 API 请求
 * - 服务器控制
 * - 文件管理
 * - 模组管理
 * - 玩家管理
 *
 * @package FactorioServerPro
 * @version 2.0
 * @author Factorio Server Pro Team
 */

// 启用输出缓冲，确保只返回纯净的JSON响应
ob_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 900);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/factorioRcon.php';
require_once __DIR__ . '/secureConfig.php';
require_once __DIR__ . '/services/StateService.php';
require_once __DIR__ . '/services/MonitorService.php';
require_once __DIR__ . '/services/RconService.php';
require_once __DIR__ . '/services/VoteService.php';
require_once __DIR__ . '/services/ItemService.php';
require_once __DIR__ . '/services/PlayerService.php';
require_once __DIR__ . '/services/UserService.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/services/ShopService.php';
require_once __DIR__ . '/controllers/ShopController.php';
require_once __DIR__ . '/controllers/ItemController.php';
require_once __DIR__ . '/services/VipService.php';
require_once __DIR__ . '/services/CartService.php';
require_once __DIR__ . '/controllers/CartController.php';
require_once __DIR__ . '/services/ChatService.php';

$rconConfigs = SecureConfig::loadRconConfig();

function getServerConfig($serverId = 'default')
{
    global $rconConfigs;
    
    if (isset($rconConfigs[$serverId])) {
        return $rconConfigs[$serverId];
    }
    
    if (isset($rconConfigs['default'])) {
        return $rconConfigs['default'];
    }
    
    $firstKey = array_key_first($rconConfigs);
    return $rconConfigs[$firstKey] ?? [
        'rcon_enabled' => true,
        'rcon_port' => 27015,
        'rcon_password' => SecureConfig::generateRconPassword(),
        'rcon_host' => '127.0.0.1',
        'screen_name' => 'factorio_server',
        'description' => '默认服务端',
    ];
}

function getServerList()
{
    return [];
}

function getRconConnection($serverId = 'default')
{
    $config = getServerConfig($serverId);
    
    if (!($config['rcon_enabled'] ?? true)) {
        return null;
    }
    
    try {
        $rcon = new FactorioRCON(
            $config['rcon_host'] ?? '127.0.0.1',
            $config['rcon_port'] ?? 27015,
            $config['rcon_password'] ?? ''
        );
        $rcon->connect();
        return $rcon;
    } catch (Exception $e) {
        return null;
    }
}

function sendRconCommand($command, $serverId = 'default')
{
    static $poolClient = null;
    static $poolAvailable = null;
    static $rconConnections = [];
    
    if ($poolAvailable === null) {
        require_once __DIR__ . '/rconPoolClient.php';
        $poolClient = new RconPoolClient();
        $poolAvailable = $poolClient->ping()['success'] ?? false;
    }
    
    if ($poolAvailable) {
        $result = $poolClient->execute($command);
        if ($result['success']) {
            return $result['result'] ?? '';
        }
        return null;
    }
    
    $config = getServerConfig($serverId);
    
    if (!($config['rcon_enabled'] ?? true)) {
        return null;
    }
    
    $connectionKey = $serverId . ':' . ($config['rcon_host'] ?? '127.0.0.1') . ':' . ($config['rcon_port'] ?? 27015);
    
    if (isset($rconConnections[$connectionKey])) {
        $rcon = $rconConnections[$connectionKey];
        if ($rcon->isConnected()) {
            try {
                return $rcon->sendCommand($command);
            } catch (Exception $e) {
                $rcon->disconnect();
                unset($rconConnections[$connectionKey]);
            }
        } else {
            unset($rconConnections[$connectionKey]);
        }
    }
    
    try {
        $rcon = new FactorioRCON(
            $config['rcon_host'] ?? '127.0.0.1',
            $config['rcon_port'] ?? 27015,
            $config['rcon_password'] ?? ''
        );
        $rcon->connect();
        $rconConnections[$connectionKey] = $rcon;
        return $rcon->sendCommand($command);
    } catch (Exception $e) {
        return null;
    }
}

function getScreenName($serverId = 'default')
{
    $config = getServerConfig($serverId);
    return $config['screen_name'] ?? 'factorio_server';
}

$publicActions = ['login', 'check_auth', 'generate_hash', 'update_user', 'update_check', 'log_tail', 'log_history', 'get_copy_progress', 'ip_info', 'server_list', 'system_stats', 'files', 'get_versions', 'online_players', 'phpinfo', 'get_user_info', 'user_list', 'auto_responder_status', 'auto_responder_start', 'auto_responder_stop', 'auto_responder_run_once', 'rcon_status', 'rcon_test', 'vip_info', 'shop_items', 'my_orders', 'shop_categories', 'daily_order_count', 'get_items', 'search_items', 'item_categories', 'items_with_status', 'set_item_status', 'batch_item_status', 'sync_items', 'register', 'user_info', 'pending_orders', 'cart_get', 'cart_add', 'cart_update', 'cart_remove', 'cart_clear', 'generate_binding_code', 'check_binding_status', 'validate_order'];
$action = $_REQUEST['action'] ?? '';

if (!in_array($action, $publicActions)) {
    requireLogin();
}

$baseDir     = dirname(__DIR__, 2);
$versionsDir = "$baseDir/versions";
$serverRoot  = "$baseDir/server";
$configDir   = "$baseDir/web/config/serverConfigs";
$itemsFile   = "$baseDir/web/config/game/items.json";
$systemConfigDir = "$baseDir/web/config/system";
$stateConfigDir = "$baseDir/web/config/state";
$sharedTemplatesDir = "$baseDir/server/templates";
$modDir      = "$baseDir/mods";
$logFile     = "$baseDir/factorio-current.log";
$logDir      = "$baseDir/logs";

// Get version from request or use first available version
$requestedVersion = $_REQUEST['version'] ?? '';
$availableVersions = [];
foreach (glob("$versionsDir/*", GLOB_ONLYDIR) as $d) {
    $version = basename($d);
    $possibleBinPaths = [
        "$d/factorio/bin/x64/factorio",
        "$d/bin/x64/factorio"
    ];
    foreach ($possibleBinPaths as $binPath) {
        if (file_exists($binPath)) {
            $availableVersions[] = $version;
            break;
        }
    }
}
usort($availableVersions, fn($a, $b) => version_compare($b, $a));

if (empty($availableVersions)) {
    // 对于登录等公共操作，允许继续执行
    if (in_array($action, $publicActions)) {
        // 继续执行，不退出
    } else {
        echo json_encode(['error' => 'No valid Factorio versions found']);
        exit;
    }
}

// 只有当有可用版本时才设置版本相关变量
if (!empty($availableVersions)) {
    $currentVersion = $requestedVersion && in_array($requestedVersion, $availableVersions) ? $requestedVersion : $availableVersions[0];
    $versionDir    = "$versionsDir/$currentVersion";
    $binPath       = file_exists("$versionDir/factorio/bin/x64/factorio") ? "$versionDir/factorio/bin/x64/factorio" : "$versionDir/bin/x64/factorio";
    $saveDir       = "$versionDir/saves";
    $stateFile     = "$saveDir/.state.json";
} else {
    // 当没有可用版本时，设置默认值
    $currentVersion = "";
    $versionDir    = "";
    $binPath       = "";
    $saveDir       = "";
    $stateFile     = "";
}

// 只创建非空的目录
foreach ([$configDir, $sharedTemplatesDir, $modDir, $versionsDir, $logDir] as $dir) {
    if (!empty($dir) && !is_dir($dir)) mkdir($dir, 0755, true);
}

// 只有当有可用版本时才创建 saveDir
if (!empty($saveDir) && !is_dir($saveDir)) mkdir($saveDir, 0755, true);

function getState() {
    global $stateFile;
    if (!empty($stateFile) && file_exists($stateFile)) {
        $data = json_decode(file_get_contents($stateFile), true);
        return $data ?: [];
    }
    return [];
}

function setState($key, $value) {
    global $stateFile;
    if (!empty($stateFile)) {
        $state = getState();
        $state[$key] = $value;
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function getCurrentSave() {
    $state = getState();
    return $state['current_save'] ?? 'current.zip';
}

function setCurrentSaveName($filename) {
    setState('current_save', $filename);
}

function copyWithProgress($src, $dst, &$progress, $progressFile = null) {
    $bufferSize = 8192; // 8KB buffer
    $totalSize = filesize($src);
    $copied = 0;
    $lastUpdate = 0;
    
    $srcHandle = fopen($src, 'rb');
    $dstHandle = fopen($dst, 'wb');
    
    if (!$srcHandle || !$dstHandle) {
        return false;
    }
    
    // 读取进度文件中的元数据
    $progressData = null;
    if ($progressFile && file_exists($progressFile)) {
        $progressData = json_decode(file_get_contents($progressFile), true);
    }
    
    while (!feof($srcHandle)) {
        $chunk = fread($srcHandle, $bufferSize);
        $bytesWritten = fwrite($dstHandle, $chunk);
        
        if ($bytesWritten === false) {
            fclose($srcHandle);
            fclose($dstHandle);
            return false;
        }
        
        $copied += $bytesWritten;
        $progress = round(($copied / $totalSize) * 100, 2);
        
        // 每更新1%或完成时更新进度文件（减少IO操作）
        if ($progressFile && ($progress - $lastUpdate >= 1 || $progress >= 100)) {
            if ($progressData) {
                $progressData['progress'] = $progress;
                file_put_contents($progressFile, json_encode($progressData));
            } else {
                file_put_contents($progressFile, json_encode([
                    'status' => 'copying',
                    'progress' => $progress
                ]));
            }
            $lastUpdate = $progress;
        }
    }
    
    fclose($srcHandle);
    fclose($dstHandle);
    $progress = 100;
    
    return true;
}

switch ($action) {
        case 'login':               handleLogin(); break;
        case 'logout':              handleLogout(); break;
        case 'check_auth':          handleCheckAuth(); break;
        case 'generate_hash':       handleGenerateHash(); break;
        case 'update_user':         handleUpdateUser(); break;
        case 'log_history':         handleLogHistory(); break;
        case 'log_tail':            handleLogTail(); break;
        case 'log_files':           handleLogFiles(); break;
        case 'download_log':        handleDownloadLog(); break;
        case 'delete_log':          handleDeleteLog(); break;
        case 'start':               handleStart(); break;
        case 'stop':                handleStop(); break;
        case 'console':             handleConsole(); break;
        case 'save_game':           handleSaveGame(); break;
        case 'files':               handleListFiles(); break;
        case 'upload':              handleUpload(); break;
        case 'delete_file':         handleDeleteFile(); break;
        case 'download':            handleDownload(); break;
        case 'set_current_save':    handleSetCurrentSave(); break;
        case 'get_copy_progress':   handleGetCopyProgress(); break;
        case 'mod_list':            handleModList(); break;
        case 'mod_toggle':          handleModToggle(); break;
        case 'mod_upload':          handleModUpload(); break;
        case 'mod_delete':          handleModDelete(); break;
        case 'mod_portal_search':   handleModPortalSearch(); break;
        case 'mod_portal_install':  handleModPortalInstall(); break;
        case 'player_lists':        handlePlayerLists(); break;
        case 'known_players':       handleKnownPlayers(); break;
        case 'update_check':        handleUpdateCheck(); break;
        case 'update_install':      handleUpdateInstall(); break;
        case 'get_versions':        handleGetVersions(); break;
        case 'generate_map':        handleGenerateMap(); break;
        case 'upload_map':          handleUploadMap(); break;
        case 'list_templates':      handleListTemplates(); break;
        case 'apply_template':      handleApplyTemplate(); break;
        case 'ip_info':             handleIpInfo(); break;
        case 'save_server_response': handleSaveServerResponse(); break;
        case 'remove_server_response': handleRemoveServerResponse(); break;
        case 'get_server_responses': handleGetServerResponses(); break;
        case 'rcon_status':         handleRconStatus(); break;
        case 'rcon_test':           handleRconTest(); break;
        case 'rcon_pool_status':    handleRconPoolStatus(); break;
        case 'rcon_pool_start':     handleRconPoolStart(); break;
        case 'rcon_pool_stop':      handleRconPoolStop(); break;
        case 'server_list':         handleServerList(); break;
        case 'save_server_config': handleSaveServerConfig(); break;
        case 'delete_server_config': handleDeleteServerConfig(); break;
        case 'system_stats':       handleSystemStats(); break;
        case 'online_players':     handleOnlinePlayers(); break;
        case 'phpinfo':           handlePhpInfo(); break;
        case 'change_password':   handleChangePassword(); break;
        case 'get_user_info':     handleGetUserInfo(); break;
        case 'user_list':         handleUserList(); break;
        case 'add_user':          handleAddUser(); break;
        case 'delete_user':       handleDeleteUser(); break;
        case 'update_user_info':  handleUpdateUser(); break;
        case 'reset_password':    handleResetPassword(); break;
        case 'security_check':    handleSecurityCheck(); break;
        case 'generate_binding_code': handleGenerateBindingCode(); break;
        case 'check_binding_status': handleCheckBindingStatus(); break;
        case 'security_fix':      handleSecurityFix(); break;
        case 'generate_rcon_password': handleGenerateRconPassword(); break;
        case 'auto_responder_status': handleAutoResponderStatus(); break;
        case 'auto_responder_start': handleAutoResponderStart(); break;
        case 'auto_responder_stop': handleAutoResponderStop(); break;
        case 'auto_responder_run_once': handleAutoResponderRunOnce(); break;
        case 'vip_info':
            require_once __DIR__ . '/controllers/VipController.php';
            (new \App\Controllers\VipController())->handleVipInfo();
            break;
        case 'set_vip':
            require_once __DIR__ . '/controllers/VipController.php';
            (new \App\Controllers\VipController())->handleSetVip();
            break;
        case 'set_vip_expiry':
            require_once __DIR__ . '/controllers/VipController.php';
            (new \App\Controllers\VipController())->handleSetVipExpiry();
            break;
        case 'vip_list':
            require_once __DIR__ . '/controllers/VipController.php';
            (new \App\Controllers\VipController())->handleVipList();
            break;
        case 'get_vip_config':
            require_once __DIR__ . '/controllers/VipController.php';
            (new \App\Controllers\VipController())->handleGetVipConfig();
            break;
        case 'update_vip_config':
            require_once __DIR__ . '/controllers/VipController.php';
            (new \App\Controllers\VipController())->handleUpdateVipConfig();
            break;
        case 'shop_items':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleShopItems();
            break;
        case 'shop_add_item':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleAddShopItem();
            break;
        case 'shop_update_item':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleUpdateShopItem();
            break;
        case 'get_items':
            require_once __DIR__ . '/controllers/ItemController.php';
            \App\Controllers\ItemController::handleGetItems();
            break;
        case 'search_items':
            require_once __DIR__ . '/controllers/ItemController.php';
            \App\Controllers\ItemController::handleSearchItems();
            break;
        case 'item_categories':
            require_once __DIR__ . '/controllers/ItemController.php';
            \App\Controllers\ItemController::handleGetCategories();
            break;
        case 'items_with_status':
            require_once __DIR__ . '/controllers/ItemController.php';
            \App\Controllers\ItemController::handleGetItemsWithStatus();
            break;
        case 'set_item_status':
            require_once __DIR__ . '/controllers/ItemController.php';
            \App\Controllers\ItemController::handleSetItemStatus();
            break;
        case 'batch_item_status':
            require_once __DIR__ . '/controllers/ItemController.php';
            \App\Controllers\ItemController::handleBatchItemStatus();
            break;
        case 'sync_items':
            require_once __DIR__ . '/controllers/ItemController.php';
            \App\Controllers\ItemController::handleSyncItems();
            break;
        case 'create_order':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleCreateOrder();
            break;
        case 'batch_create_orders':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleBatchCreateOrders();
            break;
        case 'my_orders':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleMyOrders();
            break;
        case 'deliver_order':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleDeliverOrder();
            break;
        case 'cancel_order':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleCancelOrder();
            break;
        case 'shop_categories':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleGetCategories();
            break;
        case 'daily_order_count':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleDailyOrderCount();
            break;
        case 'pending_orders':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handlePendingOrders();
            break;
        case 'validate_order':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleValidateOrder();
            break;
        case 'deliver_by_number':
            require_once __DIR__ . '/controllers/ShopController.php';
            \App\Controllers\ShopController::handleDeliverByNumber();
            break;
        case 'cart_get':
            require_once __DIR__ . '/controllers/CartController.php';
            \App\Controllers\CartController::handleGetCart();
            break;
        case 'cart_add':
            require_once __DIR__ . '/controllers/CartController.php';
            \App\Controllers\CartController::handleAddToCart();
            break;
        case 'cart_update':
            require_once __DIR__ . '/controllers/CartController.php';
            \App\Controllers\CartController::handleUpdateCart();
            break;
        case 'cart_remove':
            require_once __DIR__ . '/controllers/CartController.php';
            \App\Controllers\CartController::handleRemoveFromCart();
            break;
        case 'cart_clear':
            require_once __DIR__ . '/controllers/CartController.php';
            \App\Controllers\CartController::handleClearCart();
            break;
        case 'register':
            require_once __DIR__ . '/controllers/UserController.php';
            \App\Controllers\UserController::handleRegister();
            break;
        case 'update_password':
            require_once __DIR__ . '/controllers/UserController.php';
            \App\Controllers\UserController::handleUpdatePassword();
            break;
        case 'user_info':
            require_once __DIR__ . '/controllers/UserController.php';
            \App\Controllers\UserController::handleUserInfo();
            break;
        case 'config_list':         handleConfigList(); break;
        case 'config_get':          handleConfigGet(); break;
        case 'config_save':         handleConfigSave(); break;
        case 'config_delete':       handleConfigDelete(); break;
        case 'get_secrets':         handleGetSecrets(); break;
        case 'save_player_events':  handleSavePlayerEvents(); break;
        case 'get_player_events':   handleGetPlayerEvents(); break;
        case 'get_chat_settings':   handleGetChatSettings(); break;
        case 'add_trigger_response': handleAddTriggerResponse(); break;
        case 'delete_trigger_response': handleDeleteTriggerResponse(); break;
        default:
            echo json_encode(['error' => 'Unknown action']);
            exit;
    }

function handleLogin() {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        echo json_encode(['error' => '请填写用户名和密码']);
        exit;
    }
    $result = loginUser($username, $password);
    echo json_encode($result);
}

function handleLogout() {
    $result = logoutUser();
    echo json_encode($result);
}

function handleCheckAuth() {
    $user = getCurrentUser();
    if ($user) {
        echo json_encode(['authenticated' => true, 'user' => $user]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
}

function handleGenerateHash() {
    $password = $_POST['password'] ?? '';
    if (empty($password)) {
        echo json_encode(['error' => '密码不能为空']);
        exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    echo json_encode(['hash' => $hash]);
}

function handleStart() {
    $serverId = $_POST['server'] ?? 'default';
    $serverConfig = getServerConfig($serverId);
    $screenName = $serverConfig['screen_name'] ?? 'factorio_server';
    
    if (isServerRunning($serverId)) {
        echo json_encode(['message' => '服务器已在运行，无需重复启动']);
        exit;
    }
    $map   = basename($_POST['map'] ?? '');
    $cfg   = basename($_POST['config'] ?? '');
    $ver   = $_POST['version'] ?? '';
    
    if (!$map || !$cfg || !$ver) {
        echo json_encode(['error' => '请完整选择版本、地图和配置']);
        exit;
    }
    
    $versionDir = "{$GLOBALS['versionsDir']}/$ver";
    $possibleBinPaths = [
        "$versionDir/factorio/bin/x64/factorio",
        "$versionDir/bin/x64/factorio"
    ];
    $bin = null;
    foreach ($possibleBinPaths as $path) {
        if (file_exists($path)) {
            $bin = $path;
            break;
        }
    }
    
    if (!$bin || !file_exists($bin)) {
        echo json_encode(['error' => '服务端版本不存在']);
        exit;
    }
    
    // 根据请求的版本计算存档目录
    $saveDir = "$versionDir/saves";
    $selectedSave = "$saveDir/$map";
    $currentSave = "$saveDir/current.zip";
    
    $actualFiles = glob("$saveDir/*.zip");
    $actualFileNames = array_map('basename', $actualFiles);
    
    if (!file_exists($selectedSave)) {
        echo json_encode([
            'error' => '选择的存档文件不存在：' . $map,
            'selected_file' => $selectedSave,
            'actual_files' => $actualFileNames,
            'save_dir' => $saveDir
        ]);
        exit;
    }
    
    if ($map !== 'current.zip') {
        if (!copy($selectedSave, $currentSave)) {
            echo json_encode(['error' => '无法复制存档文件，请检查权限']);
            exit;
        }
    }
    
    // 从配置文件中读取 RCON 设置和白名单设置
    $configFilePath = "{$GLOBALS['configDir']}/$cfg";
    $serverSettings = [];
    if (file_exists($configFilePath)) {
        $serverSettings = json_decode(file_get_contents($configFilePath), true) ?: [];
    }
    
    $rconSettings = $serverSettings['rcon'] ?? [];
    $rconEnabled = $rconSettings['enabled'] ?? true;
    $rconPort = (int)($rconSettings['port'] ?? 27015);
    $rconPassword = $rconSettings['password'] ?? '';
    
    // 如果密码为空，生成随机密码
    if (empty($rconPassword)) {
        $rconPassword = bin2hex(random_bytes(16));
    }
    
    // 构建 RCON 参数
    $rconArgs = '';
    if ($rconEnabled) {
        $rconArgs = sprintf('--rcon-port %d --rcon-password %s', $rconPort, escapeshellarg($rconPassword));
    }
    
    // 白名单设置
    $useWhitelist = $serverSettings['use_server_whitelist'] ?? false;
    $whitelistArg = $useWhitelist ? '--use-server-whitelist' : '';
    
    // 日志文件名与配置名绑定
    $configName = preg_replace('/\.json$/i', '', $cfg);
    $logFile = $GLOBALS['baseDir'] . "/logs/factorio-{$configName}.log";
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // 如果日志文件已存在，备份旧日志（避免日志过于庞大）
    if (file_exists($logFile) && filesize($logFile) > 0) {
        $backupTime = date('Y-m-d_His');
        $backupFile = $GLOBALS['baseDir'] . "/logs/factorio-{$configName}-{$backupTime}.log";
        @rename($logFile, $backupFile);
        
        // 清理超过7天的旧备份日志
        $oldBackups = glob($GLOBALS['baseDir'] . "/logs/factorio-{$configName}-*.log");
        if ($oldBackups) {
            $expireTime = time() - (7 * 24 * 60 * 60);
            foreach ($oldBackups as $oldBackup) {
                if (filemtime($oldBackup) < $expireTime) {
                    @unlink($oldBackup);
                }
            }
        }
    }
    
    // 保存当前使用的配置文件名（不保存密码，密码从配置文件读取）
    $runtimeConfig = [
        'server_id' => $serverId,
        'config_file' => $cfg,
        'screen_name' => $screenName,
        'start_time' => time()
    ];
    file_put_contents($GLOBALS['logDir'] . '/runtimeConfig.json', json_encode($runtimeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // 先启动自动响应守护进程
    startAutoResponder();

    // 启动 WebSocket 日志服务
    startWebSocket();
    
    $cmd = sprintf(
        "screen -dmS %s bash -c \"cd %s && %s --start-server %s --server-settings %s --mod-directory %s %s %s >> %s 2>&1\"",
        escapeshellarg($screenName),
        escapeshellarg($GLOBALS['serverRoot']),
        escapeshellarg($bin),
        escapeshellarg($currentSave),
        escapeshellarg($configFilePath),
        escapeshellarg($GLOBALS['modDir']),
        $whitelistArg,
        $rconArgs,
        escapeshellarg($logFile)
    );
    shell_exec($cmd);
    
    // 更新 factorio-current.log 符号链接指向当前配置的日志文件
    $currentLogLink = $GLOBALS['baseDir'] . '/factorio-current.log';
    if (file_exists($currentLogLink) || is_link($currentLogLink)) {
        @unlink($currentLogLink);
    }
    @symlink($logFile, $currentLogLink);
    
    // 服务器启动后立即设置缓存状态为运行中，避免状态闪烁
    global $serverRunningCache;
    $serverRunningCache[$serverId] = [
        'status' => true,
        'timestamp' => time()
    ];

    echo json_encode([
        'message' => '服务器启动成功',
        'server_id' => $serverId,
        'screen_name' => $screenName,
        'rcon_port' => $rconEnabled ? $rconPort : null,
        'rcon_enabled' => $rconEnabled,
        'log_file' => basename($logFile),
        'running' => true
    ]);
}

function handleSetCurrentSave() {
    $file = basename($_POST['filename'] ?? '');
    $ver = $_POST['version'] ?? '';
    
    if (!$file || !str_ends_with($file, '.zip') || !$ver) {
        echo json_encode(['error' => '无效文件名或版本']);
        exit;
    }
    
    $saveDir = "{$GLOBALS['versionsDir']}/$ver/saves";
    if (!is_dir($saveDir)) {
        echo json_encode(['error' => '存档目录不存在']);
        exit;
    }
    
    $src = "$saveDir/$file";
    
    if (!file_exists($src)) {
        echo json_encode(['error' => '存档文件不存在']);
        exit;
    }
    
    $serverId = 'default';
    $wasRunning = isServerRunning($serverId);
    
    $runtimeConfigFile = $GLOBALS['logDir'] . '/runtimeConfig.json';
    $currentConfig = null;
    $currentVersion = null;
    
    if ($wasRunning && file_exists($runtimeConfigFile)) {
        $currentConfig = json_decode(file_get_contents($runtimeConfigFile), true);
        $currentVersion = $currentConfig['version'] ?? null;
    }
    
    if ($wasRunning) {
        $screenName = getScreenName($serverId);
        $result = sendRconCommand('/quit', $serverId);
        
        if ($result === null) {
            shell_exec("screen -S " . escapeshellarg($screenName) . " -X stuff \"/quit\n\"");
        }
        
        sleep(3);
        
        if (isServerRunning($serverId)) {
            shell_exec("screen -S " . escapeshellarg($screenName) . " -X quit");
            sleep(1);
        }
    }
    
    if ($currentConfig && !empty($currentConfig['config_file'])) {
        $cfg = $currentConfig['config_file'];
    } else {
        $configFiles = glob("{$GLOBALS['configDir']}/*.json");
        if (empty($configFiles)) {
            echo json_encode(['error' => '找不到配置文件']);
            exit;
        }
        $cfg = basename($configFiles[0]);
    }
    
    $versionDir = "{$GLOBALS['versionsDir']}/$ver";
    $possibleBinPaths = [
        "$versionDir/factorio/bin/x64/factorio",
        "$versionDir/bin/x64/factorio"
    ];
    $bin = null;
    foreach ($possibleBinPaths as $path) {
        if (file_exists($path)) {
            $bin = $path;
            break;
        }
    }
    
    if (!$bin || !file_exists($bin)) {
        echo json_encode(['error' => '服务端版本不存在']);
        exit;
    }
    
    $serverConfig = getServerConfig($serverId);
    $screenName = $serverConfig['screen_name'] ?? 'factorio_server';
    
    $configFilePath = "{$GLOBALS['configDir']}/$cfg";
    $serverSettings = [];
    if (file_exists($configFilePath)) {
        $serverSettings = json_decode(file_get_contents($configFilePath), true) ?: [];
    }
    
    $rconSettings = $serverSettings['rcon'] ?? [];
    $rconEnabled = $rconSettings['enabled'] ?? true;
    $rconPort = (int)($rconSettings['port'] ?? 27015);
    $rconPassword = $rconSettings['password'] ?? '';
    
    if (empty($rconPassword)) {
        $rconPassword = bin2hex(random_bytes(16));
    }
    
    $rconArgs = '';
    if ($rconEnabled) {
        $rconArgs = sprintf('--rcon-port %d --rcon-password %s', $rconPort, escapeshellarg($rconPassword));
    }
    
    // 白名单设置
    $useWhitelist = $serverSettings['use_server_whitelist'] ?? false;
    $whitelistArg = $useWhitelist ? '--use-server-whitelist' : '';
    
    $configName = preg_replace('/\.json$/i', '', $cfg);
    $logFile = $GLOBALS['baseDir'] . "/logs/factorio-{$configName}.log";
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $runtimeConfig = [
        'server_id' => $serverId,
        'config_file' => $cfg,
        'screen_name' => $screenName,
        'start_time' => time(),
        'version' => $ver,
        'save_file' => $file
    ];
    file_put_contents($GLOBALS['logDir'] . '/runtimeConfig.json', json_encode($runtimeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    $cmd = sprintf(
        "screen -dmS %s bash -c \"cd %s && %s --start-server %s --server-settings %s --mod-directory %s %s %s >> %s 2>&1\"",
        escapeshellarg($screenName),
        escapeshellarg($GLOBALS['serverRoot']),
        escapeshellarg($bin),
        escapeshellarg($src),
        escapeshellarg($configFilePath),
        escapeshellarg($GLOBALS['modDir']),
        $whitelistArg,
        $rconArgs,
        escapeshellarg($logFile)
    );
    shell_exec($cmd);
    
    global $serverRunningCache;
    $serverRunningCache[$serverId] = [
        'status' => true,
        'timestamp' => time()
    ];
    
    $fileSize = filesize($src);
    $sizeMB = round($fileSize / 1024 / 1024, 2);
    
    echo json_encode([
        'status' => 'success',
        'message' => "已切换到存档：{$file}，服务器已重启",
        'filename' => $file,
        'size' => $sizeMB,
        'version' => $ver,
        'was_running' => $wasRunning,
        'running' => true
    ]);
}

function handleGetCopyProgress() {
    $progressId = $_GET['progress_id'] ?? '';
    $ver = $_GET['version'] ?? '';
    
    if (!$progressId || !$ver) {
        echo json_encode(['error' => '缺少参数']);
        exit;
    }
    
    // 安全检查：防止路径遍历
    if (strpos($progressId, '..') !== false || strpos($progressId, '/') !== false) {
        echo json_encode(['error' => '无效的进度ID']);
        exit;
    }
    
    $saveDir = "{$GLOBALS['versionsDir']}/$ver/saves";
    $progressFile = "$saveDir/.progress_$progressId.json";
    
    if (!file_exists($progressFile)) {
        echo json_encode(['status' => 'not_found', 'error' => '进度文件不存在']);
        exit;
    }
    
    $content = file_get_contents($progressFile);
    $data = json_decode($content, true);
    
    if ($data === null) {
        echo json_encode(['status' => 'error', 'error' => '进度文件格式错误']);
        exit;
    }
    
    // 如果已完成或出错，删除进度文件
    if ($data['status'] === 'success' || $data['status'] === 'error') {
        @unlink($progressFile);
    }
    
    echo json_encode($data);
}

function handleListFiles() {
    $type = $_GET['type'] ?? 'map';
    $ver = $_GET['version'] ?? '';
    
    if ($type === 'config') {
        $dir = $GLOBALS['configDir'];
        $pattern = "*.json";
    } elseif (!empty($ver) && is_dir("{$GLOBALS['versionsDir']}/$ver/saves")) {
        $dir = "{$GLOBALS['versionsDir']}/$ver/saves";
        $pattern = "*.zip";
    } else {
        $allDirs = [];
        foreach (glob("{$GLOBALS['versionsDir']}/*", GLOB_ONLYDIR) as $vDir) {
            $sDir = "$vDir/saves";
            if (is_dir($sDir)) { $allDirs[] = $sDir; }
        }
        usort($allDirs, fn($a, $b) => version_compare(basename(dirname($b)), basename(dirname($a))));
        $dir = $allDirs;
        $pattern = "*.zip";
    }
    
    $files = [];
    $currentSave = '';
    
    if (is_array($dir)) {
        $foundFiles = [];
        $seen = [];
        foreach ($dir as $d) {
            if (!is_dir($d)) continue;
            $sf = glob("$d/$pattern");
            if ($sf === false) continue;
            foreach ($sf as $f) {
                $bn = basename($f);
                if (str_ends_with($bn, ".tmp.zip")) continue;
                if (isset($seen[$bn])) {
                    if (filemtime($f) > filemtime($seen[$bn])) { $seen[$bn] = $f; }
                } else { $seen[$bn] = $f; }
            }
        }
        $foundFiles = array_values($seen);
        foreach (glob("{$GLOBALS['versionsDir']}/*", GLOB_ONLYDIR) as $vDir) {
            $sd = "$vDir/saves";
            if (is_dir($sd) && file_exists("$sd/.state.json")) {
                $st = json_decode(file_get_contents("$sd/.state.json"), true);
                if (!empty($st['current_save'])) { $currentSave = $st['current_save']; break; }
            }
        }
    } else {
        if ($type !== 'config' && is_dir($dir)) {
            $stateFile = "$dir/.state.json";
            if (file_exists($stateFile)) {
                $state = json_decode(file_get_contents($stateFile), true);
                $currentSave = $state['current_save'] ?? '';
            }
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            echo json_encode(['files' => [], 'error' => '目录不存在，已自动创建: ' . $dir, 'current_save' => $currentSave, 'version' => $ver]);
            return;
        }
        $foundFiles = glob("$dir/$pattern");
        if ($foundFiles === false) {
            echo json_encode(['files' => [], 'error' => '读取目录失败', 'current_save' => $currentSave, 'version' => $ver]);
            return;
        }
    }
    
    foreach ($foundFiles as $f) {
        $bn = basename($f);
        if (str_ends_with($bn, '.tmp.zip')) continue;
        
        $display = $bn;
        $isCurrent = ($bn === $currentSave);
        
        if ($type === 'config') {
            $files[] = [
                'filename' => $bn,
                'display'  => $bn,
                'size'     => filesize($f),
                'time'     => filemtime($f)
            ];
            continue;
        }
        
        $isAuto = false;
        $isManual = false;

        if ($bn === 'current.zip') {
            $display = "📁 手动存档 (current.zip)";
            $isCurrent = true;
            $isManual = true;
        } elseif (preg_match('/^(.+?)_autosave(\d+)\.zip$/', $bn, $m)) {
            $display = "🔄 自动存档 #{$m[2]} ← {$m[1]}";
            $isAuto = true;
        } elseif (preg_match('/^_autosave(\d+)\.zip$/', $bn, $m)) {
            $display = "🔄 自动存档 #{$m[1]}";
            $isAuto = true;
        } elseif (preg_match('/_backup_\d+\.zip$/', $bn)) {
            $display = "💾 手动备份 " . preg_replace('/_backup_\d+\.zip$/', '', $bn);
            $isManual = true;
        }

        if ($isCurrent) {
            $display = "✅ " . $display . " (当前使用中)";
        }

        $files[] = [
            'filename' => $bn,
            'display'  => $display,
            'size'     => filesize($f),
            'time'     => filemtime($f),
            'is_current' => $isCurrent,
            'is_auto' => $isAuto,
            'is_manual' => $isManual
        ];
    }
    
    usort($files, function($a, $b) {
        if ($a['is_current'] !== $b['is_current']) {
            return $a['is_current'] ? -1 : 1;
        }
        return $b['time'] - $a['time'];
    });
    
    echo json_encode([
        'files' => $files, 
        'count' => count($files), 
        'dir' => $dir,
        'current_save' => $currentSave,
        'version' => $ver
    ]);
}

function handleListTemplates() {
    $templates = [];
    $dir = $GLOBALS['sharedTemplatesDir'];
    
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo json_encode(['templates' => [], 'error' => '模板目录不存在，已自动创建: ' . $dir]);
        return;
    }
    
    $foundTemplates = glob("$dir/*", GLOB_ONLYDIR);
    if ($foundTemplates === false) {
        echo json_encode(['templates' => [], 'error' => '读取模板目录失败']);
        return;
    }
    
    foreach ($foundTemplates as $templateDir) {
        $templateName = basename($templateDir);
        
        // Check for required files
        $hasServerSettings = file_exists("$templateDir/server-settings.json");
        $hasMapSettings = file_exists("$templateDir/map-settings.json");
        $hasModList = file_exists("$templateDir/mod-list.json");
        
        $templates[] = [
            'name' => $templateName,
            'has_server_settings' => $hasServerSettings,
            'has_map_settings' => $hasMapSettings,
            'has_mod_list' => $hasModList,
            'created_at' => filemtime($templateDir)
        ];
    }
    
    usort($templates, function($a, $b) {
        return $b['created_at'] - $a['created_at'];
    });
    
    echo json_encode(['templates' => $templates, 'count' => count($templates), 'dir' => $dir]);
}

function handleApplyTemplate() {
    $templateName = $_POST['template'] ?? '';
    $version = $_POST['version'] ?? '';
    
    if (!$templateName || !$version) {
        echo json_encode(['error' => '请提供模板名称和版本号']);
        return;
    }
    
    $templateDir = "{$GLOBALS['sharedTemplatesDir']}/$templateName";
    $versionDir = "{$GLOBALS['versionsDir']}/$version";
    $versionConfigDir = "$versionDir/config";
    
    if (!is_dir($templateDir)) {
        echo json_encode(['error' => '模板不存在: ' . $templateName]);
        return;
    }
    
    if (!is_dir($versionDir)) {
        echo json_encode(['error' => '版本不存在: ' . $version]);
        return;
    }
    
    // Create version config directory if it doesn't exist
    if (!is_dir($versionConfigDir)) {
        mkdir($versionConfigDir, 0755, true);
    }
    
    // Copy template files to version directory
    $appliedFiles = [];
    
    // Server settings
    if (file_exists("$templateDir/server-settings.json")) {
        $destFile = "$versionConfigDir/server-settings.json";
        if (copy("$templateDir/server-settings.json", $destFile)) {
            $appliedFiles[] = 'server-settings.json';
        }
    }
    
    // Map settings
    if (file_exists("$templateDir/map-settings.json")) {
        $destFile = "$versionConfigDir/map-settings.json";
        if (copy("$templateDir/map-settings.json", $destFile)) {
            $appliedFiles[] = 'map-settings.json';
        }
    }
    
    // Mod list
    if (file_exists("$templateDir/mod-list.json")) {
        $destFile = "$versionDir/mod-list.json";
        if (copy("$templateDir/mod-list.json", $destFile)) {
            $appliedFiles[] = 'mod-list.json';
        }
    }
    
    if (empty($appliedFiles)) {
        echo json_encode(['error' => '模板中没有可应用的文件']);
        return;
    }
    
    echo json_encode([
        'message' => "成功应用模板 '$templateName' 到版本 '$version'",
        'applied_files' => $appliedFiles,
        'template' => $templateName,
        'version' => $version
    ]);
}

function handleStop() {
    $serverId = $_POST['server'] ?? 'default';
    $screenName = getScreenName($serverId);
    
    if (isServerRunning($serverId)) {
        $result = sendRconCommand('/quit', $serverId);
        
        if ($result === null) {
            shell_exec("screen -S " . escapeshellarg($screenName) . " -X stuff \"/quit\n\"");
        }
        
        sleep(2);
        
        if (isServerRunning($serverId)) {
            shell_exec("screen -S " . escapeshellarg($screenName) . " -X quit");
        }
    }
    
    SecureConfig::deletePasswordFile($serverId);
    
    $runtimeConfigFile = $GLOBALS['logDir'] . '/runtimeConfig.json';
    if (file_exists($runtimeConfigFile)) {
        unlink($runtimeConfigFile);
    }
    
    // 停止自动响应守护进程
    stopAutoResponder();

    // 停止 WebSocket 日志服务
    stopWebSocket();

    echo json_encode(['message' => '服务器正在关闭', 'server_id' => $serverId]);
}

function handleSaveGame() {
    $serverId = $_POST['server'] ?? 'default';
    
    if (!isServerRunning($serverId)) {
        echo json_encode(['error' => '服务器未运行，无法保存']);
        exit;
    }
    
    $currentSave = getCurrentSave();
    
    $result = sendRconCommand('/save', $serverId);
    
    if ($result === null) {
        $screenName = getScreenName($serverId);
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], '/save');
        shell_exec("screen -S " . escapeshellarg($screenName) . " -p 0 -X stuff \"$escaped\n\"");
    }
    
    sleep(3);
    
    $beforeFiles = [];
    foreach (glob("{$GLOBALS['saveDir']}/*.zip") as $f) {
        $beforeFiles[basename($f)] = filemtime($f);
    }
    
    sleep(1);
    
    $newSave = null;
    foreach (glob("{$GLOBALS['saveDir']}/*.zip") as $f) {
        $bn = basename($f);
        $mtime = filemtime($f);
        
        if ($bn === 'current.zip') {
            continue;
        }
        
        if (!isset($beforeFiles[$bn]) || $mtime > $beforeFiles[$bn]) {
            $newSave = $bn;
            break;
        }
    }
    
    if ($newSave && file_exists("{$GLOBALS['saveDir']}/$newSave")) {
        $timestamp = date('Ymd_His');
        $backupName = pathinfo($newSave, PATHINFO_FILENAME) . "_backup_$timestamp.zip";
        copy("{$GLOBALS['saveDir']}/$newSave", "{$GLOBALS['saveDir']}/$backupName");
        
        echo json_encode([
            'message' => "游戏保存成功！备份已创建：$backupName",
            'saved_file' => $newSave,
            'backup_file' => $backupName
        ]);
    } else {
        echo json_encode([
            'message' => '保存命令已发送，请稍候查看存档列表'
        ]);
    }
}

function handleConsole() {
    $cmd = $_POST['cmd'] ?? '';
    $serverId = $_POST['server'] ?? 'default';
    
    if ($cmd === '') {
        echo json_encode(['error' => 'Empty command']);
        exit;
    }
    
    $result = sendRconCommand($cmd, $serverId);
    
    if ($result !== null) {
        echo json_encode([
            'message' => '指令已发送',
            'response' => $result,
            'method' => 'rcon',
            'server_id' => $serverId
        ]);
    } else {
        $screenName = getScreenName($serverId);
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $cmd);
        shell_exec("screen -S " . escapeshellarg($screenName) . " -p 0 -X stuff \"$escaped\n\"");
        echo json_encode([
            'message' => '指令已发送',
            'method' => 'screen',
            'server_id' => $serverId
        ]);
    }
}

function handleUpload() {
    if (empty($_FILES['file'])) {
        echo json_encode(['error' => '无文件上传']);
        exit;
    }
    $uploaded = 0;
    foreach ($_FILES['file']['name'] as $i => $name) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $targetDir = ($ext === 'json') ? $GLOBALS['configDir'] : $GLOBALS['saveDir'];
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
        if (move_uploaded_file($_FILES['file']['tmp_name'][$i], "$targetDir/$safeName")) {
            $uploaded++;
        }
    }
    echo json_encode(['message' => "成功上传 $uploaded 个文件"]);
}

function handleDownload() {
    if (($_GET['type'] ?? '') === 'config') {
        http_response_code(403);
        exit('Forbidden');
    }
    $file = basename($_GET['filename'] ?? '');
    $path = "{$GLOBALS['saveDir']}/$file";
    if (!$file || !file_exists($path)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path);
    exit;
}

function handleDeleteFile() {
    $file = basename($_POST['filename'] ?? $_GET['filename'] ?? '');
    $type = $_GET['type'] ?? 'map';
    $dir  = ($type === 'config') ? $GLOBALS['configDir'] : $GLOBALS['saveDir'];
    $path = "$dir/$file";
    if ($file && file_exists($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['zip','json'])) {
        unlink($path);
        echo json_encode(['message' => '已删除']);
    } else {
        echo json_encode(['error' => '删除失败']);
    }
}

$serverRunningCache = [];

function isServerRunning($serverId = 'default') {
    global $serverRunningCache;
    
    $currentTime = time();
    
    if (isset($serverRunningCache[$serverId])) {
        if ($currentTime - $serverRunningCache[$serverId]['timestamp'] < 5) {
            return $serverRunningCache[$serverId]['status'];
        }
    }
    
    // 优先检查runtimeConfig.json中的screen_name
    $runtimeConfigFile = $GLOBALS['logDir'] . '/runtimeConfig.json';
    $screenName = 'factorio_server';
    
    if (file_exists($runtimeConfigFile)) {
        $runtimeConfig = json_decode(file_get_contents($runtimeConfigFile), true);
        if ($runtimeConfig && !empty($runtimeConfig['screen_name'])) {
            $screenName = $runtimeConfig['screen_name'];
        }
    }
    
    // 如果runtimeConfig中没有，再从服务器配置中获取
    if ($screenName === 'factorio_server') {
        $config = getServerConfig($serverId);
        $screenName = $config['screen_name'] ?? 'factorio_server';
    }
    
    $screenCheck = shell_exec("screen -ls 2>/dev/null | grep -E '\." . preg_quote($screenName, '/') . "\s'");
    $psCheck = shell_exec("ps aux | grep '[b]in/x64/factorio.*--start-server' 2>/dev/null");
    $status = !empty($screenCheck) || !empty(trim($psCheck));
    
    $serverRunningCache[$serverId] = [
        'status' => $status,
        'timestamp' => $currentTime
    ];
    
    return $status;
}

function handleModList() {
    $files = []; $enabled = [];
    $modListPath = "{$GLOBALS['modDir']}/mod-list.json";
    if (file_exists($modListPath)) {
        $json = json_decode(file_get_contents($modListPath), true) ?: [];
        foreach ($json['mods'] ?? [] as $m) {
            $enabled[$m['name']] = $m['enabled'];
        }
    }
    foreach (glob("{$GLOBALS['modDir']}/*.zip") as $f) {
        $fn = basename($f);
        $name = preg_replace('/^(.+?)_\d+\.\d+\.\d+\.zip$/', '$1', $fn);
        $name = $name === $fn ? pathinfo($fn, PATHINFO_FILENAME) : $name;
        $files[] = [
            'filename' => $fn,
            'name'     => $name,
            'enabled'  => $enabled[$name] ?? true,
            'size'     => round(filesize($f)/1024, 1)
        ];
    }
    foreach (['base','quality','space-age','elevated-rails'] as $dlc) {
        if (isset($enabled[$dlc])) {
            $files[] = ['filename'=>'[官方 DLC]','name'=>$dlc,'enabled'=>$enabled[$dlc],'size'=>0];
        }
    }
    echo json_encode(['mods' => $files]);
}

function handleModToggle() {
    $name = $_POST['name'] ?? '';
    $state = $_POST['enabled'] === 'true';
    $path = "{$GLOBALS['modDir']}/mod-list.json";
    $data = file_exists($path) ? json_decode(file_get_contents($path), true) : ['mods'=>[]];
    $found = false;
    foreach ($data['mods'] as &$m) {
        if ($m['name'] === $name) {
            $m['enabled'] = $state;
            $found = true;
            break;
        }
    }
    if (!$found) $data['mods'][] = ['name'=>$name, 'enabled'=>$state];
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['message'=>'OK']);
}

function handleModUpload() {
    if (empty($_FILES['file'])) {
        echo json_encode(['error'=>'无文件']);
        exit;
    }
    $ok = 0;
    foreach ($_FILES['file']['name'] as $i => $n) {
        if (strtolower(pathinfo($n, PATHINFO_EXTENSION)) === 'zip') {
            $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $n);
            if (move_uploaded_file($_FILES['file']['tmp_name'][$i], "{$GLOBALS['modDir']}/$safe")) $ok++;
        }
    }
    echo json_encode(['message'=>"上传成功 $ok 个模组"]);
}

function handleModDelete() {
    $f = basename($_GET['filename'] ?? '');
    $p = "{$GLOBALS['modDir']}/$f";
    if (file_exists($p) && strtolower(pathinfo($p, PATHINFO_EXTENSION)) === 'zip') {
        unlink($p);
        echo json_encode(['message'=>'已删除']);
    } else {
        echo json_encode(['error'=>'删除失败']);
    }
}

function handleModPortalSearch() {
    $q = trim($_GET['q'] ?? '');
    $params = ['page_size' => 20, 'order' => 'latest'];
    if ($q !== '') $params['q'] = $q;
    $url = 'https://mods.factorio.com/api/mods?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'FactorioServerPro/2.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || $result === false) {
        echo json_encode(['results' => [], 'error' => 'API 请求失败']);
        exit;
    }
    $data = json_decode($result, true);
    echo json_encode($data ?: ['results' => []]);
}

function handleModPortalInstall() {
    $name = $_POST['name'] ?? '';
    $user = $_POST['username'] ?? '';
    $token = $_POST['token'] ?? '';
    if (!$name || !$user || !$token) {
        echo json_encode(['error' => '参数缺失']);
        exit;
    }
    $ch = curl_init("https://mods.factorio.com/api/mods/$name");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($data['releases'])) {
        echo json_encode(['error' => 'Mod 不存在或无版本']);
        exit;
    }
    usort($data['releases'], fn($a, $b) => version_compare($b['version'], $a['version']));
    $latest = $data['releases'][0];
    $downloadUrl = $latest['download_url'] . "?username=$user&token=$token";
    $target = "{$GLOBALS['modDir']}/{$latest['file_name']}";
    $fp = fopen($target, 'wb');
    $ch = curl_init("https://mods.factorio.com$downloadUrl");
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($code === 200 && filesize($target) > 1000) {
        $listFile = "{$GLOBALS['modDir']}/mod-list.json";
        $list = file_exists($listFile) ? json_decode(file_get_contents($listFile), true) : ['mods'=>[]];
        $found = false;
        foreach ($list['mods'] as &$m) {
            if ($m['name'] === $name) { $m['enabled'] = true; $found = true; break; }
        }
        if (!$found) $list['mods'][] = ['name' => $name, 'enabled' => true];
        file_put_contents($listFile, json_encode($list, JSON_PRETTY_PRINT));
        echo json_encode(['message' => '安装成功']);
    } else {
        @unlink($target);
        echo json_encode(['error' => "下载失败 (HTTP $code)"]);
    }
}

function handlePlayerLists() {
    $safeReadList = function($filePath) {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return [];
        }
        
        return $data;
    };
    
    $configDir = $GLOBALS['configDir'] ?? dirname(__DIR__, 2) . '/web/config/serverConfigs';
    $playerDir = dirname(__DIR__, 2) . '/web/config/player';
    
    echo json_encode([
        'admins'     => $safeReadList("$playerDir/server-adminlist.json"),
        'bans'       => $safeReadList("$playerDir/server-banlist.json"),
        'whitelist'  => $safeReadList("$playerDir/server-whitelist.json")
    ]);
}

function handleKnownPlayers() {
    $logFile = getLogFilePath();
    
    if (!file_exists($logFile)) {
        echo json_encode(['success' => true, 'players' => []]);
        exit;
    }
    
    $content = file_get_contents($logFile);
    if ($content === false) {
        echo json_encode(['success' => true, 'players' => []]);
        exit;
    }
    
    $players = [];
    $lines = explode("\n", $content);
    
    foreach ($lines as $line) {
        if (preg_match('/\[PLAYER-JOIN\]\s*(\S+)/', $line, $m)) {
            $playerName = trim($m[1]);
            $players[$playerName] = true;
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?\s(\S+)\s+joined the game/i', $line, $m)) {
            $playerName = trim($m[2]);
            $players[$playerName] = true;
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?\[JOIN\]\s*(\S+)/i', $line, $m)) {
            $playerName = trim($m[2]);
            $players[$playerName] = true;
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?JOINING GAME.*?peer\s+(\w+)/i', $line, $m)) {
            $players[$m[2]] = true;
        }
    }
    
    $playerList = array_keys($players);
    sort($playerList);
    
    echo json_encode([
        'success' => true,
        'players' => $playerList,
        'count' => count($playerList)
    ]);
    exit;
}

function handleUpdateCheck() {
    // 调试输出
    error_log('handleUpdateCheck called');
    
    // 使用 cURL 获取版本信息
    $ch = curl_init("https://factorio.com/api/latest-releases");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $json = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    error_log('cURL result: HTTP ' . $httpCode . ', Error: ' . $curlError);
    curl_close($ch);
    
    $data = json_decode($json, true) ?: [];
    
    $currentVersion = 'unknown';
    $allVersions = [];
    foreach (glob("{$GLOBALS['versionsDir']}/*", GLOB_ONLYDIR) as $d) {
        $v = basename($d);
        $paths = ["$d/factorio/bin/x64/factorio", "$d/bin/x64/factorio"];
        foreach ($paths as $bp) {
            if (file_exists($bp)) {
                $allVersions[$v] = $bp;
                break;
            }
        }
    }
    if (!empty($allVersions)) {
        uksort($allVersions, 'version_compare');
        $latestBin = end($allVersions);
        $output = shell_exec($latestBin . ' --version 2>&1');
        if ($output && preg_match('/Version:?\s*([\d.]+)/i', $output, $matches)) {
            $currentVersion = $matches[1];
        }
    }
    
    $response = [
        'stable'       => $data['stable']['headless'] ?? 'unknown',
        'experimental' => $data['experimental']['headless'] ?? 'unknown',
        'current'      => $currentVersion,
        'http_code'    => $httpCode,
        'api_response' => $json ? 'success' : 'failed'
    ];
    
    echo json_encode($response);
}

function handleGetVersions() {
    $list = [];
    foreach (glob("{$GLOBALS['versionsDir']}/*", GLOB_ONLYDIR) as $d) {
        $version = basename($d);
        $possiblePaths = [
            "$d/factorio/bin/x64/factorio",
            "$d/bin/x64/factorio"
        ];
        foreach ($possiblePaths as $binPath) {
            if (file_exists($binPath)) {
                $list[] = ['id'=>$version, 'name'=>'v'.$version];
                break;
            }
        }
    }
    usort($list, fn($a,$b)=>version_compare($b['id'],$a['id']));
    echo json_encode(['versions'=>$list]);
}

function handleGenerateMap() {
    require_once __DIR__ . '/controllers/ServerController.php';
    $controller = new \App\Controllers\ServerController();
    $params = [
        'map_name' => $_POST['map_name'] ?? '',
        'seed' => $_POST['seed'] ?? null,
        'version' => $_POST['version'] ?? '',
        'map_width' => (int)($_POST['map_width'] ?? 0),
        'map_height' => (int)($_POST['map_height'] ?? 0),
    ];
    $controller->generateMap($params);
}

function handleUploadMap() {
    if (empty($_FILES['map_file'])) {
        echo json_encode(['error' => '无文件上传']);
        exit;
    }
    $file = $_FILES['map_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        echo json_encode(['error' => '只能上传 .zip 格式的地图存档文件']);
        exit;
    }
    $rawName = $file['name'];
    $safeName = str_replace(array('/', '\\'), '', $rawName);
    $safeName = str_replace('..', '', $safeName);
    $saveDir = $GLOBALS['saveDir'];
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], "$saveDir/$safeName")) {
        echo json_encode(['message' => "地图上传成功: $safeName"]);
    } else {
        echo json_encode(['error' => '上传失败，请检查目录权限']);
        exit;
    }
}

function handleUpdateInstall() {
    $v = $_POST['version'] ?? '';
    if (!$v || is_dir("{$GLOBALS['versionsDir']}/$v")) {
        echo json_encode(['error'=>'版本无效或已存在']);
        exit;
    }
    $tmp = "{$GLOBALS['versionsDir']}/temp_$v.tar.xz";
    $url = "https://www.factorio.com/get-download/$v/headless/linux64";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => fopen($tmp, 'wb'),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600
    ]);
    $ok = curl_exec($ch);
    curl_close($ch);
    if (!file_exists($tmp) || filesize($tmp) < 1000000) {
        @unlink($tmp);
        echo json_encode(['error'=>'下载失败']);
        exit;
    }
    mkdir("{$GLOBALS['versionsDir']}/$v", 0755, true);
    shell_exec("tar -xf " . escapeshellarg($tmp) . " -C " . escapeshellarg("{$GLOBALS['versionsDir']}/$v") . " --strip-components=1");
    unlink($tmp);
    echo json_encode(['message'=>'安装完成']);
}

function getLogFilePath($configName = null) {
    $runtimeConfigFile = $GLOBALS['baseDir'] . '/logs/runtimeConfig.json';
    if (file_exists($runtimeConfigFile)) {
        $runtimeConfig = json_decode(file_get_contents($runtimeConfigFile), true);
        if (!empty($runtimeConfig['config_file'])) {
            $configName = preg_replace('/\.json$/i', '', $runtimeConfig['config_file']);
        }
    }

    if ($configName === null) {
        $configName = $_GET['config'] ?? $_POST['config'] ?? '';
    }

    if (!empty($configName) && $configName !== 'default') {
        $configName = preg_replace('/\.json$/i', '', $configName);
        $logFile = $GLOBALS['baseDir'] . "/logs/factorio-{$configName}.log";
        return $logFile;
    }

    $logsDir = $GLOBALS['baseDir'] . '/logs';
    if (is_dir($logsDir)) {
        $logFiles = glob("$logsDir/factorio-*.log");
        if (!empty($logFiles)) {
            usort($logFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            return $logFiles[0];
        }
    }

    return $GLOBALS['logFile'];
}

function handleLogTail() {
    $lines   = max(100, min(10000, (int)($_GET['lines'] ?? 1000)));
    $logFile = getLogFilePath($_GET['config'] ?? null);
    if (!file_exists($logFile)) {
        echo "日志文件不存在: " . $logFile;
        exit;
    }
    if (filesize($logFile) === 0) {
        echo "日志文件为空";
        exit;
    }
    $content = file_get_contents($logFile);
    if ($content === false) {
        echo "读取文件失败";
        exit;
    }
    
    $linesArray = explode("\n", $content);
    $linesArray = array_filter($linesArray, function($line) {
        return trim($line) !== '';
    });
    
    $tailLines = array_slice($linesArray, -$lines);
    $buffer = implode("\n", $tailLines);
    
    echo $buffer ?: "日志为空";
    exit;
}

function handleIpInfo() {
    $ip = $_GET['ip'] ?? '';
    if (empty($ip)) {
        echo json_encode(['error' => '请提供IP地址']);
        exit;
    }
    
    // 验证IP地址格式
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        echo json_encode(['error' => '无效的IP地址']);
        exit;
    }
    
    // 使用免费的IP地址查询API
    // 使用ip-api.com（免费，每分钟45次请求限制）
    $url = "http://ip-api.com/json/{$ip}?lang=zh-CN";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        echo json_encode(['error' => '查询失败，请稍后重试']);
        exit;
    }
    
    $data = json_decode($response, true);
    
    if ($data['status'] === 'fail') {
        echo json_encode(['error' => $data['message'] ?? '查询失败']);
        exit;
    }
    
    // 返回简化的信息
    $result = [
        'ip' => $ip,
        'country' => $data['country'] ?? '',
        'region' => $data['regionName'] ?? '',
        'city' => $data['city'] ?? '',
        'isp' => $data['isp'] ?? '',
        'org' => $data['org'] ?? '',
        'as' => $data['as'] ?? '',
        'timezone' => $data['timezone'] ?? '',
        'lat' => $data['lat'] ?? '',
        'lon' => $data['lon'] ?? ''
    ];
    
    echo json_encode($result);
    exit;
}

function handleSaveServerResponse() {
    $type = $_POST['type'] ?? '';
    $keyword = $_POST['keyword'] ?? '';
    $value = $_POST['value'] ?? '';

    if (empty($type)) {
        echo json_encode(['success' => false, 'message' => '响应类型不能为空']);
        exit;
    }

    if (empty($keyword)) {
        echo json_encode(['success' => false, 'message' => '关键词不能为空']);
        exit;
    }

    try {
        $chatService = new \App\Services\ChatService();
        $result = $chatService->saveServerResponse($type, $keyword, $value);
        if ($result) {
            echo json_encode(['success' => true, 'message' => '服务器响应设置已保存']);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '保存失败: ' . $e->getMessage()]);
    }
    exit;
}

function handleRemoveServerResponse() {
    $keyword = $_POST['keyword'] ?? '';
    $type = $_POST['type'] ?? '';

    if (empty($keyword)) {
        echo json_encode(['success' => false, 'message' => '关键词不能为空']);
        exit;
    }

    try {
        $chatService = new \App\Services\ChatService();
        $result = $chatService->removeServerResponse($keyword, $type);
        if ($result) {
            echo json_encode(['success' => true, 'message' => '服务器响应设置已删除']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败或记录不存在']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
    }
    exit;
}

function handleGetServerResponses() {
    try {
        $chatService = new \App\Services\ChatService();
        $responses = $chatService->getServerResponses();
        echo json_encode([
            'success' => true,
            'responses' => $responses
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '获取失败: ' . $e->getMessage()]);
    }
    exit;
}

function handleRconStatus() {
    $serverId = $_GET['server'] ?? 'default';
    
    $runtimeConfigFile = $GLOBALS['logDir'] . '/runtimeConfig.json';
    $runtimeConfig = null;
    if (file_exists($runtimeConfigFile)) {
        $runtimeConfig = json_decode(file_get_contents($runtimeConfigFile), true);
    }
    
    $running = isServerRunning($serverId);
    
    $rconEnabled = true;
    $rconPort = 27015;
    $rconHost = '127.0.0.1';
    $rconPassword = '';
    $screenName = 'factorio_server';
    $configFile = '';
    
    if ($runtimeConfig && !empty($runtimeConfig['config_file'])) {
        $configFile = $runtimeConfig['config_file'];
        $configFilePath = $GLOBALS['configDir'] . '/' . $configFile;
        
        if (file_exists($configFilePath)) {
            $serverSettings = json_decode(file_get_contents($configFilePath), true);
            $rconSettings = $serverSettings['rcon'] ?? [];
            $rconEnabled = $rconSettings['enabled'] ?? true;
            $rconPort = (int)($rconSettings['port'] ?? 27015);
            $rconPassword = $rconSettings['password'] ?? '';
            $screenName = $runtimeConfig['screen_name'] ?? 'factorio_server';
        }
    }
    
    $hasPassword = !empty($rconPassword);
    $connected = false;
    $error = null;
    
    if ($running && $rconEnabled && $hasPassword) {
        require_once __DIR__ . '/rconPoolClient.php';
        $poolClient = new RconPoolClient();
        $poolAvailable = $poolClient->ping()['success'] ?? false;
        
        if ($poolAvailable) {
            $result = $poolClient->execute('/p');
            $connected = $result['success'];
            if (!$connected) {
                $error = $result['error'] ?? '连接池执行失败';
            }
        } else {
            try {
                $rcon = new \App\Services\RconService($rconHost, $rconPort, $rconPassword, 3);
                $rcon->connect();
                $rcon->disconnect();
                $connected = true;
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
    
    echo json_encode([
        'server_id' => $serverId,
        'rcon_enabled' => $rconEnabled,
        'rcon_host' => $rconHost,
        'rcon_port' => $rconPort,
        'screen_name' => $screenName,
        'config_file' => $configFile,
        'connected' => $connected,
        'running' => $running,
        'has_password' => $hasPassword,
        'error' => $error
    ]);
    exit;
}

function handleRconTest() {
    $serverId = $_POST['server'] ?? 'default';
    $command = $_POST['command'] ?? '/help';
    
    $runtimeConfigFile = $GLOBALS['logDir'] . '/runtimeConfig.json';
    $runtimeConfig = null;
    if (file_exists($runtimeConfigFile)) {
        $runtimeConfig = json_decode(file_get_contents($runtimeConfigFile), true);
    }
    
    if (!$runtimeConfig || empty($runtimeConfig['config_file'])) {
        echo json_encode(['error' => '未找到运行时配置，请确保服务器已正确启动']);
        exit;
    }
    
    $configFile = $runtimeConfig['config_file'];
    $configFilePath = $GLOBALS['configDir'] . '/' . $configFile;
    
    if (!file_exists($configFilePath)) {
        echo json_encode(['error' => '配置文件不存在: ' . $configFile]);
        exit;
    }
    
    $serverSettings = json_decode(file_get_contents($configFilePath), true);
    $rconSettings = $serverSettings['rcon'] ?? [];
    
    $rconEnabled = $rconSettings['enabled'] ?? true;
    $rconPort = (int)($rconSettings['port'] ?? 27015);
    $rconHost = '127.0.0.1';
    $rconPassword = $rconSettings['password'] ?? '';
    
    if (!$rconEnabled) {
        echo json_encode(['error' => 'RCON 未启用']);
        exit;
    }
    
    if (empty($rconPassword)) {
        echo json_encode(['error' => 'RCON 密码未配置']);
        exit;
    }
    
    try {
        $rcon = new \App\Services\RconService($rconHost, $rconPort, $rconPassword, 10);
        $rcon->connect();
        $result = $rcon->sendCommand($command);
        $rcon->disconnect();
        
        echo json_encode([
            'success' => true,
            'server_id' => $serverId,
            'command' => $command,
            'response' => $result
        ]);
    } catch (\Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

function handleRconPoolStatus() {
    require_once __DIR__ . '/RconPoolClient.php';
    
    $client = new RconPoolClient();
    $result = $client->status();
    
    echo json_encode([
        'success' => true,
        'pool' => $result
    ]);
    exit;
}

function handleRconPoolStart() {
    $daemonScript = __DIR__ . '/rconPoolDaemon.php';
    $output = shell_exec('php ' . escapeshellarg($daemonScript) . ' start 2>&1');
    
    sleep(1);
    
    require_once __DIR__ . '/RconPoolClient.php';
    $client = new RconPoolClient();
    $result = $client->ping();
    
    if ($result['success'] ?? false) {
        echo json_encode(['success' => true, 'message' => 'RCON 连接池已启动']);
    } else {
        echo json_encode(['success' => false, 'message' => '启动失败: ' . $output]);
    }
    exit;
}

function handleRconPoolStop() {
    $daemonScript = __DIR__ . '/rcon_pool_daemon.php';
    $output = shell_exec('php ' . escapeshellarg($daemonScript) . ' stop 2>&1');
    
    echo json_encode(['success' => true, 'message' => trim($output)]);
    exit;
}

function handleServerList() {
    $servers = getServerList();
    $statusList = [];
    
    foreach ($servers as $server) {
        $statusList[] = [
            'id' => $server['id'],
            'description' => $server['description'],
            'rcon_port' => $server['rcon_port'],
            'screen_name' => $server['screen_name'],
            'running' => isServerRunning($server['id']),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'servers' => $statusList
    ]);
    exit;
}

function handleSaveServerConfig()
{
    $serverId = $_POST['server_id'] ?? '';
    $configJson = $_POST['config'] ?? '';
    
    if (empty($serverId)) {
        echo json_encode(['success' => false, 'error' => '服务器 ID 不能为空']);
        exit;
    }
    
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $serverId)) {
        echo json_encode(['success' => false, 'error' => '服务器 ID 只能包含字母、数字、下划线和连字符']);
        exit;
    }
    
    $config = json_decode($configJson, true);
    if (!$config) {
        echo json_encode(['success' => false, 'error' => '配置格式无效']);
        exit;
    }
    
    $rconPassword = $config['rcon_password'] ?? '';
    if (empty($rconPassword)) {
        $rconPassword = SecureConfig::generateRconPassword();
    } else {
        $strengthResult = SecureConfig::validatePasswordStrength($rconPassword);
        if (!$strengthResult['valid']) {
            echo json_encode(['success' => false, 'error' => 'RCON 密码强度不足: ' . implode(', ', $strengthResult['errors'])]);
            exit;
        }
    }
    
    $configFile = dirname(__DIR__) . '/config/system/rcon.php';
    $configs = SecureConfig::loadRconConfig();
    
    $configs[$serverId] = [
        'rcon_enabled' => $config['rcon_enabled'] ?? true,
        'rcon_port' => intval($config['rcon_port'] ?? 27015),
        'rcon_password' => $rconPassword,
        'rcon_host' => $config['rcon_host'] ?? '127.0.0.1',
        'screen_name' => $config['screen_name'] ?? 'factorio_server',
        'description' => $config['description'] ?? $serverId,
    ];
    
    if (!SecureConfig::saveRconConfig($configs)) {
        echo json_encode(['success' => false, 'error' => '无法保存配置文件']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => '服务器配置已保存']);
    exit;
}

function handleDeleteServerConfig()
{
    $serverId = $_POST['server_id'] ?? '';
    
    if (empty($serverId)) {
        echo json_encode(['success' => false, 'error' => '服务器 ID 不能为空']);
        exit;
    }
    
    if ($serverId === 'default') {
        echo json_encode(['success' => false, 'error' => '不能删除默认服务器']);
        exit;
    }
    
    $configFile = dirname(__DIR__) . '/config/system/rcon.php';
    $configs = SecureConfig::loadRconConfig();
    
    if (!isset($configs[$serverId])) {
        echo json_encode(['success' => false, 'error' => '服务器配置不存在']);
        exit;
    }
    
    unset($configs[$serverId]);
    
    if (!SecureConfig::saveRconConfig($configs)) {
        echo json_encode(['success' => false, 'error' => '无法保存配置文件']);
        exit;
    }
    
    SecureConfig::deletePasswordFile($serverId);
    
    echo json_encode(['success' => true, 'message' => '服务器配置已删除']);
    exit;
}

function handleSystemStats()
{
    $cpuPercent = 0;
    $memoryPercent = 0;
    $memoryUsed = '--';
    $memoryTotal = '--';
    $diskPercent = 0;
    $diskUsed = '--';
    $diskTotal = '--';
    $totalPlayers = 0;
    
    $loadAvg = sys_getloadavg();
    if ($loadAvg && isset($loadAvg[0])) {
        $cpuCores = intval(shell_exec('nproc 2>/dev/null') ?: 1);
        $cpuPercent = min(100, round($loadAvg[0] / $cpuCores * 100));
    }
    
    if (file_exists('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch);
        
        if ($totalMatch && $availMatch) {
            $total = intval($totalMatch[1]);
            $available = intval($availMatch[1]);
            $used = $total - $available;
            
            $memoryTotal = round($total / 1024 / 1024, 1) . ' GB';
            $memoryUsed = round($used / 1024 / 1024, 1) . ' GB';
            $memoryPercent = round($used / $total * 100);
        }
    }
    
    $diskFree = disk_free_space('/');
    $diskTotalSpace = disk_total_space('/');
    if ($diskTotalSpace > 0) {
        $diskUsed = round(($diskTotalSpace - $diskFree) / 1024 / 1024 / 1024, 1) . ' GB';
        $diskTotal = round($diskTotalSpace / 1024 / 1024 / 1024, 1) . ' GB';
        $diskPercent = round(($diskTotalSpace - $diskFree) / $diskTotalSpace * 100);
    }
    
    echo json_encode([
        'success' => true,
        'cpu_percent' => $cpuPercent,
        'memory_percent' => $memoryPercent,
        'memory_used' => $memoryUsed,
        'memory_total' => $memoryTotal,
        'disk_percent' => $diskPercent,
        'disk_used' => $diskUsed,
        'disk_total' => $diskTotal,
        'total_players' => getOnlinePlayerCount(),
        'uptime' => trim(shell_exec('uptime -p 2>/dev/null || echo "--"')),
        'load_avg' => $loadAvg,
    ]);
    exit;
}

function getOnlinePlayerCount()
{
    $logFile = getLogFilePath();
    
    if (!file_exists($logFile)) {
        return 0;
    }
    
    $content = file_get_contents($logFile);
    if ($content === false) {
        return 0;
    }
    
    $players = [];
    $lines = explode("\n", $content);
    
    foreach ($lines as $line) {
        if (preg_match('/\[PLAYER-JOIN\]\s*(\S+)/', $line, $m)) {
            $playerName = trim($m[1]);
            $players[$playerName] = 'joined';
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?\s(\S+)\s+joined the game/i', $line, $m)) {
            $playerName = trim($m[2]);
            $players[$playerName] = 'joined';
        }
        if (preg_match('/\[PLAYER-LEAVE\]\s*(\S+)/', $line, $m)) {
            $playerName = trim($m[1]);
            if (!empty($playerName)) {
                $players[$playerName] = 'left';
            }
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?\s(\S+)\s+left the game/i', $line, $m)) {
            $playerName = trim($m[2]);
            $players[$playerName] = 'left';
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?JOINING GAME.*?peer\s+(\w+)/i', $line, $m)) {
            $players[$m[2]] = 'joined';
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?LEAVING GAME.*?peer\s+(\w+)/i', $line, $m)) {
            $players[$m[2]] = 'left';
        }
    }
    
    $onlineCount = 0;
    foreach ($players as $status) {
        if ($status === 'joined') {
            $onlineCount++;
        }
    }
    
    return $onlineCount;
}

$onlinePlayersCache = [];

function handleOnlinePlayers()
{
    global $onlinePlayersCache;
    
    $serverId = $_GET['server'] ?? 'default';
    $currentTime = time();
    
    if (isset($onlinePlayersCache[$serverId])) {
        if ($currentTime - $onlinePlayersCache[$serverId]['timestamp'] < 30) {
            echo json_encode($onlinePlayersCache[$serverId]['data']);
            exit;
        }
    }
    
    $onlinePlayers = [];
    $response = sendRconCommand('/players', $serverId);
    
    if ($response !== null) {
        if (preg_match_all('/(\d+)\.\s+(\S+)\s+\(online\)/', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $onlinePlayers[] = [
                    'name' => $match[2],
                    'status' => 'online',
                    'index' => intval($match[1])
                ];
            }
        }
        
        $result = [
            'success' => true,
            'count' => count($onlinePlayers),
            'players' => $onlinePlayers,
            'source' => 'rcon'
        ];
        
        $onlinePlayersCache[$serverId] = [
            'timestamp' => $currentTime,
            'data' => $result
        ];
        
        echo json_encode($result);
        exit;
    }
    
    $logFile = getLogFilePath($serverId);
    
    if (!file_exists($logFile)) {
        echo json_encode(['success' => true, 'count' => 0, 'players' => [], 'source' => 'log']);
        exit;
    }
    
    $content = file_get_contents($logFile);
    if ($content === false) {
        echo json_encode(['success' => true, 'count' => 0, 'players' => [], 'source' => 'log']);
        exit;
    }
    
    $players = [];
    $lines = explode("\n", $content);
    
    foreach ($lines as $line) {
        if (preg_match('/\[PLAYER-JOIN\]\s*(\S+)/', $line, $m)) {
            $playerName = trim($m[1]);
            $players[$playerName] = ['status' => 'online', 'last_action' => date('Y-m-d H:i:s'), 'action' => 'joined'];
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?\s(\S+)\s+joined the game/i', $line, $m)) {
            $playerName = trim($m[2]);
            $players[$playerName] = ['status' => 'online', 'last_action' => $m[1], 'action' => 'joined'];
        }
        if (preg_match('/\[PLAYER-LEAVE\]\s*(\S+)/', $line, $m)) {
            $playerName = trim($m[1]);
            if (!empty($playerName) && $playerName !== '' ) {
                $players[$playerName] = ['status' => 'offline', 'last_action' => date('Y-m-d H:i:s'), 'action' => 'left'];
            }
        } elseif (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?\s(\S+)\s+left the game/i', $line, $m)) {
            $playerName = trim($m[2]);
            $players[$playerName] = ['status' => 'offline', 'last_action' => $m[1], 'action' => 'left'];
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?JOINING GAME.*?peer\s+(\w+)/i', $line, $m)) {
            $players[$m[2]] = ['status' => 'online', 'last_action' => $m[1], 'action' => 'joined'];
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?LEAVING GAME.*?peer\s+(\w+)/i', $line, $m)) {
            $players[$m[2]] = ['status' => 'offline', 'last_action' => $m[1], 'action' => 'left'];
        }
    }
    
    $onlinePlayers = [];
    foreach ($players as $name => $data) {
        if ($data['status'] === 'online') {
            $onlinePlayers[] = [
                'name' => $name,
                'last_action' => $data['last_action']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($onlinePlayers),
        'players' => $onlinePlayers,
        'source' => 'log'
    ]);
    exit;
}

function handlePhpInfo()
{
    echo json_encode([
        'success' => true,
        'php_version' => PHP_VERSION,
        'os' => PHP_OS_FAMILY,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '--',
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
    ]);
    exit;
}

function handleChangePassword()
{
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword)) {
        echo json_encode(['success' => false, 'error' => '请输入新密码']);
        exit;
    }

    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'error' => '密码长度至少6位']);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'error' => '两次密码输入不一致']);
        exit;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => '未登录或会话无效']);
        exit;
    }

    try {
        $db = \App\Core\Database::getInstance();
        $db->initialize();

        $user = $db->query('SELECT password_hash FROM users WHERE id = :id', [':id' => $userId]);
        if (empty($user)) {
            echo json_encode(['success' => false, 'error' => '用户不存在']);
            exit;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->execute('UPDATE users SET password_hash = :hash, updated_at = :ua WHERE id = :id', [':hash' => $newHash, ':ua' => time(), ':id' => $userId]);

        echo json_encode(['success' => true, 'message' => '密码修改成功']);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => '密码修改失败: ' . $e->getMessage()]);
    }
    exit;
}

function handleGetUserInfo()
{
    $user = getCurrentUser();
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => '未登录']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
    exit;
}

function handleUserList()
{
    $db = \App\Core\Database::getInstance();
    $db->initialize();

    $rows = $db->query('SELECT id, username, name, game_id, role, vip_level, password_hash, created_at FROM users ORDER BY id');
    $users = [];
    foreach ($rows as $row) {
        $users[] = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'name' => $row['name'] ?? $row['username'],
            'game_id' => $row['game_id'] ?? '',
            'role' => $row['role'] ?? 'user',
            'vip_level' => (int)($row['vip_level'] ?? 0),
            'has_password' => !empty($row['password_hash'])
        ];
    }

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
    exit;
}

function handleAddUser()
{
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $gameId = trim($_POST['game_id'] ?? '');
    $role = $_POST['role'] ?? 'user';

    if (empty($username)) {
        echo json_encode(['success' => false, 'error' => '请输入用户名']);
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        echo json_encode(['success' => false, 'error' => '用户名需要3-20位字母、数字或下划线']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => '密码长度至少6位']);
        exit;
    }

    if (!in_array($role, ['admin', 'user'])) {
        $role = 'user';
    }

    $db = \App\Core\Database::getInstance();
    $db->initialize();

    $existing = $db->query('SELECT id FROM users WHERE username = ?', [$username]);
    if (!empty($existing)) {
        echo json_encode(['success' => false, 'error' => '用户名已存在']);
        exit;
    }

    $db->execute(
        'INSERT INTO users (username, password_hash, role, name, game_id, vip_level, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), $role, $name ?: $username, $gameId, time(), time()]
    );

    echo json_encode(['success' => true, 'message' => '用户添加成功']);
    exit;
}

function handleDeleteUser()
{
    $username = $_POST['username'] ?? '';
    $currentUser = $_SESSION['username'] ?? '';

    if (empty($username)) {
        echo json_encode(['success' => false, 'error' => '请指定用户']);
        exit;
    }

    if ($username === $currentUser) {
        echo json_encode(['success' => false, 'error' => '不能删除当前登录用户']);
        exit;
    }

    $db = \App\Core\Database::getInstance();
    $db->initialize();

    $result = $db->execute('DELETE FROM users WHERE username = ?', [$username]);
    if ($result > 0) {
        echo json_encode(['success' => true, 'message' => '用户已删除']);
    } else {
        echo json_encode(['success' => false, 'error' => '用户不存在']);
    }
    exit;
}

function handleUpdateUser()
{
    $username = $_POST['username'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';
    $gameId = $_POST['game_id'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (empty($username)) {
        echo json_encode(['success' => false, 'error' => '请指定用户']);
        exit;
    }

    $db = \App\Core\Database::getInstance();
    $db->initialize();

    $existing = $db->query('SELECT id FROM users WHERE username = ?', [$username]);
    if (empty($existing)) {
        echo json_encode(['success' => false, 'error' => '用户不存在']);
        exit;
    }

    if (!empty($name)) {
        $db->execute('UPDATE users SET name = ?, updated_at = ? WHERE username = ?', [$name, time(), $username]);
    }

    if (in_array($role, ['admin', 'user'])) {
        $db->execute('UPDATE users SET role = ?, updated_at = ? WHERE username = ?', [$role, time(), $username]);
    }

    $db->execute('UPDATE users SET game_id = ?, updated_at = ? WHERE username = ?', [$gameId, time(), $username]);

    if (!empty($newPassword) && strlen($newPassword) >= 6) {
        $db->execute('UPDATE users SET password_hash = ?, updated_at = ? WHERE username = ?', [password_hash($newPassword, PASSWORD_DEFAULT), time(), $username]);
    }

    echo json_encode(['success' => true, 'message' => '用户信息已更新']);
    exit;
}

function handleResetPassword()
{
    $username = $_POST['username'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (empty($username)) {
        echo json_encode(['success' => false, 'error' => '请指定用户']);
        exit;
    }

    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'error' => '密码长度至少6位']);
        exit;
    }

    $db = \App\Core\Database::getInstance();
    $db->initialize();

    $result = $db->execute('UPDATE users SET password_hash = ?, updated_at = ? WHERE username = ?', [password_hash($newPassword, PASSWORD_DEFAULT), time(), $username]);

    if ($result > 0) {
        echo json_encode(['success' => true, 'message' => '密码已重置']);
    } else {
        echo json_encode(['success' => false, 'error' => '用户不存在']);
    }
    exit;
}

function handleGenerateBindingCode()
{
    $authService = new \App\Services\AuthService();
    if (!$authService->isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        exit;
    }

    $currentUser = $authService->getCurrentUser();
    $userId = (int)($currentUser['id'] ?? 0);

    if (!is_numeric($currentUser['id'] ?? null)) {
        echo json_encode(['success' => false, 'error' => '当前用户不支持此功能']);
        exit;
    }

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => '用户未登录']);
        exit;
    }

    $db = \App\Core\Database::getInstance();
    $db->initialize();

    $user = $db->query('SELECT id, game_id, binding_code FROM users WHERE id = ?', [$userId]);
    if (empty($user)) {
        echo json_encode(['success' => false, 'error' => '用户不存在']);
        exit;
    }

    $user = $user[0];

    if (!empty($user['game_id'])) {
        echo json_encode(['success' => false, 'error' => '该账号已绑定游戏ID，无需重复绑定']);
        exit;
    }

    $bindingCode = strtoupper(substr(md5($userId . time() . uniqid()), 0, 8));

    $db->execute('UPDATE users SET binding_code = ?, updated_at = ? WHERE id = ?', [$bindingCode, time(), $userId]);

    echo json_encode([
        'success' => true,
        'binding_code' => $bindingCode,
        'message' => '绑定码生成成功，请在游戏中发送绑定码进行认证'
    ]);
    exit;
}

function handleCheckBindingStatus()
{
    $authService = new \App\Services\AuthService();
    if (!$authService->isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        exit;
    }

    $currentUser = $authService->getCurrentUser();
    $userId = (int)($currentUser['id'] ?? 0);

    if (!is_numeric($currentUser['id'] ?? null)) {
        echo json_encode(['success' => false, 'error' => '当前用户不支持此功能']);
        exit;
    }

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => '用户未登录']);
        exit;
    }

    $db = \App\Core\Database::getInstance();
    $db->initialize();

    $user = $db->query('SELECT id, username, game_id, binding_code, vip_level FROM users WHERE id = ?', [$userId]);
    if (empty($user)) {
        echo json_encode(['success' => false, 'error' => '用户不存在']);
        exit;
    }

    $user = $user[0];

    echo json_encode([
        'success' => true,
        'game_id' => $user['game_id'] ?? '',
        'binding_code' => $user['binding_code'] ?? '',
        'vip_level' => (int)$user['vip_level'],
        'is_bound' => !empty($user['game_id'])
    ]);
    exit;
}

function handleLogHistory() {
    $logFile = getLogFilePath($_GET['config'] ?? null);
    $offset = intval($_GET['offset'] ?? 0);
    $limit = intval($_GET['limit'] ?? 100);
    $fromEnd = isset($_GET['from_end']) && $_GET['from_end'] === 'true';
    $limit = min($limit, 500);
    
    if (!file_exists($logFile)) {
        echo json_encode([
            'success' => true, 
            'entries' => [], 
            'total' => 0, 
            'offset' => 0,
            'log_file' => basename($logFile),
            'message' => '该配置暂无日志文件'
        ]);
        exit;
    }
    
    $entries = [];
    $totalLines = 0;
    $allLines = [];
    
    $f = fopen($logFile, 'r');
    if (!$f) {
        echo json_encode(['success' => false, 'error' => '无法读取日志文件']);
        exit;
    }
    
    while (!feof($f)) {
        $line = fgets($f);
        if ($line !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                $allLines[] = $line;
                $totalLines++;
            }
        }
    }
    fclose($f);
    
    if ($fromEnd) {
        $start = max(0, $totalLines - $offset - $limit);
        $end = $totalLines - $offset;
        for ($i = $start; $i < $end && $i < $totalLines; $i++) {
            $entries[] = parseLogLine($allLines[$i]);
        }
        $hasMore = $start > 0;
    } else {
        $start = $offset;
        $end = min($offset + $limit, $totalLines);
        for ($i = $start; $i < $end; $i++) {
            $entries[] = parseLogLine($allLines[$i]);
        }
        $hasMore = $end < $totalLines;
    }
    
    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'total' => $totalLines,
        'offset' => $offset,
        'hasMore' => $hasMore,
        'log_file' => basename($logFile)
    ]);
    exit;
}

function handleLogFiles() {
    $logsDir = $GLOBALS['baseDir'] . '/logs';
    $files = [];
    
    if (is_dir($logsDir)) {
        $logFiles = glob("$logsDir/factorio-*.log");
        
        // 获取当前使用的配置
        $runtimeConfigFile = $GLOBALS['logDir'] . '/runtimeConfig.json';
        $currentConfig = null;
        if (file_exists($runtimeConfigFile)) {
            $runtimeConfig = json_decode(file_get_contents($runtimeConfigFile), true);
            if ($runtimeConfig && !empty($runtimeConfig['config_file'])) {
                $currentConfig = preg_replace('/\.json$/i', '', $runtimeConfig['config_file']);
            }
        }
        
        foreach ($logFiles as $file) {
            $filename = basename($file);
            $filesize = filesize($file);
            $modified = filemtime($file);
            
            // 判断是否为当前日志
            $isCurrent = false;
            $isBackup = false;
            
            if ($currentConfig && $filename === "factorio-{$currentConfig}.log") {
                $isCurrent = true;
            }
            
            // 判断是否为备份日志（包含时间戳格式）
            if (preg_match('/factorio-.+-\d{4}-\d{2}-\d{2}_\d{6}\.log$/', $filename)) {
                $isBackup = true;
            }
            
            $files[] = [
                'filename' => $filename,
                'size' => formatFileSize($filesize),
                'size_bytes' => $filesize,
                'modified' => date('Y-m-d H:i:s', $modified),
                'is_current' => $isCurrent,
                'is_backup' => $isBackup
            ];
        }
        
        // 按修改时间降序排序
        usort($files, function($a, $b) {
            return strtotime($b['modified']) - strtotime($a['modified']);
        });
    }
    
    echo json_encode([
        'success' => true,
        'files' => $files
    ]);
    exit;
}

function handleDownloadLog() {
    $filename = basename($_GET['filename'] ?? '');
    
    if (empty($filename) || !preg_match('/^factorio-.+\.log$/', $filename)) {
        echo json_encode(['success' => false, 'error' => '无效的文件名']);
        exit;
    }
    
    $filepath = $GLOBALS['baseDir'] . '/logs/' . $filename;
    
    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => '文件不存在']);
        exit;
    }
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

function handleDeleteLog() {
    $filename = basename($_POST['filename'] ?? '');
    
    if (empty($filename) || !preg_match('/^factorio-.+\.log$/', $filename)) {
        echo json_encode(['success' => false, 'error' => '无效的文件名']);
        exit;
    }
    
    $filepath = $GLOBALS['baseDir'] . '/logs/' . $filename;
    
    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => '文件不存在']);
        exit;
    }
    
    // 检查是否为当前使用的日志
    $runtimeConfigFile = $GLOBALS['logDir'] . '/runtimeConfig.json';
    if (file_exists($runtimeConfigFile)) {
        $runtimeConfig = json_decode(file_get_contents($runtimeConfigFile), true);
        if ($runtimeConfig && !empty($runtimeConfig['config_file'])) {
            $currentConfig = preg_replace('/\.json$/i', '', $runtimeConfig['config_file']);
            if ($filename === "factorio-{$currentConfig}.log") {
                echo json_encode(['success' => false, 'error' => '无法删除当前正在使用的日志文件']);
                exit;
            }
        }
    }
    
    if (@unlink($filepath)) {
        echo json_encode(['success' => true, 'message' => '日志文件已删除']);
    } else {
        echo json_encode(['success' => false, 'error' => '删除失败，请检查权限']);
    }
    exit;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

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

function handleSecurityCheck()
{
    $issues = SecureConfig::checkConfigSecurity();
    
    $configFile = dirname(__DIR__) . '/config/system/rcon.php';
    $needsMigration = false;
    
    if (file_exists($configFile)) {
        $content = file_get_contents($configFile);
        if (strpos($content, "'rcon_password' =>") !== false && 
            strpos($content, "'rcon_password_encrypted' =>") === false) {
            $needsMigration = true;
        }
    }
    
    if ($needsMigration) {
        $issues[] = [
            'file' => 'rcon.php',
            'type' => 'migration',
            'message' => 'RCON 密码未加密存储，建议迁移到加密存储'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'issues' => $issues,
        'needs_migration' => $needsMigration,
        'secure' => empty($issues)
    ]);
    exit;
}

function handleSecurityFix()
{
    $fixed = SecureConfig::fixConfigSecurity();
    
    $configFile = dirname(__DIR__) . '/config/system/rcon.php';
    $migrated = false;
    
    if (file_exists($configFile)) {
        $content = file_get_contents($configFile);
        if (strpos($content, "'rcon_password' =>") !== false && 
            strpos($content, "'rcon_password_encrypted' =>") === false) {
            
            $configs = require $configFile;
            $newConfigs = [];
            
            foreach ($configs as $id => $cfg) {
                $newConfigs[$id] = [
                    'rcon_enabled' => $cfg['rcon_enabled'] ?? true,
                    'rcon_port' => $cfg['rcon_port'] ?? 27015,
                    'rcon_password' => $cfg['rcon_password'] ?? '',
                    'rcon_host' => $cfg['rcon_host'] ?? '127.0.0.1',
                    'screen_name' => $cfg['screen_name'] ?? 'factorio_server',
                    'description' => $cfg['description'] ?? $id,
                ];
            }
            
            if (SecureConfig::saveRconConfig($newConfigs)) {
                $migrated = true;
                $fixed[] = 'rcon.php (密码加密迁移)';
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'fixed' => $fixed,
        'migrated' => $migrated,
        'message' => '安全修复完成'
    ]);
    exit;
}

function handleGenerateRconPassword()
{
    $password = SecureConfig::generateRconPassword();
    
    echo json_encode([
        'success' => true,
        'password' => $password,
        'message' => '已生成安全的 RCON 密码'
    ]);
    exit;
}

function handleAutoResponderStatus() {
    $pidFile = dirname(__DIR__) . '/run/autoResponder.pid';
    $running = false;
    $pid = null;
    $mode = 'daemon';
    
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0 && posix_kill($pid, 0)) {
            $running = true;
        } else {
            @unlink($pidFile);
        }
    }
    
    echo json_encode([
        'running' => $running,
        'pid' => $pid,
        'mode' => $mode
    ]);
    exit;
}

function handleAutoResponderStart() {
    $daemonScript = __DIR__ . '/autoResponderDaemon.php';
    $pidFile = dirname(__DIR__) . '/run/autoResponder.pid';
    $lockFile = dirname(__DIR__) . '/run/autoResponder.lock';
    
    $lockHandle = @fopen($lockFile, 'c');
    if (!$lockHandle) {
        echo json_encode([
            'success' => false,
            'message' => '无法创建锁文件'
        ]);
        exit;
    }
    
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        echo json_encode([
            'success' => false,
            'message' => '启动操作正在进行中，请稍后再试'
        ]);
        exit;
    }
    
    try {
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if ($pid > 0 && posix_kill($pid, 0)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                echo json_encode([
                    'success' => false,
                    'message' => '自动响应守护进程已在运行中'
                ]);
                exit;
            }
            @unlink($pidFile);
        }
        
        $logFile = dirname(__DIR__) . '/logs/autoResponderDaemon.log';
        
        // 确保日志目录存在
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
            chown($logDir, 'www');
            chgrp($logDir, 'www');
        }
        
        $cmd = sprintf(
            'nohup /usr/bin/php %s start >> %s 2>&1 &',
            escapeshellarg($daemonScript),
            escapeshellarg($logFile)
        );
        exec($cmd);
        
        $waitTime = 0;
        $maxWait = 2000000;
        $checkInterval = 100000;
        
        while ($waitTime < $maxWait) {
            usleep($checkInterval);
            $waitTime += $checkInterval;
            
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                if ($pid > 0 && posix_kill($pid, 0)) {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                    echo json_encode([
                        'success' => true,
                        'message' => '自动响应守护进程已启动',
                        'pid' => $pid
                    ]);
                    exit;
                }
            }
        }
        
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        echo json_encode([
            'success' => false,
            'message' => '启动失败，守护进程未能正常启动'
        ]);
        exit;
    } catch (Exception $e) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        echo json_encode([
            'success' => false,
            'message' => '启动失败: ' . $e->getMessage()
        ]);
        exit;
    }
}

function handleAutoResponderStop() {
    $pidFile = dirname(__DIR__) . '/run/autoResponder.pid';
    
    if (!file_exists($pidFile)) {
        echo json_encode([
            'success' => false,
            'message' => '自动响应守护进程未运行'
        ]);
        exit;
    }
    
    $pid = (int)file_get_contents($pidFile);
    
    if ($pid > 0 && posix_kill($pid, 0)) {
        posix_kill($pid, SIGTERM);
        usleep(500000);
        
        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
    }
    
    @unlink($pidFile);
    
    echo json_encode([
        'success' => true,
        'message' => '自动响应守护进程已停止'
    ]);
    exit;
}

function handleAutoResponderRunOnce() {
    $message = $_POST['message'] ?? '';
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => '请提供消息内容']);
        exit;
    }
    
    // 使用 Factorio 正确的命令格式发送消息
    $screenName = 'factorio_server';
    $command = '/c game.print("' . str_replace('"', '\\"', $message) . '")';
    $escapedCommand = str_replace('"', '\\"', $command);
    $fullCommand = sprintf('screen -S %s -p 0 -X stuff "%s\n"', $screenName, $escapedCommand);
    shell_exec($fullCommand);
    
    echo json_encode([
        'success' => true,
        'message' => '消息已发送',
        'sent_message' => $message,
        'command' => $command
    ]);
    exit;
}

function startAutoResponder() {
    $pidFile = dirname(__DIR__) . '/run/autoResponder.pid';
    $logFile = dirname(__DIR__) . '/logs/autoResponderDaemon.log';
    
    // 确保日志目录存在
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
        chown($logDir, 'www');
        chgrp($logDir, 'www');
    }
    
    // 确保运行目录存在
    $runDir = dirname($pidFile);
    if (!is_dir($runDir)) {
        mkdir($runDir, 0755, true);
        chown($runDir, 'www');
        chgrp($runDir, 'www');
    }
    
    // 检查是否已有守护进程在运行
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0 && posix_kill($pid, 0)) {
            return;
        }
        @unlink($pidFile);
    }
    
    $daemonScript = __DIR__ . '/autoResponderDaemon.php';
    
    // 使用完整路径执行
    $cmd = sprintf(
        'nohup /usr/bin/php %s start >> %s 2>&1 &',
        escapeshellarg($daemonScript),
        escapeshellarg($logFile)
    );
    
    // 执行命令
    exec($cmd);
    
    // 等待守护进程启动
    usleep(1000000); // 增加等待时间
    
    // 检查守护进程是否启动成功
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0 && posix_kill($pid, 0)) {
            error_log('自动响应守护进程启动成功，PID: ' . $pid);
        } else {
            error_log('自动响应守护进程启动失败，PID 文件存在但进程不存在');
        }
    } else {
        error_log('自动响应守护进程启动失败，PID 文件未创建');
    }
}

function stopAutoResponder() {
    $pidFile = dirname(__DIR__) . '/run/autoResponder.pid';

    if (!file_exists($pidFile)) {
        return;
    }

    $pid = (int)file_get_contents($pidFile);

    if ($pid > 0 && posix_kill($pid, 0)) {
        posix_kill($pid, SIGTERM);
        usleep(500000);

        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
    }

    @unlink($pidFile);
}

function startWebSocket() {
    $pidFile = dirname(__DIR__) . '/run/websocket.pid';

    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0 && posix_kill($pid, 0)) {
            return;
        }
        @unlink($pidFile);
    }

    $wsScript = __DIR__ . '/websocket_server.php';
    $logFile = dirname(__DIR__) . '/logs/websocket.log';
    $cmd = sprintf(
        'nohup php %s >> %s 2>&1 & echo $!',
        escapeshellarg($wsScript),
        escapeshellarg($logFile)
    );
    $output = trim(exec($cmd));
    if (is_numeric($output) && $output > 0) {
        file_put_contents($pidFile, $output);
    }
}

function stopWebSocket() {
    $pidFile = dirname(__DIR__) . '/run/websocket.pid';

    if (!file_exists($pidFile)) {
        return;
    }

    $pid = (int)file_get_contents($pidFile);

    if ($pid > 0 && posix_kill($pid, 0)) {
        posix_kill($pid, SIGTERM);
        usleep(500000);

        if (posix_kill($pid, 0)) {
            posix_kill($pid, SIGKILL);
        }
    }

    @unlink($pidFile);
}

// 刷新输出缓冲，确保所有JSON响应被正确发送
ob_end_flush();

// ===== 配置文件管理 API =====
function getServerConfigDir() {
    $baseDir = dirname(__DIR__);
    $dir = $baseDir . '/config/serverConfigs';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    return $dir;
}

function handleConfigList() {
    $dir = getServerConfigDir();
    $files = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') as $file) {
            $files[] = basename($file, '.json');
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

function handleConfigGet() {
    $filename = trim($_GET['filename'] ?? '');
    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => '文件名不能为空']);
        exit;
    }
    $filename = preg_replace('/\.json$/i', '', $filename);
    $filepath = getServerConfigDir() . '/' . $filename . '.json';
    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => '配置文件不存在']);
        exit;
    }
    $config = json_decode(file_get_contents($filepath), true);
    if (!$config) {
        echo json_encode(['success' => false, 'error' => '配置文件格式无效']);
        exit;
    }
    echo json_encode(['success' => true, 'config' => $config]);
    exit;
}

function handleConfigSave() {
    $filename = trim($_POST['filename'] ?? '');
    $configJson = $_POST['config'] ?? '';
    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => '文件名不能为空']);
        exit;
    }
    $filename = preg_replace('/\.json$/i', '', $filename);
    if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]+$/u', $filename)) {
        echo json_encode(['success' => false, 'error' => '文件名只能包含字母、数字、汉字、下划线或中划线']);
        exit;
    }
    if (mb_strlen($filename, 'UTF-8') > 20) {
        echo json_encode(['success' => false, 'error' => '文件名不能超过 20 个字符']);
        exit;
    }
    $config = json_decode($configJson, true);
    if (!$config) {
        echo json_encode(['success' => false, 'error' => '配置格式无效']);
        exit;
    }
    $filepath = getServerConfigDir() . '/' . $filename . '.json';
    if (file_put_contents($filepath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        echo json_encode(['success' => false, 'error' => '保存失败，请检查目录权限']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => '配置已保存']);
    exit;
}

function handleConfigDelete() {
    $filename = trim($_POST['filename'] ?? '');
    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => '文件名不能为空']);
        exit;
    }
    $filename = preg_replace('/\.json$/i', '', $filename);
    $filepath = getServerConfigDir() . '/' . $filename . '.json';
    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => '配置文件不存在']);
        exit;
    }
    if (!unlink($filepath)) {
        echo json_encode(['success' => false, 'error' => '删除失败']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => '配置文件已删除']);
    exit;
}

function handleGetSecrets() {
    $config = loadConfig();
    $secrets = $config['factorio_secrets'] ?? ['username' => '', 'password' => '', 'token' => ''];
    echo json_encode(['success' => true, 'secrets' => $secrets]);
    exit;
}

// ===== WAL Checkpoint 定期清理（概率触发，约 1% 的请求）=====
// 防止 -wal 文件无限增长，定期将 WAL 内容合并回主数据库
if (rand(1, 100) === 1) {
    try {
        $db = \App\Core\Database::getInstance();
        $pdo = $db->getConnection();

        // TRUNCATE 模式：将 WAL 文件内容合并回主数据库并清空 WAL
        $pdo->exec("PRAGMA wal_checkpoint(TRUNCATE)");

        // 可选：记录日志（调试时开启）
        // error_log("[DB] WAL checkpoint executed at " . date('Y-m-d H:i:s'));
    } catch (\Exception $e) {
        // 静默失败，不影响业务逻辑
        // error_log("[DB] WAL checkpoint failed: " . $e->getMessage());
    }
}
// ===== WAL Checkpoint 清理结束 =====

function handleSavePlayerEvents() {
    try {
        $playerEvents = json_decode($_POST['player_events'] ?? '[]', true);
        if (!$playerEvents) {
            echo json_encode(['success' => false, 'message' => '无效的数据']);
            return;
        }

        require_once __DIR__ . '/services/ChatService.php';
        $chatService = new \App\Services\ChatService();

        $settings = [
            'playerEvents' => $playerEvents
        ];

        $result = $chatService->updateSettings($settings);
        if ($result) {
            echo json_encode(['success' => true, 'message' => '玩家事件设置已保存']);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败']);
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleGetPlayerEvents() {
    try {
        require_once __DIR__ . '/services/ChatService.php';
        $chatService = new \App\Services\ChatService();
        $settings = $chatService->getSettings();

        echo json_encode([
            'success' => true,
            'playerEvents' => $settings['playerEvents']
        ]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleGetChatSettings() {
    try {
        require_once __DIR__ . '/services/ChatService.php';
        $chatService = new \App\Services\ChatService();
        $settings = $chatService->getSettings();
        echo json_encode(['success' => true, 'data' => $settings]);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleAddTriggerResponse() {
    $keyword = $_POST['keyword'] ?? '';
    $response = $_POST['response'] ?? '';
    
    if (empty($keyword) || empty($response)) {
        echo json_encode(['success' => false, 'message' => '关键词和响应内容不能为空']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/services/ChatService.php';
        $chatService = new \App\Services\ChatService();
        
        $result = $chatService->addTriggerResponse([
            'keyword' => $keyword,
            'response' => $response,
            'isEnabled' => true
        ]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => '关键词回复已添加']);
        } else {
            echo json_encode(['success' => false, 'message' => '添加失败']);
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleDeleteTriggerResponse() {
    $id = $_POST['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => '请指定要删除的关键词回复ID']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/services/ChatService.php';
        $chatService = new \App\Services\ChatService();
        
        $result = $chatService->deleteTriggerResponse($id);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => '关键词回复已删除']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败']);
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}


