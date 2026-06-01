<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

echo "Teachers table columns (schedule-related):\n";
$query = "SHOW COLUMNS FROM teachers LIKE 'evaluation%'";
$stmt = $db->prepare($query);
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
}

echo "\n\nTeacher schedule data (where scheduled):\n";
$query = "SELECT id, name, evaluation_schedule, evaluation_schedule_end FROM teachers WHERE evaluation_schedule IS NOT NULL LIMIT 3";
$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo "ID: " . $row['id'] . ", Name: " . $row['name'] . "\n";
    echo "  Start: " . ($row['evaluation_schedule'] ?? 'NULL') . "\n";
    echo "  End: " . ($row['evaluation_schedule_end'] ?? 'NULL') . "\n";
}


