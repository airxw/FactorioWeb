<?php

namespace App\Services;

class ChatService
{
    private $settingsFile;

    public function __construct(string $settingsFile = null)
    {
        $this->settingsFile = $settingsFile ?? dirname(__DIR__, 2) . '/../config/state/chatSettings.json';
    }

    public function getSettings(): array
    {
        if (!file_exists($this->settingsFile)) {
            return $this->getDefaultSettings();
        }

        $content = file_get_contents($this->settingsFile);
        $settings = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->getDefaultSettings();
        }

        return array_merge($this->getDefaultSettings(), $settings);
    }

    public function saveSettings(array $settings): bool
    {
        $dir = dirname($this->settingsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents(
            $this->settingsFile,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;
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
        $settings = $this->getSettings();
        $task['id'] = uniqid();
        $settings['scheduledTasks'][] = $task;
        return $this->saveSettings($settings);
    }

    public function addTriggerResponse(array $trigger): bool
    {
        $settings = $this->getSettings();
        $trigger['id'] = uniqid();
        $settings['triggerResponses'][] = $trigger;
        return $this->saveSettings($settings);
    }

    public function deleteTriggerResponse(string $id): bool
    {
        $settings = $this->getSettings();
        $settings['triggerResponses'] = array_filter(
            $settings['triggerResponses'],
            fn($item) => $item['id'] !== $id
        );
        $settings['triggerResponses'] = array_values($settings['triggerResponses']);
        return $this->saveSettings($settings);
    }

    public function saveServerResponse(string $type, string $keyword, string $value): bool
    {
        $settings = $this->getSettings();

        if (!isset($settings['serverResponses'])) {
            $settings['serverResponses'] = [];
        }

        $found = false;
        foreach ($settings['serverResponses'] as $index => $response) {
            if ($response['keyword'] === $keyword) {
                $settings['serverResponses'][$index] = [
                    'type' => $type,
                    'keyword' => $keyword,
                    'value' => $value
                ];
                $found = true;
                break;
            }
        }

        if (!$found) {
            $settings['serverResponses'][] = [
                'type' => $type,
                'keyword' => $keyword,
                'value' => $value
            ];
        }

        return $this->saveSettings($settings);
    }

    public function removeServerResponse(string $keyword, string $type): bool
    {
        $settings = $this->getSettings();

        if (!isset($settings['serverResponses'])) {
            return false;
        }

        $settings['serverResponses'] = array_filter(
            $settings['serverResponses'],
            fn($response) => !($response['keyword'] === $keyword && $response['type'] === $type)
        );
        $settings['serverResponses'] = array_values($settings['serverResponses']);

        return $this->saveSettings($settings);
    }

    public function getServerResponses(): array
    {
        $settings = $this->getSettings();
        return $settings['serverResponses'] ?? [];
    }

    private function getDefaultSettings(): array
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
            ]
        ];
    }
}
