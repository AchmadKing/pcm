-- Add unit_price column to project_ahsp_details for RAB AHSP
-- NULL means use item's UP price from project_items, otherwise use this custom price
ALTER TABLE project_ahsp_details 
ADD COLUMN unit_price DECIMAL(15,2) DEFAULT NULL AFTER coefficient;
