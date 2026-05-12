<?php
require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

$columns = [
    'email_verified' => "ALTER TABLE teachers ADD COLUMN email_verified TINYINT(1) DEFAULT 0 AFTER email",
    'email_verification_code' => "ALTER TABLE teachers ADD COLUMN email_verification_code VARCHAR(10) NULL AFTER email_verified",
    'email_verification_expires' => "ALTER TABLE teachers ADD COLUMN email_verification_expires DATETIME NULL AFTER email_verification_code"
];

foreach ($columns as $column => $sql) {
    $check = $db->prepare("SHOW COLUMNS FROM teachers LIKE :column");
    $check->bindParam(':column', $column);
    $check->execute();
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        try {
            $db->exec($sql);
            echo "Added column: {$column}\n";
        } catch (PDOException $e) {
            echo "Failed to add {$column}: " . $e->getMessage() . "\n";
        }
    } else {
        echo "Column already exists: {$column}\n";
    }
}
