<?php
// Migration: add printed name fields to evaluations
// Run: php database/migrations/migrate_add_printed_names.php

require_once __DIR__ . '/../../config/database.php';

try {
    $db = (new Database())->getConnection();

    // Add columns if missing
    $cols = $db->query("SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'evaluations'
          AND COLUMN_NAME IN ('rater_printed_name','faculty_printed_name')")->fetchAll(PDO::FETCH_COLUMN);

    $missingRater = !in_array('rater_printed_name', $cols, true);
    $missingFaculty = !in_array('faculty_printed_name', $cols, true);

    if (!$missingRater && !$missingFaculty) {
        echo "Printed name columns already exist.\n";
        exit(0);
    }

    $alters = [];
    if ($missingRater) {
        $alters[] = "ADD COLUMN `rater_printed_name` VARCHAR(255) NULL AFTER `recommendations`";
    }
    if ($missingFaculty) {
        $alters[] = "ADD COLUMN `faculty_printed_name` VARCHAR(255) NULL AFTER `rater_date`";
    }

    $sql = "ALTER TABLE `evaluations`\n" . implode(",\n", $alters);
    $db->exec($sql);

    echo "Migration complete. Added columns: " . implode(', ', array_filter([
        $missingRater ? 'rater_printed_name' : null,
        $missingFaculty ? 'faculty_printed_name' : null,
    ])) . "\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
