<?php
/**
 * One-time backfill: create teachers records for coordinators who don't have one.
 * This allows deans/principals to evaluate chairpersons and subject coordinators.
 * 
 * Safe to run multiple times — it skips coordinators who already have a teachers record.
 */
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$roles = ['chairperson', 'subject_coordinator', 'grade_level_coordinator'];
$placeholders = implode(',', array_fill(0, count($roles), '?'));

$stmt = $db->prepare("
    SELECT u.id AS user_id, u.name, u.department, u.role
    FROM users u
    LEFT JOIN teachers t ON t.user_id = u.id
    WHERE u.role IN ($placeholders)
      AND u.status = 'active'
      AND t.id IS NULL
");
foreach ($roles as $i => $r) {
    $stmt->bindValue($i + 1, $r);
}
$stmt->execute();
$missing = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($missing)) {
    echo "All coordinators already have teacher records. Nothing to do.\n";
    exit(0);
}

$insert = $db->prepare("INSERT INTO teachers (name, department, user_id, status, created_at) VALUES (:name, :department, :user_id, 'active', NOW())");

foreach ($missing as $row) {
    $insert->bindValue(':name', $row['name']);
    $insert->bindValue(':department', $row['department']);
    $insert->bindValue(':user_id', $row['user_id']);
    $insert->execute();
    echo "Created teacher record for {$row['name']} (user_id={$row['user_id']}, role={$row['role']}, dept={$row['department']})\n";
}

echo "\nDone. Created " . count($missing) . " teacher record(s).\n";
