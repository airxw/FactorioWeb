<?php

namespace App\Services;

use App\Core\Database;

class UserService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->db->initialize();
    }

    public function register(string $username, string $password, string $name = ''): array
    {
        if (empty($username) || empty($password)) {
            return ['success' => false, 'error' => '用户名和密码不能为空'];
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return ['success' => false, 'error' => '用户名需要3-20位字母、数字或下划线'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => '密码长度至少6位'];
        }

        $existing = $this->db->query(
            'SELECT id FROM users WHERE username = :username',
            [':username' => $username]
        );

        if (!empty($existing)) {
            return ['success' => false, 'error' => '用户名已存在'];
        }

        $now = time();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->db->execute(
            'INSERT INTO users (username, password_hash, role, name, vip_level, created_at, updated_at) 
             VALUES (:username, :password_hash, :role, :name, :vip_level, :created_at, :updated_at)',
            [
                ':username'     => $username,
                ':password_hash'=> $passwordHash,
                ':role'         => 'user',
                ':name'         => $name ?: $username,
                ':vip_level'    => 0,
                ':created_at'   => $now,
                ':updated_at'   => $now
            ]
        );

        $userId = $this->db->lastInsertId();

        return [
            'success' => true,
            'message' => '注册成功',
            'user_id' => (int)$userId
        ];
    }

    public function getUserById(int $id): ?array
    {
        $result = $this->db->query(
            'SELECT id, username, role, name, vip_level, vip_expiry, created_at, updated_at FROM users WHERE id = :id',
            [':id' => $id]
        );

        return $result[0] ?? null;
    }

    public function getUserByUsername(string $username): ?array
    {
        $result = $this->db->query(
            'SELECT id, username, password_hash, role, name, vip_level, vip_expiry, created_at, updated_at FROM users WHERE username = :username',
            [':username' => $username]
        );

        return $result[0] ?? null;
    }

    public function updateProfile(int $userId, array $data): array
    {
        $user = $this->getUserById($userId);

        if (!$user) {
            return ['success' => false, 'error' => '用户不存在'];
        }

        $fields = [];
        $params = [':id' => $userId];

        if (isset($data['name']) && trim($data['name']) !== '') {
            $fields[] = 'name = :name';
            $params[':name'] = trim($data['name']);
        }

        if (isset($data['role']) && in_array($data['role'], ['admin', 'user'])) {
            $fields[] = 'role = :role';
            $params[':role'] = $data['role'];
        }

        if (empty($fields)) {
            return ['success' => false, 'error' => '没有需要更新的字段'];
        }

        $fields[] = 'updated_at = :updated_at';
        $params[':updated_at'] = time();

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $this->db->execute($sql, $params);

        return ['success' => true, 'message' => '个人信息更新成功'];
    }

    public function changePassword(int $userId, string $oldPassword, string $newPassword): array
    {
        $user = $this->getUserById($userId);

        if (!$user) {
            return ['success' => false, 'error' => '用户不存在'];
        }

        $userWithPassword = $this->db->query(
            'SELECT password_hash, password_version FROM users WHERE id = :id',
            [':id' => $userId]
        );

        if (empty($userWithPassword)) {
            return ['success' => false, 'error' => '用户不存在'];
        }

        $currentHash = $userWithPassword[0]['password_hash'];

        if (!password_verify($oldPassword, $currentHash)) {
            return ['success' => false, 'error' => '原密码错误'];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => '新密码长度至少6位'];
        }

        $newVersion = ($userWithPassword[0]['password_version'] ?? 0) + 1;
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->execute(
            'UPDATE users SET password_hash = :password_hash, password_version = :password_version, updated_at = :updated_at WHERE id = :id',
            [
                ':password_hash'   => $newHash,
                ':password_version' => $newVersion,
                ':updated_at'       => time(),
                ':id'              => $userId
            ]
        );

        return ['success' => true, 'message' => '密码修改成功'];
    }

    public function recordLogin(int $userId, string $ip = ''): void
    {
        $this->db->execute(
            'UPDATE users SET last_login_ip = :ip, login_count = COALESCE(login_count, 0) + 1, updated_at = :updated WHERE id = :id',
            [':ip' => $ip ?: null, ':updated' => time(), ':id' => $userId]
        );
    }

    public function getPlayerProfile(int $userId): array
    {
        $user = $this->db->query(
            'SELECT u.*, pb.player_name, pb.status as binding_status FROM users u LEFT JOIN player_bindings pb ON pb.user_id = u.id AND pb.status = \'active\' WHERE u.id = :id',
            [':id' => $userId]
        );

        if (empty($user)) {
            return [];
        }

        $userData = $user[0];
        $playerName = $userData['player_name'] ?? null;

        $history = [];
        if ($playerName) {
            $history = $this->db->query(
                'SELECT * FROM player_histories WHERE player_name = :player ORDER BY last_join_time DESC LIMIT 5',
                [':player' => $playerName]
            );
        }

        $orderStats = $this->db->query(
            'SELECT COUNT(*) as total, SUM(CASE WHEN status = \'delivered\' THEN 1 ELSE 0 END) as delivered FROM orders WHERE user_id = :id',
            [':id' => $userId]
        )[0];

        return array_merge($userData, [
            'player_history' => $history,
            'order_stats' => $orderStats
        ]);
    }
}
