USE heart_dorm_db;

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='reservation_additional_payments' AND COLUMN_NAME='is_full_summary')=0,
  'ALTER TABLE reservation_additional_payments ADD COLUMN is_full_summary TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
