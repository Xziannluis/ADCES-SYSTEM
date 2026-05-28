<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== Search for 'Oncarga' ===\n";
$stmt = $conn->prepare("SELECT * FROM users WHERE name LIKE '%Oncarga%' OR name LIKE '%oncarga%'");
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "No 'Oncarga' found in users table\n\n";
} else {
    foreach ($results as $r) {
        echo json_encode($r) . "\n";
    }
}

// Maybe it's in teacher_assignments or evaluations as text
echo "\n=== Search evaluations table for 'Oncarga' ===\n";
$stmt = $conn->prepare("SELECT * FROM evaluations WHERE rater_printed_name LIKE '%Oncarga%'");
$stmt->execute();
$evals = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($evals as $e) {
    echo "Eval {$e['id']}: rater={$e['rater_printed_name']}\n";
}

// Check observation_plan query to see how observers are populated
echo "\n=== Checking how Reginald's evaluations are being queried ===\n";
echo "Running the query from observation_plan.php...\n\n";

// This mimics the query from observation_plan.php
$is_leader = true; // Simulating leader (dean viewing CCIS)

if ($is_leader) {
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester, e.department as eval_department,
                     t.scheduled_by, t.scheduled_department
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              LEFT JOIN users eu ON eu.id = e.evaluator_id
              WHERE e.academic_year = :academic_year
              AND e.semester = :semester
              AND e.teacher_id = 43
              ORDER BY t.name ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute(['academic_year' => '2025-2026', 'semester' => '1st']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query Result for Reginald:\n";
    foreach ($rows as $row) {
        echo "  Row: Teacher={$row['name']}, Eval ID={$row['eval_id']}, Date={$row['observation_date']}, Status={$row['eval_status']}\n";
    }
}
?>
