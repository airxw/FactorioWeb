<?php

class SecureConfig
{
    private static $encryptionKey = null;
    private static $keyFile = __DIR__ . '/../config/system/.encryptionKey';
    
    public static function getEncryptionKey()
    {
        if (self::$encryptionKey !== null) {
            return self::$encryptionKey;
        }
        
        if (file_exists(self::$keyFile)) {
            self::$encryptionKey = file_get_contents(self::$keyFile);
            return self::$encryptionKey;
        }
        
        self::$encryptionKey = bin2hex(random_bytes(32));
        
        $configDir = dirname(self::$keyFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0750, true);
        }
        
        file_put_contents(self::$keyFile, self::$encryptionKey);
        chmod(self::$keyFile, 0600);
        
        return self::$encryptionKey;
    }
    
    public static function encrypt($data)
    {
        $key = self::getEncryptionKey();
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', hex2bin($key), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public static function decrypt($data)
    {
        if (empty($data)) {
            return '';
        }
        
        $key = self::getEncryptionKey();
        $data = base64_decode($data);
        
        if ($data === false || strlen($data) < 16) {
            return '';
        }
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', hex2bin($key), OPENSSL_RAW_DATA, $iv);
        
        return $decrypted === false ? '' : $decrypted;
    }
    
    public static function generateRconPassword($length = 24)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
    
    public static function validatePasswordStrength($password)
    {
        $result = [
            'valid' => true,
            'errors' => [],
            'score' => 0
        ];
        
        if (strlen($password) < 8) {
            $result['valid'] = false;
            $result['errors'][] = '密码长度至少8位';
        } else {
            $result['score'] += min(strlen($password) - 7, 3);
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $result['valid'] = false;
            $result['errors'][] = '密码必须包含小写字母';
        } else {
            $result['score'] += 1;
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $result['valid'] = false;
            $result['errors'][] = '密码必须包含大写字母';
        } else {
            $result['score'] += 1;
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $result['valid'] = false;
            $result['errors'][] = '密码必须包含数字';
        } else {
            $result['score'] += 1;
        }
        
        if (preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
            $result['score'] += 2;
        }
        
        return $result;
    }
    
    public static function saveRconConfig($configs)
    {
        $configFile = dirname(__DIR__) . '/config/system/rcon.php';
        $configDir = dirname($configFile);
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0750, true);
        }
        
        $configContent = "<?php\n\nreturn [\n";
        foreach ($configs as $id => $cfg) {
            $password = $cfg['rcon_password'] ?? '';
            $encryptedPassword = !empty($password) ? self::encrypt($password) : '';
            
            $configContent .= "    '$id' => [\n";
            $configContent .= "        'rcon_enabled' => " . ($cfg['rcon_enabled'] ? 'true' : 'false') . ",\n";
            $configContent .= "        'rcon_port' => " . intval($cfg['rcon_port']) . ",\n";
            $configContent .= "        'rcon_password_encrypted' => '" . addslashes($encryptedPassword) . "',\n";
            $configContent .= "        'rcon_host' => '" . addslashes($cfg['rcon_host'] ?? '127.0.0.1') . "',\n";
            $configContent .= "        'screen_name' => '" . addslashes($cfg['screen_name'] ?? 'factorio_server') . "',\n";
            $configContent .= "        'description' => '" . addslashes($cfg['description'] ?? $id) . "',\n";
            $configContent .= "    ],\n";
        }
        $configContent .= "];\n";
        
        $result = file_put_contents($configFile, $configContent) !== false;
        
        if ($result) {
            chmod($configFile, 0600);
        }
        
        return $result;
    }
    
    public static function loadRconConfig()
    {
        $configFile = dirname(__DIR__) . '/config/system/rcon.php';
        
        if (!file_exists($configFile)) {
            return [
                'default' => [
                    'rcon_enabled' => true,
                    'rcon_port' => 27015,
                    'rcon_password' => self::generateRconPassword(),
                    'rcon_host' => '127.0.0.1',
                    'screen_name' => 'factorio_server',
                    'description' => '默认服务端',
                ]
            ];
        }
        
        $configs = require $configFile;
        
        foreach ($configs as $id => &$cfg) {
            if (isset($cfg['rcon_password_encrypted']) && !empty($cfg['rcon_password_encrypted'])) {
                $cfg['rcon_password'] = self::decrypt($cfg['rcon_password_encrypted']);
            } elseif (isset($cfg['rcon_password'])) {
                $cfg['rcon_password'] = self::decrypt($cfg['rcon_password']);
            } else {
                $cfg['rcon_password'] = self::generateRconPassword();
            }
        }
        
        return $configs;
    }
    
    public static function createPasswordFile($serverId, $password)
    {
        $passwordFile = dirname(__DIR__, 2) . "/config/.rcon_pass_$serverId";
        $dir = dirname($passwordFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($passwordFile, $password);
        chmod($passwordFile, 0600);
        return $passwordFile;
    }

    public static function deletePasswordFile($serverId)
    {
        $passwordFile = dirname(__DIR__, 2) . "/config/.rcon_pass_$serverId";
        if (file_exists($passwordFile)) {
            unlink($passwordFile);
        }
    }
    
    public static function checkConfigSecurity()
    {
        $issues = [];
        $configDir = __DIR__ . '/config';
        
        if (!is_dir($configDir)) {
            return $issues;
        }
        
        $sensitiveFiles = [
            'rcon.php' => 0600,
            '.encryption_key' => 0600,
            'config.php' => 0600
        ];
        
        foreach ($sensitiveFiles as $file => $expectedPerms) {
            $filePath = "$configDir/$file";
            if (file_exists($filePath)) {
                $actualPerms = fileperms($filePath) & 0777;
                if ($actualPerms > $expectedPerms) {
                    $issues[] = [
                        'file' => $file,
                        'current' => decoct($actualPerms),
                        'expected' => decoct($expectedPerms),
                        'message' => "文件权限过于宽松，建议设置为 {$expectedPerms}"
                    ];
                }
            }
        }
        
        $parentDir = dirname($configDir);
        $dirPerms = fileperms($parentDir) & 0777;
        if ($dirPerms > 0755) {
            $issues[] = [
                'file' => 'web目录',
                'current' => decoct($dirPerms),
                'expected' => '755',
                'message' => 'web目录权限过于宽松'
            ];
        }
        
        return $issues;
    }
    
    public static function fixConfigSecurity()
    {
        $fixed = [];
        $configDir = __DIR__ . '/config';
        
        if (!is_dir($configDir)) {
            return $fixed;
        }
        
        $sensitiveFiles = [
            'rcon.php' => 0600,
            '.encryption_key' => 0600
        ];
        
        foreach ($sensitiveFiles as $file => $expectedPerms) {
            $filePath = "$configDir/$file";
            if (file_exists($filePath)) {
                $actualPerms = fileperms($filePath) & 0777;
                if ($actualPerms > $expectedPerms) {
                    chmod($filePath, $expectedPerms);
                    $fixed[] = $file;
                }
            }
        }
        
        return $fixed;
    }
}
