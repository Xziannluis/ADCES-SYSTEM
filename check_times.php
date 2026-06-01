<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check what academic_year the evaluations have
echo "Distinct academic_years in evaluations table:\n";
$query = "SELECT DISTINCT academic_year FROM evaluations WHERE teacher_id = 43 ORDER BY academic_year DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo "- " . $row['academic_year'] . "\n";
}

echo "\n\nEvaluations for Reginald with their academic_years:\n";
$query = "SELECT id, academic_year, semester, observation_date, observation_time FROM evaluations WHERE teacher_id = 43 ORDER BY observation_date";
$stmt = $db->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo "ID " . $row['id'] . ": " . $row['observation_date'] . " at " . $row['observation_time'] . " (academic_year: " . $row['academic_year'] . ", semester: " . $row['semester'] . ")\n";
}







