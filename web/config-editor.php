<?php
// 引入认证模块
require_once __DIR__ . '/auth.php';

// 要求登录
requireLogin();

// 保存配置的目录
$serverDir = '../server/configs'; // 指向上一级统一管理的目录

if (!is_dir($serverDir)) {
    mkdir($serverDir, 0755, true);
}

// 加载默认配置（用于表单填充）
function loadJsonConfig($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

// 保存新配置副本
function saveConfigCopy($dir, $filename, $data) {
    $safeFilename = basename($filename); // 防路径穿越
    $path = "$dir/$safeFilename.json";
    $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $jsonContent);
}

$message = '';
$config = loadJsonConfig('server-settings.json');

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawFilename = trim($_POST['config_filename'] ?? '');
    if (empty($rawFilename)) {
        $message = "❌ 配置文件名不能为空！";
    } else {
        // 移除用户可能输入的 .json 后缀
        $rawFilename = preg_replace('/\.json$/i', '', $rawFilename);
        // 只允许：字母、数字、汉字、下划线、中划线
        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]+$/u', $rawFilename)) {
            $message = "❌ 文件名只能包含字母、数字、汉字、下划线或中划线！";
        } else {
            $len = mb_strlen($rawFilename, 'UTF-8');
            if ($len > 20) {
                $message = "❌ 文件名不能超过 20 个字符（当前 $len 个）！";
            } else {
                // 截断函数（默认50字符）
                $truncate = function($str, $max = 50) {
                    return mb_substr((string)$str, 0, $max, 'UTF-8');
                };

                // 构建配置数据
                $data = [
                    // 强制添加前缀，用户输入的内容跟在后面
                    'name' => $truncate('[ieac] ' . trim($_POST['name'] ?? '')),
                    'description' => $truncate('[QQ群:1137842268] ' . trim($_POST['description'] ?? '')),
                    'tags' => isset($_POST['tags']) ? array_map('trim', explode(',', $_POST['tags'])) : ($config['tags'] ?? []),
                    
                    'max_players' => (int)($_POST['max_players'] ?? 0),
                    'ignore_player_limit_for_returning_players' => !empty($_POST['ignore_player_limit_for_returning_players']),
                    'require_user_verification' => !empty($_POST['require_user_verification']),

                    'visibility' => [
                        'public' => !empty($_POST['visibility_public']),
                        'lan' => !empty($_POST['visibility_lan'])
                    ],

                    'username' => $truncate($_POST['username'] ?? ''),
                    'password' => $truncate($_POST['password'] ?? ''),
                    'token' => $truncate($_POST['token'] ?? ''),
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

                if (saveConfigCopy($serverDir, $rawFilename, $data) !== false) {
                    $message = "✅ 配置已成功保存为：<strong>$rawFilename.json</strong>";
                } else {
                    $message = "❌ 保存失败！请检查 <code>server/</code> 目录是否有写入权限。";
                }
            }
        }
    }
}
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
            --border: #ddd;
            --bg: #fafafa;
            --text: #333;
            --section-bg: #fff;
            --success: #d4edda;
            --error: #f8d7da;
            --success-text: #155724;
            --error-text: #721c24;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: "Microsoft YaHei", "PingFang SC", "Segoe UI", sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
            padding: 20px;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
        }
        header {
            text-align: center;
            margin-bottom: 30px;
        }
        h1 {
            color: var(--primary);
            font-size: 28px;
            margin-bottom: 8px;
        }
        .subtitle {
            color: #777;
            font-size: 14px;
        }
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
        .form-group {
            margin: 16px 0;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
        }
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
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15);
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }
        .checkbox-group label {
            font-weight: normal;
            margin: 0;
            font-size: 14px;
            cursor: pointer;
        }
        button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            transition: background 0.2s;
        }
        button:hover {
            background: var(--primary-dark);
        }
        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
        }
        .success {
            background: var(--success);
            color: var(--success-text);
        }
        .error {
            background: var(--error);
            color: var(--error-text);
        }
        .note {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
            font-style: italic;
        }
        code {
            background: #f5f5f5;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: monospace;
        }
        footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #888;
            font-size: 13px;
        }
        footer a {
            color: var(--primary);
            text-decoration: none;
        }
        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Factorio 服务器配置编辑器</h1>
            <div class="subtitle">[ieac] 专用配置管理面板</div>
        </header>

        <?php if (!empty($message)): ?>
            <div class="message <?= strpos($message, '✅') !== false ? 'success' : 'error' ?>">
                <?= htmlspecialchars(strip_tags($message), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <!-- 配置文件名 -->
            <div class="section">
                <h2>💾 保存配置</h2>
                <div class="form-group">
                    <label for="config_filename">配置文件名（必填）</label>
                    <input type="text" id="config_filename" name="config_filename"
                           value="<?= htmlspecialchars($_POST['config_filename'] ?? '') ?>"
                           placeholder="例如：生存服-中文-1" required>
                    <div class="note">仅允许字母、数字、汉字、下划线(_)、中划线(-)；最多 20 个字符（汉字算 1 个）</div>
                </div>
            </div>

            <!-- 基本信息 -->
            <div class="section">
                <h2>基本信息</h2>
                <div class="form-group">
                    <label for="name">服务器名称（最多 50 字符，将自动添加 [ieac] 前缀）</label>
                    <input type="text" id="name" name="name"
                           value="<?= htmlspecialchars(ltrim($config['name'] ?? '', '[ieac] ')) ?>"
                           placeholder="在此输入服务器名称">
                    <div class="note">输入的内容将自动添加 [ieac] 前缀，例如：[ieac] 中文生存服</div>
                </div>
                <div class="form-group">
                    <label for="description">服务器描述（最多 50 字符，将自动添加 [QQ群:1137842268] 前缀）</label>
                    <textarea id="description" name="description" rows="2"><?= htmlspecialchars(ltrim($config['description'] ?? '', '[QQ群:1137842268] ')) ?></textarea>
                    <div class="note">输入的内容将自动添加 [QQ群:1137842268] 前缀，方便玩家识别</div>
                </div>
                <div class="form-group">
                    <label for="tags">标签（用英文逗号分隔，总长 ≤50 字符）</label>
                    <input type="text" id="tags" name="tags" maxlength="50"
                           value="<?= htmlspecialchars(implode(',', $config['tags'] ?? [])) ?>">
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
                    <input type="checkbox" id="visibility_public" name="visibility_public" <?= !empty($config['visibility']['public'] ?? false) ? 'checked' : '' ?>>
                    <label for="visibility_public">公开（在官方匹配服务器上发布）</label>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="visibility_lan" name="visibility_lan" <?= !empty($config['visibility']['lan'] ?? false) ? 'checked' : '' ?>>
                    <label for="visibility_lan">局域网可见</label>
                </div>
            </div>

            <!-- 认证信息 -->
            <div class="section">
                <h2>认证信息</h2>
                <div class="form-group">
                    <label for="username">Factorio.com 用户名（≤50字符）</label>
                    <input type="text" id="username" name="username" maxlength="50"
                           value="<?= htmlspecialchars($config['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password">Factorio.com 密码（≤50字符）</label>
                    <input type="password" id="password" name="password" maxlength="50"
                           value="<?= htmlspecialchars($config['password'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="token">认证令牌（可替代密码，≤50字符）</label>
                    <input type="text" id="token" name="token" maxlength="50"
                           value="<?= htmlspecialchars($config['token'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="game_password">游戏密码（为空则无需密码，≤50字符）</label>
                    <input type="password" id="game_password" name="game_password" maxlength="50"
                           value="<?= htmlspecialchars($config['game_password'] ?? '') ?>">
                </div>
            </div>

            <!-- 网络设置 -->
            <div class="section">
                <h2>网络设置</h2>
                <div class="form-group">
                    <label for="max_upload_in_kilobytes_per_second">最大上传速度（KB/s，0 表示无限制）</label>
                    <input type="number" id="max_upload_in_kilobytes_per_second" name="max_upload_in_kilobytes_per_second"
                           value="<?= $config['max_upload_in_kilobytes_per_second'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group">
                    <label for="max_upload_slots">最大上传槽位（0 表示无限制，默认 5）</label>
                    <input type="number" id="max_upload_slots" name="max_upload_slots"
                           value="<?= $config['max_upload_slots'] ?? 5 ?>" min="0">
                </div>
                <div class="form-group">
                    <label for="minimum_latency_in_ticks">最小延迟（tick 数，1 tick = 16ms，0 表示无限制）</label>
                    <input type="number" id="minimum_latency_in_ticks" name="minimum_latency_in_ticks"
                           value="<?= $config['minimum_latency_in_ticks'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group">
                    <label for="max_heartbeats_per_second">最大心跳频率（每秒 6–240）</label>
                    <input type="number" id="max_heartbeats_per_second" name="max_heartbeats_per_second"
                           value="<?= $config['max_heartbeats_per_second'] ?? 60 ?>" min="6" max="240">
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
                        <option value="admins-only" <?= ($config['allow_commands'] ?? '') === 'admins-only' ? 'selected' : '' ?>>仅管理员</option>
                    </select>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="only_admins_can_pause_the_game" name="only_admins_can_pause_the_game"
                           <?= !empty($config['only_admins_can_pause_the_game'] ?? false) ? 'checked' : '' ?>>
                    <label for="only_admins_can_pause_the_game">仅管理员可以暂停游戏</label>
                </div>
            </div>

            <!-- 自动保存设置 -->
            <div class="section">
                <h2>自动保存设置</h2>
                <div class="form-group">
                    <label for="autosave_interval">自动保存间隔（分钟）</label>
                    <input type="number" id="autosave_interval" name="autosave_interval"
                           value="<?= $config['autosave_interval'] ?? 10 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="autosave_slots">自动保存槽位数</label>
                    <input type="number" id="autosave_slots" name="autosave_slots"
                           value="<?= $config['autosave_slots'] ?? 5 ?>" min="1">
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="autosave_only_on_server" name="autosave_only_on_server"
                           <?= !empty($config['autosave_only_on_server'] ?? false) ? 'checked' : '' ?>>
                    <label for="autosave_only_on_server">仅在服务器上保存自动存档</label>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="non_blocking_saving" name="non_blocking_saving"
                           <?= !empty($config['non_blocking_saving'] ?? false) ? 'checked' : '' ?>>
                    <label for="non_blocking_saving">非阻塞保存（实验性，有风险）</label>
                </div>
            </div>

            <!-- AFK和自动暂停 -->
            <div class="section">
                <h2>AFK 和自动暂停</h2>
                <div class="form-group">
                    <label for="afk_autokick_interval">AFK 自动踢除时间（分钟，0 表示永不）</label>
                    <input type="number" id="afk_autokick_interval" name="afk_autokick_interval"
                           value="<?= $config['afk_autokick_interval'] ?? 0 ?>" min="0">
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="auto_pause" name="auto_pause"
                           <?= !empty($config['auto_pause'] ?? false) ? 'checked' : '' ?>>
                    <label for="auto_pause">当没有玩家时自动暂停服务器</label>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="auto_pause_when_players_connect" name="auto_pause_when_players_connect"
                           <?= !empty($config['auto_pause_when_players_connect'] ?? false) ? 'checked' : '' ?>>
                    <label for="auto_pause_when_players_connect">当有玩家连接时暂停服务器</label>
                </div>
            </div>

            <!-- 网络分段设置 -->
            <div class="section">
                <h2>网络分段设置</h2>
                <div class="form-group">
                    <label for="minimum_segment_size">最小分段大小</label>
                    <input type="number" id="minimum_segment_size" name="minimum_segment_size"
                           value="<?= $config['minimum_segment_size'] ?? 25 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="minimum_segment_size_peer_count">最小分段大小对应的玩家数</label>
                    <input type="number" id="minimum_segment_size_peer_count" name="minimum_segment_size_peer_count"
                           value="<?= $config['minimum_segment_size_peer_count'] ?? 20 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="maximum_segment_size">最大分段大小</label>
                    <input type="number" id="maximum_segment_size" name="maximum_segment_size"
                           value="<?= $config['maximum_segment_size'] ?? 100 ?>" min="1">
                </div>
                <div class="form-group">
                    <label for="maximum_segment_size_peer_count">最大分段大小对应的玩家数</label>
                    <input type="number" id="maximum_segment_size_peer_count" name="maximum_segment_size_peer_count"
                           value="<?= $config['maximum_segment_size_peer_count'] ?? 10 ?>" min="1">
                </div>
            </div>

            <!-- 提交按钮 -->
            <button type="submit">💾 保存为新配置副本</button>
        </form>

        <footer>
            © 2025 [ieac] Factorio 服务器管理面板 | 
            QQ群: <a href="https://jq.qq.com/?_wv=1027&k=1137842268" target="_blank">1137842268</a>
        </footer>
    </div>
</body>
</html>