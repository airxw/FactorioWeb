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
        'admin' => [
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'role' => 'admin',
            'name' => '管理员'
        ]
    ],

    /**
     * 会话配置
     */
    'session_expiry' => 86400,

    /**
     * 默认登录信息（仅供参考，不用于实际验证）
     * 实际密码请使用 password-tool.html 生成哈希
     */
    'default_username' => 'admin',
    'default_password' => 'password'
];
