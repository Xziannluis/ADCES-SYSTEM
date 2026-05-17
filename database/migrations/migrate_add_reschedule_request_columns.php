<?php
/**
 * Migration: Add structured reschedule request fields to notifications.
 * This avoids fragile link parsing when matching request/accept workflows.
 */
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

$columns = [
    'request_eval_id' => "ALTER TABLE notifications ADD COLUMN request_eval_id INT NULL AFTER link",
    'request_schedule_key' => "ALTER TABLE notifications ADD COLUMN request_schedule_key VARCHAR(255) NULL AFTER request_eval_id"
];

foreach ($columns as $col => $sql) {
    $check = $db->query("SHOW COLUMNS FROM notifications LIKE '$col'");
    if ($check && $check->rowCount() === 0) {
        $db->exec($sql);
        echo "Added column: $col\n";
    } else {
        echo "Column already exists: $col\n";
    }
}

// Optional helper index for fast matching by type + teacher + request row.
try {
    $idxCheck = $db->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notifications_resched_match'");
    if (!$idxCheck || $idxCheck->rowCount() === 0) {
        $db->exec("CREATE INDEX idx_notifications_resched_match ON notifications (type, user_id, teacher_id, request_eval_id, is_read)");
        echo "Added index: idx_notifications_resched_match\n";
    } else {
        echo "Index already exists: idx_notifications_resched_match\n";
    }
} catch (Exception $e) {
    echo "Index create skipped: " . $e->getMessage() . "\n";
}

echo "Migration complete.\n";

