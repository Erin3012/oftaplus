-- Migración aditiva 2026-10-15: reportes y sugerencias con adjunto opcional.
-- Compatible con MySQL / MariaDB (XAMPP y cPanel).
-- El adjunto se guarda fuera de la carpeta pública; aquí solo se guarda el nombre aleatorio.

CREATE TABLE IF NOT EXISTS feedback_reports (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  type ENUM('report','suggestion') NOT NULL,
  description TEXT NOT NULL,
  status ENUM('new','in_review','resolved') NOT NULL DEFAULT 'new',
  attachment_path VARCHAR(80) NULL,
  attachment_name VARCHAR(180) NULL,
  attachment_mime VARCHAR(100) NULL,
  attachment_size INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_changed_at DATETIME NULL,
  status_changed_by BIGINT UNSIGNED NULL,
  INDEX idx_feedback_reports_company_created (company_id, created_at),
  INDEX idx_feedback_reports_company_status (company_id, status),
  CONSTRAINT fk_feedback_reports_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_feedback_reports_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_feedback_reports_changed_by FOREIGN KEY (status_changed_by) REFERENCES users(id)
) ENGINE=InnoDB;
