<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// 模拟 api.php 的完整加载流程
define('ROOT_DIR', dirname(__DIR__));

// 先启动 session
session_start();

// 模拟登录
$_SESSION['user_id'] = 4;
$_SESSION['username'] = 'airxw';
$_SESSION['logged_in'] = true;

// 加载完整的 api.php 依赖链
require_once ROOT_DIR . '/app/core/Database.php';
require_once ROOT_DIR . '/app/services/AuthService.php';
require_once ROOT_DIR . '/app/services/VipService.php';
require_once ROOT_DIR . '/app/services/ItemService.php';
require_once ROOT_DIR . '/app/services/RconService.php';
require_once ROOT_DIR . '/app/services/ShopService.php';
require_once ROOT_DIR . '/app/controllers/ShopController.php';

echo json_encode([
    'test_mode' => true,
    'session_user_id' => $_SESSION['user_id'] ?? null,
    'php_version' => PHP_VERSION,
    'shop_service_file_mtime' => filemtime(ROOT_DIR . '/app/services/ShopService.php'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// 现在模拟 handleBatchCreateOrders 的完整流程
try {
    $authService = new \App\Services\AuthService();
    // 手动设置当前用户（模拟登录状态）
    $reflection = new ReflectionClass($authService);
    if ($reflection->hasProperty('currentUser')) {
        $prop = $reflection->getProperty('currentUser');
        $prop->setAccessible(true);
        $prop->setValue($authService, ['id' => 4, 'username' => 'airxw']);
    }

    $shopService = new \App\Services\ShopService();

    $userId = 4;
    $items = [
        ['item_code' => 'express-transport-belt', 'quantity' => 1, 'quality' => 0],
        ['item_code' => 'fast-transport-belt', 'quantity' => 1, 'quality' => 0]
    ];

    echo "=== 通过 ShopService 直接调用 ===\n";
    $results = $shopService->batchCreatePurchaseOrders($userId, $items);

    $ok = count(array_filter($results, fn($r) => $r['success']));
    $fail = count($results) - $ok;

    echo json_encode([
        'direct_call' => true,
        'total_input' => count($items),
        'total_results' => count($results),
        'success_count' => $ok,
        'fail_count' => $fail,
        'orders' => array_map(function($r) {
            return [
                'success' => $r['success'],
                'order_id' => $r['order_id'] ?? null,
                'order_number' => $r['order_number'] ?? null,
                'error' => $r['error'] ?? null,
            ];
        }, $results)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // 清理
    $db = \App\Core\Database::getInstance();
    $db->execute("DELETE FROM orders WHERE order_number LIKE 'FY260405%' AND created_at > " . (time() - 3600));

} catch (Exception $e) {
    echo json_encode(['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()], JSON_PRETTY_PRINT);
}
