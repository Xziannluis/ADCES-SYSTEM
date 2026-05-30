<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$academic_year = '2025-2026';
$semester = '1st';
$raw_department = 'SHS';
$current_user_id = 61; // Wendell

// Test the FIXED query
$query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                 t.evaluation_schedule, t.scheduled_department,
                 e.id as eval_id, e.department as eval_department
          FROM teachers t
          JOIN evaluations e ON e.teacher_id = t.id
          WHERE (
                (t.scheduled_department IS NOT NULL AND t.scheduled_department <> '' AND t.scheduled_department = ?)
                OR
                ((t.scheduled_department IS NULL OR t.scheduled_department = '') AND t.department = ?)
                OR
                (
                    e.evaluator_id = ?
                    AND e.department = ?
                )
          )
          AND (t.user_id IS NULL OR t.user_id != ?)
          AND e.academic_year = ?
          AND e.semester = ?
          ORDER BY t.name ASC";

$stmt = $db->prepare($query);
$stmt->execute([
    $raw_department,      // dept2
    $raw_department,      // dept3
    $current_user_id,     // self_eval_id
    $raw_department,      // self_eval_dept
    $current_user_id,     // current_user_id
    $academic_year,
    $semester
]);

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Fixed Query Results for Wendell B. Gonzaga</h2>";
echo "Query returns: " . count($results) . " rows<br><br>";

if (count($results) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Teacher Name</th><th>Teacher Dept</th><th>Eval ID</th><th>Eval Dept</th><th>Status</th></tr>";
    foreach ($results as $row) {
        $status = ($row['teacher_department'] !== 'SHS') ? 'CROSS-DEPARTMENT' : 'Same Dept';
        echo "<tr>";
        echo "<td>" . $row['name'] . "</td>";
        echo "<td>" . $row['teacher_department'] . "</td>";
        echo "<td>" . $row['eval_id'] . "</td>";
        echo "<td>" . $row['eval_department'] . "</td>";
        echo "<td><strong>$status</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<br><strong>✓ SUCCESS: Data is now showing! Cross-departmental evaluation is included.</strong>";
} else {
    echo "<strong>✗ FAILED: Still no results</strong>";
}
?>
