-- Preserve reservation receipts when a reservation becomes a tenant profile.
USE heart_dorm_db;

ALTER TABLE reservations MODIFY COLUMN status ENUM('Reserved','Cancelled','Converted') NOT NULL DEFAULT 'Reserved';
SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='reservations' AND COLUMN_NAME='tenant_profile_id')=0,
  'ALTER TABLE reservations ADD COLUMN tenant_profile_id INT NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS reservation_additional_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  amount DECIMAL(11,2) NOT NULL,
  bank_reference VARCHAR(100) NOT NULL,
  received_by_name VARCHAR(120) NOT NULL,
  received_by INT NULL,
  received_at DATETIME NOT NULL,
  UNIQUE KEY uq_additional_reservation (reservation_id),
  FOREIGN KEY (reservation_id) REFERENCES reservations(id),
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='reservations' AND COLUMN_NAME='payment_classification')=0,
  'ALTER TABLE reservations ADD COLUMN payment_classification ENUM(''Partial'',''Full'') NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='reservations' AND CONSTRAINT_NAME='fk_reservation_tenant_profile')=0,
  'ALTER TABLE reservations ADD CONSTRAINT fk_reservation_tenant_profile FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
