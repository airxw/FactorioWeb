<?php

namespace App\Services;

use App\Core\Database;

class ItemService
{
    private Database $db;
    private string $itemsFile;
    private ?array $itemsCache = null;

    public function __construct(Database $db = null, string $itemsFile = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->itemsFile = $itemsFile ?? dirname(__DIR__, 2) . '/config/game/items.json';
    }

    public function loadItems(): array
    {
        if ($this->itemsCache !== null) {
            return $this->itemsCache;
        }

        if (!file_exists($this->itemsFile)) {
            return [];
        }

        $content = file_get_contents($this->itemsFile);
        $this->itemsCache = json_decode($content, true) ?? [];
        return $this->itemsCache;
    }

    public function searchItems(string $query, int $limit = 10): array
    {
        $items = $this->loadItems();
        $results = [];
        $query = strtolower($query);

        foreach ($items as $name => $data) {
            if (strpos(strtolower($name), $query) !== false) {
                $results[] = [
                    'name' => $name,
                    'localizedName' => $data['localizedName'] ?? $name,
                    'type' => $data['type'] ?? 'item'
                ];
                
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    public function resolveItemName(string $input, array &$suggestions = []): ?string
    {
        $items = $this->loadItems();
        $input = strtolower(trim($input));

        if (isset($items[$input])) {
            return $input;
        }

        foreach ($items as $name => $data) {
            $localizedName = strtolower($data['localizedName'] ?? '');
            if ($localizedName === $input || strtolower($name) === $input) {
                return $name;
            }
        }

        $suggestions = $this->searchItems($input, 5);
        return null;
    }

    public function saveItemRequestCooldown(string $player, int $duration = 3600): void
    {
        $this->db->execute(
            'INSERT OR REPLACE INTO item_requests (user_id, item_name, count, status, cooldown_until) VALUES ((SELECT id FROM users WHERE username = :player), \'\', 0, \'cooldown\', :until)',
            [':player' => $player, ':until' => time() + $duration]
        );
    }

    public function checkItemRequestCooldown(string $player): bool
    {
        $result = $this->db->query(
            'SELECT cooldown_until FROM item_requests ir JOIN users u ON u.id = ir.user_id WHERE u.username = :player AND ir.status = \'cooldown\' AND ir.cooldown_until > :now ORDER BY ir.cooldown_until DESC LIMIT 1',
            [':player' => $player, ':now' => time()]
        );

        if (empty($result)) {
            return true;
        }

        if (time() > (int)$result[0]['cooldown_until']) {
            $this->db->execute(
                'UPDATE item_requests SET status = \'expired\' WHERE user_id = (SELECT id FROM users WHERE username = :player) AND status = \'cooldown\' AND cooldown_until <= :now',
                [':player' => $player, ':now' => time()]
            );
            return true;
        }

        return false;
    }

    public function getItemRequestCooldownRemaining(string $player): int
    {
        $result = $this->db->query(
            'SELECT MAX(cooldown_until) as max_until FROM item_requests ir JOIN users u ON u.id = ir.user_id WHERE u.username = :player AND ir.status = \'cooldown\'',
            [':player' => $player]
        );

        if (empty($result) || !$result[0]['max_until']) {
            return 0;
        }

        return max(0, (int)$result[0]['max_until'] - time());
    }

    public function saveItemRequestConfirm(string $player, string $itemName, int $count): void
    {
        $this->db->execute(
            'INSERT OR REPLACE INTO item_requests (user_id, item_name, count, status, created_at) VALUES ((SELECT id FROM users WHERE username = :player), :itemName, :count, \'confirm\', :created)',
            [':player' => $player, ':itemName' => $itemName, ':count' => $count, ':created' => time()]
        );
    }

    public function loadItemRequestConfirm(string $player): ?array
    {
        $result = $this->db->query(
            'SELECT item_name as itemName, count, created_at as timestamp FROM item_requests ir JOIN users u ON u.id = ir.user_id WHERE u.username = :player AND ir.status = \'confirm\' ORDER BY ir.created_at DESC LIMIT 1',
            [':player' => $player]
        );
        return $result[0] ?? null;
    }

    public function deleteItemRequestConfirm(string $player): void
    {
        $this->db->execute(
            'UPDATE item_requests SET status = \'cancelled\' WHERE user_id = (SELECT id FROM users WHERE username = :player) AND status = \'confirm\'',
            [':player' => $player]
        );
    }

    public function clearCache(): void
    {
        $this->itemsCache = null;
    }

    public function getItemsByCategory(string $category): array
    {
        $items = $this->loadItems();
        return $items[$category] ?? [];
    }

    public function getAllCategories(): array
    {
        $items = $this->loadItems();
        $labelMap = [
            'logistics' => '物流',
            'production' => '生产',
            'combat' => '战斗',
            'intermediate' => '中间产品',
            'space-age' => '太空时代',
            'equipment' => '装备模块',
            'other' => '其他',
        ];
        $order = ['logistics', 'production', 'combat', 'intermediate', 'space-age', 'equipment', 'other'];
        $result = [];
        foreach ($order as $cat) {
            if (isset($items[$cat])) {
                $result[] = [
                    'name' => $cat,
                    'label' => $labelMap[$cat],
                    'count' => count($items[$cat]),
                ];
            }
        }
        return $result;
    }

    public function getItemCount(): int
    {
        $items = $this->loadItems();
        $total = 0;
        foreach ($items as $category) {
            $total += count($category);
        }
        return $total;
    }

    public function getLastSyncTime(): ?int
    {
        if (file_exists($this->itemsFile)) {
            return filemtime($this->itemsFile);
        }
        return null;
    }

    public function getRemoteUrl(): string
    {
        return 'https://gitee.com/sive/factorioitem/raw/main/items.json';
    }

    public function syncItemsFromRemote(?string $url = null): array
    {
        $remoteUrl = $url ?? $this->getRemoteUrl();

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'FactorioWeb/1.0'
            ]
        ]);

        $content = @file_get_contents($remoteUrl, false, $context);
        if ($content === false) {
            return ['success' => false, 'error' => '无法连接远程服务器'];
        }

        $remoteData = json_decode($content, true);
        if (!$remoteData || !is_array($remoteData)) {
            return ['success' => false, 'error' => '远程数据格式错误'];
        }

        $saved = @file_put_contents($this->itemsFile, json_encode($remoteData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($saved === false) {
            return ['success' => false, 'error' => '无法保存到本地文件'];
        }

        $this->clearCache();
        $this->syncItemsToDatabase($remoteData);

        return [
            'success' => true,
            'total' => array_sum(array_map('count', $remoteData)),
            'categories' => count($remoteData),
            'message' => '同步成功'
        ];
    }

    private function syncItemsToDatabase(array $categories): void
    {
        $now = time();
        foreach ($categories as $category => $items) {
            foreach ($items as $code => $name) {
                $existing = $this->db->query(
                    'SELECT item_code FROM items WHERE item_code = :code',
                    [':code' => $code]
                );

                if (empty($existing)) {
                    $this->db->execute(
                        'INSERT OR IGNORE INTO items (item_code, item_name, category, is_enabled, last_sync_at) VALUES (:code, :name, :cat, 1, :time)',
                        [':code' => $code, ':name' => $name, ':cat' => $category, ':time' => $now]
                    );
                } else {
                    $this->db->execute(
                        'UPDATE items SET item_name = :name, category = :cat, last_sync_at = :time WHERE item_code = :code',
                        [':name' => $name, ':cat' => $category, ':time' => $now, ':code' => $code]
                    );
                }
            }
        }
    }

    public function getItemStatus(string $itemCode): ?array
    {
        error_log("[ItemService] getItemStatus called for: {$itemCode}");
        $result = $this->db->query(
            'SELECT item_code, item_name, category, is_enabled, last_sync_at FROM items WHERE item_code = :code',
            [':code' => $itemCode]
        );
        error_log("[ItemService] getItemStatus result: " . var_export($result, true));
        return $result[0] ?? null;
    }

    public function setItemStatus(string $itemCode, bool $isEnabled): bool
    {
        $rows = $this->db->execute(
            'UPDATE items SET is_enabled = :enabled WHERE item_code = :code',
            [':enabled' => $isEnabled ? 1 : 0, ':code' => $itemCode]
        );
        return $rows > 0;
    }

    public function batchSetItemsStatus(array $itemCodes, bool $isEnabled): int
    {
        if (empty($itemCodes)) {
            return 0;
        }

        $placeholders = [];
        $params = [':enabled' => $isEnabled ? 1 : 0];
        foreach ($itemCodes as $i => $code) {
            $key = ':code' . $i;
            $placeholders[] = $key;
            $params[$key] = $code;
        }

        $sql = 'UPDATE items SET is_enabled = :enabled WHERE item_code IN (' . implode(',', $placeholders) . ')';
        return $this->db->execute($sql, $params);
    }

    public function getItemsWithStatus(?string $category = null, ?string $search = null, string $sortField = 'name', string $sortOrder = 'asc', string $status = ''): array
    {
        $categories = $this->loadItems();
        $result = [];
        $missingItems = [];

        $catsToProcess = $category ? [$category] : array_keys($categories);
        $searchLower = $search ? strtolower($search) : '';
        $filterStatus = strtolower($status);

        foreach ($catsToProcess as $cat) {
            if (!isset($categories[$cat])) continue;

            foreach ($categories[$cat] as $code => $name) {
                if ($searchLower) {
                    if (strpos(strtolower($code), $searchLower) === false &&
                        strpos(strtolower($name), $searchLower) === false) {
                        continue;
                    }
                }

                $dbItem = $this->getItemStatus($code);

                if (empty($dbItem)) {
                    $missingItems[] = [
                        'code' => $code,
                        'name' => $name,
                        'category' => $cat
                    ];
                    $isEnabled = true;
                } else {
                    $isEnabled = (bool)$dbItem['is_enabled'];
                }

                if ($filterStatus !== '') {
                    if ($filterStatus === 'enabled' && !$isEnabled) continue;
                    if ($filterStatus === 'disabled' && $isEnabled) continue;
                }

                $result[] = [
                    'code' => $code,
                    'name' => $name,
                    'category' => $cat,
                    'is_enabled' => $isEnabled,
                    'in_database' => !empty($dbItem)
                ];
            }
        }

        if (!empty($missingItems)) {
            $this->batchInsertItems($missingItems);
        }

        $sortOrderLower = strtolower($sortOrder);

        usort($result, function($a, $b) use ($sortField, $sortOrderLower) {
            $valA = $a[$sortField] ?? '';
            $valB = $b[$sortField] ?? '';

            if ($sortField === 'is_enabled') {
                $valA = (int)$valA;
                $valB = (int)$valB;
            } else {
                $valA = strtolower((string)$valA);
                $valB = strtolower((string)$valB);
            }

            if ($valA === $valB) return 0;

            if ($sortOrderLower === 'desc') {
                return $valA > $valB ? -1 : 1;
            }
            return $valA < $valB ? -1 : 1;
        });

        return $result;
    }

    private function batchInsertItems(array $items): void
    {
        $now = time();
        $this->db->beginTransaction();

        try {
            foreach ($items as $item) {
                $this->db->execute(
                    'INSERT OR IGNORE INTO items (item_code, item_name, category, is_enabled, last_sync_at) VALUES (:code, :name, :cat, 1, :time)',
                    [':code' => $item['code'], ':name' => $item['name'], ':cat' => $item['category'], ':time' => $now]
                );
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('[ItemService] batchInsertItems error: ' . $e->getMessage());
        }
    }

    public function getItemsStats(): array
    {
        $stats = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as enabled,
                SUM(CASE WHEN is_enabled = 0 THEN 1 ELSE 0 END) as disabled
             FROM items"
        );

        $row = $stats[0] ?? ['total' => 0, 'enabled' => 0, 'disabled' => 0];
        return [
            'total' => (int)$row['total'],
            'enabled' => (int)$row['enabled'],
            'disabled' => (int)$row['disabled']
        ];
    }
}
