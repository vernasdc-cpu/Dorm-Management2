USE heart_dorm_db;

CREATE TABLE IF NOT EXISTS approved_billing_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT NOT NULL,
  user_id INT NULL,
  bill_id INT NULL,
  payment_id INT NULL,
  invoice_path VARCHAR(255) NOT NULL,
  proof_path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_approved_billing_submission (submission_id),
  UNIQUE KEY uq_approved_billing_payment (payment_id),
  FOREIGN KEY (submission_id) REFERENCES tenant_billing_payments(id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
);
