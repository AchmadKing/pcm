<?php
require_once dirname(__DIR__) . '/config/database.php';

echo "=== USERS ===" . PHP_EOL;
$users = dbGetAll("SELECT id, username, full_name, role, is_active FROM users");
foreach ($users as $u) {
    echo $u['id'] . ' | ' . $u['username'] . ' | ' . $u['full_name'] . ' | ' . $u['role'] . ' | active:' . $u['is_active'] . PHP_EOL;
}

echo PHP_EOL . "=== ROLES ===" . PHP_EOL;
$roles = dbGetAll("SELECT * FROM roles");
foreach ($roles as $r) {
    echo $r['id'] . ' | ' . $r['name'] . ' | ' . $r['display_name'] . ' | system:' . $r['is_system'] . PHP_EOL;
}

echo PHP_EOL . "=== SUPER ADMIN PERMISSIONS ===" . PHP_EOL;
$perms = dbGetAll("SELECT rp.permission_key, rp.is_allowed FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.name = 'super_admin'");
foreach ($perms as $p) {
    echo $p['permission_key'] . ' => ' . ($p['is_allowed'] ? 'YES' : 'NO') . PHP_EOL;
}

echo PHP_EOL . "=== VERIFICATION COMPLETE ===" . PHP_EOL;
