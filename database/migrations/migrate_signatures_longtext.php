<?php
// Migration: make evaluations.rater_signature and evaluations.faculty_signature LONGTEXT
// Run: php database/migrations/migrate_signatures_longtext.php

require_once __DIR__ . '/../../config/database.php';

try {
    $db = (new Database())->getConnection();

    // Check existing column types
    $stmt = $db->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'evaluations'
          AND COLUMN_NAME IN ('rater_signature', 'faculty_signature')");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$cols) {
        echo "No signature columns found on evaluations table.\n";
        exit(0);
    }

    // Update columns to LONGTEXT
    $db->exec("ALTER TABLE `evaluations`
        MODIFY `rater_signature` LONGTEXT NULL,
        MODIFY `faculty_signature` LONGTEXT NULL");

    // Re-check
    $stmt->execute();
    $after = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Migration complete. Updated column types:\n";
    foreach ($after as $c) {
        echo "- {$c['COLUMN_NAME']}: {$c['COLUMN_TYPE']}\n";
    }
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
