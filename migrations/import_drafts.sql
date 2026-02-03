-- Migration: Create import_drafts table
-- This stores preview data so admin can edit before final import

CREATE TABLE IF NOT EXISTS import_drafts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    import_type ENUM('items', 'ahsp') NOT NULL DEFAULT 'items',
    data JSON NOT NULL,
    valid_count INT DEFAULT 0,
    error_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_type (project_id, import_type)
);
