<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

if (!$db) {
    throw new RuntimeException('Database connection failed.');
}

// Add form_settings snapshot columns to evaluations table
$columns = [
    'fs_form_code_no'   => "VARCHAR(100) DEFAULT NULL",
    'fs_issue_status'   => "VARCHAR(50) DEFAULT NULL",
    'fs_revision_no'    => "VARCHAR(50) DEFAULT NULL",
    'fs_date_effective' => "VARCHAR(100) DEFAULT NULL",
    'fs_approved_by'    => "VARCHAR(100) DEFAULT NULL",
];

foreach ($columns as $col => $def) {
    try {
        $db->exec("ALTER TABLE evaluations ADD COLUMN {$col} {$def}");
        echo "Added column: {$col}\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "Column {$col} already exists, skipping.\n";
        } else {
            throw $e;
        }
    }
}

// Backfill existing evaluations with current form_settings values
$fsStmt = $db->query("SELECT setting_key, setting_value FROM form_settings");
$fs = [];
while ($r = $fsStmt->fetch(PDO::FETCH_ASSOC)) {
    $fs[$r['setting_key']] = $r['setting_value'];
}

if (!empty($fs)) {
    $updateStmt = $db->prepare(
        "UPDATE evaluations 
         SET fs_form_code_no = :form_code_no,
             fs_issue_status = :issue_status,
             fs_revision_no = :revision_no,
             fs_date_effective = :date_effective,
             fs_approved_by = :approved_by
         WHERE fs_form_code_no IS NULL"
    );
    $updateStmt->execute([
        ':form_code_no'   => $fs['form_code_no'] ?? 'FM-DPM-SMCC-RTH-04',
        ':issue_status'   => $fs['issue_status'] ?? '02',
        ':revision_no'    => $fs['revision_no'] ?? '02',
        ':date_effective' => $fs['date_effective'] ?? '13 September 2023',
        ':approved_by'    => $fs['approved_by'] ?? 'President',
    ]);
    $count = $updateStmt->rowCount();
    echo "Backfilled {$count} existing evaluations with current form settings.\n";
}

echo "Migration complete: form_settings snapshot columns added to evaluations.\n";
