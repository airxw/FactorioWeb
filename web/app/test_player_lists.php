<?php
$configDir = dirname(__DIR__, 2) . '/config';
$playerDir = "$configDir/player";

$safeReadList = function($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    
    $content = file_get_contents($filePath);
    if ($content === false) {
        return [];
    }
    
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return [];
    }
    
    return $data;
};

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'admins'     => $safeReadList("$playerDir/server-adminlist.json"),
    'bans'       => $safeReadList("$playerDir/server-banlist.json"),
    'whitelist'  => $safeReadList("$playerDir/server-whitelist.json")
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
