<?php
/**
 * Diagnostic script to identify why Gosela appears in CCIS when printed by SHS dean
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
echo ".warn { background-color: #ffeeee; color: red; }";
echo ".good { background-color: #eeffee; color: green; }";
echo "</style></head>\n<body>\n";

echo "<h1>WENDELL GONZAG DATA VISIBILITY ISSUE DIAGNOSIS</h1>";
echo "<p>Checking why Gosela (teacher) appears in CCIS when CCIS DEAN prints...</p>\n";

// 1. Find Wendell's user record
echo "<h2>1. Find Wendell's User Record</h2>";
$wendell_stmt = $db->prepare("SELECT id, name, role, department FROM users WHERE name LIKE '%Wendell%' OR name LIKE '%Gonzag%'");
$wendell_stmt->execute();
$wendell = $wendell_stmt->fetch(PDO::FETCH_ASSOC);

if ($wendell) {
    echo "Found: <strong>{$wendell['name']}</strong><br>";
    echo "User ID: {$wendell['id']}<br>";
    echo "Role: {$wendell['role']}<br>";
    echo "Department (from users table): <strong>{$wendell['department']}</strong><br>";
} else {
    echo "<span class='warn'>Wendell not found in users table!</span>";
    exit;
}

// 2. Find Gosela's teacher record
echo "<h2>2. Find Gosela's Teacher Record</h2>";
$gosela_stmt = $db->prepare("SELECT id, name, department, scheduled_department, scheduled_by FROM teachers WHERE name LIKE '%Gosela%' OR name LIKE '%Gonzag%' ORDER BY id DESC LIMIT 5");
$gosela_stmt->execute();
$goselas = $gosela_stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($goselas)) {
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Department (Primary)</th><th>scheduled_department</th><th>scheduled_by</th></tr>";
    foreach ($goselas as $g) {
        $class = ($g['department'] !== 'SHS' || !empty($g['scheduled_department'])) ? 'warn' : 'good';
        echo "<tr class='$class'>";
        echo "<td>{$g['id']}</td>";
        echo "<td>{$g['name']}</td>";
        echo "<td>{$g['department']}</td>";
        echo "<td>" . ($g['scheduled_department'] ? $g['scheduled_department'] : '<em>NULL</em>') . "</td>";
        echo "<td>" . ($g['scheduled_by'] ? $g['scheduled_by'] : '<em>NULL</em>') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if (count($goselas) > 1) {
        echo "<p><strong>⚠ Multiple Gosela records found. Using last one (ID: {$goselas[0]['id']}) for evaluation check.</strong></p>";
    }
    $gosela = $goselas[0];
} else {
    echo "<span class='warn'>No Gosela/Gonzag found in teachers table!</span>";
    exit;
}

// 3. Find evaluations linking Wendell (evaluator) to Gosela (teacher)
echo "<h2>3. Evaluations: Wendell evaluating Gosela</h2>";
$eval_stmt = $db->prepare("
    SELECT e.id, e.evaluator_id, e.teacher_id, e.department as eval_department, 
           e.academic_year, e.semester, e.status, e.created_at,
           u.name as evaluator_name, u.department as evaluator_dept,
           t.name as teacher_name, t.department as teacher_dept
    FROM evaluations e
    JOIN users u ON e.evaluator_id = u.id
    JOIN teachers t ON e.teacher_id = t.id
    WHERE e.evaluator_id = ? AND e.teacher_id = ?
    ORDER BY e.created_at DESC
");
$eval_stmt->execute([$wendell['id'], $gosela['id']]);
$evaluations = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($evaluations)) {
    echo "<table border='1'><tr><th>Eval ID</th><th>Academic Year</th><th>Semester</th><th>Status</th><th>Eval Department</th><th>Evaluator Dept</th><th>Teacher Dept</th></tr>";
    foreach ($evaluations as $e) {
        $class = ($e['eval_department'] !== 'CCIS') ? 'warn' : 'good';
        echo "<tr class='$class'>";
        echo "<td>{$e['id']}</td>";
        echo "<td>{$e['academic_year']}</td>";
        echo "<td>{$e['semester']}</td>";
        echo "<td>{$e['status']}</td>";
        echo "<td>" . ($e['eval_department'] ? $e['eval_department'] : '<span class="warn"><em>NULL - THIS IS THE PROBLEM!</em></span>') . "</td>";
        echo "<td>{$e['evaluator_dept']}</td>";
        echo "<td>{$e['teacher_dept']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No evaluations found between Wendell and Gosela</p>";
}

// 4. Test the print query logic for CCIS DEAN
echo "<h2>4. What Would CCIS DEAN See (Current Query)?</h2>";
echo "<p><strong>Scenario:</strong> User is dean with department='CCIS', printing CCIS department, semester 1st, year 2025-2026</p>";

$ccis_dean_query = "
    SELECT DISTINCT t.id, t.name, t.department as teacher_department,
           t.scheduled_department,
           e.id as eval_id, e.department as eval_department, e.evaluator_id,
           u.name as evaluator_name, u.department as evaluator_dept
    FROM teachers t
    JOIN evaluations e ON e.teacher_id = t.id
    LEFT JOIN users u ON u.id = e.evaluator_id
    WHERE (
        e.department = ?
        OR (
            (e.department IS NULL OR e.department = '')
            AND t.scheduled_department = ?
        )
        OR (
            (e.department IS NULL OR e.department = '')
            AND (t.scheduled_department IS NULL OR t.scheduled_department = '')
            AND t.department = ?
        )
    )
    AND (t.user_id IS NULL OR t.user_id != ?)
    AND e.academic_year = ?
    AND e.semester = ?
    ORDER BY t.name ASC
";

$ccis_query_stmt = $db->prepare($ccis_dean_query);
$ccis_query_stmt->execute(['CCIS', 'CCIS', 'CCIS', 0, '2025-2026', '1st']);
$ccis_results = $ccis_query_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($ccis_results) . " teachers in CCIS query</p>";

// Check if Gosela is in there
$gosela_found = false;
foreach ($ccis_results as $r) {
    if ($r['id'] === $gosela['id']) {
        $gosela_found = true;
        echo "<p class='warn'><strong>⚠ GOSELA FOUND IN CCIS RESULTS!</strong></p>";
        break;
    }
}

if ($gosela_found) {
    echo "<h2>5. WHY IS GOSELA IN CCIS QUERY?</h2>";
    
    // Check each condition
    foreach ($evaluations as $e) {
        echo "<div style='background:#eee; padding:10px; margin:10px 0;'>";
        echo "<strong>Evaluation ID {$e['id']}:</strong><br>";
        
        if ($e['eval_department'] === 'CCIS') {
            echo "✓ <strong>Reason:</strong> e.department = 'CCIS'<br>";
        } elseif ($e['eval_department'] === null || $e['eval_department'] === '') {
            echo "✗ e.department IS NULL/empty<br>";
            
            if ($gosela['scheduled_department'] === 'CCIS') {
                echo "✓ <strong>Reason:</strong> e.department is NULL AND t.scheduled_department = 'CCIS'<br>";
            } elseif ($gosela['scheduled_department'] === null || $gosela['scheduled_department'] === '') {
                echo "✗ t.scheduled_department IS NULL/empty<br>";
                
                if ($gosela['department'] === 'CCIS') {
                    echo "✓ <strong>Reason (BUG):</strong> e.department IS NULL AND t.scheduled_department IS NULL AND <strong>t.department = 'CCIS' (WRONG!)</strong><br>";
                    echo "  → This falls through to the third condition and matches because<br>";
                    echo "  → Gosela's PRIMARY department is checked, not EVALUATOR's department!<br>";
                } else {
                    echo "✗ t.department = {$gosela['department']}, not CCIS<br>";
                }
            }
        }
        
        echo "</div>";
    }
} else {
    echo "<p class='good'>✓ Gosela NOT found in CCIS query - looks OK for now</p>";
}

echo "<h2>6. RECOMMENDED FIX</h2>";
echo "<p>The issue is in the third condition of the CCIS DEAN query:</p>";
echo "<code>";
echo "OR (<br>&nbsp;&nbsp;(e.department IS NULL OR e.department = '')<br>";
echo "&nbsp;&nbsp;AND (t.scheduled_department IS NULL OR t.scheduled_department = '')<br>";
echo "&nbsp;&nbsp;<span style='color:red;'>AND (t.department = :department)</span> ← <strong>WRONG!</strong><br>";
echo ")<br>";
echo "</code>";
echo "<p>When e.department is NULL, the query falls back to checking the TEACHER's primary department.<br>";
echo "But it should check if the EVALUATOR's department matches instead!</p>";

echo "<p><strong>The fix should be:</strong> When e.department is NULL and there's no scheduled_department,<br>";
echo "don't automatically match based on teacher department. Instead, only show evaluations where:</p>";
echo "<ul>";
echo "<li>e.department is explicitly set to 'CCIS' (evaluator set this), OR</li>";
echo "<li>t.scheduled_department is explicitly set to 'CCIS' (admin scheduled for CCIS), OR</li>";
echo "<li>The evaluator_id belongs to the CCIS department (evaluator is in CCIS)</li>";
echo "</ul>";

echo "</body>\n</html>";
?>
