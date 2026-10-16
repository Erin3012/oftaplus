-- Migración aditiva 2026-10-16: base de boletas importadas desde el listado de Gesvision.
-- Compatible con MySQL / MariaDB (XAMPP y cPanel).
-- Son registros históricos de consulta: no crea documentos, clientes ni movimientos de stock.
-- Contiene nombres de clientes reales cuando se importa: nunca subas el Excel ni la base de datos al repositorio.

CREATE TABLE IF NOT EXISTS sales_receipts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  series VARCHAR(10) NOT NULL,
  number INT UNSIGNED NOT NULL,
  branch_name VARCHAR(160) NOT NULL,
  customer_name VARCHAR(180) NOT NULL,
  status VARCHAR(40) NOT NULL,
  paid_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  pending_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  worker_name VARCHAR(160) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_receipts_number (company_id, series, number),
  KEY idx_sales_receipts_company_date (company_id, created_at),
  CONSTRAINT fk_sales_receipts_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;
