<?php
session_start();

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Validate CSRF token from POST data.
 * Call this at the top of any POST form handler.
 */
function csrf_validate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo 'Invalid security token. Please refresh the page and try again.';
        exit();
    }
    return true;
}

/** Output a hidden CSRF input field for forms. */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

// Detect controller/AJAX endpoints early so we can return JSON instead of redirects.
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_controller_endpoint = (strpos($request_uri, '/controllers/') !== false)
    || (strpos($request_uri, '/includes/notification_mark_read.php') !== false)
    || (strpos($request_uri, '/includes/get_teacher_evaluations.php') !== false)
    || (strpos($request_uri, '/includes/get_teachers_by_form_type.php') !== false);

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
// Exception: allow access to specific evaluators pages they share
$leaders_allowed_evaluator_pages = ['/evaluators/evaluation_peac.php', '/evaluators/my_evaluations.php'];
$is_leaders_allowed_page = false;
foreach ($leaders_allowed_evaluator_pages as $allowed_page) {
    if (strpos($_SERVER['REQUEST_URI'], $allowed_page) !== false) {
        $is_leaders_allowed_page = true;
        break;
    }
}
if(!$is_controller_endpoint && !$is_leaders_allowed_page && in_array($user_role, ['president', 'vice_president']) && strpos($_SERVER['REQUEST_URI'], '/leaders/') === false) {
    header("Location: ../leaders/dashboard.php");
    exit();
}

// Department-level administrators
if (
    !$is_controller_endpoint &&
    in_array($user_role, ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator']) &&
    strpos($_SERVER['REQUEST_URI'], '/evaluators/') === false &&
    basename($_SERVER['PHP_SELF']) !== 'dashboard.php'
) {
    header("Location: ../evaluators/dashboard.php");
    exit();
}
?>