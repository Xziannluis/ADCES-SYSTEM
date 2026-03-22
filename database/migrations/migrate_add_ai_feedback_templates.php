<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

if (!$db) {
    throw new RuntimeException('Database connection failed while creating ai_feedback_templates table.');
}

$sql = "
CREATE TABLE IF NOT EXISTS ai_feedback_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    field_name VARCHAR(64) NOT NULL,
    evaluation_comment TEXT NOT NULL,
    feedback_text TEXT NOT NULL,
    embedding_vector LONGBLOB NOT NULL,
    source VARCHAR(64) DEFAULT 'seed',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ai_feedback_templates_field_name (field_name),
    KEY idx_ai_feedback_templates_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

$db->exec($sql);

try {
    $db->exec("ALTER TABLE ai_feedback_templates MODIFY embedding_vector LONGBLOB NOT NULL");
} catch (Throwable $e) {
    // Keep migration idempotent for existing environments.
}

echo "ai_feedback_templates table is ready.\n";