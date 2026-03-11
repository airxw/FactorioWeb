<?php

namespace App\Services;

class MonitorService
{
    private ?ServerService $serverService;

    public function __construct(ServerService $serverService = null)
    {
        $this->serverService = $serverService;
    }

    public function getCpuUsage(): float
    {
        $stat1 = $this->readCpuStat();
        usleep(200000);
        $stat2 = $this->readCpuStat();
        
        $total1 = array_sum($stat1);
        $total2 = array_sum($stat2);
        $idle1 = $stat1[3] ?? 0;
        $idle2 = $stat2[3] ?? 0;
        
        $totalDiff = $total2 - $total1;
        $idleDiff = $idle2 - $idle1;
        
        if ($totalDiff == 0) {
            return 0.0;
        }
        
        return round(($totalDiff - $idleDiff) / $totalDiff * 100, 1);
    }

    private function readCpuStat(): array
    {
        if (!is_readable('/proc/stat')) {
            return [0, 0, 0, 0];
        }
        
        $content = file_get_contents('/proc/stat');
        $line = explode("\n", $content)[0];
        $parts = explode(" ", preg_replace("!cpu +!", "", $line));
        
        return array_map('intval', $parts);
    }

    public function getMemInfo(): array
    {
        $data = [
            'total' => 0,
            'free' => 0,
            'used' => 0,
            'percent' => 0
        ];
        
        if (!is_readable('/proc/meminfo')) {
            return $data;
        }
        
        $content = file_get_contents('/proc/meminfo');
        
        preg_match('/MemTotal:\s+(\d+)/', $content, $total);
        preg_match('/MemFree:\s+(\d+)/', $content, $free);
        preg_match('/Buffers:\s+(\d+)/', $content, $buffers);
        preg_match('/Cached:\s+(\d+)/', $content, $cached);
        preg_match('/SReclaimable:\s+(\d+)/', $content, $sreclaim);
        
        $data['total'] = ($total[1] ?? 0) * 1024;
        $data['free'] = ($free[1] ?? 0) * 1024;
        
        $cache = (($buffers[1] ?? 0) + ($cached[1] ?? 0) + ($sreclaim[1] ?? 0)) * 1024;
        $data['used'] = $data['total'] - ($data['free'] + $cache);
        $data['percent'] = $data['total'] > 0 ? round($data['used'] / $data['total'] * 100, 1) : 0;
        
        return $data;
    }

    public function getDiskUsage(string $path = '/'): array
    {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;
        
        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0
        ];
    }

    public function getUptime(): int
    {
        if (!is_readable('/proc/uptime')) {
            return 0;
        }
        
        return (int)explode(" ", file_get_contents('/proc/uptime'))[0];
    }

    public function getLoadAverage(): array
    {
        return sys_getloadavg();
    }

    public function getSystemStats(): array
    {
        $mem = $this->getMemInfo();
        $disk = $this->getDiskUsage('/');
        
        return [
            'cpu' => $this->getCpuUsage(),
            'load' => $this->getLoadAverage(),
            'uptime' => $this->getUptime(),
            'memory' => [
                'used' => round($mem['used'] / 1073741824, 2),
                'total' => round($mem['total'] / 1073741824, 2),
                'percent' => $mem['percent']
            ],
            'disk' => [
                'used' => round($disk['used'] / 1073741824, 2),
                'total' => round($disk['total'] / 1073741824, 2),
                'percent' => $disk['percent']
            ]
        ];
    }

    public function getOnlinePlayerCount(): int
    {
        if (!$this->serverService) {
            return 0;
        }
        
        return $this->serverService->getOnlinePlayerCount();
    }

    public function getOnlinePlayers(): array
    {
        if (!$this->serverService) {
            return [];
        }
        
        return $this->serverService->getOnlinePlayers();
    }
}
