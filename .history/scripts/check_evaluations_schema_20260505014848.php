<?php
// scripts/check_evaluations_schema.php
// Safe DB schema inspection script for the running application.
// Usage (browser): http://localhost/ADCES-SYSTEM/scripts/check_evaluations_schema.php
// Usage (CLI): php scripts/check_evaluations_schema.php

require_once __DIR__ . '/../config/database.php';

// Force plain text output for browser readability
if (!PHP_SAPI || PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    echo "ERROR: Unable to connect to the database.\n";
    echo "Driver error: " . ($database->getLastError() ?? 'unknown') . "\n";
    exit(1);
}

try {
    $stmt = $db->query("DESCRIBE evaluations");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) {
        echo "Table 'evaluations' not found or returned no columns.\n";
        exit(0);
    }

    echo "Found " . count($cols) . " columns in table 'evaluations'.\n\n";
    printf("%-25s %-25s %-6s %-6s %-15s %s\n", 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra');
    echo str_repeat('-', 90) . "\n";
    foreach ($cols as $col) {
        $field = $col['Field'] ?? '';
        $type = $col['Type'] ?? '';
        $null = $col['Null'] ?? '';
        $key = $col['Key'] ?? '';
        $default = isset($col['Default']) ? $col['Default'] : 'NULL';
        $extra = $col['Extra'] ?? '';
        printf("%-25s %-25s %-6s %-6s %-15s %s\n", $field, $type, $null, $key, (string)$default, $extra);
    }

    $hasFaculty = false;
    foreach ($cols as $col) {
        if (isset($col['Field']) && $col['Field'] === 'faculty_name') {
            $hasFaculty = true;
            break;
        }
    }

    echo "\nfaculty_name column: " . ($hasFaculty ? "PRESENT" : "MISSING") . "\n";

    // Also show location of temp debug file we may have written earlier
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adces_schedule_debug.log';
    echo "\nTemporary debug log path: " . $tmp . "\n";

} catch (PDOException $e) {
    echo "PDOException: " . $e->getMessage() . "\n";
    exit(1);
}
