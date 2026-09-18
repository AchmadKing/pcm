-- =====================================================
-- Performance Indexes Migration
-- PCC - Fix Cloudflare 524 Timeout
-- Run this SQL on your production database (pcm_db)
-- =====================================================

-- 1. Index on request_items.subcategory_id
-- Used in actual.php for per-subcategory actual spending queries
CREATE INDEX IF NOT EXISTS idx_request_items_subcategory 
ON request_items (subcategory_id);

-- 2. Composite index on project_ahsp_rap (project_id, ahsp_code)
-- Used in getRapAhspComponentBreakdown() for code-based lookups
CREATE INDEX IF NOT EXISTS idx_project_ahsp_rap_code 
ON project_ahsp_rap (project_id, ahsp_code);

-- 3. Index on project_ahsp_details.ahsp_id
-- Used in getAhspComponentBreakdown() for AHSP detail lookups
CREATE INDEX IF NOT EXISTS idx_ahsp_details_ahsp 
ON project_ahsp_details (ahsp_id);

-- 4. Index on project_ahsp_details_rap.ahsp_id
-- Used in getRapAhspComponentBreakdown() for RAP AHSP detail lookups
CREATE INDEX IF NOT EXISTS idx_ahsp_details_rap_ahsp 
ON project_ahsp_details_rap (ahsp_id);

-- 5. Composite index on requests (project_id, status)
-- Used in actual.php and view.php for filtered request queries
CREATE INDEX IF NOT EXISTS idx_requests_project_status 
ON requests (project_id, status);

-- 6. Index on request_items.request_id (if not exists)
-- Used in weekly detail AJAX and actualization queries
CREATE INDEX IF NOT EXISTS idx_request_items_request 
ON request_items (request_id);

-- 7. Index on rab_subcategories.ahsp_id
-- Used in RAB/RAP summary queries joining to AHSP
CREATE INDEX IF NOT EXISTS idx_rab_subcategories_ahsp 
ON rab_subcategories (ahsp_id);

-- 8. Index on rap_items.subcategory_id
-- Used in RAP tab queries
CREATE INDEX IF NOT EXISTS idx_rap_items_subcategory 
ON rap_items (subcategory_id);
