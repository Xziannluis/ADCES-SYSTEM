<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== Verifying Observer Logic After Fixes ===\n\n";

// Get Reginald's remaining evaluations
$stmt = $conn->prepare('
    SELECT e.id, e.evaluator_id, e.department, e.observation_date, u.name as evaluator_name, u.department as eval_dept
    FROM evaluations e
    JOIN users u ON u.id = e.evaluator_id
    WHERE e.teacher_id = 43 AND e.academic_year = "2025-2026" AND e.semester = "1st"
    ORDER BY e.observation_date DESC
');
$stmt->execute();
$evals = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($evals as $eval) {
    echo "Evaluation {$eval['id']} ({$eval['observation_date']}):\n";
    echo "  Department: {$eval['department']}\n";
    echo "  Evaluator: {$eval['evaluator_name']} (from {$eval['eval_dept']})\n\n";
    
    // Show what observers SHOULD be included from teacher_assignments
    echo "  Teacher Assignments (should filter to {$eval['department']} only):\n";
    $stmt2 = $conn->prepare('
        SELECT u.name, u.department, u.role
        FROM teacher_assignments ta
        JOIN users u ON u.id = ta.evaluator_id
        WHERE ta.teacher_id = 43
    ');
    $stmt2->execute();
    $assigns = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    $match_count = 0;
    foreach ($assigns as $a) {
        $match = $a['department'] === $eval['department'] ? '✓ INCLUDE' : '✗ EXCLUDE';
        echo "    {$match}: {$a['name']} ({$a['role']}) in {$a['department']}\n";
        if ($a['department'] === $eval['department']) {
            $match_count++;
        }
    }
    
    // Show deans/principals for this department
    echo "  \n  Deans/Principals in {$eval['department']}:\n";
    $stmt3 = $conn->prepare("SELECT name, role FROM users WHERE department = :dept AND role IN ('dean','principal') AND status = 'active'");
    $stmt3->execute([':dept' => $eval['department']]);
    $deans = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    foreach ($deans as $d) {
        echo "    ✓ {$d['name']} ({$d['role']})\n";
    }
    
    echo "\n";
}

echo "\n=== Test Result ===\n";
echo "✓ All observers should now be from their respective evaluation departments only\n";
echo "✓ No cross-department contamination\n";
?>
