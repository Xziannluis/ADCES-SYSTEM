<?php
require_once __DIR__ . '/../auth/session-check.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit();
}

$db = (new Database())->getConnection();
$notif_id = (int)($_POST['id'] ?? 0);

if ($notif_id > 0) {
    // Observer requests stay unread until the recipient accepts or marks unable.
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid AND type <> 'observer_request'");
    $stmt->execute([':id' => $notif_id, ':uid' => $_SESSION['user_id']]);
} elseif (isset($_POST['mark_all'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0 AND type <> 'observer_request'");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
}

echo json_encode(['success' => true]);
