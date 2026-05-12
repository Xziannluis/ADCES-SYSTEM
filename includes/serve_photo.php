<?php
/**
 * Serve a photo from the database BLOB storage.
 * Usage: includes/serve_photo.php?type=teacher&id=7
 *        includes/serve_photo.php?type=user&id=15
 */
require_once __DIR__ . '/../config/database.php';

$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['teacher', 'user'], true)) {
    http_response_code(404);
    exit;
}

$database = new Database();
$db = $database->getConnection();

if ($type === 'teacher') {
    $stmt = $db->prepare("SELECT photo_data, photo_mime FROM teachers WHERE id = :id AND photo_data IS NOT NULL LIMIT 1");
} else {
    $stmt = $db->prepare("SELECT photo_data, photo_mime FROM users WHERE id = :id AND photo_data IS NOT NULL LIMIT 1");
}
$stmt->bindParam(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['photo_data'])) {
    http_response_code(404);
    exit;
}

$mime = $row['photo_mime'] ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($row['photo_data']));
header('Cache-Control: public, max-age=86400');
echo $row['photo_data'];
exit;
