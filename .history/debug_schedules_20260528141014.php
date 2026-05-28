<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check teacher_schedules for Reginald
$stmt = $conn->prepare('SELECT * FROM teacher_schedules WHERE teacher_id = 43 AND academic_year = "2025-2026" AND semester = "1st" ORDER BY observation_date DESC');
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== CCIS Schedules for Reginald (2025-2026, 1st Semester) ===\n\n";
foreach ($schedules as $s) {
    echo "Schedule ID: {$s['id']}, Date: {$s['observation_date']}, Department: {$s['department']}\n";
    echo "  Room: {$s['observation_room']}, Subject: {$s['subject']}, Department field: {$s['department']}\n";
    
    if ($s['scheduled_by']) {
        $s2 = $conn->prepare('SELECT name, department FROM users WHERE id = :id');
        $s2->execute([':id' => $s['scheduled_by']]);
        $user = $s2->fetch(PDO::FETCH_ASSOC);
        echo "  Scheduled by: {$user['name']} ({$user['department']})\n";
    }
    echo "\n";
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
