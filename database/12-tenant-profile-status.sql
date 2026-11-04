-- Migration: add an active/inactive status independent of billing status.
-- Safe to run repeatedly on MySQL 8.
USE heart_dorm_db;
SET @schema := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND COLUMN_NAME='profile_status')=0,
  'ALTER TABLE tenant_profiles ADD COLUMN profile_status ENUM(''Active'',''Inactive'') NOT NULL DEFAULT ''Active'' AFTER photo_path',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND INDEX_NAME='idx_tenant_status')=0,
  'ALTER TABLE tenant_profiles ADD INDEX idx_tenant_status (profile_status)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
