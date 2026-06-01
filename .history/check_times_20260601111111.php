<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();
$query = 'SELECT id, observation_date, observation_time, evaluation_schedule, evaluation_schedule_end FROM evaluations WHERE id <= 5 LIMIT 5';
$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo "ID: " . $row['id'] . "\n";
    echo "  observation_date: " . ($row['observation_date'] ?? 'NULL') . "\n";
    echo "  observation_time: " . ($row['observation_time'] ?? 'NULL') . "\n";
    echo "  evaluation_schedule: " . ($row['evaluation_schedule'] ?? 'NULL') . "\n";
    echo "  evaluation_schedule_end: " . ($row['evaluation_schedule_end'] ?? 'NULL') . "\n\n";
}
