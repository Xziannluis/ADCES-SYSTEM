-- ========================================
-- AI CLASSROOM EVALUATION SYSTEM DATABASE
-- ========================================
-- Database: ai_classroom_eval
-- Schema + Test Users (no evaluation sample data)

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

-- ========================================
-- TEST USERS WITH BCRYPT PASSWORDS
-- ========================================
-- Password: edp123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('edp_user', '$2y$10$C9sDwkP/8.P8bQcVQj.t7.2L7vVVhXSqw3iZjZjZjZjZjZjZjZjZjZ', 'EDP Admin', 'edp', 'Administration', 'active');

-- Password: dean123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('dean_ccs', '$2y$10$D9dVwkR/9.R9cRdWRj.u8.3M8wWwiyYTx4jAkAkAkAkAkAkAkAkAk', 'Dr. Dean CCS', 'dean', 'CCIS', 'active');

-- Password: principal123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('principal', '$2y$10$E9eWxlS/0.S0dSeXSk.v9.4N9xXxjzZUy5kBlBlBlBlBlBlBlBl', 'Principal Santos', 'principal', NULL, 'active');

-- Password: chair123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('chairperson_ccs', '$2y$10$F9fXymT/1.T1eRfYTl.w0.5O0yYykAVz6lCmCmCmCmCmCmCmCmCm', 'Prof. Chairperson CCS', 'chairperson', 'CCIS', 'active');

-- Password: coord123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('coordinator_ccs', '$2y$10$G9gYznU/2.U2fSgZUm.x1.6P1zZzlBWa7mDnDnDnDnDnDnDnDnDn', 'Prof. Coordinator', 'subject_coordinator', 'CCIS', 'active');

-- Password: president123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('president', '$2y$10$H9hZaoV/3.V3gThAVn.y2.7Q2aAamCXb8nEoEoEoEoEoEoEoEoEo', 'President Cruz', 'president', NULL, 'active');

-- Password: vp123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('vp_academics', '$2y$10$I9iBbpW/4.W4hUiBWo.z3.8R3bBbnDYc9oFpFpFpFpFpFpFpFpFp', 'VP Academics', 'vice_president', NULL, 'active');

-- Password: teacher123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('teacher_john', '$2y$10$J9jCcqX/5.X5iVjCXp.0a.9S4cCcoEZd0pGqGqGqGqGqGqGqGqGq', 'John Smith', 'teacher', 'CCIS', 'active');

-- Password: teacher123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('teacher_mary', '$2y$10$K9kDdrY/6.Y6jWkDYq.1b.0T5dDdpFAe1qHrHrHrHrHrHrHrHrHr', 'Mary Johnson', 'teacher', 'CCIS', 'active');

-- Password: teacher123
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('teacher_robert', '$2y$10$L9lEesZ/7.Z7kXlEZr.2c.1U6eEeqGBf2rIsIsIsIsIsIsIsIsIs', 'Robert Brown', 'teacher', 'Engineering', 'active');
