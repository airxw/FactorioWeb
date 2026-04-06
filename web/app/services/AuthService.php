<?php

namespace App\Services;

use App\Core\Database;

class AuthService
{
    private $config;
    private $db;

    public function __construct(string $configFile = null)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $cfgFile = $configFile ?? dirname(__DIR__, 3) . '/config/system/auth.php';
        if (file_exists($cfgFile)) {
            $this->config = require $cfgFile;
        } else {
            $this->config = ['session_expiry' => 86400];
        }

        try {
            $this->db = Database::getInstance();
            $this->db->initialize();
        } catch (\Exception $e) {
            $this->db = null;
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isLoggedIn(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        $expiry = $this->config['session_expiry'] ?? 86400;

        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $expiry) {
            session_destroy();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                      strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['error' => '请先登录', 'redirect' => 'public/pages/login.html']);
            } else {
                header('Location: public/pages/login.html');
            }

            exit;
        }
    }

    public function login(string $username, string $password): array
    {
        if ($this->db === null) {
            return ['success' => false, 'error' => '数据库不可用'];
        }

        $result = $this->db->query(
            'SELECT id, username, password_hash, role, name, vip_level, vip_expiry FROM users WHERE username = :username',
            [':username' => $username]
        );

        if (empty($result)) {
            return ['success' => false, 'error' => '用户名或密码错误'];
        }

        $dbUser = $result[0];

        if (!password_verify($password, $dbUser['password_hash'])) {
            return ['success' => false, 'error' => '用户名或密码错误'];
        }

        $_SESSION['user_id'] = (int)$dbUser['id'];
        $_SESSION['username'] = $dbUser['username'];
        $_SESSION['user_name'] = $dbUser['name'] ?? $username;
        $_SESSION['user_role'] = $dbUser['role'] ?? 'user';
        $_SESSION['vip_level'] = (int)($dbUser['vip_level'] ?? 0);
        $_SESSION['last_activity'] = time();

        $this->recordLogin((int)$dbUser['id']);
        $this->autoRenewVip((int)$dbUser['id']);

        return [
            'success' => true,
            'user' => [
                'id'          => (int)$dbUser['id'],
                'username'    => $dbUser['username'],
                'name'        => $dbUser['name'] ?? $username,
                'role'        => $dbUser['role'] ?? 'user',
                'vip_level'   => (int)($dbUser['vip_level'] ?? 0)
            ]
        ];
    }

    public function logout(): array
    {
        session_destroy();
        return ['success' => true];
    }

    public function getCurrentUser(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return [
            'id'       => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'name'     => $_SESSION['user_name'] ?? null,
            'role'     => $_SESSION['user_role'] ?? null,
            'vip_level'=> $_SESSION['vip_level'] ?? 0
        ];
    }

    public function isAdmin(): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        return ($_SESSION['user_role'] ?? '') === 'admin';
    }

    public function getUserList(): array
    {
        if ($this->db === null) {
            return [];
        }

        $rows = $this->db->query('SELECT id, username, name, role, vip_level, password_hash FROM users ORDER BY id');
        $users = [];
        foreach ($rows as $row) {
            $users[] = [
                'id'           => (int)$row['id'],
                'username'     => $row['username'],
                'name'         => $row['name'] ?? $row['username'],
                'role'         => $row['role'] ?? 'user',
                'has_password' => !empty($row['password_hash'])
            ];
        }
        return $users;
    }

    public function addUser(string $username, string $password, string $name = '', string $role = 'user'): array
    {
        if ($this->db === null) {
            return ['success' => false, 'error' => '数据库不可用'];
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return ['success' => false, 'error' => '用户名需要3-20位字母、数字或下划线'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => '密码长度至少6位'];
        }

        if (!in_array($role, ['admin', 'user'])) {
            $role = 'user';
        }

        $existing = $this->db->query('SELECT id FROM users WHERE username = ?', [$username]);
        if (!empty($existing)) {
            return ['success' => false, 'error' => '用户名已存在'];
        }

        $this->db->execute(
            'INSERT INTO users (username, password_hash, role, name, vip_level, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $role, $name ?: $username, time(), time()]
        );

        return ['success' => true, 'message' => '用户添加成功'];
    }

    public function deleteUser(string $username): array
    {
        if ($this->db === null) {
            return ['success' => false, 'error' => '数据库不可用'];
        }

        $currentUser = $_SESSION['username'] ?? '';

        if ($username === $currentUser) {
            return ['success' => false, 'error' => '不能删除当前登录用户'];
        }

        $result = $this->db->execute('DELETE FROM users WHERE username = ?', [$username]);
        if ($result > 0) {
            return ['success' => true, 'message' => '用户已删除'];
        }

        return ['success' => false, 'error' => '用户不存在'];
    }

    public function updateUser(string $username, array $data): array
    {
        if ($this->db === null) {
            return ['success' => false, 'error' => '数据库不可用'];
        }

        $existing = $this->db->query('SELECT id FROM users WHERE username = ?', [$username]);
        if (empty($existing)) {
            return ['success' => false, 'error' => '用户不存在'];
        }

        if (!empty($data['name'])) {
            $this->db->execute('UPDATE users SET name = ?, updated_at = ? WHERE username = ?', [$data['name'], time(), $username]);
        }

        if (in_array($data['role'] ?? '', ['admin', 'user'])) {
            $this->db->execute('UPDATE users SET role = ?, updated_at = ? WHERE username = ?', [$data['role'], time(), $username]);
        }

        if (!empty($data['password']) && strlen($data['password']) >= 6) {
            $this->db->execute('UPDATE users SET password_hash = ?, updated_at = ? WHERE username = ?', [password_hash($data['password'], PASSWORD_DEFAULT), time(), $username]);
        }

        return ['success' => true, 'message' => '用户信息已更新'];
    }

    private function recordLogin(int $userId): void
    {
        if ($this->db === null) return;

        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->db->execute(
                'UPDATE users SET last_login_ip = :ip, login_count = COALESCE(login_count, 0) + 1, updated_at = :ua WHERE id = :id',
                [':ip' => $ip ?: null, ':ua' => time(), ':id' => $userId]
            );
        } catch (\Exception $e) {}
    }

    public function autoRenewVip(int $userId): void
    {
        if ($this->db === null) {
            return;
        }

        $result = $this->db->query(
            'SELECT vip_level, vip_expiry FROM users WHERE id = :id',
            [':id' => $userId]
        );

        if (empty($result)) {
            return;
        }

        $user = $result[0];
        $vipLevel = (int)$user['vip_level'];
        $vipExpiry = (int)($user['vip_expiry'] ?? 0);

        if ($vipLevel <= 0 || $vipExpiry <= 0) {
            return;
        }

        $now = time();

        if ($vipExpiry <= $now) {
            return;
        }

        $daysUntilExpiry = ($vipExpiry - $now) / 86400;

        if ($daysUntilExpiry < 7 && $daysUntilExpiry > 0) {
            $newExpiry = $vipExpiry + (30 * 86400);

            $this->db->execute(
                'UPDATE users SET vip_expiry = :vip_expiry, updated_at = :updated_at WHERE id = :id',
                [
                    ':vip_expiry' => $newExpiry,
                    ':updated_at' => $now,
                    ':id'         => $userId
                ]
            );
        }
    }
}
