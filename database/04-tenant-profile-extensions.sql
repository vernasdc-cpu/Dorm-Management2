-- Migration: extend tenant profiles with 2x2 photo, floor level and room link.
-- Safe to run repeatedly on MySQL 8: each change is applied only when missing.
USE heart_dorm_db;
SET @schema := DATABASE();

-- floor_level
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND COLUMN_NAME='floor_level')=0,
  'ALTER TABLE tenant_profiles ADD COLUMN floor_level VARCHAR(10) NULL AFTER unit_number', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- room_id
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND COLUMN_NAME='room_id')=0,
  'ALTER TABLE tenant_profiles ADD COLUMN room_id INT NULL AFTER floor_level', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- photo_path
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND COLUMN_NAME='photo_path')=0,
  'ALTER TABLE tenant_profiles ADD COLUMN photo_path VARCHAR(255) NULL AFTER room_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- floor index
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND INDEX_NAME='idx_tenant_floor')=0,
  'ALTER TABLE tenant_profiles ADD INDEX idx_tenant_floor (floor_level)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- room index
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND INDEX_NAME='idx_tenant_room')=0,
  'ALTER TABLE tenant_profiles ADD INDEX idx_tenant_room (room_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- room foreign key
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND CONSTRAINT_NAME='fk_tenant_room')=0,
  'ALTER TABLE tenant_profiles ADD CONSTRAINT fk_tenant_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill room_id for profiles created before this migration.
UPDATE tenant_profiles tp
JOIN rooms r ON r.room_number = tp.unit_number
SET tp.room_id = r.id
WHERE tp.room_id IS NULL AND tp.unit_number IS NOT NULL;

-- Backfill floor_level from the unit number's leading digit (G, 2xx, 3xx, …).
UPDATE tenant_profiles
SET floor_level = CASE UPPER(LEFT(TRIM(unit_number), 1))
  WHEN 'G' THEN 'G' WHEN '2' THEN '2nd' WHEN '3' THEN '3rd'
  WHEN '4' THEN '4th' WHEN '5' THEN '5th' WHEN '6' THEN '6th' END
WHERE (floor_level IS NULL OR floor_level = '')
  AND unit_number IS NOT NULL AND TRIM(unit_number) <> ''
  AND UPPER(LEFT(TRIM(unit_number), 1)) IN ('G','2','3','4','5','6');
