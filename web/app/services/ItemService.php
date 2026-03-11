<?php

namespace App\Services;

class ItemService
{
    private StateService $stateService;
    private string $itemsFile;
    private string $cooldownFile = 'requestItemCooldown';
    private string $confirmFile = 'itemRequestConfirm';
    private ?array $itemsCache = null;

    public function __construct(StateService $stateService = null, string $itemsFile = null)
    {
        $this->stateService = $stateService ?? new StateService();
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
        $cooldowns = $this->stateService->loadState($this->cooldownFile);
        $cooldowns[$player] = time() + $duration;
        $this->stateService->saveState($this->cooldownFile, $cooldowns);
    }

    public function checkItemRequestCooldown(string $player): bool
    {
        $cooldowns = $this->stateService->loadState($this->cooldownFile);
        
        if (!isset($cooldowns[$player])) {
            return true;
        }
        
        if (time() > $cooldowns[$player]) {
            unset($cooldowns[$player]);
            $this->stateService->saveState($this->cooldownFile, $cooldowns);
            return true;
        }
        
        return false;
    }

    public function getItemRequestCooldownRemaining(string $player): int
    {
        $cooldowns = $this->stateService->loadState($this->cooldownFile);
        
        if (!isset($cooldowns[$player])) {
            return 0;
        }
        
        return max(0, $cooldowns[$player] - time());
    }

    public function saveItemRequestConfirm(string $player, string $itemName, int $count): void
    {
        $confirms = $this->stateService->loadState($this->confirmFile);
        $confirms[$player] = [
            'itemName' => $itemName,
            'count' => $count,
            'timestamp' => time()
        ];
        $this->stateService->saveState($this->confirmFile, $confirms);
    }

    public function loadItemRequestConfirm(string $player): ?array
    {
        $confirms = $this->stateService->loadState($this->confirmFile);
        return $confirms[$player] ?? null;
    }

    public function deleteItemRequestConfirm(string $player): void
    {
        $confirms = $this->stateService->loadState($this->confirmFile);
        unset($confirms[$player]);
        $this->stateService->saveState($this->confirmFile, $confirms);
    }

    public function clearCache(): void
    {
        $this->itemsCache = null;
    }
}
