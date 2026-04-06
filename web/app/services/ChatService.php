<?php

namespace App\Services;

use App\Core\Database;

class ChatService
{
    private Database $db;
    private string $settingsFile;

    public function __construct(Database $db = null, string $settingsFile = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->settingsFile = $settingsFile ?? dirname(__DIR__, 2) . '/../config/state/chatSettings.json';
    }

    public function getSettings(): array
    {
        $scheduledTasks = $this->db->query('SELECT * FROM chat_scheduled_tasks ORDER BY created_at');
        $triggerResponses = $this->db->query('SELECT * FROM chat_trigger_responses WHERE is_enabled = 1 ORDER BY created_at');
        $serverResponses = $this->db->query('SELECT * FROM chat_server_responses ORDER BY created_at');
        $playerEventsRaw = $this->db->query('SELECT * FROM chat_player_events');

        $playerEvents = [
            'welcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
            'vipWelcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
            'goodbye' => ['enabled' => false, 'message' => '', 'type' => 'public'],
            'firstJoinGift' => ['enabled' => false, 'items' => ''],
            'rejoinGift' => ['enabled' => false, 'items' => '']
        ];

        foreach ($playerEventsRaw as $event) {
            if (isset($playerEvents[$event['event_type']])) {
                $playerEvents[$event['event_type']] = [
                    'enabled' => (bool)$event['is_enabled'],
                    'message' => $event['message'],
                    'type' => $event['msg_type'],
                    'items' => $event['items']
                ];
            }
        }

        return [
            'scheduledTasks' => $scheduledTasks,
            'triggerResponses' => $triggerResponses,
            'serverResponses' => $serverResponses,
            'playerEvents' => $playerEvents
        ];
    }

    public function saveSettings(array $settings): bool
    {
        if (isset($settings['scheduledTasks'])) {
            $this->db->execute('DELETE FROM chat_scheduled_tasks');
            foreach ($settings['scheduledTasks'] as $task) {
                $this->addScheduledTask($task);
            }
        }

        if (isset($settings['triggerResponses'])) {
            $this->db->execute('DELETE FROM chat_trigger_responses');
            foreach ($settings['triggerResponses'] as $trigger) {
                $this->addTriggerResponse($trigger);
            }
        }

        if (isset($settings['serverResponses'])) {
            $this->db->execute('DELETE FROM chat_server_responses');
            foreach ($settings['serverResponses'] as $response) {
                $this->saveServerResponse($response['type'], $response['keyword'], $response['value']);
            }
        }

        if (isset($settings['playerEvents'])) {
            foreach ($settings['playerEvents'] as $eventType => $data) {
                $this->db->execute(
                    'INSERT OR REPLACE INTO chat_player_events (event_type, is_enabled, message, items, msg_type, updated_at) VALUES (:type, :enabled, :message, :items, :msgType, :updated)',
                    [
                        ':type' => $eventType,
                        ':enabled' => $data['enabled'] ? 1 : 0,
                        ':message' => $data['message'] ?? '',
                        ':items' => $data['items'] ?? '',
                        ':msgType' => $data['type'] ?? 'public',
                        ':updated' => time()
                    ]
                );
            }
        }

        return true;
    }

    public function updateSettings(array $data): bool
    {
        $currentSettings = $this->getSettings();

        if (isset($data['scheduledTasks'])) {
            $currentSettings['scheduledTasks'] = $data['scheduledTasks'];
        }

        if (isset($data['triggerResponses'])) {
            $currentSettings['triggerResponses'] = $data['triggerResponses'];
        }

        if (isset($data['serverResponses'])) {
            $currentSettings['serverResponses'] = $data['serverResponses'];
        }

        if (isset($data['playerEvents'])) {
            $currentSettings['playerEvents'] = $data['playerEvents'];
        }

        return $this->saveSettings($currentSettings);
    }

    public function addScheduledTask(array $task): bool
    {
        $taskId = $task['id'] ?? uniqid();
        return $this->db->execute(
            'INSERT OR IGNORE INTO chat_scheduled_tasks (task_id, message, schedule_type, scheduled_time, is_enabled, created_at) VALUES (:id, :message, :type, :time, :enabled, :created)',
            [
                ':id' => $taskId,
                ':message' => $task['message'] ?? '',
                ':type' => $task['scheduleType'] ?? 'hourly',
                ':time' => $task['scheduledTime'] ?? null,
                ':enabled' => isset($task['isEnabled']) ? ($task['isEnabled'] ? 1 : 0) : 1,
                ':created' => time()
            ]
        ) > 0;
    }

    public function addTriggerResponse(array $trigger): bool
    {
        $triggerId = $trigger['id'] ?? uniqid();
        return $this->db->execute(
            'INSERT OR IGNORE INTO chat_trigger_responses (trigger_id, keyword, response, is_enabled, created_at) VALUES (:id, :keyword, :response, :enabled, :created)',
            [
                ':id' => $triggerId,
                ':keyword' => $trigger['keyword'] ?? '',
                ':response' => $trigger['response'] ?? '',
                ':enabled' => isset($trigger['isEnabled']) ? ($trigger['isEnabled'] ? 1 : 0) : 1,
                ':created' => time()
            ]
        ) > 0;
    }

    public function deleteTriggerResponse(string $id): bool
    {
        return $this->db->execute(
            'DELETE FROM chat_trigger_responses WHERE trigger_id = :id',
            [':id' => $id]
        ) > 0;
    }

    public function saveServerResponse(string $type, string $keyword, string $value): bool
    {
        return $this->db->execute(
            'INSERT OR REPLACE INTO chat_server_responses (type, keyword, value, created_at) VALUES (:type, :keyword, :value, :created)',
            [
                ':type' => $type,
                ':keyword' => $keyword,
                ':value' => $value,
                ':created' => time()
            ]
        ) > 0;
    }

    public function removeServerResponse(string $keyword, string $type): bool
    {
        return $this->db->execute(
            'DELETE FROM chat_server_responses WHERE keyword = :keyword AND type = :type',
            [':keyword' => $keyword, ':type' => $type]
        ) > 0;
    }

    public function getServerResponses(): array
    {
        return $this->db->query('SELECT * FROM chat_server_responses ORDER BY created_at');
    }

    private function getDefaultSettings(): array
    {
        return [
            'scheduledTasks' => [],
            'triggerResponses' => [],
            'serverResponses' => [],
            'playerEvents' => [
                'welcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'vipWelcome' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'goodbye' => ['enabled' => false, 'message' => '', 'type' => 'public'],
                'firstJoinGift' => ['enabled' => false, 'items' => ''],
                'rejoinGift' => ['enabled' => false, 'items' => '']
            ]
        ];
    }
}
