<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

echo "DETAILED DATA INSPECTION\n";
echo "========================\n\n";

// Check evaluations for CAS
echo "Evaluations in database:\n";
try {
    $result = $db->query("SELECT id, teacher_id, department, status, overall_avg FROM evaluations LIMIT 10");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo "  ID: {$row['id']}, Teacher: {$row['teacher_id']}, Dept: {$row['department']}, Status: {$row['status']}, Avg: {$row['overall_avg']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nTeachers with evaluations:\n";
try {
    $result = $db->query("
        SELECT DISTINCT t.id, t.name, t.department, e.status, e.overall_avg
        FROM teachers t
        INNER JOIN evaluations e ON e.teacher_id = t.id
        LIMIT 10
    ");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo "  {$row['name']} ({$row['department']}) - Status: {$row['status']}, Avg: {$row['overall_avg']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nTeachers in CAS department:\n";
try {
    $result = $db->query("SELECT id, name, department FROM teachers WHERE department = 'CAS' LIMIT 5");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo "  {$row['name']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nTeacher with completed evaluations:\n";
try {
    $result = $db->query("
        SELECT t.id, t.name, t.department, COUNT(e.id) as eval_count
        FROM teachers t
        LEFT JOIN evaluations e ON e.teacher_id = t.id AND e.status = 'completed'
        GROUP BY t.id
        HAVING eval_count > 0
    ");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo "  {$row['name']} ({$row['department']}) - {$row['eval_count']} evaluations\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
