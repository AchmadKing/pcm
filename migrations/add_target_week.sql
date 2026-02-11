-- Migration: Add target_week column to requests table
-- Date: 2026-02-10

ALTER TABLE requests ADD COLUMN target_week INT DEFAULT NULL AFTER admin_notes;
