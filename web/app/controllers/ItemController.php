<?php

namespace App\Controllers;

use App\Services\ItemService;
use App\Services\ItemSyncService;
use App\Core\Response;

class ItemController
{
    public static function handleGetItems(): void
    {
        $itemService = new ItemService();
        $category = $_GET['category'] ?? null;

        if ($category !== null) {
            $categories = [$category => $itemService->getItemsByCategory($category)];
            $total = count($categories[$category]);
        } else {
            $categories = $itemService->loadItems();
            $total = $itemService->getItemCount();
        }

        $categoryList = $itemService->getAllCategories();

        Response::success([
            'categories' => $categories,
            'total' => $total,
            'category_list' => $categoryList,
        ]);
    }

    public static function handleSearchItems(): void
    {
        $query = $_GET['q'] ?? null;

        if ($query === null || trim($query) === '') {
            Response::error('缺少搜索关键词');
            return;
        }

        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));

        $itemService = new ItemService();
        $items = $itemService->searchItems($query, $limit);

        Response::success([
            'query' => $query,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public static function handleGetCategories(): void
    {
        $itemService = new ItemService();
        $categories = $itemService->getAllCategories();
        $totalItems = $itemService->getItemCount();

        Response::success([
            'categories' => $categories,
            'total_items' => $totalItems,
        ]);
    }

    public static function handleSyncItems(): void
    {
        $itemService = new ItemService();
        $url = $_POST['url'] ?? null;
        $result = $itemService->syncItemsFromRemote($url);

        if ($result['success']) {
            Response::success($result, $result['message']);
        }

        Response::error($result['error'] ?? '同步失败');
    }

    public static function handleGetItemsWithStatus(): void
    {
        $itemService = new ItemService();
        $category = $_GET['category'] ?? null;
        $search = $_GET['search'] ?? null;
        $status = $_GET['status'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $sortField = $_GET['sort_field'] ?? 'name';
        $sortOrder = $_GET['sort_order'] ?? 'asc';

        $items = $itemService->getItemsWithStatus($category, $search, $sortField, $sortOrder, $status);
        $total = count($items);
        $stats = $itemService->getItemsStats();

        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);

        Response::success([
            'items' => $pagedItems,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'stats' => $stats
        ]);
    }

    public static function handleSetItemStatus(): void
    {
        $itemCode = $_POST['item_code'] ?? null;
        $isEnabled = $_POST['is_enabled'] ?? null;

        if (!$itemCode || $isEnabled === null) {
            Response::error('缺少必要参数');
            return;
        }

        $itemService = new ItemService();
        $success = $itemService->setItemStatus($itemCode, (int)$isEnabled === 1);

        if ($success) {
            Response::success(['item_code' => $itemCode, 'is_enabled' => (int)$isEnabled === 1], '状态已更新');
        }

        Response::error('更新失败，物品不存在');
    }

    public static function handleBatchItemStatus(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $itemCodes = $input['item_codes'] ?? null;
        $isEnabled = $input['is_enabled'] ?? null;

        if (!$itemCodes || !is_array($itemCodes) || $isEnabled === null) {
            Response::error('缺少必要参数');
            return;
        }

        $itemService = new ItemService();
        $affected = $itemService->batchSetItemsStatus($itemCodes, (int)$isEnabled === 1);

        Response::success([
            'affected' => $affected,
            'is_enabled' => (int)$isEnabled === 1
        ], "已更新 {$affected} 个物品状态");
    }
}
