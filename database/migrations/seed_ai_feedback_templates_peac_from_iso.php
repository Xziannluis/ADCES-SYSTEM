<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();
if (!$db) {
    throw new RuntimeException('Database connection failed while seeding PEAC templates.');
}

// Ensure form_type exists.
$hasFormType = $db->query("SHOW COLUMNS FROM ai_feedback_templates LIKE 'form_type'")->fetch(PDO::FETCH_ASSOC);
if (!$hasFormType) {
    throw new RuntimeException("Column 'form_type' does not exist in ai_feedback_templates. Run migrate_ai_feedback_templates_form_type_strict.php first.");
}

$isoCount = (int)$db->query("SELECT COUNT(*) FROM ai_feedback_templates WHERE form_type = 'iso' AND is_active = 1")->fetchColumn();
$peacCount = (int)$db->query("SELECT COUNT(*) FROM ai_feedback_templates WHERE form_type = 'peac' AND is_active = 1")->fetchColumn();

echo "Before seeding: iso={$isoCount}, peac={$peacCount}\n";

if ($isoCount <= 0) {
    throw new RuntimeException('No ISO templates found to clone.');
}

if ($peacCount > 0) {
    echo "PEAC templates already exist. No clone needed.\n";
} else {
    $inserted = $db->exec("
        INSERT INTO ai_feedback_templates
            (field_name, evaluation_comment, feedback_text, embedding_vector, source, is_active, form_type)
        SELECT
            field_name, evaluation_comment, feedback_text, embedding_vector, source, is_active, 'peac'
        FROM ai_feedback_templates
        WHERE form_type = 'iso' AND is_active = 1
    ");
    echo "Cloned rows to PEAC: " . (int)$inserted . "\n";
}

// Ensure strict lookup index exists.
$idx = $db->query("SHOW INDEX FROM ai_feedback_templates WHERE Key_name = 'idx_ai_feedback_form_field_active'")->fetch(PDO::FETCH_ASSOC);
if (!$idx) {
    $db->exec("CREATE INDEX idx_ai_feedback_form_field_active ON ai_feedback_templates (form_type, field_name, is_active)");
    echo "Added index: idx_ai_feedback_form_field_active\n";
} else {
    echo "Index already exists: idx_ai_feedback_form_field_active\n";
}

$isoCountAfter = (int)$db->query("SELECT COUNT(*) FROM ai_feedback_templates WHERE form_type = 'iso' AND is_active = 1")->fetchColumn();
$peacCountAfter = (int)$db->query("SELECT COUNT(*) FROM ai_feedback_templates WHERE form_type = 'peac' AND is_active = 1")->fetchColumn();
echo "After seeding: iso={$isoCountAfter}, peac={$peacCountAfter}\n";

echo "PEAC fallback seed complete.\n";

