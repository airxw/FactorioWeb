<?php

namespace Modules\AutoResponder;

use App\Services\RconService;

class Daemon extends AutoResponder
{
    private $pidFile;
    private $positionFile;
    private $itemsFile;
    private $voteStateFile;
    private $voteCooldownFile;
    private $playerHistoryFile;
    private $requestItemCooldownFile;

    public function __construct()
    {
        parent::__construct();
        $this->pidFile = dirname($this->webDir) . '/run/autoResponder.pid';
        $this->positionFile = dirname($this->webDir) . '/config/state/autoResponderPosition.txt';
        $this->itemsFile = dirname($this->webDir) . '/config/game/items.json';
        $this->voteStateFile = dirname($this->webDir) . '/config/state/voteState.json';
        $this->voteCooldownFile = dirname($this->webDir) . '/config/state/voteCooldown.json';
        $this->playerHistoryFile = dirname($this->webDir) . '/config/state/playerHistory.json';
        $this->requestItemCooldownFile = dirname($this->webDir) . '/config/state/requestItemCooldown.json';
    }

    public function run(): void
    {
        $this->runAsDaemon();
    }

    protected function runAsDaemon(): void
    {
        file_put_contents($this->pidFile, getmypid());

        $this->logInfo('守护进程启动，PID: ' . getmypid());

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

            $playerEvents = $this->settings['playerEvents'] ?? [];
            $welcome = $playerEvents['welcome'] ?? [];

            if ($welcome['enabled'] ?? false) {
                $message = str_replace('{player}', $player, $welcome['message'] ?? '欢迎 {player} 加入游戏!');
                if ($welcome['type'] === 'private') {
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

            $playerEvents = $this->settings['playerEvents'] ?? [];
            $goodbye = $playerEvents['goodbye'] ?? [];

            if ($goodbye['enabled'] ?? false) {
                $message = str_replace('{player}', $player, $goodbye['message'] ?? '{player} 离开了游戏');
                $this->sendChatMessage($message);
            }
        }
    }

    protected function handlePlayerGift(string $player): void
    {
        $playerHistory = $this->loadPlayerHistory();
        $playerEvents = $this->settings['playerEvents'] ?? [];

        if (!isset($playerHistory[$player])) {
            $gift = $playerEvents['firstJoinGift'] ?? [];
            $playerHistory[$player] = ['first_join' => time(), 'join_count' => 1];
            $this->logInfo("首次加入礼包: $player");
        } else {
            $gift = $playerEvents['rejoinGift'] ?? [];
            $playerHistory[$player]['join_count'] = ($playerHistory[$player]['join_count'] ?? 0) + 1;
            $playerHistory[$player]['last_join'] = time();
            $this->logInfo("再次加入礼包: $player");
        }

        $this->savePlayerHistory($playerHistory);

        if ($gift['enabled'] ?? false) {
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

            $this->processTriggerResponses($player, $message);
            $this->processCommands($player, $message);
        }
    }

    protected function processTriggerResponses(string $player, string $message): void
    {
        $triggers = $this->settings['triggerResponses'] ?? [];

        foreach ($triggers as $trigger) {
            if (!($trigger['enabled'] ?? true)) {
                continue;
            }

            $keyword = $trigger['keyword'] ?? '';
            if (empty($keyword)) {
                continue;
            }

            if (stripos($message, $keyword) !== false) {
                $response = $trigger['response'] ?? '';
                $type = $trigger['type'] ?? 'public';

                if (!empty($response)) {
                    $response = str_replace(['{player}', '{keyword}'], [$player, $keyword], $response);

                    if ($type === 'private') {
                        $this->sendPrivateMessage($player, $response);
                    } else {
                        $this->sendChatMessage($response);
                    }
                    $this->logInfo("触发响应: $keyword -> $response");
                }
            }
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
            'start_time' => $currentTime,
            'duration' => 60
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
        $duration = $voteState['duration'] ?? 60;

        if (($currentTime - $voteState['start_time']) > $duration) {
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
        if (file_exists($this->positionFile)) {
            return (int)file_get_contents($this->positionFile);
        }
        return 0;
    }

    protected function saveFilePosition(int $position): void
    {
        file_put_contents($this->positionFile, $position);
    }

    protected function loadVoteState(): array
    {
        if (file_exists($this->voteStateFile)) {
            return json_decode(file_get_contents($this->voteStateFile), true) ?? [];
        }
        return [];
    }

    protected function saveVoteState(array $state): void
    {
        file_put_contents($this->voteStateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    protected function clearVoteState(): void
    {
        if (file_exists($this->voteStateFile)) {
            unlink($this->voteStateFile);
        }
    }

    protected function loadVoteCooldown(): array
    {
        if (file_exists($this->voteCooldownFile)) {
            return json_decode(file_get_contents($this->voteCooldownFile), true) ?? [];
        }
        return [];
    }

    protected function saveVoteCooldown(array $cooldowns): void
    {
        file_put_contents($this->voteCooldownFile, json_encode($cooldowns, JSON_PRETTY_PRINT));
    }

    protected function loadPlayerHistory(): array
    {
        if (file_exists($this->playerHistoryFile)) {
            return json_decode(file_get_contents($this->playerHistoryFile), true) ?? [];
        }
        return [];
    }

    protected function savePlayerHistory(array $history): void
    {
        file_put_contents($this->playerHistoryFile, json_encode($history, JSON_PRETTY_PRINT));
    }

    protected function loadRequestItemCooldown(): array
    {
        if (file_exists($this->requestItemCooldownFile)) {
            return json_decode(file_get_contents($this->requestItemCooldownFile), true) ?? [];
        }
        return [];
    }

    protected function saveRequestItemCooldown(array $cooldowns): void
    {
        file_put_contents($this->requestItemCooldownFile, json_encode($cooldowns, JSON_PRETTY_PRINT));
    }
}
