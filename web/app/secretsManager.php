<?php
/**
 * Factorio Server Pro - 敏感信息管理
 * 
 * 管理 Factorio.com 账号信息（用户名、密码、令牌）
 * 仅管理员可访问
 */

require_once __DIR__ . '/auth.php';

requireLogin();

if (!isAdmin()) {
    die('访问被拒绝：仅管理员可访问此页面');
}

$configFile = dirname(__DIR__) . '/config/system/auth.php';

function loadSecretsConfig() {
    global $configFile;
    
    if (file_exists($configFile)) {
        $config = require $configFile;
        return $config['factorio_secrets'] ?? [
            'username' => '',
            'password' => '',
            'token' => '',
        ];
    }
    
    return [
        'username' => '',
        'password' => '',
        'token' => '',
    ];
}

function saveSecretsConfig($secrets) {
    global $configFile;
    
    $config = [];
    if (file_exists($configFile)) {
        $config = require $configFile;
    }
    
    $config['factorio_secrets'] = [
        'username' => trim($secrets['username'] ?? ''),
        'password' => trim($secrets['password'] ?? ''),
        'token' => trim($secrets['token'] ?? ''),
    ];
    
    $configContent = "<?php\n\nreturn [\n";
    
    if (isset($config['users'])) {
        $configContent .= "    'users' => [\n";
        foreach ($config['users'] as $username => $user) {
            $configContent .= "        '$username' => [\n";
            $configContent .= "            'password' => '" . addslashes($user['password']) . "',\n";
            $configContent .= "            'role' => '" . addslashes($user['role']) . "',\n";
            $configContent .= "            'name' => '" . addslashes($user['name'] ?? $username) . "',\n";
            $configContent .= "        ],\n";
        }
        $configContent .= "    ],\n";
    }
    
    $configContent .= "    'session_expiry' => " . ($config['session_expiry'] ?? 86400) . ",\n";
    
    $configContent .= "    'factorio_secrets' => [\n";
    $configContent .= "        'username' => '" . addslashes($config['factorio_secrets']['username']) . "',\n";
    $configContent .= "        'password' => '" . addslashes($config['factorio_secrets']['password']) . "',\n";
    $configContent .= "        'token' => '" . addslashes($config['factorio_secrets']['token']) . "',\n";
    $configContent .= "    ],\n";
    
    $configContent .= "];\n";
    
    return file_put_contents($configFile, $configContent) !== false;
}

$message = '';
$secrets = loadSecretsConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSecrets = [
        'username' => $_POST['username'] ?? '',
        'password' => $_POST['password'] ?? '',
        'token' => $_POST['token'] ?? '',
    ];
    
    if (saveSecretsConfig($newSecrets)) {
        $message = "✅ 敏感信息已保存";
        $secrets = $newSecrets;
    } else {
        $message = "❌ 保存失败！请检查文件权限。";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>敏感信息管理 - Factorio Server Pro</title>
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
        .container { max-width: 600px; margin: 0 auto; }
        header { text-align: center; margin-bottom: 30px; }
        h1 { color: var(--danger); font-size: 24px; margin-bottom: 8px; }
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
            font-size: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        .form-group { margin: 16px 0; }
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15);
        }
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
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .warning-box h3 {
            color: #856404;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .warning-box ul {
            color: #856404;
            font-size: 13px;
            margin-left: 20px;
        }
        .nav-links { margin-bottom: 20px; }
        .nav-links a { color: var(--primary); text-decoration: none; font-size: 14px; }
        .nav-links a:hover { text-decoration: underline; }
        footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #888;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="configEditor.php">← 返回配置编辑器</a>
        </div>

        <header>
            <h1>🔐 敏感信息管理</h1>
            <div class="subtitle">Factorio.com 账号凭据配置</div>
        </header>

        <?php if (!empty($message)): ?>
            <div class="message <?= strpos($message, '✅') !== false ? 'success' : 'error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="warning-box">
            <h3>⚠️ 安全提示</h3>
            <ul>
                <li>此信息用于服务器向 Factorio 官方匹配服务器注册</li>
                <li>请妥善保管您的账号信息</li>
                <li>建议使用 Token 而非密码进行认证</li>
                <li>Token 可在 <a href="https://factorio.com/profile" target="_blank">Factorio 官网</a> 获取</li>
            </ul>
        </div>

        <form method="post">
            <div class="section">
                <h2>Factorio.com 账号信息</h2>
                
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" 
                           value="<?= htmlspecialchars($secrets['username']) ?>" 
                           placeholder="Factorio.com 用户名" autocomplete="off">
                    <div class="note">用于服务器认证的 Factorio.com 用户名</div>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" 
                           value="<?= htmlspecialchars($secrets['password']) ?>" 
                           placeholder="Factorio.com 密码" autocomplete="new-password">
                    <div class="note">建议使用 Token 替代密码</div>
                </div>
                
                <div class="form-group">
                    <label for="token">Token（令牌）</label>
                    <input type="password" id="token" name="token" 
                           value="<?= htmlspecialchars($secrets['token']) ?>" 
                           placeholder="Factorio.com API Token" autocomplete="off">
                    <div class="note">可在 Factorio 官网个人资料页面生成</div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn-primary">💾 保存</button>
                    <button type="button" class="btn-secondary" onclick="location.href='configEditor.php'">返回</button>
                </div>
            </div>
        </form>

        <footer>
            © 2025 [ieac] Factorio 服务器管理面板
        </footer>
    </div>
</body>
</html>
