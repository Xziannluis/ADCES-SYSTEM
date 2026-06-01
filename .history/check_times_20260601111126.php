<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();
// First check what columns exist in evaluations
$query = "SHOW COLUMNS FROM evaluations";
$stmt = $db->prepare($query);
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Evaluations table columns:\n";
foreach ($columns as $col) {
    echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
}
echo "\n\nSample evaluation data:\n";
$query = "SELECT id, observation_date, observation_time FROM evaluations LIMIT 3";
$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo "ID: " . $row['id'] . ", Date: " . ($row['observation_date'] ?? 'NULL') . ", Time: " . ($row['observation_time'] ?? 'NULL') . "\n";
}

