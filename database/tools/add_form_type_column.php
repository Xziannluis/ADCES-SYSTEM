<?php
/**
 * Add form_type column to ai_feedback_templates and tag existing rows.
 * Run once: php database/tools/add_form_type_column.php
 */
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

// Check if form_type column exists
$cols = $db->query('SHOW COLUMNS FROM ai_feedback_templates LIKE "form_type"')->fetchAll();
if (count($cols) === 0) {
    $db->exec('ALTER TABLE ai_feedback_templates ADD COLUMN form_type VARCHAR(10) DEFAULT NULL AFTER feedback_text');
    $db->exec('CREATE INDEX idx_form_type ON ai_feedback_templates (form_type)');
    echo "Added form_type column.\n";
} else {
    echo "form_type column already exists.\n";
}

// Tag PEAC templates by keyword detection
$peac_updated = $db->exec("UPDATE ai_feedback_templates SET form_type = 'peac' WHERE form_type IS NULL AND (
    evaluation_comment LIKE '%PEAC%'
    OR evaluation_comment LIKE '%unit standards and competencies%'
    OR evaluation_comment LIKE '%PVMGO%'
    OR evaluation_comment LIKE '%teacher action%'
    OR evaluation_comment LIKE '%student learning action%'
    OR evaluation_comment LIKE '%21st century%'
    OR evaluation_comment LIKE '%21st-century%'
    OR feedback_text LIKE '%PEAC%'
    OR feedback_text LIKE '%unit standards and competencies%'
    OR feedback_text LIKE '%PVMGO%'
    OR feedback_text LIKE '%teacher action%'
    OR feedback_text LIKE '%student learning action%'
    OR feedback_text LIKE '%21st century%'
    OR feedback_text LIKE '%21st-century%'
)");
echo "Tagged {$peac_updated} rows as PEAC.\n";

// Tag remaining as ISO
$iso_updated = $db->exec("UPDATE ai_feedback_templates SET form_type = 'iso' WHERE form_type IS NULL");
echo "Tagged {$iso_updated} rows as ISO.\n";

// Show counts
$counts = $db->query('SELECT form_type, COUNT(*) as cnt FROM ai_feedback_templates GROUP BY form_type')->fetchAll(PDO::FETCH_ASSOC);
foreach ($counts as $c) {
    echo $c['form_type'] . ': ' . $c['cnt'] . " rows\n";
}
