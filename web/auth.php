<?php
/**
 * Factorio Server Pro - 认证和会话管理模块
 * 
 * 功能说明:
 * - 用户登录验证
 * - 会话管理
 * - 权限检查
 *
 * @package FactorioServerPro
 * @version 2.0
 * @author Factorio Server Pro Team
 */

// 启动会话
session_start();

/**
 * 加载配置文件
 * 
 * 从 config.php 加载用户配置，如果文件不存在则使用默认配置
 * 
 * @staticvar array $config 配置缓存
 * @return array 配置数组
 */
function loadConfig() {
    static $config = null;
    
    if ($config === null) {
        $configFile = __DIR__ . '/config.php';
        
        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            // 默认配置（当 config.php 不存在时使用）
            $config = [
                'users' => [
                    'admin' => [
                        'password' => password_hash('password', PASSWORD_DEFAULT),
                        'role' => 'admin',
                        'name' => '管理员'
                    ]
                ],
                'session_expiry' => 86400
            ];
        }
    }
    
    return $config;
}

/**
 * 检查用户是否已登录
 * 
 * 验证会话有效性，包括:
 * - 会话是否存在
 * - 会话是否超时
 * - 更新最后活动时间
 * 
 * @return bool 是否已登录
 */
function isLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $config = loadConfig();
    $expiry = $config['session_expiry'] ?? 86400;
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $expiry) {
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * 要求用户登录（用于受保护的页面/API）
 * 
 * 如果用户未登录，将:
 * - AJAX 请求: 返回 JSON 错误
 * - 普通请求: 重定向到登录页
 * 
 * @return void
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => '请先登录', 'redirect' => 'login.html']);
        } else {
            header('Location: login.html');
        }
        
        exit;
    }
}

/**
 * 处理用户登录
 * 
 * 验证用户名和密码，成功则创建会话
 * 
 * @param string $username 用户名
 * @param string $password 密码（明文）
 * @return array 登录结果 ['success' => bool, 'error' => string, 'user' => array]
 */
function loginUser($username, $password) {
    $config = loadConfig();
    
    if (!isset($config['users'][$username])) {
        return ['success' => false, 'error' => '用户名或密码错误'];
    }
    
    $user = $config['users'][$username];
    
    if (password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $username;
        $_SESSION['user_name'] = $user['name'] ?? $username;
        $_SESSION['user_role'] = $user['role'] ?? 'user';
        $_SESSION['last_activity'] = time();
        
        return [
            'success' => true,
            'user' => [
                'username' => $username,
                'name' => $user['name'] ?? $username
            ]
        ];
    }
    
    return ['success' => false, 'error' => '用户名或密码错误'];
}

/**
 * 处理用户登出
 * 
 * 销毁当前会话
 * 
 * @return array 登出结果 ['success' => bool]
 */
function logoutUser() {
    session_destroy();
    return ['success' => true];
}

/**
 * 获取当前登录用户信息
 * 
 * @return array|null 用户信息或 null（未登录）
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'username' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
        'role' => $_SESSION['user_role'] ?? null
    ];
}
