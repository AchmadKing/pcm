-- =====================================================
-- Migration: Add Project Documents Table & Permissions
-- PCM - Project Cost Management System
-- =====================================================

CREATE TABLE IF NOT EXISTS project_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) DEFAULT NULL,
    file_size BIGINT DEFAULT 0,
    category VARCHAR(100) NOT NULL DEFAULT 'Umum / Referensi',
    description TEXT DEFAULT NULL,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_project_id (project_id),
    INDEX idx_category (category),
    INDEX idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant permissions to Super Admin, Admin, and Project Manager by default if role exists
INSERT INTO role_permissions (role_id, permission_key, is_allowed, view_mode)
SELECT r.id, 'documentation.view', 1, NULL
FROM roles r
WHERE r.name IN ('super_admin', 'admin', 'project_manager')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp 
      WHERE rp.role_id = r.id AND rp.permission_key = 'documentation.view'
  );

INSERT INTO role_permissions (role_id, permission_key, is_allowed, view_mode)
SELECT r.id, 'documentation.upload', 1, NULL
FROM roles r
WHERE r.name IN ('super_admin', 'admin', 'project_manager')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp 
      WHERE rp.role_id = r.id AND rp.permission_key = 'documentation.upload'
  );
