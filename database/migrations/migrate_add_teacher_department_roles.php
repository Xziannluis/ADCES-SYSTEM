<?php
// Migration: add teacher_department_roles table
// Run: php database/migrations/migrate_add_teacher_department_roles.php

require_once __DIR__ . '/../../config/database.php';

try {
    $db = (new Database())->getConnection();

    $exists = $db->query("SHOW TABLES LIKE 'teacher_department_roles'")->fetchColumn();
    if ($exists) {
        echo "teacher_department_roles table already exists.\n";
        exit(0);
    }

    $sql = "CREATE TABLE `teacher_department_roles` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `teacher_id` INT NOT NULL,
        `department` VARCHAR(100) NOT NULL,
        `assigned_by` INT NULL,
        `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        UNIQUE KEY `teacher_department_unique` (`teacher_id`, `department`),
        KEY `teacher_id_idx` (`teacher_id`),
        KEY `department_role_idx` (`department`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $db->exec($sql);
    echo "teacher_department_roles table created.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
