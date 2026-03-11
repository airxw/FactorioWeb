<?php

namespace Modules\AutoResponder;

use App\Services\RconService;

abstract class AutoResponder
{
    protected $webDir;
    protected $settingsFile;
    protected $stateFile;
    protected $logFile;
    protected $settings;
    protected $state;
    protected $rconConfig;

    public function __construct()
    {
        $this->webDir = dirname(__DIR__, 2);
        $this->settingsFile = dirname($this->webDir) . '/config/state/chatSettings.json';
        $this->stateFile = dirname($this->webDir) . '/config/state/autoResponderState.json';
        $this->logFile = dirname($this->webDir) . '/logs/autoResponder.log';
        $this->rconConfigFile = dirname($this->webDir) . '/config/system/rcon.php';

        $this->loadSettings();
        $this->loadState();
        $this->loadRconConfig();
    }

    protected function loadSettings(): void
    {
        if (file_exists($this->settingsFile)) {
            $content = file_get_contents($this->settingsFile);
            $this->settings = json_decode($content, true) ?? [];
        } else {
            $this->settings = $this->getDefaultSettings();
        }
    }

    protected function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $content = file_get_contents($this->stateFile);
            $this->state = json_decode($content, true) ?? [];
        } else {
            $this->state = [];
        }
    }

    protected function loadRconConfig(): void
    {
        $configFile = $this->webDir . '/config/system/rcon.php';
        if (file_exists($configFile)) {
            $this->rconConfig = require $configFile;
        } else {
            $this->rconConfig = [];
        }
    }

    protected function saveState(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->stateFile, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function getSettings(): array
    {
        return $this->settings;
    }

    protected function getState(): array
    {
        return $this->state;
    }

    protected function setState(array $state): void
    {
        $this->state = $state;
        $this->saveState();
    }

    protected function getServerConfig(string $serverId = 'default'): array
    {
        if (isset($this->rconConfig[$serverId])) {
            return $this->rconConfig[$serverId];
        }

        if (isset($this->rconConfig['default'])) {
            return $this->rconConfig['default'];
        }

        return [
            'rcon_enabled' => true,
            'rcon_port' => 27015,
            'rcon_password' => '',
            'rcon_host' => '127.0.0.1',
            'screen_name' => 'factorio_server'
        ];
    }

    protected function getRconConnection(string $serverId = 'default'): ?RconService
    {
        $config = $this->getServerConfig($serverId);

        if (!($config['rcon_enabled'] ?? true)) {
            return null;
        }

        try {
            $rcon = new RconService(
                $config['rcon_host'] ?? '127.0.0.1',
                $config['rcon_port'] ?? 27015,
                $config['rcon_password'] ?? ''
            );
            $rcon->connect();
            return $rcon;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function sendChatMessage(string $message, string $serverId = 'default'): bool
    {
        $rcon = $this->getRconConnection($serverId);

        if ($rcon === null) {
            return false;
        }

        try {
            $rcon->sendCommand('/silent-command game.print("' . addslashes($message) . '")');
            $rcon->disconnect();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function sendPrivateMessage(string $player, string $message, string $serverId = 'default'): bool
    {
        $rcon = $this->getRconConnection($serverId);

        if ($rcon === null) {
            return false;
        }

        try {
            $rcon->sendCommand('/silent-command game.players["' . addslashes($player) . '"].print("' . addslashes($message) . '")');
            $rcon->disconnect();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function executeCommand(string $command, string $serverId = 'default'): ?string
    {
        $rcon = $this->getRconConnection($serverId);

        if ($rcon === null) {
            return null;
        }

        try {
            $result = $rcon->sendCommand($command);
            $rcon->disconnect();
            return $result;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function isServerRunning(string $serverId = 'default'): bool
    {
        $config = $this->getServerConfig($serverId);

        $rconTest = RconService::testConnection(
            $config['rcon_host'] ?? '127.0.0.1',
            $config['rcon_port'] ?? 27015,
            $config['rcon_password'] ?? ''
        );

        if ($rconTest) {
            return true;
        }

        $screenName = $config['screen_name'] ?? 'factorio_server';
        $screenCheck = shell_exec("screen -ls | grep " . escapeshellarg($screenName));
        $psCheck = shell_exec("pgrep -f 'factorio.*headless'");

        return !empty($screenCheck) || !empty($psCheck);
    }

    protected function log(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message\n";

        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    protected function logInfo(string $message): void
    {
        $this->log('INFO', $message);
    }

    protected function logWarning(string $message): void
    {
        $this->log('WARNING', $message);
    }

    protected function logError(string $message): void
    {
        $this->log('ERROR', $message);
    }

    protected function logDebug(string $message): void
    {
        if ($this->settings['debug'] ?? false) {
            $this->log('DEBUG', $message);
        }
    }

    protected function getDefaultSettings(): array
    {
        return [
            'scheduledTasks' => [],
            'triggerResponses' => [],
            'serverResponses' => [],
            'playerEvents' => [
                'welcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'goodbye' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'firstJoinGift' => ['enabled' => false, 'items' => ''],
                'rejoinGift' => ['enabled' => false, 'items' => '']
            ],
            'debug' => false
        ];
    }
}
