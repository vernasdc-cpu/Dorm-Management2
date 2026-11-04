-- Migration: Tenant portal, billing corrections, violations, reports, announcements, payment verification
USE heart_dorm_db;

-- Link users to tenant profiles
SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND COLUMN_NAME='tenant_profile_id')=0,
  'ALTER TABLE users ADD COLUMN tenant_profile_id INT NULL AFTER phone', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Link users to residents
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND COLUMN_NAME='resident_link_id')=0,
  'ALTER TABLE users ADD COLUMN resident_link_id INT NULL AFTER tenant_profile_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add foreign keys if they don't exist
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_user_tenant_profile')=0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_tenant_profile FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_user_resident')=0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_resident FOREIGN KEY (resident_link_id) REFERENCES residents(residents_id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Billing corrections
CREATE TABLE IF NOT EXISTS billing_corrections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  tenant_user_id INT NULL,
  tenant_profile_id INT NULL,
  resident_id INT NULL,
  disputed_field VARCHAR(100) NOT NULL,
  current_value VARCHAR(255) NULL,
  requested_value VARCHAR(255) NOT NULL,
  explanation TEXT NOT NULL,
  attachment_path VARCHAR(255) NULL,
  status ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
  reviewed_by INT NULL,
  reviewed_at TIMESTAMP NULL,
  review_notes TEXT NULL,
  original_amount DECIMAL(10,2) NULL,
  corrected_amount DECIMAL(10,2) NULL,
  applied_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id) ON DELETE SET NULL,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_bcorr_status (status)
);

-- Violations
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

-- Tenant reports/maintenance
CREATE TABLE IF NOT EXISTS tenant_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_ref VARCHAR(40) UNIQUE NOT NULL,
  user_id INT NOT NULL,
  tenant_profile_id INT NULL,
  resident_id INT NULL,
  room_id INT NULL,
  category VARCHAR(80) NOT NULL,
  subject VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('Submitted','In Progress','Resolved','Rejected') NOT NULL DEFAULT 'Submitted',
  assigned_staff_id INT NULL,
  staff_response TEXT NULL,
  attachment_path VARCHAR(255) NULL,
  resolution_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id) ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_trep_user (user_id),
  INDEX idx_trep_status (status)
);

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  author_id INT NULL,
  audience ENUM('all','residents','floor','specific') NOT NULL DEFAULT 'all',
  target_floor VARCHAR(10) NULL,
  target_roles TEXT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  important_deadline DATE NULL,
  attachment_path VARCHAR(255) NULL,
  published_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ann_pub (is_published)
);

CREATE TABLE IF NOT EXISTS announcement_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ann_read (announcement_id, user_id),
  FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Payment verification enhancements
ALTER TABLE payments ADD COLUMN IF NOT EXISTS verification_status ENUM('Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Verified';
ALTER TABLE payments ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS verified_by INT NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS submitted_by_user_id INT NULL;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='payments' AND CONSTRAINT_NAME='fk_pay_verified_by2')=0,
  'ALTER TABLE payments ADD CONSTRAINT fk_pay_verified_by2 FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='payments' AND CONSTRAINT_NAME='fk_pay_submitted_by2')=0,
  'ALTER TABLE payments ADD CONSTRAINT fk_pay_submitted_by2 FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Tenant applications (for new tenants)
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
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (intended_room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_app_status (application_status)
);

-- Link to bills
ALTER TABLE bills ADD COLUMN IF NOT EXISTS application_id INT NULL;
ALTER TABLE bills ADD COLUMN IF NOT EXISTS bill_type ENUM('Monthly','Initial','Other') NOT NULL DEFAULT 'Monthly';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='bills' AND CONSTRAINT_NAME='fk_bill_app')=0,
  'ALTER TABLE bills ADD CONSTRAINT fk_bill_app FOREIGN KEY (application_id) REFERENCES tenant_applications(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
