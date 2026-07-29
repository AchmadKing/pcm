-- Migration: Add rab_head_subs table and head_sub_id to rab_categories

CREATE TABLE IF NOT EXISTS `rab_head_subs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `rab_head_subs_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add head_sub_id column to rab_categories if it doesn't exist
SET @dbname = DATABASE();
SET @tablename = "rab_categories";
SET @columnname = "head_sub_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE rab_categories ADD COLUMN head_sub_id INT(11) DEFAULT NULL AFTER project_id, ADD CONSTRAINT fk_rab_categories_head_sub FOREIGN KEY (head_sub_id) REFERENCES rab_head_subs(id) ON DELETE SET NULL;"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
