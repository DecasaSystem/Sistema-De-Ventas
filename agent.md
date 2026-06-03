# agent.md — Sembrado de la base de datos definitiva (Aiven)

> **Para el agente (Claude):** lee este archivo cuando el usuario diga algo como
> "ya creé la base de datos de Aiven, siémbrala". Aquí está el procedimiento exacto,
> verificado contra el código real del repo. Todos los comandos se ejecutan desde
> `decasa-api/` salvo que se indique lo contrario.

## Objetivo

Dejar la base de datos NUEVA de Aiven con **solo los datos por defecto** para arrancar:

- Catálogo: **productos, materiales, fichas técnicas** (+ ítems de ficha).
- Costos: **salarios_cargo** y **tarifas_proceso**.
- **tiendas** (las 5 reales).
- **inventario** en 0 (una fila por producto × tienda, listo para cargar stock).
- **UN solo usuario admin** (supervisor) para entrar y crear el resto desde la app.

NO se cargan: órdenes, pagos, clientes, despachos, consultas, usuarios demo, ni nada transaccional.

## Fuentes de datos (lo que ya existe en el repo)

| Dato | Fuente | Notas |
|------|--------|-------|
| tiendas (5) | `decasa-api/costos_seed.sql` **o** `TiendasSeeder` | Idénticas en ambos. |
| productos (208) | `decasa-api/costos_seed.sql` | Snapshot reciente CON costos. Es el canónico. |
| materiales (621) | `decasa-api/costos_seed.sql` | **Única** fuente server-side. No hay seeder de materiales. |
| fichas_tecnicas (306) | `decasa-api/costos_seed.sql` | **Única** fuente server-side. El comando `fichas:importar` lee Excel de `C:\Users\Lenovo\Desktop\materiales` (carpeta local, NO existe en el server). |
| ficha_tecnica_items (4666) | `decasa-api/costos_seed.sql` | — |
| salarios_cargo (4) | `costos_seed.sql` o `SalarioCargoSeeder` | — |
| tarifas_proceso (16) | `costos_seed.sql` o `TarifaProcesoSeeder` | — |
| **inventario** | `InventarioSeeder` | ⚠️ **NO está en `costos_seed.sql`**. Hay que sembrarlo aparte. |
| usuarios | — | El SQL trae 8 **demo** que NO queremos. Crear admin a mano (ver abajo). |

`costos_seed.sql` es idempotente (`ON DUPLICATE KEY UPDATE`) y hace `SET FOREIGN_KEY_CHECKS = 0`, así que se puede reimportar sin romper.

> **Sobre la doble carga de tarifas/salarios (verificado, NO es problema):**
> La migración `2026_05_25_200000_data_tarifas_proceso_y_salarios_cargo` inserta 16 tarifas
> y 4 salarios por defecto al migrar (tiene guard `if count > 0 return`). Luego el
> `costos_seed.sql` los recarga con `ON DUPLICATE KEY UPDATE` sobre los mismos `id` →
> **actualiza, no duplica**. El SQL queda como valor final. Las migraciones `vincular_items_*`
> corren sobre tablas vacías (no-ops) y el dump ya trae los ítems enlazados. Orden correcto:
> migrate → SQL.

## Procedimiento

### 1. Apuntar `.env` a la base de Aiven
Aiven MySQL exige SSL. En `decasa-api/.env`:
```
DB_CONNECTION=mysql
DB_HOST=<host>.aivencloud.com
DB_PORT=<puerto>
DB_DATABASE=<db>
DB_USERNAME=<user>
DB_PASSWORD=<pass>
MYSQL_ATTR_SSL_CA=<ruta al ca.pem de Aiven>   # o usar --ssl-mode=REQUIRED en el cliente mysql
```
> En producción (Render) estas variables van en el panel, no en el `.env` ni en `render.yaml`.

### 2. Crear el esquema (todas las migraciones)
```bash
php artisan migrate --force
```
⚠️ **Importante:** correr TODAS las migraciones, no solo la base. El `rol` de `usuarios`
empieza como enum `['vendedor','supervisor']` en la migración inicial y migraciones
posteriores lo amplían a `conductor`, `ebanista`, `despachador` y añaden columnas
(`firma_url`, `facturacion`, `es_tapicero`, `notif_asignar_fecha`). Si no corren todas,
no se podrán crear esos roles.

### 3. Cargar catálogo + costos desde el SQL
```bash
mysql -h <host> -P <port> -u <user> -p<pass> --ssl-mode=REQUIRED <db> < costos_seed.sql
```
Esto inserta tiendas, productos, materiales, fichas, ítems, salarios y tarifas
(y también los 8 usuarios demo — se eliminan en el paso 5).

### 4. Generar inventario en 0
```bash
php artisan db:seed --class=InventarioSeeder --force
```
Crea una fila por producto × tienda con `cantidad_disponible = 0`, `cantidad_reservada = 0`,
`stock_minimo = 1`. (Lee productos y tiendas ya cargados en el paso 3.)

### 5. Dejar UN solo usuario admin
Eliminar los demo y crear el admin. Vía tinker:
```bash
php artisan tinker
```
```php
DB::table('usuarios')->delete();   // borra los 8 demo del SQL
DB::table('usuarios')->insert([
    'nombre'            => 'Admin',
    'email'            => 'admin@decasa.com',          // ← confirmar con el usuario
    'password'          => Hash::make('CAMBIAR_ESTO'),  // ← contraseña fuerte; cambiar tras 1er login
    'rol'               => 'supervisor',
    'tienda_default_id' => 1,                           // Decasa Bolívar
    'activo'            => true,
    'created_at'        => now(),
]);
```
> Si el usuario prefiere, en vez de tinker se puede crear un `AdminSeeder` con `updateOrInsert`
> sobre `email` (patrón idéntico al de `UsuariosSeeder`, pero con un solo registro).
> **Pedir al usuario el email y la contraseña** antes de fijarlos; no inventar credenciales finales.

### 6. Verificar
```sql
SELECT 'tiendas' t, COUNT(*) n FROM tiendas
UNION ALL SELECT 'productos', COUNT(*) FROM productos
UNION ALL SELECT 'materiales', COUNT(*) FROM materiales
UNION ALL SELECT 'fichas_tecnicas', COUNT(*) FROM fichas_tecnicas
UNION ALL SELECT 'ficha_tecnica_items', COUNT(*) FROM ficha_tecnica_items
UNION ALL SELECT 'salarios_cargo', COUNT(*) FROM salarios_cargo
UNION ALL SELECT 'tarifas_proceso', COUNT(*) FROM tarifas_proceso
UNION ALL SELECT 'inventario', COUNT(*) FROM inventario
UNION ALL SELECT 'usuarios', COUNT(*) FROM usuarios;
```
Esperado aprox.: tiendas=5, productos=208, materiales=621, fichas=306, items=4666,
salarios=4, tarifas=16, inventario = 208×5 = **1040**, usuarios=**1**.

## Notas / decisiones pendientes del usuario
- **Credenciales del admin** (email + contraseña): confirmarlas antes del paso 5.
- ¿`costos_seed.sql` sigue siendo el snapshot bueno de productos/costos, o hay que
  regenerarlo con `php artisan` (comando `ExportarCostos`) desde la base actual antes de migrar?
- Confirmar que el `ca.pem`/SSL de Aiven esté disponible para los comandos `mysql` y para Laravel.
