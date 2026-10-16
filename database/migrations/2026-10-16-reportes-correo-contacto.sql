-- Migración aditiva 2026-10-16: correo de contacto opcional en reportes y sugerencias.
-- Compatible con MySQL / MariaDB (XAMPP y cPanel); se puede ejecutar más de una vez.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'feedback_reports' AND column_name = 'contact_email');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE feedback_reports ADD COLUMN contact_email VARCHAR(190) NULL AFTER user_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
