<?php
/**
 * Check teacher_departments and department assignments for Gosela/Wendell
 */
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<!DOCTYPE html>\n<html>\n<head><style>";
echo "body { font-family: Arial; margin: 20px; }";
echo "h2 { color: #333; border-bottom: 2px solid #333; }";
echo "table { border-collapse: collapse; margin: 20px 0; }";
echo "td, th { border: 1px solid #ccc; padding: 8px; text-align: left; }";
echo "th { background-color: #f0f0f0; }";
echo ".warn { background-color: #ffeeee; }";
echo "</style></head>\n<body>\n";

// Get the problematic teacher
$teacher_stmt = $db->prepare("
    SELECT id, name, department 
    FROM teachers 
    WHERE name LIKE '%Gosela%' OR name LIKE '%Wendell%'
    ORDER BY id DESC
    LIMIT 10
");
$teacher_stmt->execute();
$teachers = $teacher_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>Teacher Department Assignments Check</h1>";

foreach ($teachers as $teacher) {
    echo "<h2>Teacher: {$teacher['name']} (ID: {$teacher['id']}, Primary Dept: {$teacher['department']})</h2>";
    
    // Check if there's a teacher_departments record
    $td_stmt = $db->prepare("
        SELECT id, department, created_at FROM teacher_departments 
        WHERE teacher_id = ?
    ");
    $td_stmt->execute([$teacher['id']]);
    $dept_assignments = $td_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($dept_assignments)) {
        echo "<p><strong>Secondary Department Assignments:</strong></p>";
        echo "<table border='1'>";
        echo "<tr><th>Department</th><th>Assigned</th></tr>";
        foreach ($dept_assignments as $da) {
            echo "<tr>";
            echo "<td>{$da['department']}</td>";
            echo "<td>{$da['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p><em>No secondary department assignments</em></p>";
    }
    
    // Check evaluations
    echo "<p><strong>Evaluations for this teacher:</strong></p>";
    $eval_stmt = $db->prepare("
        SELECT e.id, e.evaluator_id, e.department as eval_dept, e.status, e.academic_year, e.semester,
               u.name as eval_by, u.department as eval_by_dept
        FROM evaluations e
        LEFT JOIN users u ON e.evaluator_id = u.id
        WHERE e.teacher_id = ?
        ORDER BY e.academic_year DESC, e.id DESC
    ");
    $eval_stmt->execute([$teacher['id']]);
    $evals = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($evals)) {
        echo "<table border='1'>";
        echo "<tr><th>Eval ID</th><th>Evaluator (Dept)</th><th>Eval Dept</th><th>Status</th><th>Year/Sem</th></tr>";
        foreach ($evals as $e) {
            echo "<tr>";
            echo "<td>{$e['id']}</td>";
            echo "<td>{$e['eval_by']} ({$e['eval_by_dept']})</td>";
            echo "<td>" . ($e['eval_dept'] ? $e['eval_dept'] : '<em>NULL</em>') . "</td>";
            echo "<td>{$e['status']}</td>";
            echo "<td>{$e['academic_year']} {$e['semester']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p><em>No evaluations</em></p>";
    }
    
    echo "<hr>";
}

// Also check what columns exist in teachers table
echo "<h2>Teachers Table Structure</h2>";
$columns_stmt = $db->prepare("DESCRIBE teachers");
$columns_stmt->execute();
$columns = $columns_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    echo "<td><strong>{$col['Field']}</strong></td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "</tr>";
}
echo "</table>";

?>
