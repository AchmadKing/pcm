-- =====================================================
-- Migration: Update RAP to link to subcategories
-- Run this SQL in phpMyAdmin or MySQL client
-- =====================================================

-- Drop existing rap_items table and recreate with subcategory_id
DROP TABLE IF EXISTS rap_items;

CREATE TABLE rap_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subcategory_id INT NOT NULL,
    volume DECIMAL(15,4) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(18,2) GENERATED ALWAYS AS (volume * unit_price) STORED,
    notes TEXT,
    is_locked TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subcategory_id) REFERENCES rab_subcategories(id) ON DELETE CASCADE,
    INDEX idx_subcategory (subcategory_id)
) ENGINE=InnoDB;
