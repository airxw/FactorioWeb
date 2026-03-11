<?php

namespace App\Services;

class AuthService
{
    private $config;
    private $configFile;

    public function __construct(string $configFile = null)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->configFile = $configFile ?? dirname(__DIR__, 3) . '/config/auth.php';
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        if (file_exists($this->configFile)) {
            $this->config = require $this->configFile;
        } else {
            $this->config = [
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
        if (!isset($this->config['users'][$username])) {
            return ['success' => false, 'error' => '用户名或密码错误'];
        }
        
        $user = $this->config['users'][$username];
        
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
            'username' => $_SESSION['user_id'] ?? null,
            'name' => $_SESSION['user_name'] ?? null,
            'role' => $_SESSION['user_role'] ?? null
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
        $users = [];
        foreach ($this->config['users'] ?? [] as $username => $userData) {
            $users[] = [
                'username' => $username,
                'name' => $userData['name'] ?? $username,
                'role' => $userData['role'] ?? 'user',
                'has_password' => !empty($userData['password'])
            ];
        }
        return $users;
    }

    public function addUser(string $username, string $password, string $name = '', string $role = 'user'): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return ['success' => false, 'error' => '用户名需要3-20位字母、数字或下划线'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => '密码长度至少6位'];
        }

        if (!in_array($role, ['admin', 'user'])) {
            $role = 'user';
        }

        if (isset($this->config['users'][$username])) {
            return ['success' => false, 'error' => '用户名已存在'];
        }

        $this->config['users'][$username] = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'name' => $name ?: $username
        ];

        if ($this->saveConfig()) {
            return ['success' => true, 'message' => '用户添加成功'];
        }

        return ['success' => false, 'error' => '保存配置失败'];
    }

    public function deleteUser(string $username): array
    {
        $currentUser = $_SESSION['user_id'] ?? '';
        
        if ($username === $currentUser) {
            return ['success' => false, 'error' => '不能删除当前登录用户'];
        }

        if (!isset($this->config['users'][$username])) {
            return ['success' => false, 'error' => '用户不存在'];
        }

        unset($this->config['users'][$username]);

        if ($this->saveConfig()) {
            return ['success' => true, 'message' => '用户已删除'];
        }

        return ['success' => false, 'error' => '保存配置失败'];
    }

    public function updateUser(string $username, array $data): array
    {
        if (!isset($this->config['users'][$username])) {
            return ['success' => false, 'error' => '用户不存在'];
        }

        if (!empty($data['name'])) {
            $this->config['users'][$username]['name'] = $data['name'];
        }

        if (in_array($data['role'] ?? '', ['admin', 'user'])) {
            $this->config['users'][$username]['role'] = $data['role'];
        }

        if (!empty($data['password']) && strlen($data['password']) >= 6) {
            $this->config['users'][$username]['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if ($this->saveConfig()) {
            return ['success' => true, 'message' => '用户信息已更新'];
        }

        return ['success' => false, 'error' => '保存配置失败'];
    }

    private function saveConfig(): bool
    {
        $configContent = "<?php\n\nreturn [\n";
        $configContent .= "    'users' => [\n";
        foreach ($this->config['users'] ?? [] as $username => $userData) {
            $configContent .= "        '$username' => [\n";
            $configContent .= "            'password' => '" . addslashes($userData['password'] ?? '') . "',\n";
            $configContent .= "            'role' => '" . ($userData['role'] ?? 'user') . "',\n";
            $configContent .= "            'name' => '" . addslashes($userData['name'] ?? $username) . "',\n";
            $configContent .= "        ],\n";
        }
        $configContent .= "    ],\n";
        $configContent .= "    'session_expiry' => " . ($this->config['session_expiry'] ?? 86400) . "\n";
        $configContent .= "];\n";

        return file_put_contents($this->configFile, $configContent) !== false;
    }
}
