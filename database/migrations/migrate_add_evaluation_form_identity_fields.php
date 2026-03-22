<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

if (!$db) {
    throw new RuntimeException('Database connection failed while updating evaluations table.');
}

$columns = [
    'faculty_name' => "ALTER TABLE evaluations ADD COLUMN faculty_name VARCHAR(255) NULL AFTER teacher_id",
    'department' => "ALTER TABLE evaluations ADD COLUMN department VARCHAR(100) NULL AFTER faculty_name",
];

$existing = [];
$stmt = $db->query('SHOW COLUMNS FROM evaluations');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$row['Field']] = true;
}

foreach ($columns as $column => $sql) {
    if (!isset($existing[$column])) {
        $db->exec($sql);
        echo "Added column: {$column}\n";
    } else {
        echo "Column already exists: {$column}\n";
    }
}

echo "evaluations table identity fields are ready.\n";