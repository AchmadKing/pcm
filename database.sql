-- =====================================================
-- PCM (Project Cost Management) Database Schema
-- Database: pcm_db
-- =====================================================

CREATE DATABASE IF NOT EXISTS pcm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pcm_db;

-- =====================================================
-- 1. USERS TABLE
-- =====================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'field_team') NOT NULL DEFAULT 'field_team',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role)
) ENGINE=InnoDB;

-- =====================================================
-- 2. REGIONS TABLE
-- =====================================================
CREATE TABLE regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB;

-- =====================================================
-- 3. ITEMS TABLE (Material, Labor, Equipment)
-- =====================================================
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(200) NOT NULL,
    type ENUM('material', 'labor', 'equipment') NOT NULL,
    unit VARCHAR(20) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_type (type),
    INDEX idx_name (name)
) ENGINE=InnoDB;

-- =====================================================
-- 4. ITEM PRICES (Multi-Quality Pricing)
-- =====================================================
CREATE TABLE item_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    region_id INT NOT NULL,
    quality ENUM('top', 'mid', 'low', 'bad') NOT NULL DEFAULT 'mid',
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_price (item_id, region_id, quality),
    INDEX idx_region (region_id),
    INDEX idx_quality (quality)
) ENGINE=InnoDB;

-- =====================================================
-- 5. PROJECTS TABLE
-- =====================================================
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    region_id INT NOT NULL,
    description TEXT,
    status ENUM('draft', 'on_progress', 'completed') NOT NULL DEFAULT 'draft',
    
    -- Project Info (from gambar 2)
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
    rab_submitted TINYINT(1) NOT NULL DEFAULT 0,
    rap_submitted TINYINT(1) NOT NULL DEFAULT 0,
    ppn_percentage DECIMAL(5,2) NOT NULL DEFAULT 11.00,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_region (region_id)
) ENGINE=InnoDB;

-- =====================================================
-- 6. RAB CATEGORIES (Kategori Pekerjaan: A, B, C)
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
-- 7. RAB SUBCATEGORIES (Sub-Kategori: 1, 2, 3)
-- Each subcategory has its own unit, volume, and unit_price (from AHSP)
-- =====================================================
CREATE TABLE rab_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(200) NOT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'm2',
    volume DECIMAL(15,4) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES rab_categories(id) ON DELETE CASCADE,
    INDEX idx_category (category_id)
) ENGINE=InnoDB;

-- =====================================================
-- 8. RAB ITEMS (Item Pekerjaan)
-- =====================================================
CREATE TABLE rab_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subcategory_id INT NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(255) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    volume DECIMAL(15,4) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(18,2) GENERATED ALWAYS AS (volume * unit_price) STORED,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subcategory_id) REFERENCES rab_subcategories(id) ON DELETE CASCADE,
    INDEX idx_subcategory (subcategory_id)
) ENGINE=InnoDB;

-- =====================================================
-- 9. AHSP DETAILS (Analisa Harga Satuan Pekerjaan)
-- AHSP is at subcategory level - components apply to all items in subcategory
-- =====================================================
CREATE TABLE ahsp_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subcategory_id INT NOT NULL,
    item_id INT NOT NULL,
    type ENUM('labor', 'material', 'equipment') NOT NULL,
    coefficient DECIMAL(15,6) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(18,2) GENERATED ALWAYS AS (coefficient * unit_price) STORED,
    quality ENUM('top', 'mid', 'low', 'bad') DEFAULT 'top',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subcategory_id) REFERENCES rab_subcategories(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id),
    INDEX idx_subcategory (subcategory_id),
    INDEX idx_type (type)
) ENGINE=InnoDB;

-- =====================================================
-- 10. RAP ITEMS (Budget Internal - Copy dari RAB Subcategories)
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
    UNIQUE KEY unique_subcategory (subcategory_id),
    INDEX idx_locked (is_locked)
) ENGINE=InnoDB;

-- =====================================================
-- 11. REQUESTS (Pengajuan Dana dari Tim Lapangan)
-- =====================================================
CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    request_number VARCHAR(50) NOT NULL,
    request_date DATE NOT NULL,
    week_number INT,
    description TEXT,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_notes TEXT,
    approved_by INT,
    approved_at DATETIME,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_project (project_id),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB;

-- =====================================================
-- 12. REQUEST ITEMS (Detail Item dalam Pengajuan)
-- =====================================================
CREATE TABLE request_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    rab_item_id INT NOT NULL,
    quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
    field_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(18,2) GENERATED ALWAYS AS (quantity * field_price) STORED,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (rab_item_id) REFERENCES rab_items(id),
    INDEX idx_request (request_id),
    INDEX idx_rab_item (rab_item_id)
) ENGINE=InnoDB;

-- =====================================================
-- DEFAULT DATA
-- =====================================================

-- Default Admin User (password: admin123)
INSERT INTO users (username, password, full_name, role) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin'),
('field_team', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Tim Lapangan', 'field_team');

-- Sample Regions
INSERT INTO regions (name, description) VALUES 
('Yogyakarta', 'Daerah Istimewa Yogyakarta'),
('Surabaya', 'Jawa Timur'),
('Jakarta', 'DKI Jakarta'),
('Surakarta', 'Jawa Tengah');

-- Sample Items (Labor)
INSERT INTO items (code, name, type, unit) VALUES 
('L.01', 'Pekerja', 'labor', 'OH'),
('L.02', 'Tukang Kayu', 'labor', 'OH'),
('L.03', 'Tukang Batu', 'labor', 'OH'),
('L.04', 'Kepala Tukang', 'labor', 'OH'),
('L.05', 'Mandor', 'labor', 'OH');

-- Sample Items (Material)
INSERT INTO items (code, name, type, unit) VALUES 
('M.01', 'Semen Portland 40kg', 'material', 'Zak'),
('M.02', 'Pasir Pasang', 'material', 'M3'),
('M.03', 'Pasir Beton', 'material', 'M3'),
('M.04', 'Batu Pecah/Split', 'material', 'M3'),
('M.05', 'Batu Kali', 'material', 'M3'),
('M.06', 'Besi Beton Polos', 'material', 'Kg'),
('M.07', 'Besi Beton Ulir', 'material', 'Kg'),
('M.08', 'Kayu Bekisting', 'material', 'M3'),
('M.09', 'Paku', 'material', 'Kg'),
('M.10', 'Kawat Bendrat', 'material', 'Kg');

-- Sample Prices for Yogyakarta
INSERT INTO item_prices (item_id, region_id, quality, price) VALUES 
-- Labor prices
(1, 1, 'top', 90000), (1, 1, 'mid', 85000), (1, 1, 'low', 75000), (1, 1, 'bad', 65000),
(2, 1, 'top', 100000), (2, 1, 'mid', 95000), (2, 1, 'low', 85000), (2, 1, 'bad', 75000),
(3, 1, 'top', 100000), (3, 1, 'mid', 95000), (3, 1, 'low', 85000), (3, 1, 'bad', 75000),
(4, 1, 'top', 110000), (4, 1, 'mid', 102000), (4, 1, 'low', 95000), (4, 1, 'bad', 85000),
(5, 1, 'top', 120000), (5, 1, 'mid', 116600), (5, 1, 'low', 105000), (5, 1, 'bad', 95000),
-- Material prices
(6, 1, 'top', 65000), (6, 1, 'mid', 58000), (6, 1, 'low', 52000), (6, 1, 'bad', 48000),
(7, 1, 'top', 280000), (7, 1, 'mid', 250000), (7, 1, 'low', 220000), (7, 1, 'bad', 200000),
(8, 1, 'top', 320000), (8, 1, 'mid', 300000), (8, 1, 'low', 275000), (8, 1, 'bad', 250000),
(9, 1, 'top', 350000), (9, 1, 'mid', 320000), (9, 1, 'low', 290000), (9, 1, 'bad', 260000),
(10, 1, 'top', 200000), (10, 1, 'mid', 180000), (10, 1, 'low', 160000), (10, 1, 'bad', 140000);
