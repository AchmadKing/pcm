-- Add item_code and brand columns to project_items table
ALTER TABLE project_items ADD COLUMN item_code VARCHAR(50) NOT NULL AFTER project_id;
ALTER TABLE project_items ADD COLUMN brand VARCHAR(100) NULL AFTER name;
