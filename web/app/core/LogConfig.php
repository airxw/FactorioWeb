<?php
/**
 * 日志配置类
 * 
 * 统一管理项目中所有日志文件的路径
 * 所有日志文件都存放在 web/logs/ 目录下
 * 
 * @package App\Core
 * @version 1.0
 */

namespace App\Core;

class LogConfig
{
    private static $logsDir = null;

    /**
     * 获取日志根目录
     * 
     * @return string 日志根目录路径
     */
    public static function getLogsDir(): string
    {
        if (self::$logsDir === null) {
            self::$logsDir = dirname(__DIR__, 2) . '/logs';
            if (!is_dir(self::$logsDir)) {
                mkdir(self::$logsDir, 0777, true);
            }
        }
        return self::$logsDir;
    }

    /**
     * 获取 RCON 连接池日志文件路径
     * 
     * @return string 日志文件路径
     */
    public static function rconPoolLog(): string
    {
        return self::getLogsDir() . '/rconPool.log';
    }

    /**
     * 获取自动响应日志文件路径
     * 
     * @return string 日志文件路径
     */
    public static function autoResponderLog(): string
    {
        return self::getLogsDir() . '/autoResponder.log';
    }

    /**
     * 获取自动响应守护进程日志文件路径
     * 
     * @return string 日志文件路径
     */
    public static function autoResponderDaemonLog(): string
    {
        return self::getLogsDir() . '/autoResponderDaemon.log';
    }

    /**
     * 获取 WebSocket 服务日志文件路径
     * 
     * @return string 日志文件路径
     */
    public static function websocketLog(): string
    {
        return self::getLogsDir() . '/websocket.log';
    }

    /**
     * 获取系统监控日志文件路径
     * 
     * @return string 日志文件路径
     */
    public static function monitorLog(): string
    {
        return self::getLogsDir() . '/monitor.log';
    }

    /**
     * 获取 API 错误日志文件路径
     * 
     * @return string 日志文件路径
     */
    public static function apiErrorLog(): string
    {
        return self::getLogsDir() . '/apiError.log';
    }

    /**
     * 获取 Factorio 游戏日志文件路径
     * 注意：这是 Factorio 服务端产生的日志，不在 web/logs 下
     * 
     * @return string 日志文件路径
     */
    public static function factorioLog(): string
    {
        return dirname(__DIR__, 3) . '/factorio-current.log';
    }

    /**
     * 写入日志
     * 
     * @param string $logFile 日志文件路径
     * @param string $message 日志消息
     * @param string $level 日志级别 (INFO, WARNING, ERROR, DEBUG)
     * @return bool 是否写入成功
     */
    public static function write(string $logFile, string $message, string $level = 'INFO'): bool
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] [$level] $message\n";
        return file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * 日志轮转
     * 当日志文件超过指定大小时进行轮转
     * 
     * @param string $logFile 日志文件路径
     * @param int $maxSize 最大文件大小（字节），默认 10MB
     * @param int $maxFiles 保留的最大日志文件数
     */
    public static function rotate(string $logFile, int $maxSize = 10485760, int $maxFiles = 5): void
    {
        if (!file_exists($logFile)) {
            return;
        }

        if (filesize($logFile) < $maxSize) {
            return;
        }

        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $oldFile = $logFile . '.' . $i;
            $newFile = $logFile . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                if ($i === $maxFiles - 1) {
                    unlink($oldFile);
                } else {
                    rename($oldFile, $newFile);
                }
            }
        }

        if (file_exists($logFile)) {
            rename($logFile, $logFile . '.1');
        }
    }
}
