<?php

namespace App\Services;

use App\Core\Database;

class StateService
{
    private string $configDir;
    private array $cache = [];
    private ?Database $db = null;
    private bool $useDatabase = true;
    private array $tableMap = [
        'playerHistory' => ['type' => 'player_histories', 'handler' => 'handlePlayerHistory'],
        'chatSettings' => ['type' => 'chat_settings', 'handler' => 'handleChatSettings'],
        'voteState' => ['type' => 'votes', 'handler' => 'handleVoteState'],
        'voteCooldown' => ['type' => 'vote_cooldowns', 'handler' => 'handleVoteCooldown'],
        'itemRequestConfirm' => ['type' => 'item_requests_confirm', 'handler' => 'handleItemRequestConfirm'],
        'requestItemCooldown' => ['type' => 'item_requests_cooldown', 'handler' => 'handleRequestItemCooldown'],
        'autoResponderState' => ['type' => 'auto_responder_states', 'handler' => 'handleAutoResponderState'],
        'messageQueue' => ['type' => 'message_queues', 'handler' => 'handleMessageQueue'],
        'logStreamState' => ['type' => 'log_stream_states', 'handler' => 'handleLogStreamState']
    ];

    public function __construct(string $configDir = null, Database $db = null)
    {
        $this->configDir = $configDir ?? dirname(__DIR__, 2) . '/config';
        
        try {
            if ($db) {
                $this->db = $db;
            } else {
                $this->db = Database::getInstance();
                if (!$this->db->getConnection()) {
                    throw new \Exception('数据库连接失败');
                }
            }
        } catch (\Exception $e) {
            error_log("StateService: 数据库不可用，降级为文件模式 - " . $e->getMessage());
            $this->useDatabase = false;
        }
    }

    public function saveState(string $name, array $state): bool
    {
        if ($this->useDatabase && isset($this->tableMap[$name])) {
            return $this->saveToDatabase($name, $state);
        }
        
        return $this->saveToFile($name, $state);
    }

    public function loadState(string $name): array
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        if ($this->useDatabase && isset($this->tableMap[$name])) {
            $data = $this->loadFromDatabase($name);
            
            if (!empty($data)) {
                $this->cache[$name] = $data;
                return $data;
            }
        }

        return $this->loadFromFile($name);
    }

    public function getStateValue(string $name, string $key, $default = null)
    {
        $state = $this->loadState($name);
        return $state[$key] ?? $default;
    }

    public function setStateValue(string $name, string $key, $value): bool
    {
        $state = $this->loadState($name);
        $state[$key] = $value;
        return $this->saveState($name, $state);
    }

    public function deleteState(string $name): bool
    {
        unset($this->cache[$name]);

        if ($this->useDatabase && isset($this->tableMap[$name])) {
            return $this->deleteFromDatabase($name);
        }

        $file = $this->getStateFile($name);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    public function clearCache(string $name = null): void
    {
        if ($name) {
            unset($this->cache[$name]);
        } else {
            $this->cache = [];
        }
    }

    public function isUsingDatabase(): bool
    {
        return $this->useDatabase;
    }

    private function saveToDatabase(string $name, array $state): bool
    {
        try {
            $handler = $this->tableMap[$name]['handler'] ?? null;
            if ($handler && method_exists($this, $handler)) {
                return $this->$handler($name, $state, 'save');
            }

            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return $this->db->execute(
                "INSERT OR REPLACE INTO auto_responder_states (key, value, updated_at) VALUES (:name, :value, :updated)",
                [':name' => $name, ':value' => $json, ':updated' => time()]
            ) > 0;
        } catch (\Exception $e) {
            error_log("StateService 数据库写入失败 [{$name}]: " . $e->getMessage());
            return $this->saveToFile($name, $state);
        }
    }

    private function loadFromDatabase(string $name): array
    {
        try {
            $handler = $this->tableMap[$name]['handler'] ?? null;
            if ($handler && method_exists($this, $handler)) {
                return $this->$handler($name, [], 'load');
            }

            $result = $this->db->query(
                "SELECT value FROM auto_responder_states WHERE key = :name",
                [':name' => $name]
            );

            if (!empty($result)) {
                return json_decode($result[0]['value'], true) ?? [];
            }

            return [];
        } catch (\Exception $e) {
            error_log("StateService 数据库读取失败 [{$name}]: " . $e->getMessage());
            return [];
        }
    }

    private function deleteFromDatabase(string $name): bool
    {
        try {
            $handler = $this->tableMap[$name]['handler'] ?? null;
            if ($handler && method_exists($this, $handler)) {
                return $this->$handler($name, [], 'delete');
            }

            return $this->db->execute(
                "DELETE FROM auto_responder_states WHERE key = :name",
                [':name' => $name]
            ) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function handlePlayerHistory(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            foreach ($data as $player => $info) {
                $exists = $this->db->query("SELECT id FROM player_histories WHERE player_name = :player", [':player' => $player]);
                if (empty($exists)) {
                    $this->db->execute(
                        "INSERT INTO player_histories (player_name, first_join_time, last_join_time, join_count, created_at, updated_at) VALUES (:player, :firstJoin, :lastJoin, :count, :created, :updated)",
                        [':player' => $player, ':firstJoin' => $info['firstJoin'] ?? time(), ':lastJoin' => $info['lastJoin'] ?? time(), ':count' => $info['joinCount'] ?? 1, ':created' => time(), ':updated' => time()]
                    );
                } else {
                    $this->db->execute(
                        "UPDATE player_histories SET last_join_time = :lastJoin, join_count = :count, updated_at = :updated WHERE player_name = :player",
                        [':lastJoin' => $info['lastJoin'] ?? time(), ':count' => $info['joinCount'] ?? 1, ':updated' => time(), ':player' => $player]
                    );
                }
            }
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("DELETE FROM player_histories");
            return [];
        }

        $rows = $this->db->query("SELECT * FROM player_histories");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['player_name']] = [
                'firstJoin' => $row['first_join_time'],
                'lastJoin' => $row['last_join_time'],
                'joinCount' => $row['join_count']
            ];
        }
        return $result;
    }

    private function handleVoteState(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            if (!empty($data) && isset($data['active']) && $data['active']) {
                $this->db->execute("UPDATE votes SET is_active = 0");
                $this->db->execute(
                    "INSERT INTO votes (is_active, initiator, target, required_votes, start_time, created_at) VALUES (1, :initiator, :target, :required, :start, :created)",
                    [':initiator' => $data['initiator'] ?? '', ':target' => $data['target'] ?? '', ':required' => $data['required'] ?? 3, ':start' => $data['startTime'] ?? time(), ':created' => time()]
                );
                
                if (!empty($data['votes'])) {
                    $voteId = $this->db->lastInsertId();
                    foreach ($data['votes'] as $player => $voted) {
                        $this->db->execute(
                            "INSERT OR IGNORE INTO vote_records (vote_id, player_name, vote_bool, voted_at) VALUES (:voteId, :player, :voteBool, :votedAt)",
                            [':voteId' => $voteId, ':player' => $player, ':voteBool' => $voted ? 1 : 0, ':votedAt' => time()]
                        );
                    }
                }
            }
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("DELETE FROM vote_records");
            $this->db->execute("DELETE FROM votes");
            return [];
        }

        $votes = $this->db->query("SELECT * FROM votes WHERE is_active = 1 LIMIT 1");
        if (empty($votes)) {
            return ['active' => false];
        }

        $vote = $votes[0];
        $records = $this->db->query("SELECT player_name, vote_bool FROM vote_records WHERE vote_id = :id", [':id' => $vote['id']]);
        $voteData = [
            'active' => true,
            'initiator' => $vote['initiator'],
            'target' => $vote['target'],
            'required' => $vote['required_votes'],
            'startTime' => $vote['start_time'],
            'votes' => []
        ];
        foreach ($records as $record) {
            $voteData['votes'][$record['player_name']] = (bool)$record['vote_bool'];
        }
        return $voteData;
    }

    private function handleVoteCooldown(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            foreach ($data as $player => $until) {
                $this->db->execute(
                    "INSERT OR REPLACE INTO vote_cooldowns (player_name, cooldown_until) VALUES (:player, :until)",
                    [':player' => $player, ':until' => $until]
                );
            }
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("DELETE FROM vote_cooldowns");
            return [];
        }

        $rows = $this->db->query("SELECT player_name, cooldown_until FROM vote_cooldowns");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['player_name']] = (int)$row['cooldown_until'];
        }
        return $result;
    }

    private function handleItemRequestConfirm(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            foreach ($data as $player => $confirm) {
                $this->db->execute(
                    "INSERT OR REPLACE INTO item_requests (user_id, item_name, count, status, created_at) VALUES ((SELECT id FROM users WHERE username = :player), :itemName, :count, 'confirm', :created)",
                    [':player' => $player, ':itemName' => $confirm['itemName'] ?? '', ':count' => $confirm['count'] ?? 1, ':created' => time()]
                );
            }
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("UPDATE item_requests SET status = 'cancelled' WHERE status = 'confirm'");
            return [];
        }

        $rows = $this->db->query(
            "SELECT u.username as player, ir.item_name as itemName, ir.count, ir.created_at as timestamp FROM item_requests ir JOIN users u ON u.id = ir.user_id WHERE ir.status = 'confirm'"
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['player']] = ['itemName' => $row['itemName'], 'count' => (int)$row['count'], 'timestamp' => (int)$row['timestamp']];
        }
        return $result;
    }

    private function handleRequestItemCooldown(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            foreach ($data as $player => $until) {
                $this->db->execute(
                    "INSERT OR REPLACE INTO item_requests (user_id, item_name, count, status, cooldown_until) VALUES ((SELECT id FROM users WHERE username = :player), '', 0, 'cooldown', :until)",
                    [':player' => $player, ':until' => $until]
                );
            }
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("UPDATE item_requests SET status = 'expired' WHERE status = 'cooldown'");
            return [];
        }

        $rows = $this->db->query(
            "SELECT u.username as player, MAX(ir.cooldown_until) as until FROM item_requests ir JOIN users u ON u.id = ir.user_id WHERE ir.status = 'cooldown' AND ir.cooldown_until > :now GROUP BY u.username",
            [':now' => time()]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['player']] = (int)$row['until'];
        }
        return $result;
    }

    private function handleAutoResponderState(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->db->execute(
                "INSERT OR REPLACE INTO auto_responder_states (key, value, updated_at) VALUES (:name, :value, :updated)",
                [':name' => $name, ':value' => $json, ':updated' => time()]
            );
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("DELETE FROM auto_responder_states WHERE key = :name", [':name' => $name]);
            return [];
        }

        $result = $this->db->query("SELECT value FROM auto_responder_states WHERE key = :name", [':name' => $name]);
        return !empty($result) ? (json_decode($result[0]['value'], true) ?? []) : [];
    }

    private function handleMessageQueue(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                foreach ($data as $msg) {
                    $this->db->execute(
                        "INSERT INTO message_queues (message, priority, scheduled_at, is_sent, created_at) VALUES (:msg, :priority, :scheduled, 0, :created)",
                        [':msg' => $msg['message'] ?? '', ':priority' => $msg['priority'] ?? 0, ':scheduled' => $msg['scheduledAt'] ?? null, ':created' => time()]
                    );
                }
            }
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("DELETE FROM message_queues");
            return [];
        }

        $rows = $this->db->query("SELECT * FROM message_queues ORDER BY created_at ASC");
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'message' => $row['message'],
                'priority' => (int)$row['priority'],
                'scheduledAt' => $row['scheduled_at'],
                'isSent' => (bool)$row['is_sent'],
                'sentAt' => $row['sent_at']
            ];
        }
        return $result;
    }

    private function handleLogStreamState(string $name, $data = [], string $action = 'load')
    {
        if ($action === 'save') {
            foreach ($data as $clientId => $pos) {
                $this->db->execute(
                    "INSERT OR REPLACE INTO log_stream_states (client_id, position, last_activity, created_at) VALUES (:clientId, :position, :activity, :created)",
                    [':clientId' => $clientId, ':position' => (int)$pos, ':activity' => time(), ':created' => time()]
                );
            }
            return [];
        }

        if ($action === 'delete') {
            $this->db->execute("DELETE FROM log_stream_states");
            return [];
        }

        $rows = $this->db->query("SELECT client_id, position FROM log_stream_states");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['client_id']] = (int)$row['position'];
        }
        return $result;
    }

    private function saveToFile(string $name, array $state): bool
    {
        error_log("[StateService] 警告：正在将数据保存到已归档的 JSON 文件（{$name}），建议检查数据库是否正常");

        $file = $this->getStateFile($name);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $result = file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->cache[$name] = $state;
        return $result !== false;
    }

    private function loadFromFile(string $name): array
    {
        $file = $this->getStateFile($name);
        if (!file_exists($file)) {
            return [];
        }

        error_log("[StateService] 警告：正在从已归档的 JSON 文件加载数据（{$name}），建议检查数据库是否正常。文件路径: {$file}");

        $content = @file_get_contents($file);
        $this->cache[$name] = json_decode($content, true) ?? [];
        return $this->cache[$name];
    }

    private function getStateFile(string $name): string
    {
        return $this->configDir . '/state/' . $name . '.json';
    }
}
