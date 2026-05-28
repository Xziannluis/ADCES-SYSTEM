<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== Checking if Evaluation 103 department is correct ===\n\n";

$stmt = $conn->prepare('
    SELECT e.id, e.teacher_id, e.department, e.evaluator_id, e.observation_date, e.observation_room, e.subject_observed,
           t.name as teacher_name, t.department as teacher_dept,
           u.name as evaluator_name, u.department as eval_dept, u.role
    FROM evaluations e
    JOIN teachers t ON t.id = e.teacher_id
    JOIN users u ON u.id = e.evaluator_id
    WHERE e.id = 103
');
$stmt->execute();
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Evaluation 103:\n";
echo "  Teacher: {$eval['teacher_name']} (primary dept: {$eval['teacher_dept']})\n";
echo "  Evaluator: {$eval['evaluator_name']} ({$eval['role']} in {$eval['eval_dept']})\n";
echo "  Evaluation recorded in department: {$eval['department']}\n";
echo "  Observation: {$eval['observation_date']} at {$eval['observation_room']}\n\n";

// Check what other evaluations exist for this teacher
echo "=== All evaluations for {$eval['teacher_name']} ===\n";
$stmt2 = $conn->prepare('
    SELECT e.id, e.department, e.observation_date, u.name as evaluator_name, u.role
    FROM evaluations e
    JOIN users u ON u.id = e.evaluator_id
    WHERE e.teacher_id = 43 AND e.academic_year = "2025-2026" AND e.semester = "1st"
    ORDER BY e.observation_date DESC
');
$stmt2->execute();
$evals = $stmt2->fetchAll(PDO::FETCH_ASSOC);

foreach ($evals as $e) {
    $match = $e['department'] === $eval['teacher_dept'] ? '✓' : 'X';
    echo "$match Eval {$e['id']}: {$e['evaluator_name']} ({$e['role']}) in {$e['department']}, date: {$e['observation_date']}\n";
}

echo "\n=== Recommendation ===\n";
if ($eval['department'] !== $eval['teacher_dept']) {
    echo "⚠ Evaluation 103 is marked as {$eval['department']}, but teacher is in {$eval['teacher_dept']}.\n";
    echo "This might be intentional if {$eval['evaluator_name']} is from {$eval['eval_dept']} and is evaluating across departments.\n";
    echo "However, if this was created in error, it should be deleted or corrected.\n";
}
?>
