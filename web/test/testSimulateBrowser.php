<?php
// 直接模拟 api.php 的完整处理流程
// 绕过 nginx 和 PHP-FPM，确认代码本身是否正确

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/app/core/Database.php';
require_once ROOT_DIR . '/app/services/AuthService.php';
require_once ROOT_DIR . '/app/services/VipService.php';
require_once ROOT_DIR . '/app/services/ItemService.php';
require_once ROOT_DIR . '/app/services/RconService.php';
require_once ROOT_DIR . '/app/services/ShopService.php';

header('Content-Type: application/json; charset=utf-8');

// 完全复制 ShopController::handleBatchCreateOrders 的逻辑
try {
    $shopService = new \App\Services\ShopService();

    // 与前端发送的数据格式完全一致
    $itemsJson = '[{"code":"express-transport-belt","name":"极速传送带","quantity":1,"quality":0},{"code":"fast-transport-belt","name":"高速传送带","quantity":1,"quality":0}]';
    $items = json_decode($itemsJson, true);

    echo json_encode([
        'test' => 'simulating_browser_request',
        'items_received' => $items,
        'items_count' => count($items),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    // 使用与 Controller 相同的参数调用
    $userId = 4;
    $results = $shopService->batchCreatePurchaseOrders($userId, $items, '待定');

    $successCount = count(array_filter($results, fn($r) => $r['success']));
    $failCount = count($results) - $successCount;

    echo json_encode([
        'final_result' => true,
        'http_code' => $failCount > 0 ? 400 : 200,
        'total_input' => count($items),
        'total_results' => count($results),
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'message' => $failCount === 0
            ? "订单已生成！共 {$successCount} 个订单"
            : "部分订单创建失败：{$successCount} 个成功，{$failCount} 个失败",
        'orders' => array_map(function($r) {
            return [
                'order_id' => $r['order_id'] ?? null,
                'order_number' => $r['order_number'] ?? null,
                'item_code' => $r['item_code'] ?? '',
                'success' => $r['success'],
                'error' => $r['error'] ?? null
            ];
        }, $results)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // 清理测试数据
    $db = \App\Core\Database::getInstance();
    $db->execute("DELETE FROM orders WHERE order_number LIKE 'FY260405%' AND created_at > " . (time() - 3600));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'exception' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
