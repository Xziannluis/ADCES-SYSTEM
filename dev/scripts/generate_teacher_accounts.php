<?php
require_once 'config/database.php';
require_once 'models/User.php';
require_once 'models/Teacher.php';

$db = (new Database())->getConnection();
$user = new User($db);
$teacher = new Teacher($db);

// Get all teachers
$stmt = $db->query("SELECT * FROM teachers WHERE status = 'active'");
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$created_count = 0;
$failed_count = 0;
$existing_count = 0;

echo "<h2>Teacher Account Generation System</h2>";
echo "<hr>";

foreach($teachers as $teacher_data) {
    // Generate username from teacher name (first letter of first name + last name)
    $name_parts = explode(' ', $teacher_data['name']);
    $base_username = strtolower(substr($name_parts[0], 0, 1) . end($name_parts));
    
    // Add teacher ID to make it unique
    $username = $base_username . $teacher_data['id'];
    
    // Generate a random password
    $password = 'Teacher@' . substr(str_shuffle('0123456789'), 0, 4) . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2);
    
    // Check if account already exists
    if($user->usernameExists($username)) {
        echo "<span style='color: orange;'>⚠ SKIP - Account already exists for <strong>{$teacher_data['name']}</strong> (Username: {$username})</span><br>";
        $existing_count++;
        continue;
    }
    
    // Create user account
    $user_data = [
        'username' => $username,
        'password' => $password,
        'name' => $teacher_data['name'],
        'role' => 'teacher',
        'department' => $teacher_data['department']
    ];
    
    $result = $user->create($user_data);
    
    if($result) {
        // Get the user ID that was just created
        $query = "SELECT id FROM users WHERE username = :username";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $new_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update the teacher record with the user_id
        $update_query = "UPDATE teachers SET user_id = :user_id WHERE id = :teacher_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':user_id', $new_user['id']);
        $update_stmt->bindParam(':teacher_id', $teacher_data['id']);
        $update_stmt->execute();
        
        echo "<span style='color: green;'>✓ SUCCESS - Account created for <strong>{$teacher_data['name']}</strong></span><br>";
        echo "  Username: <code>{$username}</code> | Password: <code>{$password}</code><br>";
        echo "  Department: {$teacher_data['department']}<br><br>";
        
        $created_count++;
    } else {
        echo "<span style='color: red;'>✗ FAILED - Could not create account for <strong>{$teacher_data['name']}</strong></span><br>";
        $failed_count++;
    }
}

echo "<hr>";
echo "<h3>Summary</h3>";
echo "Created: <strong style='color: green;'>{$created_count}</strong> accounts<br>";
echo "Already Existing: <strong style='color: orange;'>{$existing_count}</strong> accounts<br>";
echo "Failed: <strong style='color: red;'>{$failed_count}</strong> accounts<br>";
echo "<hr>";
echo "<p><a href='index.php'><strong>← Back to Login</strong></a></p>";
?>
