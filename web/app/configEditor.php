<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$serverDir = __DIR__ . '/../config/serverConfigs';
if (!is_dir($serverDir)) { mkdir($serverDir, 0755, true); }

function loadJsonConfig($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}
function loadSecrets() {
    $config = loadConfig();
    return $config['factorio_secrets'] ?? ['username' => '', 'password' => '', 'token' => ''];
}
function getConfigFiles($dir) {
    $files = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') as $file) { $files[] = basename($file, '.json'); }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}
function saveConfigFile($dir, $filename, $data) {
    $safeFilename = basename($filename);
    return file_put_contents($dir . '/' . $safeFilename . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
function deleteConfigFile($dir, $filename) {
    $path = $dir . '/' . basename($filename) . '.json';
    return file_exists($path) ? unlink($path) : false;
}

$message = '';
$secrets = loadSecrets();
$configFiles = getConfigFiles($serverDir);
$editFile = trim($_GET['file'] ?? '');
$config = [];

if (!empty($editFile)) {
    $editFile = preg_replace('/\.json$/i', '', $editFile);
    $configPath = $serverDir . '/' . $editFile . '.json';
    if (file_exists($configPath)) { $config = loadJsonConfig($configPath); }
    else { $message = "配置文件不存在：$editFile"; $editFile = ''; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $rawFilename = trim($_POST['config_filename'] ?? '');
    if ($action === 'delete' && !empty($rawFilename)) {
        if (deleteConfigFile($serverDir, $rawFilename)) {
            $message = "配置文件已删除：$rawFilename";
            $configFiles = getConfigFiles($serverDir);
            $editFile = ''; $config = [];
        } else { $message = "删除失败！"; }
    } elseif (!empty($rawFilename)) {
        $rawFilename = preg_replace('/\.json$/i', '', $rawFilename);
        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}-]+$/u', $rawFilename)) { $message = "文件名只能包含字母、数字、汉字、下划线或中划线！"; }
        elseif (mb_strlen($rawFilename, 'UTF-8') > 20) { $message = "文件名不能超过 20 个字符！"; }
        else {
            $trunc = function($s, $m = 50) { return mb_substr((string)$s, 0, $m, 'UTF-8'); };
            $name = trim($_POST['name'] ?? ''); $name = preg_replace('/^\[ieac\]\s*/i', '', $name);
            $desc = trim($_POST['description'] ?? ''); $desc = preg_replace('/^\[QQ群:1137842268\]\s*/i', '', $desc);
            $rconCfg = $config['rcon'] ?? [];
            if (empty($rconCfg['password'])) { $rconCfg['password'] = bin2hex(random_bytes(16)); }

            $data = [
                'name' => $trunc('[ieac] ' . $name),
                'description' => $trunc('[QQ群:1137842268] ' . $desc),
                'tags' => isset($_POST['tags']) ? array_map('trim', explode(',', $_POST['tags'])) : [],
                'max_players' => (int)($_POST['max_players'] ?? 0),
                'ignore_player_limit_for_returning_players' => !empty($_POST['ignore_player_limit_for_returning_players']),
                'require_user_verification' => !empty($_POST['require_user_verification']),
                'visibility' => ['public' => !empty($_POST['visibility_public']), 'lan' => !empty($_POST['visibility_lan'])],
                'username' => $secrets['username'], 'password' => $secrets['password'], 'token' => $secrets['token'],
                'game_password' => $trunc($_POST['game_password'] ?? ''),
                'rcon' => ['enabled' => !empty($_POST['rcon_enabled']), 'port' => (int)($_POST['rcon_port'] ?? 27015), 'password' => $trunc($_POST['rcon_password'] ?? $rconCfg['password'])],
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
                $message = "配置已保存为：$rawFilename.json";
                $configFiles = getConfigFiles($serverDir); $editFile = $rawFilename; $config = $data;
            } else { $message = "保存失败！请检查目录权限。"; }
        }
    } else { $message = "配置文件名不能为空！"; }
}

$displayName = isset($config['name']) ? preg_replace('/^\[ieac\]\s*/i', '', $config['name']) : '';
$displayDesc = isset($config['description']) ? preg_replace('/^\[QQ群:1137842268\]\s*/i', '', $config['description']) : '';
$rconConfig = $config['rcon'] ?? []; $rconEnabled = $rconConfig['enabled'] ?? true;
$rconPort = $rconConfig['port'] ?? 27015; $rconPassword = $rconConfig['password'] ?? '';
if (empty($rconPassword)) { $rconPassword = bin2hex(random_bytes(16)); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>配置编辑器 - Factorio Server Pro</title>
    <link rel="icon" type="image/x-icon" href="/app/public/favicon.ico">
    <link rel="stylesheet" href="/lib/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/lib/vendor/bootstrap/bootstrap-icons.css">
    <link id="skin-style" rel="stylesheet" href="/app/public/assets/skins/default.css">
    <link rel="stylesheet" href="/app/public/assets/css/main.css">
    <style>
        :root { --sidebar-width: 220px; }
        body { background: var(--bg-body); min-height: 100vh; overflow-x: hidden; }
        .sidebar {
            position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh;
            background: var(--bg-sidebar); color: white; z-index: 1000;
            display: flex; flex-direction: column; overflow-y: auto;
        }
        .sidebar-nav-item {
            display: block; padding: 0.65rem 1.25rem; color: rgba(255,255,255,0.6);
            text-decoration: none; transition: all 0.2s; font-size: 0.9rem;
            border-left: 3px solid transparent;
        }
        .sidebar-nav-item:hover { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.9); }
        .sidebar-nav-item.active { background: rgba(255,255,255,0.12); color: white; border-left-color: #4dabf7; }
        .sidebar-nav-item i { width: 20px; margin-right: 10px; font-size: 0.95rem; }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .nav-section-title { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.35); padding: 0.75rem 1.25rem 0.35rem; font-weight: 600; }
        .page-header { margin-bottom: 1.25rem; }
        .page-header h4 { margin-bottom: 0.15rem; }
        .page-header p { margin-bottom: 0; color: #6c757d; font-size: 0.875rem; }
        .file-chip {
            display: inline-flex; align-items: center; padding: 0.4rem 0.85rem;
            border-radius: 50px; font-size: 0.82rem; text-decoration: none; cursor: pointer;
            transition: all 0.2s; border: 1px solid var(--border-color); color: var(--text-primary); background: white;
        }
        .file-chip:hover { border-color: var(--primary); color: var(--primary); background: #e8f4fd; }
        .file-chip.active { background: var(--primary); color: white; border-color: var(--primary); }
        .readonly-field { background: #e9ecef !important; color: #6c757d; }
        .note-text { font-size: 0.77rem; color: #888; margin-top: 4px; }
        .tags-container {
            display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
            padding: 8px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: white; min-height: 42px;
        }
        .tag-item { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: var(--primary); color: white; border-radius: 50px; font-size: 0.78rem; }
        .tag-item .tag-remove { cursor: pointer; font-weight: bold; opacity: 0.7; }
        .tag-item .tag-remove:hover { opacity: 1; }
        .tags-input { flex: 1; min-width: 120px; border: none; outline: none; background: transparent; font-size: 0.87rem; padding: 4px 0; }
        .btn-action { padding: 0.55rem 1.25rem; font-size: 0.9rem; font-weight: 600; border-radius: 8px; }
        .rcon-card .card-header { background: linear-gradient(135deg, #fd7e14 0%, #e67e22 100%) !important; color: white; }
        .admin-card .card-header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important; color: white; }
    </style>
</head>
<body>

<div class="layout-container">

<div class="sidebar">
    <div class="sidebar-header">
        <a href="public/pages/dashboard.html" class="sidebar-brand">
            <i class="bi bi-hdd-rack-fill"></i>
            <span>Factorio Pro</span>
        </a>
    </div>

    <nav class="sidebar-body">
        <div class="server-info-compact mb-3">
            <div class="server-info-main">
                <span class="server-info-name"><i class="bi bi-gear-wide-connected me-2"></i>配置编辑器</span>
                <span class="badge bg-primary">管理工具</span>
            </div>
            <div class="server-info-meta">
                <span><i class="bi bi-file-earmark-code me-1"></i>服务器配置</span>
                <span><i class="bi bi-shield-check me-1"></i>已认证</span>
            </div>
        </div>

        <div class="nav-section-title">导航</div>
        <a href="public/pages/dashboard.html" class="sidebar-nav-item">
            <i class="bi bi-speedometer2"></i>控制面板
        </a>
        <a href="#" class="sidebar-nav-item active">
            <i class="bi bi-gear-wide-connected"></i>配置编辑器
        </a>
        <a href="public/pages/console.html" class="sidebar-nav-item">
            <i class="bi bi-terminal"></i>控制台
        </a>

        <div class="nav-section-title mt-3">系统</div>
        <a href="public/pages/dashboard.html#settings" class="sidebar-nav-item">
            <i class="bi bi-gear"></i>系统设置
        </a>
    </nav>

    <div class="user-dropdown" style="position:sticky;bottom:0;background:rgba(26,26,46,0.95);backdrop-filter:blur(8px);padding:12px 16px;border-top:1px solid rgba(255,255,255,0.08);">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-circle fs-4 opacity-75"></i>
                <div>
                    <div class="small fw-bold text-white">管理员</div>
                    <div class="small opacity-50" style="font-size:0.7rem">在线</div>
                </div>
            </div>
            <a href="auth.php?action=logout" class="btn-icon btn-exit" title="退出">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<div class="main-content">
    <div style="padding: 24px; max-width: 1400px; margin: 0 auto;">
        <div class="page-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-gear-wide-connected me-2 text-warning"></i>服务器配置编辑器</h4>
                <p class="text-muted mb-0 small">创建和管理 Factorio 服务端启动配置文件</p>
            </div>
            <a href="public/pages/dashboard.html" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>返回控制面板
            </a>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert <?= strpos($message, '已保存') !== false || strpos($message, '已删除') !== false ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi <?= strpos($message, '已保存') !== false || strpos($message, '已删除') !== false ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <div class="card admin-card mb-4">
            <div class="card-header d-flex align-items-center"><i class="bi bi-shield-lock me-2"></i>管理员 - 敏感信息</div>
            <div class="card-body">
                <p class="small text-muted mb-3">Factorio.com 敏感信息（用户名、密码、Token）集中存储在系统配置中，游戏密码可在此设置。</p>
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">Factorio.com 用户名</label>
                        <input class="form-control readonly-field" value="<?= htmlspecialchars($secrets['username'] ?: '(未设置)') ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <a href="secretsManager.php" class="btn btn-outline-danger w-100 btn-action"><i class="bi bi-key me-1"></i>管理敏感信息</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 配置文件选择 -->
        <div class="card mb-4">
            <div class="card-header bg-white text-dark"><i class="bi bi-folder2-open me-2"></i>选择配置文件</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-0">
                    <a href="?file=" class="file-chip <?= empty($editFile) ? 'active' : '' ?>"><i class="bi bi-plus-lg me-1"></i>新建配置</a>
                    <?php foreach ($configFiles as $file): ?>
                    <a href="?file=<?= urlencode($file) ?>" class="file-chip <?= $editFile === $file ? 'active' : '' ?>"><?= htmlspecialchars($file) ?></a>
                    <?php endforeach; ?>
                    <?php if (empty($configFiles) && empty($editFile)): ?>
                    <span class="text-muted small mt-2">暂无配置文件，请新建一个</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="post" id="configForm">
            <input type="hidden" name="action" value="save">

            <!-- 保存操作栏 -->
            <div class="card mb-4">
                <div class="card-header bg-white text-dark"><i class="bi bi-save me-2"></i>保存配置</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="config_filename" class="form-label">配置文件名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="config_filename" name="config_filename"
                                   value="<?= htmlspecialchars($editFile) ?>" placeholder="例如：生存服-中文-1" required>
                            <div class="note-text">仅允许字母、数字、汉字、下划线(_)、中划线(-)；最多 20 字符</div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end gap-2">
                            <button type="submit" name="action" value="save" class="btn btn-primary btn-action flex-grow-1"><i class="bi bi-save me-1"></i>保存</button>
                            <button type="submit" name="action" value="saveas" class="btn btn-outline-secondary btn-action"><i class="bi bi-copy me-1"></i>另存为</button>
                            <?php if (!empty($editFile)): ?>
                            <button type="submit" name="action" value="delete" class="btn btn-outline-danger btn-action"
                                    onclick="return confirm('确定要删除此配置吗？此操作不可恢复。');"><i class="bi bi-trash me-1"></i>删除</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">

                <!-- 左列 -->
                <div class="col-lg-6">

                    <!-- RCON 设置 -->
                    <div class="card rcon-card mb-4">
                        <div class="card-header"><i class="bi bi-broadcast-pin me-2"></i>RCON 远程控制</div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="rcon_enabled" name="rcon_enabled" <?= $rconEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rcon_enabled">启用 RCON 远程控制</label>
                            </div>
                            <div class="mb-3">
                                <label for="rcon_port" class="form-label">RCON 端口</label>
                                <input type="number" class="form-control" id="rcon_port" name="rcon_port" value="<?= $rconPort ?>" min="1" max="65535">
                                <div class="note-text">默认 27015，确保端口未被占用</div>
                            </div>
                            <div class="mb-0">
                                <label for="rcon_password" class="form-label">RCON 密码</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="rcon_password" name="rcon_password" maxlength="100" value="<?= htmlspecialchars($rconPassword) ?>">
                                    <button type="button" class="btn btn-outline-dark" onclick="generateRconPassword()" title="生成新密码"><i class="bi bi-arrow-repeat"></i></button>
                                </div>
                                <div class="note-text">用于远程控制台连接，建议设置强密码</div>
                            </div>
                        </div>
                    </div>

                    <!-- 基本信息 -->
                    <div class="card mb-4">
                        <div class="card-header bg-white text-dark"><i class="bi bi-info-circle me-2"></i>基本信息</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="name" class="form-label">服务器名称 <span class="text-muted small">(自动添加 [ieac] 前缀)</span></label>
                                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($displayName) ?>" placeholder="输入服务器名称">
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">服务器描述 <span class="text-muted small">(自动添加 [QQ群] 前缀)</span></label>
                                <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($displayDesc) ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="game_password" class="form-label">游戏密码</label>
                                <input type="password" class="form-control" id="game_password" name="game_password" maxlength="50" value="<?= htmlspecialchars($config['game_password'] ?? '') ?>" placeholder="留空表示无需密码">
                                <div class="note-text">玩家连接时需要输入此密码</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">标签 <span class="text-muted small">(回车添加)</span></label>
                                <div id="tags-container" class="tags-container">
                                    <div id="tags-list" class="d-flex flex-wrap gap-1"></div>
                                    <input type="text" id="tags-input" class="tags-input" placeholder="输入后按回车..." maxlength="20">
                                </div>
                                <input type="hidden" id="tags" name="tags" value="<?= htmlspecialchars(implode(',', $config['tags'] ?? [])) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- 玩家设置 -->
                    <div class="card mb-4">
                        <div class="card-header bg-white text-dark"><i class="bi bi-people me-2"></i>玩家设置</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="max_players" class="form-label">最大玩家数</label>
                                <input type="number" class="form-control" id="max_players" name="max_players" value="<?= $config['max_players'] ?? 0 ?>" min="0" placeholder="0 = 无限制">
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="ignore_limit" name="ignore_player_limit_for_returning_players" <?= !empty($config['ignore_player_limit_for_returning_players']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ignore_limit">允许回头玩家忽略限制</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="req_verify" name="require_user_verification" <?= !empty($config['require_user_verification']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="req_verify">要求用户验证（仅有效账号）</label>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- 右列 -->
                <div class="col-lg-6">

                    <!-- 可见性 & 网络 -->
                    <div class="card mb-4">
                        <div class="card-header bg-white text-dark"><i class="bi bi-wifi me-2"></i>可见性与网络</div>
                        <div class="card-body">
                            <h6 class="fw-bold text-muted small mb-2">可见性</h6>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="vis_public" name="visibility_public" <?= !empty($config['visibility']['public']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vis_public">公开（官方匹配列表可见）</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="vis_lan" name="visibility_lan" <?= !empty($config['visibility']['lan']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vis_lan">局域网可见</label>
                            </div>
                            <hr class="my-3">
                            <h6 class="fw-bold text-muted small mb-2">网络传输</h6>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small">上传速度 KB/s</label>
                                    <input type="number" class="form-control form-control-sm" name="max_upload_in_kilobytes_per_second" value="<?= $config['max_upload_in_kilobytes_per_second'] ?? 0 ?>" min="0" placeholder="0=无限制">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">上传槽位</label>
                                    <input type="number" class="form-control form-control-sm" name="max_upload_slots" value="<?= $config['max_upload_slots'] ?? 5 ?>" min="0" placeholder="默认5">
                                </div>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small">最小延迟 (tick)</label>
                                    <input type="number" class="form-control form-control-sm" name="minimum_latency_in_ticks" value="<?= $config['minimum_latency_in_ticks'] ?? 0 ?>" min="0" placeholder="0=无限制">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">心跳频率 /秒</label>
                                    <input type="number" class="form-control form-control-sm" name="max_heartbeats_per_second" value="<?= $config['max_heartbeats_per_second'] ?? 60 ?>" min="6" max="240">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 游戏控制 & 自动保存 -->
                    <div class="card mb-4">
                        <div class="card-header bg-white text-dark"><i class="bi bi-controller me-2"></i>游戏控制与存档</div>
                        <div class="card-body">
                            <h6 class="fw-bold text-muted small mb-2">命令权限</h6>
                            <select class="form-select form-select-sm mb-3" name="allow_commands">
                                <option value="true" <?= ($config['allow_commands'] ?? '') === 'true' ? 'selected' : '' ?>>所有人</option>
                                <option value="false" <?= ($config['allow_commands'] ?? '') === 'false' ? 'selected' : '' ?>>禁止</option>
                                <option value="admins-only" <?= ($config['allow_commands'] ?? 'admins-only') === 'admins-only' ? 'selected' : '' ?>>仅管理员</option>
                            </select>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="admin_pause" name="only_admins_can_pause_the_game" <?= !empty($config['only_admins_can_pause_the_game']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="admin_pause">仅管理员可暂停</label>
                            </div>
                            <hr class="my-3">
                            <h6 class="fw-bold text-muted small mb-2">自动保存</h6>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small">间隔（分钟）</label>
                                    <input type="number" class="form-control form-control-sm" name="autosave_interval" value="<?= $config['autosave_interval'] ?? 10 ?>" min="1">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">槽位数</label>
                                    <input type="number" class="form-control form-control-sm" name="autosave_slots" value="<?= $config['autosave_slots'] ?? 5 ?>" min="1">
                                </div>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="autosave_only_on_server" <?= !empty($config['autosave_only_on_server']) ? 'checked' : '' ?>>
                                <label class="form-check-label">仅服务端保存</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="non_blocking_saving" <?= !empty($config['non_blocking_saving']) ? 'checked' : '' ?>>
                                <label class="form-check-label">非阻塞保存<span class="text-danger small ms-1">(实验性)</span></label>
                            </div>
                        </div>
                    </div>

                    <!-- AFK & 网络分段 -->
                    <div class="card mb-4">
                        <div class="card-header bg-white text-dark"><i class="bi bi-moon me-2"></i>AFK 与网络分段</div>
                        <div class="card-body">
                            <h6 class="fw-bold text-muted small mb-2">AFK 管理</h6>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small">AFK 踢除时间（分钟）</label>
                                    <input type="number" class="form-control form-control-sm" name="afk_autokick_interval" value="<?= $config['afk_autokick_interval'] ?? 0 ?>" min="0" placeholder="0=永不">
                                </div>
                                <div class="col-6 d-flex align-items-end gap-2">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="auto_pause" <?= !empty($config['auto_pause']) ? 'checked' : '' ?>>
                                        <label class="form-check-label small">无玩家时暂停</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="auto_pause_when_players_connect" <?= !empty($config['auto_pause_when_players_connect']) ? 'checked' : '' ?>>
                                <label class="form-check-label">有玩家连接时暂停</label>
                            </div>
                            <hr class="my-3">
                            <h6 class="fw-bold text-muted small mb-2">网络分段大小</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small">最小分段</label>
                                    <input type="number" class="form-control form-control-sm" name="minimum_segment_size" value="<?= $config['minimum_segment_size'] ?? 25 ?>" min="1">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">对应玩家数</label>
                                    <input type="number" class="form-control form-control-sm" name="minimum_segment_size_peer_count" value="<?= $config['minimum_segment_size_peer_count'] ?? 20 ?>" min="1">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">最大分段</label>
                                    <input type="number" class="form-control form-control-sm" name="maximum_segment_size" value="<?= $config['maximum_segment_size'] ?? 100 ?>" min="1">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">对应玩家数</label>
                                    <input type="number" class="form-control form-control-sm" name="maximum_segment_size_peer_count" value="<?= $config['maximum_segment_size_peer_count'] ?? 10 ?>" min="1">
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </form>

        <div class="text-center mt-4 pt-3 border-top">
            <small class="text-muted">&copy; 2025 [ieac] Factorio Server Pro | <a href="https://jq.qq.com/?_wv=1027&k=1137842268" target="_blank" class="text-decoration-none">QQ群: 1137842268</a></small>
        </div>
    </div>

</div>
</div>

<script src="/lib/vendor/bootstrap/js/bootstrap.bundle.js"></script>
<script>
(function() {
    const tagsInput = document.getElementById('tags-input');
    const tagsList = document.getElementById('tags-list');
    const tagsHidden = document.getElementById('tags');
    const maxTags = 5;
    let tags = [];

    function initTags() {
        const val = tagsHidden.value.trim();
        if (val) tags = val.split(',').filter(t => t.trim()).map(t => t.trim());
        renderTags();
    }
    function renderTags() {
        tagsList.innerHTML = '';
        tags.forEach(function(tag, idx) {
            var el = document.createElement('span');
            el.className = 'tag-item';
            el.innerHTML = escapeHtml(tag) + '<span class="tag-remove" data-index="' + idx + '">&times;</span>';
            tagsList.appendChild(el);
        });
        tagsHidden.value = tags.join(',');
    }
    function escapeHtml(text) {
        var d = document.createElement('div'); d.textContent = text; return d.innerHTML;
    }
    function addTag(val) {
        var v = val.trim();
        if (!v) return false;
        if (tags.length >= maxTags) { alert('最多 ' + maxTags + ' 个标签'); return false; }
        if (tags.includes(v)) { alert('标签已存在'); return false; }
        tags.push(v); renderTags(); return true;
    }
    function removeTag(idx) { tags.splice(idx, 1); renderTags(); }

    tagsInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); if (addTag(this.value)) this.value = ''; }
        else if (e.key === 'Backspace' && !this.value && tags.length > 0) { tags.pop(); renderTags(); }
    });
    tagsList.addEventListener('click', function(e) {
        if (e.target.classList.contains('tag-remove')) removeTag(parseInt(e.target.dataset.index));
    });
    initTags();
})();

function generateRconPassword() {
    var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    var pwd = '';
    for (var i = 0; i < 24; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    document.getElementById('rcon_password').value = pwd;
}
</script>
</body>
</html>
