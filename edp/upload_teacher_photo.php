<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if(!isset($_POST['teacher_id']) || empty($_POST['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Teacher ID is required']);
    exit();
}

$teacher_id = $_POST['teacher_id'];

if(!isset($_FILES['teacher_photo']) || $_FILES['teacher_photo']['error'] != UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit();
}

$upload_dir = '../uploads/teachers/';
if(!is_dir($upload_dir)) {
    if(!mkdir($upload_dir, 0777, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
        exit();
    }
}

$file = $_FILES['teacher_photo'];
$file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

if(!in_array($file_extension, $allowed_extensions)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload JPG, PNG, or GIF images only.']);
    exit();
}

// Check file size (2MB max)
if($file['size'] > 2 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File size too large. Maximum size is 2MB.']);
    exit();
}

// Generate unique filename
$new_filename = 'teacher_' . $teacher_id . '_' . time() . '.' . $file_extension;
$upload_path = $upload_dir . $new_filename;

// Move uploaded file
if(move_uploaded_file($file['tmp_name'], $upload_path)) {
    // Also store in DB as BLOB for portability across SQL exports
    $photo_data = file_get_contents($upload_path);
    $mime_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $photo_mime = $mime_types[$file_extension] ?? 'image/jpeg';
    try {
        $stmt = $db->prepare("UPDATE teachers SET photo_path = :photo_path, photo_data = :photo_data, photo_mime = :photo_mime WHERE id = :id");
        $stmt->bindValue(':photo_path', $new_filename);
        $stmt->bindValue(':photo_data', $photo_data, PDO::PARAM_LOB);
        $stmt->bindValue(':photo_mime', $photo_mime);
        $stmt->bindValue(':id', $teacher_id);
        $success = $stmt->execute();
    } catch (Exception $e) {
        $success = false;
    }

    if($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Photo uploaded successfully',
            'photo_url' => '../uploads/teachers/' . $new_filename
        ]);
    } else {
        // Delete the uploaded file if database update failed
        if(file_exists($upload_path)) {
            unlink($upload_path);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to update teacher record']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
}
?>