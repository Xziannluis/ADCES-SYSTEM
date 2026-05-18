<?php
/**
 * Migration: create teacher_schedules table for advance scheduling history.
 */

require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS teacher_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            evaluation_id INT NULL,
            academic_year VARCHAR(20) NOT NULL,
            semester VARCHAR(10) NOT NULL,
            schedule_start DATETIME NOT NULL,
            schedule_end DATETIME NULL,
            room VARCHAR(255) NULL,
            focus_json TEXT NULL,
            subject_area VARCHAR(255) NULL,
            subject VARCHAR(255) NULL,
            form_type ENUM('iso','peac','both') NOT NULL DEFAULT 'iso',
            scheduled_department VARCHAR(100) NULL,
            scheduled_by INT NULL,
            status ENUM('scheduled','rescheduled','cancelled','completed') NOT NULL DEFAULT 'scheduled',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_teacher_schedules_teacher (teacher_id),
            INDEX idx_teacher_schedules_eval (evaluation_id),
            INDEX idx_teacher_schedules_slot (teacher_id, schedule_start),
            UNIQUE KEY uniq_teacher_schedule_slot (teacher_id, schedule_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "teacher_schedules table is ready.\n";
} catch (PDOException $e) {
    echo "Failed to create teacher_schedules: " . $e->getMessage() . "\n";
}

