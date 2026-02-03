-- =====================================================
-- Migration: Add RAB/RAP submit workflow and PPN
-- Run this SQL in phpMyAdmin or MySQL client
-- =====================================================

-- Add submit status and PPN percentage to projects
ALTER TABLE projects
ADD COLUMN rab_submitted TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
ADD COLUMN rap_submitted TINYINT(1) NOT NULL DEFAULT 0 AFTER rab_submitted,
ADD COLUMN ppn_percentage DECIMAL(5,2) NOT NULL DEFAULT 11.00 AFTER rap_submitted;
