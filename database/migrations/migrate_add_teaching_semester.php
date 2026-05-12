<?php
/**
 * Migration: Add teaching_semester column to teachers table.
 * Tracks which semester(s) a teacher is actively teaching.
 * Values: '1st', '2nd', 'Both', or NULL (not yet set).
 */

require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $check = $db->query("SHOW COLUMNS FROM `teachers` LIKE 'teaching_semester'");
    if ($check->rowCount() === 0) {
        $db->exec("ALTER TABLE `teachers` ADD COLUMN `teaching_semester` VARCHAR(10) NULL DEFAULT NULL AFTER `status`");
        echo "Added column: teaching_semester\n";
    } else {
        echo "Column already exists: teaching_semester\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nMigration complete.\n";
