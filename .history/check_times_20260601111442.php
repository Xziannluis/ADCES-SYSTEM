<?php
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

// Simulating what the print page does
$academic_year = $_GET['academic_year'] ?? '';
$semester = $_GET['semester'] ?? '1st';

if (empty($academic_year)) {
    $month = (int)date('n');
    $year = (int)date('Y');
    if ($month >= 6) {
        $academic_year = $year . '-' . ($year + 1);
    } else {
        $academic_year = ($year - 1) . '-' . $year;
    }
}

echo "Academic Year: " . $academic_year . "\n";
echo "Semester: " . $semester . "\n\n";

// Now check evaluations with these filters
$query = "SELECT e.id, e.teacher_id, t.name, e.academic_year, e.semester, e.observation_date, e.observation_time
          FROM evaluations e
          JOIN teachers t ON t.id = e.teacher_id
          WHERE t.id = 43 AND e.academic_year = ? AND e.semester = ?
          ORDER BY e.observation_date";
$stmt = $db->prepare($query);
$stmt->execute([$academic_year, $semester]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Filtered results for Reginald (43) with academic_year=$academic_year, semester=$semester:\n";
foreach ($results as $row) {
    echo "ID " . $row['id'] . ": " . $row['observation_date'] . " " . $row['observation_time'] . " (academic_year=" . $row['academic_year'] . ", semester=" . $row['semester'] . ")\n";
}






