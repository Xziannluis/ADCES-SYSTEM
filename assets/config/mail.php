<?php
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
