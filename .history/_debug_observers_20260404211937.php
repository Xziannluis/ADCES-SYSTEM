<?php
require_once __DIR__ . '/config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->query("SELECT id, name, role, department, status FROM users WHERE name LIKE '%DAISA%' OR name LIKE '%daisa%'");
echo "DAISA user record:\n";
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { print_r($r); }

$stmt2 = $db->query("SELECT id, name, department, scheduled_by, scheduled_department FROM teachers WHERE name LIKE '%ZILDZIAN%'");
echo "\nZILDZIAN teacher record:\n";
while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) { print_r($r); }

$tid_stmt = $db->query("SELECT id FROM teachers WHERE name LIKE '%ZILDZIAN%' LIMIT 1");
$tid = $tid_stmt->fetchColumn();

if ($tid) {
    $stmt3 = $db->prepare("SELECT e.id, e.evaluator_id, u.name, u.role, u.department, e.status, e.semester, e.academic_year FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :tid");
    $stmt3->execute([':tid' => $tid]);
    echo "\nEvaluations for ZILDZIAN (tid=$tid):\n";
    while ($r = $stmt3->fetch(PDO::FETCH_ASSOC)) { print_r($r); }

    $stmt4 = $db->prepare("SELECT ta.evaluator_id, u.name, u.role, u.department FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :tid");
    $stmt4->execute([':tid' => $tid]);
    echo "\nAssignments for ZILDZIAN:\n";
    while ($r = $stmt4->fetch(PDO::FETCH_ASSOC)) { print_r($r); }
}
