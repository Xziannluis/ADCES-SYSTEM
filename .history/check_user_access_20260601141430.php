<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check Marlon's account
$stmt = $db->prepare("SELECT id, name, role, department, status FROM users WHERE name LIKE '%Marlon%' LIMIT 5");
$stmt->execute();
$marlon_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Marlon's Account Info ===\n";
foreach ($marlon_users as $user) {
    echo "ID: " . $user['id'] . "\n";
    echo "Name: " . $user['name'] . "\n";
    echo "Role: " . $user['role'] . "\n";
    echo "Department: " . ($user['department'] ?? 'NULL') . "\n";
    echo "Status: " . $user['status'] . "\n";
    echo "---\n";
}

// Check what teachers Lani Jane Lumogda is assigned to
$stmt2 = $db->prepare("SELECT id, name, department, status FROM teachers WHERE name LIKE '%Lani%Jane%' OR name LIKE '%lumogda%' LIMIT 5");
$stmt2->execute();
$lani_teachers = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== Lani Jane Lumogda Info ===\n";
foreach ($lani_teachers as $teacher) {
    echo "ID: " . $teacher['id'] . "\n";
    echo "Name: " . $teacher['name'] . "\n";
    echo "Department: " . $teacher['department'] . "\n";
    echo "Status: " . $teacher['status'] . "\n";
    echo "---\n";
}

// Check evaluations for Lani Jane
if (!empty($lani_teachers)) {
    $lani_id = $lani_teachers[0]['id'];
    
    $stmt3 = $db->prepare("
        SELECT e.id, e.evaluator_id, e.department as eval_dept, e.semester, e.academic_year, u.name as evaluator_name, u.role, u.department as user_dept
        FROM evaluations e
        LEFT JOIN users u ON u.id = e.evaluator_id
        WHERE e.teacher_id = ?
        LIMIT 10
    ");
    $stmt3->execute([$lani_id]);
    $evaluations = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n=== Evaluations for Lani Jane (Teacher ID: $lani_id) ===\n";
    foreach ($evaluations as $eval) {
        echo "Eval ID: " . $eval['id'] . "\n";
        echo "Evaluator: " . ($eval['evaluator_name'] ?? 'NULL') . " (Role: " . ($eval['role'] ?? 'NULL') . ", Dept: " . ($eval['user_dept'] ?? 'NULL') . ")\n";
        echo "Eval Department: " . $eval['eval_dept'] . "\n";
        echo "Semester/AY: " . $eval['semester'] . " / " . $eval['academic_year'] . "\n";
        echo "---\n";
    }
}

// Check if Marlon has been assigned to any of Lani Jane's evaluations
if (!empty($marlon_users) && !empty($lani_teachers)) {
    $marlon_id = $marlon_users[0]['id'];
    $lani_id = $lani_teachers[0]['id'];
    
    $stmt4 = $db->prepare("
        SELECT ta.id, ta.evaluator_id, ta.teacher_id, ta.eval_id, e.department
        FROM teacher_assignments ta
        LEFT JOIN evaluations e ON e.id = ta.eval_id
        WHERE ta.evaluator_id = ? AND ta.teacher_id = ?
    ");
    $stmt4->execute([$marlon_id, $lani_id]);
    $assignments = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n=== Marlon's Assignments to Lani Jane ===\n";
    if (empty($assignments)) {
        echo "No direct assignments found\n";
    } else {
        foreach ($assignments as $assign) {
            echo "Assignment ID: " . $assign['id'] . "\n";
            echo "Eval ID: " . ($assign['eval_id'] ?? 'NULL') . "\n";
            echo "Eval Department: " . ($assign['department'] ?? 'NULL') . "\n";
            echo "---\n";
        }
    }
}
?>
