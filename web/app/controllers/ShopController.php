<?php

namespace App\Controllers;

use App\Services\ShopService;
use App\Services\AuthService;
use App\Core\Response;

class ShopController
{
    private $shopService;
    private $authService;

    public function __construct()
    {
        $this->shopService = new ShopService();
        $this->authService = new AuthService();
    }

    /**
     * 获取采购物品列表（从物品库获取，只显示启用的物品）
     * GET 请求，支持 category 和 search 参数筛选
     */
    public static function handleShopItems()
    {
        $instance = new self();

        if (!$instance->authService->isLoggedIn()) {
            Response::success(['items' => [], 'categories' => []]);
            return;
        }

        $category = $_GET['category'] ?? null;
        $search = $_GET['search'] ?? null;

        // 使用 ItemService 从物品库获取物品，只显示启用的
        $itemService = new \App\Services\ItemService();
        $itemsWithStatus = $itemService->getItemsWithStatus($category, $search, 'enabled');

        // 获取分类列表
        $categories = $itemService->getAllCategories();

        // 获取用户 VIP 信息
        $user = $instance->authService->getCurrentUser();
        $userId = $user['username'] ?? null;

        $vipService = new \App\Services\VipService();
        $vipInfo = $vipService->getVipInfo($userId);

        $vipBenefits = null;
        if ($vipInfo['success']) {
            $vipBenefits = $vipInfo['data']['benefits'];
        }

        Response::success([
            'items' => $itemsWithStatus,
            'categories' => $categories,
            'vip_benefits' => $vipBenefits
        ]);
    }

    /**
     * 管理员添加商品
     * POST 请求，需验证管理员权限
     */
    public static function handleAddShopItem()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        if (!$instance->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $data = [
            'item_name' => $_POST['item_name'] ?? '',
            'item_code' => $_POST['item_code'] ?? '',
            'price' => $_POST['price'] ?? 0,
            'stock' => $_POST['stock'] ?? -1,
            'quality' => $_POST['quality'] ?? 0,
            'category' => $_POST['category'] ?? '默认'
        ];

        $result = $instance->shopService->addShopItem($data);

        if ($result['success']) {
            Response::success(['item_id' => $result['item_id']], $result['message']);
        }

        Response::error($result['error']);
    }

    /**
     * 管理员编辑商品
     * POST 请求
     */
    public static function handleUpdateShopItem()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        if (!$instance->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            Response::error('商品ID无效');
        }

        $data = [];

        $updatableFields = ['item_name', 'item_code', 'price', 'stock', 'quality', 'category'];
        foreach ($updatableFields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = $_POST[$field];
            }
        }

        // 支持通过 is_active 字段上架/下架
        if (isset($_POST['is_active'])) {
            $data['is_active'] = (int)$_POST['is_active'];
        }

        $result = $instance->shopService->updateShopItem($id, $data);

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error']);
    }

    /**
     * 创建采购订单（采购模式）
     * POST 请求
     */
    public static function handleCreateOrder()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $user = $instance->authService->getCurrentUser();

        $itemCode = trim($_POST['item_code'] ?? '');
        $playerName = trim($_POST['player_name'] ?? '待定');
        $quality = (int)($_POST['quality'] ?? 0);
        $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : null;

        if (empty($itemCode)) {
            Response::error('请选择物品');
        }

        $userId = $instance->getUserId($user);

        $result = $instance->shopService->createPurchaseOrder($userId, $itemCode, $playerName, $quality, $quantity);

        if ($result['success']) {
            Response::success([
                'order_id' => $result['order_id'],
                'quantity' => $result['quantity'],
                'quality' => $result['quality']
            ], $result['message']);
        }

        Response::error($result['error']);
    }

    public static function handleBatchCreateOrders()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $user = $instance->authService->getCurrentUser();
        $userId = $instance->getUserId($user);

        $itemsJson = $_POST['items'] ?? '';
        if (empty($itemsJson)) {
            Response::error('没有要结算的物品');
        }

        $items = json_decode($itemsJson, true);
        if (!is_array($items) || empty($items)) {
            Response::error('物品数据格式错误');
        }

        $results = $instance->shopService->batchCreatePurchaseOrders($userId, $items, '待定');

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failCount = count($results) - $successCount;
        $totalCount = count($results);

        if ($failCount === 0) {
            // 构建订单号列表（去重，因为所有订单都使用相同的订单号）
            $orderNumbers = [];
            foreach ($results as $result) {
                if ($result['success'] && isset($result['order_number'])) {
                    $orderNumbers[] = $result['order_number'];
                }
            }
            $uniqueOrderNumbers = array_unique($orderNumbers);
            $orderListStr = implode(', ', $uniqueOrderNumbers);

            // 获取 VIP 剩余次数
            $vipRemaining = null;
            try {
                list($_vipOk, $_vipErr, $limits) = $instance->shopService->getVipService()->checkVipPermission($userId, 'purchase');
                $todayCount = $instance->shopService->getTodayOrderCount($userId);
                $dailyLimit = (int)($limits['dailyLimit'] ?? 5);
                $vipRemaining = [
                    'daily_limit' => $dailyLimit,
                    'daily_remaining' => max(0, $dailyLimit - $todayCount),
                    'level_name' => $limits['name'] ?? '普通'
                ];
            } catch (Exception $_e) {}

            Response::success([
                'total_count' => $totalCount,
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'orders' => array_map(function($r) {
                    // 生成物品名称（基于物品代码）
                    $itemName = $r['item_code'] ?? '';
                    // 简单的物品名称映射
                    $itemNames = [
                        'iron-ore' => '铁矿石',
                        'copper-ore' => '铜矿',
                        'uranium-ore' => '铀矿',
                        'transport-belt' => '传送带',
                        'exoskeleton-equipment' => '外骨骼模块'
                    ];
                    if (isset($itemNames[$itemName])) {
                        $itemName = $itemNames[$itemName];
                    }
                    
                    return [
                        'order_id' => $r['order_id'] ?? null,
                        'order_number' => $r['order_number'] ?? null,
                        'item_code' => $r['item_code'] ?? '',
                        'item_name' => $itemName,
                        'quantity' => $r['quantity'] ?? 0,
                        'quality' => $r['quality'] ?? 0,
                        'success' => $r['success']
                    ];
                }, $results),
                'vip_remaining' => $vipRemaining
            ], "🎉 订单已生成！共 1 个订单，请在游戏中发送订单号领取：{$orderListStr}");
        }

        Response::error("⚠️ 部分订单创建失败：{$successCount} 个成功，{$failCount} 个失败", 400, [
            'total_count' => $totalCount,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'details' => array_map(function($r) {
                return [
                    'item_code' => $r['item_code'] ?? '',
                    'success' => $r['success'] ?? false,
                    'error' => $r['error'] ?? '未知错误',
                    'order_id' => $r['order_id'] ?? null,
                    'order_number' => $r['order_number'] ?? null,
                ];
            }, $results)
        ]);
    }

    /**
     * 获取当前用户的订单列表
     * GET 请求
     */
    public static function handleMyOrders()
    {
        $instance = new self();

        if (!$instance->authService->isLoggedIn()) {
            Response::success(['orders' => [], 'total' => 0]);
            return;
        }

        $user = $instance->authService->getCurrentUser();
        $userId = $instance->getUserId($user);

        $status = $_GET['status'] ?? null;
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $orders = $instance->shopService->getOrders($userId, $status, $limit, $offset);

        Response::success([
            'orders' => $orders,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * 发放订单物品（管理员操作）
     * POST 请求
     */
    public static function handleDeliverOrder()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        if (!$instance->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $orderId = (int)($_POST['order_id'] ?? 0);
        $playerName = isset($_POST['player_name']) ? trim($_POST['player_name']) : null;

        if ($orderId <= 0) {
            Response::error('订单ID无效');
        }

        $result = $instance->shopService->deliverOrder($orderId, $playerName);

        if ($result['success']) {
            Response::success([
                'rcon_response' => $result['rcon_response'],
                'player_name' => $result['player_name']
            ], $result['message']);
        }

        Response::error($result['error']);
    }

    /**
     * 取消订单
     * POST 请求
     */
    public static function handleCancelOrder()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $orderId = (int)($_POST['order_id'] ?? 0);

        if ($orderId <= 0) {
            Response::error('订单ID无效');
        }

        $order = $instance->shopService->getOrderById($orderId);

        if (!$order) {
            Response::notFound('订单不存在');
        }

        $user = $instance->authService->getCurrentUser();
        $userId = $instance->getUserId($user);

        // 非管理员只能取消自己的订单
        if (!$instance->authService->isAdmin() && (int)$order['user_id'] !== $userId) {
            Response::forbidden('只能取消自己的订单');
        }

        $result = $instance->shopService->cancelOrder($orderId);

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error']);
    }

    /**
     * 获取商品分类列表
     * GET 请求
     */
    public static function handleGetCategories()
    {
        $instance = new self();

        if ($instance->authService->isLoggedIn()) {
            $categories = $instance->shopService->getCategoryList();
        } else {
            $categories = [];
        }

        Response::success(['categories' => $categories]);
    }

    /**
     * 获取当日订单数量统计
     * GET 请求
     */
    public static function handleDailyOrderCount()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $user = $instance->authService->getCurrentUser();
        $userId = $instance->getUserId($user);

        $count = $instance->shopService->getDailyOrderCount($userId);

        Response::success(['count' => $count]);
    }

    /**
     * 根据用户信息获取数据库中的用户ID
     * 当前实现：基于 session 中的 user_id 生成数字标识
     *
     * @param array|null $user 当前登录用户信息
     * @return int 用户ID
     */
    private function getUserId(?array $user): int
    {
        if ($user === null) {
            return 0;
        }

        return (int)($user['id'] ?? $user['user_id'] ?? 0);
    }

    /**
     * 获取所有待发放订单（管理员用）
     * GET 请求
     */
    public static function handlePendingOrders()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        if (!$instance->authService->isAdmin()) {
            Response::forbidden('需要管理员权限');
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        $orders = $instance->shopService->getAllPendingOrders($limit, $offset);

        Response::success(['orders' => $orders]);
    }

    /**
     * 验证订单号（供游戏内调用）
     * POST /app/api.php?action=validate_order
     * 参数: order_number
     */
    public static function handleValidateOrder()
    {
        header('Content-Type: application/json; charset=utf-8');

        $orderNumber = trim($_POST['order_number'] ?? '');

        if (empty($orderNumber)) {
            echo json_encode(['success' => false, 'error' => '订单号不能为空'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $instance = new self();
        $result = $instance->shopService->validateOrderNumber($orderNumber);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 通过订单号自助交付（供游戏内调用）
     * POST /app/api.php?action=deliver_by_number
     * 参数: order_number, player_name
     */
    public static function handleDeliverByNumber()
    {
        header('Content-Type: application/json; charset=utf-8');

        $orderNumber = trim($_POST['order_number'] ?? '');
        $playerName = trim($_POST['player_name'] ?? '');

        if (empty($orderNumber)) {
            echo json_encode(['success' => false, 'error' => '订单号不能为空'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($playerName)) {
            echo json_encode(['success' => false, 'error' => '玩家名不能为空'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $instance = new self();
        $result = $instance->shopService->deliverOrderByNumber($orderNumber, $playerName);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}
