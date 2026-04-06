<?php

namespace App\Controllers;

use App\Services\CartService;
use App\Core\Response;

class CartController
{
    private $authService;

    public function __construct()
    {
        $this->authService = new \App\Services\AuthService();
    }

    private function getUserId(): int
    {
        $user = $this->authService->getCurrentUser();
        if ($user === null) {
            return 0;
        }
        return crc32($user['username']);
    }

    public static function handleGetCart()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $userId = $instance->getUserId();
        $cartService = new CartService($userId);
        $items = $cartService->getCartItems();

        Response::success(['items' => $items]);
    }

    public static function handleAddToCart()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $itemCode = trim($_POST['item_code'] ?? '');
        $itemName = trim($_POST['item_name'] ?? '');
        $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
        $quality = isset($_POST['quality']) ? (int)$_POST['quality'] : 0;

        if (empty($itemCode) || empty($itemName)) {
            Response::error('物品代码和名称不能为空');
        }

        $userId = $instance->getUserId();
        $cartService = new CartService($userId);
        $result = $cartService->addItem($itemCode, $itemName, $quantity, $quality);

        if ($result['success']) {
            Response::success(['count' => $cartService->getCartCount()], $result['message']);
        }

        Response::error($result['error'] ?? '添加失败');
    }

    public static function handleUpdateCart()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $itemCode = trim($_POST['item_code'] ?? '');
        $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
        $quality = isset($_POST['quality']) ? (int)$_POST['quality'] : 0;

        if (empty($itemCode)) {
            Response::error('物品代码不能为空');
        }

        $userId = $instance->getUserId();
        $cartService = new CartService($userId);
        $result = $cartService->updateItem($itemCode, $quantity, $quality);

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error'] ?? '更新失败');
    }

    public static function handleRemoveFromCart()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $itemCode = trim($_POST['item_code'] ?? '');

        if (empty($itemCode)) {
            Response::error('物品代码不能为空');
        }

        $userId = $instance->getUserId();
        $cartService = new CartService($userId);
        $result = $cartService->removeItem($itemCode);

        if ($result['success']) {
            Response::success(['count' => $cartService->getCartCount()], $result['message']);
        }

        Response::error($result['error'] ?? '移除失败');
    }

    public static function handleClearCart()
    {
        $instance = new self();
        $instance->authService->requireLogin();

        $userId = $instance->getUserId();
        $cartService = new CartService($userId);
        $result = $cartService->clearCart();

        if ($result['success']) {
            Response::success(null, $result['message']);
        }

        Response::error($result['error'] ?? '清空失败');
    }
}
