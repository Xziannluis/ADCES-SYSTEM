<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "=== Finding which schedule matches the screenshot observation ===\n";
echo "(Looking for: 2026-05-29, T-104, Computing/TVL)\n\n";

// The evaluation 103 has the matching data
$stmt = $conn->prepare('
    SELECT e.id as eval_id, e.observation_date, e.observation_room, e.subject_observed, e.subject_area, e.department, e.evaluator_id, u.name as evaluator_name,
           ts.id as schedule_id, ts.scheduled_by, ts.scheduled_department, u2.name as scheduled_by_name
    FROM evaluations e
    LEFT JOIN teacher_schedules ts ON ts.evaluation_id = e.id
    LEFT JOIN users u ON u.id = e.evaluator_id
    LEFT JOIN users u2 ON u2.id = ts.scheduled_by
    WHERE e.teacher_id = 43 AND e.academic_year = "2025-2026" AND e.semester = "1st"
    ORDER BY e.observation_date DESC
');
$stmt->execute();
$evals = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($evals as $e) {
    $match = ($e['observation_date'] == '2026-05-29' && $e['observation_room'] == 'T-104') ? '>>> MATCH <<<' : '';
    echo "Eval {$e['eval_id']}: Date={$e['observation_date']}, Room={$e['observation_room']}, Subject={$e['subject_observed']}/{$e['subject_area']}\n";
    echo "  Department: {$e['department']}, Evaluator: {$e['evaluator_name']}\n";
    echo "  Schedule: {$e['schedule_id']}, Scheduled by: {$e['scheduled_by_name']} in {$e['scheduled_department']}\n";
    echo "  $match\n\n";
}

echo "\n=== PROBLEM ===\n";
echo "The observation from Wendell B. Gonzaga (SHS) at 2026-05-29 T-104 is being shown as a CCIS observation\n";
echo "This happens because the observation_plan.php/observation_plan_print.php is showing evaluations\n";
echo "but the system is pulling the wrong set of observers.\n\n";

echo "=== THE FIX ===\n";
echo "Option 1: Delete incorrect teacher_assignments (April T. Olmedo from Reginald)\n";
echo "Option 2: Delete the SHS schedules (8 and 11) if they were created in error\n";
echo "Option 3: Filter SHS evaluations out when viewing CCIS observations\n\n";

echo "=== Checking teacher_assignments that need fixing ===\n";
$stmt = $conn->prepare('
    SELECT ta.id, ta.teacher_id, ta.evaluator_id, u.name, u.department, u.role
    FROM teacher_assignments ta
    JOIN users u ON u.id = ta.evaluator_id
    WHERE ta.teacher_id = 43 AND u.department != "CCIS"
');
$stmt->execute();
$bad_assigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($bad_assigns as $a) {
    echo "DELETE ID {$a['id']}: {$a['name']} ({$a['role']} in {$a['department']}) - SHOULD NOT BE ASSIGNED TO REGINALD (CCIS)\n";
}
?>
