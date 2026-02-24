<?php
session_start();

// Log the logout
if(isset($_SESSION['user_id'])) {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();

    $user_id = $_SESSION['user_id'];
    $user_exists = false;

    try {
        $check_stmt = $db->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
        $check_stmt->bindParam(':id', $user_id);
        $check_stmt->execute();
        $user_exists = $check_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('Logout user check failed: ' . $e->getMessage());
    }
    
    if ($user_exists) {
        try {
            $log_query = "INSERT INTO audit_logs (user_id, action, description, ip_address) 
                         VALUES (:user_id, 'LOGOUT', 'User logged out of the system', :ip_address)";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->bindParam(':user_id', $user_id);
            $log_stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
            $log_stmt->execute();
        } catch (PDOException $e) {
            error_log('Logout audit log failed: ' . $e->getMessage());
        }
    }
}

// Destroy all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: ../login.php");
exit();
?>