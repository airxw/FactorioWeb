<?php

namespace App\Controllers;

use App\Services\UserService;
use App\Services\AuthService;
use App\Core\Response;

class UserController
{
    public static function handleRegister(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $name = trim($_POST['name'] ?? '');

        if (empty($username) || empty($password)) {
            Response::error('用户名和密码不能为空');
        }

        $userService = new UserService();
        $result = $userService->register($username, $password, $name);

        if ($result['success']) {
            Response::success([
                'user_id'  => $result['user_id'],
                'username' => $username
            ], $result['message']);
        }

        Response::error($result['error']);
    }

    public static function handleUpdatePassword(): void
    {
        $authService = new AuthService();
        $authService->requireLogin();

        $userId = (int)($_SESSION['user_id'] ?? 0);
        
        if (!is_numeric($_SESSION['user_id'])) {
            Response::error('当前用户不支持数据库密码修改，请使用配置文件方式修改');
        }

        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';

        if (empty($oldPassword) || empty($newPassword)) {
            Response::error('请填写完整信息');
        }

        if (strlen($newPassword) < 6) {
            Response::error('新密码长度至少6位');
        }

        $userService = new UserService();
        $result = $userService->changePassword($userId, $oldPassword, $newPassword);

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error']);
    }

    public static function handleUserInfo(): void
    {
        $authService = new AuthService();

        if (!$authService->isLoggedIn()) {
            Response::unauthorized('请先登录');
        }

        $currentUser = $authService->getCurrentUser();

        $userInfo = [
            'id'          => null,
            'username'    => $currentUser['username'] ?? null,
            'name'        => $currentUser['name'] ?? null,
            'role'        => $currentUser['role'] ?? null,
            'vip_level'   => 0,
            'vip_expiry'  => null
        ];

        $sessionUserId = $_SESSION['user_id'] ?? null;
        
        if ($sessionUserId !== null && is_numeric($sessionUserId)) {
            $userService = new UserService();
            $dbUser = $userService->getUserById((int)$sessionUserId);

            if ($dbUser) {
                $userInfo['id']         = $dbUser['id'];
                $userInfo['username']   = $dbUser['username'];
                $userInfo['name']       = $dbUser['name'];
                $userInfo['role']       = $dbUser['role'];
                $userInfo['vip_level']  = (int)$dbUser['vip_level'];
                $userInfo['vip_expiry'] = $dbUser['vip_expiry'];
            }
        } else {
            $userInfo['vip_level'] = (int)($_SESSION['vip_level'] ?? 0);
        }

        Response::success($userInfo);
    }
}
