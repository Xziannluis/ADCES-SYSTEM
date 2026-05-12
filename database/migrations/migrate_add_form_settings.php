<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

if (!$db) {
    throw new RuntimeException('Database connection failed while creating form_settings table.');
}

$sql = "
CREATE TABLE IF NOT EXISTS form_settings (
    id INT(11) NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(500) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

$db->exec($sql);

// Seed default form settings if table is empty
$count = $db->query("SELECT COUNT(*) FROM form_settings")->fetchColumn();
if ($count == 0) {
    $defaults = [
        ['form_code_no', 'FM-DPM-SMCC-RTH-04'],
        ['issue_status', '02'],
        ['revision_no', '02'],
        ['date_effective', '13 September 2025'],
        ['approved_by', 'President'],
    ];

    $stmt = $db->prepare("INSERT INTO form_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $row) {
        $stmt->execute($row);
    }
}

echo "Migration complete: form_settings table created successfully.\n";
