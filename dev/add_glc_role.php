<?php
// One-time migration: verify Grade Level Coordinator setup.
// Safe to re-run — read-only checks only.
require_once __DIR__ . '/../config/database.php';
$db = new Database();
$pdo = $db->getConnection();

$s = $pdo->query("SHOW TABLES LIKE 'evaluator_grade_levels'");
echo $s->rowCount() > 0 ? "evaluator_grade_levels: OK\n" : "evaluator_grade_levels: MISSING\n";

$s2 = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
$row = $s2->fetch(PDO::FETCH_ASSOC);
echo (strpos($row['Type'], 'grade_level_coordinator') !== false) ? "grade_level_coordinator in ENUM: OK\n" : "grade_level_coordinator in ENUM: MISSING\n";
