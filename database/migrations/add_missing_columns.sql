-- ========================================
-- ADD MISSING COLUMNS TO EXISTING TABLES
-- Run this in phpMyAdmin on a PC that already has the database
-- ========================================

-- TEACHERS TABLE: add schedule-related columns
ALTER TABLE `teachers`
  ADD COLUMN `evaluation_focus` TEXT DEFAULT NULL AFTER `evaluation_room`,
  ADD COLUMN `evaluation_subject_area` VARCHAR(255) DEFAULT NULL AFTER `evaluation_focus`,
  ADD COLUMN `evaluation_subject` VARCHAR(255) DEFAULT NULL AFTER `evaluation_subject_area`,
  ADD COLUMN `evaluation_semester` VARCHAR(10) DEFAULT NULL AFTER `evaluation_subject`,
  ADD COLUMN `teaching_semester` VARCHAR(10) DEFAULT NULL AFTER `status`;

-- EVALUATIONS TABLE: add observation plan columns
ALTER TABLE `evaluations`
  ADD COLUMN `faculty_name` VARCHAR(255) DEFAULT NULL AFTER `teacher_id`,
  ADD COLUMN `department` VARCHAR(100) DEFAULT NULL AFTER `faculty_name`,
  ADD COLUMN `observation_room` VARCHAR(100) DEFAULT NULL AFTER `observation_type`,
  ADD COLUMN `subject_area` VARCHAR(255) DEFAULT NULL AFTER `observation_room`,
  ADD COLUMN `evaluation_focus` TEXT DEFAULT NULL AFTER `subject_area`;
