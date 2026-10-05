-- =====================================================
-- Migration: Add Borongan and Harian Support for Requests
-- Project: PCM - Project Cost Management
-- Purpose: Support Borongan (default) and Harian methods for Direct Cost / RAP
-- Date: 2026-10-05
-- =====================================================

-- 1. Tambah work_type di tabel requests (default: 'borongan')
ALTER TABLE `requests`
    ADD COLUMN IF NOT EXISTS `work_type` ENUM('borongan', 'harian') NOT NULL DEFAULT 'borongan' AFTER `request_type`;

-- 2. Tambah detail input harian di tabel request_items (default work_type: 'borongan')
ALTER TABLE `request_items`
    ADD COLUMN IF NOT EXISTS `work_type` ENUM('borongan', 'harian') NOT NULL DEFAULT 'borongan' AFTER `item_type`,
    ADD COLUMN IF NOT EXISTS `work_volume` DECIMAL(15,4) NULL DEFAULT NULL AFTER `work_type`,
    ADD COLUMN IF NOT EXISTS `work_unit` VARCHAR(50) NULL DEFAULT NULL AFTER `work_volume`,
    ADD COLUMN IF NOT EXISTS `work_quantity` DECIMAL(15,4) NULL DEFAULT NULL AFTER `work_unit`,
    ADD COLUMN IF NOT EXISTS `work_quantity_unit` VARCHAR(50) NULL DEFAULT NULL AFTER `work_quantity`,
    ADD COLUMN IF NOT EXISTS `work_duration` DECIMAL(15,2) NULL DEFAULT NULL AFTER `work_quantity_unit`,
    ADD COLUMN IF NOT EXISTS `work_duration_unit` VARCHAR(20) NULL DEFAULT 'Hr' AFTER `work_duration`,
    ADD COLUMN IF NOT EXISTS `work_billing_unit` VARCHAR(50) NULL DEFAULT NULL AFTER `work_duration_unit`;

-- 3. Tambah indeks untuk performa query
CREATE INDEX IF NOT EXISTS `idx_requests_work_type` ON `requests` (`work_type`);
CREATE INDEX IF NOT EXISTS `idx_request_items_work_type` ON `request_items` (`work_type`);
CREATE INDEX IF NOT EXISTS `idx_request_items_work_volume` ON `request_items` (`work_volume`);
