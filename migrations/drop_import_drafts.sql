-- Migration: Drop import_drafts table
-- The save draft feature has been removed from the application
-- This table is no longer needed

DROP TABLE IF EXISTS import_drafts;
