-- =============================================================
-- Usuario MySQL restringido para la aplicación Decasa
-- Ejecutar como root en MySQL una sola vez
-- =============================================================
--
-- NO pongas la contraseña real en este archivo: está versionado. Reemplaza
-- el marcador por una contraseña fuerte generada aparte, ejecuta el script,
-- y guarda esa contraseña solo en el .env del servidor (que NO se versiona).

-- 1. Crear usuario
CREATE USER IF NOT EXISTS 'decasa_app'@'127.0.0.1' IDENTIFIED BY 'REEMPLAZA_ESTA_CONTRASEÑA';

-- 2. Solo permisos de lectura/escritura de datos — sin DDL
GRANT SELECT, INSERT, UPDATE, DELETE ON decasa_system.* TO 'decasa_app'@'127.0.0.1';

-- 3. Denegar explícitamente operaciones estructurales
REVOKE DROP, CREATE, ALTER, INDEX, REFERENCES ON decasa_system.* FROM 'decasa_app'@'127.0.0.1';

-- 4. Aplicar cambios
FLUSH PRIVILEGES;

-- =============================================================
-- Después de ejecutar este script, actualiza el .env del servidor:
--   DB_USERNAME=decasa_app
--   DB_PASSWORD=<la contraseña que generaste>
-- =============================================================
