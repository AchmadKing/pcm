-- ========================================
-- Request System Enhancement Migration
-- Field Teams & Project Assignments
-- ========================================

-- 1. FIELD TEAMS TABLE
-- Menyimpan data personil lapangan
CREATE TABLE IF NOT EXISTS field_teams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(100) DEFAULT NULL COMMENT 'Jabatan/Role di lapangan',
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_name (name),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. PROJECT ASSIGNMENTS TABLE
-- Tabel pivot untuk menugaskan field team ke proyek tertentu
CREATE TABLE IF NOT EXISTS project_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    team_id INT NOT NULL,
    assigned_by INT DEFAULT NULL COMMENT 'User ID admin yang menugaskan',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL COMMENT 'Catatan penugasan',
    is_active TINYINT(1) DEFAULT 1,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES field_teams(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    
    UNIQUE KEY unique_assignment (project_id, team_id),
    INDEX idx_project (project_id),
    INDEX idx_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ENHANCE REQUEST_ITEMS TABLE (if needed)
-- Add category_id column if not exists
SET @query = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'request_items' 
     AND COLUMN_NAME = 'category_id') = 0,
    'ALTER TABLE request_items ADD COLUMN category_id INT DEFAULT NULL AFTER subcategory_id',
    'SELECT 1'
));
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add item_code column if not exists
SET @query = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'request_items' 
     AND COLUMN_NAME = 'item_code') = 0,
    'ALTER TABLE request_items ADD COLUMN item_code VARCHAR(50) DEFAULT NULL AFTER item_name COMMENT "Kode AHSP dari RAP"',
    'SELECT 1'
));
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add coefficient column if not exists (for AHSP reference)
SET @query = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'request_items' 
     AND COLUMN_NAME = 'coefficient') = 0,
    'ALTER TABLE request_items ADD COLUMN coefficient DECIMAL(15,6) DEFAULT 1.000000 AFTER quantity COMMENT "Koefisien yang diajukan"',
    'SELECT 1'
));
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add snapshot price column if not exists
SET @query = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'request_items' 
     AND COLUMN_NAME = 'snapshot_unit_price') = 0,
    'ALTER TABLE request_items ADD COLUMN snapshot_unit_price DECIMAL(15,2) DEFAULT NULL AFTER unit_price COMMENT "Harga satuan saat pengajuan (snapshot)"',
    'SELECT 1'
));
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. ADD FOREIGN KEY TO REQUEST_ITEMS FOR CATEGORY (if not exists)
-- Note: We don't add FK constraint since category_id might be optional

-- 5. REQUEST ATTACHMENTS TABLE
-- Menyimpan file lampiran (nota/bukti) untuk pengajuan
CREATE TABLE IF NOT EXISTS request_attachments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL COMMENT 'Nama file di server',
    original_name VARCHAR(255) NOT NULL COMMENT 'Nama file asli dari user',
    file_type VARCHAR(50) DEFAULT NULL COMMENT 'MIME type',
    file_size INT DEFAULT NULL COMMENT 'Ukuran file dalam bytes',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. MODIFY PROJECT_ASSIGNMENTS TO USE USER_ID INSTEAD OF TEAM_ID
-- Update project_assignments to reference users table for field_team role
-- First check if using old structure
SET @has_team_id = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'project_assignments' 
    AND COLUMN_NAME = 'team_id');

SET @has_user_id = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'project_assignments' 
    AND COLUMN_NAME = 'user_id');

-- Add user_id column if not exists
SET @query = (SELECT IF(@has_user_id = 0,
    'ALTER TABLE project_assignments ADD COLUMN user_id INT DEFAULT NULL AFTER project_id',
    'SELECT 1'
));
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
