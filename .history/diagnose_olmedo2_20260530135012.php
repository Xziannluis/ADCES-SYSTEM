<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check all users named Olmedo
echo "<h2>All Users with 'Olmedo' in name</h2>";
$olmedo_users = $db->prepare("SELECT id, name, role, department FROM users WHERE name LIKE '%Olmedo%'");
$olmedo_users->execute();
while ($u = $olmedo_users->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$u['id']} | Name: {$u['name']} | Role: {$u['role']} | Dept: {$u['department']}<br>";
}

echo "<hr>";
echo "<h2>All Teachers named 'Olmedo'</h2>";
$olmedo_teachers = $db->prepare("SELECT id, name, user_id, department FROM teachers WHERE name LIKE '%Olmedo%'");
$olmedo_teachers->execute();
while ($t = $olmedo_teachers->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$t['id']} | Name: {$t['name']} | User ID: {$t['user_id']} | Dept: {$t['department']}<br>";
}

echo "<hr>";
echo "<h2>Evaluations assigned to any Olmedo</h2>";
$olmedo_eval = $db->prepare("
    SELECT u.name as evaluator, COUNT(e.id) as eval_count
    FROM evaluations e
    JOIN users u ON u.id = e.evaluator_id
    WHERE u.name LIKE '%Olmedo%'
    GROUP BY e.evaluator_id, u.name
");
$olmedo_eval->execute();
echo $olmedo_eval->rowCount() . " Olmedo users with evaluations<br>";
while ($row = $olmedo_eval->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$row['evaluator']}: {$row['eval_count']} evaluations<br>";
}

// If there's a teacher Olmedo, check what the page shows when they log in
echo "<hr>";
echo "<h2>Teacher Olmedo's Observation View</h2>";
$teacher_olmedo = $db->prepare("SELECT * FROM teachers WHERE name LIKE '%Olmedo%' LIMIT 1");
$teacher_olmedo->execute();
$to = $teacher_olmedo->fetch(PDO::FETCH_ASSOC);
if ($to) {
    echo "Teacher Olmedo ID: {$to['id']} | Dept: {$to['department']}<br>";
    echo "Their scheduled evaluations:<br>";
    $their_evals = $db->prepare("
        SELECT e.id, e.evaluator_id, u.name as evaluator_name, e.observation_date, e.status
        FROM evaluations e
        JOIN users u ON u.id = e.evaluator_id
        WHERE e.teacher_id = ?
        ORDER BY e.observation_date DESC
    ");
    $their_evals->execute([$to['id']]);
    if ($their_evals->rowCount() == 0) {
        echo "  No evaluations<br>";
    } else {
        while ($ev = $their_evals->fetch(PDO::FETCH_ASSOC)) {
            echo "  - {$ev['evaluator_name']}: {$ev['observation_date']} ({$ev['status']})<br>";
        }
    }
}
?>
