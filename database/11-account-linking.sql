-- Migration: Account linking between users, tenant_profiles, residents
USE heart_dorm_db;

SET @schema := DATABASE();

-- Ensure columns exist
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND COLUMN_NAME='tenant_profile_id')=0,
  'ALTER TABLE users ADD COLUMN tenant_profile_id INT NULL AFTER phone', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND COLUMN_NAME='resident_link_id')=0,
  'ALTER TABLE users ADD COLUMN resident_link_id INT NULL AFTER tenant_profile_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_user_tenant_profile')=0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_tenant_profile FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_user_resident')=0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_resident FOREIGN KEY (resident_link_id) REFERENCES residents(residents_id) ON DELETE SET NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Auto-link by email
UPDATE users u
JOIN residents r ON LOWER(r.email)=LOWER(u.email)
SET u.resident_link_id = r.residents_id
WHERE u.resident_link_id IS NULL AND u.role='resident';

UPDATE users u
JOIN residents r ON r.user_id = u.id
SET u.resident_link_id = r.residents_id
WHERE u.resident_link_id IS NULL;

UPDATE users u
JOIN tenant_profiles tp ON LOWER(tp.email)=LOWER(u.email)
SET u.tenant_profile_id = tp.id
WHERE u.tenant_profile_id IS NULL;

-- Create resident records for linked users if missing
INSERT IGNORE INTO residents (user_id, room_id, status)
SELECT u.id, tp.room_id, 'active'
FROM users u
JOIN tenant_profiles tp ON tp.id = u.tenant_profile_id
WHERE u.role='resident' AND NOT EXISTS (SELECT 1 FROM residents r WHERE r.user_id = u.id);

UPDATE residents r
JOIN users u ON u.id = r.user_id
SET r.residents_id = COALESCE(u.resident_link_id, r.residents_id)
WHERE u.resident_link_id IS NOT NULL AND r.residents_id != u.resident_link_id;

-- Ensure users.resident_link_id is set
UPDATE residents r
JOIN users u ON u.id = r.user_id
SET u.resident_link_id = r.residents_id
WHERE u.resident_link_id IS NULL;
