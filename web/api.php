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

$publicActions = ['login', 'check_auth', 'generate_hash'];
$action = $_REQUEST['action'] ?? '';

if (!in_array($action, $publicActions)) {
    requireLogin();
}

$baseDir     = dirname(__DIR__);
$binPath     = "$baseDir/bin/x64/factorio";
$versionsDir = "$baseDir/versions";
$serverRoot  = "$baseDir/server";
$saveDir     = "$baseDir/server/saves";
$configDir   = "$baseDir/server/configs";
$modDir      = "$baseDir/mods";
$logFile     = "$baseDir/factorio-current.log";
$stateFile   = "$saveDir/.state.json";

foreach ([$saveDir, $configDir, $modDir, $versionsDir] as $dir) {
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
    
    $srcHandle = fopen($src, 'rb');
    $dstHandle = fopen($dst, 'wb');
    
    if (!$srcHandle || !$dstHandle) {
        return false;
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
        
        // 更新进度文件
        if ($progressFile) {
            file_put_contents($progressFile, $progress);
        }
        
        // 每100KB更新一次进度（减少计算开销）
        if ($copied % 102400 === 0) {
            usleep(1000); // 短暂休眠，避免CPU占用过高
        }
    }
    
    fclose($srcHandle);
    fclose($dstHandle);
    $progress = 100;
    
    // 最终进度更新
    if ($progressFile) {
        file_put_contents($progressFile, $progress);
    }
    
    return true;
}

switch ($action) {
    case 'login':               handleLogin(); break;
    case 'logout':              handleLogout(); break;
    case 'check_auth':          handleCheckAuth(); break;
    case 'generate_hash':       handleGenerateHash(); break;
    case 'start':               handleStart(); break;
    case 'stop':                handleStop(); break;
    case 'console':             handleConsole(); break;
    case 'save_game':           handleSaveGame(); break;
    case 'files':               handleListFiles(); break;
    case 'upload':              handleUpload(); break;
    case 'delete_file':         handleDeleteFile(); break;
    case 'download':            handleDownload(); break;
    case 'set_current_save':    handleSetCurrentSave(); break;
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
    if (isServerRunning()) {
        echo json_encode(['message' => '服务器已在运行，无需重复启动']);
        exit;
    }
    $map   = basename($_POST['map'] ?? '');
    $cfg   = basename($_POST['config'] ?? '');
    $ver   = $_POST['version'] ?? 'default';
    if (!$map || !$cfg) {
        echo json_encode(['error' => '请完整选择地图和配置']);
        exit;
    }
    if ($ver === 'default') {
        $bin = $GLOBALS['binPath'];
    } else {
        $possiblePaths = [
            "{$GLOBALS['versionsDir']}/$ver/factorio/bin/x64/factorio",
            "{$GLOBALS['versionsDir']}/$ver/bin/x64/factorio"
        ];
        $bin = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $bin = $path;
                break;
            }
        }
    }
    if (!$bin || !file_exists($bin)) {
        echo json_encode(['error' => '服务端版本不存在']);
        exit;
    }
    $currentSave = "{$GLOBALS['saveDir']}/current.zip";
    if (!file_exists($currentSave)) {
        echo json_encode(['error' => '当前存档 current.zip 不存在，请先选择一个存档']);
        exit;
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
    if (!$file || !str_ends_with($file, '.zip')) {
        echo json_encode(['error' => '无效文件名']);
        exit;
    }
    $src = "{$GLOBALS['saveDir']}/$file";
    $dst = "{$GLOBALS['saveDir']}/current.zip";
    if (!file_exists($src)) {
        echo json_encode(['error' => '存档文件不存在']);
        exit;
    }
    if (!is_writable($GLOBALS['saveDir'])) {
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
    
    // 检查是否需要实时进度
    $realtime = isset($_POST['realtime']) && $_POST['realtime'] === 'true';
    
    if ($realtime) {
        // 使用分块传输编码
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        $progressFile = "{$GLOBALS['saveDir']}/.progress_" . uniqid() . ".tmp";
        file_put_contents($progressFile, '0');
        
        // 启动异步复制
        $pid = pcntl_fork();
        if ($pid == -1) {
            echo "event: error\ndata: {\"error\":\"无法启动复制进程\"}\n\n";
            exit;
        } elseif ($pid == 0) {
            // 子进程
            $progress = 0;
            if (copyWithProgress($src, $dst, $progress, $progressFile)) {
                chmod($dst, 0644);
                setCurrentSaveName($file);
                file_put_contents($progressFile, json_encode([
                    'status' => 'success',
                    'progress' => 100,
                    'message' => "已切换到存档：$file",
                    'filename' => $file,
                    'size' => $sizeMB
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
            // 父进程
            while (true) {
                usleep(500000); // 每500ms检查一次
                
                if (!file_exists($progressFile)) {
                    break;
                }
                
                $content = file_get_contents($progressFile);
                if (strpos($content, '{') === 0) {
                    // 完成
                    $data = json_decode($content, true);
                    echo "event: complete\ndata: " . json_encode($data) . "\n\n";
                    flush();
                    @unlink($progressFile);
                    break;
                } else {
                    // 进度更新
                    $progress = (float)$content;
                    echo "event: progress\ndata: {\"progress\":$progress,\"file\":\"$file\",\"size\":$sizeMB}\n\n";
                    flush();
                }
            }
        }
    } else {
        // 传统方式
        $progress = 0;
        if (copyWithProgress($src, $dst, $progress)) {
            chmod($dst, 0644);
            setCurrentSaveName($file);
            echo json_encode([
                'message' => "已切换到存档：$file",
                'filename' => $file,
                'size' => $sizeMB,
                'copied' => true,
                'progress' => 100
            ]);
        } else {
            $error = error_get_last();
            echo json_encode(['error' => '切换失败：' . ($error['message'] ?? '未知错误')]);
        }
    }
}

function handleListFiles() {
    $type = $_GET['type'] ?? 'map';
    if ($type === 'config') {
        $dir = $GLOBALS['configDir'];
        $pattern = "*.json";
    } else {
        $dir = $GLOBALS['saveDir'];
        $pattern = "*.zip";
    }
    
    $files = [];
    $currentSave = getCurrentSave();
    
    if (!is_dir($dir)) {
        echo json_encode(['files' => [], 'error' => '目录不存在: ' . $dir, 'current_save' => $currentSave]);
        return;
    }
    
    $foundFiles = glob("$dir/$pattern");
    if ($foundFiles === false) {
        echo json_encode(['files' => [], 'error' => '读取目录失败', 'current_save' => $currentSave]);
        return;
    }
    
    foreach ($foundFiles as $f) {
        $bn = basename($f);
        if (str_ends_with($bn, '.tmp.zip')) continue;
        if ($bn === 'current.zip') continue;
        
        $display = $bn;
        $isCurrent = ($bn === $currentSave);
        
        if (preg_match('/^(.+)_autosave\d+\.zip$/', $bn, $m)) {
            $display = "自动存档 ← {$m[1]}";
        } elseif (strpos($bn, '_autosave') === 0) {
            $display = "自动存档 " . substr($bn, 10, -4);
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
        'current_save' => $currentSave
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

function isServerRunning() {
    $screenCheck = shell_exec("screen -ls | grep factorio_server");
    $psCheck = shell_exec("pgrep -f 'factorio.*headless'");
    return !empty($screenCheck) || !empty($psCheck);
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
    $json = @file_get_contents("https://factorio.com/api/latest-releases");
    $data = json_decode($json, true) ?: [];
    
    $currentVersion = 'unknown';
    if (file_exists($GLOBALS['binPath'])) {
        $output = shell_exec($GLOBALS['binPath'] . ' --version 2>&1');
        if ($output && preg_match('/Version:?\s*([\d.]+)/i', $output, $matches)) {
            $currentVersion = $matches[1];
        }
    }
    
    echo json_encode([
        'stable'       => $data['stable']['headless'] ?? 'unknown',
        'experimental' => $data['experimental']['headless'] ?? 'unknown',
        'current'      => $currentVersion
    ]);
}

function handleGetVersions() {
    $list = [];
    if (file_exists($GLOBALS['binPath'])) {
        $list[] = ['id'=>'default', 'name'=>'默认版本'];
    }
    foreach (glob("{$GLOBALS['versionsDir']}/*", GLOB_ONLYDIR) as $d) {
        $possiblePaths = [
            "$d/factorio/bin/x64/factorio",
            "$d/bin/x64/factorio"
        ];
        foreach ($possiblePaths as $binPath) {
            if (file_exists($binPath)) {
                $list[] = ['id'=>basename($d), 'name'=>'v'.basename($d)];
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
    $logFile = __DIR__ . '/factorio-current.log';
    if (!file_exists($logFile) || filesize($logFile) === 0) {
        echo "暂无日志";
        exit;
    }
    $fp = fopen($logFile, 'r');
    if (!$fp) {
        echo "无法读取日志文件";
        exit;
    }
    $buffer = '';
    $pos    = -1;
    $count  = 0;
    $size   = filesize($logFile);
    while ($count < $lines && -$pos < $size) {
        fseek($fp, $pos, SEEK_END);
        $char = fgetc($fp);
        if ($char === "\n" || $char === "\r") {
            if ($buffer !== '' || $count > 0) $count++;
            if ($count >= $lines) break;
        }
        $buffer = $char . $buffer;
        $pos--;
    }
    fclose($fp);
    echo $buffer ?: "日志为空";
    exit;
}
