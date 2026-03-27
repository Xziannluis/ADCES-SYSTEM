<?php
require_once __DIR__ . '/../config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Remove stale assignment: APRIL OLMEDO (user_id=64, SHS) -> DAISA GUPIT (teacher_id=49, CCIS)
$stmt = $conn->prepare("DELETE FROM teacher_assignments WHERE id = 14 AND evaluator_id = 64 AND teacher_id = 49");
$stmt->execute();
echo "Deleted " . $stmt->rowCount() . " stale assignment(s).\n";

// Verify
$stmt = $conn->prepare("SELECT * FROM teacher_assignments WHERE evaluator_id = 64 AND teacher_id = 49");
$stmt->execute();
$remaining = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Remaining APRIL->DAISA assignments: " . count($remaining) . "\n";
