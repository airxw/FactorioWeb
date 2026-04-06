<?php
// 这个脚本用于通过 curl 模拟浏览器请求 api.php
// 用法: php testCurlApi.php

$url = 'http://127.0.0.1:34198/app/api.php';  // 使用 34198 端口（nginx 监听的端口）

// 先登录获取 session
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'action' => 'login',
        'username' => 'airxw',
        'password' => 'xwxiewei'
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => __DIR__ . '/cookies.txt',
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 10,
]);
$loginResult = curl_exec($ch);
curl_close($ch);

echo "=== 登录结果 ===\n";
echo $loginResult . "\n\n";

// 用 session 发送批量创建订单请求
$items = json_encode([
    ['item_code' => 'express-transport-belt', 'quantity' => 1, 'quality' => 0],
    ['item_code' => 'fast-transport-belt', 'quantity' => 1, 'quality' => 0]
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'action' => 'batch_create_orders',
        'items' => $items
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => __DIR__ . '/cookies.txt',
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 10,
]);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== 订单创建结果 (HTTP {$httpCode}) ===\n";
echo $result . "\n\n";

// 清理
@unlink(__DIR__ . '/cookies.txt');
