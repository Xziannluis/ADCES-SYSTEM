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
    $stmt = $conn->prepare('DESCRIBE evaluations');
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Evaluations columns: " . json_encode($columns) . "\n\n";
    
    // Get evaluations
    $stmt = $conn->prepare('SELECT * FROM evaluations WHERE teacher_id = :tid ORDER BY observation_date DESC LIMIT 5');
    $stmt->execute([':tid' => $tid]);
    $evals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Evaluations: " . json_encode($evals) . "\n";
    
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
