<?php

namespace Modules\AutoResponder;

class Cron extends AutoResponder
{
    protected $logFile;
    private $messageQueueFile;

    public function __construct()
    {
        parent::__construct();
        $this->logFile = dirname($this->webDir) . '/logs/autoResponder.log';
        $this->messageQueueFile = dirname($this->webDir) . '/config/state/messageQueue.json';
    }

    public function run(): void
    {
        if (!$this->isServerRunning()) {
            $this->logInfo('服务器未运行，跳过处理');
            return;
        }

        $this->processScheduledTasks();
        $this->processPeriodicMessages();
        $this->processMessageQueue();
    }

    protected function processScheduledTasks(): void
    {
        $tasks = $this->settings['scheduledTasks'] ?? [];
        $currentTime = time();
        $currentMinute = date('H:i');

        foreach ($tasks as $task) {
            if (!($task['enabled'] ?? true)) {
                continue;
            }

            $taskTime = $task['time'] ?? '';
            if ($taskTime === $currentMinute) {
                $lastRunKey = 'scheduled_' . ($task['id'] ?? md5(json_encode($task)));
                $lastRun = $this->state[$lastRunKey] ?? 0;

                if ($currentTime - $lastRun < 60) {
                    continue;
                }

                $message = $task['message'] ?? '';
                $type = $task['type'] ?? 'public';

                if (!empty($message)) {
                    if ($type === 'private') {
                        $this->logInfo("发送私密消息: $message");
                    } else {
                        $this->sendChatMessage($message);
                        $this->logInfo("发送定时消息: $message");
                    }
                }

                $this->state[$lastRunKey] = $currentTime;
                $this->saveState();
            }
        }
    }

    protected function processPeriodicMessages(): void
    {
        $periodicMessages = $this->settings['periodicMessages'] ?? [];

        foreach ($periodicMessages as $pm) {
            if (!($pm['enabled'] ?? true)) {
                continue;
            }

            $interval = (int)($pm['interval'] ?? 0);
            if ($interval <= 0) {
                continue;
            }

            $key = 'periodic_' . ($pm['id'] ?? md5(json_encode($pm)));
            $lastRun = $this->state[$key] ?? 0;
            $currentTime = time();

            if ($currentTime - $lastRun >= $interval * 60) {
                $message = $pm['message'] ?? '';
                if (!empty($message)) {
                    $this->sendChatMessage($message);
                    $this->logInfo("发送周期消息: $message");
                }
                $this->state[$key] = $currentTime;
                $this->saveState();
            }
        }
    }

    protected function processMessageQueue(): void
    {
        if (!file_exists($this->messageQueueFile)) {
            return;
        }

        $content = file_get_contents($this->messageQueueFile);
        $queue = json_decode($content, true) ?? [];

        if (empty($queue)) {
            return;
        }

        $remaining = [];
        $currentTime = time();

        foreach ($queue as $item) {
            $sendTime = $item['time'] ?? 0;

            if ($currentTime >= $sendTime) {
                $message = $item['message'] ?? '';
                $type = $item['type'] ?? 'public';
                $player = $item['player'] ?? '';

                if (!empty($message)) {
                    if ($type === 'private' && !empty($player)) {
                        $this->sendPrivateMessage($player, $message);
                    } else {
                        $this->sendChatMessage($message);
                    }
                    $this->logInfo("发送队列消息: $message");
                }
            } else {
                $remaining[] = $item;
            }
        }

        file_put_contents($this->messageQueueFile, json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function queueMessage(string $message, int $delayMinutes = 0, string $type = 'public', string $player = ''): bool
    {
        if (!file_exists($this->messageQueueFile)) {
            $queue = [];
        } else {
            $content = file_get_contents($this->messageQueueFile);
            $queue = json_decode($content, true) ?? [];
        }

        $queue[] = [
            'message' => $message,
            'time' => time() + ($delayMinutes * 60),
            'type' => $type,
            'player' => $player
        ];

        $dir = dirname($this->messageQueueFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($this->messageQueueFile, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }
}
