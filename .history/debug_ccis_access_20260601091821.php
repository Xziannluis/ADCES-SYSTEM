<?php
/**
 * Debug script to check why SHS data appears for CCIS coordinator account
 */
require_once 'auth/session-check.php';
require_once 'config/database.php';
require_once 'includes/program_assignments.php';

$database = new Database();
$db = $database->getConnection();

echo "<h2>Debug: Department Access Control</h2>";
echo "<p>Current User ID: " . $_SESSION['user_id'] . "</p>";
echo "<p>Current Role: " . $_SESSION['role'] . "</p>";
echo "<p>Session Department: " . ($_SESSION['department'] ?? 'NOT SET') . "</p>";

if (!in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator', 'dean', 'principal'])) {
    echo "<p style='color:red;'>This script is for coordinators/deans only.</p>";
    exit;
}

// Check assigned programs
$programs = resolveEvaluatorPrograms($db, $_SESSION['user_id'], $_SESSION['department'] ?? '');
echo "<h3>Assigned Programs:</h3>";
echo "<pre>";
print_r($programs);
echo "</pre>";

// Check raw data from evaluator_assignments table
echo "<h3>Raw Evaluator Assignments:</h3>";
$stmt = $db->prepare("
    SELECT ea.*, u.department as user_department
    FROM evaluator_assignments ea
    LEFT JOIN users u ON u.id = ea.evaluator_id
    WHERE ea.evaluator_id = :user_id
");
$stmt->execute([':user_id' => $_SESSION['user_id']]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($assignments)) {
    echo "<p style='color:red;'>No evaluator assignments found!</p>";
    echo "<p>This is likely the cause - if no assignments exist, the system may default to showing all departments.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Program</th><th>Supervisor ID</th></tr>";
    foreach ($assignments as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . ($row['program'] ?? 'NULL') . "</td>";
        echo "<td>" . $row['supervisor_id'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Check what teachers appear in print query for this department
echo "<h3>Teachers That Would Appear in Print (Department: " . ($_SESSION['department'] ?? 'ALL') . "):</h3>";

$print_dept = trim((string)($_GET['department'] ?? $_SESSION['department'] ?? ''));

if ($print_dept) {
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department, e.department as eval_department
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              WHERE (
                    (
                        t.scheduled_department IS NOT NULL
                        AND t.scheduled_department <> ''
                        AND t.scheduled_department = :department_sched
                    )
                    OR
                    (
                        (t.scheduled_department IS NULL OR t.scheduled_department = '')
                        AND t.department = :department_primary
                    )
              )
              ORDER BY t.name
              LIMIT 20";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':department_sched' => $print_dept,
        ':department_primary' => $print_dept
    ]);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Teacher</th><th>Primary Department</th><th>Evaluation Department</th></tr>";
    foreach ($teachers as $t) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($t['name']) . "</td>";
        echo "<td>" . htmlspecialchars($t['teacher_department']) . "</td>";
        echo "<td>" . htmlspecialchars($t['eval_department']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<p>Total: " . count($teachers) . " teachers</p>";
} else {
    echo "<p style='color:orange;'>No department specified.</p>";
}
?>
