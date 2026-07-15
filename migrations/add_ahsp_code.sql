-- Add ahsp_code column to project_ahsp table
ALTER TABLE project_ahsp ADD COLUMN ahsp_code VARCHAR(50) NULL AFTER project_id;

-- Set default value for existing rows
UPDATE project_ahsp SET ahsp_code = CONCAT('AHSP-', id) WHERE ahsp_code IS NULL OR ahsp_code = '';
