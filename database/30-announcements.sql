-- Ensure announcements are available on fresh and upgraded installations.
USE heart_dorm_db;

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

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='announcements' AND COLUMN_NAME='target_floor')=0,
  'ALTER TABLE announcements ADD COLUMN target_floor VARCHAR(10) NULL AFTER audience', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='announcements' AND COLUMN_NAME='audience' AND COLUMN_TYPE='enum(''all'',''residents'',''specific'')')=1,
  'ALTER TABLE announcements MODIFY audience ENUM(''all'',''residents'',''floor'',''specific'') NOT NULL DEFAULT ''all''', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS announcement_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ann_read (announcement_id, user_id),
  FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
