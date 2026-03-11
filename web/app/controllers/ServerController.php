<?php

namespace App\Controllers;

use App\Services\ServerService;
use App\Core\Response;

class ServerController
{
    private $serverService;

    public function __construct()
    {
        $this->serverService = new ServerService();
    }

    public function start(array $params): void
    {
        $result = $this->serverService->start($params);

        if (isset($result['error'])) {
            Response::error($result['error']);
        }

        Response::success($result, $result['message']);
    }

    public function stop(array $params): void
    {
        $serverId = $params['server'] ?? 'default';
        $result = $this->serverService->stop($serverId);
        Response::success($result, $result['message']);
    }

    public function save(array $params): void
    {
        $serverId = $params['server'] ?? 'default';
        $result = $this->serverService->save($serverId);

        if (isset($result['error'])) {
            Response::error($result['error']);
        }

        Response::success(null, $result['message']);
    }

    public function console(array $params): void
    {
        $command = $params['command'] ?? '';
        $serverId = $params['server'] ?? 'default';

        $result = $this->serverService->console($command, $serverId);

        if (isset($result['error'])) {
            Response::error($result['error']);
        }

        Response::success($result, $result['message']);
    }

    public function rconTest(array $params): void
    {
        $serverId = $params['server'] ?? 'default';
        $config = $this->serverService->getServerConfig($serverId);

        $result = \App\Services\RconService::testConnection(
            $config['rcon_host'] ?? '127.0.0.1',
            $config['rcon_port'] ?? 27015,
            $config['rcon_password'] ?? ''
        );

        Response::success([
            'connected' => $result,
            'server_id' => $serverId
        ]);
    }

    public function rconStatus(array $params): void
    {
        $serverId = $params['server'] ?? 'default';
        $config = $this->serverService->getServerConfig($serverId);

        $rcon = $this->serverService->getRconConnection($serverId);

        Response::success([
            'connected' => $rcon !== null && $rcon->isConnected(),
            'server_id' => $serverId,
            'rcon_port' => $config['rcon_port'] ?? 27015
        ]);
    }

    public function serverList(): void
    {
        $list = $this->serverService->getServerList();
        Response::success($list);
    }

    public function serverConfig(array $params): void
    {
        $serverId = $params['server'] ?? 'default';
        $config = $this->serverService->getServerConfig($serverId);

        unset($config['rcon_password']);

        Response::success($config);
    }

    public function saveServerConfig(array $params): void
    {
        $serverId = $params['server_id'] ?? '';

        if (empty($serverId)) {
            Response::error('请指定服务器ID');
        }

        $configFile = dirname(__DIR__, 2) . '/config/system/rcon.php';
        $configs = file_exists($configFile) ? require $configFile : [];

        $configs[$serverId] = [
            'rcon_enabled' => $params['rcon_enabled'] ?? true,
            'rcon_port' => (int)($params['rcon_port'] ?? 27015),
            'rcon_password' => $params['rcon_password'] ?? '',
            'rcon_host' => $params['rcon_host'] ?? '127.0.0.1',
            'screen_name' => $params['screen_name'] ?? 'factorio_server',
            'description' => $params['description'] ?? $serverId,
        ];

        $configContent = "<?php\n\nreturn [\n";
        foreach ($configs as $id => $cfg) {
            $configContent .= "    '$id' => [\n";
            $configContent .= "        'rcon_enabled' => " . ($cfg['rcon_enabled'] ? 'true' : 'false') . ",\n";
            $configContent .= "        'rcon_port' => " . $cfg['rcon_port'] . ",\n";
            $configContent .= "        'rcon_password' => '" . addslashes($cfg['rcon_password']) . "',\n";
            $configContent .= "        'rcon_host' => '" . addslashes($cfg['rcon_host']) . "',\n";
            $configContent .= "        'screen_name' => '" . addslashes($cfg['screen_name']) . "',\n";
            $configContent .= "        'description' => '" . addslashes($cfg['description']) . "',\n";
            $configContent .= "    ],\n";
        }
        $configContent .= "];\n";

        if (file_put_contents($configFile, $configContent) !== false) {
            Response::success(null, '服务器配置已保存');
        }

        Response::error('保存配置失败');
    }

    public function deleteServerConfig(array $params): void
    {
        $serverId = $params['server_id'] ?? '';

        if (empty($serverId)) {
            Response::error('请指定服务器ID');
        }

        $configFile = dirname(__DIR__, 2) . '/config/system/rcon.php';
        $configs = file_exists($configFile) ? require $configFile : [];

        if (!isset($configs[$serverId])) {
            Response::error('服务器配置不存在');
        }

        unset($configs[$serverId]);

        $configContent = "<?php\n\nreturn [\n";
        foreach ($configs as $id => $cfg) {
            $configContent .= "    '$id' => [\n";
            $configContent .= "        'rcon_enabled' => " . ($cfg['rcon_enabled'] ? 'true' : 'false') . ",\n";
            $configContent .= "        'rcon_port' => " . $cfg['rcon_port'] . ",\n";
            $configContent .= "        'rcon_password' => '" . addslashes($cfg['rcon_password']) . "',\n";
            $configContent .= "        'rcon_host' => '" . addslashes($cfg['rcon_host']) . "',\n";
            $configContent .= "        'screen_name' => '" . addslashes($cfg['screen_name']) . "',\n";
            $configContent .= "        'description' => '" . addslashes($cfg['description']) . "',\n";
            $configContent .= "    ],\n";
        }
        $configContent .= "];\n";

        if (file_put_contents($configFile, $configContent) !== false) {
            Response::success(null, '服务器配置已删除');
        }

        Response::error('删除配置失败');
    }

    public function isRunning(array $params): void
    {
        $serverId = $params['server'] ?? 'default';
        $running = $this->serverService->isRunning($serverId);

        Response::success([
            'running' => $running,
            'server_id' => $serverId
        ]);
    }
}
