<?php
/**
 * ============================================================================
 * Factorio 服务端管理服务
 * 
 * ⚠️⚠️⚠️ 重要安全警告 ⚠️⚠️⚠️
 * 
 * 【严禁使用非 web 用户权限启动 Factorio 服务端】
 * 
 * 本系统设计为通过 Web 界面管理 Factorio 服务端。
 * 所有服务端启动、停止、重启操作必须通过以下方式进行：
 * 
 * 1. Web 管理界面（推荐）
 * 2. 以 www/web 用户身份运行的相关脚本
 * 
 * 禁止行为：
 * - 禁止使用 root 用户直接启动 factorio 服务端
 * - 禁止使用其他非 web 用户启动 factorio 服务端
 * - 禁止直接在命令行运行 factorio 可执行文件
 * 
 * 违反此规定可能导致：
 * - 文件权限混乱
 * - Web 界面无法正常管理服务端
 * - 存档文件损坏或无法访问
 * - 安全风险
 * 
 * 正确启动方式：通过 Web 界面 -> 服务端管理 -> 启动服务端
 * ============================================================================
 */

namespace App\Services;

class ServerService
{
    private $rconConfigs;
    private $rconConnections = [];
    private $runningCache = [];

    public function __construct(?array $rconConfigs = null)
    {
        $this->rconConfigs = $rconConfigs ?? $this->loadRconConfig();
    }

    private function loadRconConfig(): array
    {
        $configFile = dirname(__DIR__, 2) . '/config/system/rcon.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        return [
            'default' => [
                'rcon_enabled' => true,
                'rcon_port' => 27015,
                'rcon_password' => 'factorio_rcon_password',
                'rcon_host' => '127.0.0.1',
                'screen_name' => 'factorio_server',
                'description' => '默认服务端',
            ],
        ];
    }

    public function getServerConfig(string $serverId = 'default'): array
    {
        if (isset($this->rconConfigs[$serverId])) {
            return $this->rconConfigs[$serverId];
        }

        if (isset($this->rconConfigs['default'])) {
            return $this->rconConfigs['default'];
        }

        $firstKey = array_key_first($this->rconConfigs);
        return $this->rconConfigs[$firstKey] ?? [
            'rcon_enabled' => true,
            'rcon_port' => 27015,
            'rcon_password' => 'factorio_rcon_password',
            'rcon_host' => '127.0.0.1',
            'screen_name' => 'factorio_server',
            'description' => '默认服务端',
        ];
    }

    public function getServerList(): array
    {
        $list = [];
        foreach ($this->rconConfigs as $id => $config) {
            $list[] = [
                'id' => $id,
                'description' => $config['description'] ?? $id,
                'rcon_port' => $config['rcon_port'] ?? 27015,
                'screen_name' => $config['screen_name'] ?? 'factorio_server',
            ];
        }
        return $list;
    }

    public function getRconConnection(string $serverId = 'default'): ?RconService
    {
        $config = $this->getServerConfig($serverId);

        if (!($config['rcon_enabled'] ?? true)) {
            return null;
        }

        if (isset($this->rconConnections[$serverId]) && $this->rconConnections[$serverId]->isConnected()) {
            return $this->rconConnections[$serverId];
        }

        try {
            $rcon = new RconService(
                $config['rcon_host'] ?? '127.0.0.1',
                $config['rcon_port'] ?? 27015,
                $config['rcon_password'] ?? ''
            );
            $rcon->connect();
            $this->rconConnections[$serverId] = $rcon;
            return $rcon;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function sendRconCommand(string $command, string $serverId = 'default'): ?string
    {
        static $poolClient = null;
        static $poolAvailable = null;
        
        if ($poolAvailable === null) {
            require_once dirname(__DIR__) . '/rconPoolClient.php';
            $poolClient = new \RconPoolClient();
            $poolAvailable = $poolClient->ping()['success'] ?? false;
        }
        
        if ($poolAvailable) {
            $result = $poolClient->execute($command);
            if ($result['success']) {
                return $result['result'] ?? '';
            }
            return null;
        }

        $config = $this->getServerConfig($serverId);

        if (!($config['rcon_enabled'] ?? true)) {
            return null;
        }

        return RconService::quickCommand(
            $command,
            $config['rcon_host'] ?? '127.0.0.1',
            $config['rcon_port'] ?? 27015,
            $config['rcon_password'] ?? ''
        );
    }

    public function getScreenName(string $serverId = 'default'): string
    {
        $config = $this->getServerConfig($serverId);
        return $config['screen_name'] ?? 'factorio_server';
    }

    public function isRunning(string $serverId = 'default'): bool
    {
        $currentTime = time();

        if (isset($this->runningCache[$serverId])) {
            if ($currentTime - $this->runningCache[$serverId]['timestamp'] < 5) {
                return $this->runningCache[$serverId]['status'];
            }
        }

        $config = $this->getServerConfig($serverId);

        if ($config['rcon_enabled'] ?? true) {
            static $poolClient = null;
            static $poolAvailable = null;
            
            if ($poolAvailable === null) {
                require_once dirname(__DIR__) . '/rconPoolClient.php';
                $poolClient = new \RconPoolClient();
                $poolAvailable = $poolClient->ping()['success'] ?? false;
            }
            
            if ($poolAvailable) {
                $result = $poolClient->execute('/p');
                if ($result['success']) {
                    $this->runningCache[$serverId] = [
                        'status' => true,
                        'timestamp' => $currentTime
                    ];
                    return true;
                }
            } else {
                $rconTest = RconService::testConnection(
                    $config['rcon_host'] ?? '127.0.0.1',
                    $config['rcon_port'] ?? 27015,
                    $config['rcon_password'] ?? ''
                );
                if ($rconTest) {
                    $this->runningCache[$serverId] = [
                        'status' => true,
                        'timestamp' => $currentTime
                    ];
                    return true;
                }
            }
        }

        $screenName = $config['screen_name'] ?? 'factorio_server';
        $screenCheck = shell_exec("screen -ls | grep " . escapeshellarg($screenName));
        $psCheck = shell_exec("pgrep -f 'factorio.*headless'");
        $status = !empty($screenCheck) || !empty($psCheck);

        $this->runningCache[$serverId] = [
            'status' => $status,
            'timestamp' => $currentTime
        ];

        return $status;
    }

    public function executeScreenCommand(string $command, string $serverId = 'default'): void
    {
        $screenName = $this->getScreenName($serverId);
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $command);
        shell_exec("screen -S " . escapeshellarg($screenName) . " -p 0 -X stuff \"$escaped\n\"");
    }

    public function start(array $params): array
    {
        $serverId = $params['server'] ?? 'default';
        $map = basename($params['map'] ?? '');
        $cfg = basename($params['config'] ?? '');
        $ver = $params['version'] ?? '';

        if ($this->isRunning($serverId)) {
            return ['message' => '服务器已在运行，无需重复启动'];
        }

        if (!$map || !$cfg || !$ver) {
            return ['error' => '请完整选择版本、地图和配置'];
        }
        
        $baseDir = dirname(__DIR__, 2);
        $versionsDir = "$baseDir/../versions";
        $versionDir = "$versionsDir/$ver";
        
        $possibleBinPaths = [
            "$versionDir/factorio/bin/x64/factorio",
            "$versionDir/bin/x64/factorio"
        ];
        
        $bin = null;
        foreach ($possibleBinPaths as $path) {
            if (file_exists($path)) {
                $bin = $path;
                break;
            }
        }
        
        if (!$bin || !file_exists($bin)) {
            return ['error' => '服务端版本不存在'];
        }
        
        $serverConfig = $this->getServerConfig($serverId);
        $screenName = $serverConfig['screen_name'] ?? 'factorio_server';
        $saveDir = "$versionDir/saves";
        $configDir = "$baseDir/config/serverConfigs";
        $modDir = "$baseDir/../mods";
        $logFile = "$baseDir/../factorio-current.log";

        $selectedSave = "$saveDir/$map";
        $currentSave = "$saveDir/current.zip";

        if (!file_exists($selectedSave)) {
            return ['error' => '选择的存档文件不存在：' . $map];
        }

        if ($map !== 'current.zip') {
            if (!copy($selectedSave, $currentSave)) {
                return ['error' => '无法复制存档文件，请检查权限'];
            }
        }

        $rconPort = $serverConfig['rcon_port'] ?? 27015;
        $rconPassword = $serverConfig['rcon_password'] ?? 'factorio_rcon_password';
        $serverRoot = "$baseDir/server";
        
        // 从配置文件读取白名单设置
        $configFilePath = "$configDir/$cfg";
        $serverSettings = [];
        if (file_exists($configFilePath)) {
            $serverSettings = json_decode(file_get_contents($configFilePath), true) ?: [];
        }
        $useWhitelist = $serverSettings['use_server_whitelist'] ?? false;
        $whitelistArg = $useWhitelist ? '--use-server-whitelist' : '';

        $cmd = sprintf(
            "screen -dmS %s bash -c 'cd %s && %s --start-server %s --server-settings %s --mod-directory %s %s --rcon-port %d --rcon-password %s >> %s 2>&1'",
            escapeshellarg($screenName),
            escapeshellarg($serverRoot),
            escapeshellarg($bin),
            escapeshellarg($currentSave),
            escapeshellarg($configFilePath),
            escapeshellarg($modDir),
            $whitelistArg,
            $rconPort,
            escapeshellarg($rconPassword),
            escapeshellarg($logFile)
        );

        shell_exec($cmd);

        return [
            'message' => '服务器启动成功',
            'server_id' => $serverId,
            'screen_name' => $screenName,
            'rcon_port' => $rconPort
        ];
    }

    public function stop(string $serverId = 'default'): array
    {
        $screenName = $this->getScreenName($serverId);

        if ($this->isRunning($serverId)) {
            $result = $this->sendRconCommand('/quit', $serverId);

            if ($result === null) {
                shell_exec("screen -S " . escapeshellarg($screenName) . " -X stuff \"/quit\n\"");
            }

            sleep(2);

            if ($this->isRunning($serverId)) {
                shell_exec("screen -S " . escapeshellarg($screenName) . " -X quit");
            }
        }

        return ['message' => '服务器正在关闭', 'server_id' => $serverId];
    }

    public function save(string $serverId = 'default'): array
    {
        if (!$this->isRunning($serverId)) {
            return ['error' => '服务器未运行，无法保存'];
        }

        $this->sendRconCommand('/save', $serverId);
        return ['message' => '保存命令已发送'];
    }

    public function console(string $command, string $serverId = 'default'): array
    {
        if ($command === '') {
            return ['error' => 'Empty command'];
        }

        $result = $this->sendRconCommand($command, $serverId);

        if ($result !== null) {
            return [
                'message' => '指令已发送',
                'response' => $result,
                'method' => 'rcon',
                'server_id' => $serverId
            ];
        }

        $this->executeScreenCommand($command, $serverId);
        return [
            'message' => '指令已发送',
            'method' => 'screen',
            'server_id' => $serverId
        ];
    }
    public function getOnlinePlayerCount(string $serverId = 'default'): int
    {
        $rcon = $this->getRconConnection($serverId);
        if ($rcon === null) {
            return 0;
        }
        
        $result = $rcon->sendCommand('/players online');
        if ($result === null) {
            return 0;
        }
        
        preg_match_all('/\((\d+)\)\s+([^\s]+)/', $result, $matches);
        return count($matches[2] ?? []);
    }

    public function getOnlinePlayers(string $serverId = 'default'): array
    {
        $rcon = $this->getRconConnection($serverId);
        if ($rcon === null) {
            return [];
        }
        
        $result = $rcon->sendCommand('/players online');
        if ($result === null) {
            return [];
        }
        
        $players = [];
        if (preg_match_all('/\((\d+)\)\s+([^\s]+)/', $result, $matches)) {
            foreach ($matches[2] as $name) {
                $players[] = trim($name);
            }
        }
        
        return $players;
    }

    public function generateMap(array $params): array
    {
        $mapName = trim($params['map_name'] ?? '');
        $seed = $params['seed'] ?? null;
        $version = $params['version'] ?? '';
        $mapWidth = (int)($params['map_width'] ?? 0);
        $mapHeight = (int)($params['map_height'] ?? 0);

        if (empty($mapName)) {
            return ['error' => '地图名称不能为空'];
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]+$/u', $mapName)) {
            return ['error' => '地图名称只能包含字母、数字、下划线、连字符和中文'];
        }

        $safeName = preg_replace('/\.zip$/i', '', $mapName) . '.zip';

        $baseDir = dirname(__DIR__, 2);
        $versionsDir = "$baseDir/../versions";

        if ($version && is_dir("$versionsDir/$version")) {
            $versionDir = "$versionsDir/$version";
        } else {
            $dirs = glob("$versionsDir/*", GLOB_ONLYDIR);
            if (empty($dirs)) {
                return ['error' => '未找到任何已安装的服务端版本'];
            }
            usort($dirs, fn($a, $b) => version_compare(basename($b), basename($a)));
            $versionDir = $dirs[0];
        }

        $possibleBinPaths = [
            "$versionDir/factorio/bin/x64/factorio",
            "$versionDir/bin/x64/factorio"
        ];
        $bin = null;
        foreach ($possibleBinPaths as $path) {
            if (file_exists($path)) {
                $bin = $path;
                break;
            }
        }
        if (!$bin) {
            return ['error' => '未找到 Factorio 可执行文件，请先安装服务端版本'];
        }

        $saveDir = "$versionDir/saves";
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }

        $targetPath = "$saveDir/$safeName";
        if (file_exists($targetPath)) {
            return ['error' => "地图存档已存在: $safeName"];
        }

        $cmdArgs = [$bin, '--create', $safeName];
        if ($seed !== null && $seed !== '') {
            $cmdArgs[] = '--seed';
            $cmdArgs[] = (string)$seed;
        }
        if ($mapWidth > 0 && $mapHeight > 0) {
            $cmdArgs[] = '--map-gen-settings';
            $cmdArgs[] = escapeshellarg('{"width":' . $mapWidth . ',"height":' . $mapHeight . '}');
        } elseif ($mapWidth > 0 || $mapHeight > 0) {
            $w = max(0, $mapWidth);
            $h = max(0, $mapHeight);
            $cmdArgs[] = '--map-gen-settings';
            $cmdArgs[] = escapeshellarg('{"width":' . $w . ',"height":' . $h . '}');
        }

        $cmdLine = implode(' ', array_map(function ($arg) {
            return (strpos($arg, ' ') !== false || strpos($arg, "'") !== false)
                ? escapeshellarg($arg)
                : escapeshellcmd($arg);
        }, $cmdArgs));

        $output = shell_exec("cd " . escapeshellarg($saveDir) . " && $cmdLine 2>&1");

        if (file_exists($targetPath)) {
            return [
                'message' => "地图生成成功: $safeName",
                'map_file' => $safeName,
                'file_size' => round(filesize($targetPath) / 1024 / 1024, 2),
                'output' => $output
            ];
        }

        return [
            'error' => "地图生成失败，请检查日志",
            'output' => $output
        ];
    }
}
