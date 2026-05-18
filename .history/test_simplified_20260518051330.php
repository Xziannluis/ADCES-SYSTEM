<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

echo "TESTING SIMPLIFIED QUERIES\n";
echo "==========================\n\n";

$department = 'CCIS';
echo "Testing with department: $department\n\n";

// Test the simplified query
$query = "SELECT DISTINCT t.id, t.name
    FROM evaluations e
    INNER JOIN teachers t ON e.teacher_id = t.id
    WHERE e.department = :department
      AND e.status = 'completed'
      AND e.overall_avg IS NOT NULL
      AND e.overall_avg > 0
    ORDER BY t.name ASC";

try {
    $stmt = $db->prepare($query);
    $stmt->bindValue(':department', $department);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Found " . count($results) . " teachers in $department with completed evaluations:\n";
    foreach ($results as $r) {
        echo "  - " . $r['name'] . "\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Also test evaluation retrieval
$query2 = "SELECT e.*, t.name as teacher_name
    FROM evaluations e
    JOIN teachers t ON e.teacher_id = t.id
    WHERE e.department = :department
    AND e.status = 'completed'
    AND e.overall_avg IS NOT NULL
    AND e.overall_avg > 0
    ORDER BY e.observation_date DESC";

try {
    $stmt = $db->prepare($query2);
    $stmt->bindValue(':department', $department);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Found " . count($results) . " completed evaluations in $department:\n";
    foreach ($results as $r) {
        echo "  - " . $r['teacher_name'] . " (Date: " . $r['observation_date'] . ", Avg: " . $r['overall_avg'] . ")\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

?>
