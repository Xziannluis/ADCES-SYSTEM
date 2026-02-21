<?php
require_once '../auth/session-check.php';
// Only dean/principal may evaluate coordinators using this shortcut
if (!in_array($_SESSION['role'], ['dean', 'principal'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
$db = (new Database())->getConnection();

$coordinator_user_id = $_GET['user_id'] ?? null;
if (empty($coordinator_user_id) || !is_numeric($coordinator_user_id)) {
    $_SESSION['error'] = 'Invalid coordinator specified.';
    header('Location: dashboard.php');
    exit();
}

// Verify user exists
$user_q = $db->prepare("SELECT id, name, department FROM users WHERE id = :id LIMIT 1");
$user_q->bindParam(':id', $coordinator_user_id);
$user_q->execute();
$user = $user_q->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $_SESSION['error'] = 'Coordinator not found.';
    header('Location: dashboard.php');
    exit();
}

// Check if coordinator already has a teacher profile
$teacher_q = $db->prepare("SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1");
$teacher_q->bindParam(':user_id', $coordinator_user_id);
$teacher_q->execute();
$teacher = $teacher_q->fetch(PDO::FETCH_ASSOC);

if ($teacher && !empty($teacher['id'])) {
    // Redirect to evaluation form for existing teacher id
    header('Location: evaluation.php?teacher_id=' . $teacher['id']);
    exit();
}

// Create a teacher profile for the coordinator (minimal fields)
$insert = $db->prepare("INSERT INTO teachers (user_id, name, department, status, created_at) VALUES (:user_id, :name, :department, 'active', NOW())");
$insert->bindParam(':user_id', $coordinator_user_id);
$insert->bindParam(':name', $user['name']);
$insert->bindParam(':department', $user['department']);
if ($insert->execute()) {
    $new_teacher_id = $db->lastInsertId();
    header('Location: evaluation.php?teacher_id=' . $new_teacher_id);
    exit();
} else {
    $_SESSION['error'] = 'Failed to create a temporary teacher profile for evaluation.';
    header('Location: dashboard.php');
    exit();
}
?>