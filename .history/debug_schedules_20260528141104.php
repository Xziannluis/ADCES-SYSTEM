<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check teacher_schedules for Reginald
$stmt = $conn->prepare('SELECT id, scheduled_by, scheduled_department, schedule_start, evaluation_id FROM teacher_schedules WHERE teacher_id = 43 AND academic_year = "2025-2026" AND semester = "1st"');
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== Teacher Schedules with scheduled_department ===\n";
foreach ($schedules as $s) {
    $s2 = $conn->prepare('SELECT name, department FROM users WHERE id = :id');
    $s2->execute([':id' => $s['scheduled_by']]);
    $user = $s2->fetch(PDO::FETCH_ASSOC);
    
    echo "Schedule {$s['id']}: scheduled_department='{$s['scheduled_department']}', scheduled_by={$user['name']} ({$user['department']}), eval_id={$s['evaluation_id']}\n";
}

// Also check if this teacher has assignments outside their department
echo "=== Teacher Assignments for Reginald ===\n";
$stmt = $conn->prepare('
    SELECT ta.id, u.name, u.department, u.role, ta.eval_id
    FROM teacher_assignments ta
    JOIN users u ON u.id = ta.evaluator_id
    WHERE ta.teacher_id = 43
');
$stmt->execute();
$assigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($assigns as $a) {
    $match = $a['department'] === 'CCIS' ? 'MATCH' : 'MISMATCH';
    echo "{$match}: {$a['name']} ({$a['role']}) in {$a['department']}\n";
}
?>
