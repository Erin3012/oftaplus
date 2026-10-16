-- Migración aditiva 2026-10-16: observaciones de la venta (campo propio, separado de "Motivo vis." que usa notes).
-- Compatible con MySQL / MariaDB (XAMPP y cPanel). No modifica datos existentes.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'documents' AND column_name = 'observations');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE documents ADD COLUMN observations TEXT NULL AFTER notes', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
