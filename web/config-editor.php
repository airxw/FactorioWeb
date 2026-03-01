<?php
/**
 * Factorio Server Pro - 配置编辑器
 * 
 * 支持创建新配置和编辑已有配置
 * 敏感信息从 config.php 读取，不在配置文件中存储
 */

require_once __DIR__ . '/auth.php';

requireLogin();

// 配置文件存放目录
$serverDir = __DIR__ . '/../server/configs';

if (!is_dir($serverDir)) {
    mkdir($serverDir, 0755, true);
}

// 加载JSON配置文件
function loadJsonConfig($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

// 加载敏感信息（从config.php读取）
function loadSecrets() {
    $config = loadConfig();
    return $config['factorio_secrets'] ?? [
        'username' => '',
        'password' => '',
        'token' => '',
    ];
}

// 获取配置文件列表
function getConfigFiles($dir) {
    $files = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') as $file) {
            $files[] = basename($file, '.json');
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

// 保存配置文件
function saveConfigFile($dir, $filename, $data) {
    $safeFilename = basename($filename);
    $path = $dir . '/' . $safeFilename . '.json';
    $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $jsonContent);
}

// 删除配置文件
function deleteConfigFile($dir, $filename) {
    $safeFilename = basename($filename);
    $path = $dir . '/' . $safeFilename . '.json';
    if (file_exists($path)) {
        return unlink($path);
    }
    return false;
}

$message = '';
$secrets = loadSecrets();
$configFiles = getConfigFiles($serverDir);

// 当前编辑的配置文件
$editFile = trim($_GET['file'] ?? '');
$config = [];

// 如果指定了文件，加载该配置
if (!empty($editFile)) {
    $editFile = preg_replace('/\.json$/i', '', $editFile);
    $configPath = $serverDir . '/' . $editFile . '.json';
    if (file_exists($configPath)) {
        $config = loadJsonConfig($configPath);
    } else {
        $message = "❌ 配置文件不存在：$editFile";
        $editFile = '';
    }
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $rawFilename = trim($_POST['config_filename'] ?? '');
    
    if ($action === 'delete' && !empty($rawFilename)) {
        // 删除配置
        if (deleteConfigFile($serverDir, $rawFilename)) {
            $message = "✅ 配置文件已删除：<strong>$rawFilename</strong>";
            $configFiles = getConfigFiles($serverDir);
            $editFile = '';
            $config = [];
        } else {
            $message = "❌ 删除失败！";
        }
    } elseif (!empty($rawFilename)) {
        // 保存配置
        $rawFilename = preg_replace('/\.json$/i', '', $rawFilename);
        
        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]+$/u', $rawFilename)) {
            $message = "❌ 文件名只能包含字母、数字、汉字、下划线或中划线！";
        } elseif (mb_strlen($rawFilename, 'UTF-8') > 20) {
            $message = "❌ 文件名不能超过 20 个字符！";
        } else {
            $truncate = function($str, $max = 50) {
                return mb_substr((string)$str, 0, $max, 'UTF-8');
            };

            // 构建配置数据（敏感信息从 config.php 读取）
            $name = trim($_POST['name'] ?? '');
            $name = preg_replace('/^\[ieac\]\s*/i', '', $name);
            $description = trim($_POST['description'] ?? '');
            $description = preg_replace('/^\[QQ群:1137842268\]\s*/i', '', $description);
            
            $data = [
                'name' => $truncate('[ieac] ' . $name),
                'description' => $truncate('[QQ群:1137842268] ' . $description),

                'tags' => isset($_POST['tags']) ? array_map('trim', explode(',', $_POST['tags'])) : [],
                
                'max_players' => (int)($_POST['max_players'] ?? 0),
                'ignore_player_limit_for_returning_players' => !empty($_POST['ignore_player_limit_for_returning_players']),
                'require_user_verification' => !empty($_POST['require_user_verification']),

                'visibility' => [
                    'public' => !empty($_POST['visibility_public']),
                    'lan' => !empty($_POST['visibility_lan'])
                ],

                // 敏感信息：username/password/token 从 config.php 读取，game_password 允许前端编辑
                'username' => $secrets['username'],
                'password' => $secrets['password'],
                'token' => $secrets['token'],
                'game_password' => $truncate($_POST['game_password'] ?? ''),

                'max_upload_in_kilobytes_per_second' => (int)($_POST['max_upload_in_kilobytes_per_second'] ?? 0),
                'max_upload_slots' => (int)($_POST['max_upload_slots'] ?? 5),
                'minimum_latency_in_ticks' => (int)($_POST['minimum_latency_in_ticks'] ?? 0),
                'max_heartbeats_per_second' => (int)($_POST['max_heartbeats_per_second'] ?? 60),

                'allow_commands' => $_POST['allow_commands'] ?? 'admins-only',
                'only_admins_can_pause_the_game' => !empty($_POST['only_admins_can_pause_the_game']),

                'autosave_interval' => (int)($_POST['autosave_interval'] ?? 10),
                'autosave_slots' => (int)($_POST['autosave_slots'] ?? 5),
                'autosave_only_on_server' => !empty($_POST['autosave_only_on_server']),
                'non_blocking_saving' => !empty($_POST['non_blocking_saving']),

                'afk_autokick_interval' => (int)($_POST['afk_autokick_interval'] ?? 0),
                'auto_pause' => !empty($_POST['auto_pause']),
                'auto_pause_when_players_connect' => !empty($_POST['auto_pause_when_players_connect']),

                'minimum_segment_size' => (int)($_POST['minimum_segment_size'] ?? 25),
                'minimum_segment_size_peer_count' => (int)($_POST['minimum_segment_size_peer_count'] ?? 20),
                'maximum_segment_size' => (int)($_POST['maximum_segment_size'] ?? 100),
                'maximum_segment_size_peer_count' => (int)($_POST['maximum_segment_size_peer_count'] ?? 10),
            ];

            if (saveConfigFile($serverDir, $rawFilename, $data) !== false) {
                $message = "✅ 配置已保存为：<strong>$rawFilename.json</strong>";
                $configFiles = getConfigFiles($serverDir);
                // 无论是保存还是另存为，都更新当前编辑的文件为新文件名
                $editFile = $rawFilename;
                $config = $data;
            } else {
                $message = "❌ 保存失败！请检查目录权限。";
            }
        }
    } else {
        $message = "❌ 配置文件名不能为空！";
    }
}

// 从配置中提取显示值（去除前缀）
$displayName = isset($config['name']) ? preg_replace('/^\[ieac\]\s*/i', '', $config['name']) : '';
$displayDesc = isset($config['description']) ? preg_replace('/^\[QQ群:1137842268\]\s*/i', '', $config['description']) : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factorio 服务器配置编辑器 - [ieac]</title>
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #45a049;
            --danger: #dc3545;
            --danger-dark: #c82333;
            --border: #ddd;
            --bg: #fafafa;
            --text: #333;
            --section-bg: #fff;
            --success: #d4edda;
            --error: #f8d7da;
            --success-text: #155724;
            --error-text: #721c24;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Microsoft YaHei", "PingFang SC", "Segoe UI", sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
            padding: 20px;
        }
        .container { max-width: 960px; margin: 0 auto; }
        header { text-align: center; margin-bottom: 30px; }
        h1 { color: var(--primary); font-size: 28px; margin-bottom: 8px; }
        .subtitle { color: #777; font-size: 14px; }
        .section {
            background: var(--section-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }
        .section h2 {
            margin-top: 0;
            color: #2c3e50;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        .form-group { margin: 16px 0; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; }
        input[type="text"],
        input[type="number"],
        input[type="password"],
        select,
        textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15);
        }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input[type="checkbox"] { width: auto; margin: 0; }
        .checkbox-group label { font-weight: normal; margin: 0; font-size: 14px; cursor: pointer; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; }
        button {
            flex: 1;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            transition: background 0.2s;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: var(--danger-dark); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
        }
        .success { background: var(--success); color: var(--success-text); }
        .error { background: var(--error); color: var(--error-text); }
        .note { font-size: 12px; color: #888; margin-top: 5px; font-style: italic; }
        code { background: #f5f5f5; padding: 2px 4px; border-radius: 3px; font-family: monospace; }
        footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #888;
            font-size: 13px;
        }
        footer a { color: var(--primary); text-decoration: none; }
        footer a:hover { text-decoration: underline; }
        .file-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .file-item {
            padding: 8px 12px;
            background: #e9ecef;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--text);
        }
        .file-item:hover { background: #dee2e6; }
        .file-item.active { background: var(--primary); color: white; }
        .readonly-input {
            background-color: #e9ecef !important;
            cursor: not-allowed;
        }
        .admin-section {
            background: #f8f9fa;
            border: 1px dashed #adb5bd;
        }
        .admin-section h2 { color: var(--danger); }
        .nav-links { margin-bottom: 20px; }
        .nav-links a { color: var(--primary); text-decoration: none; font-size: 14px; }
        .nav-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="index.html">← 返回控制面板</a>
        </div>

        <header>
            <h1>Factorio 服务器配置编辑器</h1>
            <div class="subtitle">[ieac] 专用配置管理面板</div>
        </header>

        <?php if (!empty($message)): ?>
            <div class="message <?= strpos($message, '✅') !== false ? 'success' : 'error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <div class="section admin-section">
            <h2>🔐 管理员功能</h2>
            <p style="margin-bottom: 10px;">敏感信息（用户名、密码、令牌）已集中存储在 config.php 中。</p>
            <a href="secrets-manager.php" style="display: inline-block; padding: 8px 16px; background: var(--danger); color: white; text-decoration: none; border-radius: 4px; font-size: 14px;">
                管理敏感信息 →
            </a>
        </div>
        <?php endif; ?>

        <!-- 配置文件选择 -->
        <div class="section">
            <h2>📁 配置文件</h2>
            <div class="form-group">
                <label>选择已有配置编辑：</label>
                <div class="file-list">
                    <a href="?file=" class="file-item <?= empty($editFile) ? 'active' : '' ?>">+ 新建配置</a>
                    <?php foreach ($configFiles as $file): ?>
                        <a href="?file=<?= urlencode($file) ?>" class="file-item <?= $editFile === $file ? 'active' : '' ?>">
                            <?= htmlspecialchars($file) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="save">
            
            <!-- 配置文件名 -->
            <div class="section">
                <h2>💾 保存配置</h2>
                <div class="form-group">
                    <label for="config_filename">配置文件名（必填）</label>
                    <input type="text" id="config_filename" name="config_filename"
                           value="<?= htmlspecialchars($editFile) ?>"
                           placeholder="例如：生存服-中文-1" required>
                    <div class="note">仅允许字母、数字、汉字、下划线(_)、中划线(-)；最多 20 个字符</div>
                </div>
                <div class="button-group">
                    <button type="submit" name="action" value="save" class="btn-primary">💾 保存配置</button>
                    <button type="submit" name="action" value="saveas" class="btn-secondary">📋 另存为</button>
                    <button type="button" class="btn-secondary" onclick="location.href='index.html'">返回控制面板</button>
                    <?php if (!empty($editFile)): ?>
                    <button type="submit" name="action" value="delete" class="btn-danger" 
                            onclick="return confirm('确定要删除此配置吗？');">🗑️ 删除</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 基本信息 -->
            <div class="section">
                <h2>基本信息</h2>
                <div class="form-group">
                    <label for="name">服务器名称（自动添加 [ieac] 前缀）</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($displayName) ?>" placeholder="在此输入服务器名称">
                </div>
                <div class="form-group">
                    <label for="description">服务器描述（自动添加 [QQ群:1137842268] 前缀）</label>
                    <textarea id="description" name="description" rows="2"><?= htmlspecialchars($displayDesc) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="tags">标签（用英文逗号分隔）</label>
                    <input type="text" id="tags" name="tags" maxlength="50" value="<?= htmlspecialchars(implode(',', $config['tags'] ?? [])) ?>">
                </div>
            </div>

            <!-- 玩家设置 -->
            <div class="section">
                <h2>玩家设置</h2>
                <div class="form-group">
                    <label for="max_players">最大玩家数（0 表示无限制）</label>
                    <input type="number" id="max_players" name="max_players" value="<?= $config['max_players'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="ignore_player_limit_for_returning_players" name="ignore_player_limit_for_returning_players" <?= !empty($config['ignore_player_limit_for_returning_players']) ? 'checked' : '' ?>>
                    <label for="ignore_player_limit_for_returning_players">允许回头玩家忽略玩家限制</label>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="require_user_verification" name="require_user_verification" <?= !empty($config['require_user_verification']) ? 'checked' : '' ?>>
                    <label for="require_user_verification">要求用户验证（仅允许有有效 Factorio 账号的用户）</label>
                </div>
            </div>

            <!-- 可见性设置 -->
            <div class="section">
                <h2>可见性设置</h2>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="visibility_public" name="visibility_public" <?= !empty($config['visibility']['public']) ? 'checked' : '' ?>>
                    <label for="visibility_public">公开（在官方匹配服务器上发布）</label>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="visibility_lan" name="visibility_lan" <?= !empty($config['visibility']['lan']) ? 'checked' : '' ?>>
                    <label for="visibility_lan">局域网可见</label>
                </div>
            </div>

            <!-- 认证信息 -->
            <div class="section">
                <h2>认证信息</h2>
                <div class="note" style="margin-bottom: 15px; padding: 10px; background: #fff3cd; border-radius: 4px;">
                    Factorio.com 用户名从服务器配置读取，游戏密码可在此设置
                </div>
                <div class="form-group">
                    <label for="username">Factorio.com 用户名</label>
                    <input type="text" id="username" name="username" 
                           value="<?= !empty($secrets['username']) ? '********' : '(未设置)' ?>" 
                           readonly disabled class="readonly-input">
                    <div class="note">此信息由管理员在 config.php 中配置</div>
                </div>
                <div class="form-group">
                    <label for="game_password">游戏密码（留空表示无需密码）</label>
                    <input type="password" id="game_password" name="game_password" maxlength="50"
                           value="<?= htmlspecialchars($config['game_password'] ?? '') ?>">
                    <div class="note">玩家连接服务器时需要输入此密码</div>
                </div>
            </div>

            <!-- 网络设置 -->
            <div class="section">
                <h2>网络设置</h2>
                <div class="form-group">
                    <label for="max_upload_in_kilobytes_per_second">最大上传速度（KB/s，0 表示无限制）</label>
                    <input type="number" id="max_upload_in_kilobytes_per_second" name="max_upload_in_kilobytes_per_second" value="<?= $config['max_upload_in_kilobytes_per_second'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group">
                    <label for="max_upload_slots">最大上传槽位（0 表示无限制，默认 5）</label>
                    <input type="number" id="max_upload_slots" name="max_upload_slots" value="<?= $config['max_upload_slots'] ?? 5 ?>" min="0">
                </div>
                <div class="form-group">
                    <label for="minimum_latency_in_ticks">最小延迟（tick 数，1 tick = 16ms，0 表示无限制）</label>
                    <input type="number" id="minimum_latency_in_ticks" name="minimum_latency_in_ticks" value="<?= $config['minimum_latency_in_ticks'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group">
                    <label for="max_heartbeats_per_second">最大心跳频率（每秒 6–240）</label>
                    <input type="number" id="max_heartbeats_per_second" name="max_heartbeats_per_second" value="<?= $config['max_heartbeats_per_second'] ?? 60 ?>" min="6" max="240">
                </div>
            </div>

            <!-- 命令和暂停设置 -->
            <div class="section">
                <h2>命令和游戏控制</h2>
                <div class="form-group">
                    <label for="allow_commands">允许命令</label>
                    <select id="allow_commands" name="allow_commands">
                        <option value="true" <?= ($config['allow_commands'] ?? '') === 'true' ? 'selected' : '' ?>>所有人</option>
                        <option value="false" <?= ($config['allow_commands'] ?? '') === 'false' ? 'selected' : '' ?>>禁止</option>
                        <option value="admins-only" <?= ($config['allow_commands'] ?? 'admins-only') === 'admins-only' ? 'selected' : '' ?>>仅管理员</option>
                    </select>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="only_admins_can_pause_the_game" name="only_admins_can_pause_the_game" <?= !empty($config['only_admins_can_pause_the_game']) ? 'checked' : '' ?>>
                    <label for="only_admins_can_pause_the_game">仅管理员可以暂停游戏</label>
                </div>
            </div>

            <!-- 自动保存设置 -->
            <div class="section">
                <h2>自动保存设置</h2>
                <div class="form-group">
                    <label for="autosave_interval">自动保存间隔（分钟）</label>
                    <input type="number" id="autosave_interval" name="autosave_interval" value="<?= $config['autosave_interval'] ?? 10 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="autosave_slots">自动保存槽位数</label>
                    <input type="number" id="autosave_slots" name="autosave_slots" value="<?= $config['autosave_slots'] ?? 5 ?>" min="1">
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="autosave_only_on_server" name="autosave_only_on_server" <?= !empty($config['autosave_only_on_server']) ? 'checked' : '' ?>>
                    <label for="autosave_only_on_server">仅在服务器上保存自动存档</label>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="non_blocking_saving" name="non_blocking_saving" <?= !empty($config['non_blocking_saving']) ? 'checked' : '' ?>>
                    <label for="non_blocking_saving">非阻塞保存（实验性，有风险）</label>
                </div>
            </div>

            <!-- AFK和自动暂停 -->
            <div class="section">
                <h2>AFK 和自动暂停</h2>
                <div class="form-group">
                    <label for="afk_autokick_interval">AFK 自动踢除时间（分钟，0 表示永不）</label>
                    <input type="number" id="afk_autokick_interval" name="afk_autokick_interval" value="<?= $config['afk_autokick_interval'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="auto_pause" name="auto_pause" <?= !empty($config['auto_pause']) ? 'checked' : '' ?>>
                    <label for="auto_pause">当没有玩家时自动暂停服务器</label>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="auto_pause_when_players_connect" name="auto_pause_when_players_connect" <?= !empty($config['auto_pause_when_players_connect']) ? 'checked' : '' ?>>
                    <label for="auto_pause_when_players_connect">当有玩家连接时暂停服务器</label>
                </div>
            </div>

            <!-- 网络分段设置 -->
            <div class="section">
                <h2>网络分段设置</h2>
                <div class="form-group">
                    <label for="minimum_segment_size">最小分段大小</label>
                    <input type="number" id="minimum_segment_size" name="minimum_segment_size" value="<?= $config['minimum_segment_size'] ?? 25 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="minimum_segment_size_peer_count">最小分段大小对应的玩家数</label>
                    <input type="number" id="minimum_segment_size_peer_count" name="minimum_segment_size_peer_count" value="<?= $config['minimum_segment_size_peer_count'] ?? 20 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="maximum_segment_size">最大分段大小</label>
                    <input type="number" id="maximum_segment_size" name="maximum_segment_size" value="<?= $config['maximum_segment_size'] ?? 100 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="maximum_segment_size_peer_count">最大分段大小对应的玩家数</label>
                    <input type="number" id="maximum_segment_size_peer_count" name="maximum_segment_size_peer_count" value="<?= $config['maximum_segment_size_peer_count'] ?? 10 ?>" min="1">
                </div>
            </div>
        </form>

        <footer>
            © 2025 [ieac] Factorio 服务器管理面板 | 
            QQ群: <a href="https://jq.qq.com/?_wv=1027&k=1137842268" target="_blank">1137842268</a>
        </footer>
    </div>
</body>
</html>
