-- Migración aditiva 2026-10-14: marcas, categorías e índices compuestos para pagos y cierres.
-- Compatible con MySQL / MariaDB (XAMPP y cPanel).

CREATE TABLE IF NOT EXISTS brands (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_brands_company_name (company_id, name),
  CONSTRAINT fk_brands_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- Columnas en products si no existen
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'brand_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN brand_id BIGINT UNSIGNED NULL AFTER product_type, ADD CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'category');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN category VARCHAR(100) NULL AFTER brand_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índices compuestos para acelerar cobros y cierres
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'idx_payments_company_paid');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE payments ADD INDEX idx_payments_company_paid (company_id, paid_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cash_closures' AND index_name = 'idx_cash_closures_register_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE cash_closures ADD INDEX idx_cash_closures_register_status (cash_register_id, status, opened_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Tipo de documento GUI (Guía emitida)
INSERT IGNORE INTO document_types (company_id, code, name, direction, affects_stock, affects_receivables)
SELECT id, 'GUI', 'Guía emitida', 'sale', 1, 0 FROM companies WHERE NOT EXISTS (SELECT 1 FROM document_types dt WHERE dt.company_id = companies.id AND dt.code = 'GUI');

