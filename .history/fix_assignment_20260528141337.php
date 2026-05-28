<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== FIXING THE OBSERVER ISSUE ===\n\n";

// Step 1: Check current assignment
$stmt = $conn->prepare('SELECT id, teacher_id, evaluator_id FROM teacher_assignments WHERE id = 87');
$stmt->execute();
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if ($record) {
    echo "Current assignment to delete:\n";
    echo "  ID: {$record['id']}, Teacher ID: {$record['teacher_id']}, Evaluator ID: {$record['evaluator_id']}\n\n";
    
    // Get evaluator details
    $stmt2 = $conn->prepare('SELECT name, department, role FROM users WHERE id = :id');
    $stmt2->execute([':id' => $record['evaluator_id']]);
    $eval = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    $stmt3 = $conn->prepare('SELECT name, department FROM teachers WHERE id = :id');
    $stmt3->execute([':id' => $record['teacher_id']]);
    $teacher = $stmt3->fetch(PDO::FETCH_ASSOC);
    
    echo "Removing assignment: {$eval['name']} ({$eval['department']}) from {$teacher['name']} ({$teacher['department']})\n\n";
    
    // Delete the incorrect assignment
    $stmt4 = $conn->prepare('DELETE FROM teacher_assignments WHERE id = 87 LIMIT 1');
    $result = $stmt4->execute();
    
    echo "✓ Assignment deleted successfully!\n\n";
    
    // Verify
    $stmt5 = $conn->prepare('SELECT COUNT(*) as count FROM teacher_assignments WHERE id = 87');
    $stmt5->execute();
    $verify = $stmt5->fetch(PDO::FETCH_ASSOC);
    echo "Verification: Remaining records with ID 87: {$verify['count']}\n";
    
    // Show remaining assignments for Reginald
    echo "\n=== Remaining assignments for Reginald ===\n";
    $stmt6 = $conn->prepare('
        SELECT u.name, u.department, u.role
        FROM teacher_assignments ta
        JOIN users u ON u.id = ta.evaluator_id
        WHERE ta.teacher_id = 43
    ');
    $stmt6->execute();
    $remaining = $stmt6->fetchAll(PDO::FETCH_ASSOC);
    foreach ($remaining as $r) {
        echo "  - {$r['name']} ({$r['role']} in {$r['department']})\n";
    }
} else {
    echo "Record with ID 87 not found!\n";
}
?>
