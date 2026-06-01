<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check what the print query is returning for Reginald
$query = "SELECT DISTINCT t.id, t.name, t.evaluation_schedule, t.evaluation_schedule_end,
          e.id as eval_id, e.observation_date, e.observation_time
          FROM teachers t
          JOIN evaluations e ON e.teacher_id = t.id
          WHERE t.id = 43 AND e.academic_year = '2025-2026' AND e.semester = '1st'
          ORDER BY e.observation_date";
$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Query results for Reginald (this is what print uses):\n";
foreach ($results as $row) {
    echo "EvalID " . $row['eval_id'] . ": ";
    echo "ObsDate=" . $row['observation_date'] . ", ";
    echo "ObsTime=" . $row['observation_time'] . ", ";
    echo "SchedEnd=" . ($row['evaluation_schedule_end'] ?? 'NULL') . "\n";
}





