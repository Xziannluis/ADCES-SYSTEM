<?php
/**
 * Backfill existing photo files into database BLOB columns.
 * Run once after adding photo_data/photo_mime columns:
 *   php dev/backfill_photo_blobs.php
 */
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
];

// Backfill teachers
$stmt = $db->query("SELECT id, photo_path FROM teachers WHERE photo_path IS NOT NULL AND photo_path != '' AND photo_data IS NULL");
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Teachers to backfill: " . count($teachers) . "\n";

foreach ($teachers as $row) {
    $file = __DIR__ . '/../uploads/teachers/' . $row['photo_path'];
    if (!file_exists($file)) {
        echo "  [SKIP] Teacher #{$row['id']}: file not found ({$row['photo_path']})\n";
        continue;
    }
    $ext = strtolower(pathinfo($row['photo_path'], PATHINFO_EXTENSION));
    $mime = $mime_map[$ext] ?? 'image/jpeg';
    $data = file_get_contents($file);

    $update = $db->prepare("UPDATE teachers SET photo_data = :data, photo_mime = :mime WHERE id = :id");
    $update->bindValue(':data', $data, PDO::PARAM_LOB);
    $update->bindValue(':mime', $mime);
    $update->bindValue(':id', $row['id']);
    if ($update->execute()) {
        echo "  [OK] Teacher #{$row['id']}: {$row['photo_path']} (" . strlen($data) . " bytes)\n";
    } else {
        echo "  [FAIL] Teacher #{$row['id']}\n";
    }
}

// Backfill users
$stmt = $db->query("SELECT id, photo_path FROM users WHERE photo_path IS NOT NULL AND photo_path != '' AND photo_data IS NULL");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nUsers to backfill: " . count($users) . "\n";

foreach ($users as $row) {
    $file = __DIR__ . '/../uploads/users/' . $row['photo_path'];
    if (!file_exists($file)) {
        echo "  [SKIP] User #{$row['id']}: file not found ({$row['photo_path']})\n";
        continue;
    }
    $ext = strtolower(pathinfo($row['photo_path'], PATHINFO_EXTENSION));
    $mime = $mime_map[$ext] ?? 'image/jpeg';
    $data = file_get_contents($file);

    $update = $db->prepare("UPDATE users SET photo_data = :data, photo_mime = :mime WHERE id = :id");
    $update->bindValue(':data', $data, PDO::PARAM_LOB);
    $update->bindValue(':mime', $mime);
    $update->bindValue(':id', $row['id']);
    if ($update->execute()) {
        echo "  [OK] User #{$row['id']}: {$row['photo_path']} (" . strlen($data) . " bytes)\n";
    } else {
        echo "  [FAIL] User #{$row['id']}\n";
    }
}

echo "\nDone.\n";
