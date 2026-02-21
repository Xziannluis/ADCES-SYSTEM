<?php
session_start();
// Detect controller/AJAX endpoints early so we can return JSON instead of redirects.
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_controller_endpoint = (strpos($request_uri, '/controllers/') !== false);

if(!isset($_SESSION['user_id'])) {
    if ($is_controller_endpoint) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    header("Location: ../login.php");
    exit();
}

$current_file = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'];

// For API/controller endpoints, don't enforce "must be in role folder" redirects.
// These endpoints are called via fetch/AJAX from pages in other folders.

// Check if user is accessing correct directory based on role
if(!$is_controller_endpoint && $user_role === 'edp' && strpos($_SERVER['REQUEST_URI'], '/edp/') === false) {
    header("Location: ../edp/dashboard.php");
    exit();
}

// Leaders (President, Vice President) should access the leaders area
if(!$is_controller_endpoint && in_array($user_role, ['president', 'vice_president']) && strpos($_SERVER['REQUEST_URI'], '/leaders/') === false) {
    header("Location: ../leaders/dashboard.php");
    exit();
}

// Department-level administrators
if (
    !$is_controller_endpoint &&
    in_array($user_role, ['dean', 'principal', 'chairperson', 'subject_coordinator']) &&
    strpos($_SERVER['REQUEST_URI'], '/evaluators/') === false &&
    basename($_SERVER['PHP_SELF']) !== 'dashboard.php'
) {
    header("Location: ../evaluators/dashboard.php");
    exit();
}
?>