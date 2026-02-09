-- ========================================
-- AI CLASSROOM EVALUATION SYSTEM DATABASE
-- ========================================
-- Database: ai_classroom_eval
-- Schema only (no sample data)

-- Drop existing database if it exists
DROP DATABASE IF EXISTS `ai_classroom_eval`;
CREATE DATABASE `ai_classroom_eval` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ai_classroom_eval`;

-- ========================================
-- USERS TABLE
-- ========================================
CREATE TABLE `users` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `role` ENUM('edp', 'dean', 'principal', 'chairperson', 'subject_coordinator', 'president', 'vice_president', 'teacher') NOT NULL,
  `department` VARCHAR(100),
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `role_idx` (`role`),
  KEY `department_idx` (`department`),
  KEY `status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TEACHERS TABLE
-- ========================================
CREATE TABLE `teachers` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `department` VARCHAR(100),
  `email` VARCHAR(100),
  `phone` VARCHAR(20),
  `photo_path` VARCHAR(255),
  `evaluation_schedule` DATETIME,
  `evaluation_room` VARCHAR(100),
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  KEY `department_idx` (`department`),
  KEY `status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- EVALUATIONS TABLE (Main table for evaluation records)
-- ========================================
CREATE TABLE `evaluations` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `teacher_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `academic_year` VARCHAR(20),
  `semester` VARCHAR(20),
  `subject_observed` VARCHAR(255),
  `observation_time` VARCHAR(100),
  `observation_date` DATE,
  `observation_type` VARCHAR(50),
  `seat_plan` TINYINT DEFAULT 0,
  `course_syllabi` TINYINT DEFAULT 0,
  `others_requirements` TINYINT DEFAULT 0,
  `others_specify` TEXT,
  `status` ENUM('draft', 'completed') DEFAULT 'draft',
  `communications_avg` DECIMAL(3,2),
  `management_avg` DECIMAL(3,2),
  `assessment_avg` DECIMAL(3,2),
  `overall_avg` DECIMAL(3,2),
  `strengths` TEXT,
  `improvement_areas` TEXT,
  `recommendations` TEXT,
  `rater_printed_name` VARCHAR(255),
  `agreement` TEXT,
  `rater_signature` LONGTEXT,
  `rater_date` DATE,
  `faculty_printed_name` VARCHAR(255),
  `faculty_signature` LONGTEXT,
  `faculty_date` DATE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`evaluator_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  KEY `teacher_idx` (`teacher_id`),
  KEY `evaluator_idx` (`evaluator_id`),
  KEY `status_idx` (`status`),
  KEY `created_at_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- EVALUATION_DETAILS TABLE (Stores individual rating criteria)
-- ========================================
CREATE TABLE `evaluation_details` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `evaluation_id` INT NOT NULL,
  `category` VARCHAR(50),
  `criterion_index` INT,
  `criterion_text` TEXT,
  `rating` INT,
  `comments` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations`(`id`) ON DELETE CASCADE,
  KEY `evaluation_idx` (`evaluation_id`),
  KEY `category_idx` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- EVALUATION_CRITERIA TABLE (Predefined evaluation criteria)
-- ========================================
CREATE TABLE `evaluation_criteria` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `category` VARCHAR(50) NOT NULL,
  `criterion_index` INT NOT NULL,
  `criterion_text` TEXT NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `category_index` (`category`, `criterion_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- AI_RECOMMENDATIONS TABLE
-- ========================================
CREATE TABLE `ai_recommendations` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `evaluation_id` INT NOT NULL,
  `recommendation_text` TEXT,
  `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations`(`id`) ON DELETE CASCADE,
  KEY `evaluation_idx` (`evaluation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- AUDIT_LOGS TABLE
-- ========================================
CREATE TABLE `audit_logs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `user_id` INT,
  `action` VARCHAR(100),
  `description` TEXT,
  `ip_address` VARCHAR(45),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  KEY `user_idx` (`user_id`),
  KEY `action_idx` (`action`),
  KEY `created_at_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- CREATE INDEXES FOR PERFORMANCE
-- ========================================
CREATE INDEX idx_evaluations_teacher_date ON evaluations(teacher_id, created_at DESC);
CREATE INDEX idx_evaluations_evaluator ON evaluations(evaluator_id);
CREATE INDEX idx_evaluation_details_eval ON evaluation_details(evaluation_id);
CREATE INDEX idx_teachers_user ON teachers(user_id);
CREATE INDEX idx_users_role_dept ON users(role, department);
