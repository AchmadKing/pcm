-- =====================================================
-- Migration: Add UNIQUE Constraint on projects.project_code
-- PCC - Project Cost Control System
-- Purpose: Enforce strict database-level uniqueness for project_code
-- =====================================================

-- 1. Convert empty strings to NULL so UNIQUE index ignores empty legacy fields
UPDATE `projects` 
SET `project_code` = NULL 
WHERE `project_code` IS NOT NULL AND TRIM(`project_code`) = '';

-- 2. Resolve existing duplicates deterministically
UPDATE `projects` p
JOIN (
    SELECT id, project_code, 
           ROW_NUMBER() OVER (PARTITION BY project_code ORDER BY id ASC) as rn
    FROM `projects`
    WHERE `project_code` IS NOT NULL AND `project_code` != ''
) dup ON p.id = dup.id
SET p.project_code = CONCAT(dup.project_code, '-', LPAD(dup.rn, 2, '0'))
WHERE dup.rn > 1;

-- 3. Modify column definition to VARCHAR(100) NULL DEFAULT NULL
ALTER TABLE `projects` 
MODIFY COLUMN `project_code` VARCHAR(100) NULL DEFAULT NULL;

-- 4. Add UNIQUE constraint index if it does not already exist
ALTER TABLE `projects` ADD UNIQUE INDEX `uq_project_code` (`project_code`);
