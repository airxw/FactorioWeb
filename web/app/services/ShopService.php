<?php

namespace App\Services;

use App\Core\Database;

class ShopService
{
    private $db;
    private $rconService;
    private $vipService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->rconService = new RconService();
        $this->vipService = new VipService($this->db);
    }

    public function getVipService(): VipService
    {
        return $this->vipService;
    }

    public function getTodayOrderCount(int $userId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM orders WHERE user_id = :user_id AND created_at >= strftime('%s', date('now')) AND status != 'cancelled'",
            [':user_id' => $userId]
        );
        return (int)($result[0]['cnt'] ?? 0);
    }

    // ==================== 商品管理方法 ====================

    public function getShopItems($category = null, $activeOnly = true)
    {
        $sql = "SELECT * FROM shop_items WHERE 1=1";
        $params = [];

        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }

        if ($category !== null && $category !== '') {
            $sql .= " AND category = :category";
            $params[':category'] = $category;
        }

        $sql .= " ORDER BY category ASC, id ASC";

        return $this->db->query($sql, $params);
    }

    public function getShopItemById($id)
    {
        $results = $this->db->query(
            "SELECT * FROM shop_items WHERE id = :id",
            [':id' => (int)$id]
        );

        return $results[0] ?? null;
    }

    public function addShopItem($data)
    {
        $itemName = trim($data['item_name'] ?? '');
        $itemCode = trim($data['item_code'] ?? '');
        $price = (float)($data['price'] ?? 0);
        $stock = isset($data['stock']) ? (int)$data['stock'] : -1;
        $quality = (int)($data['quality'] ?? 0);
        $category = trim($data['category'] ?? '默认');

        if (empty($itemName) || empty($itemCode)) {
            return ['success' => false, 'error' => '商品名称和物品代码不能为空'];
        }

        if ($price < 0) {
            return ['success' => false, 'error' => '价格不能为负数'];
        }

        $this->db->execute(
            "INSERT INTO shop_items (item_name, item_code, price, stock, quality, category, is_active, created_at) 
             VALUES (:item_name, :item_code, :price, :stock, :quality, :category, 1, :created_at)",
            [
                ':item_name' => $itemName,
                ':item_code' => $itemCode,
                ':price' => $price,
                ':stock' => $stock,
                ':quality' => $quality,
                ':category' => $category,
                ':created_at' => time()
            ]
        );

        $insertId = $this->db->lastInsertId();

        return [
            'success' => true,
            'message' => '商品添加成功',
            'item_id' => (int)$insertId
        ];
    }

    public function updateShopItem($id, $data)
    {
        $item = $this->getShopItemById($id);

        if (!$item) {
            return ['success' => false, 'error' => '商品不存在'];
        }

        $fields = [];
        $params = [':id' => (int)$id];

        $updatableFields = ['item_name', 'item_code', 'price', 'stock', 'quality', 'category'];

        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return ['success' => false, 'error' => '没有需要更新的字段'];
        }

        $sql = "UPDATE shop_items SET " . implode(', ', $fields) . " WHERE id = :id";
        $this->db->execute($sql, $params);

        return ['success' => true, 'message' => '商品更新成功'];
    }

    public function deleteShopItem($id)
    {
        $item = $this->getShopItemById($id);

        if (!$item) {
            return ['success' => false, 'error' => '商品不存在'];
        }

        $this->db->execute(
            "UPDATE shop_items SET is_active = 0 WHERE id = :id",
            [':id' => (int)$id]
        );

        return ['success' => true, 'message' => '商品已下架'];
    }

    // ==================== 订单号管理方法 ====================

    /**
     * 生成唯一订单号
     * 格式：FY + YYMMDD(6位日期) + 6位随机码(大小写字母+数字)
     * 示例：FY260405A3k8m2
     *
     * @return string 14位订单号
     */
    private function generateOrderNumber(): string
    {
        $maxRetries = 10;
        $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $datePart = date('ymd');

            $randomCode = substr(str_shuffle($charset), 0, 6);

            $orderNumber = 'FY' . $datePart . $randomCode;

            $existing = $this->db->query(
                "SELECT id FROM orders WHERE order_number = :order_number LIMIT 1",
                [':order_number' => $orderNumber]
            );

            if (empty($existing)) {
                error_log("[Order] Generated unique order number: {$orderNumber} (attempt " . ($attempt + 1) . ")");
                return $orderNumber;
            }

            error_log("[WARNING] Order number collision detected: {$orderNumber}, retrying...");
        }

        // 如果无法生成唯一订单号，使用时间戳作为 fallback
        $timestamp = time();
        $orderNumber = 'FY' . date('ymd') . substr(md5($timestamp), 0, 6);
        error_log("[Order] Using timestamp-based order number: {$orderNumber}");
        return $orderNumber;
    }

    /**
     * 验证订单号格式是否合法
     * 格式规则：FY + 6位数字日期 + 6位字母数字混合
     * 正则：/^FY\d{6}[A-Za-z0-9]{6}$/
     *
     * @param string $number 待验证的订单号
     * @return bool 是否符合格式要求
     */
    public static function isValidOrderNumberFormat(string $number): bool
    {
        return (bool) preg_match('/^FY\d{6}[A-Za-z0-9]{6}$/', $number);
    }

    /**
     * 验证订单号有效性并返回详细信息
     *
     * @param string $orderNumber 订单号
     * @return array 验证结果和订单详情
     */
    public function validateOrderNumber(string $orderNumber): array
    {
        error_log("[Order Validation] Validating order number: {$orderNumber}");

        if (!self::isValidOrderNumberFormat($orderNumber)) {
            error_log("[Order Validation] Invalid format: {$orderNumber}");
            return ['valid' => false, 'error' => '订单号格式错误'];
        }

        $results = $this->db->query(
            "SELECT o.*, si.item_name, si.item_code, si.quality as item_quality
             FROM orders o
             LEFT JOIN shop_items si ON o.item_id = si.id
             WHERE o.order_number = :order_number",
            [':order_number' => $orderNumber]
        );

        if (empty($results)) {
            error_log("[Order Validation] Order not found: {$orderNumber}");
            return ['valid' => false, 'error' => '订单不存在'];
        }

        $order = $results[0];

        if ($order['status'] !== 'pending') {
            error_log("[Order Validation] Order status invalid: {$orderNumber}, status={$order['status']}");
            return ['valid' => false, 'error' => '该订单已被领取或已取消'];
        }

        $quality = $order['quality'] ?? $order['item_quality'] ?? 0;

        error_log("[Order Validation] Order validated successfully: {$orderNumber}, ID={$order['id']}");

        return [
            'valid' => true,
            'order_id' => $order['id'],
            'items' => [[
                'code' => $order['item_code'] ?? '',
                'name' => $order['item_name'] ?? '',
                'quantity' => (int)$order['quantity'],
                'quality' => (int)$quality
            ]],
            'status' => $order['status'],
            'created_at' => date('Y-m-d H:i:s', $order['created_at'])
        ];
    }

    /**
     * 通过订单号自助交付物品（游戏内领取）
     * 核心原则："谁发送有效订单号就向谁交付"
     *
     * @param string $orderNumber 订单号
     * @param string $playerName 接收物品的玩家名
     * @return array 交付结果
     */
    public function deliverOrderByNumber(string $orderNumber, string $playerName): array
    {
        error_log("[Self-Delivery] Starting delivery for order: {$orderNumber} to player: {$playerName}");

        try {
            $validation = $this->validateOrderNumber($orderNumber);

            if (!$validation['valid']) {
                error_log("[Self-Delivery] Validation failed: {$validation['error']}");
                return ['success' => false, 'error' => $validation['error']];
            }

            $orderId = $validation['order_id'];
            $orderItems = $validation['items'];

            // 检查是否为批量订单（rcon_command 为 JSON）
            $rawOrder = $this->db->query("SELECT rcon_command FROM orders WHERE id = :id", [':id' => (int)$orderId]);
            $rawRcon = $rawOrder[0]['rcon_command'] ?? '';
            $batchItems = json_decode($rawRcon, true);

            if (is_array($batchItems) && !empty($batchItems)) {
                // 批量订单：逐个发放所有物品
                return $this->deliverBatchItems($orderId, $batchItems, $playerName, $orderNumber);
            }

            // 单物品订单（旧格式兼容）
            if (empty($orderItems)) {
                return ['success' => false, 'error' => '订单中无有效物品'];
            }
            $items = $orderItems[0];
            $itemCode = $items['code'];
            $itemName = $items['name'];
            $quantity = $items['quantity'];
            $quality = $items['quality'];

            return $this->deliverSingleItem($orderId, $itemCode, $itemName, $quantity, $quality, $playerName);

        } catch (\Exception $e) {
            error_log("[ERROR][Self-Delivery] Exception during delivery: " . $e->getMessage());
            return ['success' => false, 'error' => '物品发放失败: ' . $e->getMessage()];
        }
    }

    private function deliverBatchItems(int $orderId, array $items, string $playerName, string $orderNumber): array
    {
        $escapedPlayerName = str_replace("'", "\\'", $playerName);
        $delivered = [];
        $failed = [];

        foreach ($items as $idx => $item) {
            $code = $item['item_code'] ?? '';
            $name = $item['item_name'] ?? $code;
            $qty = (int)($item['quantity'] ?? 1);
            $q = (int)($item['quality'] ?? 0);

            if ($q > 0) {
                $spec = "{$code} {$qty} q{$q}";
            } else {
                $spec = "{$code} {$qty}";
            }

            $rconCmd = "/c game.player['{$escapedPlayerName}'].insert{name='{$spec}'}";
            $result = sendRconCommand($rconCmd);

            if ($result !== null) {
                $delivered[] = "{$name} x{$qty}";
            } else {
                $failed[] = $name;
            }
        }

        if (!empty($failed)) {
            return ['success' => false, 'error' => '部分物品发放失败: ' . implode(', ', $failed)];
        }

        $this->db->execute(
            "UPDATE orders SET status='delivered', delivered_at=:at, delivered_to_player=:pn WHERE id=:id AND status='pending'",
            [':id' => $orderId, ':at' => time(), ':pn' => $playerName]
        );

        $itemSummary = implode(', ', $delivered);
        return [
            'success' => true,
            'message' => "✅ 发放成功！已向 {$playerName} 发放：{$itemSummary}",
            'order_id' => $orderId,
            'delivered_to' => $playerName,
            'item_count' => count($items),
            'items' => $delivered
        ];
    }

    private function deliverSingleItem(int $orderId, string $itemCode, string $itemName, int $quantity, int $quality, string $playerName): array
    {
        $escapedPlayerName = str_replace("'", "\\'", $playerName);

        if ($quality > 0) {
            $itemSpec = "{$itemCode} {$quantity} q{$quality}";
        } else {
            $itemSpec = "{$itemCode} {$quantity}";
        }

        $rconCommand = "/c game.player['{$escapedPlayerName}'].insert{name='{$itemSpec}'}";
        $result = sendRconCommand($rconCommand);

        if ($result === null) {
            return ['success' => false, 'error' => 'RCON 命令执行失败，请检查服务器连接状态'];
        }

        $this->db->execute(
            "UPDATE orders SET status='delivered', delivered_at=:at, delivered_to_player=:pn WHERE id=:id AND status='pending'",
            [':id' => $orderId, ':at' => time(), ':pn' => $playerName]
        );

        $displayName = !empty($itemName) ? $itemName : $itemCode;
        return [
            'success' => true,
            'message' => "✅ 发放成功！已向 {$playerName} 发放：{$displayName} x{$quantity}",
            'order_id' => $orderId,
            'delivered_to' => $playerName,
            'item' => $displayName,
            'quantity' => $quantity
        ];
    }

    // ==================== 订单管理方法 ====================

    public function createOrder($userId, $itemId, $playerName, $quantity)
    {
        $quantity = max(1, (int)$quantity);
        $item = $this->getShopItemById($itemId);

        if (!$item) {
            return ['success' => false, 'error' => '商品不存在'];
        }

        if ($item['is_active'] != 1) {
            return ['success' => false, 'error' => '该商品已下架'];
        }

        // 库存检查（-1 表示无限库存）
        if ($item['stock'] >= 0 && $item['stock'] < $quantity) {
            return ['success' => false, 'error' => '库存不足，当前库存: ' . $item['stock']];
        }

        // 获取用户 VIP 折扣率
        $vipDiscount = $this->getVipDiscount($userId);

        // 价格计算: 原价 * (1 - VIP折扣率) * 数量
        $unitPrice = $item['price'] * (1 - $vipDiscount);
        $totalPrice = round($unitPrice * $quantity, 2);

        try {
            $this->db->beginTransaction();

            // 扣减库存（无限库存不扣减）
            if ($item['stock'] >= 0) {
                $this->db->execute(
                    "UPDATE shop_items SET stock = stock - :qty WHERE id = :id AND stock >= :qty",
                    [':id' => (int)$itemId, ':qty' => $quantity]
                );
            }

            // 创建订单
            $this->db->execute(
                "INSERT INTO orders (user_id, item_id, player_name, quantity, status, total_price, created_at) 
                 VALUES (:user_id, :item_id, :player_name, :quantity, 'pending', :total_price, :created_at)",
                [
                    ':user_id' => $userId,
                    ':item_id' => (int)$itemId,
                    ':player_name' => $playerName,
                    ':quantity' => $quantity,
                    ':total_price' => $totalPrice,
                    ':created_at' => time()
                ]
            );

            $orderId = (int)$this->db->lastInsertId();

            $this->db->commit();

            return [
                'success' => true,
                'message' => '订单创建成功',
                'order_id' => $orderId,
                'total_price' => $totalPrice,
                'unit_price' => $unitPrice,
                'discount' => $vipDiscount
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => '订单创建失败: ' . $e->getMessage()];
        }
    }

    public function getOrders($userId, $status = null, $limit = 20, $offset = 0)
    {
        $sql = "SELECT o.*, si.item_name, si.item_code, si.quality 
                FROM orders o 
                LEFT JOIN shop_items si ON o.item_id = si.id 
                WHERE o.user_id = :user_id";
        $params = [':user_id' => (int)$userId];

        if ($status !== null && $status !== '') {
            $sql .= " AND o.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY o.created_at DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = (int)$limit;
        $params[':offset'] = (int)$offset;

        return $this->db->query($sql, $params);
    }

    public function getAllPendingOrders($limit = 100, $offset = 0)
    {
        $sql = "SELECT o.*, si.item_name, si.item_code, si.quality 
                FROM orders o 
                LEFT JOIN shop_items si ON o.item_id = si.id 
                WHERE o.status = 'pending'
                ORDER BY o.created_at ASC LIMIT :limit OFFSET :offset";
        $params = [':limit' => (int)$limit, ':offset' => (int)$offset];

        return $this->db->query($sql, $params);
    }

    public function getOrderById($orderId)
    {
        $results = $this->db->query(
            "SELECT o.*, si.item_name, si.item_code, si.quality, si.price as original_price 
             FROM orders o 
             LEFT JOIN shop_items si ON o.item_id = si.id 
             WHERE o.id = :order_id",
            [':order_id' => (int)$orderId]
        );

        return $results[0] ?? null;
    }

    /**
     * 管理员手动发放订单（备用方案）
     * @deprecated 推荐使用 deliverOrderByNumber() 实现游戏内自助领取
     * @保留原因：应对异常情况的人工干预
     *
     * @param int $orderId 订单ID
     * @param string|null $playerNameOverride 覆盖玩家名（可选）
     * @return array 发放结果
     */
    public function deliverOrder($orderId, $playerNameOverride = null)
    {
        $order = $this->getOrderById($orderId);

        if (!$order) {
            return ['success' => false, 'error' => '订单不存在'];
        }

        if ($order['status'] !== 'pending') {
            return ['success' => false, 'error' => '该订单状态不允许发放，当前状态: ' . $order['status']];
        }

        $playerName = $playerNameOverride ?? $order['player_name'];
        $quantity = $order['quantity'];

        if ((int)$order['item_id'] === 0) {
            $itemCode = $order['rcon_command'] ?? '';
            $quality = isset($order['remarks']) ? (int)$order['remarks'] : 0;
        } else {
            $itemCode = $order['item_code'] ?? '';
            $quality = $order['quality'] ?? 0;
        }

        if (empty($itemCode)) {
            return ['success' => false, 'error' => '订单中缺少物品代码'];
        }

        $escapedPlayerName = str_replace("'", "\\'", $playerName);

        if ($quality > 0) {
            $itemSpec = "{$itemCode} {$quantity} q{$quality}";
        } else {
            $itemSpec = "{$itemCode} {$quantity}";
        }

        $rconCommand = "/c game.player['{$escapedPlayerName}'].insert{name='{$itemSpec}'}";

        try {
            $result = sendRconCommand($rconCommand);

            if ($result === null) {
                return ['success' => false, 'error' => 'RCON 命令执行失败，请检查服务器连接状态'];
            }

            $this->db->execute(
                "UPDATE orders SET status = 'delivered', delivered_at = :delivered_at, player_name = :player_name, delivered_to_player = :delivered_to_player WHERE id = :order_id",
                [':order_id' => (int)$orderId, ':delivered_at' => time(), ':player_name' => $playerName, ':delivered_to_player' => $playerName]
            );

            error_log("[Admin Delivery] Order {$orderId} delivered to player: {$playerName}, item: {$itemCode} x{$quantity}");

            return [
                'success' => true,
                'message' => '物品发放成功',
                'rcon_response' => $result,
                'player_name' => $playerName,
                'item' => $itemCode,
                'quantity' => $quantity,
                'quality' => $quality
            ];
        } catch (\Exception $e) {
            error_log("[ERROR][Admin Delivery] Failed for order {$orderId}: " . $e->getMessage());
            return ['success' => false, 'error' => '物品发放失败: ' . $e->getMessage()];
        }
    }

    public function cancelOrder($orderId)
    {
        $order = $this->getOrderById($orderId);

        if (!$order) {
            return ['success' => false, 'error' => '订单不存在'];
        }

        if ($order['status'] !== 'pending') {
            return ['success' => false, 'error' => '只能取消待处理的订单'];
        }

        try {
            $this->db->beginTransaction();

            // 更新订单状态
            $this->db->execute(
                "UPDATE orders SET status = 'cancelled' WHERE id = :order_id",
                [':order_id' => (int)$orderId]
            );

            // 恢复库存
            $this->db->execute(
                "UPDATE shop_items SET stock = stock + :qty WHERE id = :item_id",
                [':item_id' => (int)$order['item_id'], ':qty' => (int)$order['quantity']]
            );

            $this->db->commit();

            return ['success' => true, 'message' => '订单已取消，库存已恢复'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => '取消订单失败: ' . $e->getMessage()];
        }
    }

    // ==================== 统计方法 ====================

    public function getDailyOrderCount($userId)
    {
        $todayStart = strtotime('today');
        $todayEnd = strtotime('tomorrow');

        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM orders 
             WHERE user_id = :user_id AND created_at >= :start AND created_at < :end",
            [':user_id' => (int)$userId, ':start' => $todayStart, ':end' => $todayEnd]
        );

        return (int)($result[0]['cnt'] ?? 0);
    }

    public function getCategoryList()
    {
        $results = $this->db->query(
            "SELECT DISTINCT category FROM shop_items WHERE is_active = 1 ORDER BY category ASC"
        );

        $categories = [];
        foreach ($results as $row) {
            $categories[] = $row['category'];
        }

        return $categories;
    }

    // ==================== 私有辅助方法 ====================

    private function getVipDiscount($userId)
    {
        try {
            if (class_exists('App\Services\VipService')) {
                $vipService = new \App\Services\VipService();
                return $vipService->getDiscountRate($userId);
            }
        } catch (\Exception $e) {
        }

        return 0;
    }

    public function getOrderStats(string $timeRange = '30d'): array
    {
        $timeMap = [
            '7d' => 7 * 86400,
            '30d' => 30 * 86400,
            '90d' => 90 * 86400,
            '365d' => 365 * 86400
        ];
        $since = time() - ($timeMap[$timeRange] ?? $timeMap['30d']);

        $totalStats = $this->db->query(
            'SELECT COUNT(*) as total_orders, SUM(total_price) as total_revenue, AVG(total_price) as avg_order FROM orders WHERE created_at >= :since',
            [':since' => $since]
        )[0];

        $vipStats = $this->db->query(
            "SELECT u.vip_level, COUNT(o.id) as order_count, SUM(o.total_price) as total_spent, AVG(o.total_price) as avg_order FROM users u LEFT JOIN orders o ON o.user_id = u.id AND o.status = 'delivered' AND o.created_at >= :since WHERE u.vip_level > 0 GROUP BY u.vip_level ORDER BY u.vip_level",
            [':since' => $since]
        );

        $topItems = $this->db->query(
            "SELECT si.item_name, si.price, COUNT(o.id) as sales_count, SUM(o.quantity) as total_quantity, SUM(o.total_price) as revenue, COUNT(DISTINCT o.user_id) as buyer_count FROM shop_items si JOIN orders o ON o.item_id = si.id AND o.status = 'delivered' AND o.created_at >= :since GROUP BY si.id ORDER BY revenue DESC LIMIT 10",
            [':since' => $since]
        );

        return [
            'time_range' => $timeRange,
            'total_orders' => (int)$totalStats['total_orders'],
            'total_revenue' => (float)$totalStats['total_revenue'],
            'avg_order_value' => (float)$totalStats['avg_order'],
            'vip_breakdown' => $vipStats,
            'top_items' => $topItems
        ];
    }

    public function getSalesReport(int $startDate, int $endDate): array
    {
        $dailyStats = $this->db->query(
            "SELECT DATE(created_at, 'unixepoch') as date, COUNT(*) as orders, SUM(total_price) as revenue, COUNT(DISTINCT user_id) as buyers FROM orders WHERE created_at >= :start AND created_at <= :end GROUP BY date ORDER BY date",
            [':start' => $startDate, ':end' => $endDate]
        );

        return [
            'period_start' => date('Y-m-d', $startDate),
            'period_end' => date('Y-m-d', $endDate),
            'daily_data' => $dailyStats,
            'summary' => [
                'total_days' => count($dailyStats),
                'total_orders' => array_sum(array_column($dailyStats, 'orders')),
                'total_revenue' => array_sum(array_column($dailyStats, 'revenue')),
                'unique_buyers' => array_sum(array_column($dailyStats, 'buyers'))
            ]
        ];
    }

    /**
     * 创建采购订单（采购模式）
     * 
     * @param int $userId 用户ID
     * @param string $itemCode 物品代码
     * @param string $playerName 玩家名称
     * @param int $quality 品质等级
     * @return array
     */
    public function createPurchaseOrder($userId, $itemCode, $playerName, $quality = 0, $quantity = null): array
    {
        // 验证物品是否存在且启用
        $itemService = new \App\Services\ItemService();
        $itemStatus = $itemService->getItemStatus($itemCode);
        
        if (!$itemStatus) {
            return ['success' => false, 'error' => '物品不存在'];
        }
        
        if (!$itemStatus['is_enabled']) {
            return ['success' => false, 'error' => '该物品已禁用'];
        }

        // 获取 VIP 权益并验证采购
        $vipService = new \App\Services\VipService();
        list($allowed, $errorMsg, $limits) = $vipService->validatePurchase($userId, 1, $quality);
        
        if (!$allowed) {
            return ['success' => false, 'error' => $errorMsg];
        }

        // 验证并使用数量参数
        $usedQuantity = $limits['maxQuantity'];
        if ($quantity !== null) {
            $usedQuantity = max(1, min((int)$quantity, $limits['maxQuantity']));
        }
        
        // 验证品质参数
        $usedQuality = min((int)$quality, $limits['maxQuality']);

        try {
            $this->db->beginTransaction();

            // 创建订单（采购模式，价格为0，item_code存储在rcon_command字段，quality存储在备注字段）
            $this->db->execute(
                "INSERT INTO orders (user_id, item_id, player_name, quantity, status, total_price, rcon_command, remarks, created_at) 
                 VALUES (:user_id, 0, :player_name, :quantity, 'pending', 0, :rcon_command, :remarks, :created_at)",
                [
                    ':user_id' => $userId,
                    ':player_name' => $playerName,
                    ':quantity' => $usedQuantity,
                    ':rcon_command' => $itemCode, // 用 rcon_command 字段存储 item_code
                    ':remarks' => $usedQuality, // 用 remarks 字段存储品质
                    ':created_at' => time()
                ]
            );

            $orderId = (int)$this->db->lastInsertId();

            // 🆕 生成订单号
            $orderNumber = $this->generateOrderNumber();
            $this->db->execute(
                "UPDATE orders SET order_number = :order_number WHERE id = :order_id",
                [
                    ':order_number' => $orderNumber,
                    ':order_id' => $orderId
                ]
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => '采购订单创建成功',
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'quantity' => $usedQuantity,
                'quality' => $usedQuality
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => '订单创建失败: ' . $e->getMessage()];
        }
    }

    public function batchCreatePurchaseOrders($userId, array $items, $playerName = '待定'): array
    {
        error_log("[DEBUG] batchCreatePurchaseOrders called with userId: " . var_export($userId, true));
        error_log("[DEBUG] batchCreatePurchaseOrders items count: " . count($items));
        error_log("[DEBUG] batchCreatePurchaseOrders items: " . var_export($items, true));
        
        if (empty($items)) {
            error_log("[DEBUG] Empty items array");
            return [['success' => false, 'error' => '购物车为空']];
        }

        $vipService = new \App\Services\VipService();
        $itemService = new \App\Services\ItemService();

        error_log("[DEBUG] Calling checkVipPermission...");
        list($allowed, $errorMsg, $limits) = $vipService->checkVipPermission($userId, 'purchase');
        error_log("[DEBUG] checkVipPermission - allowed: " . var_export($allowed, true) . ", error: " . var_export($errorMsg, true) . ", limits: " . var_export($limits, true));
        
        if (!$allowed) {
            error_log("[DEBUG] VIP permission denied: " . $errorMsg);
            return [['success' => false, 'error' => $errorMsg]];
        }

        // 检查今日剩余订单次数
        $todayStart = strtotime(date('Y-m-d') . ' 00:00:00');
        error_log("[DEBUG] todayStart: " . $todayStart);
        
        $todayOrderCount = $this->db->query(
            "SELECT COUNT(*) as cnt FROM orders WHERE user_id = :uid AND created_at >= :start AND status != 'cancelled'",
            [':uid' => (int)$userId, ':start' => $todayStart]
        );
        $usedToday = (int)($todayOrderCount[0]['cnt'] ?? 0);
        $dailyLimit = (int)($limits['dailyLimit'] ?? 5);
        error_log("[DEBUG] usedToday: {$usedToday}, dailyLimit: {$dailyLimit}");

        if ($usedToday >= $dailyLimit) {
            error_log("[DEBUG] Daily limit exceeded: {$usedToday}/{$dailyLimit}");
            return [['success' => false, 'error' => "今日采购次数已用完（{$usedToday}/{$dailyLimit}），请明天再试"]];
        }

        $results = [];
        $validItems = [];

        try {
            error_log("[DEBUG] Starting transaction...");
            $this->db->beginTransaction();

            foreach ($items as $index => $item) {
                error_log("[DEBUG] Processing item {$index}: " . var_export($item, true));
                $itemCode = $item['item_code'] ?? $item['code'] ?? '';
                $quality = (int)($item['quality'] ?? 0);
                $quantity = isset($item['quantity']) ? (int)$item['quantity'] : null;

                if (empty($itemCode)) {
                    error_log("[DEBUG] Empty item code for item {$index}");
                    $results[] = ['success' => false, 'error' => '物品代码为空', 'item_code' => $itemCode];
                    continue;
                }

                $itemStatus = $itemService->getItemStatus($itemCode);
                error_log("[DEBUG] Item status for {$itemCode}: " . var_export($itemStatus, true));
                if (!$itemStatus) {
                    error_log("[DEBUG] Item not found: {$itemCode}");
                    $results[] = ['success' => false, 'error' => "物品 {$itemCode} 不存在", 'item_code' => $itemCode];
                    continue;
                }
                if (!$itemStatus['is_enabled']) {
                    error_log("[DEBUG] Item disabled: {$itemCode}");
                    $results[] = ['success' => false, 'error' => "物品 {$itemCode} 已禁用", 'item_code' => $itemCode];
                    continue;
                }
                
                // 调试：检查物品信息
                error_log("[DEBUG] Item code: {$itemCode}, quantity: {$quantity}, quality: {$quality}");

                $maxQty = (int)($limits['maxQuantity'] ?? 10);
                $usedQuantity = $quantity !== null ? max(1, min((int)$quantity, $maxQty)) : $maxQty;
                $maxQuality = (int)($limits['maxQuality'] ?? 0);
                $usedQuality = min((int)$quality, $maxQuality);
                error_log("[DEBUG] Using quantity: {$usedQuantity}, quality: {$usedQuality}");

                // 同步 shop_items（如需要）
                $shopItem = $this->db->query(
                    "SELECT id FROM shop_items WHERE item_code = :item_code AND is_active = 1 LIMIT 1",
                    [':item_code' => $itemCode]
                );
                $realItemId = isset($shopItem[0]['id']) ? (int)$shopItem[0]['id'] : null;
                $itemName = $itemCode;
                error_log("[DEBUG] Shop item found: " . var_export($shopItem, true) . ", realItemId: " . $realItemId);

                if ($realItemId === null) {
                    error_log("[DEBUG] Shop item not found, creating new one for {$itemCode}");
                    $itemInfo = $this->db->query(
                        "SELECT item_name, category FROM items WHERE item_code = :item_code AND is_enabled = 1 LIMIT 1",
                        [':item_code' => $itemCode]
                    );
                    error_log("[DEBUG] Item info from items table: " . var_export($itemInfo, true));
                    if (empty($itemInfo)) {
                        error_log("[DEBUG] Item not found in items table: {$itemCode}");
                        $results[] = ['success' => false, 'error' => "物品 {$itemCode} 不存在或已禁用", 'item_code' => $itemCode];
                        continue;
                    }
                    $itemName = $itemInfo[0]['item_name'] ?? $itemCode;
                    error_log("[DEBUG] Creating shop item: {$itemName} ({$itemCode})");
                    $this->db->execute(
                        "INSERT INTO shop_items (item_name, item_code, price, stock, quality, category, is_active, created_at) VALUES (:item_name, :item_code, 0, -1, 0, :category, 1, :created_at)",
                        [':item_name' => $itemName, ':item_code' => $itemCode, ':category' => $itemInfo[0]['category'] ?? 'other', ':created_at' => time()]
                    );
                    $realItemId = (int)$this->db->lastInsertId();
                    error_log("[DEBUG] Created shop item with id: " . $realItemId);
                } else {
                    // 从shop_items获取物品名称
                    $shopItemInfo = $this->db->query(
                        "SELECT item_name FROM shop_items WHERE id = :id LIMIT 1",
                        [':id' => $realItemId]
                    );
                    error_log("[DEBUG] Shop item info: " . var_export($shopItemInfo, true));
                    if (!empty($shopItemInfo)) {
                        $itemName = $shopItemInfo[0]['item_name'] ?? $itemCode;
                    }
                }

                $validItems[] = [
                    'item_id' => $realItemId,
                    'item_code' => $itemCode,
                    'item_name' => $itemName,
                    'quantity' => $usedQuantity,
                    'quality' => $usedQuality
                ];
                error_log("[DEBUG] Added to valid items: " . var_export($validItems[count($validItems)-1], true));
                $results[] = ['success' => true, 'item_code' => $itemCode, 'quantity' => $usedQuantity, 'quality' => $usedQuality];
            }

            // 所有物品验证通过后，创建 1 个聚合订单
            $successCount = count(array_filter($results, fn($r) => $r['success']));
            error_log("[DEBUG] Success count: " . $successCount);
            if ($successCount > 0 && !empty($validItems)) {
                $orderNumber = $this->generateOrderNumber();
                $itemsJson = json_encode($validItems, JSON_UNESCAPED_UNICODE);
                error_log("[DEBUG] Creating batch order with items: " . $itemsJson);

                try {
                    // 先检查是否存在外骨骼模块的shop_item记录
                    $shopItem = $this->db->query(
                        "SELECT id FROM shop_items WHERE item_code = 'exoskeleton-equipment' AND is_active = 1 LIMIT 1"
                    );
                    
                    $itemId = 0;
                    if (!empty($shopItem)) {
                        $itemId = (int)$shopItem[0]['id'];
                    } else {
                        // 如果不存在，创建一个
                        $this->db->execute(
                            "INSERT INTO shop_items (item_name, item_code, price, stock, quality, category, is_active, created_at) 
                             VALUES ('外骨骼模块', 'exoskeleton-equipment', 0, -1, 0, 'equipment', 1, :created_at)",
                            [':created_at' => time()]
                        );
                        $itemId = (int)$this->db->lastInsertId();
                    }
                    
                    $this->db->execute(
                        "INSERT INTO orders (user_id, item_id, player_name, quantity, status, total_price, rcon_command, remarks, created_at)
                         VALUES (:user_id, :item_id, :player_name, :quantity, 'pending', 0, :rcon_command, :remarks, :created_at)",
                        [
                            ':user_id' => $userId,
                            ':item_id' => $itemId,
                            ':player_name' => $playerName,
                            ':quantity' => $successCount,
                            ':rcon_command' => $itemsJson,
                            ':remarks' => '',
                            ':created_at' => time()
                        ]
                    );
                    $orderId = (int)$this->db->lastInsertId();
                    error_log("[DEBUG] Inserted order with id: " . $orderId);
                    
                    $this->db->execute(
                        "UPDATE orders SET order_number = :order_number WHERE id = :order_id",
                        [':order_number' => $orderNumber, ':order_id' => $orderId]
                    );
                    error_log("[DEBUG] Updated order number to: " . $orderNumber);

                    error_log("[DEBUG] Created batch order #{$orderId} with number {$orderNumber}, {$successCount} items");

                    // 更新每个结果项，添加订单信息
                    foreach ($results as &$result) {
                        if ($result['success']) {
                            $result['order_id'] = $orderId;
                            $result['order_number'] = $orderNumber;
                        }
                    }
                    error_log("[DEBUG] Updated results with order info");
                } catch (\Exception $e) {
                    error_log("[DEBUG] Order creation failed: " . $e->getMessage());
                    // 回滚事务
                    $this->db->rollBack();
                    return [['success' => false, 'error' => '订单创建失败: ' . $e->getMessage()]];
                }
            }

            $this->db->commit();
            error_log("[DEBUG] Transaction committed");
        } catch (\Exception $e) {
            error_log("[DEBUG] Exception caught: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->db->rollBack();
            return [['success' => false, 'error' => '批量创建失败: ' . $e->getMessage()]];
        }

        error_log("[DEBUG] Returning results: " . var_export($results, true));
        return $results;
    }
}
