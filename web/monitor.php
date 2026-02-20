<?php
header('Content-Type: application/json');
error_reporting(0); // 屏蔽错误输出，防止破坏 JSON

// 获取 CPU 使用率 (采样 0.2s)
function getCpuUsage() {
    $stat1 = getCpuStat(); 
    usleep(200000); // 等待 200ms
    $stat2 = getCpuStat();
    
    $total1 = array_sum($stat1); 
    $total2 = array_sum($stat2);
    $idle1 = $stat1[3]; 
    $idle2 = $stat2[3];
    
    $totalDiff = $total2 - $total1;
    $idleDiff = $idle2 - $idle1;
    
    if ($totalDiff == 0) return 0;
    return round(($totalDiff - $idleDiff) / $totalDiff * 100, 1);
}

function getCpuStat() {
    // 读取 /proc/stat
    if (!is_readable('/proc/stat')) return [0,0,0,0];
    $content = file_get_contents('/proc/stat');
    $line = explode("\n", $content)[0]; // 第一行是总 CPU
    $parts = explode(" ", preg_replace("!cpu +!", "", $line));
    return $parts;
}

// 获取内存信息
function getMemInfo() {
    $data = ['total' => 0, 'free' => 0, 'buffers' => 0, 'cached' => 0];
    if (is_readable('/proc/meminfo')) {
        $content = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $content, $total);
        preg_match('/MemFree:\s+(\d+)/', $content, $free);
        preg_match('/Buffers:\s+(\d+)/', $content, $buffers);
        preg_match('/Cached:\s+(\d+)/', $content, $cached);
        preg_match('/SReclaimable:\s+(\d+)/', $content, $sreclaim); // 部分缓存

        $data['total'] = ($total[1] ?? 0) * 1024;
        $data['free'] = ($free[1] ?? 0) * 1024;
        // 实际上可用内存 = free + buffers + cached
        $cache = (($buffers[1]??0) + ($cached[1]??0) + ($sreclaim[1]??0)) * 1024;
        $data['used'] = $data['total'] - ($data['free'] + $cache);
    }
    return $data;
}

// 获取运行时间
function getUptime() {
    if (!is_readable('/proc/uptime')) return 0;
    return (int)explode(" ", file_get_contents('/proc/uptime'))[0];
}

// 检查服务器进程
$isRunning = !empty(shell_exec("screen -ls | grep factorio_server"));

// 收集数据
$mem = getMemInfo();
$dt = disk_total_space(__DIR__);
$df = disk_free_space(__DIR__);
$cpu = getCpuUsage();
$load = sys_getloadavg();

// 输出前端 index.php 需要的 JSON 格式
echo json_encode([
    'system' => [
        'cpu' => $cpu,
        'load' => $load,
        'uptime' => getUptime(),
        'memory' => [
            'used' => round($mem['used'] / 1073741824, 2), // GB
            'percent' => ($mem['total'] > 0) ? round($mem['used'] / $mem['total'] * 100, 1) : 0
        ],
        'disk' => [
            'used' => round(($dt - $df) / 1073741824, 2), // GB
            'percent' => ($dt > 0) ? round(($dt - $df) / $dt * 100, 1) : 0
        ]
    ],
    'app' => [
        'is_running' => $isRunning
    ]
]);
?>