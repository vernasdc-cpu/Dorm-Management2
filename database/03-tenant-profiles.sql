-- Migration: Tenant profile registry (Tenant/Resident & Tenant/Commercial).
-- Safe to run on an existing database: uses CREATE TABLE IF NOT EXISTS.
USE heart_dorm_db;

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
  payment_status ENUM('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  FOREIGN KEY (bed_id) REFERENCES beds(id) ON DELETE SET NULL,
  UNIQUE KEY uq_tenant_bed (bed_id),
  INDEX idx_tenant_type (tenant_type),
  INDEX idx_tenant_status (profile_status),
  INDEX idx_tenant_payment (payment_status),
  INDEX idx_tenant_name (full_name),
  INDEX idx_tenant_floor (floor_level),
  INDEX idx_tenant_room (room_id)
);
