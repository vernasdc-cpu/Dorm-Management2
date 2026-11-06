-- Migration: tenant reports and general maintenance requests.
-- Safe to run repeatedly on existing databases.
USE heart_dorm_db;

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
