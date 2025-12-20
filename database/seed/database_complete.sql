-- ========================================
-- AI CLASSROOM EVALUATION SYSTEM DATABASE
-- ========================================
-- Database: ai_classroom_eval
-- Complete schema with all tables and sample data

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
  `agreement` TEXT,
  `rater_signature` VARCHAR(255),
  `rater_date` DATE,
  `faculty_signature` VARCHAR(255),
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
-- SAMPLE DATA - USERS
-- ========================================
INSERT INTO `users` (`username`, `password`, `name`, `role`, `department`, `status`) VALUES
('edp_user', '$2y$10$YourHashedPasswordHere1', 'EDP Admin', 'edp', 'Administration', 'active'),
('dean_ccs', '$2y$10$YourHashedPasswordHere2', 'Dr. Dean CCS', 'dean', 'Computer Science', 'active'),
('principal', '$2y$10$YourHashedPasswordHere3', 'Principal Santos', 'principal', NULL, 'active'),
('chairperson_ccs', '$2y$10$YourHashedPasswordHere4', 'Prof. Chairperson CCS', 'chairperson', 'Computer Science', 'active'),
('coordinator_ccs', '$2y$10$YourHashedPasswordHere5', 'Prof. Coordinator', 'subject_coordinator', 'Computer Science', 'active'),
('president', '$2y$10$YourHashedPasswordHere6', 'President Cruz', 'president', NULL, 'active'),
('vp_academics', '$2y$10$YourHashedPasswordHere7', 'VP Academics', 'vice_president', NULL, 'active'),
('teacher_john', '$2y$10$YourHashedPasswordHere8', 'John Smith', 'teacher', 'Computer Science', 'active'),
('teacher_mary', '$2y$10$YourHashedPasswordHere9', 'Mary Johnson', 'teacher', 'Computer Science', 'active'),
('teacher_robert', '$2y$10$YourHashedPasswordHere10', 'Robert Brown', 'teacher', 'Engineering', 'active');

-- ========================================
-- SAMPLE DATA - TEACHERS
-- ========================================
INSERT INTO `teachers` (`user_id`, `name`, `department`, `email`, `phone`, `evaluation_schedule`, `status`) VALUES
(8, 'John Smith', 'Computer Science', 'john.smith@school.edu', '555-0001', '2023-11-20 10:00:00', 'active'),
(9, 'Mary Johnson', 'Computer Science', 'mary.johnson@school.edu', '555-0002', '2023-11-21 14:00:00', 'active'),
(10, 'Robert Brown', 'Engineering', 'robert.brown@school.edu', '555-0003', '2023-11-22 09:00:00', 'active');

-- ========================================
-- SAMPLE DATA - EVALUATION CRITERIA
-- ========================================
-- Communications Competence (5 items)
INSERT INTO `evaluation_criteria` (`category`, `criterion_index`, `criterion_text`, `description`) VALUES
('communications', 0, 'Uses an audible voice that can be heard at the back of the room.', 'Vocal clarity and projection'),
('communications', 1, 'Speaks fluently in the language of instruction.', 'Language fluency'),
('communications', 2, 'Facilitates a dynamic discussion.', 'Engagement and discussion facilitation'),
('communications', 3, 'Uses engaging non-verbal cues (facial expression, gestures).', 'Non-verbal communication'),
('communications', 4, 'Uses words & expressions suited to the level of the students.', 'Appropriate language level');

-- Management and Presentation (12 items)
INSERT INTO `evaluation_criteria` (`category`, `criterion_index`, `criterion_text`, `description`) VALUES
('management', 0, 'The TILO (Topic Intended Learning Outcomes) are clearly presented.', 'Learning outcomes clarity'),
('management', 1, 'Recall and connects previous lessons to the new lessons.', 'Lesson continuity'),
('management', 2, 'The topic/lesson is introduced in an interesting & engaging way.', 'Lesson introduction'),
('management', 3, 'Uses current issues, real life & local examples to enrich class discussion.', 'Relevance to real world'),
('management', 4, 'Focuses class discussion on key concepts of the lesson.', 'Focus on key concepts'),
('management', 5, 'Encourages active participation among students and ask questions about the topic.', 'Student participation'),
('management', 6, 'Uses current instructional strategies and resources.', 'Instructional strategies'),
('management', 7, 'Designs teaching aids that facilitate understanding of key concepts.', 'Teaching aids design'),
('management', 8, 'Adapts teaching approach in the light of student feedback and reactions.', 'Adaptability'),
('management', 9, 'Aids students using thought provoking questions (Art of Questioning).', 'Questioning techniques'),
('management', 10, 'Integrate the institutional core values to the lessons.', 'Core values integration'),
('management', 11, 'Conduct the lesson using the principle of SMART', 'SMART principle application');

-- Assessment of Students\' Learning (6 items)
INSERT INTO `evaluation_criteria` (`category`, `criterion_index`, `criterion_text`, `description`) VALUES
('assessment', 0, 'Monitors students\' understanding on key concepts discussed.', 'Understanding monitoring'),
('assessment', 1, 'Uses assessment tool that relates specific course competencies stated in the syllabus.', 'Assessment tool alignment'),
('assessment', 2, 'Design test/quarter/assignments and other assessment tasks that are corrector-based.', 'Assessment design'),
('assessment', 3, 'Introduces varied activities that will answer the differentiated needs to the learners with varied learning style.', 'Differentiated learning'),
('assessment', 4, 'Conducts normative assessment before evaluating and grading the learner\'s performance outcome.', 'Normative assessment'),
('assessment', 5, 'Monitors the formative assessment results and find ways to ensure learning for the learners.', 'Formative assessment monitoring');

-- ========================================
-- SAMPLE DATA - EVALUATIONS (Completed Examples)
-- ========================================
INSERT INTO `evaluations` (
  `teacher_id`, `evaluator_id`, `academic_year`, `semester`, `subject_observed`, 
  `observation_date`, `observation_type`, `seat_plan`, `course_syllabi`, 
  `status`, `communications_avg`, `management_avg`, `assessment_avg`, `overall_avg`,
  `strengths`, `improvement_areas`, `recommendations`,
  `rater_signature`, `rater_date`, `faculty_signature`, `faculty_date`, `created_at`
) VALUES
(1, 4, '2023-2024', '1st', 'Data Structures', '2023-11-15', 'Formal', 1, 1, 
'completed', 4.4, 4.2, 4.0, 4.2,
'Excellent classroom management and student engagement. Clear explanations and well-organized lessons.',
'Could improve on incorporating more real-world examples. Time management during discussions could be better.',
'Continue developing interactive teaching strategies. Work on balancing theoretical and practical examples.',
'Dr. Dean CCS', '2023-11-15', 'John Smith', '2023-11-16', NOW()),

(2, 5, '2023-2024', '1st', 'Database Management', '2023-11-18', 'Formal', 1, 1,
'completed', 4.6, 4.4, 4.5, 4.5,
'Outstanding delivery of complex material. Excellent use of visual aids and examples. Students actively engaged.',
'Minor: Could provide more opportunities for student-led discussions.',
'Maintain current teaching excellence. Consider developing advanced discussion techniques.',
'Prof. Coordinator', '2023-11-18', 'Mary Johnson', '2023-11-19', NOW());

-- ========================================
-- SAMPLE DATA - EVALUATION DETAILS (for first evaluation)
-- ========================================
-- Communications ratings for evaluation 1
INSERT INTO `evaluation_details` (`evaluation_id`, `category`, `criterion_index`, `rating`, `comments`) VALUES
(1, 'communications', 0, 5, 'Clear and audible voice throughout the class'),
(1, 'communications', 1, 4, 'Spoke fluently with minor hesitations'),
(1, 'communications', 2, 4, 'Good facilitation with some quiet students not participating'),
(1, 'communications', 3, 5, 'Excellent use of hand gestures and facial expressions'),
(1, 'communications', 4, 4, 'Appropriate language level for students');

-- Management ratings for evaluation 1
INSERT INTO `evaluation_details` (`evaluation_id`, `category`, `criterion_index`, `rating`, `comments`) VALUES
(1, 'management', 0, 4, 'Learning outcomes clearly stated'),
(1, 'management', 1, 4, 'Good connection to previous lessons'),
(1, 'management', 2, 4, 'Engaging introduction with real scenario'),
(1, 'management', 3, 4, 'Used industry examples effectively'),
(1, 'management', 4, 4, 'Focused on key concepts'),
(1, 'management', 5, 4, 'Students actively participated'),
(1, 'management', 6, 4, 'Used modern teaching strategies'),
(1, 'management', 7, 5, 'Well-designed slides and diagrams'),
(1, 'management', 8, 4, 'Adapted well to student questions'),
(1, 'management', 9, 4, 'Good questioning techniques'),
(1, 'management', 10, 3, 'Limited integration of core values'),
(1, 'management', 11, 4, 'Applied SMART principles adequately');

-- Assessment ratings for evaluation 1
INSERT INTO `evaluation_details` (`evaluation_id`, `category`, `criterion_index`, `rating`, `comments`) VALUES
(1, 'assessment', 0, 4, 'Good monitoring through questions'),
(1, 'assessment', 1, 4, 'Assessment aligned with syllabus'),
(1, 'assessment', 2, 4, 'Well-designed assessment tasks'),
(1, 'assessment', 3, 4, 'Provides varied activities'),
(1, 'assessment', 4, 4, 'Conducts regular formative assessment'),
(1, 'assessment', 5, 4, 'Follows up on assessment results');

-- ========================================
-- SAMPLE DATA - EVALUATION DETAILS (for second evaluation)
-- ========================================
-- Communications ratings for evaluation 2
INSERT INTO `evaluation_details` (`evaluation_id`, `category`, `criterion_index`, `rating`, `comments`) VALUES
(2, 'communications', 0, 5, 'Excellent voice projection and clarity'),
(2, 'communications', 1, 5, 'Fluent and natural delivery'),
(2, 'communications', 2, 5, 'Excellent facilitation of dynamic discussions'),
(2, 'communications', 3, 5, 'Engaging non-verbal communication'),
(2, 'communications', 4, 4, 'Well-suited language for the audience');

-- Management ratings for evaluation 2
INSERT INTO `evaluation_details` (`evaluation_id`, `category`, `criterion_index`, `rating`, `comments`) VALUES
(2, 'management', 0, 5, 'Clear and well-articulated learning outcomes'),
(2, 'management', 1, 5, 'Excellent connection to prior knowledge'),
(2, 'management', 2, 4, 'Engaging and creative lesson introduction'),
(2, 'management', 3, 5, 'Excellent use of real-world examples'),
(2, 'management', 4, 4, 'Strong focus on key concepts'),
(2, 'management', 5, 5, 'Excellent student participation'),
(2, 'management', 6, 4, 'Current instructional strategies'),
(2, 'management', 7, 5, 'Excellent teaching aids'),
(2, 'management', 8, 4, 'Good adaptability'),
(2, 'management', 9, 5, 'Excellent questioning techniques'),
(2, 'management', 10, 4, 'Good integration of values'),
(2, 'management', 11, 5, 'Excellent SMART application');

-- Assessment ratings for evaluation 2
INSERT INTO `evaluation_details` (`evaluation_id`, `category`, `criterion_index`, `rating`, `comments`) VALUES
(2, 'assessment', 0, 5, 'Excellent monitoring of understanding'),
(2, 'assessment', 1, 5, 'Perfect alignment with syllabus'),
(2, 'assessment', 2, 4, 'Well-designed assessment tasks'),
(2, 'assessment', 3, 5, 'Excellent variety in activities'),
(2, 'assessment', 4, 4, 'Good normative assessment practices'),
(2, 'assessment', 5, 5, 'Excellent formative assessment monitoring');

-- ========================================
-- CREATE INDEXES FOR PERFORMANCE
-- ========================================
CREATE INDEX idx_evaluations_teacher_date ON evaluations(teacher_id, created_at DESC);
CREATE INDEX idx_evaluations_evaluator ON evaluations(evaluator_id);
CREATE INDEX idx_evaluation_details_eval ON evaluation_details(evaluation_id);
CREATE INDEX idx_teachers_user ON teachers(user_id);
CREATE INDEX idx_users_role_dept ON users(role, department);

-- ========================================
-- NOTES FOR PASSWORD HASHING
-- ========================================
-- The sample passwords above are placeholders.
-- To generate actual bcrypt hashes in PHP, use:
-- $hashedPassword = password_hash('yourpassword', PASSWORD_BCRYPT);
-- 
-- For testing, here are some common test credentials:
-- Username: edp_user, Password: edp123
-- Username: dean_ccs, Password: dean123
-- Username: teacher_john, Password: teacher123
-- 
-- Update the password hashes with actual values using the PHP method above.
