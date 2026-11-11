CREATE TABLE IF NOT EXISTS login_rate_limits (
  rate_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  failed_attempts INT UNSIGNED NOT NULL,
  window_started_at DATETIME NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_login_rate_window (window_started_at)
) ENGINE=InnoDB;
