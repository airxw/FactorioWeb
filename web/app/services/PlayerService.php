<?php

namespace App\Services;

use App\Core\Database;

class PlayerService
{
    private Database $db;
    private ?string $configName = null;

    public function __construct(Database $db = null, string $configName = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->configName = $configName;
    }

    public function setConfigName(string $configName): void
    {
        $this->configName = $configName;
    }

    private function getConfigName(): string
    {
        return $this->configName ?? 'default';
    }

    public function loadPlayerHistory(): array
    {
        return $this->db->query(
            'SELECT player_name, first_join_time, last_join_time, join_count, last_leave_time, ip FROM player_histories WHERE config_name = :config_name',
            [':config_name' => $this->getConfigName()]
        );
    }

    public function savePlayerHistory(array $history): void
    {
        foreach ($history as $player => $data) {
            $this->upsertPlayer($player, $data);
        }
    }

    private function upsertPlayer(string $player, array $data): void
    {
        $exists = $this->db->query(
            'SELECT id FROM player_histories WHERE config_name = :config_name AND player_name = :player',
            [':config_name' => $this->getConfigName(), ':player' => $player]
        );

        if (empty($exists)) {
            $this->db->execute(
                'INSERT INTO player_histories (config_name, player_name, first_join_time, last_join_time, join_count, ip, created_at, updated_at) VALUES (:config_name, :player, :firstJoin, :lastJoin, :joinCount, :ip, :created, :updated)',
                [
                    ':config_name' => $this->getConfigName(),
                    ':player' => $player,
                    ':firstJoin' => $data['firstJoin'] ?? time(),
                    ':lastJoin' => $data['lastJoin'] ?? time(),
                    ':joinCount' => $data['joinCount'] ?? 1,
                    ':ip' => $data['ip'] ?? null,
                    ':created' => time(),
                    ':updated' => time()
                ]
            );
        } else {
            $this->db->execute(
                'UPDATE player_histories SET last_join_time = :lastJoin, join_count = :joinCount, ip = :ip, updated_at = :updated WHERE config_name = :config_name AND player_name = :player',
                [
                    ':lastJoin' => $data['lastJoin'] ?? time(),
                    ':joinCount' => $data['joinCount'] ?? 1,
                    ':ip' => $data['ip'] ?? null,
                    ':updated' => time(),
                    ':config_name' => $this->getConfigName(),
                    ':player' => $player
                ]
            );
        }
    }

    public function isPlayerFirstJoin(string $player): bool
    {
        $result = $this->db->query(
            'SELECT id FROM player_histories WHERE config_name = :config_name AND player_name = :player',
            [':config_name' => $this->getConfigName(), ':player' => $player]
        );
        return empty($result);
    }

    public function recordPlayerJoin(string $player, string $ip = null): void
    {
        $exists = $this->db->query(
            'SELECT id, join_count FROM player_histories WHERE config_name = :config_name AND player_name = :player',
            [':config_name' => $this->getConfigName(), ':player' => $player]
        );

        if (empty($exists)) {
            $this->db->execute(
                'INSERT INTO player_histories (config_name, player_name, first_join_time, last_join_time, join_count, ip, created_at, updated_at) VALUES (:config_name, :player, :firstJoin, :lastJoin, 1, :ip, :created, :updated)',
                [
                    ':config_name' => $this->getConfigName(),
                    ':player' => $player,
                    ':firstJoin' => time(),
                    ':lastJoin' => time(),
                    ':ip' => $ip,
                    ':created' => time(),
                    ':updated' => time()
                ]
            );
        } else {
            $newCount = ($exists[0]['join_count'] ?? 0) + 1;
            $this->db->execute(
                'UPDATE player_histories SET last_join_time = :lastJoin, join_count = :count, ip = COALESCE(:ip, ip), updated_at = :updated WHERE config_name = :config_name AND player_name = :player',
                [
                    ':lastJoin' => time(),
                    ':count' => $newCount,
                    ':ip' => $ip,
                    ':updated' => time(),
                    ':config_name' => $this->getConfigName(),
                    ':player' => $player
                ]
            );
        }
    }

    public function recordPlayerLeave(string $player): void
    {
        $this->db->execute(
            'UPDATE player_histories SET last_leave_time = :leaveTime, updated_at = :updated WHERE config_name = :config_name AND player_name = :player',
            [
                ':leaveTime' => time(),
                ':updated' => time(),
                ':config_name' => $this->getConfigName(),
                ':player' => $player
            ]
        );
    }

    public function getPlayerInfo(string $player): ?array
    {
        $result = $this->db->query(
            'SELECT * FROM player_histories WHERE config_name = :config_name AND player_name = :player',
            [':config_name' => $this->getConfigName(), ':player' => $player]
        );
        return $result[0] ?? null;
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
        return $this->db->query(
            'SELECT * FROM player_histories WHERE config_name = :config_name ORDER BY last_join_time DESC LIMIT :limit',
            [':config_name' => $this->getConfigName(), ':limit' => $limit]
        );
    }
}
