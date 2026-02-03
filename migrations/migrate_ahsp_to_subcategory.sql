-- =====================================================
-- Migration: Change AHSP from rab_item level to subcategory level
-- Run this SQL in phpMyAdmin or MySQL client
-- =====================================================

-- Add subcategory_id column to ahsp_details
ALTER TABLE ahsp_details 
ADD COLUMN subcategory_id INT AFTER id,
ADD FOREIGN KEY (subcategory_id) REFERENCES rab_subcategories(id) ON DELETE CASCADE,
ADD INDEX idx_subcategory (subcategory_id);

-- Make rab_item_id nullable (for backward compatibility)
ALTER TABLE ahsp_details 
MODIFY COLUMN rab_item_id INT NULL;

-- Update existing data: copy subcategory_id from rab_items
UPDATE ahsp_details ad
JOIN rab_items ri ON ad.rab_item_id = ri.id
SET ad.subcategory_id = ri.subcategory_id;
