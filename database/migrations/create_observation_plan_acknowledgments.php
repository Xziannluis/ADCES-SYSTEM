<?php
/**
 * Migration: Create observation_plan_acknowledgments table
 * Tracks when a teacher acknowledges/signs their observation plan.
 */
require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

$sql = "CREATE TABLE IF NOT EXISTS observation_plan_acknowledgments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL COMMENT 'FK to teachers.id',
    academic_year VARCHAR(20) NOT NULL COMMENT 'e.g. 2025-2026',
    semester VARCHAR(10) NOT NULL COMMENT '1st or 2nd',
    acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    signature LONGTEXT DEFAULT NULL COMMENT 'Base64 signature image if captured',
    INDEX idx_opa_teacher (teacher_id),
    INDEX idx_opa_lookup (teacher_id, academic_year, semester),
    CONSTRAINT fk_opa_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

try {
    $db->exec($sql);
    echo "SUCCESS: observation_plan_acknowledgments table created.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
