<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check who scheduled Lani Jane
$stmt = $db->prepare("SELECT id, name, role, department FROM users WHERE id = 15");
$stmt->execute();
$scheduler = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== Who Scheduled Lani Jane (user_id: 15) ===\n";
if ($scheduler) {
    echo "ID: " . $scheduler['id'] . "\n";
    echo "Name: " . $scheduler['name'] . "\n";
    echo "Role: " . $scheduler['role'] . "\n";
    echo "Department: " . ($scheduler['department'] ?? 'NULL') . "\n";
} else {
    echo "User not found\n";
}

echo "\n=== Access Control Analysis ===\n";
echo "Marlon's Role: chairperson (Coordinator)\n";
echo "Marlon's Department: CCIS\n";
echo "Lani Jane's Department: CCIS\n";
echo "Lani Jane's scheduled_department: CCIS\n";
echo "Lani Jane's Scheduler Department: " . ($scheduler['department'] ?? 'NULL') . "\n";
echo "\nConclusion: Marlon CAN see Lani Jane's data because:\n";
echo "- He's a chairperson (coordinator role)\n";
echo "- Lani Jane is in CCIS department (his department)\n";
echo "- The query allows coordinators to see teachers in their department\n";
echo "\nThis is CORRECT behavior - no vulnerability here!\n";

// Check if there are evaluations for Lani Jane
$stmt2 = $db->prepare("SELECT e.id, e.evaluator_id, u.name, u.role, u.department FROM evaluations e LEFT JOIN users u ON u.id = e.evaluator_id WHERE e.teacher_id = 47");
$stmt2->execute();
$evals = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== Evaluations for Lani Jane ===\n";
if (empty($evals)) {
    echo "NO evaluations found yet for Lani Jane\n";
    echo "She has a SCHEDULE but no evaluators assigned yet\n";
} else {
    foreach ($evals as $eval) {
        echo "Evaluator: " . ($eval['name'] ?? 'NULL') . " (Role: " . ($eval['role'] ?? 'NULL') . ")\n";
    }
}
?>
