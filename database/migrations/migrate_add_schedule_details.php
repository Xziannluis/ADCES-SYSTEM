<?php
/**
 * Migration: Add focus-of-observation, subject area, and subject columns to teachers table.
 * These store the schedule details set by the evaluator when scheduling an observation.
 *
 * evaluation_focus       — JSON array of selected focus categories
 *                          e.g. ["communications","management"]
 * evaluation_subject_area — Subject area (e.g., Social Sciences, English)
 * evaluation_subject      — Specific subject (e.g., GEC 9 – Ethics)
 */

require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

$columns = [
    'evaluation_focus'        => "ALTER TABLE `teachers` ADD COLUMN `evaluation_focus` TEXT NULL DEFAULT NULL AFTER `evaluation_room`",
    'evaluation_subject_area' => "ALTER TABLE `teachers` ADD COLUMN `evaluation_subject_area` VARCHAR(255) NULL DEFAULT NULL AFTER `evaluation_focus`",
    'evaluation_subject'      => "ALTER TABLE `teachers` ADD COLUMN `evaluation_subject` VARCHAR(255) NULL DEFAULT NULL AFTER `evaluation_subject_area`",
    'evaluation_semester'     => "ALTER TABLE `teachers` ADD COLUMN `evaluation_semester` VARCHAR(10) NULL DEFAULT NULL AFTER `evaluation_subject`",
];

foreach ($columns as $col => $sql) {
    try {
        $check = $db->query("SHOW COLUMNS FROM `teachers` LIKE '{$col}'");
        if ($check->rowCount() === 0) {
            $db->exec($sql);
            echo "Added column: {$col}\n";
        } else {
            echo "Column already exists: {$col}\n";
        }
    } catch (PDOException $e) {
        echo "Error adding {$col}: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration complete.\n";
