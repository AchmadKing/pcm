-- ==============================================================================
-- MIGRATION: Restore Compatibility Columns for Hosting
-- Project: PCM - Project Cost Management
-- Purpose:
--   Jika file PHP di hosting belum ter-update ke commit terbaru (masih membaca
--   kolom unit_price / total_price tersimpan), script ini menambahkan kembali
--   kolom-kolom tersebut sehingga website di hosting tidak mengalami HTTP ERROR 500.
-- ==============================================================================

-- 1. RAB & RAP: Tambah kembali kolom unit_price dan total_price
ALTER TABLE `rab_subcategories` 
    ADD COLUMN IF NOT EXISTS `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `volume`;

ALTER TABLE `rap_items` 
    ADD COLUMN IF NOT EXISTS `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `volume`,
    ADD COLUMN IF NOT EXISTS `total_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`;

-- 2. request_items: Tambah kembali total_price & coefficient
ALTER TABLE `request_items` 
    ADD COLUMN IF NOT EXISTS `total_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`,
    ADD COLUMN IF NOT EXISTS `coefficient` decimal(15,4) NULL DEFAULT NULL AFTER `total_price`;

-- 3. requests: Tambah kembali total_amount & approved_amount
ALTER TABLE `requests` 
    ADD COLUMN IF NOT EXISTS `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `status`,
    ADD COLUMN IF NOT EXISTS `approved_amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`;

-- 4. Master Data AHSP: Tambah kembali unit_price
ALTER TABLE `project_ahsp` 
    ADD COLUMN IF NOT EXISTS `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `unit`;

ALTER TABLE `project_ahsp_rap` 
    ADD COLUMN IF NOT EXISTS `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `unit`;

-- 5. Snapshots: Tambah kembali unit_price & total_price
ALTER TABLE `rab_snapshot_subcategories` 
    ADD COLUMN IF NOT EXISTS `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `volume`;

ALTER TABLE `rab_snapshot_ahsp_details` 
    ADD COLUMN IF NOT EXISTS `total_price` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`;

-- 6. Tabel weekly_progress (jika script approval/actualization lama masih memanggilnya)
CREATE TABLE IF NOT EXISTS `weekly_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `subcategory_id` int(11) NOT NULL,
  `week_number` int(11) NOT NULL,
  `week_start` date DEFAULT NULL,
  `week_end` date DEFAULT NULL,
  `realization_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `subcategory_id` (`subcategory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
