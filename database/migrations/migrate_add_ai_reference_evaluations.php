<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

$sql = "
CREATE TABLE IF NOT EXISTS ai_reference_evaluations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    evaluation_id INT NOT NULL,
    faculty_name VARCHAR(255) DEFAULT '',
    department VARCHAR(100) DEFAULT '',
    subject_observed VARCHAR(255) DEFAULT '',
    observation_type VARCHAR(100) DEFAULT '',
    communications_avg DECIMAL(4,2) DEFAULT 0.00,
    management_avg DECIMAL(4,2) DEFAULT 0.00,
    assessment_avg DECIMAL(4,2) DEFAULT 0.00,
    overall_avg DECIMAL(4,2) DEFAULT 0.00,
    ratings_json LONGTEXT NOT NULL,
    strengths TEXT NOT NULL,
    improvement_areas TEXT NOT NULL,
    recommendations TEXT NOT NULL,
    source VARCHAR(50) DEFAULT 'live-submit',
    source_evaluation_id INT DEFAULT NULL,
    reference_created_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_reference_evaluation (evaluation_id),
    KEY idx_ai_reference_department (department),
    KEY idx_ai_reference_source (source),
    KEY idx_ai_reference_created_at (created_at),
    CONSTRAINT fk_ai_reference_evaluations_evaluation
        FOREIGN KEY (evaluation_id) REFERENCES evaluations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

$db->exec($sql);
echo "ai_reference_evaluations table is ready.\n";
