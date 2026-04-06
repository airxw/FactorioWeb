<?php

namespace App\Services;

use App\Core\Database;

class VipService
{
    private $db;
    private $cachedLevels = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->db->initialize();
    }

    public function getVipLevelsConfig(): array
    {
        if ($this->cachedLevels !== null) {
            return $this->cachedLevels;
        }

        $rows = $this->db->query('SELECT * FROM vip_levels_config ORDER BY level ASC');
        $levels = [];

        foreach ($rows as $row) {
            $levels[(int)$row['level']] = [
                'name' => $row['name'],
                'dailyLimit' => (int)$row['daily_limit'],
                'maxQuantity' => (int)$row['max_quantity'],
                'maxQuality' => (int)$row['max_quality'],
                'color' => $row['color'],
                'description' => $row['description'],
                'isEnabled' => (bool)$row['is_enabled']
            ];
        }

        if (empty($levels)) {
            return [
                0 => ['name' => '普通', 'dailyLimit' => 5, 'maxQuantity' => 10, 'maxQuality' => 0, 'color' => '#6b7280', 'description' => '', 'isEnabled' => true]
            ];
        }

        $this->cachedLevels = $levels;
        return $levels;
    }

    public function getLevelConfig(int $level): array
    {
        $levels = $this->getVipLevelsConfig();
        return $levels[$level] ?? ($levels[0] ?? ['name' => '普通', 'dailyLimit' => 5, 'maxQuantity' => 10, 'maxQuality' => 0, 'color' => '#6b7280', 'description' => '', 'isEnabled' => true]);
    }

    public function clearCache(): void
    {
        $this->cachedLevels = null;
    }

    public function getVipInfo($userId): array
    {
        if (is_numeric($userId)) {
            $user = $this->db->query('SELECT id, username, vip_level, vip_expiry FROM users WHERE id = :id', [':id' => (int)$userId]);
        } else {
            $user = $this->db->query('SELECT id, username, vip_level, vip_expiry FROM users WHERE username = :username', [':username' => $userId]);
        }

        if (empty($user)) {
            return [
                'success' => false,
                'error' => '用户不存在'
            ];
        }

        $userData = $user[0];
        $vipLevel = (int)($userData['vip_level'] ?? 0);
        $vipExpiry = (int)($userData['vip_expiry'] ?? 0);

        $now = time();
        if ($vipLevel > 0 && $vipExpiry > 0 && $vipExpiry < $now) {
            try {
                $this->db->execute('UPDATE users SET vip_level = 0, vip_expiry = 0 WHERE id = :id', [':id' => $userData['id']]);
            } catch (\Exception $e) {
                error_log("无法更新 VIP 过期状态: " . $e->getMessage());
            }
            $vipLevel = 0;
            $vipExpiry = 0;
        }

        $levelConfig = $this->getLevelConfig($vipLevel);
        $allLevels = $this->getVipLevelsConfig();
        $isVipActive = ($vipLevel > 0 && ($vipExpiry <= 0 || $vipExpiry > $now));

        return [
            'success' => true,
            'data' => [
                'user_id' => $userData['id'],
                'username' => $userData['username'],
                'vip_level' => $vipLevel,
                'vip_name' => $levelConfig['name'],
                'is_active' => $isVipActive,
                'expiry_time' => $vipExpiry > 0 ? date('Y-m-d H:i:s', $vipExpiry) : null,
                'expiry_timestamp' => $vipExpiry,
                'remaining_days' => $vipExpiry > 0 ? max(0, ceil(($vipExpiry - $now) / 86400)) : null,
                'benefits' => [
                    'daily_limit' => $levelConfig['dailyLimit'],
                    'max_quantity' => $levelConfig['maxQuantity'],
                    'max_quality' => $levelConfig['maxQuality']
                ],
                'all_levels' => array_map(function($level, $config) {
                    return [
                        'level' => $level,
                        'name' => $config['name'],
                        'daily_limit' => $config['dailyLimit'],
                        'max_quantity' => $config['maxQuantity'],
                        'max_quality' => $config['maxQuality']
                    ];
                }, array_keys($allLevels), $allLevels)
            ]
        ];
    }

    public function setVipLevel($userId, $level, $expiryDays = 365): array
    {
        $allLevels = $this->getVipLevelsConfig();

        if (!isset($allLevels[$level])) {
            return [
                'success' => false,
                'error' => '无效的 VIP 等级，有效等级为: 0-' . (count($allLevels) - 1)
            ];
        }

        if (is_numeric($userId)) {
            $user = $this->db->query('SELECT id, username, vip_level FROM users WHERE id = :id', [':id' => (int)$userId]);
        } else {
            $user = $this->db->query('SELECT id, username, vip_level FROM users WHERE username = :username', [':username' => $userId]);
        }

        if (empty($user)) {
            return [
                'success' => false,
                'error' => '用户不存在'
            ];
        }

        $userData = $user[0];
        $expiryTime = $level > 0 ? time() + ($expiryDays * 86400) : 0;

        $this->db->execute(
            'UPDATE users SET vip_level = :level, vip_expiry = :expiry, updated_at = :updated WHERE id = :id',
            [
                ':level' => (int)$level,
                ':expiry' => $expiryTime,
                ':updated' => time(),
                ':id' => $userData['id']
            ]
        );

        return [
            'success' => true,
            'message' => "已将用户 {$userData['username']} 的 VIP 等级设置为 " . $allLevels[$level]['name'] . "，有效期 {$expiryDays} 天",
            'data' => [
                'user_id' => $userData['id'],
                'username' => $userData['username'],
                'vip_level' => (int)$level,
                'vip_name' => $allLevels[$level]['name'],
                'expiry_date' => $level > 0 ? date('Y-m-d H:i:s', $expiryTime) : null
            ]
        ];
    }

    public function checkVipPermission($userId, $action = 'purchase'): array
    {
        $info = $this->getVipInfo($userId);

        if (!$info['success']) {
            return [false, $info['error'], []];
        }

        $vipData = $info['data'];
        $vipLevel = $vipData['vip_level'];
        $isActive = $vipData['is_active'];
        $levelConfig = $this->getLevelConfig($vipLevel);

        switch ($action) {
            case 'purchase':
                if ($vipLevel > 0 && !$isActive) {
                    return [false, '您的 VIP 已过期，续费后可享受更多权益', $levelConfig];
                }
                break;

            case 'admin':
                if ($vipLevel < 3) {
                    return [false, '该操作需要黄金及以上等级的 VIP 权限', $levelConfig];
                }
                break;

            case 'premium':
                if ($vipLevel < 1) {
                    return [false, '该操作至少需要青铜会员权限', $levelConfig];
                }
                break;

            default:
                break;
        }

        return [true, '', $levelConfig];
    }

    public function autoRenewVip($userId): array
    {
        if (is_numeric($userId)) {
            $user = $this->db->query('SELECT id, username, vip_level, vip_expiry FROM users WHERE id = :id', [':id' => (int)$userId]);
        } else {
            $user = $this->db->query('SELECT id, username, vip_level, vip_expiry FROM users WHERE username = :username', [':username' => $userId]);
        }

        if (empty($user)) {
            return [
                'success' => false,
                'error' => '用户不存在'
            ];
        }

        $userData = $user[0];
        $currentLevel = (int)($userData['vip_level'] ?? 0);
        $currentExpiry = (int)($userData['vip_expiry'] ?? 0);

        if ($currentLevel <= 0) {
            return [
                'success' => false,
                'error' => '当前用户不是 VIP 会员，无法续期'
            ];
        }

        $renewDays = 30;
        $newExpiry = $currentExpiry > time() ? $currentExpiry + ($renewDays * 86400) : time() + ($renewDays * 86400);

        $this->db->execute(
            'UPDATE users SET vip_expiry = :expiry, updated_at = :updated WHERE id = :id',
            [
                ':expiry' => $newExpiry,
                ':updated' => time(),
                ':id' => $userData['id']
            ]
        );

        $levelConfig = $this->getLevelConfig($currentLevel);

        return [
            'success' => true,
            'message' => "已自动续期 {$renewDays} 天",
            'data' => [
                'user_id' => $userData['id'],
                'username' => $userData['username'],
                'vip_level' => $currentLevel,
                'vip_name' => $levelConfig['name'],
                'old_expiry' => $currentExpiry > 0 ? date('Y-m-d H:i:s', $currentExpiry) : null,
                'new_expiry' => date('Y-m-d H:i:s', $newExpiry),
                'renew_days' => $renewDays
            ]
        ];
    }

    public function getVipUserList($level = null): array
    {
        $params = [];
        $sql = 'SELECT u.id, u.username, u.name, u.vip_level, u.vip_expiry, u.created_at FROM users u';

        if ($level !== null && $level !== '') {
            $sql .= ' WHERE u.vip_level = :level';
            $params[':level'] = (int)$level;
        } else {
            $sql .= ' WHERE u.vip_level > 0';
        }

        $sql .= ' ORDER BY u.vip_level DESC, u.vip_expiry DESC';

        $users = $this->db->query($sql, $params);
        $result = [];

        foreach ($users as $user) {
            $vipLevel = (int)($user['vip_level'] ?? 0);
            $vipExpiry = (int)($user['vip_expiry'] ?? 0);
            $now = time();
            $isExpired = $vipExpiry > 0 && $vipExpiry < $now;
            $levelConfig = $this->getLevelConfig($vipLevel);

            $result[] = [
                'user_id' => $user['id'],
                'username' => $user['username'],
                'display_name' => $user['name'] ?: $user['username'],
                'vip_level' => $vipLevel,
                'vip_name' => $levelConfig['name'],
                'is_expired' => $isExpired,
                'expiry_date' => $vipExpiry > 0 ? date('Y-m-d H:i:s', $vipExpiry) : null,
                'remaining_days' => $vipExpiry > 0 ? max(0, ceil(($vipExpiry - $now) / 86400)) : null,
                'created_at' => $user['created_at'] ? date('Y-m-d H:i:s', $user['created_at']) : null
            ];
        }

        return [
            'success' => true,
            'data' => $result,
            'total' => count($result)
        ];
    }

    public function validatePurchase($userId, $quantity, $quality = 0): array
    {
        $quantity = (int)$quantity;
        $quality = (int)$quality;

        if ($quantity <= 0) {
            return [false, '购买数量必须大于0'];
        }

        if ($quality < 0) {
            return [false, '品质等级不能为负数'];
        }

        list($allowed, $errorMsg, $limits) = $this->checkVipPermission($userId, 'purchase');

        if (!$allowed) {
            return [false, $errorMsg];
        }

        $dailyLimit = $limits['dailyLimit'];
        $maxQuantity = $limits['maxQuantity'];
        $maxQuality = $limits['maxQuality'];

        $todayStart = strtotime(date('Y-m-d') . ' 00:00:00');

        if (is_numeric($userId)) {
            $todayOrders = $this->db->query(
                'SELECT COUNT(*) as cnt, SUM(quantity) as total_qty FROM orders WHERE user_id = :uid AND created_at >= :start AND status != :status',
                [':uid' => (int)$userId, ':start' => $todayStart, ':status' => 'cancelled']
            );
        } else {
            $userRecord = $this->db->query('SELECT id FROM users WHERE username = :username', [':username' => $userId]);
            if (empty($userRecord)) {
                return [false, '用户不存在'];
            }
            $todayOrders = $this->db->query(
                'SELECT COUNT(*) as cnt, SUM(quantity) as total_qty FROM orders WHERE user_id = :uid AND created_at >= :start AND status != :status',
                [':uid' => $userRecord[0]['id'], ':start' => $todayStart, ':status' => 'cancelled']
            );
        }

        $todayCount = (int)($todayOrders[0]['cnt'] ?? 0);
        $todayTotalQty = (int)($todayOrders[0]['total_qty'] ?? 0);

        if ($todayCount >= $dailyLimit) {
            return [false, "今日购买次数已达上限（{$dailyLimit}次），请明天再试"];
        }

        if ($quantity > $maxQuantity) {
            return [false, "单次购买数量超过限制（最大 {$maxQuantity} 个）"];
        }

        if ($quality > $maxQuality) {
            $qualityNames = ['普通', '优质', '稀有', '史诗', '传说'];
            $requiredName = isset($qualityNames[$maxQuality]) ? $qualityNames[$maxQuality] : "等级{$maxQuality}";
            return [false, "品质等级过高，您的 VIP 等级最高支持 {$requiredName} 品质"];
        }

        return [true, '', [
            'remaining_daily_count' => $dailyLimit - $todayCount,
            'today_used_count' => $todayCount,
            'max_quantity' => $maxQuantity,
            'max_quality' => $maxQuality
        ]];
    }

    public function updateVipLevelConfig(int $level, array $data): array
    {
        $existing = $this->db->query('SELECT level FROM vip_levels_config WHERE level = :level', [':level' => $level]);

        if (empty($existing)) {
            return ['success' => false, 'error' => 'VIP 等级不存在'];
        }

        try {
            $fields = [];
            $params = [':level' => $level];

            $allowedFields = ['name', 'daily_limit', 'max_quantity', 'max_quality', 'color', 'description'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }

            if (!empty($fields)) {
                $fields[] = 'updated_at = :updated';
                $params[':updated'] = time();

                $sql = "UPDATE vip_levels_config SET " . implode(', ', $fields) . " WHERE level = :level";
                $this->db->execute($sql, $params);
            }

            $this->clearCache();

            return ['success' => true, 'message' => "VIP 等级 {$level} 配置更新成功"];
        } catch (\Exception $e) {
            error_log("更新 VIP 配置失败: " . $e->getMessage());
            return ['success' => false, 'error' => '配置更新失败: ' . $e->getMessage()];
        }
    }

    public function recordVipChange(int $userId, int $oldLevel, int $newLevel, string $reason = 'admin_set', ?int $adminId = null): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vip_records (user_id, old_level, new_level, reason, operated_by, created_at) VALUES (:userId, :oldLevel, :newLevel, :reason, :adminId, :created)',
                [
                    ':userId' => $userId,
                    ':oldLevel' => $oldLevel,
                    ':newLevel' => $newLevel,
                    ':reason' => $reason,
                    ':adminId' => $adminId,
                    ':created' => time()
                ]
            );
        } catch (\Exception $e) {
            error_log("VIP 记录写入失败: " . $e->getMessage());
        }
    }

    public function getVipHistory(int $userId, int $limit = 20): array
    {
        try {
            return $this->db->query(
                'SELECT vr.*, u.name as admin_name FROM vip_records vr LEFT JOIN users u ON u.id = vr.operated_by WHERE vr.user_id = :userId ORDER BY vr.created_at DESC LIMIT :limit',
                [':userId' => $userId, ':limit' => $limit]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    public function revertVipLevel(int $userId, int $recordId): array
    {
        $record = $this->db->query(
            'SELECT * FROM vip_records WHERE id = :id AND user_id = :userId',
            [':id' => $recordId, ':userId' => $userId]
        );

        if (empty($record)) {
            return ['success' => false, 'error' => '记录不存在'];
        }

        $targetLevel = (int)$record[0]['old_level'];
        $currentInfo = $this->getVipInfo($userId);

        if (!$currentInfo['success']) {
            return ['success' => false, 'error' => '用户信息获取失败'];
        }

        $this->setVipLevel($userId, $targetLevel, 365, 'revert');
        $this->recordVipChange($userId, $currentInfo['data']['vip_level'], $targetLevel, 'revert', null);

        return ['success' => true, 'message' => "已回滚到 VIP{$targetLevel}"];
    }
}
