-- Keep historical accounts while enforcing unique emails for non-deleted users.
USE heart_dorm_db;

SET @schema := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND COLUMN_NAME='current_email')=0,
  'ALTER TABLE users ADD COLUMN current_email VARCHAR(160) GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN LOWER(email) ELSE NULL END) STORED', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND INDEX_NAME='uq_users_current_email')=0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_current_email (current_email)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT GROUP_CONCAT(CONCAT('DROP INDEX `', REPLACE(index_name, '`', '``'), '`') SEPARATOR ', ')
INTO @old_email_indexes
FROM (
  SELECT index_name FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='users' AND NON_UNIQUE=0 AND INDEX_NAME <> 'PRIMARY'
  GROUP BY index_name
  HAVING COUNT(*)=1 AND MAX(COLUMN_NAME)='email'
) AS old_indexes;

SET @sql := IF(@old_email_indexes IS NULL, 'DO 0', CONCAT('ALTER TABLE users ', @old_email_indexes));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
