-- Migración aditiva 2026-10-08: proveedores, traspasos e inventarios.
-- Solo crea tablas nuevas si no existen. No borra ni modifica tablas o datos existentes.
CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(180) NOT NULL,
  tax_id VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_suppliers_code (company_id, code),
  KEY idx_suppliers_search (company_id, name),
  CONSTRAINT fk_suppliers_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  origin_location_id BIGINT UNSIGNED NOT NULL,
  destination_location_id BIGINT UNSIGNED NOT NULL,
  transfer_type ENUM('request','direct') NOT NULL DEFAULT 'direct',
  status ENUM('requested','sent','received') NOT NULL DEFAULT 'requested',
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_transfers_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_stock_transfers_origin FOREIGN KEY (origin_location_id) REFERENCES stock_locations(id),
  CONSTRAINT fk_stock_transfers_destination FOREIGN KEY (destination_location_id) REFERENCES stock_locations(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transfer_lines (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  CONSTRAINT fk_stock_transfer_lines_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id),
  CONSTRAINT fk_stock_transfer_lines_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_counts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  notes VARCHAR(255) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  CONSTRAINT fk_inventory_counts_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_inventory_counts_location FOREIGN KEY (location_id) REFERENCES stock_locations(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_count_lines (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  inventory_count_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  expected_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  counted_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_inventory_count_lines (inventory_count_id, product_id),
  CONSTRAINT fk_inventory_count_lines_count FOREIGN KEY (inventory_count_id) REFERENCES inventory_counts(id),
  CONSTRAINT fk_inventory_count_lines_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;
