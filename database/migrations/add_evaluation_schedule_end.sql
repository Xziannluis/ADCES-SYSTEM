-- Add end time field for evaluation schedule
ALTER TABLE `teachers` ADD COLUMN `evaluation_schedule_end` DATETIME DEFAULT NULL AFTER `evaluation_schedule`;
