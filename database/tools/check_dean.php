<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();
$s = $db->query("SELECT id, username, name, role, department FROM users WHERE role='dean'");
while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
    echo "id={$r['id']} username={$r['username']} name={$r['name']} dept={$r['department']}\n";
}
