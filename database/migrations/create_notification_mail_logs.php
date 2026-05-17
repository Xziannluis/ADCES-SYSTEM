<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();
if (!$db) {
    throw new RuntimeException('Database connection failed while creating notification_mail_logs table.');
}

$sql = "
CREATE TABLE IF NOT EXISTS notification_mail_logs (
    id INT(11) NOT NULL AUTO_INCREMENT,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    message_text TEXT NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    source VARCHAR(100) NOT NULL DEFAULT 'sendGenericNotificationEmail',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_nml_status_created (status, created_at),
    KEY idx_nml_email_created (recipient_email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

$db->exec($sql);
echo "notification_mail_logs table is ready.\n";

