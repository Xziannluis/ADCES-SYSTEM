<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

$stmt = $db->query('SHOW COLUMNS FROM evaluations');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'], PHP_EOL;
}
