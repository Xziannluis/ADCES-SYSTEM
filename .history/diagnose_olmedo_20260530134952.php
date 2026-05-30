<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Check April T. Olmedo's evaluations
echo "<h2>April T. Olmedo's Evaluation Details</h2>";
$olmedo = $db->prepare("SELECT id FROM users WHERE name LIKE '%Olmedo%' LIMIT 1");
$olmedo->execute();
$olmedo_id = $olmedo->fetchColumn();

if ($olmedo_id) {
    echo "April T. Olmedo ID: {$olmedo_id}<br><br>";
    
    $olmedo_evals = $db->prepare("
        SELECT e.id, e.teacher_id, e.department as eval_dept, t.name as teacher_name, t.department as teacher_dept, t.scheduled_department
        FROM evaluations e
        JOIN teachers t ON t.id = e.teacher_id
        WHERE e.evaluator_id = ?
        LIMIT 5
    ");
    $olmedo_evals->execute([$olmedo_id]);
    while ($row = $olmedo_evals->fetch(PDO::FETCH_ASSOC)) {
        echo "Evaluation: {$row['teacher_name']} (Teacher Dept: {$row['teacher_dept']}) | Eval Dept: {$row['eval_dept']}<br>";
    }
    
    echo "<hr>";
    echo "<h2>Why Olmedo's Data Shows</h2>";
    echo "The teacher in Olmedo's evaluation is from the same department as the evaluation department,<br>";
    echo "so the query condition (t.department = eval_department) is satisfied.<br>";
    echo "<br>";
    echo "<h2>Why Wendell's Data Doesn't Show</h2>";
    echo "Wendell evaluated a CCIS teacher but marked it as SHS evaluation.<br>";
    echo "The query requires t.department = 'SHS', but the teacher is CCIS.<br>";
} else {
    echo "Olmedo not found<br>";
}
?>
