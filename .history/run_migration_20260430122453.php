<?php
/**
 * One-time migration script to add evaluation_schedule_end column
 * Access via: http://localhost/ADCES-SYSTEM/run_migration.php
 */

require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if column already exists
    $stmt = $db->query("SHOW COLUMNS FROM `teachers` LIKE 'evaluation_schedule_end'");
    $columnExists = $stmt && $stmt->fetch(PDO::FETCH_NUM);
    
    if ($columnExists) {
        echo "<h2 style='color: green;'>✓ Column already exists</h2>";
        echo "<p>The <code>evaluation_schedule_end</code> column is already present in the <code>teachers</code> table.</p>";
    } else {
        // Add the column
        $db->exec("ALTER TABLE `teachers` ADD COLUMN `evaluation_schedule_end` DATETIME DEFAULT NULL AFTER `evaluation_schedule`");
        echo "<h2 style='color: green;'>✓ Migration successful!</h2>";
        echo "<p>The <code>evaluation_schedule_end</code> column has been added to the <code>teachers</code> table.</p>";
    }
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>✗ Migration failed</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Code:</strong> " . htmlspecialchars($e->getCode()) . "</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        h1 { color: #333; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Database Migration Tool</h1>
    <hr>
</body>
</html>
