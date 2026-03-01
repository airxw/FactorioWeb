<?php
/**
 * 缓存测试脚本
 * 模拟多次服务器状态检查，验证缓存是否有效
 */

echo "=== 服务器状态缓存测试 ===\n\n";

// 服务器状态缓存，有效期 5 秒
$serverRunningCache = [
    'status' => false,
    'timestamp' => 0
];

// 模拟 isServerRunning 函数
function isServerRunning() {
    global $serverRunningCache;
    
    // 检查缓存是否有效（5秒内）
    $currentTime = time();
    if ($currentTime - $serverRunningCache['timestamp'] < 5) {
        return $serverRunningCache['status'];
    }
    
    // 执行状态检查（模拟实际的 shell 命令执行，这里用 sleep 模拟耗时）
    echo "   执行实际的服务器状态检查...\n";
    usleep(30000); // 模拟 30ms 的执行时间
    $status = false; // 模拟服务器未运行
    
    // 更新缓存
    $serverRunningCache['status'] = $status;
    $serverRunningCache['timestamp'] = $currentTime;
    
    return $status;
}

// 测试开始时间
$startTime = microtime(true);

// 模拟多次 API 调用
$callCount = 5;
echo "模拟 $callCount 次服务器状态检查...\n\n";

// 第一次调用（应该执行实际检查）
echo "第 1 次调用:\n";
$call1Start = microtime(true);
$status1 = isServerRunning();
$call1End = microtime(true);
echo "   状态: " . ($status1 ? "运行中" : "未运行") . "\n";
echo "   耗时: " . round(($call1End - $call1Start) * 1000, 2) . " ms\n\n";

// 等待 1 秒，确保在缓存有效期内
usleep(1000000);

// 第二次调用（应该使用缓存）
echo "第 2 次调用:\n";
$call2Start = microtime(true);
$status2 = isServerRunning();
$call2End = microtime(true);
echo "   状态: " . ($status2 ? "运行中" : "未运行") . "\n";
echo "   耗时: " . round(($call2End - $call2Start) * 1000, 2) . " ms\n\n";

// 等待 1 秒，确保在缓存有效期内
usleep(1000000);

// 第三次调用（应该使用缓存）
echo "第 3 次调用:\n";
$call3Start = microtime(true);
$status3 = isServerRunning();
$call3End = microtime(true);
echo "   状态: " . ($status3 ? "运行中" : "未运行") . "\n";
echo "   耗时: " . round(($call3End - $call3Start) * 1000, 2) . " ms\n\n";

// 等待 1 秒，确保在缓存有效期内
usleep(1000000);

// 第四次调用（应该使用缓存）
echo "第 4 次调用:\n";
$call4Start = microtime(true);
$status4 = isServerRunning();
$call4End = microtime(true);
echo "   状态: " . ($status4 ? "运行中" : "未运行") . "\n";
echo "   耗时: " . round(($call4End - $call4Start) * 1000, 2) . " ms\n\n";

// 等待 1 秒，确保在缓存有效期内
usleep(1000000);

// 第五次调用（应该使用缓存）
echo "第 5 次调用:\n";
$call5Start = microtime(true);
$status5 = isServerRunning();
$call5End = microtime(true);
echo "   状态: " . ($status5 ? "运行中" : "未运行") . "\n";
echo "   耗时: " . round(($call5End - $call5Start) * 1000, 2) . " ms\n\n";

// 总耗时
$totalEnd = microtime(true);
echo "=== 测试结果 ===\n";
echo "总耗时: " . round(($totalEnd - $startTime) * 1000, 2) . " ms\n";
echo "平均耗时: " . round(($totalEnd - $startTime) * 1000 / $callCount, 2) . " ms\n";
echo "\n=== 缓存测试完成 ===\n";
?>