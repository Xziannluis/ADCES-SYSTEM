<?php
// PHP proxy endpoint for the local Python AI service.
// This keeps the browser/UI calling PHP while Python does the ML work.

require_once __DIR__ . '/../auth/session-check.php';
require_once __DIR__ . '/../config/constants.php';

// allow evaluators + leaders
// Keep this in sync with roles that can view evaluations.
if (!in_array($_SESSION['role'] ?? '', [
    'dean',
    'principal',
    'chairperson',
    'subject_coordinator',
    'grade_level_coordinator',
    'president',
    'vice_president',
], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$aiBase = getenv('AI_SERVICE_URL');
if (!$aiBase) {
    // default local base URL
    $aiBase = 'http://127.0.0.1:8008';
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'PHP cURL extension is not enabled. Please enable it in php.ini and restart Apache.',
    ]);
    exit();
}

// Debug helper:
// - GET /controllers/ai_generate.php?mode=health -> calls Python /health
// - GET /controllers/ai_generate.php?mode=echo   -> calls Python /debug/echo (with empty JSON body)
// Normal generation remains POST-only.
$mode = $_GET['mode'] ?? '';
$isDebugGet = ($_SERVER['REQUEST_METHOD'] === 'GET') && in_array($mode, ['health', 'echo'], true);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isDebugGet) {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed (use POST). For debugging, use ?mode=health or ?mode=echo',
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Empty request body']);
        exit();
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit();
    }
} else {
    // Debug GET modes don't need request body
    $payload = [];
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
