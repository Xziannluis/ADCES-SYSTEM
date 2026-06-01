<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check Marlon's program assignments
echo "=== Marlon's Program Assignments (via resolveEvaluatorPrograms) ===\n";
$ea_stmt = $db->prepare("SELECT DISTINCT program FROM evaluator_assignments WHERE evaluator_id = 65 AND program IS NOT NULL AND program <> ''");
$ea_stmt->execute();
$programs = $ea_stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Programs from evaluator_assignments: " . implode(', ', $programs) . "\n";

// Also check his primary department
$user_dept_stmt = $db->prepare("SELECT department FROM users WHERE id = 65");
$user_dept_stmt->execute();
$primary_dept = $user_dept_stmt->fetchColumn();
echo "Primary Department from users table: " . $primary_dept . "\n";

echo "\n=== Lani Jane's Teacher Data ===\n";
$lani_stmt = $db->prepare("SELECT id, name, department, user_id, scheduled_department FROM teachers WHERE id = 47");
$lani_stmt->execute();
$lani = $lani_stmt->fetch(PDO::FETCH_ASSOC);
echo "Name: " . $lani['name'] . "\n";
echo "Primary Department: " . $lani['department'] . "\n";
echo "Scheduled Department: " . ($lani['scheduled_department'] ?? 'NULL') . "\n";
echo "User ID: " . $lani['user_id'] . "\n";

// Check if Lani Jane has secondary departments
$sec_dept_stmt = $db->prepare("SELECT department FROM teacher_departments WHERE teacher_id = 47");
$sec_dept_stmt->execute();
$sec_depts = $sec_dept_stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Secondary Departments: " . (empty($sec_depts) ? "None" : implode(', ', array_column($sec_depts, 'department'))) . "\n";

echo "\n=== VERDICT: Is Marlon's Access CORRECT? ===\n";

// Reconstruct what resolveEvaluatorPrograms would return
$all_progs = [];
if (!empty($programs)) {
    $all_progs = array_merge($all_progs, $programs);
}
if (!empty($primary_dept) && !in_array($primary_dept, $all_progs)) {
    $all_progs[] = $primary_dept;
}

echo "\nMarlon's Accessible Programs/Departments:\n";
foreach ($all_progs as $p) {
    echo "  - " . $p . "\n";
}

echo "\nLani Jane's Department: " . $lani['department'] . "\n";
echo "Lani Jane's Scheduled Department: " . ($lani['scheduled_department'] ?? $lani['department']) . "\n";

$lani_dept = $lani['scheduled_department'] !== null && $lani['scheduled_department'] !== '' ? $lani['scheduled_department'] : $lani['department'];

if (in_array($lani_dept, $all_progs)) {
    echo "\n✓ MARLON CAN CORRECTLY ACCESS THIS DATA\n";
    echo "  Reason: Lani Jane is in a department ($lani_dept) that Marlon is assigned to\n";
} else {
    echo "\n✗ MARLON SHOULD NOT HAVE ACCESS\n";
    echo "  Reason: Lani Jane is in $lani_dept but Marlon is only assigned to: " . implode(', ', $all_progs) . "\n";
}

// Additional info
echo "\n=== ACTUAL QUESTION INTERPRETATION ===\n";
echo "The user asked: 'why he saw the data... even his not the coordinator of lumogda'\n";
echo "\nPossible interpretations:\n";
echo "1. Marlon shouldn't have access (INCORRECT - he's assigned to her department)\n";
echo "2. Marlon is only supposed to see HIS ASSIGNED teachers (DEPENDS ON BUSINESS LOGIC)\n";
echo "3. There's a deeper issue with how 'coordinator' is defined\n";

?>
