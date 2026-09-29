-- Oftaplus / Gesvision-compatible data model
-- MySQL 8+ / MariaDB 10.5+
-- This file contains structure only. It intentionally has no customer, credential,
-- tax identifier, document or financial seed data.

-- In cPanel, create the database and assign its user first. Import this file
-- while that database is selected in phpMyAdmin or the terminal.

CREATE TABLE IF NOT EXISTS companies (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  legal_name VARCHAR(200) NULL,
  tax_id VARCHAR(40) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CLP',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Santiago',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS branches (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  address VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_branches_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  display_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  role ENUM('owner','admin','seller','cashier','viewer') NOT NULL DEFAULT 'seller',
  active TINYINT(1) NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_company_email (company_id, email),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_methods (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(40) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_payment_methods_code (company_id, code),
  CONSTRAINT fk_payment_methods_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_terms (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_payment_terms_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_types (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(100) NOT NULL,
  direction ENUM('sale','purchase','internal') NOT NULL,
  affects_stock TINYINT(1) NOT NULL DEFAULT 0,
  affects_receivables TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_document_types_code (company_id, code),
  CONSTRAINT fk_document_types_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_series (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  document_type_id BIGINT UNSIGNED NOT NULL,
  prefix VARCHAR(20) NULL,
  next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_document_series (company_id, branch_id, document_type_id, prefix),
  CONSTRAINT fk_document_series_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_document_series_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_document_series_type FOREIGN KEY (document_type_id) REFERENCES document_types(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(180) NOT NULL,
  customer_type ENUM('person','company','walk_in') NOT NULL DEFAULT 'person',
  tax_id VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  profile_id BIGINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_code (company_id, code),
  KEY idx_customers_search (company_id, name, email),
  CONSTRAINT fk_customers_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_contacts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  contact_type VARCHAR(80) NOT NULL,
  value VARCHAR(255) NOT NULL,
  preferred TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_customer_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_clinical_data (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  data_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_clinical_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(80) NOT NULL,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  product_type ENUM('product','service','kit','model') NOT NULL DEFAULT 'product',
  unit VARCHAR(30) NOT NULL DEFAULT 'unidad',
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  cost_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  attributes_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_sku (company_id, sku),
  KEY idx_products_search (company_id, name, sku),
  CONSTRAINT fk_products_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_prices (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  price_list VARCHAR(80) NOT NULL DEFAULT 'general',
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  valid_from DATE NULL,
  valid_until DATE NULL,
  CONSTRAINT fk_product_prices_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_prices (product_id, price_list, valid_from)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_locations (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_stock_locations_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_stock_locations_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  document_type_id BIGINT UNSIGNED NOT NULL,
  series_id BIGINT UNSIGNED NULL,
  parent_document_id BIGINT UNSIGNED NULL,
  number BIGINT UNSIGNED NULL,
  status ENUM('draft','issued','partially_paid','paid','delivered','cancelled') NOT NULL DEFAULT 'draft',
  customer_id BIGINT UNSIGNED NULL,
  supplier_name VARCHAR(180) NULL,
  seller_id BIGINT UNSIGNED NULL,
  payment_term_id BIGINT UNSIGNED NULL,
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_documents_number (company_id, document_type_id, series_id, number),
  KEY idx_documents_listing (company_id, issue_date, status),
  CONSTRAINT fk_documents_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_documents_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_documents_type FOREIGN KEY (document_type_id) REFERENCES document_types(id),
  CONSTRAINT fk_documents_series FOREIGN KEY (series_id) REFERENCES document_series(id),
  CONSTRAINT fk_documents_parent FOREIGN KEY (parent_document_id) REFERENCES documents(id),
  CONSTRAINT fk_documents_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_documents_seller FOREIGN KEY (seller_id) REFERENCES users(id),
  CONSTRAINT fk_documents_payment_term FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_lines (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  document_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  line_number INT UNSIGNED NOT NULL,
  concept VARCHAR(180) NOT NULL,
  reference VARCHAR(100) NULL,
  description TEXT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  gross_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_status VARCHAR(40) NULL,
  CONSTRAINT fk_document_lines_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_document_lines_product FOREIGN KEY (product_id) REFERENCES products(id),
  UNIQUE KEY uq_document_lines_number (document_id, line_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  payment_method_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_at DATETIME NOT NULL,
  reference VARCHAR(120) NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_payments_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_relations (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  source_document_id BIGINT UNSIGNED NOT NULL,
  target_document_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('quote_to_order','order_to_delivery','delivery_to_invoice','invoice_to_payment','associated') NOT NULL,
  UNIQUE KEY uq_document_relation (source_document_id, target_document_id, relation_type),
  CONSTRAINT fk_document_relations_source FOREIGN KEY (source_document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_document_relations_target FOREIGN KEY (target_document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NULL,
  quantity DECIMAL(12,3) NOT NULL,
  movement_type ENUM('in','out','adjustment','transfer') NOT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_movements_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_stock_movements_location FOREIGN KEY (location_id) REFERENCES stock_locations(id),
  CONSTRAINT fk_stock_movements_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cash_registers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_cash_registers_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_cash_registers_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cash_closures (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  cash_register_id BIGINT UNSIGNED NOT NULL,
  opened_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  closing_amount DECIMAL(14,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_cash_closures_register FOREIGN KEY (cash_register_id) REFERENCES cash_registers(id),
  CONSTRAINT fk_cash_closures_user FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS accounting_accounts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(160) NOT NULL,
  account_type ENUM('asset','liability','equity','income','expense') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_accounting_accounts_code (company_id, code),
  CONSTRAINT fk_accounting_accounts_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_accounting_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_entries (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NULL,
  entry_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_journal_entries_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_journal_entries_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_lines (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  journal_entry_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_journal_lines_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_journal_lines_account FOREIGN KEY (account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_templates (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_crm_templates_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_campaigns (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  template_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  status ENUM('draft','scheduled','sending','completed','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_crm_campaigns_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_crm_campaigns_template FOREIGN KEY (template_id) REFERENCES crm_templates(id),
  CONSTRAINT fk_crm_campaigns_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_messages (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  destination_masked VARCHAR(80) NULL,
  status ENUM('queued','sent','delivered','failed') NOT NULL DEFAULT 'queued',
  sent_at DATETIME NULL,
  CONSTRAINT fk_crm_messages_campaign FOREIGN KEY (campaign_id) REFERENCES crm_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_messages_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  changes_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_logs_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
