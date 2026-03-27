<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Check evaluations columns
echo "=== evaluations columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM evaluations")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} ({$c['Type']}) default={$c['Default']}\n";
}

// Check teachers columns  
echo "\n=== teachers columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM teachers")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} ({$c['Type']}) default={$c['Default']}\n";
}

// Check if observation_plan_acknowledgments exists
echo "\n=== tables in DB ===\n";
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo implode(", ", $tables) . "\n";

// Check notifications columns
echo "\n=== notifications columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} ({$c['Type']}) default={$c['Default']}\n";
}

// Check notifications indexes
echo "\n=== notifications indexes ===\n";
$idxs = $db->query("SHOW INDEX FROM notifications")->fetchAll(PDO::FETCH_ASSOC);
foreach ($idxs as $i) {
    echo "  {$i['Key_name']} -> {$i['Column_name']}\n";
}
