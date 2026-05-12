<?php
/**
 * Migration: Add evaluation_focus column to evaluations table.
 * Stores the JSON array of focus categories that were active during the evaluation
 * (e.g. ["communications","management"]).  NULL means all categories were evaluated.
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // Check if column already exists
    $check = $db->query("SHOW COLUMNS FROM evaluations LIKE 'evaluation_focus'");
    if ($check && $check->fetch()) {
        echo "Column 'evaluation_focus' already exists in evaluations table.\n";
    } else {
        $db->exec("ALTER TABLE evaluations ADD COLUMN evaluation_focus TEXT NULL DEFAULT NULL AFTER observation_type");
        echo "Added 'evaluation_focus' column to evaluations table.\n";
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
