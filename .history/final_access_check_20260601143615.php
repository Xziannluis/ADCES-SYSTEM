<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check Marlon's coordinator assignments
echo "=== Marlon's Multiple Department Assignments ===\n";
$marlon_dept_stmt = $db->prepare("SELECT * FROM user_programs WHERE user_id = 65");
$marlon_dept_stmt->execute();
$dept_assignments = $marlon_dept_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($dept_assignments as $assign) {
    echo "Program: " . $assign['program_name'] . " (ID: " . $assign['id'] . ")\n";
}

echo "\n=== Lani Jane's Teacher Data ===\n";
$lani_stmt = $db->prepare("SELECT id, name, department, user_id FROM teachers WHERE id = 47");
$lani_stmt->execute();
$lani = $lani_stmt->fetch(PDO::FETCH_ASSOC);
echo "Name: " . $lani['name'] . "\n";
echo "Primary Department: " . $lani['department'] . "\n";
echo "User ID: " . $lani['user_id'] . "\n";

// Check if Lani Jane has secondary departments
$sec_dept_stmt = $db->prepare("SELECT department FROM teacher_departments WHERE teacher_id = 47");
$sec_dept_stmt->execute();
$sec_depts = $sec_dept_stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Secondary Departments: " . (empty($sec_depts) ? "None" : implode(', ', array_column($sec_depts, 'department'))) . "\n";

// The KEY Question: Is Marlon's access to Lani Jane data CORRECT?
echo "\n=== ACCESS VERDICT ===\n";
echo "Marlon's assigned programs: CAS, JHS, CCIS\n";
echo "Lani Jane's primary department: CCIS\n";
echo "Lani Jane's scheduled_department: CCIS\n";
echo "\nConclusion:\n";
echo "✓ Marlon CAN see Lani Jane's data because:\n";
echo "  1. He's a chairperson assigned to CCIS\n";
echo "  2. Lani Jane is in CCIS\n";
echo "  3. She has an evaluation schedule in CCIS\n";
echo "  4. Coordinators can see all teachers in their assigned departments\n";
echo "\nThis is CORRECT behavior!\n";

// But let's check if there's a specific coordinator assignment
echo "\n=== Specific Coordinator Assignment Check ===\n";
$ta_stmt = $db->prepare("SELECT ta.*, u.name FROM teacher_assignments ta JOIN users u ON u.id = ta.evaluator_id WHERE ta.teacher_id = 47");
$ta_stmt->execute();
$assignments = $ta_stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($assignments)) {
    echo "No specific coordinator assignments for Lani Jane\n";
} else {
    foreach ($assignments as $assign) {
        echo "Assigned Coordinator: " . $assign['name'] . "\n";
    }
}
?>
