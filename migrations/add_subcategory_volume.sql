-- =====================================================
-- Migration: Add unit and volume to rab_subcategories
-- Run this SQL in phpMyAdmin or MySQL client
-- =====================================================

-- Add unit and volume columns to rab_subcategories
ALTER TABLE rab_subcategories 
ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'm2' AFTER name,
ADD COLUMN volume DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER unit;

-- Add unit_price column (calculated from AHSP, stored for convenience)
ALTER TABLE rab_subcategories 
ADD COLUMN unit_price DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER volume;
