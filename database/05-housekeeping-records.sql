-- Migration: housekeeping cleaning records.
-- Creates the table used by the Housekeeping page (safe to run repeatedly).
USE heart_dorm_db;

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
