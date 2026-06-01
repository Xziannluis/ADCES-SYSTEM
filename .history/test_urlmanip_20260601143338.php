<?php
require_once 'config/database.php';
require_once 'includes/program_assignments.php';

$database = new Database();
$db = $database->getConnection();

// Simulate Marlon's session
$marlon_id = 65;
$marlon_role = 'chairperson';
$marlon_dept = 'CCIS';

// Get Marlon's assigned programs
$programs = resolveEvaluatorPrograms($db, $marlon_id, $marlon_dept);
echo "=== Marlon's Assigned Programs ===\n";
print_r($programs);

// Simulate what happens when Marlon tries to access different departments via URL
$all_departments = ['ELEM', 'JHS', 'SHS', 'CCIS', 'CAS', 'CTEAS', 'CBM', 'CTHM', 'CCJE'];

echo "\n=== Access Control Check ===\n";
echo "Marlon's Session Department: $marlon_dept\n";
echo "Marlon's Role: $marlon_role\n";

// Build available filter departments (same logic as observation_plan.php)
$available_filter_departments = [];
$programs_list = resolveEvaluatorPrograms($db, $marlon_id, $marlon_dept);
foreach ($programs_list as $p) {
    $p = trim((string)$p);
    if (in_array($p, $all_departments, true) && !in_array($p, $available_filter_departments, true)) {
        $available_filter_departments[] = $p;
    }
}
if (empty($available_filter_departments) && $marlon_dept !== '' && in_array($marlon_dept, $all_departments, true)) {
    $available_filter_departments[] = $marlon_dept;
}

echo "\nAvailable Filter Departments: " . implode(', ', $available_filter_departments) . "\n";

// Test URL manipulation - try each department
echo "\n=== Testing URL Department Parameter Manipulation ===\n";
foreach ($all_departments as $test_dept) {
    $requested = $test_dept;
    $guarded = guardCoordinatorDepartment($requested, $available_filter_departments, $marlon_dept);
    $can_access = $guarded !== '' ? "✓ CAN ACCESS" : "✗ BLOCKED";
    echo "$test_dept: $can_access (guard result: '$guarded')\n";
}

// Test what teachers Marlon could see
echo "\n=== Testing Teachers Visibility ===\n";
echo "Testing if Marlon can see teachers from non-CCIS departments:\n";

// Get a teacher from CAS department
$cas_teacher = $db->prepare("SELECT id, name, department FROM teachers WHERE department = 'CAS' AND status = 'active' LIMIT 1");
$cas_teacher->execute();
$cas_t = $cas_teacher->fetch(PDO::FETCH_ASSOC);

if ($cas_t) {
    echo "\nCAS Teacher: " . $cas_t['name'] . " (ID: " . $cas_t['id'] . ")\n";
    
    // Check if this teacher has a schedule in a way Marlon could accidentally see
    $check_query = "SELECT t.id, t.name, t.department, t.evaluation_schedule, t.scheduled_department
                    FROM teachers t
                    WHERE t.id = ? AND t.status = 'active' AND t.evaluation_schedule IS NOT NULL";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([$cas_t['id']]);
    $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($check_result) {
        echo "This teacher has a schedule!\n";
        echo "If Marlon tries to filter by CAS: " . ($guarded_cas = guardCoordinatorDepartment('CAS', $available_filter_departments, $marlon_dept)) . "\n";
        echo "Result: " . ($guarded_cas === '' ? "✓ Properly blocked" : "✗ VULNERABILITY - Can access!") . "\n";
    } else {
        echo "This teacher has no schedule (would need manual URL manipulation to even try)\n";
    }
}
?>
