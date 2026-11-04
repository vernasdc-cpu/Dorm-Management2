-- Add units and associate rooms with a unit on the same floor.
USE heart_dorm_db;

CREATE TABLE IF NOT EXISTS units (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_number VARCHAR(20) NOT NULL,
  floor_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_unit_number_floor (unit_number, floor_id),
  FOREIGN KEY (floor_id) REFERENCES floors(id)
);

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='rooms' AND COLUMN_NAME='unit_id')=0,
  'ALTER TABLE rooms ADD COLUMN unit_id INT NULL AFTER room_number', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='rooms' AND INDEX_NAME='idx_rooms_unit')=0,
  'ALTER TABLE rooms ADD INDEX idx_rooms_unit (unit_id)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='rooms' AND CONSTRAINT_NAME='fk_rooms_unit')=0,
  'ALTER TABLE rooms ADD CONSTRAINT fk_rooms_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO units (unit_number, floor_id)
SELECT DISTINCT
  CASE WHEN LOCATE('-', room_number) > 0 THEN SUBSTRING_INDEX(room_number, '-', 1) ELSE room_number END,
  floor_id
FROM rooms
ON DUPLICATE KEY UPDATE unit_number=VALUES(unit_number);

UPDATE rooms r
JOIN units u ON u.floor_id=r.floor_id
  AND u.unit_number=CASE WHEN LOCATE('-', r.room_number) > 0 THEN SUBSTRING_INDEX(r.room_number, '-', 1) ELSE r.room_number END
SET r.unit_id=u.id
WHERE r.unit_id IS NULL;
