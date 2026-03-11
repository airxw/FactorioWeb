<?php

$basePath = __DIR__ . '/app/public';
$webRootPath = __DIR__;

$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

$publicFile = $basePath . $requestPath;
$webRootFile = $webRootPath . $requestPath;

if ($requestPath !== '/' && file_exists($webRootFile) && is_file($webRootFile)) {
    $ext = strtolower(pathinfo($webRootFile, PATHINFO_EXTENSION));
    
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'html' => 'text/html',
        'htm' => 'text/html',
    ];
    
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
        readfile($webRootFile);
        exit;
    }
}

if ($requestPath !== '/' && file_exists($publicFile) && is_file($publicFile)) {
    $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'html' => 'text/html',
        'htm' => 'text/html',
    ];
    
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
        readfile($publicFile);
        exit;
    }
}

$scriptName = $_SERVER['SCRIPT_NAME'];
$basePath = dirname($scriptName);

if ($requestPath === '/' || $requestPath === '/index.php') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header('Location: ' . $protocol . '://' . $host . '/app/public/pages/login.html');
    exit;
}

$apiFile = __DIR__ . '/app/api.php';
if (strpos($requestPath, '/api') === 0 || strpos($requestPath, '/web/api') === 0 || isset($_GET['action'])) {
    require $apiFile;
    exit;
}

$pageFile = __DIR__ . '/app/public/pages' . $requestPath;
if (file_exists($pageFile) && is_file($pageFile)) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    header('Location: ' . $protocol . '://' . $host . '/app/public/pages' . $requestPath);
    exit;
}

header('HTTP/1.0 404 Not Found');
echo '404 - 页面未找到';
