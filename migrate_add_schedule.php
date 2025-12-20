<?php
require_once 'config/database.php';

$db = (new Database())->getConnection();

try {
    // Add evaluation_schedule column if it doesn't exist
    $db->exec("ALTER TABLE teachers ADD COLUMN evaluation_schedule DATETIME DEFAULT NULL AFTER status");
    echo "Added evaluation_schedule column<br>";
} catch (Exception $e) {
    if (strpos($e->getMessage(), "Duplicate column") !== false) {
        echo "evaluation_schedule column already exists<br>";
    } else {
        echo "Error adding evaluation_schedule: " . $e->getMessage() . "<br>";
    }
}

try {
    // Add evaluation_room column if it doesn't exist
    $db->exec("ALTER TABLE teachers ADD COLUMN evaluation_room VARCHAR(255) DEFAULT NULL AFTER evaluation_schedule");
    echo "Added evaluation_room column<br>";
} catch (Exception $e) {
    if (strpos($e->getMessage(), "Duplicate column") !== false) {
        echo "evaluation_room column already exists<br>";
    } else {
        echo "Error adding evaluation_room: " . $e->getMessage() . "<br>";
    }
}

// Verify columns
$query = 'DESCRIBE teachers';
$stmt = $db->prepare($query);
$stmt->execute();
echo "<br><h3>Current teachers table structure:</h3>";
echo "<table border='1' style='border-collapse: collapse; padding: 10px;'>";
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr><td>" . $row['Field'] . "</td><td>" . $row['Type'] . "</td></tr>";
}
echo "</table>";

echo "<br><p style='color: green; font-weight: bold;'>Database migration completed successfully!</p>";
?>
