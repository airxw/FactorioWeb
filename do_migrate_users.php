<?php
require_once __DIR__ . '/web/app/autoload.php';
$d = App\Core\Database::getInstance();
$d->initialize();
$c = require __DIR__ . '/web/config/system/auth.php';
$users = $c['users'] ?? [];
foreach ($users as $u => $data) {
    $ex = $d->query('SELECT id FROM users WHERE username = ?', [$u]);
    if (empty($ex)) {
        $d->execute('INSERT INTO users(username,password_hash,role,name,vip_level,created_at,updated_at) VALUES(?,?,?,?,0,?,?)',
            [$u, $data['password'] ?? '', $data['role'] ?? 'user', $data['name'] ?? $u, time(), time()]);
        echo 'INSERT ' . $u . ' -> ' . $d->lastInsertId() . "\n";
    } else {
        $d->execute('UPDATE users SET password_hash=?,role=?,name=?,updated_at=? WHERE username=?',
            [$data['password'] ?? '', $data['role'] ?? 'user', $data['name'] ?? $u, time(), $u]);
        echo 'UPDATE ' . $u . "\n";
    }
}
echo "---\n";
$r = $d->query('SELECT id,username,name,role,vip_level FROM users ORDER BY id');
foreach ($r as $row) printf("[%d] %s (%s) %s VIP%d\n", $row['id'], $row['username'], $row['name'] ?? '-', $row['role'], $row['vip_level'] ?? 0);
