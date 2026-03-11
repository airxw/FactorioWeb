<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Core\Response;

class AuthController
{
    private $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function login(array $params): void
    {
        $username = $params['username'] ?? '';
        $password = $params['password'] ?? '';

        if (empty($username) || empty($password)) {
            Response::error('请输入用户名和密码');
        }

        $result = $this->authService->login($username, $password);

        if ($result['success']) {
            Response::success($result['user'], '登录成功');
        }

        Response::error($result['error']);
    }

    public function logout(): void
    {
        $this->authService->logout();
        Response::success(null, '已退出登录');
    }

    public function checkAuth(): void
    {
        if ($this->authService->isLoggedIn()) {
            Response::success([
                'logged_in' => true,
                'user' => $this->authService->getCurrentUser()
            ]);
        }

        Response::success([
            'logged_in' => false,
            'user' => null
        ]);
    }

    public function generateHash(array $params): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $password = $params['password'] ?? '';

        if (empty($password)) {
            Response::error('请输入密码');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        Response::success(['hash' => $hash]);
    }

    public function getUserList(): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $users = $this->authService->getUserList();
        Response::success($users);
    }

    public function addUser(array $params): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $result = $this->authService->addUser(
            $params['username'] ?? '',
            $params['password'] ?? '',
            $params['name'] ?? '',
            $params['role'] ?? 'user'
        );

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error']);
    }

    public function deleteUser(array $params): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $username = $params['username'] ?? '';

        if (empty($username)) {
            Response::error('请指定用户名');
        }

        $result = $this->authService->deleteUser($username);

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error']);
    }

    public function updateUser(array $params): void
    {
        $this->authService->requireLogin();

        $currentUser = $this->authService->getCurrentUser();
        $targetUsername = $params['username'] ?? $currentUser['username'];

        if (!$this->authService->isAdmin() && $targetUsername !== $currentUser['username']) {
            Response::forbidden('只能修改自己的信息');
        }

        $result = $this->authService->updateUser($targetUsername, $params);

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error']);
    }

    public function changePassword(array $params): void
    {
        $this->authService->requireLogin();

        $currentUser = $this->authService->getCurrentUser();
        $oldPassword = $params['old_password'] ?? '';
        $newPassword = $params['new_password'] ?? '';

        if (empty($oldPassword) || empty($newPassword)) {
            Response::error('请填写完整信息');
        }

        $config = $this->authService->getConfig();
        $userData = $config['users'][$currentUser['username']] ?? null;

        if (!$userData || !password_verify($oldPassword, $userData['password'])) {
            Response::error('原密码错误');
        }

        $result = $this->authService->updateUser($currentUser['username'], [
            'password' => $newPassword
        ]);

        if ($result['success']) {
            Response::success(null, '密码修改成功');
        }

        Response::error($result['error']);
    }

    public function getUserInfo(): void
    {
        $this->authService->requireLogin();

        $user = $this->authService->getCurrentUser();
        Response::success($user);
    }
}
