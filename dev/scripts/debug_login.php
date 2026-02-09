<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("<h2 style='color:red'>Cannot connect to database</h2>");
}

echo "<h2>Database Diagnostic</h2>";

// Check if users table exists
try {
    $result = $db->query("SELECT COUNT(*) as total FROM users");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>Total users:</strong> " . $row['total'] . "</p>";
} catch (Exception $e) {
    die("<p style='color:red'>Error querying users: " . $e->getMessage() . "</p>");
}

// List all users
try {
    $stmt = $db->query("SELECT id, username, name, role, department, status FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "<p style='color:orange'>No users found in database</p>";
    } else {
        echo "<h3>Users in Database:</h3>";
        echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
        echo "<tr><th>ID</th><th>Username</th><th>Name</th><th>Role</th><th>Department</th><th>Status</th></tr>";
        foreach ($users as $u) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($u['id']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($u['username']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($u['name']) . "</td>";
            echo "<td>" . htmlspecialchars($u['role']) . "</td>";
            echo "<td>" . htmlspecialchars($u['department'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($u['status']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test password verification
echo "<h3>Password Verification Test</h3>";
$test_credentials = [
    ['username' => 'teacher_john', 'password' => 'teacher123'],
    ['username' => 'edp_user', 'password' => 'edp123'],
];

foreach ($test_credentials as $cred) {
    try {
        $query = "SELECT password FROM users WHERE username = :username LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $cred['username']);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $hash = $row['password'];
            $verify = password_verify($cred['password'], $hash);
            
            echo "<p>";
            echo "Username: <strong>" . htmlspecialchars($cred['username']) . "</strong>, ";
            echo "Password: <strong>" . htmlspecialchars($cred['password']) . "</strong><br>";
            echo "Hash (truncated): " . htmlspecialchars(substr($hash, 0, 40)) . "...<br>";
            echo "Verification: <strong style='color:" . ($verify ? 'green' : 'red') . "'>" . ($verify ? 'PASS ✓' : 'FAIL ✗') . "</strong>";
            echo "</p>";
        } else {
            echo "<p style='color:red'>User not found: " . htmlspecialchars($cred['username']) . "</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Diagnostic</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        h2, h3 { color: #333; }
        table { background: white; margin: 10px 0; }
        p { line-height: 1.6; }
    </style>
</head>
<body>
</body>
</html>
