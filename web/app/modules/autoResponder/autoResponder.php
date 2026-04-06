<?php

namespace Modules\AutoResponder;

use App\Services\RconService;
use App\Services\StateService;

abstract class AutoResponder
{
    protected $webDir;
    protected $logFile;
    protected $settings;
    protected $state;
    protected $rconConfig;
    protected ?StateService $stateService = null;
    private bool $stateServiceAvailable = false;

    public function __construct(?StateService $stateService = null)
    {
        $this->webDir = dirname(__DIR__, 2);
        $this->logFile = dirname($this->webDir) . '/logs/autoResponder.log';

        try {
            if ($stateService) {
                $this->stateService = $stateService;
            } else {
                $this->stateService = new StateService();
            }
            $this->stateServiceAvailable = true;
        } catch (\Exception $e) {
            $this->logWarning("StateService 初始化失败，将使用文件模式降级运行: " . $e->getMessage());
            $this->stateServiceAvailable = false;
        }

        $this->loadSettings();
        $this->loadState();
        $this->loadRconConfig();
    }

    protected function loadSettings(): void
    {
        if ($this->stateServiceAvailable && $this->stateService) {
            try {
                $data = $this->stateService->loadState('chatSettings');
                if (!empty($data)) {
                    $this->settings = $data;
                    return;
                }
                $this->logInfo("从数据库加载 chatSettings 为空，使用默认设置");
            } catch (\Exception $e) {
                $this->logError("StateService 加载 chatSettings 失败: " . $e->getMessage() . "\n堆栈: " . $e->getTraceAsString());
            }
        }

        $fallbackFile = dirname($this->webDir) . '/config/state/chatSettings.json';
        if (file_exists($fallbackFile)) {
            try {
                $content = file_get_contents($fallbackFile);
                $this->settings = json_decode($content, true) ?? [];
                if ($this->stateServiceAvailable) {
                    $this->logWarning("已从文件回退加载 chatSettings");
                }
            } catch (\Exception $e) {
                $this->logError("文件回退加载 chatSettings 失败: " . $e->getMessage());
                $this->settings = $this->getDefaultSettings();
            }
        } else {
            $this->settings = $this->getDefaultSettings();
        }
    }

    protected function loadState(): void
    {
        if ($this->stateServiceAvailable && $this->stateService) {
            try {
                $data = $this->stateService->loadState('autoResponderState');
                $this->state = !empty($data) ? $data : [];
                return;
            } catch (\Exception $e) {
                $this->logError("StateService 加载 autoResponderState 失败: " . $e->getMessage() . "\n堆栈: " . $e->getTraceAsString());
            }
        }

        $fallbackFile = dirname($this->webDir) . '/config/state/autoResponderState.json';
        if (file_exists($fallbackFile)) {
            try {
                $content = file_get_contents($fallbackFile);
                $this->state = json_decode($content, true) ?? [];
                if ($this->stateServiceAvailable) {
                    $this->logWarning("已从文件回退加载 autoResponderState");
                }
            } catch (\Exception $e) {
                $this->logError("文件回退加载 autoResponderState 失败: " . $e->getMessage());
                $this->state = [];
            }
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
        if ($this->stateServiceAvailable && $this->stateService) {
            try {
                $success = $this->stateService->saveState('autoResponderState', $this->state);
                if ($success) {
                    return;
                }
                $this->logWarning("StateService 保存 autoResponderState 失败，尝试文件回退");
            } catch (\Exception $e) {
                $this->logError("StateService 保存 autoResponderState 异常: " . $e->getMessage() . "\n堆栈: " . $e->getTraceAsString());
            }
        }

        $fallbackFile = dirname($this->webDir) . '/config/state/autoResponderState.json';
        try {
            $dir = dirname($fallbackFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($fallbackFile, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($this->stateServiceAvailable) {
                $this->logWarning("已回退到文件保存 autoResponderState");
            }
        } catch (\Exception $e) {
            $this->logError("文件回退保存 autoResponderState 失败: " . $e->getMessage());
        }
    }

    protected function saveSettings(): void
    {
        if ($this->stateServiceAvailable && $this->stateService) {
            try {
                $success = $this->stateService->saveState('chatSettings', $this->settings);
                if ($success) {
                    return;
                }
                $this->logWarning("StateService 保存 chatSettings 失败，尝试文件回退");
            } catch (\Exception $e) {
                $this->logError("StateService 保存 chatSettings 异常: " . $e->getMessage() . "\n堆栈: " . $e->getTraceAsString());
            }
        }

        $fallbackFile = dirname($this->webDir) . '/config/state/chatSettings.json';
        try {
            $dir = dirname($fallbackFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($fallbackFile, json_encode($this->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($this->stateServiceAvailable) {
                $this->logWarning("已回退到文件保存 chatSettings");
            }
        } catch (\Exception $e) {
            $this->logError("文件回退保存 chatSettings 失败: " . $e->getMessage());
        }
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

        static $poolClient = null;
        static $poolAvailable = null;
        
        if ($poolAvailable === null) {
            require_once dirname(__DIR__, 2) . '/rconPoolClient.php';
            $poolClient = new \RconPoolClient();
            $poolAvailable = $poolClient->ping()['success'] ?? false;
        }
        
        if ($poolAvailable) {
            $result = $poolClient->execute('/p');
            if ($result['success']) {
                return true;
            }
        } else {
            $rconTest = RconService::testConnection(
                $config['rcon_host'] ?? '127.0.0.1',
                $config['rcon_port'] ?? 27015,
                $config['rcon_password'] ?? ''
            );

            if ($rconTest) {
                return true;
            }
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
