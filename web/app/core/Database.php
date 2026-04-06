<?php

namespace App\Core;

use PDO;
use PDOException;

class DatabaseException extends \RuntimeException {}
class ConnectionException extends DatabaseException {}
class QueryException extends DatabaseException {}
class ConstraintException extends QueryException {}

class Database
{
    private static $instance = null;
    private $pdo = null;
    private $dbPath;
    private $initialized = false;
    private const DB_VERSION = 2;

    private $transactionDepth = 0;
    private $transactionSavepoints = [];

    private $queryCount = 0;
    private $totalTime = 0.0;
    private $slowQueryCount = 0;
    private $slowQueryThreshold = 100;
    private $slowQueryLog = [];
    private $enableSlowQueryLog = false;

    private $lastConnectTime = 0;
    private const CONNECT_TIMEOUT = 300;

    private function __construct()
    {
        $this->dbPath = dirname(__DIR__, 3) . '/data/factorio.db';
        $this->ensureDataDir();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function ensureDataDir(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function cleanupStaleWALFiles(): void
    {
        $shmFile = $this->dbPath . '-shm';
        $walFile = $this->dbPath . '-wal';

        if (!file_exists($shmFile) && !file_exists($walFile)) {
            return;
        }

        $maxAge = 60;
        $now = time();
        $shmAge = file_exists($shmFile) ? ($now - filemtime($shmFile)) : 0;
        $walAge = file_exists($walFile) ? ($now - filemtime($walFile)) : 0;

        if ($shmAge < $maxAge && $walAge < $maxAge) {
            return;
        }

        $removedFiles = [];
        if (file_exists($shmFile) && $shmAge >= $maxAge) {
            if (@unlink($shmFile)) {
                $removedFiles[] = basename($shmFile);
            }
        }
        if (file_exists($walFile) && $walAge >= $maxAge) {
            if (@unlink($walFile)) {
                $removedFiles[] = basename($walFile);
            }
        }

        if (!empty($removedFiles)) {
            error_log("[Database] 已清理陈旧WAL文件(" . max($shmAge, $walAge) . "秒前): " . implode(', ', $removedFiles));
        }
    }

    private function createConnection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_PERSISTENT, false);

            // ===== SQLite 性能优化 PRAGMA 设置 =====
            // 容错处理：如果数据库是只读的，这些 PRAGMA 可能会失败

            $pragmas = [
                'journal_mode = DELETE',
                'synchronous = NORMAL',
                'cache_size = -20000',
                'temp_store = MEMORY',
                'busy_timeout = 5000',
                'foreign_keys = ON'
            ];

            foreach ($pragmas as $pragma) {
                try {
                    $this->pdo->exec("PRAGMA $pragma");
                } catch (PDOException $e) {
                    error_log("PRAGMA $pragma 执行失败（可能是只读数据库）: " . $e->getMessage());
                }
            }
            // ===== PRAGMA 设置结束 =====
        } catch (PDOException $e) {
            throw new ConnectionException('数据库连接失败: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    private function connectWithRetry(int $maxRetries = 3): void
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                $attempt++;
                $this->createConnection();
                return;
            } catch (ConnectionException $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    $sleepTime = pow(2, $attempt);
                    sleep($sleepTime);
                    error_log("[Database] 连接失败，第 {$attempt} 次重试，等待 {$sleepTime}s...");
                }
            }
        }

        throw new ConnectionException(
            "数据库连接失败，已重试 {$maxRetries} 次: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    public function initialize(): bool
    {
        if ($this->initialized) {
            return true;
        }

        try {
            $this->cleanupStaleWALFiles();
            $this->createConnection();
            $this->createTables();

            $currentVersion = $this->getVersion();
            if ($currentVersion < self::DB_VERSION) {
                $this->migrateToV2($currentVersion);
            }

            $this->initVipLevelsConfig();
            $this->updateVersion();
        } catch (\Exception $e) {
            error_log("Database 初始化警告（可能是只读数据库）: " . $e->getMessage());
        }

        $this->initialized = true;
        return true;
    }

    private function migrateToV2(int $fromVersion): void
    {
        $this->createTablesV2();
        $this->alterExistingTables();
        $this->createCartTable();
    }

    private function createCartTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS shopping_cart (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                item_code TEXT NOT NULL,
                item_name TEXT NOT NULL,
                quantity INTEGER DEFAULT 1,
                quality INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT (strftime('%s','now')),
                updated_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(user_id, item_code)
            );
            CREATE INDEX IF NOT EXISTS idx_cart_user ON shopping_cart(user_id);
        ";
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("创建购物车表失败: " . $e->getMessage());
        }
    }

    private function createTables(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                password_hash TEXT,
                role TEXT DEFAULT 'user',
                name TEXT,
                game_id TEXT,
                binding_code TEXT,
                vip_level INTEGER DEFAULT 0,
                vip_expiry INTEGER,
                created_at INTEGER,
                updated_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS player_bindings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                player_name TEXT,
                verification_code TEXT,
                status TEXT DEFAULT 'pending',
                expires_at INTEGER,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS shop_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_name TEXT,
                item_code TEXT,
                price REAL,
                stock INTEGER DEFAULT -1,
                quality INTEGER DEFAULT 0,
                category TEXT,
                is_active INTEGER DEFAULT 1,
                created_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                item_id INTEGER,
                player_name TEXT,
                quantity INTEGER,
                status TEXT DEFAULT 'pending',
                total_price REAL,
                created_at INTEGER,
                delivered_at INTEGER,
                FOREIGN KEY(user_id) REFERENCES users(id),
                FOREIGN KEY(item_id) REFERENCES shop_items(id)
            );

            CREATE TABLE IF NOT EXISTS item_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                item_name TEXT,
                count INTEGER,
                status TEXT DEFAULT 'pending',
                cooldown_until INTEGER,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS items (
                item_code TEXT PRIMARY KEY,
                item_name TEXT NOT NULL,
                category TEXT NOT NULL,
                is_enabled INTEGER DEFAULT 1,
                last_sync_at INTEGER,
                created_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS db_meta (
                key TEXT PRIMARY KEY,
                value TEXT
            );
        ";

        $this->pdo->exec($sql);
    }

    private function createTablesV2(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS player_histories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                config_name TEXT NOT NULL DEFAULT 'default',
                player_name TEXT NOT NULL,
                first_join_time INTEGER,
                last_join_time INTEGER,
                join_count INTEGER DEFAULT 1,
                last_leave_time INTEGER,
                ip TEXT,
                created_at INTEGER,
                updated_at INTEGER,
                UNIQUE(config_name, player_name)
            );
            CREATE INDEX IF NOT EXISTS idx_ph_config ON player_histories(config_name);
            CREATE INDEX IF NOT EXISTS idx_ph_player ON player_histories(player_name);
            // 优化高频查询：按 last_join_time 排序查询最近玩家
            CREATE INDEX IF NOT EXISTS idx_ph_last_join ON player_histories(last_join_time DESC);

            CREATE TABLE IF NOT EXISTS chat_scheduled_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id TEXT UNIQUE,
                message TEXT NOT NULL,
                schedule_type TEXT NOT NULL,
                scheduled_time TEXT,
                is_enabled INTEGER DEFAULT 1,
                created_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS chat_trigger_responses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_id TEXT UNIQUE,
                keyword TEXT NOT NULL,
                response TEXT NOT NULL,
                is_enabled INTEGER DEFAULT 1,
                created_at INTEGER
            );
            CREATE INDEX IF NOT EXISTS idx_ctr_keyword ON chat_trigger_responses(keyword);

            CREATE TABLE IF NOT EXISTS chat_server_responses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                keyword TEXT NOT NULL,
                value TEXT NOT NULL,
                created_at INTEGER,
                UNIQUE(type, keyword)
            );

            CREATE TABLE IF NOT EXISTS chat_player_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                is_enabled INTEGER DEFAULT 0,
                message TEXT DEFAULT '',
                items TEXT DEFAULT '',
                msg_type TEXT DEFAULT 'public',
                updated_at INTEGER,
                UNIQUE(event_type)
            );

            CREATE TABLE IF NOT EXISTS votes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                is_active INTEGER DEFAULT 0,
                initiator TEXT,
                target TEXT NOT NULL,
                required_votes INTEGER DEFAULT 3,
                start_time INTEGER,
                created_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS vote_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vote_id INTEGER NOT NULL,
                player_name TEXT NOT NULL,
                vote_bool INTEGER NOT NULL,
                voted_at INTEGER,
                FOREIGN KEY(vote_id) REFERENCES votes(id),
                UNIQUE(vote_id, player_name)
            );

            CREATE TABLE IF NOT EXISTS vote_cooldowns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                player_name TEXT NOT NULL UNIQUE,
                cooldown_until INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS auto_responder_states (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL UNIQUE,
                value TEXT NOT NULL,
                updated_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS message_queues (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT NOT NULL,
                priority INTEGER DEFAULT 0,
                scheduled_at INTEGER,
                is_sent INTEGER DEFAULT 0,
                sent_at INTEGER,
                created_at INTEGER
            );
            CREATE INDEX IF NOT EXISTS idx_mq_sent ON message_queues(is_sent);
            CREATE INDEX IF NOT EXISTS idx_mq_scheduled ON message_queues(scheduled_at);

            CREATE TABLE IF NOT EXISTS log_stream_states (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id TEXT NOT NULL UNIQUE,
                position INTEGER DEFAULT 0,
                last_activity INTEGER,
                created_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS vip_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                old_level INTEGER,
                new_level INTEGER NOT NULL,
                reason TEXT,
                operated_by INTEGER,
                created_at INTEGER,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );
            CREATE INDEX IF NOT EXISTS idx_vr_user ON vip_records(user_id);
            CREATE INDEX IF NOT EXISTS idx_vr_time ON vip_records(created_at);

            CREATE TABLE IF NOT EXISTS vip_levels_config (
                level INTEGER PRIMARY KEY,
                name TEXT NOT NULL DEFAULT '',
                discount REAL DEFAULT 0,
                daily_limit INTEGER DEFAULT 5,
                max_quantity INTEGER DEFAULT 10,
                max_quality INTEGER DEFAULT 0,
                color TEXT DEFAULT '#6b7280',
                description TEXT DEFAULT '',
                is_enabled INTEGER DEFAULT 1,
                created_at INTEGER DEFAULT (strftime('%s','now')),
                updated_at INTEGER DEFAULT (strftime('%s','now'))
            );

            // 优化订单查询：按用户和状态查询
            CREATE INDEX IF NOT EXISTS idx_orders_user_status ON orders(user_id, status);
            // 优化订单查询：按创建时间降序排序
            CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at DESC);
        ";

        $this->pdo->exec($sql);
    }

    private function alterExistingTables(): void
    {
        $alterSqls = [
            "ALTER TABLE orders ADD COLUMN discount_rate REAL DEFAULT 0",
            "ALTER TABLE orders ADD COLUMN original_price REAL",
            "ALTER TABLE orders ADD COLUMN rcon_command TEXT",
            "ALTER TABLE orders ADD COLUMN error_message TEXT",
            "ALTER TABLE orders ADD COLUMN admin_id INTEGER"
        ];

        foreach ($alterSqls as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'duplicate column name') === false) {
                    throw $e;
                }
            }
        }

        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip TEXT");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }

        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN login_count INTEGER DEFAULT 0");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }

        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN game_id TEXT");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }

        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN binding_code TEXT");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }
    }

    private function initVipLevelsConfig(): void
    {
        $existing = $this->query('SELECT COUNT(*) as cnt FROM vip_levels_config');
        if (!empty($existing) && (int)$existing[0]['cnt'] > 0) {
            return;
        }

        $defaultData = [
            [0, '普通', 0, 5, 10, 0, '#6b7280', '默认用户等级'],
            [1, '青铜', 0.05, 10, 20, 1, '#cd7f32', '初级VIP会员'],
            [2, '白银', 0.10, 15, 30, 2, '#c0c0c0', '中级VIP会员'],
            [3, '黄金', 0.15, 20, 50, 3, '#f59e0b', '高级VIP会员'],
            [4, '钻石', 0.20, 30, 100, 4, '#667eea', '顶级VIP会员']
        ];

        foreach ($defaultData as $row) {
            try {
                $this->execute(
                    'INSERT INTO vip_levels_config (level, name, discount, daily_limit, max_quantity, max_quality, color, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    $row
                );
            } catch (\Exception $e) {
                error_log("初始化 VIP 配置失败 (level={$row[0]}): " . $e->getMessage());
            }
        }
    }

    private function updateVersion(): void
    {
        $stmt = $this->pdo->prepare('SELECT value FROM db_meta WHERE key = :key');
        $stmt->execute([':key' => 'version']);
        $row = $stmt->fetch();

        if ($row === false || (int)$row['value'] < self::DB_VERSION) {
            $this->pdo->exec(
                "INSERT OR REPLACE INTO db_meta (key, value) VALUES ('version', '" . self::DB_VERSION . "')"
            );
        }
    }

    public function getConnection(): PDO
    {
        $now = time();
        if (($now - $this->lastConnectTime) > self::CONNECT_TIMEOUT && $this->pdo !== null) {
            try {
                $this->pdo->query('SELECT 1');
            } catch (PDOException $e) {
                $this->pdo = null;
            }
        }

        if ($this->pdo === null) {
            $this->connectWithRetry();
            $this->lastConnectTime = $now;
        }

        return $this->pdo;
    }

    public function query(string $sql, array $params = []): array
    {
        $startTime = microtime(true);

        try {
            $pdo = $this->getConnection();
            $stmt = $this->executeWithRetry($sql, $params);
            $result = $stmt->fetchAll();

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->recordQueryStats($sql, $elapsed);

            return $result;
        } catch (PDOException $e) {
            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->recordQueryStats($sql, $elapsed);

            $logMessage = sprintf(
                "[Database Error] SQL: %s | Params: %s | Code: %s | Message: %s | File: %s:%d",
                $sql,
                json_encode($params),
                $e->getCode(),
                $e->getMessage(),
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? 'unknown',
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0
            );
            error_log($logMessage);

            throw new QueryException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        $startTime = microtime(true);

        try {
            $stmt = $this->executeWithRetry($sql, $params);
            $affected = $stmt->rowCount();

            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->recordQueryStats($sql, $elapsed);

            return $affected;
        } catch (PDOException $e) {
            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->recordQueryStats($sql, $elapsed);

            $logMessage = sprintf(
                "[Database Error] SQL: %s | Params: %s | Code: %s | Message: %s | File: %s:%d",
                $sql,
                json_encode($params),
                $e->getCode(),
                $e->getMessage(),
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? 'unknown',
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0
            );
            error_log($logMessage);

            throw new QueryException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private function executeWithRetry(string $sql, array $params = [], int $maxRetries = 5)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                $pdo = $this->getConnection();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                if ($e->getCode() === 'HY000' && strpos($e->getMessage(), 'database is locked') !== false) {
                    $lastException = $e;
                    $attempt++;
                    usleep(100000);
                } else {
                    throw new QueryException(
                        "查询执行失败: " . $e->getMessage(),
                        (int)$e->getCode(),
                        $e
                    );
                }
            }
        }

        throw new QueryException(
            "数据库锁定，已重试 {$maxRetries} 次",
            0,
            $lastException
        );
    }

    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        if ($this->transactionDepth == 0) {
            $result = $this->getConnection()->beginTransaction();
        } else {
            $savepointName = 'sp' . $this->transactionDepth;
            $this->pdo->exec("SAVEPOINT $savepointName");
            $this->transactionSavepoints[] = $savepointName;
            $result = true;
        }
        $this->transactionDepth++;
        return $result;
    }

    public function commit(): bool
    {
        $this->transactionDepth--;

        if ($this->transactionDepth == 0) {
            return $this->getConnection()->commit();
        } else {
            $savepointName = array_pop($this->transactionSavepoints);
            $this->pdo->exec("RELEASE SAVEPOINT $savepointName");
            return true;
        }
    }

    public function rollBack(): bool
    {
        $this->transactionDepth--;

        if ($this->transactionDepth == 0) {
            return $this->getConnection()->rollBack();
        } else {
            $savepointName = array_pop($this->transactionSavepoints);
            $this->pdo->exec("ROLLBACK TO SAVEPOINT $savepointName");
            return true;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0 || $this->getConnection()->inTransaction();
    }

    public function transactional(callable $callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function batchInsert(string $table, array $rows, array $columns): int
    {
        if (empty($rows)) return 0;

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnNames = implode(', ', $columns);
        $sql = "INSERT INTO {$table} ({$columnNames}) VALUES ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $affected = 0;

        foreach ($rows as $row) {
            $values = array_map(function ($col) use ($row) { return $row[$col]; }, $columns);
            $stmt->execute($values);
            $affected += $stmt->rowCount();
        }

        return $affected;
    }

    public function batchUpdate(string $table, array $rows, string $idColumn = 'id'): int
    {
        if (empty($rows)) return 0;

        $affected = 0;
        foreach ($rows as $row) {
            $id = $row[$idColumn];
            unset($row[$idColumn]);

            $sets = [];
            $values = [];
            foreach ($row as $key => $value) {
                $sets[] = "{$key} = ?";
                $values[] = $value;
            }
            $values[] = $id;

            $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$idColumn} = ?";
            $affected += $this->execute($sql, $values);
        }

        return $affected;
    }

    public function enableSlowQueryLog(int $thresholdMs = 100): void
    {
        $this->enableSlowQueryLog = true;
        $this->slowQueryThreshold = $thresholdMs;
    }

    public function getQueryStats(): array
    {
        return [
            'query_count' => $this->queryCount,
            'total_time_ms' => round($this->totalTime, 2),
            'avg_time_ms' => $this->queryCount > 0 ? round($this->totalTime / $this->queryCount, 2) : 0,
            'slow_query_count' => $this->slowQueryCount,
            'slow_query_threshold_ms' => $this->slowQueryThreshold,
        ];
    }

    public function getSlowQueries(): array
    {
        return $this->slowQueryLog;
    }

    public function resetStats(): void
    {
        $this->queryCount = 0;
        $this->totalTime = 0.0;
        $this->slowQueryCount = 0;
        $this->slowQueryLog = [];
    }

    private function recordQueryStats(string $sql, float $elapsedMs): void
    {
        $this->queryCount++;
        $this->totalTime += $elapsedMs;

        if ($this->enableSlowQueryLog && $elapsedMs > $this->slowQueryThreshold) {
            $this->slowQueryCount++;
            $this->slowQueryLog[] = [
                'time' => date('Y-m-d H:i:s'),
                'duration_ms' => round($elapsedMs, 2),
                'sql' => $sql,
            ];
        }
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    public function getVersion(): int
    {
        $result = $this->query('SELECT value FROM db_meta WHERE key = :key', [':key' => 'version']);
        return isset($result[0]) ? (int)$result[0]['value'] : 0;
    }

    /**
     * 分析查询执行计划（仅开发环境使用）
     *
     * @param string $sql SQL 查询语句
     * @param array $params 查询参数
     * @return array 执行计划详情
     */
    public function explainQuery(string $sql, array $params = []): array
    {
        $explainSql = "EXPLAIN QUERY PLAN " . $sql;

        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->prepare($explainSql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [['error' => $e->getMessage()]];
        }
    }

    /**
     * 检查查询是否使用了索引
     *
     * @param string $sql SQL 查询语句
     * @param array $params 查询参数
     * @return bool 是否使用了索引
     */
    public function queryUsesIndex(string $sql, array $params = []): bool
    {
        $plan = $this->explainQuery($sql, $params);

        foreach ($plan as $row) {
            $detail = $row['detail'] ?? '';
            // 如果包含 "SCAN" 但不是 "SCAN TABLE ... USING INDEX"，则是全表扫描
            if (strpos($detail, 'SCAN TABLE') !== false && strpos($detail, 'USING INDEX') === false) {
                return false; // 全表扫描
            }
        }

        return true; // 使用了索引或无法确定
    }
}
