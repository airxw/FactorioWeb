<?php
/**
 * Cron 任务管理 API
 * 用于在 Web 界面上启动/停止自动响应系统
 */

header('Content-Type: application/json');

$webDir = __DIR__;
$autoResponderScript = $webDir . '/auto_responder.php';
$autoResponderLog = $webDir . '/auto_responder.log';
$cronCommand = '* * * * * /usr/bin/php ' . escapeshellarg($autoResponderScript) . ' >> ' . escapeshellarg($autoResponderLog) . ' 2>&1';

/**
 * 获取当前 cron 任务状态
 */
function getCronStatus() {
    global $cronCommand;
    
    $output = shell_exec('crontab -l 2>/dev/null');
    $isRunning = strpos($output, 'auto_responder.php') !== false;
    
    return [
        'enabled' => $isRunning,
        'command' => $isRunning ? $cronCommand : null
    ];
}

/**
 * 启用 cron 任务
 */
function enableCron() {
    global $cronCommand;
    
    // 获取现有 crontab
    $currentCrontab = shell_exec('crontab -l 2>/dev/null') ?: '';
    
    // 检查是否已存在
    if (strpos($currentCrontab, 'auto_responder.php') !== false) {
        return ['success' => true, 'message' => '自动响应系统已经在运行中'];
    }
    
    // 添加新任务
    $newCrontab = trim($currentCrontab) . "\n" . $cronCommand . "\n";
    
    // 写入临时文件
    $tempFile = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tempFile, $newCrontab);
    
    // 安装新的 crontab
    $result = shell_exec('crontab ' . escapeshellarg($tempFile) . ' 2>&1');
    unlink($tempFile);
    
    if ($result === null || empty($result)) {
        return ['success' => true, 'message' => '自动响应系统已启动'];
    } else {
        return ['success' => false, 'message' => '启动失败: ' . $result];
    }
}

/**
 * 禁用 cron 任务
 */
function disableCron() {
    // 获取现有 crontab
    $currentCrontab = shell_exec('crontab -l 2>/dev/null') ?: '';
    
    // 检查是否存在
    if (strpos($currentCrontab, 'auto_responder.php') === false) {
        return ['success' => true, 'message' => '自动响应系统已经停止'];
    }
    
    // 移除 auto_responder 相关的行
    $lines = explode("\n", $currentCrontab);
    $newLines = [];
    foreach ($lines as $line) {
        if (strpos($line, 'auto_responder.php') === false) {
            $newLines[] = $line;
        }
    }
    
    $newCrontab = implode("\n", $newLines);
    
    // 写入临时文件
    $tempFile = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tempFile, $newCrontab);
    
    // 安装新的 crontab
    $result = shell_exec('crontab ' . escapeshellarg($tempFile) . ' 2>&1');
    unlink($tempFile);
    
    if ($result === null || empty($result)) {
        return ['success' => true, 'message' => '自动响应系统已停止'];
    } else {
        return ['success' => false, 'message' => '停止失败: ' . $result];
    }
}

/**
 * 立即执行一次 auto_responder.php
 */
function runOnce() {
    global $autoResponderScript;
    $output = shell_exec('/usr/bin/php ' . escapeshellarg($autoResponderScript) . ' 2>&1');
    return [
        'success' => true,
        'message' => '已手动执行一次',
        'output' => $output
    ];
}

// 处理请求
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'status':
        echo json_encode(getCronStatus());
        break;
        
    case 'enable':
        echo json_encode(enableCron());
        break;
        
    case 'disable':
        echo json_encode(disableCron());
        break;
        
    case 'run_once':
        echo json_encode(runOnce());
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
        break;
}
