-- Staff-managed reservation payments and their reserved beds.
USE heart_dorm_db;

CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  creation_token CHAR(64) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  bank_reference VARCHAR(100) NOT NULL,
  room_id INT NOT NULL,
  unit_number VARCHAR(40) NOT NULL,
  floor_name VARCHAR(40) NOT NULL,
  bed_id INT NULL,
  bed_number VARCHAR(10) NOT NULL,
  amount DECIMAL(11,2) NOT NULL,
  address VARCHAR(255) NOT NULL,
  received_by_name VARCHAR(120) NOT NULL,
  received_by INT NULL,
  received_at DATETIME NOT NULL,
  status ENUM('Reserved','Cancelled') NOT NULL DEFAULT 'Reserved',
  cancelled_at DATETIME NULL,
  UNIQUE KEY uq_reservation_token (creation_token),
  UNIQUE KEY uq_reservation_bed (bed_id),
  FOREIGN KEY (room_id) REFERENCES rooms(id),
  FOREIGN KEY (bed_id) REFERENCES beds(id),
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);
