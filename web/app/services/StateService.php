<?php

namespace App\Services;

class StateService
{
    private string $configDir;
    private array $cache = [];

    public function __construct(string $configDir = null)
    {
        $this->configDir = $configDir ?? dirname(__DIR__, 2) . '/config';
    }

    public function saveState(string $name, array $state): bool
    {
        $file = $this->getStateFile($name);
        $result = file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->cache[$name] = $state;
        return $result !== false;
    }

    public function loadState(string $name): array
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $file = $this->getStateFile($name);
        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        $this->cache[$name] = json_decode($content, true) ?? [];
        return $this->cache[$name];
    }

    public function getStateValue(string $name, string $key, $default = null)
    {
        $state = $this->loadState($name);
        return $state[$key] ?? $default;
    }

    public function setStateValue(string $name, string $key, $value): bool
    {
        $state = $this->loadState($name);
        $state[$key] = $value;
        return $this->saveState($name, $state);
    }

    public function deleteState(string $name): bool
    {
        $file = $this->getStateFile($name);
        if (file_exists($file)) {
            return unlink($file);
        }
        unset($this->cache[$name]);
        return true;
    }

    public function clearCache(string $name = null): void
    {
        if ($name) {
            unset($this->cache[$name]);
        } else {
            $this->cache = [];
        }
    }

    private function getStateFile(string $name): string
    {
        return $this->configDir . '/' . $name . '.json';
    }
}
