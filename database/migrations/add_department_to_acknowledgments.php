<?php
/**
 * Migration: Add department column to observation_plan_acknowledgments table
 * Makes signatures department-aware for multi-department teachers.
 */
require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Check if column already exists
    $cols = $db->query("SHOW COLUMNS FROM observation_plan_acknowledgments LIKE 'department'");
    if ($cols->rowCount() === 0) {
        $db->exec("ALTER TABLE observation_plan_acknowledgments ADD COLUMN department VARCHAR(100) DEFAULT NULL COMMENT 'Department this signature is for' AFTER semester");
        $db->exec("DROP INDEX idx_opa_lookup ON observation_plan_acknowledgments");
        $db->exec("CREATE INDEX idx_opa_lookup ON observation_plan_acknowledgments (teacher_id, academic_year, semester, department)");
        echo "SUCCESS: Added department column to observation_plan_acknowledgments.\n";
    } else {
        echo "SKIP: department column already exists.\n";
    }
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
