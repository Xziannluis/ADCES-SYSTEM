<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();
$r = $db->query("SHOW COLUMNS FROM evaluations LIKE 'evaluation_form_type'")->fetchAll();
echo count($r) ? "evaluation_form_type EXISTS in evaluations\n" : "evaluation_form_type MISSING from evaluations\n";

$r2 = $db->query("SHOW COLUMNS FROM teachers LIKE 'evaluation_form_type'")->fetchAll();
echo count($r2) ? "evaluation_form_type EXISTS in teachers\n" : "evaluation_form_type MISSING from teachers\n";

// Also check what migration adds this
echo "\n=== evaluations current data ===\n";
$rows = $db->query("SELECT id, teacher_id, status, evaluation_form_type FROM evaluations LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) print_r($r);
