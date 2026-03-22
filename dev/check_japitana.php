<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

echo "=== Japitana user record ===\n";
$s = $db->query("SELECT id, name, role, department FROM users WHERE name LIKE '%apitana%'");
while($r = $s->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== Teachers assigned to Japitana (as supervisor) ===\n";
$s2 = $db->query("SELECT ea.evaluator_id, u.name, u.role, u.department 
    FROM evaluator_assignments ea 
    JOIN users u ON ea.evaluator_id = u.id 
    WHERE ea.supervisor_id IN (SELECT id FROM users WHERE name LIKE '%apitana%')");
while($r = $s2->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== Evaluations by Japitana ===\n";
$s3 = $db->query("SELECT e.id, e.teacher_id, t.name as teacher_name, t.department as teacher_dept, e.created_at
    FROM evaluations e 
    JOIN teachers t ON e.teacher_id = t.id
    WHERE e.evaluator_id IN (SELECT id FROM users WHERE name LIKE '%apitana%')
    ORDER BY e.created_at DESC LIMIT 10");
while($r = $s3->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== Teachers in JHS department ===\n";
$s4 = $db->query("SELECT id, name, department FROM teachers WHERE department = 'JHS' OR department LIKE '%JHS%' OR department LIKE '%Junior%' LIMIT 10");
while($r = $s4->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== Teachers evaluated by Japitana (via reports query) ===\n";
$s5 = $db->query("SELECT e.id, t.name, t.department, e.created_at 
    FROM evaluations e 
    JOIN teachers t ON e.teacher_id = t.id 
    WHERE t.department = 'JHS' 
    ORDER BY e.created_at DESC LIMIT 5");
while($r = $s5->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}

echo "\n=== All evaluations where evaluator_id=18 ===\n";
$s6 = $db->query("SELECT e.id, e.teacher_id, t.name, t.department, e.evaluator_id FROM evaluations e JOIN teachers t ON e.teacher_id = t.id WHERE e.evaluator_id = 18");
while($r = $s6->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
