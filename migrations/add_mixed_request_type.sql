-- Migration: Add 'mixed' to requests.request_type ENUM
ALTER TABLE `requests` 
MODIFY COLUMN `request_type` ENUM('rab', 'non_rab', 'mixed') NOT NULL DEFAULT 'rab';
