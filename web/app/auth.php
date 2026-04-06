<?php

session_start();

function loadConfig() {
    static $config = null;
    if ($config === null) {
        $configFile = dirname(__DIR__) . '/config/system/auth.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            $config = ['session_expiry' => 86400];
        }
    }
    return $config;
}

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

function requireLogin() {
    if (!isLoggedIn()) {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $isApiRequest = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
        $hasAction = isset($_REQUEST['action']);
        if ($isAjax || $isApiRequest || $hasAction) {
            header('Content-Type: application/json');
            echo json_encode(['error' => '请先登录', 'redirect' => 'login.html']);
        } else {
            header('Location: login.html');
        }
        exit;
    }
}

function loginUser($username, $password) {
    try {
        $db = \App\Core\Database::getInstance();
        $db->initialize();
        $result = $db->query('SELECT id, username, password_hash, role, name, vip_level FROM users WHERE username = :username', [':username' => $username]);
        if (!empty($result)) {
            $dbUser = $result[0];
            if (password_verify($password, $dbUser['password_hash'])) {
                $_SESSION['user_id'] = (int)$dbUser['id'];
                $_SESSION['username'] = $dbUser['username'];
                $_SESSION['user_name'] = $dbUser['name'] ?? $username;
                $_SESSION['user_role'] = $dbUser['role'] ?? 'user';
                $_SESSION['vip_level'] = (int)($dbUser['vip_level'] ?? 0);
                $_SESSION['last_activity'] = time();
                return ['success' => true, 'user' => ['id' => (int)$dbUser['id'], 'username' => $dbUser['username'], 'name' => $dbUser['name'] ?? $username, 'role' => $dbUser['role'] ?? 'user', 'vip_level' => (int)($dbUser['vip_level'] ?? 0)]];
            }
        }
    } catch (\Exception $e) {}
    return ['success' => false, 'error' => '用户名或密码错误'];
}

function logoutUser() {
    session_destroy();
    return ['success' => true];
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    return ['id' => $_SESSION['user_id'] ?? null, 'username' => $_SESSION['username'] ?? null, 'name' => $_SESSION['user_name'] ?? null, 'role' => $_SESSION['user_role'] ?? null, 'vip_level' => $_SESSION['vip_level'] ?? 0];
}

function isAdmin() {
    if (!isLoggedIn()) {
        return false;
    }
    return ($_SESSION['user_role'] ?? '') === 'admin';
}
