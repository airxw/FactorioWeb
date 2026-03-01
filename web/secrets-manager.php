<?php
/**
 * Factorio Server Pro - 敏感信息管理器
 * 
 * 用于管理 config.php 中的敏感配置信息
 * 仅限管理员访问
 * 
 * 安全措施：
 * - 密码和令牌不回显，只允许写入
 * - 严格过滤输入，防止注入攻击
 */

require_once __DIR__ . '/auth.php';

requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    die('❌ 权限不足：仅管理员可访问此页面');
}

$configFile = __DIR__ . '/config.php';

// 安全过滤输入（防止注入）
function sanitizeInput($input, $maxLength = 100) {
    $input = (string)$input;
    $input = mb_substr($input, 0, $maxLength, 'UTF-8');
    // 只允许安全字符：字母、数字、下划线、中划线、点、@符号
    $input = preg_replace('/[^\w\-\.@]/u', '', $input);
    return $input;
}

// 转义输出（用于写入PHP文件）
function escapeForPhp($str) {
    return addslashes($str);
}

// 加载当前用户名（仅用户名，密码和令牌不读取）
function getCurrentUsername() {
    $config = loadConfig();
    return $config['factorio_secrets']['username'] ?? '';
}

// 保存敏感信息到config.php
function saveSecretsToConfig($username, $password, $token) {
    global $configFile;
    
    $config = loadConfig();
    
    // 如果密码或令牌为空，保留原有值
    $currentSecrets = $config['factorio_secrets'] ?? ['username' => '', 'password' => '', 'token' => ''];
    
    $config['factorio_secrets'] = [
        'username' => $username,
        'password' => $password !== '' ? $password : $currentSecrets['password'],
        'token' => $token !== '' ? $token : $currentSecrets['token'],
    ];
    
    return file_put_contents($configFile, generateConfigPhp($config));
}

// 生成config.php内容
function generateConfigPhp($config) {
    $usersArray = '';
    foreach ($config['users'] as $username => $userData) {
        $usersArray .= sprintf(
            "        '%s' => [\n            'password' => '%s',\n            'role' => '%s',\n            'name' => '%s'\n        ],\n",
            escapeForPhp($username),
            escapeForPhp($userData['password']),
            escapeForPhp($userData['role']),
            escapeForPhp($userData['name'])
        );
    }
    
    $secrets = $config['factorio_secrets'];
    
    return <<<PHP
<?php
/**
 * Factorio Server Pro - 配置文件
 * 
 * 此文件包含用户认证和系统配置
 * 
 * 安全警告:
 * - 此文件包含敏感信息，请确保 web 服务器正确保护此文件
 * - 建议在生产环境中使用 .htaccess 或类似配置禁止直接访问
 *
 * @package FactorioServerPro
 * @version 2.0
 */

return [
    /**
     * 用户配置
     * 
     * 格式:
     * '用户名' => [
     *     'password' => '密码哈希 (使用 password-tool.html 生成)',
     *     'role' => '用户角色 (admin/user)',
     *     'name' => '显示名称'
     * ]
     */
    'users' => [
{$usersArray}    ],

    /**
     * 会话配置
     */
    'session_expiry' => {$config['session_expiry']},

    /**
     * 默认登录信息（仅供参考，不用于实际验证）
     * 实际密码请使用 password-tool.html 生成哈希
     */
    'default_username' => '{$config['default_username']}',
    'default_password' => '{$config['default_password']}',

    /**
     * Factorio服务器敏感配置
     * 
     * 这些配置用于server-settings.json的生成
     * 仅在服务器端使用，不暴露给前端
     */
    'factorio_secrets' => [
        'username' => '{$secrets['username']}',
        'password' => '{$secrets['password']}',
        'token' => '{$secrets['token']}',
    ],
];
PHP;
}

$message = '';
$currentUsername = getCurrentUsername();

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        // 安全过滤输入
        $newUsername = sanitizeInput($_POST['username'] ?? '', 100);
        $newPassword = sanitizeInput($_POST['password'] ?? '', 100);
        $newToken = sanitizeInput($_POST['token'] ?? '', 100);
        
        if (saveSecretsToConfig($newUsername, $newPassword, $newToken) !== false) {
            chmod($configFile, 0640);
            $message = "✅ 敏感信息已更新";
            $currentUsername = $newUsername;
        } else {
            $message = "❌ 保存失败！请检查文件权限";
        }
    } elseif ($action === 'clear') {
        if (saveSecretsToConfig('', '', '') !== false) {
            $message = "✅ 敏感信息已清空";
            $currentUsername = '';
        } else {
            $message = "❌ 清空失败！请检查文件权限";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factorio 敏感信息管理 - [ieac]</title>
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
        .container { max-width: 800px; margin: 0 auto; }
        header { text-align: center; margin-bottom: 30px; }
        h1 { color: var(--danger); font-size: 28px; margin-bottom: 8px; }
        .subtitle { color: #777; font-size: 14px; }
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
        }
        .warning-box h3 { color: #856404; margin-bottom: 10px; font-size: 16px; }
        .warning-box ul { margin-left: 20px; color: #856404; font-size: 14px; }
        .warning-box li { margin: 5px 0; }
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
        .file-info {
            background: #e9ecef;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        .file-info code { background: #fff; padding: 2px 6px; }
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
        .nav-links { margin-bottom: 20px; }
        .nav-links a { color: var(--primary); text-decoration: none; font-size: 14px; }
        .nav-links a:hover { text-decoration: underline; }
        .secure-note {
            background: #e8f4fd;
            border-left: 4px solid #2196F3;
            padding: 10px 15px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #1565C0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="config-editor.php">← 返回配置编辑器</a>
        </div>

        <header>
            <h1>🔐 敏感信息管理</h1>
            <div class="subtitle">Factorio 服务器认证信息配置</div>
        </header>

        <div class="warning-box">
            <h3>⚠️ 安全警告</h3>
            <ul>
                <li>此页面包含敏感信息，请确保在安全环境下操作</li>
                <li>密码和令牌不会显示当前值，留空则保留原值</li>
                <li>修改后请立即测试服务器连接是否正常</li>
            </ul>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message <?= strpos($message, '✅') !== false ? 'success' : 'error' ?>">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <h2>配置文件信息</h2>
            <div class="file-info">
                <strong>配置文件：</strong><code><?= htmlspecialchars($configFile) ?></code><br>
                <strong>文件状态：</strong><?= file_exists($configFile) ? '✅ 存在' : '❌ 不存在' ?>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="update">
            
            <div class="section">
                <h2>Factorio.com 认证信息</h2>
                
                <div class="secure-note">
                    密码和令牌字段留空将保留原有值，不会显示当前存储的内容。
                </div>
                
                <div class="form-group">
                    <label for="username">Factorio.com 用户名</label>
                    <input type="text" id="username" name="username" maxlength="100"
                           value="<?= htmlspecialchars($currentUsername, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="输入您的 Factorio.com 用户名">
                    <div class="note">用于公开服务器列表认证</div>
                </div>
                
                <div class="form-group">
                    <label for="password">Factorio.com 密码（留空保留原值）</label>
                    <input type="password" id="password" name="password" maxlength="100"
                           placeholder="输入新密码（留空不修改）">
                    <div class="note">密码不会显示，输入新值将覆盖原密码</div>
                </div>
                
                <div class="form-group">
                    <label for="token">认证令牌（留空保留原值）</label>
                    <input type="text" id="token" name="token" maxlength="100"
                           placeholder="输入新令牌（留空不修改）">
                    <div class="note">可在 Factorio 官网账户设置中获取，使用令牌比密码更安全</div>
                </div>
            </div>

            <div class="button-group">
                <button type="submit" class="btn-primary">💾 保存敏感信息</button>
                <button type="button" class="btn-secondary" onclick="location.href='config-editor.php'">返回配置编辑器</button>
            </div>
        </form>

        <form method="post" style="margin-top: 20px;" onsubmit="return confirm('确定要清空所有敏感信息吗？此操作不可恢复！');">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="btn-danger" style="width: 100%;">🗑️ 清空所有敏感信息</button>
        </form>

        <footer>
            © 2025 [ieac] Factorio 服务器管理面板 | 
            QQ群: <a href="https://jq.qq.com/?_wv=1027&k=1137842268" target="_blank">1137842268</a>
        </footer>
    </div>
</body>
</html>
