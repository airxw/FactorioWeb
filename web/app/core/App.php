<?php

namespace App\Core;

class App
{
    private static $instance = null;
    private $basePath;
    private $config = [];

    private function __construct()
    {
        $this->basePath = dirname(__DIR__, 2);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getConfig(string $key = null, $default = null)
    {
        if (empty($this->config)) {
            $this->loadConfig();
        }

        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    private function loadConfig(): void
    {
        $configFile = $this->basePath . '/config.php';
        if (file_exists($configFile)) {
            $this->config['app'] = require $configFile;
        }

        $rconFile = $this->basePath . '/config/system/rcon.php';
        if (file_exists($rconFile)) {
            $this->config['rcon'] = require $rconFile;
        }
    }

    public function storagePath(string $path = ''): string
    {
        return $this->basePath . '/storage' . ($path ? '/' . $path : '');
    }

    public function dataPath(string $path = ''): string
    {
        return $this->storagePath('data' . ($path ? '/' . $path : ''));
    }

    public function logPath(string $path = ''): string
    {
        return $this->storagePath('logs' . ($path ? '/' . $path : ''));
    }

    public function cachePath(string $path = ''): string
    {
        return $this->storagePath('cache' . ($path ? '/' . $path : ''));
    }
}
