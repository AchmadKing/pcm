-- =====================================================
-- Migration: Add rap_ahsp_details table
-- Purpose: Store RAP-specific AHSP customizations
-- These changes don't affect Master Data
-- =====================================================

-- Create table for RAP-specific AHSP details
CREATE TABLE IF NOT EXISTS rap_ahsp_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rap_item_id INT NOT NULL,
    item_id INT NOT NULL,
    category ENUM('upah','material','alat') NOT NULL,
    coefficient DECIMAL(15,6) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rap_item_id) REFERENCES rap_items(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES project_items(id) ON DELETE CASCADE,
    UNIQUE KEY unique_rap_item_detail (rap_item_id, item_id)
) ENGINE=InnoDB;

-- Add index for faster lookups (ignore if already exists)
-- Note: MySQL doesn't support IF NOT EXISTS for CREATE INDEX
-- These will be skipped if already created with the table
