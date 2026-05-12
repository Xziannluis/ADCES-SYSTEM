<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("Database connection failed: " . $database->getLastError());
}

try {
    // Delete all stale unsigned upcoming-schedule acknowledgments
    $stmt = $db->prepare("DELETE FROM observation_plan_acknowledgments WHERE evaluation_id IS NULL");
    $stmt->execute();
    $deleted_rows = $stmt->rowCount();
    
    echo "✓ Cleaned up {$deleted_rows} stale acknowledgment record(s)\n";
    echo "✓ You can now set schedules without the 'already signed' error\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
