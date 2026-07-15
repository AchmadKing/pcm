-- =====================================================
-- PEROMBAKAN SISTEM: Master Data Per-Proyek
-- Jalankan script ini di phpMyAdmin
-- PERINGATAN: Script ini akan MENGHAPUS data lama!
-- =====================================================

-- =====================================================
-- STEP 1: Disable foreign key checks and drop ALL old tables
-- =====================================================

-- PENTING: Disable foreign key checks dulu
SET FOREIGN_KEY_CHECKS = 0;

-- Drop semua tabel yang mungkin ada (urutan tidak penting karena FK disabled)
DROP TABLE IF EXISTS item_prices;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS regions;
DROP TABLE IF EXISTS ahsp_details;
DROP TABLE IF EXISTS rap_items;
DROP TABLE IF EXISTS request_items;
DROP TABLE IF EXISTS requests;
DROP TABLE IF EXISTS rab_items;
DROP TABLE IF EXISTS rab_subcategories;
DROP TABLE IF EXISTS rab_categories;
DROP TABLE IF EXISTS project_ahsp_details;
DROP TABLE IF EXISTS project_ahsp;
DROP TABLE IF EXISTS project_items;
DROP TABLE IF EXISTS projects;

-- Jangan enable FK checks dulu, tunggu sampai semua CREATE selesai

-- =====================================================
-- STEP 2: Create new projects table (region as text)
-- =====================================================
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    region_name VARCHAR(100),
    status ENUM('draft', 'on_progress', 'completed') DEFAULT 'draft',
    rab_submitted TINYINT(1) NOT NULL DEFAULT 0,
    rap_submitted TINYINT(1) NOT NULL DEFAULT 0,
    ppn_percentage DECIMAL(5,2) NOT NULL DEFAULT 11.00,
    
    -- Contract Info
    activity_name VARCHAR(255),
    work_description TEXT,
    funding_source VARCHAR(200),
    budget_year YEAR,
    contract_number VARCHAR(100),
    contract_date DATE,
    addendum_number VARCHAR(100),
    addendum_date DATE,
    spk_number VARCHAR(100),
    spk_date DATE,
    spmk_number VARCHAR(100),
    spmk_date DATE,
    service_provider VARCHAR(200),
    supervisor_consultant VARCHAR(200),
    duration_days INT,
    start_date DATE,
    
    overhead_percentage DECIMAL(5,2) DEFAULT 10.00,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 3: Create project_items (Items per project)
-- =====================================================
CREATE TABLE project_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    category ENUM('upah', 'material', 'alat') NOT NULL,
    unit VARCHAR(50) NOT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id),
    INDEX idx_category (category)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 4: Create project_ahsp (AHSP Templates per project)
-- =====================================================
CREATE TABLE project_ahsp (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    work_name VARCHAR(200) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 5: Create project_ahsp_details (AHSP components)
-- =====================================================
CREATE TABLE project_ahsp_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ahsp_id INT NOT NULL,
    item_id INT NOT NULL,
    coefficient DECIMAL(15,6) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ahsp_id) REFERENCES project_ahsp(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES project_items(id) ON DELETE CASCADE,
    INDEX idx_ahsp (ahsp_id)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 6: Create rab_categories
-- =====================================================
CREATE TABLE rab_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(200) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 7: Create rab_subcategories (linked to AHSP)
-- =====================================================
CREATE TABLE rab_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    ahsp_id INT NOT NULL,
    code VARCHAR(20),
    name VARCHAR(200) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    volume DECIMAL(15,4) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES rab_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (ahsp_id) REFERENCES project_ahsp(id),
    INDEX idx_category (category_id)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 8: Create rap_items (linked to subcategories)
-- =====================================================
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
    UNIQUE KEY unique_subcategory (subcategory_id)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 9: Create requests
-- =====================================================
CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    request_number VARCHAR(50),
    request_date DATE NOT NULL,
    week_number INT DEFAULT 1,
    description TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    total_amount DECIMAL(18,2) DEFAULT 0,
    approved_amount DECIMAL(18,2) DEFAULT 0,
    approved_by INT,
    approved_at DATETIME,
    admin_notes TEXT,
    rejection_reason TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_project (project_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- =====================================================
-- STEP 10: Create request_items
-- =====================================================
CREATE TABLE request_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    subcategory_id INT,
    item_name VARCHAR(200) NOT NULL,
    unit VARCHAR(50),
    quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(18,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (subcategory_id) REFERENCES rab_subcategories(id) ON DELETE SET NULL,
    INDEX idx_request (request_id)
) ENGINE=InnoDB;

-- =====================================================
-- DONE! Re-enable foreign key checks
-- =====================================================
SET FOREIGN_KEY_CHECKS = 1;

-- Selesai! Database berhasil direstrukturisasi
