<?php
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$db = $database->getConnection();

try {
    // Check if column exists
    $cols = $db->query("SHOW COLUMNS FROM teachers LIKE 'scheduled_by'");
    if ($cols->rowCount() === 0) {
        $db->exec("ALTER TABLE teachers ADD COLUMN scheduled_by INT NULL DEFAULT NULL AFTER evaluation_form_type");
        echo "Column 'scheduled_by' added to teachers table.\n";
    } else {
        echo "Column 'scheduled_by' already exists.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
