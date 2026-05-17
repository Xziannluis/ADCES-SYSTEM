<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();
if (!$db) {
    throw new RuntimeException('Database connection failed while updating ai_feedback_templates.');
}

// Ensure form_type column exists.
$hasFormType = $db->query("SHOW COLUMNS FROM ai_feedback_templates LIKE 'form_type'")->fetch(PDO::FETCH_ASSOC);
if (!$hasFormType) {
    $db->exec("ALTER TABLE ai_feedback_templates ADD COLUMN form_type VARCHAR(10) NULL AFTER field_name");
    echo "Added column: form_type\n";
} else {
    echo "Column already exists: form_type\n";
}

// Backfill null/empty form_type as ISO to avoid mixed retrieval.
$updated = $db->exec("UPDATE ai_feedback_templates
                      SET form_type = 'iso'
                      WHERE form_type IS NULL OR TRIM(form_type) = ''");
echo "Backfilled rows to iso: " . (int)$updated . "\n";

// Normalize values.
$db->exec("UPDATE ai_feedback_templates SET form_type = LOWER(TRIM(form_type))");
$db->exec("UPDATE ai_feedback_templates SET form_type = 'iso' WHERE form_type NOT IN ('iso','peac')");
echo "Normalized form_type values.\n";

// Helpful index for strict retrieval.
$idx = $db->query("SHOW INDEX FROM ai_feedback_templates WHERE Key_name = 'idx_ai_feedback_form_field_active'")->fetch(PDO::FETCH_ASSOC);
if (!$idx) {
    $db->exec("CREATE INDEX idx_ai_feedback_form_field_active ON ai_feedback_templates (form_type, field_name, is_active)");
    echo "Added index: idx_ai_feedback_form_field_active\n";
} else {
    echo "Index already exists: idx_ai_feedback_form_field_active\n";
}

echo "Migration complete.\n";

