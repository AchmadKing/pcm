-- =====================================================
-- Migration: Add Non-RAB / Indirect Costs Support
-- PCC - Project Cost Control System
-- Purpose: Support Non-RAB Budgets, Dual-Source Requests (Direct vs Non-RAB), and Budget Revision Logs
-- Date: 2026-10-01
-- =====================================================

-- 1. Create project_non_rab_budgets table
CREATE TABLE IF NOT EXISTS `project_non_rab_budgets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `category` ENUM('operasional', 'umum', 'tak_terduga', 'lainnya') NOT NULL,
    `budget_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_project_category` (`project_id`, `category`),
    INDEX `idx_project_id` (`project_id`),
    CONSTRAINT `fk_non_rab_budget_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create project_non_rab_budget_logs table (Audit Trail)
CREATE TABLE IF NOT EXISTS `project_non_rab_budget_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `category` ENUM('operasional', 'umum', 'tak_terduga', 'lainnya') NOT NULL,
    `old_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `new_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `reason` VARCHAR(255) NULL,
    `changed_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_log_project_cat` (`project_id`, `category`),
    CONSTRAINT `fk_non_rab_log_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_non_rab_log_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add request_type and non_rab_category to requests table
ALTER TABLE `requests`
    ADD COLUMN IF NOT EXISTS `request_type` ENUM('rab', 'non_rab') NOT NULL DEFAULT 'rab' AFTER `project_id`,
    ADD COLUMN IF NOT EXISTS `non_rab_category` ENUM('operasional', 'umum', 'tak_terduga', 'lainnya') NULL DEFAULT NULL AFTER `request_type`;

-- 4. Add indexes on requests table if not exists
CREATE INDEX IF NOT EXISTS `idx_request_type` ON `requests` (`request_type`);
CREATE INDEX IF NOT EXISTS `idx_non_rab_category` ON `requests` (`non_rab_category`);

-- 5. Modify request_items.subcategory_id to be nullable
ALTER TABLE `request_items`
    MODIFY COLUMN `subcategory_id` INT NULL DEFAULT NULL;
