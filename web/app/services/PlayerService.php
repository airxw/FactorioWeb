<?php

namespace App\Services;

class PlayerService
{
    private StateService $stateService;
    private string $historyFile = 'playerHistory';
    private string $historyDir;
    private ?string $configName = null;

    public function __construct(StateService $stateService = null, string $configName = null)
    {
        $this->stateService = $stateService ?? new StateService();
        $this->historyDir = dirname(__DIR__, 2) . '/config/state/playerHistory';
        $this->configName = $configName;
    }

    public function setConfigName(string $configName): void
    {
        $this->configName = $configName;
    }

    private function getHistoryFile(): string
    {
        if ($this->configName !== null) {
            $configName = preg_replace('/\.json$/i', '', $this->configName);
            if (!is_dir($this->historyDir)) {
                mkdir($this->historyDir, 0755, true);
            }
            return $this->historyDir . '/' . $configName . '.json';
        }
        return dirname(__DIR__, 2) . '/config/state/playerHistory.json';
    }

    public function loadPlayerHistory(): array
    {
        $file = $this->getHistoryFile();
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?? [];
    }

    public function savePlayerHistory(array $history): void
    {
        $file = $this->getHistoryFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
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
