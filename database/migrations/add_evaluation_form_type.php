<?php
/**
 * Migration: Add evaluation_form_type column to teachers and evaluations tables
 * Values: 'iso' (default) or 'peac'
 */
require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Add to teachers table (schedule-level setting)
    $check = $db->query("SHOW COLUMNS FROM teachers LIKE 'evaluation_form_type'");
    if ($check->rowCount() === 0) {
        $db->exec("ALTER TABLE teachers ADD COLUMN evaluation_form_type VARCHAR(10) DEFAULT 'iso' AFTER evaluation_semester");
        echo "Added evaluation_form_type to teachers table.\n";
    } else {
        echo "Column evaluation_form_type already exists in teachers table.\n";
    }

    // Add to evaluations table (record-level)
    $check2 = $db->query("SHOW COLUMNS FROM evaluations LIKE 'evaluation_form_type'");
    if ($check2->rowCount() === 0) {
        $db->exec("ALTER TABLE evaluations ADD COLUMN evaluation_form_type VARCHAR(10) DEFAULT 'iso' AFTER evaluation_focus");
        echo "Added evaluation_form_type to evaluations table.\n";
    } else {
        echo "Column evaluation_form_type already exists in evaluations table.\n";
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
