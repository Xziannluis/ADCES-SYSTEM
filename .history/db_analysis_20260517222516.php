<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    die("Connection failed: " . $db->getLastError());
}

// Get all tables
$result = $conn->query('SHOW TABLES');
$tables = $result->fetchAll(PDO::FETCH_COLUMN);

echo "=== DATABASE ANALYSIS ===\n";
echo "Database: ai_classroom_eval\n";
echo "Total Tables: " . count($tables) . "\n";
echo "============================================\n\n";

foreach ($tables as $table) {
    echo "TABLE: $table\n";
    echo str_repeat('-', 100) . "\n";
    
    // Get table structure
    $cols = $conn->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
    echo "COLUMNS:\n";
    foreach ($cols as $col) {
        $null = $col['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $key = $col['Key'] ? " [" . $col['Key'] . "]" : "";
        echo sprintf("  %-25s | %-30s | %s%s\n", $col['Field'], $col['Type'], $null, $key);
    }
    
    // Get row count
    $count = $conn->query("SELECT COUNT(*) as cnt FROM $table")->fetch(PDO::FETCH_ASSOC);
    echo "\nRecords: {$count['cnt']}\n";
    
    // Show sample data
    if ($count['cnt'] > 0) {
        echo "\nSample Data:\n";
        $sample = $conn->query("SELECT * FROM $table LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        if ($sample) {
            $headers = array_keys($sample[0]);
            echo "  " . implode(" | ", array_slice($headers, 0, 5)) . "\n";
            echo "  " . str_repeat("-", 80) . "\n";
            foreach ($sample as $row) {
                $values = [];
                foreach (array_slice($headers, 0, 5) as $h) {
                    $val = $row[$h] ?? '';
                    if (is_string($val) && strlen($val) > 25) {
                        $val = substr($val, 0, 22) . "...";
                    }
                    $values[] = $val;
                }
                echo "  " . implode(" | ", $values) . "\n";
            }
        }
    }
    
    echo "\n";
}
?>
