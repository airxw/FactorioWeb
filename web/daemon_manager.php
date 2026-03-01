<?php
/**
 * 守护进程管理 API
 * 用于在 Web 界面上启动/停止实时监控守护进程
 */

header('Content-Type: application/json');

$pidFile = __DIR__ . '/auto_responder.pid';
$startScript = __DIR__ . '/start_daemon.sh';
$stopScript = __DIR__ . '/stop_daemon.sh';
$daemonScript = __DIR__ . '/auto_responder_daemon.php';

/**
 * 获取守护进程状态
 */
function getDaemonStatus() {
    global $pidFile;
    
    if (!file_exists($pidFile)) {
        return ['running' => false, 'mode' => null];
    }
    
    $pid = intval(file_get_contents($pidFile));
    
    // 检查进程是否存在
    $output = shell_exec("ps -p {$pid} 2>/dev/null | grep -v PID");
    if (!empty($output)) {
        return [
            'running' => true,
            'mode' => 'daemon',
            'pid' => $pid
        ];
    }
    
    // PID 文件存在但进程不存在，清理
    unlink($pidFile);
    return ['running' => false, 'mode' => null];
}

/**
 * 启动守护进程
 */
function startDaemon() {
    global $startScript;
    
    // 检查是否已在运行
    $status = getDaemonStatus();
    if ($status['running']) {
        return ['success' => true, 'message' => '守护进程已在运行中', 'pid' => $status['pid']];
    }
    
    // 启动守护进程
    $output = shell_exec($startScript . ' 2>&1');
    
    // 等待一下让进程启动
    sleep(1);
    
    // 检查状态
    $status = getDaemonStatus();
    if ($status['running']) {
        return ['success' => true, 'message' => '守护进程已启动', 'pid' => $status['pid']];
    }
    
    return ['success' => false, 'message' => '启动失败: ' . $output];
}

/**
 * 停止守护进程
 */
function stopDaemon() {
    global $stopScript;
    
    $output = shell_exec($stopScript . ' 2>&1');
    
    return ['success' => true, 'message' => trim($output)];
}

/**
 * 立即执行一次（前台模式）
 */
function runOnce() {
    global $daemonScript;
    $output = shell_exec('timeout 5 php ' . escapeshellarg($daemonScript) . ' run 2>&1 &');
    return ['success' => true, 'message' => '已启动单次执行'];
}

// 处理请求
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

switch ($action) {
    case 'status':
        echo json_encode(getDaemonStatus());
        break;
        
    case 'start':
        echo json_encode(startDaemon());
        break;
        
    case 'stop':
        echo json_encode(stopDaemon());
        break;
        
    case 'run_once':
        echo json_encode(runOnce());
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
        break;
}
