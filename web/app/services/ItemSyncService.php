<?php

namespace App\Services;

class ItemSyncService
{
    private string $itemsFile;
    private string $stateFile = 'itemSyncStatus';
    private string $defaultRemoteUrl = 'https://gitee.com/sive/factorioitem/raw/main/items.json';
    private StateService $stateService;

    public function __construct(StateService $stateService = null, string $itemsFile = null)
    {
        $this->stateService = $stateService ?? new StateService();
        $this->itemsFile = $itemsFile ?? dirname(__DIR__, 2) . '/config/game/items.json';
    }

    public function syncFromRemote(string $url = null): array
    {
        $url = $url ?? $this->defaultRemoteUrl;

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            return ['success' => false, 'error' => '网络请求失败: 无法连接到远程服务器'];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || empty($data)) {
            return ['success' => false, 'error' => '远程数据格式无效'];
        }

        $hasNestedCategory = false;
        foreach ($data as $value) {
            if (is_array($value)) {
                $hasNestedCategory = true;
                break;
            }
        }
        if (!$hasNestedCategory) {
            return ['success' => false, 'error' => '远程数据格式无效'];
        }

        $dir = dirname($this->itemsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $writeResult = file_put_contents($this->itemsFile, $jsonContent);
        if ($writeResult === false) {
            return ['success' => false, 'error' => '文件写入失败'];
        }

        $totalItems = 0;
        $categories = [];
        foreach ($data as $categoryName => $items) {
            if (is_array($items)) {
                $count = count($items);
                $categories[$categoryName] = $count;
                $totalItems += $count;
            }
        }

        $syncState = [
            'last_sync' => time(),
            'source' => $url,
            'total_items' => $totalItems,
            'categories' => $categories,
        ];
        $this->stateService->saveState($this->stateFile, $syncState);

        return [
            'success' => true,
            'message' => '物品数据同步成功',
            'total' => $totalItems,
            'categories' => $categories,
        ];
    }

    public function getSyncStatus(): array
    {
        $syncState = $this->stateService->loadState($this->stateFile);

        $result = [
            'last_sync' => null,
            'source' => '',
            'total_items' => 0,
            'categories' => [],
            'file_exists' => file_exists($this->itemsFile),
            'file_mtime' => null,
        ];

        if (!empty($syncState['last_sync'])) {
            $result['last_sync'] = $syncState['last_sync'];
            $result['source'] = $syncState['source'] ?? '';
            $result['total_items'] = $syncState['total_items'] ?? 0;
            $result['categories'] = $syncState['categories'] ?? [];
        } elseif (file_exists($this->itemsFile)) {
            $result['file_mtime'] = filemtime($this->itemsFile);
        }

        return $result;
    }

    public function getRemoteUrl(): string
    {
        return $this->defaultRemoteUrl;
    }

    public function setRemoteUrl(string $url): void
    {
        $this->defaultRemoteUrl = $url;
    }
}
