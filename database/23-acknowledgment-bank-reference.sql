-- Add an optional bank reference to existing acknowledgment receipts.
USE heart_dorm_db;

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_acknowledgment_receipts' AND COLUMN_NAME='bank_reference')=0,
  'ALTER TABLE tenant_acknowledgment_receipts ADD COLUMN bank_reference VARCHAR(100) NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
