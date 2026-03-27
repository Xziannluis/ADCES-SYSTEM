<?php
require_once __DIR__ . '/../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Find APRIL OLMEDO
$stmt = $conn->prepare("SELECT id, name, department, role FROM users WHERE name LIKE ?");
$stmt->execute(['%APRIL%OLMEDO%']);
$april = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "APRIL OLMEDO (users):\n";
print_r($april);

// Find DAISA GUPIT as teacher
$stmt = $conn->prepare("SELECT id, name, department FROM teachers WHERE name LIKE ?");
$stmt->execute(['%DAISA%GUPIT%']);
$daisa = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nDAISA GUPIT (teachers):\n";
print_r($daisa);

if (!empty($april) && !empty($daisa)) {
    $aprilId = $april[0]['id'];
    $daisaId = $daisa[0]['id'];

    // Check assignment
    $stmt = $conn->prepare("SELECT * FROM teacher_assignments WHERE evaluator_id = ? AND teacher_id = ?");
    $stmt->execute([$aprilId, $daisaId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\nAssignments (APRIL->DAISA):\n";
    print_r($assignments);

    // Check DAISA's secondary departments
    $stmt = $conn->prepare("SELECT department FROM teacher_departments WHERE teacher_id = ?");
    $stmt->execute([$daisaId]);
    $depts = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    echo "\nDAISA's secondary departments: ";
    print_r($depts);

    echo "\nDAISA's primary department: " . $daisa[0]['department'] . "\n";
    echo "APRIL's department: " . $april[0]['department'] . "\n";
}
