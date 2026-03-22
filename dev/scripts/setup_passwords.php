<?php
/**
 * PASSWORD SETUP HELPER
 * Run this once to set up bcrypt password hashes for test users
 */

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("Database connection failed. Make sure database_complete.sql has been imported.");
}

// Test users with their passwords
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
echo "<pre>";

foreach ($test_users as $user) {
    $hashed = password_hash($user['password'], PASSWORD_BCRYPT);
    
    $query = "UPDATE users SET password = :password WHERE username = :username";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashed);
    $stmt->bindParam(':username', $user['username']);
    
    if ($stmt->execute()) {
        echo "✓ Updated {$user['username']} with password: {$user['password']}\n";
    } else {
        echo "✗ Failed to update {$user['username']}\n";
    }
}

echo "</pre>";
echo "<h3 style='color: green;'>✓ Password setup complete!</h3>";
echo "<p><strong>You can now login with the test credentials above.</strong></p>";
?>
