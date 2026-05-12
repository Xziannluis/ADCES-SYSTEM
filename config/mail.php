<?php
// Load .env file if present (so we don't need Apache SetEnv directives).
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            putenv($line);
        }
    }
}

// SMTP configuration for PHPMailer (use env vars to avoid committing secrets).
return [
    'enabled' => getenv('MAIL_ENABLED') ? filter_var(getenv('MAIL_ENABLED'), FILTER_VALIDATE_BOOLEAN) : false,
    'host' => getenv('MAIL_HOST') ?: 'smtp.example.com',
    'port' => getenv('MAIL_PORT') ?: 587,
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
    'smtp_auth' => getenv('MAIL_SMTP_AUTH') ? filter_var(getenv('MAIL_SMTP_AUTH'), FILTER_VALIDATE_BOOLEAN) : true,
    'from_email' => getenv('MAIL_FROM_EMAIL') ?: 'no-reply@example.com',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'SMCC Evaluation System'
];
