<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Find Reginald Ryan Gosela
$stmt = $conn->prepare('SELECT id, name FROM teachers WHERE name LIKE :name ORDER BY name LIMIT 1');
$stmt->execute([':name' => '%Reginald%']);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Teacher: " . json_encode($teacher) . "\n";

if ($teacher) {
    $tid = $teacher['id'];
    
    // Get evaluations with more detail
    $stmt = $conn->prepare('SELECT id, evaluator_id, observation_date, status, semester, scheduled_by FROM evaluations WHERE teacher_id = :tid ORDER BY observation_date DESC LIMIT 5');
    $stmt->execute([':tid' => $tid]);
    $evals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Evaluations: " . json_encode($evals) . "\n";
    
    // Get evaluator info for each evaluation
    foreach ($evals as $eval) {
        $ev_id = $eval['evaluator_id'];
        $stmt = $conn->prepare('SELECT id, name, role, department FROM users WHERE id = :id');
        $stmt->execute([':id' => $ev_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Evaluator ID $ev_id: " . json_encode($user) . "\n";
        
        if ($eval['scheduled_by']) {
            $stmt = $conn->prepare('SELECT id, name, role FROM users WHERE id = :id');
            $stmt->execute([':id' => $eval['scheduled_by']]);
            $sched = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "  Scheduled by: " . json_encode($sched) . "\n";
        }
    }
    
    // Get teacher assignments  
    $stmt = $conn->prepare('SELECT ta.evaluator_id, ta.eval_id, u.name as evaluator_name, u.role FROM teacher_assignments ta LEFT JOIN users u ON u.id = ta.evaluator_id WHERE ta.teacher_id = :tid ORDER BY ta.id');
    $stmt->execute([':tid' => $tid]);
    $assigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Assignments: " . json_encode($assigns) . "\n";
    
    // Get teacher department
    $stmt = $conn->prepare('SELECT department FROM teachers WHERE id = :tid');
    $stmt->execute([':tid' => $tid]);
    $dept = $stmt->fetchColumn();
    echo "Department: $dept\n";
    
    // Get deans/principals for this department
    $stmt = $conn->prepare("SELECT id, name, role FROM users WHERE department = :department AND role IN ('dean','principal') AND status = 'active'");
    $stmt->execute([':department' => $dept]);
    $deans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Deans/Principals: " . json_encode($deans) . "\n";
    
    // Search for Wendell user
    $stmt = $conn->prepare("SELECT id, name, role, department FROM users WHERE name LIKE :name");
    $stmt->execute([':name' => '%Wendell%']);
    $wendell = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Wendell users: " . json_encode($wendell) . "\n";
}
?>
