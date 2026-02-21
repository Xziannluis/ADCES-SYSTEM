<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("<h2 style='color:red'>Cannot connect to database</h2>");
}

// Test credentials with their plain passwords
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

echo "<h2>Updating Password Hashes</h2>";
echo "<p>Generating bcrypt hashes for all test users...</p>";
echo "<ul>";

foreach ($test_users as $user) {
    $hashed = password_hash($user['password'], PASSWORD_BCRYPT);
    
    $query = "UPDATE users SET password = :password WHERE username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashed);
    $stmt->bindParam(':username', $user['username']);
    
    try {
        if ($stmt->execute()) {
            echo "<li>✓ <strong>" . htmlspecialchars($user['username']) . "</strong> : " . htmlspecialchars($user['password']) . "</li>";
        } else {
            echo "<li>✗ Failed to update " . htmlspecialchars($user['username']) . "</li>";
        }
    } catch (Exception $e) {
        echo "<li>✗ Error updating " . htmlspecialchars($user['username']) . ": " . $e->getMessage() . "</li>";
    }
}

echo "</ul>";

// Test the hashes
echo "<h3>Verification Test (after update)</h3>";

foreach (array_slice($test_users, 0, 2) as $cred) {
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
        echo "Verification: <strong style='color:" . ($verify ? 'green' : 'red') . "'>" . ($verify ? 'PASS ✓' : 'FAIL ✗') . "</strong>";
        echo "</p>";
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Update Password Hashes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 30px; }
        .alert-success { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="alert alert-success" role="alert">
            <h4 class="alert-heading">✓ All passwords updated!</h4>
            <p>You can now try logging in at <a href="login.php">login.php</a></p>
            <hr>
            <p class="mb-0">Test with any of the credentials shown above.</p>
        </div>
    </div>
</body>
</html>
