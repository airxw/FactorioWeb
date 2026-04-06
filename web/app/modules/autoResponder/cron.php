<?php

namespace Modules\AutoResponder;

use App\Core\Database;
use App\Services\StateService;

class Cron extends AutoResponder
{
    protected $logFile;
    private $db;
    private $stateService;
    private $useDatabase = true;

    public function __construct()
    {
        parent::__construct();
        $this->logFile = dirname($this->webDir) . '/logs/autoResponder.log';

        try {
            $this->db = Database::getInstance();
            $this->db->initialize();
            $this->stateService = new StateService();
        } catch (\Exception $e) {
            $this->logError("数据库初始化失败，降级为内存模式: " . $e->getMessage());
            $this->useDatabase = false;
            $this->db = null;
            $this->stateService = null;
        }
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
        if (!$this->useDatabase) {
            $this->logWarning("数据库不可用，跳过定时任务处理");
            return;
        }

        try {
            $tasks = $this->db->query(
                "SELECT * FROM chat_scheduled_tasks WHERE is_enabled = 1"
            );

            $currentTime = time();
            $currentMinute = date('H:i');

            foreach ($tasks as $task) {
                $taskTime = $task['scheduled_time'] ?? '';
                if ($taskTime === $currentMinute) {
                    $lastRunKey = 'scheduled_' . ($task['task_id'] ?? $task['id']);
                    $lastRun = $this->getStateValue($lastRunKey, 0);

                    if ($currentTime - $lastRun < 60) {
                        continue;
                    }

                    $message = $task['message'] ?? '';
                    if (!empty($message)) {
                        $this->sendChatMessage($message);
                        $this->logInfo("发送定时消息: $message");
                    }

                    $this->setStateValue($lastRunKey, $currentTime);
                }
            }
        } catch (\Exception $e) {
            $this->logError("处理定时任务失败: " . $e->getMessage());
        }
    }

    protected function processPeriodicMessages(): void
    {
        if (!$this->useDatabase) {
            $this->logWarning("数据库不可用，跳过周期消息处理");
            return;
        }

        try {
            $tasks = $this->db->query(
                "SELECT * FROM chat_scheduled_tasks WHERE is_enabled = 1 AND schedule_type IN ('15min', '30min', 'hourly', '2hour', '6hour', '12hour', 'daily')"
            );

            $currentTime = time();

            foreach ($tasks as $task) {
                $scheduleType = $task['schedule_type'];
                $interval = $this->getIntervalMinutes($scheduleType);

                if ($interval <= 0) {
                    continue;
                }

                $key = 'periodic_' . ($task['task_id'] ?? $task['id']);
                $lastRun = $this->getStateValue($key, 0);

                if ($currentTime - $lastRun >= $interval * 60) {
                    if ($scheduleType === 'daily') {
                        $scheduledTime = $task['scheduled_time'] ?? '';
                        if (!empty($scheduledTime) && $scheduledTime !== date('H:i')) {
                            continue;
                        }
                    }

                    $message = $task['message'] ?? '';
                    if (!empty($message)) {
                        $this->sendChatMessage($message);
                        $this->logInfo("发送周期消息 [$scheduleType]: $message");
                    }

                    $this->setStateValue($key, $currentTime);
                }
            }
        } catch (\Exception $e) {
            $this->logError("处理周期消息失败: " . $e->getMessage());
        }
    }

    protected function processMessageQueue(): void
    {
        if (!$this->useDatabase) {
            $this->logWarning("数据库不可用，跳过消息队列处理");
            return;
        }

        try {
            $messages = $this->db->query(
                "SELECT * FROM message_queues WHERE is_sent = 0 AND (scheduled_at IS NULL OR scheduled_at <= :now) ORDER BY priority DESC, created_at ASC",
                [':now' => time()]
            );

            foreach ($messages as $msg) {
                $messageText = $msg['message'] ?? '';

                if (!empty($messageText)) {
                    $this->sendChatMessage($messageText);
                    $this->logInfo("发送队列消息 [ID:{$msg['id']}]: $messageText");

                    $this->db->execute(
                        "UPDATE message_queues SET is_sent = 1, sent_at = :sentAt WHERE id = :id",
                        [':sentAt' => time(), ':id' => $msg['id']]
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logError("处理消息队列失败: " . $e->getMessage());
        }
    }

    public function queueMessage(string $message, int $delayMinutes = 0, string $type = 'public', string $player = ''): bool
    {
        if (!$this->useDatabase) {
            $this->logWarning("数据库不可用，无法添加消息到队列");
            return false;
        }

        try {
            $scheduledAt = $delayMinutes > 0 ? (time() + ($delayMinutes * 60)) : null;

            $result = $this->db->execute(
                "INSERT INTO message_queues (message, scheduled_at, created_at) VALUES (:message, :scheduledAt, :createdAt)",
                [
                    ':message' => $message,
                    ':scheduledAt' => $scheduledAt,
                    ':createdAt' => time()
                ]
            );

            if ($result > 0) {
                $this->logInfo("消息已添加到队列: $message" . ($delayMinutes > 0 ? " (延迟 {$delayMinutes} 分钟)" : ''));
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logError("添加消息到队列失败: " . $e->getMessage());
            return false;
        }
    }

    private function getIntervalMinutes(string $scheduleType): int
    {
        $intervals = [
            '15min' => 15,
            '30min' => 30,
            'hourly' => 60,
            '2hour' => 120,
            '6hour' => 360,
            '12hour' => 720,
            'daily' => 1440
        ];

        return $intervals[$scheduleType] ?? 0;
    }

    private function getStateValue(string $key, $default = 0)
    {
        if ($this->stateService) {
            return $this->stateService->getStateValue('autoResponderState', $key, $default);
        }
        return $this->state[$key] ?? $default;
    }

    private function setStateValue(string $key, $value): bool
    {
        if ($this->stateService) {
            return $this->stateService->setStateValue('autoResponderState', $key, $value);
        }
        $this->state[$key] = $value;
        $this->saveState();
        return true;
    }
}
