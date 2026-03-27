<?php
// Temporary test - simulate an authenticated session and call the endpoint
session_start();
$_SESSION['user_id'] = 15;
$_SESSION['role'] = 'dean';
$_SESSION['department'] = 'CCIS';
$_SESSION['name'] = 'DAISA O. GUPIT';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Set the GET param
$_GET['form_type'] = 'iso';

// Set the REQUEST_URI for session-check
$_SERVER['REQUEST_URI'] = '/ADCES-SYSTEM/includes/get_teachers_by_form_type.php';

// Buffer the output
ob_start();
chdir(__DIR__ . '/../../includes');
require __DIR__ . '/../../includes/get_teachers_by_form_type.php';
$output = ob_get_clean();
$httpCode = http_response_code();

echo "HTTP Status: $httpCode\n";
echo "Response: $output\n";

// Check if it's valid JSON
$decoded = json_decode($output, true);
if ($decoded === null && $output !== 'null') {
    echo "JSON ERROR: " . json_last_error_msg() . "\n";
    echo "First 200 chars: " . substr($output, 0, 200) . "\n";
} else {
    echo "Valid JSON, count: " . (is_array($decoded) ? count($decoded) : 'N/A') . "\n";
}
