-- Heart Ladies Dormitory Management System — schema (MySQL 8 / MariaDB)
CREATE DATABASE IF NOT EXISTS heart_dorm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE heart_dorm_db;

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) UNIQUE NOT NULL,
  name VARCHAR(80) NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin_staff','housekeeping','resident') NOT NULL DEFAULT 'resident',
  phone VARCHAR(40) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_role (role), INDEX idx_status (status)
);

CREATE TABLE IF NOT EXISTS floors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS room_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) UNIQUE NOT NULL,
  description VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(20) UNIQUE NOT NULL,
  floor_id INT NOT NULL,
  room_type_id INT NOT NULL,
  aircon ENUM('Aircon','Non-Aircon') NOT NULL DEFAULT 'Non-Aircon',
  max_occupants INT NOT NULL DEFAULT 1,
  monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('Available','Partially Occupied','Fully Occupied','Reserved','Under Maintenance','Unavailable') NOT NULL DEFAULT 'Available',
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (floor_id) REFERENCES floors(id),
  FOREIGN KEY (room_type_id) REFERENCES room_types(id),
  INDEX idx_room_status (status)
);

CREATE TABLE IF NOT EXISTS residents (
  residents_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  room_id INT NULL,
  bed_no VARCHAR(10) NULL,
  date_of_birth DATE NULL,
  address VARCHAR(255) NULL,
  emergency_contact VARCHAR(120) NULL,
  emergency_phone VARCHAR(40) NULL,
  id_type VARCHAR(60) NULL,
  id_number VARCHAR(80) NULL,
  move_in_date DATE NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS beds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  bed_number VARCHAR(10) NOT NULL,
  status ENUM('vacant','occupied','reserved','maintenance') NOT NULL DEFAULT 'vacant',
  resident_id INT NULL,
  UNIQUE KEY uq_room_bed (room_id, bed_number),
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS room_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  room_id INT NOT NULL,
  bed_no VARCHAR(10) NULL,
  allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  vacated_at TIMESTAMP NULL DEFAULT NULL,
  status ENUM('active','transferred','vacated','reserved') NOT NULL DEFAULT 'active',
  allocated_by INT NULL,
  remarks VARCHAR(255) NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id),
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_number VARCHAR(40) UNIQUE NOT NULL,
  resident_id INT NULL,
  -- Tenant-profile contracts are auto-created from Tenant Profiles.
  -- The FK to tenant_profiles is added by database/07-tenant-contracts.sql
  -- (the table is defined later in this file, so it cannot be referenced here).
  tenant_profile_id INT NULL,
  room_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('Draft','Pending','Active','Expiring Soon','Expired','Terminated') NOT NULL DEFAULT 'Draft',
  terms TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id),
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_contract_tenant_profile (tenant_profile_id),
  INDEX idx_contract_status (status), INDEX idx_contract_end (end_date)
);

CREATE TABLE IF NOT EXISTS contract_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contract_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_type VARCHAR(20) NULL,
  file_size INT NULL,
  uploaded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS inventory_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) UNIQUE NOT NULL,
  description VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS inventory_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  category_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  available_qty INT NOT NULL DEFAULT 0,
  assigned_qty INT NOT NULL DEFAULT 0,
  damaged_qty INT NOT NULL DEFAULT 0,
  min_stock INT NOT NULL DEFAULT 5,
  location VARCHAR(120) NULL,
  condition_status VARCHAR(40) DEFAULT 'Good',
  date_purchased DATE NULL,
  supplier VARCHAR(120) NULL,
  cost DECIMAL(10,2) DEFAULT 0,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES inventory_categories(id),
  INDEX idx_inv_cat (category_id)
);

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  type ENUM('Stock In','Stock Out','Assigned','Returned','Damaged','Lost','Disposed') NOT NULL,
  quantity INT NOT NULL,
  performed_by INT NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS repair_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id VARCHAR(30) UNIQUE NOT NULL,
  resident_id INT NOT NULL,
  room_id INT NOT NULL,
  category VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  priority ENUM('Low','Medium','High','Emergency') NOT NULL DEFAULT 'Medium',
  photo_path VARCHAR(255) NULL,
  assigned_staff_id INT NULL,
  status ENUM('Reported','Pending','Assigned','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Reported',
  date_reported TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  date_resolved TIMESTAMP NULL DEFAULT NULL,
  resolution_notes TEXT NULL,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id),
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_repair_status (status), INDEX idx_repair_priority (priority)
);

CREATE TABLE IF NOT EXISTS repair_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repair_id INT NOT NULL,
  updated_by INT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repair_id) REFERENCES repair_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS bills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id INT NOT NULL,
  room_id INT NOT NULL,
  billing_period VARCHAR(7) NOT NULL,
  room_rental DECIMAL(10,2) NOT NULL DEFAULT 0,
  utilities DECIMAL(10,2) NOT NULL DEFAULT 0,
  additional_charges DECIMAL(10,2) NOT NULL DEFAULT 0,
  discounts DECIMAL(10,2) NOT NULL DEFAULT 0,
  penalties DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  balance DECIMAL(10,2) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  payment_status ENUM('Unpaid','Partially Paid','Paid','Overdue') NOT NULL DEFAULT 'Unpaid',
  invoice_number VARCHAR(40) UNIQUE NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id),
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_bill (resident_id, billing_period),
  INDEX idx_bill_status (payment_status)
);

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  resident_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(40) DEFAULT 'Cash',
  payment_date DATE NOT NULL,
  reference_no VARCHAR(60) NULL,
  receipt_number VARCHAR(40) UNIQUE NULL,
  received_by INT NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
  FOREIGN KEY (resident_id) REFERENCES residents(residents_id),
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  sender_id INT NULL,
  recipient_id INT NULL,
  recipient_role VARCHAR(30) NULL,
  priority ENUM('Normal','Important','Urgent') NOT NULL DEFAULT 'Normal',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notif_recipient (recipient_id, is_read)
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(255) NOT NULL,
  module VARCHAR(80) NOT NULL,
  record_id VARCHAR(60) NULL,
  ip_address VARCHAR(60) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_module (module)
);

CREATE TABLE IF NOT EXISTS system_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(80) UNIQUE NOT NULL,
  setting_value TEXT NULL
);

-- Tenant profile registry: separate from staff accounts and from the resident portal.
CREATE TABLE IF NOT EXISTS tenant_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_type ENUM('Tenant/Resident','Tenant/Commercial') NOT NULL DEFAULT 'Tenant/Resident',
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  contract_start DATE NULL,
  contract_end DATE NULL,
  unit_number VARCHAR(40) NULL,
  floor_level VARCHAR(10) NULL,
  room_id INT NULL,
  bed_id INT NULL,
  photo_path VARCHAR(255) NULL,
  profile_status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (bed_id) REFERENCES beds(id) ON DELETE SET NULL,
  UNIQUE KEY uq_tenant_bed (bed_id),
  INDEX idx_tenant_type (tenant_type),
  INDEX idx_tenant_status (profile_status),
  INDEX idx_tenant_name (full_name),
  INDEX idx_tenant_floor (floor_level),
  INDEX idx_tenant_room (room_id)
);

-- Housekeeping cleaning records: housekeepers log unit cleaning status per date.
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
  CONSTRAINT fk_hk_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_hk_verified_by FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_hk_date (cleaning_date),
  INDEX idx_hk_status (status),
  INDEX idx_hk_verification (verification_status),
  INDEX idx_hk_room (room_id)
);

CREATE TABLE IF NOT EXISTS housekeeping_other_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id INT NOT NULL,
  checklist_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hk_other_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_hk_other_date (checklist_date)
);

CREATE TABLE IF NOT EXISTS housekeeping_other_items (
  record_id INT NOT NULL,
  item_key VARCHAR(40) NOT NULL,
  PRIMARY KEY (record_id, item_key),
  CONSTRAINT fk_hk_other_item_record FOREIGN KEY (record_id) REFERENCES housekeeping_other_records(id) ON DELETE CASCADE
);
