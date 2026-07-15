-- =====================================================
-- Fix Request System - Missing columns and tables
-- Run this migration to fix create.php bugs
-- =====================================================

-- 1. Add missing columns to request_items
ALTER TABLE request_items 
    ADD COLUMN IF NOT EXISTS item_type VARCHAR(20) DEFAULT NULL COMMENT 'Type: upah, material, alat' AFTER item_code,
    ADD COLUMN IF NOT EXISTS subcat_details TEXT DEFAULT NULL COMMENT 'JSON details of subcategory distribution' AFTER subcategory_id;

-- 2. Create request_attachments table if not exists
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

-- 3. Normalize collation on RAP tables to utf8mb4_unicode_ci (match the rest of the database)
ALTER TABLE project_items_rap CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE project_ahsp_rap CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE project_ahsp_details_rap CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Done!
