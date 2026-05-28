<?php
require_once '../auth/session-check.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = (new Database())->getConnection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database connection failed']);
    exit();
}

try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS evaluation_proofs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evaluation_id INT NOT NULL,
            uploaded_by INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_eval (evaluation_id),
            INDEX idx_uploader (uploaded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to initialize storage']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $evaluationId = (int)($_GET['evaluation_id'] ?? 0);
    if ($evaluationId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Missing evaluation_id']);
        exit();
    }

    $stmt = $db->prepare(
        "SELECT p.id, p.file_path, p.created_at, p.uploaded_by, u.name AS uploaded_by_name
         FROM evaluation_proofs p
         LEFT JOIN users u ON u.id = p.uploaded_by
         WHERE p.evaluation_id = :eid
         ORDER BY p.id DESC"
    );
    $stmt->execute([':eid' => $evaluationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $r) {
        $path = trim((string)($r['file_path'] ?? ''));
        if ($path === '') continue;
        $items[] = [
            'id' => (int)($r['id'] ?? 0),
            'url' => '../' . ltrim(str_replace('\\', '/', $path), '/'),
            'file_name' => basename($path),
            'uploaded_by' => trim((string)($r['uploaded_by_name'] ?? 'Observer')),
            'uploaded_by_id' => (int)($r['uploaded_by'] ?? 0),
            'uploaded_at' => !empty($r['created_at']) ? date('M d, Y g:i A', strtotime((string)$r['created_at'])) : '',
            'can_delete' => ((int)($r['uploaded_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0))
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete') {
        $proofId = (int)($_POST['proof_id'] ?? 0);
        if ($proofId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Missing proof_id']);
            exit();
        }

        $sel = $db->prepare("SELECT id, file_path, uploaded_by FROM evaluation_proofs WHERE id = :id LIMIT 1");
        $sel->execute([':id' => $proofId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Photo not found']);
            exit();
        }
        if ((int)($row['uploaded_by'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'You can only remove your own uploaded photo']);
            exit();
        }

        $del = $db->prepare("DELETE FROM evaluation_proofs WHERE id = :id");
        $del->execute([':id' => $proofId]);

        $relPath = trim((string)($row['file_path'] ?? ''));
        if ($relPath !== '') {
            $abs = '../' . ltrim(str_replace('\\', '/', $relPath), '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        echo json_encode(['ok' => true, 'message' => 'Removed']);
        exit();
    }

    $evaluationId = (int)($_POST['evaluation_id'] ?? 0);
    if ($evaluationId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Missing evaluation_id']);
        exit();
    }

    if (!isset($_FILES['proof']) || (int)($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'No file uploaded']);
        exit();
    }

    $file = $_FILES['proof'];
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || $size <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Invalid file']);
        exit();
    }
    if ($size > 8 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'File too large (max 8MB)']);
        exit();
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) finfo_close($finfo);
    if (!isset($allowed[$mime])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Only JPG, PNG, GIF, WEBP are allowed']);
        exit();
    }

    $uploadDir = '../uploads/evaluation_proofs/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    if (!is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Upload directory not available']);
        exit();
    }

    $name = 'proof_' . $evaluationId . '_' . (int)($_SESSION['user_id'] ?? 0) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $uploadDir . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Failed to save uploaded file']);
        exit();
    }

    $storePath = 'uploads/evaluation_proofs/' . $name;
    $ins = $db->prepare("INSERT INTO evaluation_proofs (evaluation_id, uploaded_by, file_path) VALUES (:eid, :uid, :path)");
    $ins->execute([
        ':eid' => $evaluationId,
        ':uid' => (int)($_SESSION['user_id'] ?? 0),
        ':path' => $storePath
    ]);

    echo json_encode(['ok' => true, 'message' => 'Uploaded']);
    exit();
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
