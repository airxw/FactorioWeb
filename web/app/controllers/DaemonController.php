<?php

namespace App\Controllers;

use App\Core\Response;

class DaemonController
{
    private $pidFile;
    private $startScript;
    private $stopScript;
    private $daemonScript;

    public function __construct()
    {
        $basePath = dirname(__DIR__);
        $this->pidFile = $basePath . '/run/autoResponder.pid';
        $this->startScript = $basePath . '/scripts/start_daemon.sh';
        $this->stopScript = $basePath . '/scripts/stop_daemon.sh';
        $this->daemonScript = $basePath . '/auto_responder_daemon.php';
    }

    public function status(): void
    {
        $status = $this->getStatus();
        Response::success($status);
    }

    private function getStatus(): array
    {
        if (!file_exists($this->pidFile)) {
            return [
                'running' => false,
                'pid' => null,
                'message' => '守护进程未运行'
            ];
        }

        $pid = trim(file_get_contents($this->pidFile));

        if (empty($pid)) {
            return [
                'running' => false,
                'pid' => null,
                'message' => 'PID文件为空'
            ];
        }

        if (file_exists("/proc/$pid")) {
            return [
                'running' => true,
                'pid' => (int)$pid,
                'message' => '守护进程运行中'
            ];
        }

        @unlink($this->pidFile);

        return [
            'running' => false,
            'pid' => null,
            'message' => '守护进程已停止'
        ];
    }

    public function start(): void
    {
        $status = $this->getStatus();

        if ($status['running']) {
            Response::success($status, '守护进程已在运行');
        }

        if (!file_exists($this->startScript)) {
            Response::error('启动脚本不存在');
        }

        chmod($this->startScript, 0755);
        shell_exec("bash " . escapeshellarg($this->startScript) . " 2>&1");

        sleep(1);

        $status = $this->getStatus();

        if ($status['running']) {
            Response::success($status, '守护进程启动成功');
        }

        Response::error('守护进程启动失败');
    }

    public function stop(): void
    {
        $status = $this->getStatus();

        if (!$status['running']) {
            Response::success($status, '守护进程未运行');
        }

        if (file_exists($this->stopScript)) {
            chmod($this->stopScript, 0755);
            shell_exec("bash " . escapeshellarg($this->stopScript) . " 2>&1");
        } else {
            $pid = $status['pid'];
            shell_exec("kill " . escapeshellarg((string)$pid) . " 2>/dev/null");
        }

        sleep(1);

        if (file_exists($this->pidFile)) {
            @unlink($this->pidFile);
        }

        Response::success([
            'running' => false,
            'pid' => null
        ], '守护进程已停止');
    }

    public function restart(): void
    {
        $this->stop();
        sleep(1);
        $this->start();
    }

    public function runOnce(): void
    {
        if (!file_exists($this->daemonScript)) {
            Response::error('守护进程脚本不存在');
        }

        $output = shell_exec("php " . escapeshellarg($this->daemonScript) . " --run-once 2>&1");

        Response::success([
            'output' => $output
        ], '单次执行完成');
    }
}
