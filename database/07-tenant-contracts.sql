-- Migration: tenant-profile contracts.
-- Tenant profiles with contract dates are linked to the Contracts module so
-- they appear in Contracts and Contract Monitoring without a portal account.
-- Safe to run repeatedly on MySQL 8.
USE heart_dorm_db;
SET @schema := DATABASE();

-- Existing resident contracts keep working; resident_id becomes nullable so a
-- contract can belong to a tenant profile instead.
ALTER TABLE contracts MODIFY resident_id INT NULL;

-- tenant_profile_id column
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='contracts' AND COLUMN_NAME='tenant_profile_id')=0,
  'ALTER TABLE contracts ADD COLUMN tenant_profile_id INT NULL AFTER resident_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One contract per tenant profile.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='contracts' AND INDEX_NAME='uq_contract_tenant_profile')=0,
  'ALTER TABLE contracts ADD UNIQUE KEY uq_contract_tenant_profile (tenant_profile_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Deleting a tenant profile removes its auto-created contract (and its documents).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA=@schema AND TABLE_NAME='contracts' AND CONSTRAINT_NAME='fk_contract_tenant_profile')=0,
  'ALTER TABLE contracts ADD CONSTRAINT fk_contract_tenant_profile FOREIGN KEY (tenant_profile_id) REFERENCES tenant_profiles(id) ON DELETE CASCADE', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill contracts for existing tenant profiles that already have dates.
INSERT IGNORE INTO contracts
  (contract_number, tenant_profile_id, room_id, start_date, end_date, monthly_rate, status, terms, created_by)
SELECT CONCAT('CTR-T', tp.id), tp.id, tp.room_id, tp.contract_start, tp.contract_end,
       COALESCE(rm.monthly_rate, 0), 'Active',
       'Created automatically from tenant profile.', tp.created_by
FROM tenant_profiles tp
LEFT JOIN rooms rm ON rm.id = tp.room_id
WHERE tp.room_id IS NOT NULL
  AND tp.contract_start IS NOT NULL
  AND tp.contract_end IS NOT NULL
  AND tp.contract_start <= tp.contract_end
  AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.tenant_profile_id = tp.id);
