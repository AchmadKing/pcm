-- Migration: Add RAB Snapshot Tables
-- Date: 2026-01-27

-- =====================================================
-- 1. RAB SNAPSHOTS (Header untuk salinan RAB)
-- =====================================================
CREATE TABLE rab_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    created_by INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    overhead_percentage DECIMAL(5,2) DEFAULT 10.00,
    ppn_percentage DECIMAL(5,2) DEFAULT 11.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_project (project_id)
) ENGINE=InnoDB;

-- =====================================================
-- 2. RAB SNAPSHOT CATEGORIES (Kategori dalam salinan)
-- =====================================================
CREATE TABLE rab_snapshot_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    original_category_id INT,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (snapshot_id) REFERENCES rab_snapshots(id) ON DELETE CASCADE,
    INDEX idx_snapshot (snapshot_id)
) ENGINE=InnoDB;

-- =====================================================
-- 3. RAB SNAPSHOT SUBCATEGORIES (Subkategori dalam salinan)
-- =====================================================
CREATE TABLE rab_snapshot_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    original_subcategory_id INT,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    unit VARCHAR(50),
    volume DECIMAL(15,4) DEFAULT 0,
    unit_price DECIMAL(15,2) DEFAULT 0,
    ahsp_id INT,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (category_id) REFERENCES rab_snapshot_categories(id) ON DELETE CASCADE,
    INDEX idx_category (category_id)
) ENGINE=InnoDB;

-- =====================================================
-- 4. Add RAB source columns to rap_items
-- =====================================================
ALTER TABLE rap_items 
ADD COLUMN rab_source_type ENUM('rab', 'snapshot') DEFAULT 'rab' AFTER notes,
ADD COLUMN rab_snapshot_id INT NULL AFTER rab_source_type;

-- Add foreign key separately for cleaner migration
ALTER TABLE rap_items
ADD CONSTRAINT fk_rap_snapshot FOREIGN KEY (rab_snapshot_id) 
    REFERENCES rab_snapshots(id) ON DELETE SET NULL;
