<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_GET['user_id'] ?? 1; // Default: show first user
$academic_year = $_GET['ay'] ?? '2025-2026';
$semester = $_GET['sem'] ?? '1st';
$department = $_GET['dept'] ?? 'SHS';

echo "<h2>Diagnostic Report</h2>";

// 1. Get current user info
$user_stmt = $db->prepare("SELECT id, name, role, department FROM users WHERE id = ? LIMIT 1");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);
echo "<h3>Current User:</h3>";
echo "<pre>" . print_r($user, true) . "</pre>";

// 2. Count evaluations in system for this AY/Semester/Department
echo "<h3>Total Evaluations in {$department} ({$academic_year} {$semester}):</h3>";
$eval_stmt = $db->prepare("
    SELECT COUNT(*) as count FROM evaluations e
    JOIN users u ON u.id = e.evaluator_id
    WHERE e.academic_year = ? AND e.semester = ? AND u.department = ?
");
$eval_stmt->execute([$academic_year, $semester, $department]);
$eval_count = $eval_stmt->fetch(PDO::FETCH_ASSOC);
echo "Total: " . $eval_count['count'] . "<br>";

// 3. Evaluations by evaluator
echo "<h3>Evaluations by Evaluator:</h3>";
$by_eval_stmt = $db->prepare("
    SELECT u.name, u.id, COUNT(e.id) as eval_count
    FROM evaluations e
    JOIN users u ON u.id = e.evaluator_id
    WHERE e.academic_year = ? AND e.semester = ? AND u.department = ?
    GROUP BY e.evaluator_id, u.name, u.id
    ORDER BY eval_count DESC
");
$by_eval_stmt->execute([$academic_year, $semester, $department]);
while ($row = $by_eval_stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['name'] . " (ID: {$row['id']}): {$row['eval_count']} evaluations<br>";
}

// 4. Teachers with schedules
echo "<h3>Teachers with Schedules ({$department}):</h3>";
$sched_stmt = $db->prepare("
    SELECT t.id, t.name, t.department, t.evaluation_schedule, t.evaluation_semester, t.scheduled_by
    FROM teachers t
    WHERE (t.department = ? OR t.scheduled_department = ?)
    AND t.evaluation_schedule IS NOT NULL
    AND t.status = 'active'
    LIMIT 10
");
$sched_stmt->execute([$department, $department]);
echo $sched_stmt->rowCount() . " teachers with schedules<br>";
while ($row = $sched_stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['name']} scheduled: {$row['evaluation_schedule']}<br>";
}

// 5. Specifically for Wendell B. Gonzaga
echo "<h3>For Wendell B. Gonzaga (if exists):</h3>";
$wendell_stmt = $db->prepare("SELECT id, name, role, department FROM users WHERE name LIKE '%Wendell%' OR name LIKE '%Gonzaga%'");
$wendell_stmt->execute();
$wendell = $wendell_stmt->fetch(PDO::FETCH_ASSOC);
if ($wendell) {
    echo "Found: " . $wendell['name'] . " (ID: {$wendell['id']}, Role: {$wendell['role']}, Dept: {$wendell['department']})<br>";
    
    // Check evaluations assigned to Wendell
    $wendell_eval = $db->prepare("
        SELECT COUNT(*) as count FROM evaluations
        WHERE evaluator_id = ? AND academic_year = ? AND semester = ?
    ");
    $wendell_eval->execute([$wendell['id'], $academic_year, $semester]);
    $we = $wendell_eval->fetch(PDO::FETCH_ASSOC);
    echo "Wendell's evaluations ({$academic_year} {$semester}): {$we['count']}<br>";
} else {
    echo "Wendell not found in users table<br>";
}

// 6. Find evaluator with most evaluations for comparison
echo "<h3>Evaluator with Most Evaluations (for comparison):</h3>";
$max_eval = $db->prepare("
    SELECT u.name, u.id, COUNT(e.id) as eval_count
    FROM evaluations e
    JOIN users u ON u.id = e.evaluator_id
    WHERE e.academic_year = ? AND e.semester = ?
    GROUP BY e.evaluator_id, u.name, u.id
    ORDER BY eval_count DESC
    LIMIT 1
");
$max_eval->execute([$academic_year, $semester]);
$top_eval = $max_eval->fetch(PDO::FETCH_ASSOC);
if ($top_eval) {
    echo $top_eval['name'] . ": {$top_eval['eval_count']} evaluations<br>";
    // Show their departments
    $dept_stmt = $db->prepare("
        SELECT DISTINCT u.department FROM evaluations e
        JOIN users u ON u.id = e.evaluator_id
        WHERE e.evaluator_id = ? AND e.academic_year = ? AND e.semester = ?
    ");
    $dept_stmt->execute([$top_eval['id'], $academic_year, $semester]);
    echo "Their departments: ";
    while ($d = $dept_stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $d['department'] . " ";
    }
    echo "<br>";
}
?>
