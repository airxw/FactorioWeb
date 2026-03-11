<?php
// 发送聊天消息到Factorio服务器

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = $_POST['message'];
    $messageType = $_POST['type'] ?? 'public'; // 默认为公共消息
    
    // 根据消息类型构建不同的命令
    if ($messageType === 'public') {
        // 发送公共聊天，对所有玩家可见
        $cmd = " " . $message;
    } else {
        echo 'error';
        exit;
    }
    
    // 执行命令，使用与发送物品相同的方式
    function executeConsoleCommand($command) {
        // 转义命令中的特殊字符
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $command);
        
        // 使用screen命令将命令注入到Factorio服务器会话
        $result = shell_exec("screen -S factorio_server -p 0 -X stuff \"$escaped\n\"");
        
        return $result !== false;
    }
    
    // 执行聊天命令
    $result = executeConsoleCommand($cmd);
    
    if ($result) {
        echo 'success';
    } else {
        echo 'error';
    }
} else {
    echo 'error';
}
?>