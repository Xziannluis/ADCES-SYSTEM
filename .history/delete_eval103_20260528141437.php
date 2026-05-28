<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== DELETING ERRONEOUS EVALUATION 103 ===\n\n";

// First, show what we're deleting
$stmt = $conn->prepare('SELECT id, teacher_id, evaluator_id, observation_date FROM evaluations WHERE id = 103');
$stmt->execute();
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if ($eval) {
    $stmt2 = $conn->prepare('SELECT name FROM teachers WHERE id = :id');
    $stmt2->execute([':id' => $eval['teacher_id']]);
    $teacher = $stmt2->fetchColumn();
    
    $stmt3 = $conn->prepare('SELECT name FROM users WHERE id = :id');
    $stmt3->execute([':id' => $eval['evaluator_id']]);
    $evaluator = $stmt3->fetchColumn();
    
    echo "About to delete:\n";
    echo "  Evaluation ID: {$eval['id']}\n";
    echo "  Teacher: {$teacher}\n";
    echo "  Evaluator: {$evaluator}\n";
    echo "  Date: {$eval['observation_date']}\n\n";
    
    // Check for associated schedules
    $stmt4 = $conn->prepare('SELECT id FROM teacher_schedules WHERE evaluation_id = 103');
    $stmt4->execute();
    $schedules = $stmt4->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($schedules)) {
        echo "Found associated schedule(s): " . implode(", ", $schedules) . "\n";
        echo "These will also be deleted.\n\n";
        
        // Delete schedules first
        foreach ($schedules as $sched_id) {
            $stmt5 = $conn->prepare('DELETE FROM teacher_schedules WHERE id = :id');
            $stmt5->execute([':id' => $sched_id]);
            echo "  ✓ Deleted schedule {$sched_id}\n";
        }
    }
    
    // Delete the evaluation
    $stmt6 = $conn->prepare('DELETE FROM evaluations WHERE id = 103');
    $stmt6->execute();
    
    echo "\n  ✓ Deleted evaluation 103\n\n";
    
    // Verify deletion
    $stmt7 = $conn->prepare('SELECT COUNT(*) as count FROM evaluations WHERE id = 103');
    $stmt7->execute();
    $verify = $stmt7->fetch(PDO::FETCH_ASSOC);
    echo "Verification: Remaining evaluations with ID 103: {$verify['count']}\n";
    
    // Show remaining CCIS evaluations for Reginald
    echo "\n=== Remaining CCIS evaluations for Reginald ===\n";
    $stmt8 = $conn->prepare('
        SELECT e.id, e.observation_date, u.name as evaluator_name, u.role
        FROM evaluations e
        JOIN users u ON u.id = e.evaluator_id
        WHERE e.teacher_id = 43 AND e.department = "CCIS" AND e.academic_year = "2025-2026" AND e.semester = "1st"
        ORDER BY e.observation_date DESC
    ');
    $stmt8->execute();
    $remaining = $stmt8->fetchAll(PDO::FETCH_ASSOC);
    foreach ($remaining as $r) {
        echo "  ✓ Eval {$r['id']}: {$r['evaluator_name']} ({$r['role']}) on {$r['observation_date']}\n";
    }
} else {
    echo "Evaluation 103 not found!\n";
}
?>
