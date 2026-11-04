-- Migration: ensure tenant applications are available to billing and the tenant portal.
USE heart_dorm_db;

-- Keep tenant account-link columns available on databases upgraded through this migration.
SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND COLUMN_NAME='tenant_profile_id')=0,
  'ALTER TABLE users ADD COLUMN tenant_profile_id INT NULL AFTER phone', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND COLUMN_NAME='resident_link_id')=0,
  'ALTER TABLE users ADD COLUMN resident_link_id INT NULL AFTER tenant_profile_id', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_user_tenant_profile')=0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_tenant_profile FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_user_resident')=0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_resident FOREIGN KEY (resident_link_id) REFERENCES residents(residents_id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='audit_logs' AND COLUMN_NAME='deleted_at')=0,
  'ALTER TABLE audit_logs ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER created_at', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='audit_logs' AND INDEX_NAME='idx_audit_deleted_at')=0,
  'ALTER TABLE audit_logs ADD INDEX idx_audit_deleted_at (deleted_at)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS audit_log_deletions (
  audit_log_id INT PRIMARY KEY,
  deleted_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS housekeeping_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id INT NOT NULL,
  cleaning_date DATE NOT NULL,
  room_id INT NULL,
  unit_number VARCHAR(40) NOT NULL,
  status ENUM('Clean','Undone','Unclean') NOT NULL DEFAULT 'Undone',
  remarks VARCHAR(255) NULL,
  supervisor_id INT NULL,
  verification_status ENUM('Verified','Awaiting check') NOT NULL DEFAULT 'Awaiting check',
  verified_by INT NULL,
  verified_at TIMESTAMP NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_hk_date (cleaning_date),
  INDEX idx_hk_status (status),
  INDEX idx_hk_verification (verification_status),
  INDEX idx_hk_room (room_id)
);

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND COLUMN_NAME='supervisor_id')=0,
  'ALTER TABLE housekeeping_records ADD COLUMN supervisor_id INT NULL AFTER remarks', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND COLUMN_NAME='verification_status')=0,
  'ALTER TABLE housekeeping_records ADD COLUMN verification_status ENUM(\'Verified\',\'Awaiting check\') NOT NULL DEFAULT \'Awaiting check\' AFTER supervisor_id', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND COLUMN_NAME='verified_by')=0,
  'ALTER TABLE housekeeping_records ADD COLUMN verified_by INT NULL AFTER verification_status', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND COLUMN_NAME='verified_at')=0,
  'ALTER TABLE housekeeping_records ADD COLUMN verified_at TIMESTAMP NULL AFTER verified_by', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE housekeeping_records MODIFY status ENUM('Clean','Undone','Unclean') NOT NULL DEFAULT 'Undone';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND COLUMN_NAME='supervisor_id' AND REFERENCED_TABLE_NAME='users')=0,
  'ALTER TABLE housekeeping_records ADD CONSTRAINT fk_hk_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND COLUMN_NAME='verified_by' AND REFERENCED_TABLE_NAME='users')=0,
  'ALTER TABLE housekeeping_records ADD CONSTRAINT fk_hk_verified_by FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='housekeeping_records' AND INDEX_NAME='idx_hk_verification')=0,
  'ALTER TABLE housekeeping_records ADD INDEX idx_hk_verification (verification_status)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS violations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  violation_ref VARCHAR(40) UNIQUE NOT NULL,
  tenant_profile_id INT NULL,
  resident_id INT NULL,
  user_id INT NULL,
  room_id INT NULL,
  violation_date DATE NOT NULL,
  category VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('Reported','Reviewed','Resolved','Dismissed') NOT NULL DEFAULT 'Reported',
  penalty_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  billed TINYINT(1) NOT NULL DEFAULT 0,
  bill_id INT NULL,
  recorded_by INT NULL,
  staff_notes TEXT NULL,
  tenant_visible_notes TEXT NULL,
  appeal_status ENUM('None','Submitted','Approved','Rejected') NOT NULL DEFAULT 'None',
  appeal_text TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_viol_tenant (tenant_profile_id),
  INDEX idx_viol_user (user_id)
);

CREATE TABLE IF NOT EXISTS tenant_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  tenant_type ENUM('Tenant/Resident','Tenant/Commercial') NOT NULL DEFAULT 'Tenant/Resident',
  intended_room_id INT NULL,
  intended_unit_number VARCHAR(40) NULL,
  application_status ENUM('Pending','Approved','Rejected','Activated') NOT NULL DEFAULT 'Pending',
  submitted_by INT NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  activated_at TIMESTAMP NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (intended_room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_app_status (application_status)
);

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='tenant_applications' AND COLUMN_NAME='activated_at')=0,
  'ALTER TABLE tenant_applications ADD COLUMN activated_at TIMESTAMP NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='bills' AND COLUMN_NAME='application_id')=0,
  'ALTER TABLE bills ADD COLUMN application_id INT NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='bills' AND COLUMN_NAME='bill_type')=0,
  'ALTER TABLE bills ADD COLUMN bill_type ENUM(\'Monthly\',\'Initial\',\'Other\') NOT NULL DEFAULT \'Monthly\'', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
