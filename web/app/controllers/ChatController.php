<?php

namespace App\Controllers;

use App\Services\ChatService;
use App\Core\Response;

class ChatController
{
    private $chatService;

    public function __construct()
    {
        $this->chatService = new ChatService();
    }

    public function getSettings(): void
    {
        $settings = $this->chatService->getSettings();
        Response::success($settings);
    }

    public function saveSettings(array $params): void
    {
        $result = $this->chatService->updateSettings($params);

        if ($result) {
            Response::success(null, '设置已保存');
        }

        Response::error('保存设置失败');
    }

    public function addScheduledTask(array $params): void
    {
        $task = [
            'time' => $params['time'] ?? '',
            'message' => $params['message'] ?? '',
            'type' => $params['type'] ?? 'public',
            'enabled' => $params['enabled'] ?? true
        ];

        $result = $this->chatService->addScheduledTask($task);

        if ($result) {
            Response::success(null, '定时任务已添加');
        }

        Response::error('添加任务失败');
    }

    public function addTriggerResponse(array $params): void
    {
        $trigger = [
            'keyword' => $params['keyword'] ?? '',
            'response' => $params['response'] ?? '',
            'type' => $params['type'] ?? 'public',
            'enabled' => $params['enabled'] ?? true
        ];

        $result = $this->chatService->addTriggerResponse($trigger);

        if ($result) {
            Response::success(null, '触发响应已添加');
        }

        Response::error('添加触发响应失败');
    }

    public function deleteTriggerResponse(array $params): void
    {
        $id = $params['id'] ?? '';

        if (empty($id)) {
            Response::error('请指定触发响应ID');
        }

        $result = $this->chatService->deleteTriggerResponse($id);

        if ($result) {
            Response::success(null, '触发响应已删除');
        }

        Response::error('删除触发响应失败');
    }

    public function saveServerResponse(array $params): void
    {
        $type = $params['type'] ?? '';
        $keyword = $params['keyword'] ?? '';
        $value = $params['value'] ?? '';

        if (empty($type) || empty($keyword)) {
            Response::error('请填写完整信息');
        }

        $result = $this->chatService->saveServerResponse($type, $keyword, $value);

        if ($result) {
            Response::success(null, '服务器响应已保存');
        }

        Response::error('保存服务器响应失败');
    }

    public function removeServerResponse(array $params): void
    {
        $keyword = $params['keyword'] ?? '';
        $type = $params['type'] ?? '';

        if (empty($keyword) || empty($type)) {
            Response::error('请指定关键词和类型');
        }

        $result = $this->chatService->removeServerResponse($keyword, $type);

        if ($result) {
            Response::success(null, '服务器响应已删除');
        }

        Response::error('删除服务器响应失败');
    }

    public function getServerResponses(): void
    {
        $responses = $this->chatService->getServerResponses();
        Response::success($responses);
    }
}
