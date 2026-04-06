<?php

require_once __DIR__ . '/core/Database.php';

use App\Core\Database;

echo "========================================\n";
echo "  Factorio 数据迁移脚本\n";
echo "  将 JSON 状态数据迁移到 SQLite 数据库\n";
echo "========================================\n\n";

$stateDir = dirname(__DIR__) . '/config/state';
$cooldownFile = $stateDir . '/requestItemCooldown.json';
$confirmFile = $stateDir . '/itemRequestConfirm.json';
$totalMigrated = 0;
$cooldownMigrated = 0;
$confirmMigrated = 0;

try {
    $db = Database::getInstance();
    $db->initialize();
    echo "[OK] 数据库初始化成功\n";
    echo "     数据库路径: " . $db->getDbPath() . "\n";
    echo "     版本号: " . $db->getVersion() . "\n\n";
} catch (Exception $e) {
    echo "[FAIL] 数据库初始化失败: " . $e->getMessage() . "\n";
    exit(1);
}

if (file_exists($cooldownFile)) {
    echo "--- 迁移物品请求冷却期数据 ---\n";
    echo "源文件: $cooldownFile\n";

    $content = file_get_contents($cooldownFile);
    $data = json_decode($content, true);

    if (is_array($data) && !empty($data)) {
        foreach ($data as $playerName => $cooldownUntil) {
            if (!is_numeric($cooldownUntil)) {
                continue;
            }

            $existing = $db->query(
                'SELECT id FROM item_requests WHERE user_id IS NULL AND item_name = :item_name AND status = :status',
                [':item_name' => $playerName, ':status' => 'cooldown']
            );

            if (empty($existing)) {
                $db->execute(
                    'INSERT INTO item_requests (user_id, item_name, count, status, cooldown_until) 
                     VALUES (NULL, :item_name, 0, :status, :cooldown_until)',
                    [
                        ':item_name'      => $playerName,
                        ':status'         => 'cooldown',
                        ':cooldown_until' => (int)$cooldownUntil
                    ]
                );
                $cooldownMigrated++;
                echo "  [+] 冷却记录: 玩家={$playerName}, 到期=" . date('Y-m-d H:i:s', (int)$cooldownUntil) . "\n";
            } else {
                echo "  [=] 跳过(已存在): 玩家={$playerName}\n";
            }
        }
    } else {
        echo "  文件为空或格式无效，跳过\n";
    }
    $totalMigrated += $cooldownMigrated;
    echo "结果: 迁移 {$cooldownMigrated} 条冷却期记录\n\n";
} else {
    echo "--- 物品请求冷却期文件不存在，跳过 ---\n";
    echo "期望路径: $cooldownFile\n\n";
}

if (file_exists($confirmFile)) {
    echo "--- 迁移待确认的物品请求 ---\n";
    echo "源文件: $confirmFile\n";

    $content = file_get_contents($confirmFile);
    $data = json_decode($content, true);

    if (is_array($data) && !empty($data)) {
        foreach ($data as $request) {
            if (!isset($request['itemName']) || !isset($request['count'])) {
                continue;
            }

            $playerName = $request['player'] ?? 'unknown';
            $itemName = $request['itemName'];
            $count = (int)$request['count'];
            $timestamp = (int)($request['timestamp'] ?? time());

            $existing = $db->query(
                'SELECT id FROM item_requests WHERE user_id IS NULL AND item_name = :item_name AND count = :count AND status = :status',
                [':item_name' => "{$playerName}:{$itemName}", ':count' => $count, ':status' => 'pending']
            );

            if (empty($existing)) {
                $db->execute(
                    'INSERT INTO item_requests (user_id, item_name, count, status) 
                     VALUES (NULL, :item_name, :count, :status)',
                    [
                        ':item_name' => "{$playerName}:{$itemName}",
                        ':count'    => $count,
                        ':status'   => 'pending'
                    ]
                );
                $confirmMigrated++;
                echo "  [+] 待确认请求: 玩家={$playerName}, 物品={$itemName}, 数量={$count}\n";
            } else {
                echo "  [=] 跳过(已存在): 玩家={$playerName}, 物品={$itemName}\n";
            }
        }
    } else {
        echo "  文件为空或格式无效，跳过\n";
    }
    $totalMigrated += $confirmMigrated;
    echo "结果: 迁移 {$confirmMigrated} 条待确认请求\n\n";
} else {
    echo "--- 物品请求确认文件不存在，跳过 ---\n";
    echo "期望路径: $confirmFile\n\n";
}

echo "========================================\n";
echo "  迁移统计\n";
echo "========================================\n";
echo "  冷却期记录:   {$cooldownMigrated} 条\n";
echo "  待确认请求:   {$confirmMigrated} 条\n";
echo "  总计迁移:     {$totalMigrated} 条\n";
echo "========================================\n";

$currentRequests = $db->query('SELECT COUNT(*) as cnt FROM item_requests');
echo "  item_requests 表当前总记录数: " . ($currentRequests[0]['cnt'] ?? 0) . " 条\n";
echo "========================================\n";

if ($totalMigrated > 0) {
    echo "\n[SUCCESS] 数据迁移完成！共迁移 {$totalMigrated} 条记录。\n";
    echo "提示: 此为一次性迁移脚本，迁移完成后可删除此文件。\n";
} else {
    echo "\n[INFO] 无需迁移的数据（JSON 文件为空或所有数据已存在于数据库中）。\n";
}
