<?php

namespace App\Services;

class PlayerService
{
    private StateService $stateService;
    private string $historyFile = 'playerHistory';

    public function __construct(StateService $stateService = null)
    {
        $this->stateService = $stateService ?? new StateService();
    }

    public function loadPlayerHistory(): array
    {
        return $this->stateService->loadState($this->historyFile);
    }

    public function savePlayerHistory(array $history): void
    {
        $this->stateService->saveState($this->historyFile, $history);
    }

    public function isPlayerFirstJoin(string $player): bool
    {
        $history = $this->loadPlayerHistory();
        return !isset($history[$player]);
    }

    public function recordPlayerJoin(string $player, string $ip = null): void
    {
        $history = $this->loadPlayerHistory();
        
        if (!isset($history[$player])) {
            $history[$player] = [
                'firstJoin' => time(),
                'lastJoin' => time(),
                'joinCount' => 1,
                'ip' => $ip
            ];
        } else {
            $history[$player]['lastJoin'] = time();
            $history[$player]['joinCount']++;
            if ($ip) {
                $history[$player]['ip'] = $ip;
            }
        }
        
        $this->savePlayerHistory($history);
    }

    public function recordPlayerLeave(string $player): void
    {
        $history = $this->loadPlayerHistory();
        
        if (isset($history[$player])) {
            $history[$player]['lastLeave'] = time();
            $this->savePlayerHistory($history);
        }
    }

    public function getPlayerInfo(string $player): ?array
    {
        $history = $this->loadPlayerHistory();
        return $history[$player] ?? null;
    }

    public function getPlayerIp(string $player): ?string
    {
        $info = $this->getPlayerInfo($player);
        return $info['ip'] ?? null;
    }

    public function getOnlinePlayers(): array
    {
        return [];
    }

    public function getOnlinePlayerCount(): int
    {
        return 0;
    }

    public function getAllPlayers(): array
    {
        return $this->loadPlayerHistory();
    }

    public function getRecentPlayers(int $limit = 10): array
    {
        $history = $this->loadPlayerHistory();
        
        uasort($history, function($a, $b) {
            return ($b['lastJoin'] ?? 0) - ($a['lastJoin'] ?? 0);
        });
        
        return array_slice($history, 0, $limit, true);
    }
}
