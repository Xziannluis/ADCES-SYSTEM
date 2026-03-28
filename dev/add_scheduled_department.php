<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

$cols = $db->query("SHOW COLUMNS FROM teachers LIKE 'scheduled_department'");
if ($cols->rowCount() > 0) {
    echo "Column scheduled_department already exists.\n";
} else {
    $db->exec("ALTER TABLE teachers ADD COLUMN scheduled_department VARCHAR(100) DEFAULT NULL AFTER scheduled_by");
    echo "Column scheduled_department added successfully.\n";
}

// Show all columns
echo "\nAll columns:\n";
$r = $db->query("SHOW COLUMNS FROM teachers");
while ($c = $r->fetch(PDO::FETCH_ASSOC)) {
    echo $c['Field'] . ' | ' . $c['Type'] . "\n";
}
