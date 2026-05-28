<?php
require_once '../auth/session-check.php';
header('Content-Type: application/json');

if (!in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Invalid method']);
    exit();
}

$signature = trim((string)($_POST['signature_data'] ?? ''));
$printedName = trim((string)($_POST['printed_name'] ?? ''));

if ($signature === '' || strpos($signature, 'data:image/png;base64,') !== 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Signature is required']);
    exit();
}

if (!preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $signature)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid signature format']);
    exit();
}

if ($printedName === '') {
    $printedName = trim((string)($_SESSION['name'] ?? ''));
}

$_SESSION['report_prepared_signature'] = $signature;
$_SESSION['report_prepared_name'] = $printedName;
$_SESSION['report_prepared_role'] = trim((string)($_SESSION['role'] ?? ''));

echo json_encode(['ok' => true]);

