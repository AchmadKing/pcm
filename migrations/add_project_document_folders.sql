-- =====================================================
-- Migration: Add Project Document Folders & Folder ID
-- PCC - Project Cost Control System
-- =====================================================

CREATE TABLE IF NOT EXISTS project_document_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    parent_id INT NULL DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES project_document_folders(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_project_folder (project_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add folder_id to project_documents if not exists
SET @exist := (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'project_documents' 
      AND COLUMN_NAME = 'folder_id'
);

SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE project_documents ADD COLUMN folder_id INT NULL DEFAULT NULL AFTER project_id, ADD CONSTRAINT fk_project_documents_folder FOREIGN KEY (folder_id) REFERENCES project_document_folders(id) ON DELETE CASCADE, ADD INDEX idx_folder_id (folder_id);', 'SELECT ''Column folder_id already exists'';');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Make category nullable if not already
ALTER TABLE project_documents MODIFY COLUMN category VARCHAR(100) NULL DEFAULT NULL;
