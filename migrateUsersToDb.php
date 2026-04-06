<?php
/**
 * 用户数据迁移脚本 - auth.php → users 数据库表
 * 
 * 将配置文件中的管理员账号迁移到数据库
 */

require_once __DIR__ . '/web/app/autoload.php';

use App\Core\Database;

$configFile = __DIR__ . '/web/config/system/auth.php';
$config = file_exists($configFile) ? require $configFile : ['users' => []];

$db = Database::getInstance();
$db->initialize();

echo "=== 用户数据迁移：auth.php → users 表 ===\n\n";

$migrated = 0;
$skipped = 0;

foreach ($config['users'] ?? [] as $username => $userData) {
    $existing = $db->query(
        "SELECT id, username FROM users WHERE username = :uname",
        [':uname' => $username]
    );

    if (!empty($existing)) {
        echo "⏭️ 跳过 '{$username}': 已存在于数据库 (ID: {$existing[0]['id']})\n";
        
        $db->execute(
            "UPDATE users SET password_hash = :hash, role = :role, name = :name, updated_at = :ua WHERE username = :uname",
            [
                ':hash' => $userData['password'] ?? '',
                ':role' => $userData['role'] ?? 'user',
                ':name' => $userData['name'] ?? $username,
                ':ua' => time(),
                ':uname' => $username
            ]
        );
        echo "   ✅ 已更新密码和角色信息\n";
        $skipped++;
        continue;
    }

    $db->execute(
        "INSERT INTO users (username, password_hash, role, name, vip_level, created_at, updated_at) VALUES (:uname, :hash, :role, :name, 0, :ca, :ua)",
        [
            ':uname' => $username,
            ':hash' => $userData['password'] ?? '',
            ':role' => $userData['role'] ?? 'user',
            ':name' => $userData['name'] ?? $username,
            ':ca' => time(),
            ':ua' => time()
        ]
    );
    
    $id = $db->lastInsertId();
    $displayName = $userData['name'] ?? $username;
    $roleName = $userData['role'] ?? 'user';
    echo "✅ 迁移 '{$username}' ({$displayName}) → ID: {$id}, 角色: {$roleName}\n";
    $migrated++;
}

echo "\n═══════════════════════════\n";
echo "迁移: {$migrated} 条\n";
echo "跳过(已存在): {$skipped} 条\n";

$allUsers = $db->query("SELECT id, username, name, role, vip_level FROM users ORDER BY id");
echo "\n当前数据库用户列表:\n";
foreach ($allUsers as $u) {
    printf("  [%d] %s (%s) - %s | VIP:%d\n", $u['id'], $u['username'], $u['name'] ?? '-', $u['role'], $u['vip_level'] ?? 0);
}
