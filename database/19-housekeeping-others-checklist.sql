-- Store dated Others checklists and their selected locations/tasks.
USE heart_dorm_db;

CREATE TABLE IF NOT EXISTS housekeeping_other_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_id INT NOT NULL,
  checklist_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hk_other_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_hk_other_date (checklist_date)
);

CREATE TABLE IF NOT EXISTS housekeeping_other_items (
  record_id INT NOT NULL,
  item_key VARCHAR(40) NOT NULL,
  PRIMARY KEY (record_id, item_key),
  CONSTRAINT fk_hk_other_item_record FOREIGN KEY (record_id) REFERENCES housekeeping_other_records(id) ON DELETE CASCADE
);
