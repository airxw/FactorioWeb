<?php
header('Content-Type: application/json');
error_reporting(0); // 禁止错误输出破坏 JSON

// === 辅助函数 ===

// 获取 CPU 核心数和型号
function getCpuInfo() {
    $info = ['name' => 'Unknown', 'cores' => 1];
    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor/m', $cpuinfo, $matches);
        $info['cores'] = count($matches[0]);
        if (preg_match('/model name\s+:\s+(.+)$/m', $cpuinfo, $m)) {
            $info['name'] = trim($m[1]);
        }
    }
    return $info;
}

// 获取 CPU 使用率 (瞬时采样)
function getCpuUsage() {
    $stat1 = getCpuStat(); usleep(200000); $stat2 = getCpuStat(); // 200ms 采样
    $total1 = array_sum($stat1); $total2 = array_sum($stat2);
    $idle1 = $stat1[3]; $idle2 = $stat2[3];
    $total = $total2 - $total1;
    $idle = $idle2 - $idle1;
    return ($total == 0) ? 0 : round(($total - $idle) / $total * 100, 1);
}
function getCpuStat() {
    return is_readable('/proc/stat') ? explode(" ", preg_replace("!cpu +!", "", explode("\n", file_get_contents('/proc/stat'))[0])) : [0,0,0,0];
}

// 获取详细内存 (对标雅黑探针逻辑)
function getMemInfo() {
    $m = ['total'=>0, 'free'=>0, 'buffers'=>0, 'cached'=>0, 'real_used'=>0, 'real_free'=>0];
    if (is_readable('/proc/meminfo')) {
        $str = file_get_contents('/proc/meminfo');
        preg_match_all("/^(\w+):\s+(\d+)\s*kB/m", $str, $matches);
        $items = array_combine($matches[1], $matches[2]);
        
        $m['total'] = $items['MemTotal'] * 1024;
        $m['free']  = $items['MemFree'] * 1024;
        $m['buffers'] = $items['Buffers'] * 1024;
        $m['cached'] = $items['Cached'] * 1024 + ($items['SReclaimable'] ?? 0) * 1024;
        
        // 真实已用 = Total - Free - Buffers - Cached
        $m['real_used'] = $m['total'] - $m['free'] - $m['buffers'] - $m['cached'];
        // 包含缓存的已用（Linux 视角）
        $m['sys_used'] = $m['total'] - $m['free'];
    }
    return $m;
}

// 获取网络流量
function getNet() {
    $rx = 0; $tx = 0;
    if (is_readable('/proc/net/dev')) {
        $lines = file('/proc/net/dev');
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false && strpos($line, 'lo') === false) {
                $parts = preg_split('/\s+/', trim(explode(':', $line)[1]));
                $rx += $parts[0]; $tx += $parts[8];
            }
        }
    }
    return ['rx' => $rx, 'tx' => $tx];
}

// 获取负载和运行时间
function getLoadUptime() {
    $uptime = is_readable('/proc/uptime') ? (int)explode(" ", file_get_contents('/proc/uptime'))[0] : 0;
    $load = sys_getloadavg();
    
    // 获取 CPU 核心数
    $cpuInfo = getCpuInfo();
    $cpuCores = $cpuInfo['cores'];
    
    // 将负载值转换为百分比并四舍五入到两位小数
    if (!empty($load) && $cpuCores > 0) {
        foreach ($load as &$value) {
            // 负载值除以核心数再乘以 100 得到百分比
            $percent = ($value / $cpuCores) * 100;
            $value = round($percent, 2) . '%';
        }
    }
    
    return ['uptime' => $uptime, 'load' => $load];
}

// 字节转换为GB
function bytesToGB($bytes) {
    return round($bytes / (1024 * 1024 * 1024), 1);
}

// === 执行数据收集 ===

$cpuInfo = getCpuInfo();
$cpuUsage = getCpuUsage();
$mem = getMemInfo();
$net = getNet();
$sys = getLoadUptime();
$diskTotal = disk_total_space(__DIR__);
$diskFree = disk_free_space(__DIR__);
$diskUsed = $diskTotal - $diskFree;
$isAppRunning = !empty(shell_exec("screen -ls | grep factorio_server"));

// 输出 JSON
echo json_encode([
    'os' => php_uname('s') . ' ' . php_uname('r'),
    'cpu_name' => $cpuInfo['name'],
    'cpu_cores' => $cpuInfo['cores'],
    'cpu_usage' => $cpuUsage,
    'uptime' => $sys['uptime'],
    'load' => $sys['load'],
    'mem' => [
        'total' => $mem['total'],
        'real_used' => $mem['real_used'],
        'buffers' => $mem['buffers'],
        'cached' => $mem['cached'],
        'percent' => ($mem['total']>0) ? round($mem['real_used']/$mem['total']*100, 1) : 0
    ],
    'disk' => [
        'total' => bytesToGB($diskTotal),
        'used' => bytesToGB($diskUsed),
        'percent' => ($diskTotal>0) ? round(($diskTotal-$diskFree)/$diskTotal*100, 1) : 0
    ],
    'net' => $net,
    'app_running' => $isAppRunning
]);
?>