-- Migration: Add Weekly Progress Table
-- Date: 2026-01-30

-- Table to store weekly progress data for each subcategory
CREATE TABLE IF NOT EXISTS weekly_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    subcategory_id INT NOT NULL,
    week_number INT NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    realization_amount DECIMAL(18,2) DEFAULT 0,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (subcategory_id) REFERENCES rab_subcategories(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY unique_week_subcat (project_id, subcategory_id, week_number),
    INDEX idx_project (project_id),
    INDEX idx_subcategory (subcategory_id),
    INDEX idx_week (week_number)
) ENGINE=InnoDB;
