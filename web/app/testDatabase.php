<?php

require_once __DIR__ . '/core/Database.php';

use App\Core\Database;

$passCount = 0;
$failCount = 0;
$testResults = [];

function test(string $name, bool $passed, string $detail = ''): void
{
    global $passCount, $failCount, $testResults;
    $status = $passed ? 'PASS' : 'FAIL';
    if ($passed) {
        $passCount++;
    } else {
        $failCount++;
    }
    $testResults[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    echo sprintf("  [%s] %s%s\n", $status, $name, $detail ? " - {$detail}" : '');
}

echo "========================================\n";
echo "  Factorio 数据库初始化测试\n";
echo "========================================\n\n";

try {
    $db = Database::getInstance();
    test('Database 单例获取', true);
} catch (Exception $e) {
    test('Database 单例获取', false, $e->getMessage());
    echo "\n[ABORT] 无法获取数据库实例，终止测试\n";
    exit(1);
}

try {
    $result = $db->initialize();
    test('数据库 initialize()', $result === true);
} catch (Exception $e) {
    test('数据库 initialize()', false, $e->getMessage());
}

echo "\n--- 表结构验证 ---\n";

$expectedTables = ['users', 'player_bindings', 'shop_items', 'orders', 'item_requests'];
$tableResult = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
$existingTables = array_column($tableResult, 'name');

foreach ($expectedTables as $tableName) {
    $exists = in_array($tableName, $existingTables);
    test("表 {$tableName} 存在", $exists);
}

$extraTables = array_diff($existingTables, $expectedTables, ['db_meta']);
if (!empty($extraTables)) {
    foreach ($extraTables as $extra) {
        test("额外表 {$extra}", true, '(非预期但存在)');
    }
}

test('db_meta 表存在', in_array('db_meta', $existingTables));

echo "\n--- 版本号验证 ---\n";

try {
    $version = $db->getVersion();
    test('版本号查询成功', $version > 0, "当前版本: {$version}");
} catch (Exception $e) {
    test('版本号查询失败', false, $e->getMessage());
}

echo "\n--- CRUD 操作测试 ---\n";

$testUsername = '_test_user_' . time();
$testPassword = 'testpass123';
$testName = '测试用户';

try {
    $insertResult = $db->execute(
        'INSERT INTO users (username, password_hash, role, name, vip_level, created_at, updated_at) 
         VALUES (:username, :password_hash, :role, :name, 0, :created_at, :updated_at)',
        [
            ':username'     => $testUsername,
            ':password_hash'=> password_hash($testPassword, PASSWORD_DEFAULT),
            ':role'         => 'user',
            ':name'         => $testName,
            ':created_at'   => time(),
            ':updated_at'   => time()
        ]
    );
    test('插入测试用户记录', $insertResult === 1);
} catch (Exception $e) {
    test('插入测试用户记录', false, $e->getMessage());
}

try {
    $users = $db->query(
        'SELECT id, username, role, name, vip_level FROM users WHERE username = :username',
        [':username' => $testUsername]
    );
    $found = !empty($users) && $users[0]['username'] === $testUsername && $users[0]['name'] === $testName;
    test('查询测试用户记录', $found, $found ? "ID={$users[0]['id']}" : '未找到');
    
    if ($found) {
        $testUserId = (int)$users[0]['id'];
    } else {
        $testUserId = 0;
    }
} catch (Exception $e) {
    test('查询测试用户记录', false, $e->getMessage());
    $testUserId = 0;
}

try {
    if ($testUserId > 0) {
        $updateResult = $db->execute(
            'UPDATE users SET name = :name, updated_at = :updated_at WHERE id = :id',
            [':name' => $testName . '_updated', ':updated_at' => time(), ':id' => $testUserId]
        );
        test('更新测试用户记录', $updateResult === 1);

        $verifyUpdate = $db->query(
            'SELECT name FROM users WHERE id = :id',
            [':id' => $testUserId]
        );
        $updateVerified = !empty($verifyUpdate) && $verifyUpdate[0]['name'] === $testName . '_updated';
        test('更新数据验证', $updateVerified);
    } else {
        test('更新测试用户记录', false, '跳过(无有效用户ID)');
        test('更新数据验证', false, '跳过(无有效用户ID)');
    }
} catch (Exception $e) {
    test('更新测试用户记录', false, $e->getMessage());
    test('更新数据验证', false, $e->getMessage());
}

try {
    if ($testUserId > 0) {
        $deleteResult = $db->execute(
            'DELETE FROM users WHERE id = :id',
            [':id' => $testUserId]
        );
        test('删除测试用户记录', $deleteResult === 1);

        $verifyDelete = $db->query(
            'SELECT id FROM users WHERE username = :username',
            [':username' => $testUsername]
        );
        $deleteVerified = empty($verifyDelete);
        test('删除数据验证', $deleteVerified);
    } else {
        test('删除测试用户记录', false, '跳过(无有效用户ID)');
        test('删除数据验证', false, '跳过(无有效用户ID)');
    }
} catch (Exception $e) {
    test('删除测试用户记录', false, $e->getMessage());
    test('删除数据验证', false, $e->getMessage());
}

echo "\n--- 事务支持测试 ---\n";

try {
    $db->beginTransaction();
    
    $txUsername = '_tx_test_' . time();
    $db->execute(
        'INSERT INTO users (username, password_hash, role, name, vip_level, created_at, updated_at) 
         VALUES (:username, :password_hash, :role, :name, 0, :created_at, :updated_at)',
        [
            ':username'     => $txUsername,
            ':password_hash'=> password_hash('txpass', PASSWORD_DEFAULT),
            ':role'         => 'user',
            ':name'         => 'TX Test',
            ':created_at'   => time(),
            ':updated_at'   => time()
        ]
    );

    $txCheck = $db->query('SELECT id FROM users WHERE username = :username', [':username' => $txUsername]);
    $txInserted = !empty($txCheck);
    test('事务内写入', $txInserted);

    $db->rollBack();

    $txAfterRollback = $db->query('SELECT id FROM users WHERE username = :username', [':username' => $txUsername]);
    $txRolledBack = empty($txAfterRollback);
    test('事务回滚验证', $txRolledBack);
} catch (Exception $e) {
    test('事务支持测试', false, $e->getMessage());
    test('事务回滚验证', false, $e->getMessage());
}

echo "\n========================================\n";
echo "  测试结果汇总\n";
echo "========================================\n";
echo "  总计:   " . ($passCount + $failCount) . " 项\n";
echo "  通过:   {$passCount} 项\n";
echo "  失败:   {$failCount} 项\n";
echo "========================================\n";

if ($failCount === 0) {
    echo "\n[ALL PASS] 所有测试通过！数据库功能正常。\n";
    exit(0);
} else {
    echo "\n[SOME FAIL] 有 {$failCount} 项测试失败，请检查上方详情。\n";
    exit(1);
}
