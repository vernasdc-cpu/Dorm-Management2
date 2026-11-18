-- Preserve existing checklists while preventing new duplicate staff/day entries.
USE heart_dorm_db;

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_other_items' AND COLUMN_NAME='unit_details')=0,
  'ALTER TABLE housekeeping_other_items ADD COLUMN unit_details VARCHAR(255) NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS housekeeping_other_daily_entries (
  staff_id INT NOT NULL,
  checklist_date DATE NOT NULL,
  PRIMARY KEY (staff_id, checklist_date),
  CONSTRAINT fk_hk_other_daily_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO housekeeping_other_daily_entries (staff_id, checklist_date)
SELECT DISTINCT staff_id, checklist_date FROM housekeeping_other_records
ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id);
