<?php
/**
 * Refined diagnostic - check ALL Gosela/Wendell teacher records and their evaluations
 */
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<!DOCTYPE html>\n<html>\n<head><style>";
echo "body { font-family: Arial; margin: 20px; }";
echo "h2 { color: #333; border-bottom: 2px solid #333; }";
echo "h3 { color: #666; }";
echo "table { border-collapse: collapse; margin: 20px 0; }";
echo "td, th { border: 1px solid #ccc; padding: 8px; text-align: left; }";
echo "th { background-color: #f0f0f0; }";
echo ".warn { background-color: #ffeeee; color: red; }";
echo ".good { background-color: #eeffee; color: green; }";
echo ".info { background-color: #eeeeff; }";
echo "</style></head>\n<body>\n";

echo "<h1>TEACHER DATA VISIBILITY ISSUE - DETAILED DIAGNOSIS</h1>";

// 1. Get ALL teachers with Gosela/Wendell in the name
echo "<h2>Step 1: All Teachers with 'Gosela' or 'Wendell'</h2>";
$all_teachers_stmt = $db->prepare("
    SELECT id, name, department, scheduled_department, user_id, created_at
    FROM teachers 
    WHERE name LIKE '%Gosela%' OR name LIKE '%Wendell%'
    ORDER BY id DESC
");
$all_teachers_stmt->execute();
$all_teachers = $all_teachers_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($all_teachers)) {
    echo "<p>No teachers found with that name</p>";
} else {
    echo "<table border='1'>";
    echo "<tr><th>Teacher ID</th><th>Name</th><th>Department</th><th>scheduled_department</th><th>user_id</th></tr>";
    foreach ($all_teachers as $t) {
        echo "<tr>";
        echo "<td>{$t['id']}</td>";
        echo "<td>{$t['name']}</td>";
        echo "<td>" . ($t['department'] ? "<strong>{$t['department']}</strong>" : '<em>NULL</em>') . "</td>";
        echo "<td>" . ($t['scheduled_department'] ? "<strong>{$t['scheduled_department']}</strong>" : '<em>NULL</em>') . "</td>";
        echo "<td>" . ($t['user_id'] ? $t['user_id'] : '<em>NULL</em>') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2. For each teacher, check their evaluations
echo "<h2>Step 2: Evaluations for Each Teacher</h2>";

foreach ($all_teachers as $teacher) {
    echo "<h3>Teacher ID {$teacher['id']}: {$teacher['name']} (Department: {$teacher['department']})</h3>";
    
    $eval_stmt = $db->prepare("
        SELECT e.id, e.evaluator_id, e.department as eval_department, e.academic_year, e.semester, e.status,
               u.name as evaluator_name, u.department as evaluator_dept, u.role as evaluator_role
        FROM evaluations e
        LEFT JOIN users u ON e.evaluator_id = u.id
        WHERE e.teacher_id = ?
        ORDER BY e.created_at DESC
    ");
    $eval_stmt->execute([$teacher['id']]);
    $evals = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($evals)) {
        echo "<p><em>No evaluations for this teacher</em></p>";
    } else {
        echo "<table border='1'>";
        echo "<tr><th>Eval ID</th><th>Evaluator</th><th>Eval Dept</th><th>Status</th><th>Year</th><th>Sem</th></tr>";
        foreach ($evals as $e) {
            $class = ($e['eval_department'] !== $teacher['department'] && $e['eval_department'] !== null) ? 'warn' : '';
            echo "<tr class='$class'>";
            echo "<td>{$e['id']}</td>";
            echo "<td>{$e['evaluator_name']} ({$e['evaluator_dept']})</td>";
            echo "<td>" . ($e['eval_department'] ? "<strong>{$e['eval_department']}</strong>" : '<em>NULL</em>') . "</td>";
            echo "<td>{$e['status']}</td>";
            echo "<td>{$e['academic_year']}</td>";
            echo "<td>{$e['semester']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

// 3. Test what CCIS DEAN query returns with these teachers
echo "<h2>Step 3: CCIS DEAN Query Results (Current Logic)</h2>";

$ccis_query = "
    SELECT DISTINCT t.id, t.name, t.department as teacher_dept, t.scheduled_department,
           e.id as eval_id, e.department as eval_dept, e.evaluator_id,
           u.name as evaluator_name, u.department as evaluator_dept
    FROM teachers t
    JOIN evaluations e ON e.teacher_id = t.id
    LEFT JOIN users u ON u.id = e.evaluator_id
    WHERE (
        e.department = 'CCIS'
        OR (
            (e.department IS NULL OR e.department = '')
            AND t.scheduled_department = 'CCIS'
        )
        OR (
            (e.department IS NULL OR e.department = '')
            AND (t.scheduled_department IS NULL OR t.scheduled_department = '')
            AND t.department = 'CCIS'
        )
    )
    AND e.academic_year = '2025-2026'
    AND e.semester = '1st'
    ORDER BY t.name ASC
";

$ccis_stmt = $db->prepare($ccis_query);
$ccis_stmt->execute();
$ccis_results = $ccis_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Query found <strong>" . count($ccis_results) . "</strong> teachers</p>";

if (count($ccis_results) > 0) {
    echo "<table border='1'>";
    echo "<tr><th>Teacher ID</th><th>Teacher Name</th><th>Teacher Dept</th><th>Eval Dept</th><th>Evaluator</th><th>Reason</th></tr>";
    foreach ($ccis_results as $r) {
        echo "<tr>";
        echo "<td>{$r['id']}</td>";
        echo "<td>{$r['name']}</td>";
        echo "<td>{$r['teacher_dept']}</td>";
        echo "<td>" . ($r['eval_dept'] ? $r['eval_dept'] : '<em>NULL</em>') . "</td>";
        echo "<td>{$r['evaluator_name']}</td>";
        
        // Determine why this row was included
        if ($r['eval_dept'] === 'CCIS') {
            echo "<td>e.department = CCIS</td>";
        } elseif ($r['scheduled_department'] === 'CCIS') {
            echo "<td>t.scheduled_department = CCIS</td>";
        } elseif ($r['teacher_dept'] === 'CCIS') {
            echo "<td class='warn'>t.department = CCIS (fallback - POTENTIAL BUG)</td>";
        } else {
            echo "<td>Unknown</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}

// 4. Check if any non-CCIS teachers appear in CCIS results
echo "<h2>Step 4: Are Non-CCIS Teachers Appearing in CCIS Results?</h2>";

$non_ccis_in_ccis = array_filter($ccis_results, function($r) {
    return $r['teacher_dept'] !== 'CCIS';
});

if (!empty($non_ccis_in_ccis)) {
    echo "<p class='warn'><strong>⚠ YES! Non-CCIS teachers are showing up in CCIS:</strong></p>";
    echo "<ul>";
    foreach ($non_ccis_in_ccis as $r) {
        echo "<li>{$r['name']} (Dept: {$r['teacher_dept']}) - ID: {$r['id']}</li>";
    }
    echo "</ul>";
} else {
    echo "<p class='good'><strong>✓ No non-CCIS teachers in CCIS results</strong></p>";
}

// 5. Show the problematic query logic
echo "<h2>Step 5: The Problem</h2>";
echo "<p>The CCIS DEAN query has a fallback condition:</p>";
echo "<pre style='background:#fafafa; padding:10px;'>";
echo "OR (<br>";
echo "    (e.department IS NULL OR e.department = '')<br>";
echo "    AND (t.scheduled_department IS NULL OR t.scheduled_department = '')<br>";
echo "    <span style='color:red;'>AND t.department = 'CCIS'</span>  ← <strong>Checks TEACHER department, not evaluator!</strong><br>";
echo ")<br>";
echo "</pre>";
echo "<p>This means: When an evaluation has NO department set, and the teacher has NO scheduled_department,<br>";
echo "the query defaults to checking the teacher's PRIMARY department. <br><br>";
echo "<strong>If the teacher's primary department is CCIS, they'll show up in CCIS results even if:</strong>";
echo "<ul>";
echo "<li>The evaluation was created by an SHS evaluator</li>";
echo "<li>The evaluation should be SHS</li>";
echo "<li>The teacher has moved to another department</li>";
echo "</ul>";

?>
