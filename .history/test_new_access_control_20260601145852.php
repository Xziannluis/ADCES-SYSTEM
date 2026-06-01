<?php
require_once 'config/database.php';
require_once 'includes/program_assignments.php';

$database = new Database();
$db = $database->getConnection();

echo "=== Testing New Coordinator Access Control ===\n\n";

// Simulate Marlon's session
$marlon_id = 65;
$marlon_role = 'chairperson';
$marlon_dept = 'CCIS';

echo "1. Marlon's Session Info:\n";
echo "   ID: $marlon_id\n";
echo "   Role: $marlon_role\n";
echo "   Department: $marlon_dept\n\n";

// Check if Marlon is assigned to Lani Jane
echo "2. Check if Marlon is assigned to Lani Jane (teacher_id: 47):\n";
$ta_stmt = $db->prepare("SELECT * FROM teacher_assignments WHERE evaluator_id = ? AND teacher_id = 47");
$ta_stmt->execute([$marlon_id]);
$assignment = $ta_stmt->fetch(PDO::FETCH_ASSOC);

if ($assignment) {
    echo "   ✓ Marlon IS assigned to Lani Jane (ID: " . $assignment['id'] . ")\n";
} else {
    echo "   ✗ Marlon is NOT assigned to Lani Jane\n";
}

echo "\n3. Simulating NEW coordinator observation_plan.php query:\n";
echo "   Query: SELECT teachers FROM evaluations\n";
echo "           JOIN teacher_assignments ON evaluator_id = Marlon's ID\n";

$sim_query = "
    SELECT DISTINCT t.id, t.name, t.department
    FROM teachers t
    JOIN evaluations e ON e.teacher_id = t.id
    JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = ?
    WHERE t.status = 'active'
      AND e.academic_year = '2026-2027'
      AND e.semester = '1st'
";

$sim_stmt = $db->prepare($sim_query);
$sim_stmt->execute([$marlon_id]);
$sim_results = $sim_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "   Result for Marlon (ID: $marlon_id):\n";
if (empty($sim_results)) {
    echo "   → NO teachers shown (Marlon not assigned to any)\n";
} else {
    foreach ($sim_results as $row) {
        echo "   → Teacher: " . $row['name'] . " (" . $row['department'] . ")\n";
    }
}

echo "\n4. Comparison with OLD access pattern:\n";
echo "   OLD: Show ALL teachers in CCIS department\n";
$old_query = "SELECT DISTINCT t.id, t.name, t.department FROM teachers t WHERE t.department = ?";
$old_stmt = $db->prepare($old_query);
$old_stmt->execute(['CCIS']);
$old_results = $old_stmt->fetchAll(PDO::FETCH_ASSOC);
echo "   OLD Result: " . count($old_results) . " CCIS teachers visible to any CCIS coordinator\n";

echo "   NEW: Show ONLY assigned teachers\n";
echo "   NEW Result: " . count($sim_results) . " teachers visible to Marlon\n";

echo "\n5. Security Assessment:\n";
if (empty($sim_results) && !empty($old_results)) {
    echo "   ✓ NEW ACCESS CONTROL WORKING!\n";
    echo "   ✓ Marlon can no longer see unassigned teachers\n";
} else if (!empty($sim_results)) {
    echo "   ⚠ Marlon can see teachers (likely because he's assigned to them)\n";
} else {
    echo "   ✓ No teachers visible (either no assignments or no evaluations)\n";
}

echo "\n=== Test Complete ===\n";
?>
