-- Add project_code column to projects table
ALTER TABLE projects ADD COLUMN project_code VARCHAR(50) NULL AFTER id;
