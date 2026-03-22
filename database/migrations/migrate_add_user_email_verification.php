<?php
/**
 * Migration: Add email verification code columns to users table
 * These columns support 6-digit code-based email verification from the settings page.
 */
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

$columns = [
    'email_verification_code' => "ALTER TABLE users ADD COLUMN email_verification_code VARCHAR(10) NULL AFTER verification_token",
    'email_verification_expires' => "ALTER TABLE users ADD COLUMN email_verification_expires DATETIME NULL AFTER email_verification_code"
];

foreach ($columns as $col => $sql) {
    $check = $db->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($check->rowCount() === 0) {
        $db->exec($sql);
        echo "Added column: $col\n";
    } else {
        echo "Column already exists: $col\n";
    }
}

echo "Migration complete.\n";
