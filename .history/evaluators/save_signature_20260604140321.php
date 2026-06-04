<?php
require_once '../auth/session-check.php';
require_once '../config/database.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

$evaluation_id = (int)($input['evaluation_id'] ?? 0);
$signature = trim((string)($input['signature'] ?? ''));
$signature_date = trim((string)($input['signature_date'] ?? ''));
$signer_name = trim((string)($input['signer_name'] ?? ''));

if (!$evaluation_id || !$signature || !$signature_date) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Validate signature format (should be a data URL starting with 'data:image')
if (strpos($signature, 'data:image') !== 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid signature format']);
    exit();
}

// Get database connection
$db = (new Database())->getConnection();

// First, verify that the evaluation exists and belongs to the current user or they are authorized to sign it
$check_query = "SELECT e.id, e.teacher_id, e.evaluator_id, e.status, t.department
                FROM evaluations e
                JOIN teachers t ON e.teacher_id = t.id
                WHERE e.id = :eval_id";

$check_stmt = $db->prepare($check_query);
$check_stmt->bindParam(':eval_id', $evaluation_id, PDO::PARAM_INT);
$check_stmt->execute();
$eval_data = $check_stmt->fetch(PDO::FETCH_ASSOC);

if (!$eval_data) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Evaluation not found']);
    exit();
}

// Check authorization - user must be the evaluator or a dean/coordinator authorized to review this evaluation
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$is_authorized = false;

if ((int)$eval_data['evaluator_id'] === $user_id) {
    $is_authorized = true;
} elseif (in_array($user_role, ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    // For dean/coordinators, they should only be able to sign evaluations for their department
    // Check if they have access to this teacher's department
    if ($user_role === 'chairperson' || $user_role === 'subject_coordinator' || $user_role === 'grade_level_coordinator') {
        // Get the coordinator's assignment info
        $coord_query = "SELECT u.department FROM users u WHERE u.id = :user_id";
        $coord_stmt = $db->prepare($coord_query);
        $coord_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $coord_stmt->execute();
        $coord_data = $coord_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($coord_data && $coord_data['department'] === $eval_data['department']) {
            $is_authorized = true;
        }
    } else {
        // Dean or principal
        $is_authorized = true;
    }
}

if (!$is_authorized) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to sign this evaluation']);
    exit();
}

// Update the evaluation with the signature
$update_query = "UPDATE evaluations 
                 SET rater_signature = :signature,
                     rater_date = :rater_date,
                     updated_at = NOW()
                 WHERE id = :eval_id";

try {
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':signature', $signature);
    $update_stmt->bindParam(':rater_date', $signature_date);
    $update_stmt->bindParam(':eval_id', $evaluation_id, PDO::PARAM_INT);
    
    if ($update_stmt->execute()) {
        // Log the action
        $log_query = "INSERT INTO audit_logs (user_id, action, target_type, target_id, details, created_at) 
                      VALUES (:user_id, 'sign_evaluation', 'evaluation', :eval_id, :details, NOW())";
        
        $log_stmt = $db->prepare($log_query);
        $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':eval_id', $evaluation_id, PDO::PARAM_INT);
        
        $details = "Evaluation #{$evaluation_id} signed by {$signer_name} on {$signature_date}";
        $log_stmt->bindParam(':details', $details);
        $log_stmt->execute(); // Log errors don't stop the process

        http_response_code(200);
        echo json_encode([
            'success' => true, 
            'message' => 'Signature saved successfully',
            'evaluation_id' => $evaluation_id
        ]);
    } else {
        throw new Exception('Failed to update evaluation');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
