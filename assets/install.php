<?php
/**
 * INSTALLATION SCRIPT
 * Imports the database and sets up test users
 */

// Connection to MySQL server (no database selected yet)
try {
    $conn = new PDO("mysql:host=localhost", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<h2>✓ Connected to MySQL server</h2>";
} catch(PDOException $e) {
    die("<h2 style='color: red;'>✗ Cannot connect to MySQL: " . $e->getMessage() . "</h2>");
}

// Drop and recreate database
try {
    $conn->exec("DROP DATABASE IF EXISTS `ai_classroom_eval`");
    echo "<p>✓ Dropped old database (if exists)</p>";
    
    $conn->exec("CREATE DATABASE `ai_classroom_eval` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p>✓ Created new database</p>";
} catch(PDOException $e) {
    die("<h2 style='color: red;'>✗ Cannot create database: " . $e->getMessage() . "</h2>");
}

// Now connect to the new database
try {
    $db = new PDO("mysql:host=localhost;dbname=ai_classroom_eval", "root", "");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("set names utf8mb4");
    echo "<p>✓ Connected to ai_classroom_eval database</p>";
} catch(PDOException $e) {
    die("<h2 style='color: red;'>✗ Cannot connect to database: " . $e->getMessage() . "</h2>");
}

// Read and execute SQL file
// (organized under database/seed, but keep a fallback for older checkouts)
$sql_file = __DIR__ . '/database/seed/database_complete.sql';
if (!file_exists($sql_file)) {
    $sql_file = __DIR__ . '/database_complete.sql';
}
if (!file_exists($sql_file)) {
    die("<h2 style='color: red;'>✗ database_complete.sql not found!</h2>");
}

$sql_content = file_get_contents($sql_file);

// Clean the SQL: remove full-line comments and top-level DROP/CREATE/USE statements
$lines = preg_split('/\R/', $sql_content);
$filtered = [];
foreach ($lines as $line) {
    $trim = ltrim($line);
    if ($trim === '') continue;
    if (strpos($trim, '--') === 0) continue;
    $low = strtolower(substr($trim, 0, 20));
    if (preg_match('/^(drop database|create database|use\s)/', $low)) continue;
    $filtered[] = $line;
}
$filtered_sql = implode("\n", $filtered);

// Use mysqli multi_query to execute the SQL script reliably
$mysqli = new mysqli('localhost', 'root', '', 'ai_classroom_eval');
if ($mysqli->connect_errno) {
    die("<h2 style='color: red;'>✗ MySQLi connection failed: " . htmlspecialchars($mysqli->connect_error) . "</h2>");
}

$count = 0;
if ($mysqli->multi_query($filtered_sql)) {
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
        if ($mysqli->more_results()) {
            // Advance to next result
        }
        $count++;
    } while ($mysqli->more_results() && $mysqli->next_result());
    echo "<p>✓ Executed SQL script (multi_query). Processed approximately " . $count . " statements or result sets.</p>";
    if ($mysqli->errno) {
        echo "<p style='color: red;'>Warnings/Errors encountered: " . htmlspecialchars($mysqli->error) . "</p>";
    }
} else {
    die("<h2 style='color: red;'>✗ SQL Error (multi_query): " . htmlspecialchars($mysqli->error) . "</h2>");
}

$mysqli->close();

// Update passwords with bcrypt hashes
$test_users = [
    ['username' => 'edp_user', 'password' => 'edp123'],
    ['username' => 'dean_ccs', 'password' => 'dean123'],
    ['username' => 'principal', 'password' => 'principal123'],
    ['username' => 'chairperson_ccs', 'password' => 'chair123'],
    ['username' => 'coordinator_ccs', 'password' => 'coord123'],
    ['username' => 'president', 'password' => 'president123'],
    ['username' => 'vp_academics', 'password' => 'vp123'],
    ['username' => 'teacher_john', 'password' => 'teacher123'],
    ['username' => 'teacher_mary', 'password' => 'teacher123'],
    ['username' => 'teacher_robert', 'password' => 'teacher123'],
];

echo "<h3>Setting up test user passwords:</h3>";
echo "<ul>";

try {
    foreach ($test_users as $user) {
        $hashed = password_hash($user['password'], PASSWORD_BCRYPT);
        
        $query = "UPDATE users SET password = :password WHERE username = :username";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $hashed);
        $stmt->bindParam(':username', $user['username']);
        
        if ($stmt->execute()) {
            echo "<li>✓ <strong>{$user['username']}</strong> : {$user['password']}</li>";
        } else {
            echo "<li>✗ Failed to update {$user['username']}</li>";
        }
    }
} catch(PDOException $e) {
    die("<h2 style='color: red;'>✗ Error updating passwords: " . $e->getMessage() . "</h2>");
}

echo "</ul>";

// Verify users were created
try {
    $result = $db->query("SELECT COUNT(*) as total FROM users");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "<p>✓ Total users in database: " . $row['total'] . "</p>";
} catch(PDOException $e) {
    echo "<p style='color: orange;'>Could not verify user count: " . $e->getMessage() . "</p>";
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Installation Complete</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 40px; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="success">✓ Installation Complete!</h1>
    <p class="mt-4">The database has been set up successfully.</p>
    
    <h3 class="mt-4">Test Credentials:</h3>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Username</th>
                <th>Password</th>
                <th>Role</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>teacher_john</td>
                <td>teacher123</td>
                <td>Teacher</td>
            </tr>
            <tr>
                <td>edp_user</td>
                <td>edp123</td>
                <td>EDP</td>
            </tr>
            <tr>
                <td>dean_ccs</td>
                <td>dean123</td>
                <td>Dean</td>
            </tr>
            <tr>
                <td>chairperson_ccs</td>
                <td>chair123</td>
                <td>Chairperson</td>
            </tr>
            <tr>
                <td>principal</td>
                <td>principal123</td>
                <td>Principal</td>
            </tr>
        </tbody>
    </table>
    
    <div class="alert alert-info mt-4">
        <strong>Next Steps:</strong>
        <ol>
            <li>Go to <a href="login.php">login.php</a></li>
            <li>Try logging in with any of the credentials above</li>
            <li>Select the matching role from the dropdown</li>
        </ol>
    </div>
    
    <div class="alert alert-warning mt-4">
        <strong>Important:</strong> You can delete or rename this file (install.php) after installation is complete for security.
    </div>
</div>
</body>
</html>
