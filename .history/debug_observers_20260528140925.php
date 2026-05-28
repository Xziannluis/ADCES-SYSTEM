<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Find Reginald Ryan Gosela
$stmt = $conn->prepare('SELECT id, name, department FROM teachers WHERE name LIKE :name ORDER BY name LIMIT 1');
$stmt->execute([':name' => '%Reginald%']);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Teacher: " . json_encode($teacher) . "\n";

if ($teacher) {
    $tid = $teacher['id'];
    $dept = $teacher['department'];
    
    echo "\n=== What observers SHOULD be shown for CCIS observations ===\n";
    
    // Get deans/principals for CCIS
    echo "\n1. Deans/Principals in CCIS:\n";
    $stmt = $conn->prepare("SELECT id, name, role FROM users WHERE department = :department AND role IN ('dean','principal') AND status = 'active'");
    $stmt->execute([':department' => $dept]);
    $deans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($deans as $d) {
        echo "  - " . $d['name'] . " ({$d['role']})\n";
    }
    
    // Get teacher's assigned coordinators in CCIS
    echo "\n2. Assigned coordinators from CCIS:\n";
    $stmt = $conn->prepare(
        "SELECT DISTINCT u.name, u.role
         FROM teacher_assignments ta
         JOIN users u ON u.id = ta.evaluator_id
         WHERE ta.teacher_id = :teacher_id
           AND u.department = :department
           AND u.status = 'active'
           AND u.role IN ('chairperson','subject_coordinator','grade_level_coordinator')
         ORDER BY u.name"
    );
    $stmt->execute([':teacher_id' => $tid, ':department' => $dept]);
    $coords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($coords as $c) {
        echo "  - " . $c['name'] . " ({$c['role']})\n";
    }
    
    // Show what's currently in the teacher_assignments
    echo "\n3. All teacher assignments (regardless of department match):\n";
    $stmt = $conn->prepare('SELECT ta.*, u.name as evaluator_name, u.department FROM teacher_assignments ta LEFT JOIN users u ON u.id = ta.evaluator_id WHERE ta.teacher_id = :tid');
    $stmt->execute([':tid' => $tid]);
    $all_assign = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_assign as $a) {
        echo "  - " . $a['evaluator_name'] . " (dept: {$a['department']}, eval_id: {$a['eval_id']})\n";
    }
    
    // Show evaluations in other departments
    echo "\n4. Evaluations in other departments (might be causing cross-contamination):\n";
    $stmt = $conn->prepare('SELECT id, evaluator_id, department, observation_date, status FROM evaluations WHERE teacher_id = :tid AND department != :dept ORDER BY observation_date DESC');
    $stmt->execute([':tid' => $tid, ':dept' => $dept]);
    $other_evals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($other_evals as $e) {
        $stmt2 = $conn->prepare('SELECT name FROM users WHERE id = :id');
        $stmt2->execute([':id' => $e['evaluator_id']]);
        $eval_name = $stmt2->fetchColumn();
        echo "  - Eval {$e['id']}: {$eval_name} in {$e['department']} on {$e['observation_date']}\n";
    }
}
?>
