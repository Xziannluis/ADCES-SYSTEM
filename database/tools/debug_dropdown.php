<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Check what the dean user looks like
$stmt = $db->query("SELECT id, name, email, role, department FROM users WHERE role = 'dean'");
echo "=== Dean users ===\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

// Check evaluations with teacher department info
echo "\n=== Evaluations with teacher departments ===\n";
$stmt = $db->query("
    SELECT e.id, e.evaluator_id, e.teacher_id, e.evaluation_form_type, e.status,
           t.name AS teacher_name, t.department AS teacher_dept,
           u.name AS evaluator_name, u.role AS evaluator_role, u.department AS evaluator_dept
    FROM evaluations e
    INNER JOIN teachers t ON e.teacher_id = t.id
    LEFT JOIN users u ON e.evaluator_id = u.id
    ORDER BY e.id
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

// Check distinct departments in teachers
echo "\n=== Distinct teacher departments ===\n";
$stmt = $db->query("SELECT DISTINCT department FROM teachers ORDER BY department");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - '" . $row['department'] . "'\n";
}

// Check distinct departments in users
echo "\n=== Distinct user departments ===\n";
$stmt = $db->query("SELECT DISTINCT department FROM users ORDER BY department");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - '" . $row['department'] . "'\n";
}
