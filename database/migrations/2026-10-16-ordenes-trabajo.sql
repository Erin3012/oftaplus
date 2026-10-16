-- Migración aditiva 2026-10-16: órdenes de trabajo (OT) creadas desde el formulario de venta.
-- Compatible con MySQL / MariaDB (XAMPP y cPanel). No modifica tablas existentes ni crea datos.

CREATE TABLE IF NOT EXISTS work_orders (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  number INT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_work_orders_number (company_id, number),
  KEY idx_work_orders_document (document_id),
  CONSTRAINT fk_work_orders_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_work_orders_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_work_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_work_orders_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_order_lines (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  work_order_id BIGINT UNSIGNED NOT NULL,
  line_number INT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  concept VARCHAR(180) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  UNIQUE KEY uq_work_order_lines (work_order_id, line_number),
  CONSTRAINT fk_work_order_lines_order FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
  CONSTRAINT fk_work_order_lines_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;
