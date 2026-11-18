USE heart_dorm_db;

CREATE TABLE IF NOT EXISTS tenant_file_folders (
  tenant_profile_id INT PRIMARY KEY,
  folder_name VARCHAR(120) NOT NULL UNIQUE,
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tenant_file_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_profile_id INT NOT NULL,
  category VARCHAR(40) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tenant_file_profile (tenant_profile_id)
);
