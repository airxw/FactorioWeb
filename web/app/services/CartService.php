<?php

namespace App\Services;

use App\Core\Database;

class CartService
{
    private $db;
    private $userId;

    public function __construct(int $userId)
    {
        $this->db = Database::getInstance();
        $this->userId = $userId;
    }

    public function getCartItems(): array
    {
        $result = $this->db->query(
            "SELECT item_code, item_name, quantity, quality, created_at FROM shopping_cart WHERE user_id = :user_id ORDER BY created_at DESC",
            [':user_id' => $this->userId]
        );
        return $result ?: [];
    }

    public function addItem(string $itemCode, string $itemName, int $quantity = 1, int $quality = 0): array
    {
        $quantity = max(1, $quantity);
        $quality = max(0, min(4, $quality));

        $existing = $this->db->query(
            "SELECT id FROM shopping_cart WHERE user_id = :user_id AND item_code = :item_code",
            [':user_id' => $this->userId, ':item_code' => $itemCode]
        );

        if (!empty($existing)) {
            $this->db->execute(
                "UPDATE shopping_cart SET quantity = :quantity, quality = :quality, updated_at = :updated_at WHERE user_id = :user_id AND item_code = :item_code",
                [
                    ':quantity' => $quantity,
                    ':quality' => $quality,
                    ':updated_at' => time(),
                    ':user_id' => $this->userId,
                    ':item_code' => $itemCode
                ]
            );
            return ['success' => true, 'message' => '购物车已更新'];
        }

        $this->db->execute(
            "INSERT INTO shopping_cart (user_id, item_code, item_name, quantity, quality) VALUES (:user_id, :item_code, :item_name, :quantity, :quality)",
            [
                ':user_id' => $this->userId,
                ':item_code' => $itemCode,
                ':item_name' => $itemName,
                ':quantity' => $quantity,
                ':quality' => $quality
            ]
        );

        return ['success' => true, 'message' => '已加入购物车'];
    }

    public function removeItem(string $itemCode): array
    {
        $this->db->execute(
            "DELETE FROM shopping_cart WHERE user_id = :user_id AND item_code = :item_code",
            [':user_id' => $this->userId, ':item_code' => $itemCode]
        );
        return ['success' => true, 'message' => '已从购物车移除'];
    }

    public function updateItem(string $itemCode, int $quantity, int $quality): array
    {
        $quantity = max(1, $quantity);
        $quality = max(0, min(4, $quality));

        $this->db->execute(
            "UPDATE shopping_cart SET quantity = :quantity, quality = :quality, updated_at = :updated_at WHERE user_id = :user_id AND item_code = :item_code",
            [
                ':quantity' => $quantity,
                ':quality' => $quality,
                ':updated_at' => time(),
                ':user_id' => $this->userId,
                ':item_code' => $itemCode
            ]
        );
        return ['success' => true, 'message' => '购物车已更新'];
    }

    public function clearCart(): array
    {
        $this->db->execute(
            "DELETE FROM shopping_cart WHERE user_id = :user_id",
            [':user_id' => $this->userId]
        );
        return ['success' => true, 'message' => '购物车已清空'];
    }

    public function getCartCount(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM shopping_cart WHERE user_id = :user_id",
            [':user_id' => $this->userId]
        );
        return (int)($result[0]['cnt'] ?? 0);
    }
}
