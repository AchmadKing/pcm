-- Matikan foreign key checks agar tidak memicu error relasi
SET FOREIGN_KEY_CHECKS = 0;

-- Buat stored procedure pembantu untuk menambah kolom jika belum ada
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;
DELIMITER //
CREATE PROCEDURE AddColumnIfNotExists(
    IN tableName VARCHAR(64),
    IN columnName VARCHAR(64),
    IN columnDefinition VARCHAR(255)
)
BEGIN
    DECLARE colExists INT;
    SELECT COUNT(*) INTO colExists
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = tableName
      AND column_name = columnName;
    IF colExists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', tableName, '` ADD COLUMN `', columnName, '` ', columnDefinition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- =====================================================
-- PROSES TABEL: `field_teams`
-- =====================================================
CREATE TABLE IF NOT EXISTS `field_teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `role` varchar(100) DEFAULT NULL COMMENT 'Jabatan/Role di lapangan',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('field_teams', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('field_teams', 'name', 'varchar(100) NOT NULL');
CALL AddColumnIfNotExists('field_teams', 'role', 'varchar(100) DEFAULT NULL COMMENT \'Jabatan/Role di lapangan\'');
CALL AddColumnIfNotExists('field_teams', 'phone', 'varchar(20) DEFAULT NULL');
CALL AddColumnIfNotExists('field_teams', 'email', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('field_teams', 'is_active', 'tinyint(1) DEFAULT 1');
CALL AddColumnIfNotExists('field_teams', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('field_teams', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `projects`
-- =====================================================
CREATE TABLE IF NOT EXISTS `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_code` varchar(50) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `region_name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','on_progress','completed') DEFAULT 'draft',
  `rab_submitted` tinyint(1) NOT NULL DEFAULT 0,
  `rap_submitted` tinyint(1) NOT NULL DEFAULT 0,
  `ppn_percentage` decimal(5,2) NOT NULL DEFAULT 11.00,
  `activity_name` varchar(255) DEFAULT NULL,
  `work_description` text DEFAULT NULL,
  `funding_source` varchar(200) DEFAULT NULL,
  `budget_year` year(4) DEFAULT NULL,
  `contract_number` varchar(100) DEFAULT NULL,
  `contract_date` date DEFAULT NULL,
  `addendum_number` varchar(100) DEFAULT NULL,
  `addendum_date` date DEFAULT NULL,
  `spk_number` varchar(100) DEFAULT NULL,
  `spk_date` date DEFAULT NULL,
  `spmk_number` varchar(100) DEFAULT NULL,
  `spmk_date` date DEFAULT NULL,
  `service_provider` varchar(200) DEFAULT NULL,
  `supervisor_consultant` varchar(200) DEFAULT NULL,
  `duration_days` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `overhead_percentage` decimal(5,2) DEFAULT 10.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `rap_source_id` int(11) DEFAULT 0 COMMENT 'RAP reference source: 0=RAB Asli, >0=snapshot ID',
  `rap_master_data_initialized` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_status` (`status`),
  CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('projects', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('projects', 'project_code', 'varchar(50) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('projects', 'region_name', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'description', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'status', 'enum(\'draft\',\'on_progress\',\'completed\') DEFAULT \'draft\'');
CALL AddColumnIfNotExists('projects', 'rab_submitted', 'tinyint(1) NOT NULL DEFAULT 0');
CALL AddColumnIfNotExists('projects', 'rap_submitted', 'tinyint(1) NOT NULL DEFAULT 0');
CALL AddColumnIfNotExists('projects', 'ppn_percentage', 'decimal(5,2) NOT NULL DEFAULT 11.00');
CALL AddColumnIfNotExists('projects', 'activity_name', 'varchar(255) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'work_description', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'funding_source', 'varchar(200) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'budget_year', 'year(4) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'contract_number', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'contract_date', 'date DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'addendum_number', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'addendum_date', 'date DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'spk_number', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'spk_date', 'date DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'spmk_number', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'spmk_date', 'date DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'service_provider', 'varchar(200) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'supervisor_consultant', 'varchar(200) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'duration_days', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'start_date', 'date DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'overhead_percentage', 'decimal(5,2) DEFAULT 10.00');
CALL AddColumnIfNotExists('projects', 'created_by', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('projects', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('projects', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');
CALL AddColumnIfNotExists('projects', 'rap_source_id', 'int(11) DEFAULT 0 COMMENT \'RAP reference source: 0=RAB Asli, >0=snapshot ID\'');
CALL AddColumnIfNotExists('projects', 'rap_master_data_initialized', 'tinyint(1) DEFAULT 0');

-- =====================================================
-- PROSES TABEL: `project_ahsp`
-- =====================================================
CREATE TABLE IF NOT EXISTS `project_ahsp` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `ahsp_code` varchar(50) DEFAULT NULL,
  `work_name` varchar(200) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `project_ahsp_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('project_ahsp', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('project_ahsp', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp', 'ahsp_code', 'varchar(50) DEFAULT NULL');
CALL AddColumnIfNotExists('project_ahsp', 'work_name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp', 'unit', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp', 'unit_price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('project_ahsp', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('project_ahsp', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `project_ahsp_details`
-- =====================================================
CREATE TABLE IF NOT EXISTS `project_ahsp_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ahsp_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `coefficient` decimal(15,6) NOT NULL DEFAULT 0.000000,
  `unit_price` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ahsp` (`ahsp_id`),
  KEY `idx_item` (`item_id`),
  CONSTRAINT `project_ahsp_details_ibfk_1` FOREIGN KEY (`ahsp_id`) REFERENCES `project_ahsp` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_ahsp_details_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `project_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('project_ahsp_details', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('project_ahsp_details', 'ahsp_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_details', 'item_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_details', 'coefficient', 'decimal(15,6) NOT NULL DEFAULT 0.000000');
CALL AddColumnIfNotExists('project_ahsp_details', 'unit_price', 'decimal(15,2) DEFAULT NULL');
CALL AddColumnIfNotExists('project_ahsp_details', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `project_ahsp_details_rap`
-- =====================================================
CREATE TABLE IF NOT EXISTS `project_ahsp_details_rap` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ahsp_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `coefficient` decimal(15,6) NOT NULL DEFAULT 0.000000,
  `unit_price` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ahsp` (`ahsp_id`),
  KEY `idx_item` (`item_id`),
  CONSTRAINT `project_ahsp_details_rap_ibfk_1` FOREIGN KEY (`ahsp_id`) REFERENCES `project_ahsp_rap` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_ahsp_details_rap_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `project_items_rap` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CALL AddColumnIfNotExists('project_ahsp_details_rap', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('project_ahsp_details_rap', 'ahsp_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_details_rap', 'item_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_details_rap', 'coefficient', 'decimal(15,6) NOT NULL DEFAULT 0.000000');
CALL AddColumnIfNotExists('project_ahsp_details_rap', 'unit_price', 'decimal(15,2) DEFAULT NULL');
CALL AddColumnIfNotExists('project_ahsp_details_rap', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `project_ahsp_rap`
-- =====================================================
CREATE TABLE IF NOT EXISTS `project_ahsp_rap` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `ahsp_code` varchar(50) NOT NULL,
  `work_name` varchar(200) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `rab_ahsp_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `project_ahsp_rap_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CALL AddColumnIfNotExists('project_ahsp_rap', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('project_ahsp_rap', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_rap', 'ahsp_code', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_rap', 'work_name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_rap', 'unit', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('project_ahsp_rap', 'unit_price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('project_ahsp_rap', 'rab_ahsp_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('project_ahsp_rap', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('project_ahsp_rap', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `project_assignments`
-- =====================================================
CREATE TABLE IF NOT EXISTS `project_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL COMMENT 'User ID admin yang menugaskan',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL COMMENT 'Catatan penugasan',
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `project_assignments_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('project_assignments', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('project_assignments', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_assignments', 'user_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_assignments', 'assigned_by', 'int(11) DEFAULT NULL COMMENT \'User ID admin yang menugaskan\'');
CALL AddColumnIfNotExists('project_assignments', 'assigned_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('project_assignments', 'notes', 'text DEFAULT NULL COMMENT \'Catatan penugasan\'');
CALL AddColumnIfNotExists('project_assignments', 'is_active', 'tinyint(1) DEFAULT 1');

-- =====================================================
-- PROSES TABEL: `project_items`
-- =====================================================
CREATE TABLE IF NOT EXISTS `project_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `category` enum('upah','material','alat') NOT NULL,
  `unit` varchar(50) NOT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `actual_price` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_category` (`category`),
  CONSTRAINT `project_items_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('project_items', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('project_items', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_items', 'item_code', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('project_items', 'name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('project_items', 'brand', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('project_items', 'category', 'enum(\'upah\',\'material\',\'alat\') NOT NULL');
CALL AddColumnIfNotExists('project_items', 'unit', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('project_items', 'price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('project_items', 'actual_price', 'decimal(15,2) DEFAULT NULL');
CALL AddColumnIfNotExists('project_items', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `project_items_rap`
-- =====================================================
CREATE TABLE IF NOT EXISTS `project_items_rap` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `category` enum('upah','material','alat') NOT NULL,
  `unit` varchar(50) NOT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `actual_price` decimal(15,2) DEFAULT NULL,
  `rab_item_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `project_items_rap_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CALL AddColumnIfNotExists('project_items_rap', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('project_items_rap', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'item_code', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'brand', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'category', 'enum(\'upah\',\'material\',\'alat\') NOT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'unit', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('project_items_rap', 'actual_price', 'decimal(15,2) DEFAULT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'rab_item_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('project_items_rap', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('project_items_rap', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `rab_categories`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rab_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(200) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `rab_categories_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rab_categories', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rab_categories', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_categories', 'code', 'varchar(10) NOT NULL');
CALL AddColumnIfNotExists('rab_categories', 'name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('rab_categories', 'sort_order', 'int(11) DEFAULT 0');
CALL AddColumnIfNotExists('rab_categories', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `rab_snapshots`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rab_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `overhead_percentage` decimal(5,2) DEFAULT 10.00,
  `ppn_percentage` decimal(5,2) DEFAULT 11.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `rab_snapshots_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rab_snapshots', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rab_snapshots', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshots', 'created_by', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshots', 'name', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshots', 'description', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('rab_snapshots', 'overhead_percentage', 'decimal(5,2) DEFAULT 10.00');
CALL AddColumnIfNotExists('rab_snapshots', 'ppn_percentage', 'decimal(5,2) DEFAULT 11.00');
CALL AddColumnIfNotExists('rab_snapshots', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('rab_snapshots', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `rab_snapshot_ahsp_details`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rab_snapshot_ahsp_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_subcategory_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `category` enum('upah','material','alat') NOT NULL,
  `coefficient` decimal(15,6) NOT NULL DEFAULT 0.000000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(18,2) GENERATED ALWAYS AS (`coefficient` * `unit_price`) STORED,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_snapshot_sub` (`snapshot_subcategory_id`),
  CONSTRAINT `rab_snapshot_ahsp_details_ibfk_1` FOREIGN KEY (`snapshot_subcategory_id`) REFERENCES `rab_snapshot_subcategories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'snapshot_subcategory_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'item_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'category', 'enum(\'upah\',\'material\',\'alat\') NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'coefficient', 'decimal(15,6) NOT NULL DEFAULT 0.000000');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'unit_price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'total_price', 'decimal(18,2) GENERATED ALWAYS AS (`coefficient` * `unit_price`) STORED');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'sort_order', 'int(11) DEFAULT 0');
CALL AddColumnIfNotExists('rab_snapshot_ahsp_details', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `rab_snapshot_categories`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rab_snapshot_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) NOT NULL,
  `original_category_id` int(11) DEFAULT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_snapshot` (`snapshot_id`),
  CONSTRAINT `rab_snapshot_categories_ibfk_1` FOREIGN KEY (`snapshot_id`) REFERENCES `rab_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rab_snapshot_categories', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rab_snapshot_categories', 'snapshot_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_categories', 'original_category_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('rab_snapshot_categories', 'code', 'varchar(10) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_categories', 'name', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_categories', 'sort_order', 'int(11) DEFAULT 0');

-- =====================================================
-- PROSES TABEL: `rab_snapshot_subcategories`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rab_snapshot_subcategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `original_subcategory_id` int(11) DEFAULT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `volume` decimal(15,4) DEFAULT 0.0000,
  `unit_price` decimal(15,2) DEFAULT 0.00,
  `ahsp_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `rab_snapshot_subcategories_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `rab_snapshot_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'category_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'original_subcategory_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'code', 'varchar(20) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'name', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'unit', 'varchar(50) DEFAULT NULL');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'volume', 'decimal(15,4) DEFAULT 0.0000');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'unit_price', 'decimal(15,2) DEFAULT 0.00');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'ahsp_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('rab_snapshot_subcategories', 'sort_order', 'int(11) DEFAULT 0');

-- =====================================================
-- PROSES TABEL: `rab_subcategories`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rab_subcategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `ahsp_id` int(11) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `volume` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `rab_subcategories_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `rab_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rab_subcategories', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rab_subcategories', 'category_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_subcategories', 'ahsp_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rab_subcategories', 'code', 'varchar(20) DEFAULT NULL');
CALL AddColumnIfNotExists('rab_subcategories', 'name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('rab_subcategories', 'unit', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('rab_subcategories', 'volume', 'decimal(15,4) NOT NULL DEFAULT 0.0000');
CALL AddColumnIfNotExists('rab_subcategories', 'unit_price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('rab_subcategories', 'sort_order', 'int(11) DEFAULT 0');
CALL AddColumnIfNotExists('rab_subcategories', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `rap_ahsp_details`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rap_ahsp_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rap_item_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `category` enum('upah','material','alat') NOT NULL,
  `coefficient` decimal(15,6) NOT NULL DEFAULT 0.000000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rap_item` (`rap_item_id`),
  KEY `idx_item` (`item_id`),
  CONSTRAINT `rap_ahsp_details_ibfk_1` FOREIGN KEY (`rap_item_id`) REFERENCES `rap_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rap_ahsp_details', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rap_ahsp_details', 'rap_item_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rap_ahsp_details', 'item_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rap_ahsp_details', 'category', 'enum(\'upah\',\'material\',\'alat\') NOT NULL');
CALL AddColumnIfNotExists('rap_ahsp_details', 'coefficient', 'decimal(15,6) NOT NULL DEFAULT 0.000000');
CALL AddColumnIfNotExists('rap_ahsp_details', 'unit_price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('rap_ahsp_details', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('rap_ahsp_details', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `rap_items`
-- =====================================================
CREATE TABLE IF NOT EXISTS `rap_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subcategory_id` int(11) NOT NULL,
  `volume` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(18,2) GENERATED ALWAYS AS (`volume` * `unit_price`) STORED,
  `notes` text DEFAULT NULL,
  `rab_source_type` enum('rab','snapshot') DEFAULT 'rab',
  `rab_snapshot_id` int(11) DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_subcategory` (`subcategory_id`),
  KEY `fk_rap_snapshot` (`rab_snapshot_id`),
  CONSTRAINT `fk_rap_snapshot` FOREIGN KEY (`rab_snapshot_id`) REFERENCES `rab_snapshots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rap_items_ibfk_1` FOREIGN KEY (`subcategory_id`) REFERENCES `rab_subcategories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('rap_items', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('rap_items', 'subcategory_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('rap_items', 'volume', 'decimal(15,4) NOT NULL DEFAULT 0.0000');
CALL AddColumnIfNotExists('rap_items', 'unit_price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('rap_items', 'total_price', 'decimal(18,2) GENERATED ALWAYS AS (`volume` * `unit_price`) STORED');
CALL AddColumnIfNotExists('rap_items', 'notes', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('rap_items', 'rab_source_type', 'enum(\'rab\',\'snapshot\') DEFAULT \'rab\'');
CALL AddColumnIfNotExists('rap_items', 'rab_snapshot_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('rap_items', 'is_locked', 'tinyint(1) DEFAULT 0');
CALL AddColumnIfNotExists('rap_items', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('rap_items', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `requests`
-- =====================================================
CREATE TABLE IF NOT EXISTS `requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `request_number` varchar(50) DEFAULT NULL,
  `request_date` date NOT NULL,
  `week_number` int(11) DEFAULT 1,
  `description` text DEFAULT NULL,
  `status` enum('pending','pm_approved','approved','rejected') NOT NULL DEFAULT 'pending',
  `total_amount` decimal(18,2) DEFAULT 0.00,
  `approved_amount` decimal(18,2) DEFAULT 0.00,
  `approved_by` int(11) DEFAULT NULL,
  `pm_approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `pm_approved_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `pm_notes` text DEFAULT NULL,
  `target_week` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_project` (`project_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `requests_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('requests', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('requests', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('requests', 'request_number', 'varchar(50) DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'request_date', 'date NOT NULL');
CALL AddColumnIfNotExists('requests', 'week_number', 'int(11) DEFAULT 1');
CALL AddColumnIfNotExists('requests', 'description', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'status', 'enum(\'pending\',\'pm_approved\',\'approved\',\'rejected\') NOT NULL DEFAULT \'pending\'');
CALL AddColumnIfNotExists('requests', 'total_amount', 'decimal(18,2) DEFAULT 0.00');
CALL AddColumnIfNotExists('requests', 'approved_amount', 'decimal(18,2) DEFAULT 0.00');
CALL AddColumnIfNotExists('requests', 'approved_by', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'pm_approved_by', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'approved_at', 'datetime DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'pm_approved_at', 'datetime DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'admin_notes', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'pm_notes', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'target_week', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'rejection_reason', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('requests', 'created_by', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('requests', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('requests', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `request_items`
-- =====================================================
CREATE TABLE IF NOT EXISTS `request_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `subcategory_id` int(11) DEFAULT NULL,
  `subcat_details` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `item_name` varchar(200) NOT NULL,
  `item_code` varchar(50) DEFAULT NULL,
  `item_type` varchar(20) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `coefficient` decimal(15,6) DEFAULT 1.000000,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(18,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subcategory_id` (`subcategory_id`),
  KEY `idx_request` (`request_id`),
  CONSTRAINT `request_items_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_items_ibfk_2` FOREIGN KEY (`subcategory_id`) REFERENCES `rab_subcategories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('request_items', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('request_items', 'request_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('request_items', 'subcategory_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('request_items', 'subcat_details', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('request_items', 'category_id', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('request_items', 'item_name', 'varchar(200) NOT NULL');
CALL AddColumnIfNotExists('request_items', 'item_code', 'varchar(50) DEFAULT NULL');
CALL AddColumnIfNotExists('request_items', 'item_type', 'varchar(20) DEFAULT NULL');
CALL AddColumnIfNotExists('request_items', 'unit', 'varchar(50) DEFAULT NULL');
CALL AddColumnIfNotExists('request_items', 'quantity', 'decimal(15,4) NOT NULL DEFAULT 0.0000');
CALL AddColumnIfNotExists('request_items', 'coefficient', 'decimal(15,6) DEFAULT 1.000000');
CALL AddColumnIfNotExists('request_items', 'unit_price', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('request_items', 'total_price', 'decimal(18,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED');
CALL AddColumnIfNotExists('request_items', 'notes', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('request_items', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `request_attachments`
-- =====================================================
CREATE TABLE IF NOT EXISTS `request_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  CONSTRAINT `request_attachments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('request_attachments', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('request_attachments', 'request_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('request_attachments', 'filename', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('request_attachments', 'original_name', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('request_attachments', 'file_type', 'varchar(50) DEFAULT NULL');
CALL AddColumnIfNotExists('request_attachments', 'file_size', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('request_attachments', 'uploaded_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `request_actuals`
-- =====================================================
CREATE TABLE IF NOT EXISTS `request_actuals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `remaining_upah` decimal(15,2) NOT NULL DEFAULT 0.00,
  `consumed_upah` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remaining_material` decimal(15,2) NOT NULL DEFAULT 0.00,
  `consumed_material` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remaining_alat` decimal(15,2) NOT NULL DEFAULT 0.00,
  `consumed_alat` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes_upah` text DEFAULT NULL,
  `notes_material` text DEFAULT NULL,
  `notes_alat` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `request_actuals_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_actuals_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('request_actuals', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('request_actuals', 'request_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('request_actuals', 'remaining_upah', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('request_actuals', 'consumed_upah', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('request_actuals', 'remaining_material', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('request_actuals', 'consumed_material', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('request_actuals', 'remaining_alat', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('request_actuals', 'consumed_alat', 'decimal(15,2) NOT NULL DEFAULT 0.00');
CALL AddColumnIfNotExists('request_actuals', 'notes_upah', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('request_actuals', 'notes_material', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('request_actuals', 'notes_alat', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('request_actuals', 'created_by', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('request_actuals', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('request_actuals', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `request_actual_attachments`
-- =====================================================
CREATE TABLE IF NOT EXISTS `request_actual_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_actual_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_actual_id` (`request_actual_id`),
  CONSTRAINT `request_actual_attachments_ibfk_1` FOREIGN KEY (`request_actual_id`) REFERENCES `request_actuals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('request_actual_attachments', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('request_actual_attachments', 'request_actual_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('request_actual_attachments', 'filename', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('request_actual_attachments', 'original_name', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('request_actual_attachments', 'file_type', 'varchar(100) DEFAULT NULL');
CALL AddColumnIfNotExists('request_actual_attachments', 'file_size', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('request_actual_attachments', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `roles`
-- =====================================================
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL UNIQUE,
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `list_akses` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('roles', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('roles', 'name', 'varchar(50) NOT NULL UNIQUE');
CALL AddColumnIfNotExists('roles', 'display_name', 'varchar(100) NOT NULL');
CALL AddColumnIfNotExists('roles', 'description', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('roles', 'is_system', 'tinyint(1) DEFAULT 0');
CALL AddColumnIfNotExists('roles', 'list_akses', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('roles', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('roles', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `role_permissions`
-- =====================================================
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_permission` (`role_id`, `permission_key`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('role_permissions', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('role_permissions', 'role_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('role_permissions', 'permission_key', 'varchar(100) NOT NULL');
CALL AddColumnIfNotExists('role_permissions', 'is_allowed', 'tinyint(1) NOT NULL DEFAULT 1');
CALL AddColumnIfNotExists('role_permissions', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');

-- =====================================================
-- PROSES TABEL: `users`
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'field_team',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('users', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('users', 'username', 'varchar(50) NOT NULL');
CALL AddColumnIfNotExists('users', 'password', 'varchar(255) NOT NULL');
CALL AddColumnIfNotExists('users', 'full_name', 'varchar(100) NOT NULL');
CALL AddColumnIfNotExists('users', 'role', 'varchar(50) NOT NULL DEFAULT \'field_team\'');
CALL AddColumnIfNotExists('users', 'is_active', 'tinyint(1) DEFAULT 1');
CALL AddColumnIfNotExists('users', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('users', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- PROSES TABEL: `weekly_progress`
-- =====================================================
CREATE TABLE IF NOT EXISTS `weekly_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `subcategory_id` int(11) NOT NULL,
  `week_number` int(11) NOT NULL,
  `week_start` date DEFAULT NULL,
  `week_end` date DEFAULT NULL,
  `realization_amount` decimal(18,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_subcategory` (`subcategory_id`),
  CONSTRAINT `weekly_progress_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `weekly_progress_ibfk_2` FOREIGN KEY (`subcategory_id`) REFERENCES `rab_subcategories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CALL AddColumnIfNotExists('weekly_progress', 'id', 'int(11) NOT NULL AUTO_INCREMENT');
CALL AddColumnIfNotExists('weekly_progress', 'project_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('weekly_progress', 'subcategory_id', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('weekly_progress', 'week_number', 'int(11) NOT NULL');
CALL AddColumnIfNotExists('weekly_progress', 'week_start', 'date DEFAULT NULL');
CALL AddColumnIfNotExists('weekly_progress', 'week_end', 'date DEFAULT NULL');
CALL AddColumnIfNotExists('weekly_progress', 'realization_amount', 'decimal(18,2) DEFAULT 0.00');
CALL AddColumnIfNotExists('weekly_progress', 'notes', 'text DEFAULT NULL');
CALL AddColumnIfNotExists('weekly_progress', 'created_by', 'int(11) DEFAULT NULL');
CALL AddColumnIfNotExists('weekly_progress', 'created_at', 'timestamp NOT NULL DEFAULT current_timestamp()');
CALL AddColumnIfNotExists('weekly_progress', 'updated_at', 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- =====================================================
-- STEP 3: MENGISI DATA SISTEM DAN PERMISSION (INSERT IGNORE)
-- =====================================================

INSERT IGNORE INTO `roles` (`id`, `name`, `display_name`, `description`, `is_system`, `list_akses`) VALUES
(1, 'super_admin', 'Super Admin', 'Developer/System Owner with unrestricted access', 1, '["projects.view","projects.create","projects.edit","projects.delete","projects.lock_request","rab.view","rab.edit","rap.view","rap.edit","requests.view","requests.create","requests.approve","reports.view","reports.export","master_data.view","master_data.edit","admin.roles","admin.users"]'),
(2, 'admin', 'Administrator', 'Administrator with full management access', 1, '["projects.view","projects.create","projects.edit","projects.delete","projects.lock_request","rab.view","rab.edit","rap.view","rap.edit","requests.view","requests.create","requests.approve","reports.view","reports.export","master_data.view","master_data.edit","admin.roles","admin.users"]'),
(3, 'project_manager', 'Project Manager', 'Project Manager overseeing projects and budget requests', 1, '["projects.view","rab.view","rap.view","rap.edit","requests.view","requests.approve","reports.view","reports.export"]'),
(4, 'field_team', 'Tim Lapangan', 'Field team executing projects and submitting requests', 1, '["projects.view","rab.view","requests.view","requests.create"]');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_key`, `is_allowed`) VALUES
(1, 'projects.view', 1), (1, 'projects.create', 1), (1, 'projects.edit', 1), (1, 'projects.delete', 1), (1, 'projects.lock_request', 1),
(1, 'rab.view', 1), (1, 'rab.edit', 1), (1, 'rap.view', 1), (1, 'rap.edit', 1),
(1, 'requests.view', 1), (1, 'requests.create', 1), (1, 'requests.approve', 1),
(1, 'reports.view', 1), (1, 'reports.export', 1),
(1, 'master_data.view', 1), (1, 'master_data.edit', 1),
(1, 'admin.roles', 1), (1, 'admin.users', 1),
(2, 'projects.view', 1), (2, 'projects.create', 1), (2, 'projects.edit', 1), (2, 'projects.delete', 1), (2, 'projects.lock_request', 1),
(2, 'rab.view', 1), (2, 'rab.edit', 1), (2, 'rap.view', 1), (2, 'rap.edit', 1),
(2, 'requests.view', 1), (2, 'requests.create', 1), (2, 'requests.approve', 1),
(2, 'reports.view', 1), (2, 'reports.export', 1),
(2, 'master_data.view', 1), (2, 'master_data.edit', 1),
(2, 'admin.roles', 1), (2, 'admin.users', 1),
(3, 'projects.view', 1), (3, 'projects.create', 0), (3, 'projects.edit', 0), (3, 'projects.delete', 0), (3, 'projects.lock_request', 0),
(3, 'rab.view', 1), (3, 'rab.edit', 0), (3, 'rap.view', 1), (3, 'rap.edit', 1),
(3, 'requests.view', 1), (3, 'requests.create', 0), (3, 'requests.approve', 1),
(3, 'reports.view', 1), (3, 'reports.export', 1),
(3, 'master_data.view', 0), (3, 'master_data.edit', 0),
(3, 'admin.roles', 0), (3, 'admin.users', 0),
(4, 'projects.view', 1), (4, '4.projects.create', 0), (4, 'projects.edit', 0), (4, 'projects.delete', 0), (4, 'projects.lock_request', 0),
(4, 'rab.view', 1), (4, 'rab.edit', 0), (4, 'rap.view', 0), (4, 'rap.edit', 0),
(4, 'requests.view', 1), (4, 'requests.create', 1), (4, 'requests.approve', 0),
(4, 'reports.view', 0), (4, 'reports.export', 0),
(4, 'master_data.view', 0), (4, 'master_data.edit', 0),
(4, 'admin.roles', 0), (4, 'admin.users', 0);

-- Hapus procedure pembantu setelah digunakan
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;
SET FOREIGN_KEY_CHECKS = 1;
