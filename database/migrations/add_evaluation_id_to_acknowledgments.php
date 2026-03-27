<?php
/**
 * Migration: Add evaluation_id column to observation_plan_acknowledgments
 * Allows per-schedule/per-evaluation signing instead of blanket semester signature.
 * evaluation_id = NULL means the upcoming schedule (from teachers table).
 * evaluation_id = <int> means a specific completed evaluation.
 */
require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Check if column already exists
    $cols = $db->query("SHOW COLUMNS FROM observation_plan_acknowledgments LIKE 'evaluation_id'")->fetchAll();
    if (count($cols) === 0) {
        $db->exec("ALTER TABLE observation_plan_acknowledgments ADD COLUMN evaluation_id INT NULL DEFAULT NULL COMMENT 'FK to evaluations.id; NULL = upcoming schedule' AFTER semester");
        $db->exec("ALTER TABLE observation_plan_acknowledgments ADD INDEX idx_opa_eval (teacher_id, academic_year, semester, evaluation_id)");
        echo "SUCCESS: Added evaluation_id column to observation_plan_acknowledgments.\n";
    } else {
        echo "SKIPPED: evaluation_id column already exists.\n";
    }
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
