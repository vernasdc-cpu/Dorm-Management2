-- Migration: Enhanced billing and tenant account management
USE heart_dorm_db;

-- Add status values to users for better account management
ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','Pending Activation','Suspended','Disabled') NOT NULL DEFAULT 'active';

-- Add tenant relationship fields
ALTER TABLE users ADD COLUMN IF NOT EXISTS tenant_profile_id INT NULL;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_user_tenant_profile (tenant_profile_id);

-- Pending tenant applications
CREATE TABLE IF NOT EXISTS tenant_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  tenant_type ENUM('Tenant/Resident','Tenant/Commercial') NOT NULL DEFAULT 'Tenant/Resident',
  intended_unit_number VARCHAR(40) NULL,
  intended_room_id INT NULL,
  application_status ENUM('Pending','Approved','Rejected','Activated') NOT NULL DEFAULT 'Pending',
  submitted_by INT NULL,
  notes TEXT NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (intended_room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_app_status (application_status)
);

-- Link tenant applications to bills
ALTER TABLE bills ADD COLUMN IF NOT EXISTS application_id INT NULL;
ALTER TABLE bills ADD COLUMN IF NOT EXISTS bill_type ENUM('Monthly','Initial','Other') NOT NULL DEFAULT 'Monthly';

-- Billing line items
CREATE TABLE IF NOT EXISTS billing_line_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  line_type ENUM('room_rental','electricity','water','other','penalty','fee') NOT NULL DEFAULT 'other',
  violation_id INT NULL,
  reference VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  INDEX idx_bli_bill (bill_id)
);

-- Enhanced payments table
ALTER TABLE payments ADD COLUMN IF NOT EXISTS transaction_reference VARCHAR(100) NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS verification_status ENUM('Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Pending Verification';
ALTER TABLE payments ADD COLUMN IF NOT EXISTS verified_by INT NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS submitted_by_user_id INT NULL;

ALTER TABLE payments ADD FOREIGN KEY IF NOT EXISTS fk_pay_verified_by (verified_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE payments ADD FOREIGN KEY IF NOT EXISTS fk_pay_submitted_by (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_pay_verification (verification_status);

-- Billing correction requests
CREATE TABLE IF NOT EXISTS billing_correction_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  tenant_profile_id INT NULL,
  resident_id INT NULL,
  user_id INT NULL,
  field_or_charge VARCHAR(100) NOT NULL,
  current_value VARCHAR(255) NULL,
  requested_corrected_value VARCHAR(255) NOT NULL,
  explanation TEXT NOT NULL,
  supporting_attachment VARCHAR(255) NULL,
  status ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
  reviewed_by INT NULL,
  reviewed_at TIMESTAMP NULL,
  review_explanation TEXT NULL,
  original_value DECIMAL(10,2) NULL,
  corrected_value DECIMAL(10,2) NULL,
  applied_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_bcr_status (status)
);

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  author_id INT NULL,
  audience ENUM('all','residents','specific') NOT NULL DEFAULT 'all',
  target_roles TEXT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  important_deadline DATE NULL,
  attachment_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_at TIMESTAMP NULL,
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ann_published (is_published)
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

-- Violations
CREATE TABLE IF NOT EXISTS violations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  violation_ref VARCHAR(40) UNIQUE NOT NULL,
  tenant_profile_id INT NULL,
  resident_id INT NULL,
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
  tenant_notes TEXT NULL,
  appeal_status ENUM('None','Submitted','Approved','Rejected') NOT NULL DEFAULT 'None',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id) ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_viol_tenant (tenant_profile_id)
);

-- Reports/Maintenance requests (separate from repair_requests, more flexible)
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
