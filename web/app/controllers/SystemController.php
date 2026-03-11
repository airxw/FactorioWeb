<?php

namespace App\Controllers;

use App\Services\MonitorService;
use App\Core\Response;

class SystemController
{
    private $monitorService;

    public function __construct()
    {
        $this->monitorService = new MonitorService();
    }

    public function stats(): void
    {
        $stats = $this->monitorService->getSystemStats();
        Response::success($stats);
    }

    public function cpu(): void
    {
        $cpu = $this->monitorService->getCpuUsage();
        Response::success(['cpu_usage' => $cpu]);
    }

    public function memory(): void
    {
        $memory = $this->monitorService->getMemInfo();
        Response::success($memory);
    }

    public function disk(): void
    {
        $disk = $this->monitorService->getDiskUsage();
        Response::success($disk);
    }

    public function uptime(): void
    {
        $uptime = $this->monitorService->getUptime();
        Response::success(['uptime' => $uptime]);
    }

    public function onlinePlayers(): void
    {
        $players = $this->monitorService->getOnlinePlayers();
        $count = count($players);

        Response::success([
            'count' => $count,
            'players' => $players
        ]);
    }

    public function phpinfo(): void
    {
        phpinfo();
        exit;
    }
}
