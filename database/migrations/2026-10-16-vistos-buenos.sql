-- Migración aditiva 2026-10-16: vistos buenos (aprobaciones) de documentos de venta.
-- Compatible con MySQL / MariaDB (XAMPP y cPanel).
-- Un borrador puede tener varias solicitudes; cada una queda pendiente hasta que un owner o admin la aprueba o la rechaza.
-- No modifica documentos, cobros, stock ni clientes.

CREATE TABLE IF NOT EXISTS document_approvals (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NOT NULL DEFAULT '',
  requested_by_user_id BIGINT UNSIGNED NULL,
  requested_at DATETIME NOT NULL,
  decided_by_user_id BIGINT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_document_approvals_document (company_id, document_id, requested_at),
  CONSTRAINT fk_document_approvals_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_document_approvals_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB;
