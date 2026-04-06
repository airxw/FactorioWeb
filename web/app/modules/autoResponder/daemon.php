<?php

namespace Modules\AutoResponder;

use App\Core\Database;
use App\Services\StateService;
use App\Services\RconService;

class Daemon extends AutoResponder
{
    private $pidFile;
    private $db;
    protected ?StateService $stateService = null;
    private $useDatabase = true;
    private $itemsFile;

    public function __construct()
    {
        parent::__construct();
        $this->pidFile = dirname($this->webDir) . '/run/autoResponder.pid';
        $this->itemsFile = dirname($this->webDir) . '/config/game/items.json';

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
        $this->runAsDaemon();
    }

    protected function runAsDaemon(): void
    {
        file_put_contents($this->pidFile, getmypid());

        $this->logInfo('守护进程启动，PID: ' . getmypid());
        $this->saveRunningState(true);

        $logFile = dirname($this->webDir) . '/factorio-current.log';
        $lastPosition = $this->loadFilePosition();

        while (true) {
            if (!$this->isServerRunning()) {
                sleep(5);
                continue;
            }

            clearstatcache();
            if (!file_exists($logFile)) {
                sleep(1);
                continue;
            }

            $currentSize = filesize($logFile);

            if ($currentSize < $lastPosition) {
                $lastPosition = 0;
            }

            if ($currentSize > $lastPosition) {
                $fh = fopen($logFile, 'r');
                if ($fh) {
                    fseek($fh, $lastPosition);
                    while ($line = fgets($fh)) {
                        $this->processLogLine(trim($line));
                    }
                    $lastPosition = ftell($fh);
                    fclose($fh);
                    $this->saveFilePosition($lastPosition);
                }
            }

            $this->checkVoteTimeout();

            sleep(1);
        }
    }

    protected function processLogLine(string $line): void
    {
        if (empty($line)) {
            return;
        }

        $this->logDebug("处理日志行: $line");

        if (strpos($line, 'joined the game') !== false) {
            $this->handlePlayerJoin($line);
        } elseif (strpos($line, 'left the game') !== false) {
            $this->handlePlayerLeave($line);
        } elseif (strpos($line, '[CHAT]') !== false || strpos($line, '[chat]') !== false) {
            $this->handleChatMessage($line);
        }
    }

    protected function handlePlayerJoin(string $line): void
    {
        if (preg_match('/(\S+)\s+joined the game/', $line, $matches)) {
            $player = $matches[1];
            $this->logInfo("玩家加入: $player");

            $playerEvent = $this->getPlayerEvent('welcome');
            if ($playerEvent && ($playerEvent['is_enabled'] ?? 0)) {
                $message = str_replace('{player}', $player, $playerEvent['message'] ?? '欢迎 {player} 加入游戏!');
                $msgType = $playerEvent['msg_type'] ?? 'public';
                if ($msgType === 'private') {
                    $this->sendPrivateMessage($player, $message);
                } else {
                    $this->sendChatMessage($message);
                }
            }

            $this->handlePlayerGift($player);
        }
    }

    protected function handlePlayerLeave(string $line): void
    {
        if (preg_match('/(\S+)\s+left the game/', $line, $matches)) {
            $player = $matches[1];
            $this->logInfo("玩家离开: $player");

            $playerEvent = $this->getPlayerEvent('goodbye');
            if ($playerEvent && ($playerEvent['is_enabled'] ?? 0)) {
                $message = str_replace('{player}', $player, $playerEvent['message'] ?? '{player} 离开了游戏');
                $this->sendChatMessage($message);
            }
        }
    }

    protected function handlePlayerGift(string $player): void
    {
        $playerHistory = $this->loadPlayerHistory();
        $isFirstJoin = !isset($playerHistory[$player]);

        if ($isFirstJoin) {
            $gift = $this->getPlayerEvent('firstJoinGift');
            $this->updatePlayerHistory($player, ['first_join' => time(), 'join_count' => 1]);
            $this->logInfo("首次加入礼包: $player");
        } else {
            $gift = $this->getPlayerEvent('rejoinGift');
            $currentCount = $playerHistory[$player]['join_count'] ?? 1;
            $this->updatePlayerHistory($player, [
                'last_join' => time(),
                'join_count' => $currentCount + 1
            ]);
            $this->logInfo("再次加入礼包: $player");
        }

        if ($gift && ($gift['is_enabled'] ?? 0)) {
            $items = $gift['items'] ?? '';
            if (!empty($items)) {
                $this->giveItemsToPlayer($player, $items);
            }
        }
    }

    protected function handleChatMessage(string $line): void
    {
        if (preg_match('/\[CHAT\]\s+(\S+):\s*(.*)/i', $line, $matches)) {
            $player = $matches[1];
            $message = $matches[2];

            $this->logDebug("聊天消息 - $player: $message");

            if (preg_match('/^FY\d{6}[A-Za-z0-9]{6}$/i', trim($message))) {
                $this->handleOrderRedemption($player, trim($message));
                return;
            }

            $this->processTriggerResponses($player, $message);
            $this->processCommands($player, $message);
        }
    }

    protected function processTriggerResponses(string $player, string $message): void
    {
        if (!$this->useDatabase) {
            return;
        }

        try {
            $triggers = $this->db->query(
                "SELECT * FROM chat_trigger_responses WHERE is_enabled = 1"
            );

            foreach ($triggers as $trigger) {
                $keyword = $trigger['keyword'] ?? '';
                if (empty($keyword)) {
                    continue;
                }

                if (stripos($message, $keyword) !== false) {
                    $response = $trigger['response'] ?? '';
                    if (!empty($response)) {
                        $response = str_replace(['{player}', '{keyword}'], [$player, $keyword], $response);
                        $this->sendChatMessage($response);
                        $this->logInfo("触发响应: $keyword -> $response");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logError("处理关键词响应失败: " . $e->getMessage());
        }
    }

    protected function processCommands(string $player, string $message): void
    {
        $message = trim($message);

        if (strpos($message, '!') !== 0) {
            return;
        }

        $parts = preg_split('/\s+/', $message, 2);
        $command = strtolower($parts[0] ?? '');
        $args = $parts[1] ?? '';

        switch ($command) {
            case '!ping':
                $this->handlePingCommand($player);
                break;
            case '!serverinfo':
                $this->handleServerInfoCommand($player);
                break;
            case '!votekick':
                $this->handleVoteKickCommand($player, $args);
                break;
            case '!vote':
                $this->handleVoteCommand($player, $args);
                break;
            case '!request':
                $this->handleRequestCommand($player, $args);
                break;
            case '!help':
                $this->handleHelpCommand($player);
                break;
        }
    }

    protected function handlePingCommand(string $player): void
    {
        $this->sendPrivateMessage($player, 'Pong! 服务器响应正常');
    }

    protected function handleServerInfoCommand(string $player): void
    {
        $result = $this->executeCommand('/server-info');

        if ($result !== null) {
            $this->sendPrivateMessage($player, "服务器信息: " . substr($result, 0, 200));
        } else {
            $this->sendPrivateMessage($player, '无法获取服务器信息');
        }
    }

    protected function handleVoteKickCommand(string $player, string $target): void
    {
        $target = trim($target);

        if (empty($target)) {
            $this->sendPrivateMessage($player, '用法: !votekick <玩家名>');
            return;
        }

        $voteState = $this->loadVoteState();
        $cooldowns = $this->loadVoteCooldown();

        $currentTime = time();
        $cooldownTime = 300;

        if (isset($cooldowns[$player]) && ($currentTime - $cooldowns[$player]) < $cooldownTime) {
            $remaining = $cooldownTime - ($currentTime - $cooldowns[$player]);
            $this->sendPrivateMessage($player, "投票冷却中，请等待 {$remaining} 秒");
            return;
        }

        if (!empty($voteState['active'])) {
            $this->sendPrivateMessage($player, '已有进行中的投票');
            return;
        }

        $voteState = [
            'active' => true,
            'initiator' => $player,
            'target' => $target,
            'votes' => [$player => true],
            'startTime' => $currentTime,
            'required' => 3
        ];

        $this->saveVoteState($voteState);
        $cooldowns[$player] = $currentTime;
        $this->saveVoteCooldown($cooldowns);

        $this->sendChatMessage("投票踢人: {$player} 发起踢出 {$target} 的投票，输入 !vote yes 参与投票");
    }

    protected function handleVoteCommand(string $player, string $vote): void
    {
        $vote = strtolower(trim($vote));
        $voteState = $this->loadVoteState();

        if (empty($voteState['active'])) {
            $this->sendPrivateMessage($player, '当前没有进行中的投票');
            return;
        }

        if ($vote !== 'yes') {
            return;
        }

        if (isset($voteState['votes'][$player])) {
            $this->sendPrivateMessage($player, '你已经投过票了');
            return;
        }

        $voteState['votes'][$player] = true;
        $this->saveVoteState($voteState);

        $voteCount = count($voteState['votes']);
        $this->sendChatMessage("投票进度: {$voteCount} 票支持踢出 {$voteState['target']}");

        if ($voteCount >= 3) {
            $this->executeKick($voteState['target']);
            $this->clearVoteState();
        }
    }

    protected function checkVoteTimeout(): void
    {
        $voteState = $this->loadVoteState();

        if (empty($voteState['active'])) {
            return;
        }

        $currentTime = time();
        $duration = 60;

        if (($currentTime - $voteState['startTime']) > $duration) {
            $this->sendChatMessage("投票踢人超时，{$voteState['target']} 未被踢出");
            $this->clearVoteState();
        }
    }

    protected function executeKick(string $player): void
    {
        $result = $this->executeCommand('/kick ' . $player);
        $this->sendChatMessage("{$player} 已被投票踢出服务器");
        $this->logInfo("投票踢人成功: $player");
    }

    protected function handleRequestCommand(string $player, string $args): void
    {
        $args = trim($args);

        if (empty($args)) {
            $this->sendPrivateMessage($player, '用法: !request <物品名> [数量]');
            return;
        }

        $parts = preg_split('/\s+/', $args, 2);
        $itemName = $parts[0];
        $count = (int)($parts[1] ?? 1);

        if ($count <= 0 || $count > 1000) {
            $count = 1;
        }

        $resolvedItem = $this->resolveItemName($itemName);

        if ($resolvedItem === null) {
            $this->sendPrivateMessage($player, "未找到物品: $itemName");
            return;
        }

        $cooldowns = $this->loadRequestItemCooldown();
        $currentTime = time();
        $cooldownTime = 300;

        if (isset($cooldowns[$player]) && ($currentTime - $cooldowns[$player]) < $cooldownTime) {
            $remaining = $cooldownTime - ($currentTime - $cooldowns[$player]);
            $this->sendPrivateMessage($player, "请求冷却中，请等待 {$remaining} 秒");
            return;
        }

        $result = $this->giveItemToPlayer($player, $resolvedItem, $count);

        if ($result) {
            $this->sendPrivateMessage($player, "已给予 {$count} 个 {$resolvedItem}");
            $cooldowns[$player] = $currentTime;
            $this->saveRequestItemCooldown($cooldowns);
        } else {
            $this->sendPrivateMessage($player, "给予物品失败");
        }
    }

    protected function handleHelpCommand(string $player): void
    {
        $help = "可用命令: !ping, !serverinfo, !votekick <玩家>, !vote yes, !request <物品> [数量], !help";
        $this->sendPrivateMessage($player, $help);
    }

    protected function giveItemToPlayer(string $player, string $item, int $count = 1): bool
    {
        $result = $this->executeCommand("/c game.players['$player'].insert{name='$item', count=$count}");
        return $result !== null;
    }

    protected function giveItemsToPlayer(string $player, string $itemsString): void
    {
        $items = preg_split('/[,\s]+/', $itemsString);

        foreach ($items as $itemDef) {
            if (empty($itemDef)) {
                continue;
            }

            if (preg_match('/^(\w+)(?::(\d+))?$/', $itemDef, $matches)) {
                $item = $matches[1];
                $count = (int)($matches[2] ?? 1);
                $this->giveItemToPlayer($player, $item, $count);
            }
        }
    }

    protected function resolveItemName(string $name): ?string
    {
        if (!file_exists($this->itemsFile)) {
            return $name;
        }

        $content = file_get_contents($this->itemsFile);
        $items = json_decode($content, true) ?? [];

        $nameLower = strtolower($name);

        foreach ($items as $item) {
            if (strtolower($item['name'] ?? '') === $nameLower) {
                return $item['name'];
            }

            $localised = $item['localised_name'] ?? [];
            foreach ($localised as $locale => $localName) {
                if (strtolower($localName) === $nameLower) {
                    return $item['name'];
                }
            }
        }

        return null;
    }

    protected function loadFilePosition(): int
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                return (int)$this->stateService->getStateValue('autoResponderState', 'lastLogPosition', 0);
            } catch (\Exception $e) {
                $this->logError("从数据库读取日志位置失败: " . $e->getMessage());
            }
        }
        return 0;
    }

    protected function saveFilePosition(int $position): void
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                $this->stateService->setStateValue('autoResponderState', 'lastLogPosition', $position);
            } catch (\Exception $e) {
                $this->logError("保存日志位置到数据库失败: " . $e->getMessage());
            }
        }
    }

    protected function saveRunningState(bool $isRunning): void
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                $this->stateService->setStateValue('autoResponderState', 'isRunning', $isRunning ? '1' : '0');
            } catch (\Exception $e) {
                $this->logError("保存运行状态到数据库失败: " . $e->getMessage());
            }
        }
    }

    protected function loadVoteState(): array
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                return $this->stateService->loadState('voteState');
            } catch (\Exception $e) {
                $this->logError("从数据库读取投票状态失败: " . $e->getMessage());
            }
        }
        return [];
    }

    protected function saveVoteState(array $state): void
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                $this->stateService->saveState('voteState', $state);
            } catch (\Exception $e) {
                $this->logError("保存投票状态到数据库失败: " . $e->getMessage());
            }
        }
    }

    protected function clearVoteState(): void
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                $this->stateService->deleteState('voteState');
            } catch (\Exception $e) {
                $this->logError("清除投票状态失败: " . $e->getMessage());
            }
        }
    }

    protected function loadVoteCooldown(): array
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                return $this->stateService->loadState('voteCooldown');
            } catch (\Exception $e) {
                $this->logError("从数据库读取投票冷却失败: " . $e->getMessage());
            }
        }
        return [];
    }

    protected function saveVoteCooldown(array $cooldowns): void
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                $this->stateService->saveState('voteCooldown', $cooldowns);
            } catch (\Exception $e) {
                $this->logError("保存投票冷却到数据库失败: " . $e->getMessage());
            }
        }
    }

    protected function loadPlayerHistory(): array
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                return $this->stateService->loadState('playerHistory');
            } catch (\Exception $e) {
                $this->logError("从数据库读取玩家历史失败: " . $e->getMessage());
            }
        }
        return [];
    }

    protected function updatePlayerHistory(string $player, array $data): void
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                $history = $this->loadPlayerHistory();
                $history[$player] = array_merge($history[$player] ?? [], $data);
                $this->stateService->saveState('playerHistory', $history);
            } catch (\Exception $e) {
                $this->logError("更新玩家历史到数据库失败: " . $e->getMessage());
            }
        }
    }

    protected function loadRequestItemCooldown(): array
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                return $this->stateService->loadState('requestItemCooldown');
            } catch (\Exception $e) {
                $this->logError("从数据库读取物品请求冷却失败: " . $e->getMessage());
            }
        }
        return [];
    }

    protected function saveRequestItemCooldown(array $cooldowns): void
    {
        if ($this->useDatabase && $this->stateService) {
            try {
                $this->stateService->saveState('requestItemCooldown', $cooldowns);
            } catch (\Exception $e) {
                $this->logError("保存物品请求冷却到数据库失败: " . $e->getMessage());
            }
        }
    }

    private function getPlayerEvent(string $eventType): ?array
    {
        if (!$this->useDatabase) {
            return null;
        }

        try {
            $result = $this->db->query(
                "SELECT * FROM chat_player_events WHERE event_type = :eventType",
                [':eventType' => $eventType]
            );

            return !empty($result) ? $result[0] : null;
        } catch (\Exception $e) {
            $this->logError("查询玩家事件配置失败 [$eventType]: " . $e->getMessage());
            return null;
        }
    }

    private function handleOrderRedemption(string $playerName, string $orderNumber): void
    {
        $this->logInfo("订单领取请求: 玩家=$playerName, 订单号=$orderNumber");

        $cacheKey = "order_redemption_{$orderNumber}_{$playerName}";
        if ($this->isRateLimited($cacheKey, 5)) {
            $this->sendPrivateMessage($playerName, "⏳ 请勿频繁操作，5秒后再试");
            return;
        }

        $apiUrl = 'http://localhost/factorio/web/app/api.php';
        $postData = http_build_query([
            'action' => 'deliver_by_number',
            'order_number' => $orderNumber,
            'player_name' => $playerName
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postData,
                'timeout' => 10
            ]
        ]);

        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            $this->sendPrivateMessage($playerName, "❌ 系统错误，请联系管理员");
            error_log("[AutoResponder] Order redemption failed for {$orderNumber}: API call failed");
            return;
        }

        $result = json_decode($response, true);

        if (isset($result['success']) && $result['success']) {
            $msg = "✅ 领取成功！\n";
            $msg .= "已获得：{$result['item']} x{$result['quantity']}\n";
            $msg .= "订单号：{$orderNumber}";
            $this->sendPrivateMessage($playerName, $msg);
            error_log("[AutoResponder] Order {$orderNumber} redeemed by {$playerName}");
        } else {
            $errorMsg = $result['error'] ?? '未知错误';
            $this->sendPrivateMessage($playerName, "❌ 领取失败：{$errorMsg}");
            error_log("[AutoResponder] Order redemption failed for {$orderNumber}: {$errorMsg}");
        }
    }

    private function isRateLimited(string $cacheKey, int $cooldownSeconds): bool
    {
        $cacheFile = sys_get_temp_dir() . '/factorio_rate_limit_' . md5($cacheKey);

        if (file_exists($cacheFile)) {
            $lastTime = (int) file_get_contents($cacheFile);
            if (time() - $lastTime < $cooldownSeconds) {
                return true;
            }
        }

        file_put_contents($cacheFile, time());
        return false;
    }

    public function __destruct()
    {
        $this->saveRunningState(false);
    }
}
