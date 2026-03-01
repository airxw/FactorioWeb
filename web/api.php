<?php
/**
 * Factorio Server Pro - API 主入口文件
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

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 900);

require_once __DIR__ . '/auth.php';

$publicActions = ['login', 'check_auth', 'generate_hash', 'update_user', 'update_check', 'log_tail', 'get_copy_progress', 'ip_info'];
$action = $_REQUEST['action'] ?? '';

if (!in_array($action, $publicActions)) {
    requireLogin();
}

$baseDir     = dirname(__DIR__);
$versionsDir = "$baseDir/versions";
$serverRoot  = "$baseDir/server";
$configDir   = "$baseDir/server/configs";
$sharedTemplatesDir = "$baseDir/server/templates";
$modDir      = "$baseDir/mods";
$logFile     = "$baseDir/factorio-current.log";

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

if (empty($availableVersions)) {
    echo json_encode(['error' => 'No valid Factorio versions found']);
    exit;
}

$currentVersion = $requestedVersion && in_array($requestedVersion, $availableVersions) ? $requestedVersion : $availableVersions[0];
$versionDir    = "$versionsDir/$currentVersion";
$binPath       = file_exists("$versionDir/factorio/bin/x64/factorio") ? "$versionDir/factorio/bin/x64/factorio" : "$versionDir/bin/x64/factorio";
$saveDir       = "$versionDir/saves";
$stateFile     = "$saveDir/.state.json";

foreach ([$saveDir, $configDir, $sharedTemplatesDir, $modDir, $versionsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function getState() {
    global $stateFile;
    if (file_exists($stateFile)) {
        $data = json_decode(file_get_contents($stateFile), true);
        return $data ?: [];
    }
    return [];
}

function setState($key, $value) {
    global $stateFile;
    $state = getState();
    $state[$key] = $value;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
    case 'log_tail':            handleLogTail(); break;
    case 'update_check':        handleUpdateCheck(); break;
    case 'update_install':      handleUpdateInstall(); break;
    case 'get_versions':        handleGetVersions(); break;
    case 'list_templates':      handleListTemplates(); break;
    case 'apply_template':      handleApplyTemplate(); break;
    case 'ip_info':             handleIpInfo(); break;
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

function handleUpdateUser() {
    $username = $_POST['username'] ?? '';
    $displayName = $_POST['display_name'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username)) {
        echo json_encode(['error' => '用户名不能为空']);
        exit;
    }
    
    if (empty($displayName)) {
        echo json_encode(['error' => '显示名称不能为空']);
        exit;
    }
    
    // 验证用户名格式（只允许字母、数字、下划线）
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        echo json_encode(['error' => '用户名只能包含字母、数字和下划线']);
        exit;
    }
    
    $configFile = __DIR__ . '/config.php';
    $config = [];
    
    if (file_exists($configFile)) {
        $config = require $configFile;
    }
    
    // 确保 users 数组存在
    if (!isset($config['users'])) {
        $config['users'] = [];
    }
    
    // 更新或创建用户
    $userData = [
        'name' => $displayName,
        'role' => 'admin',
    ];
    
    // 只有当密码不为空时才更新密码
    if (!empty($password)) {
        $userData['password'] = password_hash($password, PASSWORD_DEFAULT);
    } else if (isset($config['users'][$username])) {
        // 保持原有密码
        $userData['password'] = $config['users'][$username]['password'];
    } else {
        // 新用户必须设置密码
        echo json_encode(['error' => '新用户必须设置密码']);
        exit;
    }
    
    $config['users'][$username] = $userData;
    
    // 确保会话过期时间设置
    if (!isset($config['session_expiry'])) {
        $config['session_expiry'] = 86400;
    }
    
    // 保存配置文件
    $configContent = "<?php\nreturn [\n";
    $configContent .= "    'users' => [\n";
    
    foreach ($config['users'] as $user => $data) {
        $configContent .= "        '$user' => [\n";
        $configContent .= "            'password' => '{$data['password']}',\n";
        $configContent .= "            'role' => '{$data['role']}',\n";
        $configContent .= "            'name' => '{$data['name']}',\n";
        $configContent .= "        ],\n";
    }
    
    $configContent .= "    ],\n";
    $configContent .= "    'session_expiry' => {$config['session_expiry']},\n";
    $configContent .= "];\n";
    
    if (file_put_contents($configFile, $configContent) === false) {
        echo json_encode(['error' => '无法保存配置文件，请检查权限']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => '用户设置已保存']);
}

function handleStart() {
    if (isServerRunning()) {
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
    
    // Get version-specific paths
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
    
    // 检查用户选择的地图存档是否存在
    $selectedSave = "{$GLOBALS['saveDir']}/$map";
    $currentSave = "{$GLOBALS['saveDir']}/current.zip";
    
    // 获取存档目录中的实际文件列表
    $actualFiles = glob("{$GLOBALS['saveDir']}/*.zip");
    $actualFileNames = array_map('basename', $actualFiles);
    
    if (!file_exists($selectedSave)) {
        echo json_encode([
            'error' => '选择的存档文件不存在：' . $map,
            'selected_file' => $selectedSave,
            'actual_files' => $actualFileNames,
            'save_dir' => $GLOBALS['saveDir']
        ]);
        exit;
    }
    
    // 如果选择的存档不是 current.zip，则复制为 current.zip
    if ($map !== 'current.zip') {
        if (!copy($selectedSave, $currentSave)) {
            echo json_encode(['error' => '无法复制存档文件，请检查权限']);
            exit;
        }
    }
    
    $cmd = sprintf(
        "screen -dmS factorio_server bash -c 'cd %s && %s --start-server %s --server-settings %s --mod-directory %s --use-server-whitelist >> %s 2>&1'",
        escapeshellarg($GLOBALS['serverRoot']),
        escapeshellarg($bin),
        escapeshellarg($currentSave),
        escapeshellarg("{$GLOBALS['configDir']}/$cfg"),
        escapeshellarg($GLOBALS['modDir']),
        escapeshellarg($GLOBALS['logFile'])
    );
    shell_exec($cmd);
    echo json_encode(['message' => '服务器启动成功']);
}

function handleSetCurrentSave() {
    $file = basename($_POST['filename'] ?? '');
    $ver = $_POST['version'] ?? '';
    
    if (!$file || !str_ends_with($file, '.zip') || !$ver) {
        echo json_encode(['error' => '无效文件名或版本']);
        exit;
    }
    
    // Get version-specific save directory
    $saveDir = "{$GLOBALS['versionsDir']}/$ver/saves";
    if (!is_dir($saveDir)) {
        mkdir($saveDir, 0755, true);
    }
    
    $src = "$saveDir/$file";
    $dst = "$saveDir/current.zip";
    
    if (!file_exists($src)) {
        echo json_encode(['error' => '存档文件不存在']);
        exit;
    }
    if (!is_writable($saveDir)) {
        echo json_encode(['error' => '目录不可写，请检查权限']);
        exit;
    }
    if (file_exists($dst) && !is_writable($dst)) {
        echo json_encode(['error' => 'current.zip 不可写，请检查权限']);
        exit;
    }
    
    $fileSize = filesize($src);
    $sizeMB = round($fileSize / 1024 / 1024, 2);
    
    if (file_exists($dst)) {
        @unlink($dst);
    }
    
    // 检查是否需要异步复制（使用轮询方式）
    $async = isset($_POST['async']) && $_POST['async'] === 'true';
    
    if ($async) {
        // 生成唯一的进度ID
        $progressId = uniqid('save_');
        $progressFile = "$saveDir/.progress_$progressId.json";
        
        // 初始化进度文件
        file_put_contents($progressFile, json_encode([
            'status' => 'copying',
            'progress' => 0,
            'file' => $file,
            'size' => $sizeMB,
            'version' => $ver
        ]));
        chmod($progressFile, 0666);
        
        // 启动后台复制进程
        $pid = pcntl_fork();
        if ($pid == -1) {
            @unlink($progressFile);
            echo json_encode(['error' => '无法启动复制进程']);
            exit;
        } elseif ($pid == 0) {
            // 子进程：执行复制
            $progress = 0;
            $success = copyWithProgress($src, $dst, $progress, $progressFile);
            
            if ($success) {
                chmod($dst, 0644);
                
                // Save current save info
                $stateFile = "$saveDir/.state.json";
                $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
                $state['current_save'] = $file;
                file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                // 更新进度文件为完成状态
                file_put_contents($progressFile, json_encode([
                    'status' => 'success',
                    'progress' => 100,
                    'message' => "已切换到存档：$file",
                    'filename' => $file,
                    'size' => $sizeMB,
                    'version' => $ver
                ]));
            } else {
                $error = error_get_last();
                file_put_contents($progressFile, json_encode([
                    'status' => 'error',
                    'error' => '切换失败：' . ($error['message'] ?? '未知错误')
                ]));
            }
            exit;
        } else {
            // 父进程：返回进度ID
            echo json_encode([
                'status' => 'started',
                'progress_id' => $progressId,
                'message' => '开始复制存档...'
            ]);
            exit;
        }
    } else {
        // 同步方式（传统）
        $progress = 0;
        if (copyWithProgress($src, $dst, $progress)) {
            chmod($dst, 0644);
            
            // Save current save info
            $stateFile = "$saveDir/.state.json";
            $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
            $state['current_save'] = $file;
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            echo json_encode([
                'message' => "已切换到存档：$file",
                'filename' => $file,
                'size' => $sizeMB,
                'copied' => true,
                'progress' => 100,
                'version' => $ver
            ]);
        } else {
            $error = error_get_last();
            echo json_encode(['error' => '切换失败：' . ($error['message'] ?? '未知错误')]);
        }
    }
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
    } else {
        // 使用统一的存档目录
        $dir = $GLOBALS['saveDir'];
        $pattern = "*.zip";
    }
    
    $files = [];
    $currentSave = '';
    
    // Get current save for this version
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
    
    foreach ($foundFiles as $f) {
        $bn = basename($f);
        if (str_ends_with($bn, '.tmp.zip')) continue;
        
        $display = $bn;
        $isCurrent = ($bn === $currentSave);
        
        // 特别处理 current.zip 文件
        if ($bn === 'current.zip') {
            $display = "📁 手动存档 (current.zip)";
            $isCurrent = true; // current.zip 始终被视为当前使用的存档
        }
        
        if (preg_match('/^(.+?)_autosave(\d+)\.zip$/', $bn, $m)) {
            $display = "🔄 自动存档 #{$m[2]} ← {$m[1]}";
        } elseif (preg_match('/^_autosave(\d+)\.zip$/', $bn, $m)) {
            $display = "🔄 自动存档 #{$m[1]}";
        }
        
        if ($isCurrent) {
            $display = "✅ " . $display . " (当前使用中)";
        }
        
        $files[] = [
            'filename' => $bn,
            'display'  => $display,
            'size'     => round(filesize($f) / 1024 / 1024, 2),
            'time'     => filemtime($f),
            'is_current' => $isCurrent
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
    if (isServerRunning()) {
        shell_exec("screen -S factorio_server -X stuff \"/quit\n\"");
        sleep(2);
        shell_exec("screen -S factorio_server -X quit");
    }
    echo json_encode(['message' => '服务器正在关闭']);
}

function handleSaveGame() {
    if (!isServerRunning()) {
        echo json_encode(['error' => '服务器未运行，无法保存']);
        exit;
    }
    
    // 获取当前存档名
    $currentSave = getCurrentSave();
    
    // 发送 /save 命令给 Factorio
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], '/save');
    shell_exec("screen -S factorio_server -p 0 -X stuff \"$escaped\n\"");
    
    // 等待 Factorio 完成保存（等待更长时间）
    sleep(3);
    
    // 获取保存前的文件列表
    $beforeFiles = [];
    foreach (glob("{$GLOBALS['saveDir']}/*.zip") as $f) {
        $beforeFiles[basename($f)] = filemtime($f);
    }
    
    // 再等一下
    sleep(1);
    
    // 检查是否有新文件或文件修改
    $newSave = null;
    foreach (glob("{$GLOBALS['saveDir']}/*.zip") as $f) {
        $bn = basename($f);
        $mtime = filemtime($f);
        
        if ($bn === 'current.zip') {
            continue;
        }
        
        // 检查是否是新文件或最近修改的文件
        if (!isset($beforeFiles[$bn]) || $mtime > $beforeFiles[$bn]) {
            $newSave = $bn;
            break;
        }
    }
    
    // 如果找到了新保存的文件，创建一个带时间戳的备份
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
    if ($cmd === '') {
        echo json_encode(['error' => 'Empty command']);
        exit;
    }
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $cmd);
    shell_exec("screen -S factorio_server -p 0 -X stuff \"$escaped\n\"");
    echo json_encode(['message' => '指令已发送']);
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

// 服务器状态缓存，有效期 5 秒
$serverRunningCache = [
    'status' => false,
    'timestamp' => 0
];

function isServerRunning() {
    global $serverRunningCache;
    
    // 检查缓存是否有效（5秒内）
    $currentTime = time();
    if ($currentTime - $serverRunningCache['timestamp'] < 5) {
        return $serverRunningCache['status'];
    }
    
    // 执行状态检查
    $screenCheck = shell_exec("screen -ls | grep factorio_server");
    $psCheck = shell_exec("pgrep -f 'factorio.*headless'");
    $status = !empty($screenCheck) || !empty($psCheck);
    
    // 更新缓存
    $serverRunningCache['status'] = $status;
    $serverRunningCache['timestamp'] = $currentTime;
    
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
    /**
     * 安全读取玩家列表文件
     * 
     * @param string $filePath 文件路径
     * @return array 玩家列表数组，失败返回空数组
     */
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
    
    echo json_encode([
        'admins'     => $safeReadList("{$GLOBALS['serverRoot']}/server-adminlist.json"),
        'bans'       => $safeReadList("{$GLOBALS['serverRoot']}/server-banlist.json"),
        'whitelist'  => $safeReadList("{$GLOBALS['serverRoot']}/server-whitelist.json")
    ]);
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
    if (file_exists($GLOBALS['binPath'])) {
        $output = shell_exec($GLOBALS['binPath'] . ' --version 2>&1');
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

function handleLogTail() {
    $lines   = max(100, min(10000, (int)($_GET['lines'] ?? 1000)));
    $logFile = dirname(__DIR__) . '/factorio-current.log';
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
