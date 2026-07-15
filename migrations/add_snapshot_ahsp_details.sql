-- Migration: Add RAB Snapshot AHSP Details
-- Date: 2026-01-27

-- =====================================================
-- RAB SNAPSHOT AHSP DETAILS (Editable AHSP for snapshots)
-- =====================================================
CREATE TABLE rab_snapshot_ahsp_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_subcategory_id INT NOT NULL,
    item_id INT NOT NULL,
    category ENUM('upah', 'material', 'alat') NOT NULL,
    coefficient DECIMAL(15,6) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(18,2) GENERATED ALWAYS AS (coefficient * unit_price) STORED,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (snapshot_subcategory_id) REFERENCES rab_snapshot_subcategories(id) ON DELETE CASCADE,
    INDEX idx_subcategory (snapshot_subcategory_id)
) ENGINE=InnoDB;
