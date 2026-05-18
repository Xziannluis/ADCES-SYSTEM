<?php
/**
 * DEBUG: Department Access Control - Query Testing
 */

require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

echo "════════════════════════════════════════════════════════════\n";
echo "    DEBUGGING DEPARTMENT ACCESS CONTROL\n";
echo "════════════════════════════════════════════════════════════\n\n";

// TEST 1: Check if evaluation_schedule field exists and has data
echo "TEST 1: Checking evaluation_schedule field\n";
echo "--------------------------------------------\n";

try {
    $result = $db->query("SHOW COLUMNS FROM teachers LIKE 'evaluation_schedule'");
    $col = $result->fetch();
    if ($col) {
        echo "✓ Column exists: evaluation_schedule\n";
        echo "  Type: " . $col['Type'] . "\n";
        echo "  Null: " . $col['Null'] . "\n";
    } else {
        echo "✗ Column DOES NOT EXIST: evaluation_schedule\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Check data
try {
    $result = $db->query("SELECT 
        COUNT(*) as total,
        SUM(IF(evaluation_schedule IS NOT NULL AND evaluation_schedule != '', 1, 0)) as with_schedule,
        SUM(IF(evaluation_schedule IS NULL OR evaluation_schedule = '', 1, 0)) as without_schedule
    FROM teachers");
    $data = $result->fetch(PDO::FETCH_ASSOC);
    echo "  With schedule: " . $data['with_schedule'] . "\n";
    echo "  Without schedule: " . $data['without_schedule'] . "\n";
} catch (Exception $e) {
    echo "✗ Error checking schedule data: " . $e->getMessage() . "\n";
}

echo "\n";

// TEST 2: Test the original reports.php query
echo "TEST 2: Testing Original Reports.php Query\n";
echo "--------------------------------------------\n";

$department = 'CAS';
echo "Testing with department: $department\n\n";

// Original working query
$originalQuery = "SELECT DISTINCT t.id, t.name
    FROM evaluations e
    INNER JOIN teachers t ON e.teacher_id = t.id
    WHERE t.department = :department
      AND e.status = 'completed'
      AND e.overall_avg IS NOT NULL
      AND e.overall_avg > 0
    ORDER BY t.name ASC";

echo "ORIGINAL QUERY (without schedule check):\n";
try {
    $stmt = $db->prepare($originalQuery);
    $stmt->bindValue(':department', $department);
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "✓ Returns: $count teachers\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// TEST 3: Test the NEW query with schedule requirement
echo "TEST 3: Testing NEW Query (with schedule requirement)\n";
echo "-----------------------------------------------------\n";

$newQuery = "SELECT DISTINCT t.id, t.name
    FROM evaluations e
    INNER JOIN teachers t ON e.teacher_id = t.id
    WHERE (
      (t.department = :department AND (t.evaluation_schedule IS NOT NULL AND t.evaluation_schedule != ''))
      OR 
      (t.department != :department AND EXISTS (
        SELECT 1 FROM teacher_assignments ta 
        WHERE ta.teacher_id = t.id 
        AND ta.evaluator_id IN (
          SELECT ea.evaluator_id FROM evaluator_assignments ea WHERE ea.program = :department
        )
      ))
    )
      AND e.status = 'completed'
      AND e.overall_avg IS NOT NULL
      AND e.overall_avg > 0
      AND e.department = :department
    ORDER BY t.name ASC";

echo "NEW QUERY (with schedule check):\n";
try {
    $stmt = $db->prepare($newQuery);
    $stmt->bindValue(':department', $department);
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "✓ Returns: $count teachers\n";
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $r) {
        echo "  - " . $r['name'] . "\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// TEST 4: Check if teacher_assignments and evaluator_assignments tables exist
echo "TEST 4: Checking Required Tables\n";
echo "--------------------------------\n";

$tables = ['teacher_assignments', 'evaluator_assignments'];
foreach ($tables as $table) {
    try {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        $row = $result->fetch();
        if ($row) {
            echo "✓ Table exists: $table\n";
        } else {
            echo "✗ Table MISSING: $table\n";
        }
    } catch (Exception $e) {
        echo "✗ Error checking $table: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// TEST 5: Simpler fix - just check schedule without complex joins
echo "TEST 5: Testing SIMPLIFIED Query (recommended)\n";
echo "----------------------------------------------\n";

$simplifiedQuery = "SELECT DISTINCT t.id, t.name
    FROM evaluations e
    INNER JOIN teachers t ON e.teacher_id = t.id
    WHERE e.department = :department
      AND t.evaluation_schedule IS NOT NULL 
      AND t.evaluation_schedule != ''
      AND e.status = 'completed'
      AND e.overall_avg IS NOT NULL
      AND e.overall_avg > 0
    ORDER BY t.name ASC";

echo "SIMPLIFIED QUERY (just check schedule):\n";
try {
    $stmt = $db->prepare($simplifiedQuery);
    $stmt->bindValue(':department', $department);
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "✓ Returns: $count teachers\n";
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($count > 0) {
        foreach ($results as $r) {
            echo "  - " . $r['name'] . "\n";
        }
    } else {
        echo "  (No teachers with schedules in this department)\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// TEST 6: Check what's in evaluations table
echo "TEST 6: Evaluations Data Check\n";
echo "------------------------------\n";

try {
    $result = $db->query("SELECT COUNT(*) as total FROM evaluations WHERE status = 'completed'");
    $row = $result->fetch();
    echo "Total completed evaluations: " . $row['total'] . "\n";
    
    $result = $db->query("SELECT DISTINCT department FROM evaluations");
    $departments = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "Departments with evaluations: " . implode(', ', $departments) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n";

echo "════════════════════════════════════════════════════════════\n";
echo "DIAGNOSIS COMPLETE\n";
echo "════════════════════════════════════════════════════════════\n\n";

?>
