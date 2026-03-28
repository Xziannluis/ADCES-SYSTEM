<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$s = $db->query("SELECT id, name, role, department FROM users WHERE role IN ('president','vice_president') AND status='active'");
echo "=== President / Vice President ===\n";
foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ID={$r['id']} | {$r['name']} | {$r['role']} | dept={$r['department']}\n";
}
