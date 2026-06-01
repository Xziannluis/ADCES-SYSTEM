<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

echo "Checking Reginald Ryan Gosela and Lani Jane Lumogda:\n";
$query = "SELECT id, name, evaluation_schedule, evaluation_schedule_end FROM teachers WHERE name LIKE '%Gosela%' OR name LIKE '%Lumogda%'";
$stmt = $db->prepare($query);
$stmt->execute();
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($teachers as $t) {
    echo "\nTeacher: " . $t['name'] . " (ID: " . $t['id'] . ")\n";
    echo "  evaluation_schedule: " . ($t['evaluation_schedule'] ?? 'NULL') . "\n";
    echo "  evaluation_schedule_end: " . ($t['evaluation_schedule_end'] ?? 'NULL') . "\n";
    
    // Now check their evaluations
    $evQuery = "SELECT id, observation_date, observation_time FROM evaluations WHERE teacher_id = ?";
    $evStmt = $db->prepare($evQuery);
    $evStmt->execute([$t['id']]);
    $evals = $evStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  Evaluations: " . count($evals) . "\n";
    foreach ($evals as $e) {
        echo "    - Date: " . $e['observation_date'] . ", Time: " . $e['observation_time'] . "\n";
    }
}



