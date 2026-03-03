<?php
session_start();

// Log the logout
if(isset($_SESSION['user_id'])) {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    $log_query = "INSERT INTO audit_logs (user_id, action, description, ip_address) 
                 VALUES (:user_id, 'LOGOUT', 'User logged out of the system', :ip_address)";
    $log_stmt = $db->prepare($log_query);
    $log_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $log_stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
    $log_stmt->execute();
}

// Destroy all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: ../login.php");
exit();
?>