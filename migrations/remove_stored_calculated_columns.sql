-- ==============================================================================
-- MIGRATION: Remove Stored Calculated Columns & Weekly Progress Cache
-- Project: PCM - Project Cost Management
-- Branch: main
-- Description:
--   1. Drops stored/generated calculated columns across requests, items, AHSPs,
--      RAB/RAP subcategories, and snapshots.
--   2. Modifies request_items.quantity to be NULLABLE and sets quantity = NULL
--      for Harian items (where effective qty is dynamically calculated as
--      work_quantity * work_duration).
--   3. Drops legacy cache table `weekly_progress` (all weekly history is 100%
--      dynamically derived from approved requests).
--   4. Preserves Option A: project_ahsp_details.unit_price and project_ahsp_details_rap.unit_price
--      are kept intact as nullable unit price overrides.
-- ==============================================================================

-- 1. request_items: Drop calculated columns, make quantity nullable, and backfill Harian
ALTER TABLE `request_items` DROP COLUMN `total_price`;
ALTER TABLE `request_items` DROP COLUMN `coefficient`;
ALTER TABLE `request_items` MODIFY COLUMN `quantity` decimal(15,4) NULL DEFAULT NULL;
UPDATE `request_items` SET `quantity` = NULL WHERE `work_type` = 'harian';

-- 2. requests: Drop stored totals
ALTER TABLE `requests` DROP COLUMN `total_amount`;
ALTER TABLE `requests` DROP COLUMN `approved_amount`;

-- 3. Master Data AHSP: Drop stored unit_price
ALTER TABLE `project_ahsp` DROP COLUMN `unit_price`;
ALTER TABLE `project_ahsp_rap` DROP COLUMN `unit_price`;

-- 4. RAB & RAP: Drop stored unit_price and generated total_price
ALTER TABLE `rab_subcategories` DROP COLUMN `unit_price`;
ALTER TABLE `rap_items` DROP COLUMN `total_price`;
ALTER TABLE `rap_items` DROP COLUMN `unit_price`;

-- 5. Snapshots: Drop stored unit_price and generated total_price
ALTER TABLE `rab_snapshot_subcategories` DROP COLUMN `unit_price`;
ALTER TABLE `rab_snapshot_ahsp_details` DROP COLUMN `total_price`;

-- 6. Cache table: Drop weekly_progress table
DROP TABLE IF EXISTS `weekly_progress`;
