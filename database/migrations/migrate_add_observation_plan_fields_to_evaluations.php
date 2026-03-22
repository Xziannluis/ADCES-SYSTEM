<?php
/**
 * Migration: Add observation_room and subject_area columns to evaluations table.
 * These fields preserve the teacher's schedule data after evaluation submission,
 * since the teacher's schedule fields are cleared upon completion.
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $columns = [
        'observation_room' => "ALTER TABLE `evaluations` ADD COLUMN `observation_room` VARCHAR(100) NULL DEFAULT NULL AFTER `observation_type`",
        'subject_area'     => "ALTER TABLE `evaluations` ADD COLUMN `subject_area` VARCHAR(255) NULL DEFAULT NULL AFTER `observation_room`",
    ];

    $existing = $db->query('SHOW COLUMNS FROM evaluations');
    $existingCols = array_column($existing->fetchAll(PDO::FETCH_ASSOC), 'Field');

    foreach ($columns as $col => $sql) {
        if (in_array($col, $existingCols, true)) {
            echo "Column '$col' already exists in evaluations table.\n";
        } else {
            $db->exec($sql);
            echo "Added '$col' column to evaluations table.\n";
        }
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
