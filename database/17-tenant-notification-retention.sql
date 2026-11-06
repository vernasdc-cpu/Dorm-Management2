-- Migration: track when tenant notifications are read for automatic cleanup.
-- Safe to run repeatedly on existing databases.
USE heart_dorm_db;

SET @schema := DATABASE();
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='notifications' AND COLUMN_NAME='read_at')=0,
  'ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL DEFAULT NULL AFTER is_read',
  'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE notifications
SET read_at=created_at
WHERE is_read=1 AND read_at IS NULL;
