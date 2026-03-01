<?php
// 后台任务检查脚本
// 用于定期检查并执行定时任务

// 检查定时任务
function checkScheduledTasks() {
    // 读取设置
    $settingsFile = __DIR__ . '/chat_settings.json';
    if (!file_exists($settingsFile)) {
        return;
    }
    
    $settings = json_decode(file_get_contents($settingsFile), true);
    if (!$settings || !isset($settings['scheduledTasks'])) {
        return;
    }
    
    $now = new DateTime();
    $tasksToExecute = [];
    
    // 检查哪些任务需要执行
    $settings['scheduledTasks'] = array_filter($settings['scheduledTasks'], function($task) use ($now, &$tasksToExecute) {
        $taskTime = new DateTime($task['time']);
        if ($taskTime <= $now) {
            $tasksToExecute[] = $task;
            return false; // 过滤掉已执行的任务
        }
        return true;
    });
    
    // 保存更新后的设置
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    
    // 执行任务
    foreach ($tasksToExecute as $task) {
        // 发送聊天消息
        sendChatMessage($task['message']);
    }
}

// 发送聊天消息
function sendChatMessage($message) {
    // 构建聊天命令
    $cmd = " " . $message;
    
    // 执行命令
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $cmd);
    shell_exec("screen -S factorio_server -p 0 -X stuff \"$escaped\n\"");
}

// 执行检查
checkScheduledTasks();

echo 'Tasks checked successfully';
?>