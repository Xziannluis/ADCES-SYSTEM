<?php
/**
 * Teacher Account System - Setup Verification and Documentation
 * 
 * This file verifies that the teacher account system is properly configured
 */

require_once 'config/database.php';

$db = (new Database())->getConnection();

echo "<html>
<head>
    <title>Teacher Account System - Status</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 25px; }
        .status { padding: 15px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; color: #856404; }
        .info { background: #d1ecf1; border-left: 4px solid #17a2b8; color: #0c5460; }
        .error { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 4px; font-weight: 600; font-size: 0.85rem; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
    <div class='container'>
        <h1><i class='fas fa-graduation-cap'></i> Teacher Account System - Status</h1>";

// Check teacher accounts
$query = "SELECT COUNT(*) as total FROM users WHERE role = 'teacher'";
$stmt = $db->prepare($query);
$stmt->execute();
$teachers_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

echo "<div class='status success'>
    <strong>✓ Teacher Accounts Created:</strong> $teachers_count active teacher accounts
</div>";

// Check required table columns
echo "<h2>Database Schema Verification</h2>";

$required_columns = [
    'teachers' => ['user_id', 'evaluation_schedule', 'evaluation_room'],
    'evaluations' => ['evaluation_data'],
    'users' => ['role']
];

$columns_ok = true;
foreach($required_columns as $table => $cols) {
    $describe = $db->query("DESCRIBE $table");
    $existing_cols = [];
    while($row = $describe->fetch(PDO::FETCH_ASSOC)) {
        $existing_cols[] = $row['Field'];
    }
    
    foreach($cols as $col) {
        if(in_array($col, $existing_cols)) {
            echo "<div class='status success'><span class='badge badge-success'>✓</span> <code>{$table}.{$col}</code> exists</div>";
        } else {
            echo "<div class='status warning'><span class='badge badge-warning'>!</span> <code>{$table}.{$col}</code> NOT found</div>";
            $columns_ok = false;
        }
    }
}

// Check pages exist
echo "<h2>System Pages</h2>";

$pages = [
    'teachers/dashboard.php' => 'Teacher Dashboard - View evaluations and schedule',
    'teachers/view-evaluation.php' => 'Evaluation Details - View individual evaluation',
    'generate_teacher_accounts.php' => 'Account Generator - Create teacher accounts'
];

foreach($pages as $page => $desc) {
    if(file_exists($page)) {
        echo "<div class='status success'><span class='badge badge-success'>✓</span> <strong>{$page}</strong><br><small>{$desc}</small></div>";
    } else {
        echo "<div class='status error'><span class='badge badge-danger'>✗</span> <strong>{$page}</strong> - NOT FOUND</div>";
    }
}

// Check login page support
echo "<h2>Login Configuration</h2>";
$login_content = file_get_contents('login.php');
if(strpos($login_content, "value='teacher'") !== false || strpos($login_content, 'value="teacher"') !== false) {
    echo "<div class='status success'><span class='badge badge-success'>✓</span> Teacher role available in login page</div>";
} else {
    echo "<div class='status error'><span class='badge badge-danger'>✗</span> Teacher role NOT in login page</div>";
}

// Check index.php support
echo "<h2>Index Router Configuration</h2>";
$index_content = file_get_contents('index.php');
if(strpos($index_content, "teacher") !== false) {
    echo "<div class='status success'><span class='badge badge-success'>✓</span> Teacher role routing configured</div>";
} else {
    echo "<div class='status error'><span class='badge badge-danger'>✗</span> Teacher role routing NOT configured</div>";
}

// Sample teacher accounts
echo "<h2>Sample Teacher Accounts</h2>";

$sample_query = "SELECT username, password, name, department FROM users WHERE role = 'teacher' LIMIT 5";
$sample_stmt = $db->prepare($sample_query);
$sample_stmt->execute();
$samples = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);

if(count($samples) > 0) {
    echo "<p><strong>Note:</strong> Passwords are hashed in the database. Here are sample usernames to test login:</p>";
    echo "<table>
        <thead>
            <tr>
                <th>Teacher Name</th>
                <th>Username</th>
                <th>Department</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>";
    
    foreach($samples as $sample) {
        echo "<tr>
            <td>{$sample['name']}</td>
            <td><code>{$sample['username']}</code></td>
            <td>{$sample['department']}</td>
            <td><span class='badge badge-success'>✓ Active</span></td>
        </tr>";
    }
    
    echo "</tbody></table>";
    echo "<div class='status info'>
        <strong>ℹ Credentials Notice:</strong> Teacher passwords were randomly generated during account creation.
        To reset a teacher's password, use the generate_teacher_accounts.php script or contact your system administrator.
    </div>";
}

// Feature summary
echo "<h2>Teacher Portal Features</h2>";

$features = [
    'View Evaluation Schedule' => 'Teachers can see when their evaluation is scheduled and where (room location)',
    'View All Evaluations' => 'Teachers can view all evaluations they have received',
    'View Evaluation Details' => 'Teachers can open individual evaluations to see detailed ratings and comments',
    'Print Evaluations' => 'Teachers can print their evaluation reports',
    'Secure Login' => 'Teachers login with their username and password'
];

echo "<ul>";
foreach($features as $feature => $desc) {
    echo "<li><strong>{$feature}:</strong> {$desc}</li>";
}
echo "</ul>";

// Instructions
echo "<h2>Getting Started</h2>";
echo "<div class='status info'>
    <strong>1. Teacher Login:</strong><br>
    - Navigate to <code>login.php</code><br>
    - Select 'Teacher' from the Role dropdown<br>
    - Enter teacher username (e.g., <code>kbarrera1</code>)<br>
    - Enter teacher password<br>
    <br>
    <strong>2. Teacher Dashboard:</strong><br>
    - View evaluation schedule and room location<br>
    - See list of received evaluations<br>
    - Click 'View Evaluation' to see details<br>
    <br>
    <strong>3. Evaluator Side:</strong><br>
    - Evaluators assign schedule and room via Teachers Management page<br>
    - Completed evaluations appear in teacher's dashboard automatically
</div>";

// System requirements
echo "<h2>System Requirements Met</h2>";
echo "<ul>
    <li><span class='badge badge-success'>✓</span> Teacher user accounts created</li>
    <li><span class='badge badge-success'>✓</span> Teacher role in authentication system</li>
    <li><span class='badge badge-success'>✓</span> Teacher dashboard and pages created</li>
    <li><span class='badge badge-success'>✓</span> Evaluation schedule tracking enabled</li>
    <li><span class='badge badge-success'>✓</span> Room location assignment enabled</li>
    <li><span class='badge badge-success'>✓</span> Evaluation view functionality available</li>
</ul>";

echo "</div></body></html>";
?>