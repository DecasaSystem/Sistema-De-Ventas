-- =============================================================
-- Usuario MySQL restringido para la aplicación Decasa
-- Ejecutar como root en MySQL una sola vez
-- =============================================================

-- 1. Crear usuario con contraseña segura (cámbiala antes de ejecutar)
CREATE USER IF NOT EXISTS 'decasa_app'@'127.0.0.1' IDENTIFIED BY 'D3casa_App#2026!';

-- 2. Solo permisos de lectura/escritura de datos — sin DDL
GRANT SELECT, INSERT, UPDATE, DELETE ON decasa_system.* TO 'decasa_app'@'127.0.0.1';

-- 3. Denegar explícitamente operaciones estructurales
REVOKE DROP, CREATE, ALTER, INDEX, REFERENCES ON decasa_system.* FROM 'decasa_app'@'127.0.0.1';

-- 4. Aplicar cambios
FLUSH PRIVILEGES;

-- =============================================================
-- Después de ejecutar este script, actualiza el .env:
--   DB_USERNAME=decasa_app
--   DB_PASSWORD=D3casa_App#2026!
-- =============================================================
