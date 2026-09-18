-- =====================================================
-- Migration: Add view_mode to role_permissions
-- PCC - Project Cost Control System
-- Purpose: Support 3-level project access (none/assigned/all)
-- =====================================================

-- Add view_mode column to role_permissions table
ALTER TABLE `role_permissions` 
  ADD COLUMN `view_mode` VARCHAR(20) DEFAULT NULL 
  COMMENT 'Only used for projects.view: none, assigned, all';

-- Set default view_mode for existing roles that have projects.view enabled
-- Admin/Super Admin/PM get 'all' (see all projects)
UPDATE `role_permissions` rp
  JOIN `roles` r ON rp.role_id = r.id
  SET rp.view_mode = 'all' 
  WHERE rp.permission_key = 'projects.view' 
    AND rp.is_allowed = 1 
    AND r.name IN ('super_admin', 'admin', 'project_manager');

-- Field team gets 'assigned' (see only assigned projects)
UPDATE `role_permissions` rp
  JOIN `roles` r ON rp.role_id = r.id
  SET rp.view_mode = 'assigned' 
  WHERE rp.permission_key = 'projects.view' 
    AND rp.is_allowed = 1 
    AND r.name = 'field_team';

-- Any other roles with projects.view enabled default to 'assigned' for safety
UPDATE `role_permissions` 
  SET view_mode = 'assigned' 
  WHERE permission_key = 'projects.view' 
    AND is_allowed = 1 
    AND view_mode IS NULL;
