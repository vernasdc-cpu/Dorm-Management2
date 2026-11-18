-- Receipts are snapshots of confirmed move-in payments, separate from billing.
USE heart_dorm_db;

CREATE TABLE IF NOT EXISTS tenant_acknowledgment_receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_profile_id INT NOT NULL,
  creation_token CHAR(64) NOT NULL,
  tenant_name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NOT NULL,
  unit_number VARCHAR(40) NOT NULL,
  monthly_rate DECIMAL(10,2) NOT NULL,
  total_amount DECIMAL(11,2) NOT NULL,
  received_by_name VARCHAR(120) NOT NULL,
  received_by INT NULL,
  received_at DATETIME NOT NULL,
  UNIQUE KEY uq_acknowledgment_profile (tenant_profile_id),
  UNIQUE KEY uq_acknowledgment_token (creation_token),
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE CASCADE,
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);
