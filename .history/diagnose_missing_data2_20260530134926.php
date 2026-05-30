<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$academic_year = '2025-2026';
$semester = '1st';

// 1. Get Wendell's evaluation details
echo "<h2>Wendell B. Gonzaga's Evaluation Details</h2>";
$wendell_evals = $db->prepare("
    SELECT e.id, e.teacher_id, e.department, e.academic_year, e.semester, t.name as teacher_name, t.department as teacher_dept, t.scheduled_department
    FROM evaluations e
    JOIN teachers t ON t.id = e.teacher_id
    WHERE e.evaluator_id = 61 AND e.academic_year = ? AND e.semester = ?
");
$wendell_evals->execute([$academic_year, $semester]);
while ($row = $wendell_evals->fetch(PDO::FETCH_ASSOC)) {
    echo "Evaluation ID: {$row['id']}<br>";
    echo "Teacher: {$row['teacher_name']} (ID: {$row['teacher_id']})<br>";
    echo "Teacher Department: {$row['teacher_dept']}<br>";
    echo "Teacher Scheduled Department: {$row['scheduled_department']}<br>";
    echo "Evaluation Department: {$row['department']}<br>";
    echo "<hr>";
}

// 2. Check what the query in observation_plan.php would return for president viewing SHS
echo "<h2>What Observation Plan Query Returns (President/VP viewing SHS)</h2>";

// This simulates the query for president viewing SHS
$query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                 t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                 t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                 t.scheduled_by, t.scheduled_department,
                 e.id as eval_id, e.evaluator_id as eval_evaluator_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
                 e.subject_observed, e.observation_room as eval_room,
                 e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                 e.semester as eval_semester, e.department as eval_department
          FROM teachers t
          JOIN evaluations e ON e.teacher_id = t.id
          LEFT JOIN users eu ON eu.id = e.evaluator_id
          WHERE (
                (t.scheduled_department IS NOT NULL AND t.scheduled_department <> '' AND t.scheduled_department = 'SHS')
                OR
                ((t.scheduled_department IS NULL OR t.scheduled_department = '') AND t.department = 'SHS')
                OR
                (
                    e.evaluator_id = 61
                    AND e.department = 'SHS'
                    AND t.department = 'SHS'
                )
          )
          AND (t.user_id IS NULL OR t.user_id != 1)
          AND e.academic_year = '2025-2026'
          AND e.semester = '1st'
          ORDER BY t.name ASC";

$result = $db->query($query);
$rows = $result->fetchAll(PDO::FETCH_ASSOC);
echo "Query returns " . count($rows) . " rows:<br>";
foreach ($rows as $row) {
    echo "  - {$row['name']} (Dept: {$row['teacher_department']}, Sched Dept: {$row['scheduled_department']})<br>";
}

// 3. Check for department filtering issue
echo "<h2>Debugging Department Conditions</h2>";
$test_teacher = $db->prepare("SELECT * FROM teachers WHERE id = 1 LIMIT 1");
$test_teacher->execute();
$tt = $test_teacher->fetch(PDO::FETCH_ASSOC);
if ($tt) {
    echo "Sample teacher ID 1: {$tt['name']}<br>";
    echo "  Department: {$tt['department']}<br>";
    echo "  Scheduled Department: {$tt['scheduled_department']}<br>";
    echo "  User ID: {$tt['user_id']}<br>";
}

// 4. Check if maybe user_id filter is blocking it
echo "<h2>User ID Filter Check</h2>";
$teacher_with_user = $db->prepare("
    SELECT t.id, t.name, t.user_id, u.name as user_name, u.role
    FROM teachers t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.department = 'SHS'
    LIMIT 10
");
$teacher_with_user->execute();
echo $teacher_with_user->rowCount() . " SHS teachers. Details:<br>";
while ($row = $teacher_with_user->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['name']} | User: {$row['user_name']} ({$row['user_id']}) | Role: {$row['role']}<br>";
}
?>
