<?php

namespace App\Services;

class MonitorService
{
    private $serverService;

    public function __construct(ServerService $serverService = null)
    {
        $this->serverService = $serverService ?? new ServerService();
    }

    public function getCpuUsage(): float
    {
        $stat1 = $this->readCpuStat();
        usleep(100000);
        $stat2 = $this->readCpuStat();

        if ($stat1 === null || $stat2 === null) {
            return 0.0;
        }

        $total1 = $stat1['user'] + $stat1['nice'] + $stat1['system'] + $stat1['idle'] + $stat1['iowait'];
        $total2 = $stat2['user'] + $stat2['nice'] + $stat2['system'] + $stat2['idle'] + $stat2['iowait'];

        $idle1 = $stat1['idle'];
        $idle2 = $stat2['idle'];

        $totalDiff = $total2 - $total1;
        $idleDiff = $idle2 - $idle1;

        if ($totalDiff === 0) {
            return 0.0;
        }

        return round(100 * (1 - $idleDiff / $totalDiff), 1);
    }

    private function readCpuStat(): ?array
    {
        if (!file_exists('/proc/stat')) {
            return null;
        }

        $content = file_get_contents('/proc/stat');
        $lines = explode("\n", $content);
        $cpuLine = $lines[0];

        if (!preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $cpuLine, $matches)) {
            return null;
        }

        return [
            'user' => (int)$matches[1],
            'nice' => (int)$matches[2],
            'system' => (int)$matches[3],
            'idle' => (int)$matches[4],
            'iowait' => (int)$matches[5]
        ];
    }

    public function getMemInfo(): array
    {
        $result = [
            'total' => 0,
            'free' => 0,
            'available' => 0,
            'used' => 0,
            'percent' => 0
        ];

        if (!file_exists('/proc/meminfo')) {
            return $result;
        }

        $content = file_get_contents('/proc/meminfo');
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)\s+kB/i', $line, $matches)) {
                $result['total'] = (int)$matches[1] * 1024;
            } elseif (preg_match('/^MemFree:\s+(\d+)\s+kB/i', $line, $matches)) {
                $result['free'] = (int)$matches[1] * 1024;
            } elseif (preg_match('/^MemAvailable:\s+(\d+)\s+kB/i', $line, $matches)) {
                $result['available'] = (int)$matches[1] * 1024;
            }
        }

        $result['used'] = $result['total'] - $result['available'];
        if ($result['total'] > 0) {
            $result['percent'] = round(100 * $result['used'] / $result['total'], 1);
        }

        return $result;
    }

    public function getDiskUsage(string $path = '/'): array
    {
        $result = [
            'total' => 0,
            'free' => 0,
            'used' => 0,
            'percent' => 0
        ];

        $total = disk_total_space($path);
        $free = disk_free_space($path);

        if ($total !== false && $free !== false) {
            $result['total'] = $total;
            $result['free'] = $free;
            $result['used'] = $total - $free;
            if ($total > 0) {
                $result['percent'] = round(100 * $result['used'] / $total, 1);
            }
        }

        return $result;
    }

    public function getUptime(): int
    {
        if (!file_exists('/proc/uptime')) {
            return 0;
        }

        $content = file_get_contents('/proc/uptime');
        $parts = explode(' ', $content);

        return (int)floatval($parts[0]);
    }

    public function getSystemStats(): array
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemInfo(),
            'disk' => $this->getDiskUsage(),
            'uptime' => $this->getUptime(),
            'server_running' => $this->serverService->isRunning(),
            'online_players' => $this->getOnlinePlayerCount()
        ];
    }

    public function getOnlinePlayerCount(): int
    {
        $result = $this->serverService->sendRconCommand('/players online');

        if ($result === null) {
            return 0;
        }

        if (preg_match('/(\d+)\s*players?\s+currently\s+online/i', $result, $matches)) {
            return (int)$matches[1];
        }

        $players = preg_split('/\r\n|\r|\n/', trim($result));
        $count = 0;
        foreach ($players as $player) {
            $player = trim($player);
            if ($player !== '' && strpos($player, 'Players online') === false) {
                $count++;
            }
        }

        return $count;
    }

    public function getOnlinePlayers(): array
    {
        $result = $this->serverService->sendRconCommand('/players online');

        if ($result === null) {
            return [];
        }

        $players = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($result));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && strpos($line, 'Players online') === false) {
                if (preg_match('/^\s*(.+?)\s*\(online\)\s*$/i', $line, $matches)) {
                    $players[] = trim($matches[1]);
                }
            }
        }

        return $players;
    }
}
