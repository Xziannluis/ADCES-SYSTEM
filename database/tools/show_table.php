<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Full table structure
$s = $db->query('SHOW CREATE TABLE evaluations');
$r = $s->fetch(PDO::FETCH_ASSOC);
echo "=== evaluations CREATE TABLE ===\n";
echo $r['Create Table'] . "\n\n";

// Check all evaluation form types
$s = $db->query("SELECT evaluation_form_type, COUNT(*) as cnt FROM evaluations GROUP BY evaluation_form_type");
echo "=== evaluation_form_type counts ===\n";
while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
    echo "  type='" . $r['evaluation_form_type'] . "' (hex=" . bin2hex($r['evaluation_form_type'] ?? '') . ") count=" . $r['cnt'] . "\n";
}

// Also check evaluations.status 
$s = $db->query("SELECT status, COUNT(*) as cnt FROM evaluations GROUP BY status");
echo "\n=== status counts ===\n";
while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
    echo "  status='" . $r['status'] . "' count=" . $r['cnt'] . "\n";
}
