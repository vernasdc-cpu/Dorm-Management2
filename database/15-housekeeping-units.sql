-- Store housekeeping records against a unit instead of an individual room.
USE heart_dorm_db;

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND COLUMN_NAME='unit_id')=0,
  'ALTER TABLE housekeeping_records ADD COLUMN unit_id INT NULL AFTER room_id', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND INDEX_NAME='idx_hk_unit')=0,
  'ALTER TABLE housekeeping_records ADD INDEX idx_hk_unit (unit_id)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND CONSTRAINT_NAME='fk_hk_unit')=0,
  'ALTER TABLE housekeeping_records ADD CONSTRAINT fk_hk_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE housekeeping_records h
JOIN rooms r ON r.id=h.room_id
JOIN units u ON u.id=r.unit_id
SET h.unit_id=u.id, h.unit_number=u.unit_number
WHERE h.unit_id IS NULL;
