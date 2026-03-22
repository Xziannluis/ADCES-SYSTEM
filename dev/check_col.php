<?php
require_once 'config/database.php';
$d = new Database();
$db = $d->getConnection();
$r = $db->query("SHOW COLUMNS FROM teachers LIKE 'teaching_semester'");
echo $r->rowCount() > 0 ? 'EXISTS' : 'NOT EXISTS';
