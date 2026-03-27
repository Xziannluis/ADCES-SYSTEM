<?php
$c = file_get_contents('c:/Users/HP/Downloads/ai_classroom_eval-5.sql');

// Extract all CREATE TABLE blocks
preg_match_all('/CREATE TABLE `([a-z_]+)` \((.+?)\) ENGINE/s', $c, $m);

echo "=== TABLES IN DUMP (" . count($m[1]) . " total) ===\n\n";

foreach ($m[1] as $i => $table) {
    echo "--- TABLE: $table ---\n";
    // Show columns only (trim CREATE TABLE wrapper)
    $cols = $m[2][$i];
    echo trim($cols) . "\n\n";
}

// Extract all ALTER TABLE blocks
preg_match_all('/ALTER TABLE `([a-z_]+)`\s*\n(.+?);/s', $c, $alters);
echo "\n=== ALTER TABLE STATEMENTS ===\n\n";
foreach ($alters[1] as $i => $table) {
    echo "--- ALTER: $table ---\n";
    echo trim($alters[2][$i]) . "\n\n";
}
