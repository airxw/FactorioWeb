<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\VipService;
use App\Core\Response;

class VipController
{
    private $vipService;
    private $authService;

    public function __construct()
    {
        $this->vipService = new VipService();
        $this->authService = new AuthService();
    }

    public function handleVipInfo(): void
    {
        if ($this->authService->isLoggedIn()) {
            $currentUser = $this->authService->getCurrentUser();

            $userId = $currentUser['username'] ?? $currentUser['id'] ?? $_SESSION['user_id'] ?? null;

            if (!empty($userId)) {
                $result = $this->vipService->getVipInfo($userId);

                if ($result['success']) {
                    Response::success($result['data'], '获取 VIP 信息成功');
                    return;
                }
            }

            Response::success([
                'user_id' => 0,
                'username' => $currentUser['name'] ?? '',
                'vip_level' => 0,
                'vip_name' => '普通',
                'is_active' => false,
                'expiry_time' => null,
                'expiry_timestamp' => 0,
                'remaining_days' => null,
                'benefits' => [
                    'daily_limit' => 5,
                    'max_quantity' => 10,
                    'max_quality' => 0
                ],
                'all_levels' => $this->vipService->getVipLevelsConfig(),
                'logged_in' => true
            ], '获取 VIP 信息成功（用户数据未同步）');
        } else {
            Response::success([
                'user_id' => 0,
                'username' => '',
                'vip_level' => 0,
                'vip_name' => '普通',
                'is_active' => false,
                'expiry_time' => null,
                'expiry_timestamp' => 0,
                'remaining_days' => null,
                'benefits' => [
                    'daily_limit' => 5,
                    'max_quantity' => 10,
                    'max_quality' => 0
                ],
                'all_levels' => $this->vipService->getVipLevelsConfig(),
                'logged_in' => false
            ], '获取 VIP 信息成功（未登录）');
        }
    }

    public function handleSetVip(): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $username = $_POST['username'] ?? '';
        $level = $_POST['level'] ?? '';
        $expiryDays = $_POST['expiry_days'] ?? 365;

        if (empty($username)) {
            Response::error('请指定用户名');
        }

        $allLevels = $this->vipService->getVipLevelsConfig();
        if (!is_numeric($level) || !isset($allLevels[(int)$level])) {
            Response::error('无效的 VIP 等级，有效等级: 0(普通) 1(青铜) 2(白银) 3(黄金) 4(钻石)');
        }

        if (!is_numeric($expiryDays) || $expiryDays < 0) {
            Response::error('有效天数必须为非负整数');
        }

        $result = $this->vipService->setVipLevel($username, (int)$level, (int)$expiryDays);

        if ($result['success']) {
            Response::success($result['data'], $result['message']);
        }

        Response::error($result['error']);
    }

    public function handleSetVipExpiry(): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $username = $_POST['username'] ?? '';
        $expiryDate = $_POST['expiry_date'] ?? '';

        if (empty($username)) {
            Response::error('请指定用户名');
        }

        if (empty($expiryDate)) {
            Response::error('请指定到期时间（格式：Y-m-d H:i:s）');
        }

        $timestamp = strtotime($expiryDate);
        if ($timestamp === false) {
            Response::error('日期格式无效，请使用 Y-m-d H:i:s 格式');
        }

        $info = $this->vipService->getVipInfo($username);

        if (!$info['success']) {
            Response::error($info['error']);
        }

        $currentLevel = $info['data']['vip_level'];
        $db = \App\Core\Database::getInstance();

        if ($currentLevel <= 0) {
            Response::error('该用户当前不是 VIP 会员，请先设置 VIP 等级');
        }

        $db->execute(
            'UPDATE users SET vip_expiry = :expiry, updated_at = :updated WHERE username = :username',
            [
                ':expiry' => $timestamp,
                ':updated' => time(),
                ':username' => $username
            ]
        );

        Response::success([
            'username' => $username,
            'new_expiry' => date('Y-m-d H:i:s', $timestamp),
            'new_expiry_timestamp' => $timestamp
        ], "已更新用户 {$username} 的 VIP 到期时间为 {$expiryDate}");
    }

    public function handleVipList(): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $level = $_GET['level'] ?? null;
        $result = $this->vipService->getVipUserList($level);

        if ($result['success']) {
            Response::success([
                'users' => $result['data'],
                'total' => $result['total']
            ], '获取 VIP 用户列表成功');
        }

        Response::error($result['error'] ?? '获取失败');
    }

    public function handleGetVipConfig(): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $levels = $this->vipService->getVipLevelsConfig();

        $levelsArray = [];
        foreach ($levels as $level => $config) {
            $config['level'] = (int)$level;
            $levelsArray[] = $config;
        }

        Response::success([
            'levels' => $levelsArray
        ], '获取 VIP 配置成功');
    }

    public function handleUpdateVipConfig(): void
    {
        $this->authService->requireLogin();

        if (!$this->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $level = (int)($_POST['level'] ?? -1);
        $data = [];

        if (isset($_POST['name'])) {
            $data['name'] = trim($_POST['name']);
        }
        if (isset($_POST['daily_limit'])) {
            $data['daily_limit'] = max(0, (int)$_POST['daily_limit']);
        }
        if (isset($_POST['max_quantity'])) {
            $data['max_quantity'] = max(0, (int)$_POST['max_quantity']);
        }
        if (isset($_POST['max_quality'])) {
            $data['max_quality'] = max(0, min(4, (int)$_POST['max_quality']));
        }
        if (isset($_POST['color'])) {
            $data['color'] = trim($_POST['color']);
        }
        if (isset($_POST['description'])) {
            $data['description'] = trim($_POST['description']);
        }

        if ($level < 0 || empty($data)) {
            Response::error('参数无效：需要指定等级和至少一个配置项');
        }

        $result = $this->vipService->updateVipLevelConfig($level, $data);

        if ($result['success']) {
            Response::success([
                'level' => $level,
                'updated_fields' => array_keys($data)
            ], $result['message']);
        }

        Response::error($result['error'] ?? '更新失败');
    }
}
