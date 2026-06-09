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
| tiendas (5) | `decasa-api/costos_seed.sql` **o** `TiendasSeeder` | Idénticas en ambos (ya corregidas, ver abajo). |
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
(`firma_url`, `facturacion`, `es_tapicero`, `notif_asignar_fecha`, `acceso_redes`, etc.).
Si no corren todas, no se podrán crear esos roles ni funcionar correctamente.

> **Efecto secundario de la migración `add_es_fabrica_to_tiendas`:**
> Crea automáticamente una tienda genérica llamada `'Fábrica'` con `es_fabrica=1`.
> **No importa** — el paso 3b la borrará y el SQL dejará Decasa Bolívar como fábrica.

### 3. Cargar catálogo + costos desde el SQL
```bash
mysql -h <host> -P <port> -u <user> -p<pass> \
  --ssl-mode=REQUIRED \
  --default-character-set=utf8mb4 \
  <db> < costos_seed.sql
```
El SQL inserta/actualiza: tiendas (5), productos, materiales, fichas, ítems, salarios y tarifas
(y también los 8 usuarios demo — se eliminan en el paso 5).

#### 3b. Limpiar la tienda genérica que creó la migración
La migración `add_es_fabrica_to_tiendas` crea una fila `'Fábrica'` además de las 5 reales.
El SQL ya marca Decasa Bolívar (ID 1) con `es_fabrica=1`, así que la genérica sobra.
Borrarla vía tinker:
```bash
php artisan tinker --execute="App\Models\Tienda::where('nombre','Fábrica')->delete(); echo 'OK';"
```
Verificar que solo queda Bolívar con es_fabrica=1:
```bash
php artisan tinker --execute="App\Models\Tienda::all(['id','nombre','es_fabrica'])->each(fn(\$t)=>print(\$t->id.' '.\$t->nombre.' fab='.(int)\$t->es_fabrica.PHP_EOL));"
```
Debe mostrar: solo ID 1 (Decasa Bolívar) con `fab=1`, las otras cuatro con `fab=0`.

### 4. Generar inventario en 0
```bash
php artisan db:seed --class=InventarioSeeder --force
```
Crea una fila por producto × tienda con `cantidad_disponible = 0`, `cantidad_reservada = 0`,
`stock_minimo = 1`. Lee productos y tiendas ya cargados. Debe crear exactamente **208 × 5 = 1040 filas**.

⚠️ Correr DESPUÉS del paso 3b (con la tienda genérica ya borrada), si no crearía inventario
para ella también.

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
UNION ALL SELECT 'configuracion', COUNT(*) FROM configuracion;
```
Esperado aprox.: tiendas=**5**, productos=208, materiales=621, fichas=306, items=4666,
salarios=4, tarifas=16, inventario=**1040**, usuarios=**1**, configuracion=**19**.

```sql
-- Verificar tiendas correctas
SELECT id, nombre, es_fabrica FROM tiendas ORDER BY id;
```
Esperado:
| id | nombre | es_fabrica |
|----|--------|-----------|
| 1 | Decasa Bolívar | 1 |
| 2 | Decasa Vía El Edén | 0 |
| 3 | Decasa Vía Jardines | 0 |
| 4 | Decasa Unicentro Pereira | 0 |
| 5 | Decasa Circunvalar | 0 |

```sql
-- Verificar es_tapizado (sillas y sofás deben tener 1)
SELECT categoria, COUNT(*) n, SUM(es_tapizado) tapizados
FROM productos GROUP BY categoria ORDER BY categoria;
```
Todas las categorías que contengan "Silla", "Sofá" o "Modular" deben tener `tapizados = n`.

---

## ⚠️ Encoding UTF-8 — problema conocido, prevención obligatoria

### Qué pasó en la BD de prueba (Aiven no-definitiva)
Al importar `costos_seed.sql` y otros datos, 90 filas de `productos` quedaron con
`??` en lugar de `ñ`, `á`, `é`, `ó`, etc. (ej: `Dise??o`, `construcci??n`).

**Causa:** el cliente `mysql` CLI o el script PHP que hizo la inserción no forzó
`utf8mb4` en la conexión. MySQL recibió bytes UTF-8 de 2 bytes por carácter especial,
no pudo convertirlos a `latin1` (el charset de sesión por defecto) y los sustituyó por `?`.

### Lista de verificación antes de migrar a la BD definitiva

#### 1. Importar el SQL con charset explícito
```bash
mysql -h <host> -P <port> -u <user> -p<pass> \
  --ssl-mode=REQUIRED \
  --default-character-set=utf8mb4 \
  <db> < costos_seed.sql
```
El flag `--default-character-set=utf8mb4` es el que previene el problema.
Sin él, aunque el SQL diga `utf8mb4`, la *sesión* usa `latin1` y los caracteres se corrompen.

#### 2. Verificar que `costos_seed.sql` tiene la cabecera correcta
Al inicio del archivo debe existir:
```sql
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
```
Si no está, añadirla antes de importar (o pasarla con el flag del punto 1).

#### 3. Si se usan scripts PHP directos (sin Laravel), ejecutar SET NAMES inmediatamente
```php
$pdo = new PDO($dsn, $user, $pass, $opts);
$pdo->exec("SET NAMES utf8mb4");  // ← obligatorio antes de cualquier query
```
Laravel lo hace automáticamente gracias a `charset = utf8mb4` en `config/database.php`,
pero cualquier script PHP manual debe incluir esta línea.

#### 4. Verificar encoding ANTES de dar la migración por buena
```sql
SELECT COUNT(*) AS filas_corruptas
FROM productos
WHERE nombre     LIKE '%??%'
   OR descripcion LIKE '%??%'
   OR material    LIKE '%??%'
   OR categoria   LIKE '%??%';
```
El resultado debe ser **0**. Si hay filas corruptas, la fuente local (MySQL local) siempre
tiene los datos correctos y se puede re-sincronizar con un script PHP que:
- Conecte a local con `SET NAMES utf8mb4`
- Conecte a Aiven con `SET NAMES utf8mb4`
- Lea todos los productos de local y haga `UPDATE` en Aiven por `id`

#### 5. Tablas adicionales a revisar (no solo productos)
Cualquier tabla con columnas `TEXT` o `VARCHAR` puede tener el mismo problema:
- `materiales` (columna `nombre`)
- `fichas_tecnicas` (columna `nombre`)
- `tiendas` (columna `nombre`, `ciudad`)
- `usuarios` (columna `nombre`)

El mismo `SELECT COUNT(*) WHERE x LIKE '%??%'` aplica para todas.

---

## Cambios recientes en el esquema (sesiones jun-2026)

Estas columnas/tablas fueron añadidas **después** de que se creó `costos_seed.sql`.
Todas son cubiertas por `php artisan migrate --force` — no requieren acción manual,
pero se documentan aquí para referencia.

| Migración | Qué agrega |
|-----------|-----------|
| `add_es_tapizado_to_productos_table` | columna `es_tapizado` BOOL en `productos` |
| `set_es_tapizado_default_sofas_sillas` | pone `es_tapizado=1` en productos cuya categoría o nombre contenga sofa/silla/modular |
| `set_es_tapizado_on_sillas_y_sofas` *(nueva, jun-06)* | versión más completa del anterior: actualiza por categoría con LIKE case-insensitive — corre encima sin conflicto |
| `add_es_fabrica_to_tiendas` | columna `es_fabrica` BOOL en `tiendas`; crea tienda genérica `'Fábrica'` (se borra en paso 3b) |
| `add_acceso_redes_to_usuarios` | columna `acceso_redes` BOOL en `usuarios` |
| `add_fuente_fabrica_to_surtidos` | columna `fuente_fabrica` BOOL en `surtidos` |
| `add_cantidad_aceptada_to_items` | columna `cantidad_aceptada` en `traslado_items` y `surtido_items` |
| `create_v_saldo_ordenes_view` | vista `v_saldo_ordenes` usada por reportes y stats |
| `add_foto_url_2_to_productos` *(nueva, jun-09)* | columna `foto_url_2` nullable en `productos`; permite 2ª imagen para IAs de WS/IG |
| `create_configuracion_table` *(nueva, jun-09)* | crea tabla `configuracion` Y la siembra con los 19 registros iniciales (datos empresa + URLs catálogos Google Drive) — **no necesita importación manual** |

---

## Notas / decisiones pendientes del usuario
- **Credenciales del admin** (email + contraseña): confirmarlas antes del paso 5.
- ¿`costos_seed.sql` sigue siendo el snapshot bueno de productos/costos, o hay que
  regenerarlo con `php artisan` (comando `ExportarCostos`) desde la base actual antes de migrar?
- Confirmar que el `ca.pem`/SSL de Aiven esté disponible para los comandos `mysql` y para Laravel.
