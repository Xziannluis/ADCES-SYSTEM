<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$tableExists = false;
foreach ($db->query("SHOW TABLES LIKE 'ai_reference_evaluations'") as $row) {
    $tableExists = true;
    echo json_encode($row, JSON_UNESCAPED_SLASHES), PHP_EOL;
}
if (!$tableExists) {
    echo "table_missing\n";
    exit(1);
}
$count = $db->query("SELECT COUNT(*) FROM ai_reference_evaluations")->fetchColumn();
echo 'count=' . $count . PHP_EOL;
