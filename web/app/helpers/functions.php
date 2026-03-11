<?php

namespace App\Helpers;

function sanitizeInput(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function escapeForPhp(string $input): string
{
    return addslashes($input);
}

function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function formatUptime(int $seconds): string
{
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . '天';
    }
    if ($hours > 0) {
        $parts[] = $hours . '小时';
    }
    if ($minutes > 0 || empty($parts)) {
        $parts[] = $minutes . '分钟';
    }

    return implode(' ', $parts);
}

function safeReadJson(string $filePath): array
{
    if (!file_exists($filePath)) {
        return [];
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        return [];
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }

    return $data ?? [];
}

function safeWriteJson(string $filePath, array $data): bool
{
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $result = file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $result !== false;
}

function generateUniqueId(string $prefix = ''): string
{
    return $prefix . uniqid() . bin2hex(random_bytes(4));
}

function validateServerId(string $serverId): bool
{
    return preg_match('/^[a-zA-Z0-9_-]+$/', $serverId) === 1;
}

function validateUsername(string $username): bool
{
    return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username) === 1;
}

function validatePassword(string $password): bool
{
    return strlen($password) >= 6;
}
