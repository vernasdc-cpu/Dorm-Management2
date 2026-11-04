-- Migration: link tenant profiles to beds so adding a tenant occupies one bed.
-- Safe to run repeatedly on MySQL 8.
USE heart_dorm_db;
SET @schema := DATABASE();

-- 1) bed_id column
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND COLUMN_NAME='bed_id')=0,
  'ALTER TABLE tenant_profiles ADD COLUMN bed_id INT NULL AFTER room_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) One profile per bed (NULL values are allowed more than once by MySQL).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND INDEX_NAME='uq_tenant_bed')=0,
  'ALTER TABLE tenant_profiles ADD UNIQUE KEY uq_tenant_bed (bed_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Bed foreign key. Deleting a bed clears only the profile link.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='tenant_profiles' AND CONSTRAINT_NAME='fk_tenant_bed')=0,
  'ALTER TABLE tenant_profiles ADD CONSTRAINT fk_tenant_bed FOREIGN KEY (bed_id) REFERENCES beds(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Backfill room links from the unit/room number.
UPDATE tenant_profiles tp
JOIN rooms r ON r.room_number = tp.unit_number
SET tp.room_id = r.id
WHERE tp.room_id IS NULL AND tp.unit_number IS NOT NULL AND TRIM(tp.unit_number) <> '';

-- 5) Make sure each room has beds up to its max occupancy. INSERT IGNORE keeps
--    custom bed labels and never touches occupied beds.
INSERT IGNORE INTO beds (room_id, bed_number, status)
SELECT r.id, CONCAT('B', n.n), 'vacant'
FROM rooms r
JOIN (
  SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
  UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12
) n ON n.n <= r.max_occupants;

-- 6) Assign unlinked tenant profiles to the first vacant beds in their room.
DROP TEMPORARY TABLE IF EXISTS tmp_tenant_bed_assign;
CREATE TEMPORARY TABLE tmp_tenant_bed_assign AS
SELECT p.id AS profile_id, b.id AS bed_id
FROM (
  SELECT id, room_id,
         ROW_NUMBER() OVER (PARTITION BY room_id ORDER BY id) AS rn
  FROM tenant_profiles
  WHERE room_id IS NOT NULL AND bed_id IS NULL
) p
JOIN (
  SELECT id, room_id,
         ROW_NUMBER() OVER (PARTITION BY room_id
                            ORDER BY CAST(SUBSTRING(bed_number,2) AS UNSIGNED), id) AS rn
  FROM beds
  WHERE status='vacant' AND resident_id IS NULL
) b ON b.room_id = p.room_id AND b.rn = p.rn;

UPDATE tenant_profiles tp
JOIN tmp_tenant_bed_assign x ON x.profile_id = tp.id
SET tp.bed_id = x.bed_id;

DROP TEMPORARY TABLE IF EXISTS tmp_tenant_bed_assign;

-- 7) Mark beds occupied when a tenant profile is linked to them.
UPDATE beds b
JOIN tenant_profiles tp ON tp.bed_id = b.id
SET b.status = 'occupied'
WHERE b.resident_id IS NULL;

-- 8) Refresh room statuses from the resulting occupied-bed counts.
UPDATE rooms r
LEFT JOIN (
  SELECT room_id, COUNT(*) AS occ
  FROM beds WHERE status='occupied'
  GROUP BY room_id
) o ON o.room_id = r.id
SET r.status = CASE
  WHEN r.status IN ('Reserved','Under Maintenance','Unavailable') THEN r.status
  WHEN COALESCE(o.occ,0) = 0 THEN 'Available'
  WHEN o.occ >= r.max_occupants THEN 'Fully Occupied'
  ELSE 'Partially Occupied'
END;
