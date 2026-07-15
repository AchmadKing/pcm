-- =====================================================
-- Master Data RAP - Database Migration
-- Membuat tabel terpisah untuk Master Data RAP
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 1. project_items_rap (Items untuk RAP)
-- =====================================================
CREATE TABLE IF NOT EXISTS project_items_rap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    item_code VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL,
    brand VARCHAR(100),
    category ENUM('upah', 'material', 'alat') NOT NULL,
    unit VARCHAR(50) NOT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    actual_price DECIMAL(15,2),
    rab_item_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (rab_item_id) REFERENCES project_items(id) ON DELETE SET NULL,
    UNIQUE KEY unique_code_per_project (project_id, item_code),
    INDEX idx_project (project_id),
    INDEX idx_category (category),
    INDEX idx_rab_item (rab_item_id)
) ENGINE=InnoDB;

-- =====================================================
-- 2. project_ahsp_rap (AHSP untuk RAP)
-- =====================================================
CREATE TABLE IF NOT EXISTS project_ahsp_rap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    ahsp_code VARCHAR(50) NOT NULL,
    work_name VARCHAR(200) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    rab_ahsp_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (rab_ahsp_id) REFERENCES project_ahsp(id) ON DELETE SET NULL,
    UNIQUE KEY unique_code_per_project (project_id, ahsp_code),
    INDEX idx_project (project_id),
    INDEX idx_rab_ahsp (rab_ahsp_id)
) ENGINE=InnoDB;

-- =====================================================
-- 3. project_ahsp_details_rap (Detail AHSP untuk RAP)
-- =====================================================
CREATE TABLE IF NOT EXISTS project_ahsp_details_rap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ahsp_id INT NOT NULL,
    item_id INT NOT NULL,
    coefficient DECIMAL(15,6) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ahsp_id) REFERENCES project_ahsp_rap(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES project_items_rap(id) ON DELETE CASCADE,
    INDEX idx_ahsp (ahsp_id),
    INDEX idx_item (item_id)
) ENGINE=InnoDB;

-- =====================================================
-- 4. Add flag to projects table to track RAP master data init
-- =====================================================
ALTER TABLE projects ADD COLUMN IF NOT EXISTS rap_master_data_initialized TINYINT(1) DEFAULT 0;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- Selesai! Tabel Master Data RAP berhasil dibuat
-- =====================================================
