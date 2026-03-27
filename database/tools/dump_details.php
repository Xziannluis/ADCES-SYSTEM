<?php
$c = file_get_contents('c:/Users/HP/Downloads/ai_classroom_eval-5.sql');

// 1. Check evaluations columns in dump vs current
echo "=== EVALUATIONS TABLE - DUMP COLUMNS ===\n";
preg_match('/CREATE TABLE `evaluations` \((.+?)\) ENGINE/s', $c, $m);
$cols = array_map('trim', explode("\n", trim($m[1])));
foreach ($cols as $col) {
    echo "  $col\n";
}

// 2. Extract evaluation_criteria seed data
echo "\n=== EVALUATION_CRITERIA SEED DATA ===\n";
preg_match('/INSERT INTO `evaluation_criteria`.*?VALUES\s*\n(.+?);/s', $c, $m);
if (!empty($m[1])) {
    echo $m[1] . "\n";
} else {
    echo "NO SEED DATA FOUND\n";
}

// 3. Extract notifications seed data count
preg_match_all('/INSERT INTO `notifications`/', $c, $m);
echo "\n=== NOTIFICATIONS INSERT COUNT: " . count($m[0]) . " ===\n";

// 4. Check observation_plan_acknowledgments data
preg_match('/INSERT INTO `observation_plan_acknowledgments`.*?VALUES\s*\n(.+?);/s', $c, $m);
echo "\n=== OBSERVATION_PLAN_ACKNOWLEDGMENTS SEED ===\n";
if (!empty($m[1])) {
    echo substr($m[1], 0, 500) . "\n";
} else {
    echo "NO SEED DATA FOUND\n";
}

// 5. Extract teacher_department_roles structure if present
preg_match('/CREATE TABLE `teacher_department_roles` \((.+?)\) ENGINE/s', $c, $m);
echo "\n=== TEACHER_DEPARTMENT_ROLES IN DUMP ===\n";
if (!empty($m[1])) {
    echo $m[1] . "\n";
} else {
    echo "NOT IN DUMP\n";
}

// 6. Extract ai_reference_evaluations structure if present
preg_match('/CREATE TABLE `ai_reference_evaluations` \((.+?)\) ENGINE/s', $c, $m);
echo "\n=== AI_REFERENCE_EVALUATIONS IN DUMP ===\n";
if (!empty($m[1])) {
    echo $m[1] . "\n";
} else {
    echo "NOT IN DUMP\n";
}

// 7. Count total INSERT statements per table
echo "\n=== SEED DATA SUMMARY ===\n";
$tables = ['evaluation_criteria', 'evaluations', 'users', 'teachers', 'ai_feedback_templates', 'notifications', 'observation_plan_acknowledgments', 'audit_logs', 'form_settings'];
foreach ($tables as $t) {
    preg_match_all("/INSERT INTO `$t`/", $c, $m);
    echo "$t: " . count($m[0]) . " INSERT statements\n";
}
