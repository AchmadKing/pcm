-- =====================================================
-- Migration: Add Overhead & Profit Apply Flags (AHSP, RAB, RAP)
-- PCC - Project Cost Control System
-- Purpose: Support independent toggles for Overhead & Profit calculation across AHSP, RAB, and RAP
-- Date: 2026-09-30
-- =====================================================

-- 1. Add columns to `projects` table
-- Default is 1 (Active/ON) for all scopes to maintain existing calculation behavior
ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `overhead_apply_ahsp` TINYINT(1) NOT NULL DEFAULT 1 AFTER `profit_percentage`,
  ADD COLUMN IF NOT EXISTS `overhead_apply_rab`  TINYINT(1) NOT NULL DEFAULT 1 AFTER `overhead_apply_ahsp`,
  ADD COLUMN IF NOT EXISTS `overhead_apply_rap`  TINYINT(1) NOT NULL DEFAULT 1 AFTER `overhead_apply_rab`;

-- 2. Add columns to `rab_snapshots` table
-- Captures frozen toggle states at snapshot creation time
ALTER TABLE `rab_snapshots`
  ADD COLUMN IF NOT EXISTS `overhead_apply_ahsp` TINYINT(1) NOT NULL DEFAULT 1 AFTER `profit_percentage`,
  ADD COLUMN IF NOT EXISTS `overhead_apply_rab`  TINYINT(1) NOT NULL DEFAULT 1 AFTER `overhead_apply_ahsp`,
  ADD COLUMN IF NOT EXISTS `overhead_apply_rap`  TINYINT(1) NOT NULL DEFAULT 1 AFTER `overhead_apply_rab`;

-- 3. Ensure existing project records have default value = 1 (Active)
UPDATE `projects` 
SET 
  `overhead_apply_ahsp` = COALESCE(`overhead_apply_ahsp`, 1),
  `overhead_apply_rab`  = COALESCE(`overhead_apply_rab`, 1),
  `overhead_apply_rap`  = COALESCE(`overhead_apply_rap`, 1);

-- 4. Ensure existing snapshot records have default value = 1 (Active)
UPDATE `rab_snapshots` 
SET 
  `overhead_apply_ahsp` = COALESCE(`overhead_apply_ahsp`, 1),
  `overhead_apply_rab`  = COALESCE(`overhead_apply_rab`, 1),
  `overhead_apply_rap`  = COALESCE(`overhead_apply_rap`, 1);
