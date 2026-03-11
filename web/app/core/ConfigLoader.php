<?php
/**
 * 配置加载类
 * 
 * 统一管理项目中所有配置文件
 * 所有配置文件都存放在 web/config/ 目录下
 * 
 * @package App\Core
 * @version 1.0
 */

namespace App\Core;

class ConfigLoader
{
    private static $configDir = null;
    private static $gameConfigDir = null;
    private static $systemConfigDir = null;
    private static $stateConfigDir = null;
    private static $configs = [];

    /**
     * 获取配置根目录
     * 
     * @return string 配置根目录路径
     */
    public static function getConfigDir(): string
    {
        if (self::$configDir === null) {
            self::$configDir = dirname(__DIR__, 2) . '/config';
            if (!is_dir(self::$configDir)) {
                mkdir(self::$configDir, 0777, true);
            }
        }
        return self::$configDir;
    }

    /**
     * 获取游戏配置目录
     * 
     * @return string 游戏配置目录路径
     */
    public static function getGameConfigDir(): string
    {
        if (self::$gameConfigDir === null) {
            self::$gameConfigDir = self::getConfigDir() . '/game';
            if (!is_dir(self::$gameConfigDir)) {
                mkdir(self::$gameConfigDir, 0777, true);
            }
        }
        return self::$gameConfigDir;
    }

    /**
     * 获取系统配置目录
     * 
     * @return string 系统配置目录路径
     */
    public static function getSystemConfigDir(): string
    {
        if (self::$systemConfigDir === null) {
            self::$systemConfigDir = self::getConfigDir() . '/system';
            if (!is_dir(self::$systemConfigDir)) {
                mkdir(self::$systemConfigDir, 0777, true);
            }
        }
        return self::$systemConfigDir;
    }

    /**
     * 获取状态配置目录
     * 
     * @return string 状态配置目录路径
     */
    public static function getStateConfigDir(): string
    {
        if (self::$stateConfigDir === null) {
            self::$stateConfigDir = self::getConfigDir() . '/state';
            if (!is_dir(self::$stateConfigDir)) {
                mkdir(self::$stateConfigDir, 0777, true);
            }
        }
        return self::$stateConfigDir;
    }

    /**
     * 获取 RCON 配置文件路径
     * 
     * @return string 配置文件路径
     */
    public static function rconConfig(): string
    {
        return self::getSystemConfigDir() . '/rcon.php';
    }

    /**
     * 获取服务器设置文件路径（默认模板）
     * 
     * @return string 配置文件路径
     */
    public static function serverSettings(): string
    {
        return self::getGameConfigDir() . '/serverSettings.json';
    }

    /**
     * 获取物品库文件路径
     * 
     * @return string 物品库文件路径
     */
    public static function itemsFile(): string
    {
        return self::getGameConfigDir() . '/items.json';
    }

    /**
     * 获取主配置文件路径
     * 
     * @return string 配置文件路径
     */
    public static function mainConfig(): string
    {
        return self::getSystemConfigDir() . '/main.php';
    }

    /**
     * 获取聊天设置文件路径
     * 
     * @return string 配置文件路径
     */
    public static function chatSettings(): string
    {
        return self::getStateConfigDir() . '/chatSettings.json';
    }

    /**
     * 获取加密密钥文件路径
     * 
     * @return string 密钥文件路径
     */
    public static function encryptionKey(): string
    {
        return self::getSystemConfigDir() . '/.encryptionKey';
    }

    /**
     * 加载 PHP 配置文件
     * 
     * @param string $name 配置名称
     * @return array 配置数据
     */
    public static function load(string $name): array
    {
        if (isset(self::$configs[$name])) {
            return self::$configs[$name];
        }

        $file = self::getSystemConfigDir() . '/' . $name . '.php';
        if (file_exists($file)) {
            self::$configs[$name] = require $file;
        } else {
            self::$configs[$name] = [];
        }

        return self::$configs[$name];
    }

    /**
     * 加载 JSON 配置文件
     * 
     * @param string $name 配置名称 (不含扩展名)
     * @return array 配置数据
     */
    public static function loadJson(string $name): array
    {
        $cacheKey = 'json_' . $name;
        if (isset(self::$configs[$cacheKey])) {
            return self::$configs[$cacheKey];
        }

        $file = self::getConfigDir() . '/' . $name . '.json';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            self::$configs[$cacheKey] = json_decode($content, true) ?: [];
        } else {
            self::$configs[$cacheKey] = [];
        }

        return self::$configs[$cacheKey];
    }

    /**
     * 保存 JSON 配置文件
     * 
     * @param string $name 配置名称
     * @param array $data 配置数据
     * @return bool 是否保存成功
     */
    public static function saveJson(string $name, array $data): bool
    {
        $file = self::getConfigDir() . '/' . $name . '.json';
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $result = file_put_contents($file, $content, LOCK_EX);
        
        if ($result !== false) {
            $cacheKey = 'json_' . $name;
            self::$configs[$cacheKey] = $data;
            return true;
        }
        
        return false;
    }

    /**
     * 获取配置项
     * 
     * @param string $key 配置键，支持点语法 (如 'rcon.default.port')
     * @param mixed $default 默认值
     * @return mixed 配置值
     */
    public static function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $configName = array_shift($keys);
        
        $config = self::load($configName);
        
        foreach ($keys as $k) {
            if (!isset($config[$k])) {
                return $default;
            }
            $config = $config[$k];
        }
        
        return $config;
    }

    /**
     * 清除配置缓存
     * 
     * @param string|null $name 配置名称，为 null 时清除所有
     */
    public static function clearCache(?string $name = null): void
    {
        if ($name === null) {
            self::$configs = [];
        } else {
            unset(self::$configs[$name]);
            unset(self::$configs['json_' . $name]);
        }
    }
}
