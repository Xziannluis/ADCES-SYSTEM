<?php
// PHP proxy endpoint for the local Python AI service.
// This keeps the browser/UI calling PHP while Python does the ML work.

require_once __DIR__ . '/../auth/session-check.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai_autostart.php';

function ai_json_error(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

function teacher_has_ai_access(PDO $db, int $userId, int $teacherId): bool {
    if ($userId <= 0 || $teacherId <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM teacher_assignments
        WHERE evaluator_id = :user_id
          AND teacher_id = :teacher_id
        LIMIT 1
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':teacher_id' => $teacherId
    ]);

    return (bool)$stmt->fetchColumn();
}

function teacher_has_any_assignment(PDO $db, int $userId): bool {
    if ($userId <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM teacher_assignments
        WHERE evaluator_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);

    return (bool)$stmt->fetchColumn();
}

$currentRole = strtolower(str_replace(' ', '_', trim((string)($_SESSION['role'] ?? ''))));
if ($currentRole !== '') {
    $_SESSION['role'] = $currentRole;
}

$allowedEvaluatorRoles = [
    'dean',
    'principal',
    'chairperson',
    'subject_coordinator',
    'grade_level_coordinator',
    'president',
    'vice_president',
];

$aiBase = getenv('AI_SERVICE_URL');
if (!$aiBase) {
    // default local base URL
    $aiBase = 'http://127.0.0.1:8001';
}

// Debug helper:
// - GET /controllers/ai_generate.php?mode=health -> calls Python /health
// - GET /controllers/ai_generate.php?mode=echo   -> calls Python /debug/echo (with empty JSON body)
// Normal generation remains POST-only.
$mode = $_GET['mode'] ?? '';
$isDebugGet = ($_SERVER['REQUEST_METHOD'] === 'GET') && in_array($mode, ['health', 'echo'], true);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isDebugGet) {
    ai_json_error(405, 'Method not allowed (use POST). For debugging, use ?mode=health or ?mode=echo');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        ai_json_error(400, 'Empty request body');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        ai_json_error(400, 'Invalid JSON');
    }
} else {
    // Debug GET modes don't need request body
    $payload = [];
}

if (!in_array($currentRole, $allowedEvaluatorRoles, true)) {
    if ($currentRole !== 'teacher') {
        ai_json_error(403, 'Forbidden');
    }

    try {
        $database = new Database();
        $db = $database->getConnection();
        if (!$db instanceof PDO) {
            ai_json_error(403, 'Forbidden');
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $teacherId = (int)($payload['teacher_id'] ?? 0);
            if (!teacher_has_ai_access($db, $userId, $teacherId)) {
                ai_json_error(403, 'Forbidden');
            }
        } elseif (!teacher_has_any_assignment($db, $userId)) {
            ai_json_error(403, 'Forbidden');
        }
    } catch (Throwable $e) {
        ai_json_error(403, 'Forbidden');
    }
}

$path = '/generate';
if ($mode === 'health') {
    $path = '/health';
} elseif ($mode === 'echo') {
    $path = '/debug/echo';
}
$aiUrl = rtrim($aiBase, '/') . $path;

$ch = curl_init($aiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_CUSTOMREQUEST => ($_SERVER['REQUEST_METHOD'] === 'GET') ? 'GET' : 'POST',
    CURLOPT_POSTFIELDS => ($_SERVER['REQUEST_METHOD'] === 'GET') ? null : json_encode($payload),
    // First run can be slow (model download/load + CPU generation)
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 120,
    // Prefer IPv4 for localhost to avoid rare IPv6 resolution issues
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);

$responseBody = curl_exec($ch);
$curlErr = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);


header('Content-Type: application/json');

if ($responseBody === false) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'AI service connection failed. Is the Python server running?',
        'ai_url' => $aiUrl,
        'error' => $curlErr,
    ]);
    exit();
}

$data = json_decode($responseBody, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'AI service returned invalid JSON',
        'ai_url' => $aiUrl,
        'status' => $status,
        'raw' => $responseBody,
    ]);
    exit();
}

if ($status < 200 || $status >= 300) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'AI service error',
        'ai_url' => $aiUrl,
        'status' => $status,
        'data' => $data,
    ]);
    exit();
}

echo json_encode(['success' => true, 'data' => $data]);
