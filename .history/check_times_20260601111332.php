<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

echo "Checking Reginald Ryan Gosela's evaluations in detail:\n";
$query = "SELECT id, observation_date, observation_time FROM evaluations WHERE teacher_id = 43 ORDER BY observation_date";
$stmt = $db->prepare($query);
$stmt->execute();
$evals = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($evals as $e) {
    echo "Eval ID " . $e['id'] . ": Date=" . $e['observation_date'] . ", Time=" . $e['observation_time'] . "\n";
    // Check if time contains a dash (range)
    if (strpos($e['observation_time'], '-') !== false) {
        echo "  → Contains range: " . $e['observation_time'] . "\n";
    }
}

echo "\n\nChecking all observation_time values to see if any have ranges:\n";
$query = "SELECT COUNT(*) as count FROM evaluations WHERE observation_time LIKE '%-% ' OR observation_time LIKE '%:%-%:%'";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Observations with time ranges: " . $result['count'] . "\n";

// Sample some
$query = "SELECT id, observation_date, observation_time FROM evaluations WHERE observation_time LIKE '%:%-%:%' LIMIT 3";
$stmt = $db->prepare($query);
$stmt->execute();
$samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($samples) > 0) {
    foreach ($samples as $s) {
        echo "  - ID " . $s['id'] . ": " . $s['observation_time'] . "\n";
    }
}




