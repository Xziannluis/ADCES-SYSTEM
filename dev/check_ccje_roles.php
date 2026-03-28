<?php
require_once __DIR__ . '/../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check all evaluators in CCJE
echo "=== CCJE Users (evaluators) ===\n";
$stmt = $conn->query("SELECT id, name, role, department, status FROM users WHERE department = 'CCJE' ORDER BY role, name");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ID={$r['id']} | {$r['name']} | {$r['role']} | {$r['status']}\n";
}

// Check DAISA as a user
echo "\n=== DAISA GUPIT (users table) ===\n";
$stmt = $conn->prepare("SELECT id, name, role, department, status FROM users WHERE name LIKE ?");
$stmt->execute(['%DAISA%GUPIT%']);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ID={$r['id']} | {$r['name']} | {$r['role']} | dept={$r['department']} | {$r['status']}\n";
}

// Check DAISA as a teacher
echo "\n=== DAISA GUPIT (teachers table) ===\n";
$stmt = $conn->prepare("SELECT id, name, department, status FROM teachers WHERE name LIKE ?");
$stmt->execute(['%DAISA%GUPIT%']);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ID={$r['id']} | {$r['name']} | dept={$r['department']} | {$r['status']}\n";
}

// Check JUN VILLARMIA
echo "\n=== JUN VILLARMIA (users table) ===\n";
$stmt = $conn->prepare("SELECT id, name, role, department, status FROM users WHERE name LIKE ?");
$stmt->execute(['%JUN%VILLARMIA%']);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ID={$r['id']} | {$r['name']} | {$r['role']} | dept={$r['department']} | {$r['status']}\n";
}

// Check JUN as teacher
echo "\n=== JUN VILLARMIA (teachers table) ===\n";
$stmt = $conn->prepare("SELECT id, name, department, status FROM teachers WHERE name LIKE ?");
$stmt->execute(['%JUN%VILLARMIA%']);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ID={$r['id']} | {$r['name']} | dept={$r['department']} | {$r['status']}\n";
}

// Who is assigned to evaluate JUN?
echo "\n=== Evaluators assigned to JUN (teacher_assignments) ===\n";
$stmt = $conn->prepare("SELECT ta.*, u.name as eval_name, u.role as eval_role, u.department as eval_dept 
    FROM teacher_assignments ta 
    JOIN users u ON ta.evaluator_id = u.id 
    JOIN teachers t ON ta.teacher_id = t.id
    WHERE t.name LIKE '%JUN%VILLARMIA%'");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  Assignment ID={$r['id']} | Evaluator: {$r['eval_name']} ({$r['eval_role']}, {$r['eval_dept']})\n";
}

// Check all chairpersons
echo "\n=== All Chairpersons ===\n";
$stmt = $conn->query("SELECT id, name, role, department FROM users WHERE role = 'chairperson' AND status = 'active' ORDER BY department, name");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ID={$r['id']} | {$r['name']} | dept={$r['department']}\n";
}
