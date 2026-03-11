<?php
// 聊天设置管理 API
// 用于服务端存储和管理定时发送任务和条件触发响应

header('Content-Type: application/json; charset=utf-8');

define('SETTINGS_FILE', __DIR__ . '/../config/state/chatSettings.json');

// 初始化设置文件
function initSettingsFile() {
    if (!file_exists(SETTINGS_FILE)) {
        $defaultSettings = [
            'scheduledTasks' => [],
            'triggerResponses' => [],
            'serverResponses' => [],
            'playerEvents' => [
                'welcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'goodbye' => ['enabled' => false, 'message' => '', 'type' => 'public']
            ]
        ];
        file_put_contents(SETTINGS_FILE, json_encode($defaultSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

// 读取设置
function readSettings() {
    initSettingsFile();
    $content = file_get_contents(SETTINGS_FILE);
    $settings = json_decode($content, true);
    
    // 确保所有必需的字段都存在
    if (!isset($settings['serverResponses'])) {
        $settings['serverResponses'] = [];
    }
    if (!isset($settings['playerEvents'])) {
        $settings['playerEvents'] = [
            'welcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
            'goodbye' => ['enabled' => false, 'message' => '', 'type' => 'public']
        ];
    }
    
    return $settings;
}

// 保存设置
function saveSettings($settings) {
    file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 处理获取设置请求
function handleGetSettings() {
    $settings = readSettings();
    echo json_encode($settings);
}

// 处理保存设置请求
function handleSaveSettings() {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data) {
        $currentSettings = readSettings();
        
        // 更新定时任务
        if (isset($data['scheduledTasks'])) {
            $currentSettings['scheduledTasks'] = $data['scheduledTasks'];
        }
        
        // 更新触发响应
        if (isset($data['triggerResponses'])) {
            $currentSettings['triggerResponses'] = $data['triggerResponses'];
        }
        
        // 更新服务器响应
        if (isset($data['serverResponses'])) {
            $currentSettings['serverResponses'] = $data['serverResponses'];
        }
        
        // 更新玩家事件
        if (isset($data['playerEvents'])) {
            $currentSettings['playerEvents'] = $data['playerEvents'];
        }
        
        saveSettings($currentSettings);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
    }
}

// 处理添加定时任务请求
function handleAddScheduledTask() {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['message']) && isset($data['time'])) {
        $settings = readSettings();
        
        // 添加新任务
        $settings['scheduledTasks'][] = [
            'id' => uniqid(),
            'message' => $data['message'],
            'time' => $data['time'],
            'type' => $data['type'] ?? 'public'
        ];
        
        saveSettings($settings);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
    }
}

// 处理添加触发响应请求
function handleAddTriggerResponse() {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['keyword']) && isset($data['response'])) {
        $settings = readSettings();
        
        // 添加新触发响应
        $settings['triggerResponses'][] = [
            'id' => uniqid(),
            'keyword' => $data['keyword'],
            'response' => $data['response'],
            'type' => $data['type'] ?? 'public'
        ];
        
        saveSettings($settings);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
    }
}

// 处理删除触发响应请求
function handleDeleteTriggerResponse() {
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data && isset($data['id'])) {
        $settings = readSettings();
        
        // 过滤掉要删除的响应
        $settings['triggerResponses'] = array_filter($settings['triggerResponses'], function($item) use ($data) {
            return $item['id'] != $data['id'];
        });
        
        saveSettings($settings);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
    }
}

// 处理检查定时任务请求
function handleCheckScheduledTasks() {
    $settings = readSettings();
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
    
    // 重新索引数组
    $settings['scheduledTasks'] = array_values($settings['scheduledTasks']);
    
    // 保存更新后的设置
    saveSettings($settings);
    
    // 返回需要执行的任务
    echo json_encode(['tasks' => $tasksToExecute]);
}

// 路由处理
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        handleGetSettings();
        break;
    case 'save':
        handleSaveSettings();
        break;
    case 'addTask':
        handleAddScheduledTask();
        break;
    case 'addTrigger':
        handleAddTriggerResponse();
        break;
    case 'deleteTrigger':
        handleDeleteTriggerResponse();
        break;
    case 'checkTasks':
        handleCheckScheduledTasks();
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>
