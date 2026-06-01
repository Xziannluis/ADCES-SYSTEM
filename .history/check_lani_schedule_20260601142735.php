<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check Lani Jane's teacher data
$stmt = $db->prepare("SELECT * FROM teachers WHERE name LIKE '%Lani%Jane%' OR name LIKE '%lumogda%' LIMIT 1");
$stmt->execute();
$lani = $stmt->fetch(PDO::FETCH_ASSOC);

if ($lani) {
    echo "=== Lani Jane's Teacher Data ===\n";
    echo json_encode($lani, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n\n";
    
    // Check if she has an evaluation schedule
    echo "evaluation_schedule: " . ($lani['evaluation_schedule'] ?? 'NULL') . "\n";
    echo "evaluation_schedule_end: " . ($lani['evaluation_schedule_end'] ?? 'NULL') . "\n";
    echo "evaluation_semester: " . ($lani['evaluation_semester'] ?? 'NULL') . "\n";
    echo "scheduled_department: " . ($lani['scheduled_department'] ?? 'NULL') . "\n";
    echo "\n";
    
    // Check all teachers in CCIS to see the pattern
    $stmt2 = $db->prepare("SELECT id, name, department, evaluation_schedule, evaluation_semester, scheduled_department FROM teachers WHERE department = 'CCIS' AND status = 'active' LIMIT 10");
    $stmt2->execute();
    $teachers = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== All CCIS Teachers (Active) ===\n";
    foreach ($teachers as $t) {
        echo "Name: " . $t['name'] . "\n";
        echo "  Schedule: " . ($t['evaluation_schedule'] ?? 'NULL') . "\n";
        echo "  Department: " . ($t['scheduled_department'] ?? 'NULL (primary: ' . $t['department'] . ')') . "\n";
        echo "---\n";
    }
} else {
    echo "Lani Jane not found\n";
}
?>
