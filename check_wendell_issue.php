<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== Checking Current State of Reginald's Evaluations ===\n\n";

// Get all evaluations for Reginald
$stmt = $conn->prepare('
    SELECT e.id, e.evaluator_id, e.department, e.observation_date, e.observation_room, e.subject_observed, 
           e.subject_area, e.status, u.name as evaluator_name, u.department as eval_dept, u.role,
           ts.id as sched_id, ts.scheduled_department, ts.scheduled_by
    FROM evaluations e
    JOIN users u ON u.id = e.evaluator_id
    LEFT JOIN teacher_schedules ts ON ts.evaluation_id = e.id
    WHERE e.teacher_id = 43
    ORDER BY e.observation_date DESC
');
$stmt->execute();
$evals = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "All Evaluations for Reginald:\n";
foreach ($evals as $eval) {
    echo "\nEval ID: {$eval['id']}\n";
    echo "  Date: {$eval['observation_date']}, Status: {$eval['status']}\n";
    echo "  Evaluator: {$eval['evaluator_name']} ({$eval['role']} in {$eval['eval_dept']})\n";
    echo "  Eval Dept: {$eval['department']}\n";
    echo "  Room: {$eval['observation_room']}\n";
    echo "  Schedule: ID={$eval['sched_id']}, Dept={$eval['scheduled_department']}\n";
}

echo "\n\n=== Check if there are orphaned records ===\n";

// Check teacher_schedules
$stmt2 = $conn->prepare('SELECT id, evaluation_id, scheduled_department, scheduled_by FROM teacher_schedules WHERE teacher_id = 43 AND academic_year = "2025-2026" AND semester = "1st"');
$stmt2->execute();
$scheds = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "\nTeacher Schedules:\n";
foreach ($scheds as $s) {
    echo "  Schedule {$s['id']}: eval_id={$s['evaluation_id']}, dept={$s['scheduled_department']}\n";
}

// Look for Wendell users
echo "\n\n=== Search for Wendell ===\n";
$stmt3 = $conn->prepare("SELECT id, name, role, department FROM users WHERE name LIKE '%Wendell%'");
$stmt3->execute();
$wendells = $stmt3->fetchAll(PDO::FETCH_ASSOC);
foreach ($wendells as $w) {
    echo "{$w['id']}: {$w['name']} ({$w['role']}) in {$w['department']}\n";
}
?>
