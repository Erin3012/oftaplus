-- Migración aditiva 2026-10-16: cobros importados desde el listado de Gesvision.
-- Compatible con MySQL / MariaDB (XAMPP y cPanel).
-- Son registros históricos de consulta: no crea pagos, documentos, clientes ni movimientos de caja.
-- Contiene nombres de clientes reales cuando se importa: nunca subas el Excel ni la base de datos al repositorio.

CREATE TABLE IF NOT EXISTS imported_payments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  receipt_series VARCHAR(10) NOT NULL,
  receipt_number INT UNSIGNED NOT NULL,
  invoice_number VARCHAR(40) NULL,
  customer_name VARCHAR(180) NOT NULL,
  payment_type VARCHAR(60) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_imported_payments_number (company_id, receipt_series, receipt_number),
  KEY idx_imported_payments_company_paid (company_id, paid_at),
  CONSTRAINT fk_imported_payments_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;
