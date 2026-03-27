<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

// Tables to check
$tables = [
    'users', 'teachers', 'teacher_departments', 'evaluator_assignments',
    'evaluator_subjects', 'evaluator_grade_levels', 'teacher_assignments',
    'evaluations', 'evaluation_details', 'evaluation_criteria',
    'ai_recommendations', 'ai_reference_evaluations', 'audit_logs',
    'notifications', 'observation_plan_acknowledgments', 'ai_feedback_templates',
    'teacher_department_roles', 'form_settings'
];

foreach ($tables as $table) {
    try {
        $stmt = $db->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        echo "=== $table ===\n";
        echo $row[1] . "\n\n";
    } catch (Exception $e) {
        echo "=== $table === NOT FOUND IN DB\n\n";
    }
}

// Also show seed data counts
echo "\n=== ROW COUNTS ===\n";
foreach ($tables as $table) {
    try {
        $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "$table: $count rows\n";
    } catch (Exception $e) {
        echo "$table: ERROR\n";
    }
}

// Check evaluation_criteria seed data
echo "\n=== evaluation_criteria DATA ===\n";
$rows = $db->query("SELECT * FROM evaluation_criteria ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo count($rows) . " rows\n";
foreach ($rows as $r) {
    echo "  [{$r['id']}] {$r['category']} #{$r['criterion_index']}: " . substr($r['criterion_text'], 0, 80) . "\n";
}
