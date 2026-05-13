# DECASA — Sistema de Gestión de Ventas e Inventario
> Archivo de contexto para Claude Code. Lee esto completo antes de escribir cualquier código.

---

## 1. Descripción del negocio

**Decasa** es una empresa de muebles (sofás, comedores, sillas, etc.) con varias tiendas físicas en Colombia. Fabrica productos estándar y también hace productos **personalizados** en sus propias fábricas con un plazo de entrega de 30 días.

Los vendedores atienden clientes de forma **física** (en tienda) o **virtual** (WhatsApp, redes sociales). El sistema debe funcionar desde el **celular** del vendedor sin necesidad de instalar ninguna app nativa — se implementa como una **PWA (Progressive Web App)**.

---

## 2. Stack tecnológico

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel 11 (PHP) — API REST |
| Frontend | Vue 3 + Vite — SPA / PWA |
| Estilos | Tailwind CSS |
| Base de datos | MySQL (nueva BD: `decasa_system`) |
| Auth | Laravel Sanctum (tokens) |
| Gráficas | Chart.js |
| Exports | Laravel Excel (maatwebsite/excel) |
| Colas | Laravel Queue (alertas automáticas) |
| Hosting | VPS (DigitalOcean / Railway / Hostinger) |

**Nota importante:** Ya existe una BD MySQL separada (`decasa_whatsapp`) usada por un asistente de WhatsApp con 180 productos. NO tocar esa BD. Solo importar los productos con un script de migración ya lo hice si puedes ejecutar una consulta en la terminal para mirarlo.

---

## 3. Roles del sistema

| Rol | Permisos |
|-----|---------|
| `vendedor` | Crear órdenes, registrar pagos, ver sus propias ventas, buscar productos/clientes, ver sus estadísticas personales |
| `supervisor` | Todo lo anterior + ver ventas de TODOS los vendedores, todas las tiendas, reportes globales, gestionar inventario, estadísticas avanzadas, gestionar despachos (asignar órdenes a conductores) |
| `conductor` | Ver sus entregas asignadas en el orden definido por el supervisor, registrar pagos con pruebas fotográficas, marcar órdenes como entregadas |

---

## 4. Flujo de negocio

### 4.1 Tipos de venta

**Tipo A — Producto en inventario (stock disponible)**
1. Vendedor selecciona cliente y productos del catálogo
2. Sistema verifica stock disponible en la tienda seleccionada
3. Se registra la orden y se **reserva** el stock
4. Cliente paga **50% de anticipo** → se registra el pago
5. Se agenda fecha de entrega
6. Al entregar: cliente paga el **50% restante** (de una sola vez o en abonos)
7. Orden pasa a estado `entregado`

**Tipo B — Producto personalizado**
1. Vendedor registra specs del producto (color, tela, medidas, acabados → JSON)
2. Cliente paga **50% de anticipo**
3. Orden entra a producción → plazo de **30 días**
4. Sistema alerta si se acerca o supera la fecha límite
5. Al entregar: cliente paga el 50% restante (o abona)
6. Orden pasa a estado `entregado`

### 4.2 Canales de venta
- `fisica` — el vendedor está en la tienda con el cliente
- `whatsapp` — venta por WhatsApp
- `red_social` — venta por Instagram, Facebook u otra red
- `otro`

### 4.3 Despacho y entrega (nuevo flujo)

1. Cuando una orden pasa al estado `listo_entrega`, automáticamente aparece en el módulo de **Despacho** (solo visible para el supervisor y conductores)
2. La orden queda **bloqueada**: ningún otro módulo puede cambiarle el estado; solo el módulo de Despacho controla su ciclo a partir de aquí
3. Las órdenes en Despacho se listan en orden cronológico de llegada (la que primero llegó a `listo_entrega` aparece arriba)
4. El supervisor selecciona las órdenes que quiere asignar en el orden que desea que se entreguen (selección manual, no es obligatorio seleccionar todas)
5. El supervisor pulsa **"Asignar"** → se abre un selector de conductores disponibles
6. Al asignar, las órdenes pasan al estado `en_despacho` y el conductor las ve en su app en el orden definido por el supervisor
7. El conductor llega al cliente, registra el pago (monto + método + referencia) y sube **2 archivos**:
   - Foto del producto instalado/entregado en casa del cliente (prueba de entrega)
   - Foto del comprobante de pago (prueba de pago)
8. Al registrar el pago y subir las pruebas se desbloquea el botón **"Entregado"**
9. El conductor pulsa "Entregado" → la orden pasa al estado `entregado`
10. El supervisor recibe una **notificación en tiempo real** (WebSocket) de que el producto fue entregado
11. El supervisor puede acceder al detalle de la orden y ver toda la información + las 2 fotos del conductor + la barra de pago completa
12. El conductor repite el proceso con la siguiente orden en su lista

> **Regla crítica:** El supervisor ya NO puede marcar manualmente una orden como `entregado`. Solo el conductor puede hacerlo desde el módulo de Despacho.

### 4.4 Tiendas y vendedores
- Hay **varias tiendas**; cada vendedor y conductor tiene una **tienda predeterminada**
- Al crear una orden, el vendedor puede **cambiar la tienda** (si está vendiendo en otra)
- El **inventario es por tienda** — mismos productos, distinto stock en cada tienda
- El **catálogo de productos es único** para todas las tiendas

---

## 5. Schema de base de datos

**BD:** `decasa_system` — crear nueva, independiente de `decasa_whatsapp`.

```sql
CREATE DATABASE decasa_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE decasa_system;

-- Tiendas
CREATE TABLE tiendas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(100) NOT NULL,
  ciudad     VARCHAR(80),
  direccion  VARCHAR(200),
  telefono   VARCHAR(20),
  activa     BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Usuarios (vendedores, supervisores y conductores)
CREATE TABLE usuarios (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  nombre            VARCHAR(100) NOT NULL,
  email             VARCHAR(120) UNIQUE NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  rol               ENUM('vendedor','supervisor','conductor') DEFAULT 'vendedor',
  tienda_default_id INT,
  activo            BOOLEAN DEFAULT TRUE,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tienda_default_id) REFERENCES tiendas(id)
);

-- Clientes
CREATE TABLE clientes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(120) NOT NULL,
  cedula     VARCHAR(20) UNIQUE,
  telefono   VARCHAR(20),
  email      VARCHAR(120),
  direccion  VARCHAR(200),
  canal_pref ENUM('fisica','whatsapp','red_social','otro'),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Catálogo de productos (único para todas las tiendas)
CREATE TABLE productos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(150) NOT NULL,
  categoria      VARCHAR(80),
  precio_base    DECIMAL(12,2) NOT NULL,
  personalizable BOOLEAN DEFAULT FALSE,
  descripcion    TEXT,
  foto_url       VARCHAR(255),
  medidas        varchar(200),
  material       varchar(200),
  activo         BOOLEAN DEFAULT TRUE,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Stock por tienda
CREATE TABLE inventario (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  producto_id         INT NOT NULL,
  tienda_id           INT NOT NULL,
  cantidad_disponible INT DEFAULT 0,
  cantidad_reservada  INT DEFAULT 0,
  stock_minimo        INT DEFAULT 1,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE (producto_id, tienda_id),
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id)
);

-- Auditoría de movimientos de inventario
CREATE TABLE inventario_movimientos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  tienda_id   INT NOT NULL,
  tipo        ENUM('entrada','salida','reserva','liberacion'),
  cantidad    INT NOT NULL,
  motivo      VARCHAR(200),
  usuario_id  INT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)
);

-- Órdenes
CREATE TABLE ordenes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id   INT NOT NULL,
  vendedor_id  INT NOT NULL,
  tienda_id    INT NOT NULL,
  canal        ENUM('fisica','whatsapp','red_social','otro'),
  estado       ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_despacho','entregado','cancelado') DEFAULT 'pendiente_anticipo',
  -- en_despacho: asignada a un conductor, en camino
  listo_entrega_at TIMESTAMP NULL,   -- cuando llegó a listo_entrega (para ordenar la cola de despacho)
  valor_total  DECIMAL(12,2) NOT NULL,
  anticipo_pct DECIMAL(5,2) DEFAULT 50.00,
  notas        TEXT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id)  REFERENCES clientes(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id)
);

-- Despachos: cada asignación que hace el supervisor a un conductor
CREATE TABLE despachos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  conductor_id  INT NOT NULL,
  supervisor_id INT NOT NULL,
  estado        ENUM('asignado','en_ruta','completado') DEFAULT 'asignado',
  notas         TEXT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (conductor_id)  REFERENCES usuarios(id),
  FOREIGN KEY (supervisor_id) REFERENCES usuarios(id)
);

-- Items de cada despacho, con el orden de entrega que definió el supervisor
CREATE TABLE despacho_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  despacho_id   INT NOT NULL,
  orden_id      INT NOT NULL,
  posicion      INT NOT NULL,         -- posición en la ruta (1 = primera entrega, 2 = segunda, etc.)
  estado        ENUM('pendiente','entregado') DEFAULT 'pendiente',
  foto_producto VARCHAR(500) NULL,    -- prueba de entrega: foto del producto en casa del cliente
  foto_pago     VARCHAR(500) NULL,    -- prueba de pago: foto del comprobante
  entregado_at  TIMESTAMP NULL,
  UNIQUE (despacho_id, orden_id),
  UNIQUE (despacho_id, posicion),
  FOREIGN KEY (despacho_id) REFERENCES despachos(id),
  FOREIGN KEY (orden_id)    REFERENCES ordenes(id)
);

-- Items de cada orden
CREATE TABLE orden_items (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  orden_id              INT NOT NULL,
  producto_id           INT NOT NULL,
  cantidad              INT DEFAULT 1,
  precio_unitario       DECIMAL(12,2) NOT NULL,
  es_personalizado      BOOLEAN DEFAULT FALSE,
  specs_personalizacion JSON,
  fecha_entrega_prom    DATE,
  FOREIGN KEY (orden_id)    REFERENCES ordenes(id),
  FOREIGN KEY (producto_id) REFERENCES productos(id)
);

-- Pagos (anticipo + abonos + saldo final)
CREATE TABLE pagos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  orden_id    INT NOT NULL,
  vendedor_id INT NOT NULL,
  tipo        ENUM('anticipo','abono','saldo_final'),
  monto       DECIMAL(12,2) NOT NULL,
  metodo      ENUM('efectivo','transferencia','tarjeta','otro'),
  referencia  VARCHAR(100),
  notas       TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (orden_id)    REFERENCES ordenes(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id)
);

-- Producción (solo para items personalizados)
CREATE TABLE produccion (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  orden_item_id    INT NOT NULL UNIQUE,
  fecha_inicio     DATE NOT NULL,
  fecha_compromiso DATE NOT NULL,
  fecha_real       DATE,
  estado           ENUM('en_proceso','listo','retrasado','entregado') DEFAULT 'en_proceso',
  motivo_retraso   TEXT,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (orden_item_id) REFERENCES orden_items(id)
);

-- Vista: saldo pendiente por orden
CREATE VIEW v_saldo_ordenes AS
  SELECT
    o.id AS orden_id,
    o.valor_total,
    COALESCE(SUM(p.monto), 0) AS total_pagado,
    o.valor_total - COALESCE(SUM(p.monto), 0) AS saldo_pendiente
  FROM ordenes o
  LEFT JOIN pagos p ON p.orden_id = o.id
  GROUP BY o.id, o.valor_total;

-- Vista: pedidos retrasados
CREATE VIEW v_retrasos AS
  SELECT
    p.id,
    o.id AS orden_id,
    c.nombre AS cliente,
    c.telefono,
    pr.nombre AS producto,
    p.fecha_compromiso,
    DATEDIFF(CURDATE(), p.fecha_compromiso) AS dias_retraso,
    p.motivo_retraso,
    u.nombre AS vendedor,
    t.nombre AS tienda
  FROM produccion p
  JOIN orden_items oi ON oi.id = p.orden_item_id
  JOIN ordenes o      ON o.id  = oi.orden_id
  JOIN clientes c     ON c.id  = o.cliente_id
  JOIN productos pr   ON pr.id = oi.producto_id
  JOIN usuarios u     ON u.id  = o.vendedor_id
  JOIN tiendas t      ON t.id  = o.tienda_id
  WHERE p.estado = 'retrasado'
     OR (p.estado = 'en_proceso' AND p.fecha_compromiso < CURDATE());

-- Índices para reportes
CREATE INDEX idx_ordenes_vendedor ON ordenes(vendedor_id);
CREATE INDEX idx_ordenes_tienda   ON ordenes(tienda_id);
CREATE INDEX idx_ordenes_estado   ON ordenes(estado);
CREATE INDEX idx_ordenes_fecha    ON ordenes(created_at);
CREATE INDEX idx_pagos_orden      ON pagos(orden_id);
CREATE INDEX idx_inv_tienda       ON inventario(tienda_id);
```


-- Inicializar inventario en 0 para cada producto x tienda
INSERT INTO inventario (producto_id, tienda_id, cantidad_disponible)
SELECT p.id, t.id, 0
FROM productos p CROSS JOIN tiendas t;
```

## 5.1 Cambios de schema requeridos para el módulo de Despacho (pendientes de ejecutar)

```sql
-- 1. Agregar conductor al ENUM de roles
ALTER TABLE usuarios MODIFY COLUMN rol ENUM('vendedor','supervisor','conductor') DEFAULT 'vendedor';

-- 2. Agregar en_despacho al ENUM de estados de orden y la columna listo_entrega_at
ALTER TABLE ordenes
  MODIFY COLUMN estado ENUM('pendiente_anticipo','en_produccion','listo_entrega','en_despacho','entregado','cancelado') DEFAULT 'pendiente_anticipo',
  ADD COLUMN listo_entrega_at TIMESTAMP NULL AFTER estado;

-- 3. Crear tabla despachos
CREATE TABLE despachos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  conductor_id  INT NOT NULL,
  supervisor_id INT NOT NULL,
  estado        ENUM('asignado','en_ruta','completado') DEFAULT 'asignado',
  notas         TEXT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (conductor_id)  REFERENCES usuarios(id),
  FOREIGN KEY (supervisor_id) REFERENCES usuarios(id)
);

-- 4. Crear tabla despacho_items
CREATE TABLE despacho_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  despacho_id   INT NOT NULL,
  orden_id      INT NOT NULL,
  posicion      INT NOT NULL,
  estado        ENUM('pendiente','entregado') DEFAULT 'pendiente',
  foto_producto VARCHAR(500) NULL,
  foto_pago     VARCHAR(500) NULL,
  entregado_at  TIMESTAMP NULL,
  UNIQUE (despacho_id, orden_id),
  UNIQUE (despacho_id, posicion),
  FOREIGN KEY (despacho_id) REFERENCES despachos(id),
  FOREIGN KEY (orden_id)    REFERENCES ordenes(id)
);

-- 5. Índices para rendimiento
CREATE INDEX idx_ordenes_despacho ON ordenes(estado, listo_entrega_at);
CREATE INDEX idx_despacho_conductor ON despachos(conductor_id, estado);
```

## 5.2 Tablas adicionales para el módulo de Surtir (pendientes de crear)

```sql
-- Registro del evento de abastecimiento creado por el supervisor
CREATE TABLE surtidos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  supervisor_id INT NOT NULL,
  notas         TEXT,
  estado        ENUM('enviado','completado','rechazado_parcial') DEFAULT 'enviado',
  -- enviado: al menos una tienda pendiente
  -- completado: todas las tiendas aceptaron
  -- rechazado_parcial: alguna tienda rechazó (pero otras pueden haber aceptado)
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (supervisor_id) REFERENCES usuarios(id)
);

-- Una fila por tienda destino dentro del surtido
CREATE TABLE surtido_tiendas (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  surtido_id            INT NOT NULL,
  tienda_id             INT NOT NULL,
  vendedor_validador_id INT NOT NULL,   -- vendedor de esa tienda que debe confirmar la recepción
  estado                ENUM('pendiente','aceptado','rechazado') DEFAULT 'pendiente',
  notas_vendedor        TEXT NULL,      -- comentario del vendedor al aceptar o rechazar
  respondido_at         TIMESTAMP NULL,
  UNIQUE (surtido_id, tienda_id),
  FOREIGN KEY (surtido_id)            REFERENCES surtidos(id),
  FOREIGN KEY (tienda_id)             REFERENCES tiendas(id),
  FOREIGN KEY (vendedor_validador_id) REFERENCES usuarios(id)
);

-- Productos y cantidades por tienda dentro del surtido
CREATE TABLE surtido_items (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  surtido_tienda_id INT NOT NULL,
  producto_id       INT NOT NULL,
  cantidad          INT NOT NULL,
  especificaciones  JSON NULL,   -- telas, colores, medidas, acabados (igual que specs_personalizacion)
  FOREIGN KEY (surtido_tienda_id) REFERENCES surtido_tiendas(id),
  FOREIGN KEY (producto_id)       REFERENCES productos(id)
);

-- Índices
CREATE INDEX idx_surtido_tiendas_vendedor ON surtido_tiendas(vendedor_validador_id, estado);
CREATE INDEX idx_surtido_tiendas_surtido  ON surtido_tiendas(surtido_id);
CREATE INDEX idx_surtidos_supervisor      ON surtidos(supervisor_id, created_at);
```

> **Lógica de inventario al aceptar:** Al ejecutar `PATCH /api/inventario/surtido-tiendas/{id}/aceptar`, el backend itera sobre los `surtido_items` de esa `surtido_tienda` e incrementa `inventario.cantidad_disponible` para cada `(producto_id, tienda_id)`. Si no existe el registro en `inventario` para ese par, lo crea. Por cada ítem también inserta un registro en `inventario_movimientos` con `tipo = 'entrada'` y `motivo = 'Surtido #' + surtido_id`.

---

##  Estado actual de la BD y desarrollo (en progreso)

- `decasa_system` creada con todas las tablas del schema
- 207 productos migrados desde `decasa_whatsapp`
- 4 tiendas insertadas (IDs 1-4)
- Inventario inicializado en 0 para los 828 registros (207 productos × 4 tiendas)
- Backend: ✅ completo (todos los endpoints de la sección 7 implementados)
- Frontend:
  - ✅ Login, Dashboard, NuevaOrdenView (flujo de 3 pasos)
  - ✅ OrdenesView con filtros, scroll infinito, OrdenDetalleView, RegistroPagoModal
  - ✅ Componentes reusables: BadgeEstado, MoneyDisplay, EmptyState
  - ⏳ InventarioView, ProduccionView, ClientesView (básico)
  - ⏳ ReportesView (estadísticas globales supervisor) — pendiente sección 6.6
  - ⏳ StatsVendedorView (estadísticas personales vendedor) — pendiente sección 6.7
  - ⏳ PWA (manifest + service worker)
- Hosting: backend en Herd (Laravel Nginx) → `http://decasa-api.test`
- Proxy Vite configurado: `/api` → `http://decasa-api.test`

---

## 6. Módulos del sistema

### 6.1 Autenticación
- Login con email + password
- Laravel Sanctum para tokens de API
- El token se guarda en localStorage del frontend
- Middleware de roles: `role:vendedor` y `role:supervisor`
- Al hacer login, la respuesta incluye: token, nombre, rol, tienda_default_id

### 6.2 Módulo de ventas (Órdenes)

**Crear orden (vendedor):**
1. Seleccionar o crear cliente (buscar por nombre o cédula)
2. Seleccionar tienda (predeterminada del vendedor, con opción de cambiar)
3. Seleccionar canal de venta
4. Agregar productos:
   - Buscar por nombre (fulltext sobre `productos.nombre`)
   - Ver stock disponible en la tienda seleccionada
   - Si `personalizable = true`, mostrar campos extra: color, tela, medidas, acabado
   - Si es personalizado, calcular `fecha_entrega_prom = hoy + 30 días`
5. Ver resumen con valor total
6. Registrar anticipo (50% por defecto, campo editable)
7. Guardar → crea `ordenes`, `orden_items`, `pagos` (tipo anticipo) y actualiza `inventario.cantidad_reservada`
8. Si hay items personalizados → crear registro en `produccion`

**Registrar pago (vendedor):**
- Seleccionar orden pendiente del cliente
- Ingresar monto, método, referencia
- El sistema calcula saldo restante automáticamente con `v_saldo_ordenes`
- Si saldo = 0 y todos los items entregados → orden pasa a `entregado`

**Listado de órdenes (vendedor):**
- Solo sus propias órdenes
- Filtros: estado, fecha, tienda
- Ver detalle de cada orden con historial de pagos

### 6.3 Módulo de inventario

**Vista por tienda:**
- Tabla con todos los productos, stock disponible y reservado
- Buscador por nombre/categoría
- Botón "Agregar stock" → registra entrada en `inventario_movimientos`
- Alerta visual si `cantidad_disponible <= stock_minimo`

**Producto más vendido:**
- Query sobre `orden_items` agrupado por `producto_id`, filtrable por tienda y período

### 6.4 Módulo de clientes

- Búsqueda por nombre o cédula
- Historial de órdenes del cliente
- Saldo pendiente total
- Última compra y canal preferido

### 6.5 Módulo de producción (supervisor y vendedor)

- Lista de todos los pedidos personalizados en proceso
- Estado: en proceso / listo / retrasado / entregado
- Días restantes o días de retraso
- Al marcar como retrasado → campo obligatorio `motivo_retraso`
- Alertas automáticas (Laravel Queue) cuando `fecha_compromiso - CURDATE() <= 3 días`

### 6.6 Estadísticas personales del VENDEDOR

**Acceso:** cualquier rol (vendedor y supervisor). El vendedor SOLO ve sus propios datos.

**Filtro de período (siempre presente):**
- Presets rápidos: `Hoy`, `Esta semana`, `Este mes`, `Mes anterior`, `Año actual`, `Personalizado` (date picker desde/hasta)

#### Tarjetas KPI (sección "Mis Estadísticas")
| Métrica | Fórmula | Descripción |
|---------|---------|-------------|
| **Dinero vendido** | `SUM(pagos.monto)` del vendedor en el período | Total real cobrado, no solo valor de órdenes |
| **Órdenes creadas** | `COUNT(ordenes)` del vendedor en el período | Cuántas órdenes generó |
| **Órdenes entregadas** | `COUNT(ordenes WHERE estado='entregado')` | Ventas cerradas exitosamente |
| **Órdenes pendientes** | `COUNT(ordenes WHERE estado NOT IN ('entregado','cancelado'))` | Ventas en proceso |
| **Ticket promedio** | `dinero_vendido / órdenes_entregadas` | Promedio por venta cerrada |
| **Cartera pendiente** | `SUM(ordenes.valor_total - SUM(pagos.monto))` de órdenes no pagadas | Dinero que aún debe cobrar |

#### Gráfica de tendencia
- **Tipo:** línea (Chart.js)
- **Eje X:** días del período seleccionado
- **Eje Y:** dinero vendido acumulativo por día
- Muestra dos líneas: (1) dinero cobrado, (2) valor de órdenes creadas
- Permite ver la diferencia entre lo que vendió y lo que realmente cobró

#### Productos más vendidos (top 5 del vendedor)
- Ranking de los 5 productos que más ha vendido este vendedor en el período
- Cada item muestra: nombre del producto, cantidad vendida, valor total
- Gráfica de barras horizontal

#### Órdenes recientes
- Últimas 5 órdenes creadas por el vendedor
- Cada orden muestra: ID, cliente, estado, valor total, saldo pendiente
- Click → navega a `OrdenDetalleView`

---

### 6.7 Estadísticas y reportes del SUPERVISOR (panel global)

**Acceso:** solo rol `supervisor`. Ve TODOS los datos de TODOS los vendedores y TODAS las tiendas.

**Filtros globales (aplicables a TODAS las secciones):**
- Período con presets rápidos: `Hoy`, `Esta semana`, `Este mes`, `Mes anterior`, `Año actual`, `Personalizado` (date picker desde/hasta)
- Filtro por tienda: `Todas` (default) o una tienda específica

---

#### A) Panel de Resumen Global (Dashboard financiero)

Tarjetas KPI principales del período + tienda seleccionados:

| Métrica | Fórmula | Descripción |
|---------|---------|-------------|
| **Ingresos totales** | `SUM(pagos.monto)` de TODAS las tiendas | Dinero REAL ingresado en caja |
| **Órdenes totales** | `COUNT(ordenes)` | Todas las órdenes del período |
| **Órdenes entregadas** | `COUNT(ordenes WHERE estado='entregado')` | Ventas cerradas |
| **Cartera pendiente** | `SUM(saldo_pendiente)` de órdenes no pagadas | Dinero por cobrar |
| **Ticket promedio** | `ingresos / órdenes_entregadas` | Promedio por venta cerrada |
| **Órdenes canceladas** | `COUNT(ordenes WHERE estado='cancelado')` | Ventas perdidas |
| **Comparativa** | vs período anterior | % de cambio (↑ verde si subió, ↓ rojo si bajó) |

**Gráfica principal:**
- **Tipo:** línea
- **Eje X:** días del período
- **Eje Y:** dinero ingresado por día
- Permite alternar entre vista "diaria", "semanal" y "mensual"
- Muestra también una línea del período anterior para comparar

---

#### B) Estadísticas por Tienda

**Objetivo:** ver cuánto genera cada tienda, comparar rendimiento.

**Tarjetas por tienda (grid de cards):**
- Nombre de la tienda + ciudad
- Dinero vendido en el período
- Número de órdenes (entregadas + pendientes)
- Ticket promedio de esa tienda
- Vendedor destacado (el que más vendió en esa tienda)

**Gráfica:**
- **Tipo:** barras
- **Eje X:** nombre de cada tienda
- **Eje Y:** dinero vendido
- Colores diferentes por tienda

**Click en una tienda → Drill-down:**
- Muestra las mismas estadísticas pero filtradas solo a esa tienda
- Lista de vendedores de esa tienda con su ranking
- Top productos de esa tienda
- Órdenes recientes de esa tienda

**Comparativa de tiendas:**
- Tabla con todas las tiendas lado a lado:
  | Tienda | Ingresos | Órdenes | Entregadas | Canceladas | Ticket promedio |
  |--------|----------|---------|------------|------------|----------------|

---

#### C) Estadísticas por Vendedor

**Ranking general:**
- Tabla ordenada por dinero vendido (descendente)
- Columnas: nombre, tienda, dinero vendido, órdenes, entregadas, canceladas, ticket promedio, cartera pendiente
- Badge de posición: 🥇 🥈 🥉 para los top 3

**Gráfica:**
- **Tipo:** barras horizontales
- Eje Y: nombre del vendedor
- Eje X: dinero vendido
- Cada barra tiene el color de la tienda del vendedor

**Click en un vendedor → Perfil individual:**
- Misma vista que el vendedor ve en sus estadísticas personales (sección 6.6) pero desde la perspectiva del supervisor
- Además muestra:
  - Comparativa del vendedor contra el promedio del equipo
  - % que representa del total de la empresa
  - Distribución de sus ventas por canal (física, whatsapp, red social)
  - Historial completo de órdenes filtrable

---

#### D) Productos más vendidos

**Dos tabs:**
1. **Por cantidad:** productos que más se vendieron en número de unidades
2. **Por valor:** productos que generaron más dinero

**Filtros adicionales:**
- Por tienda: muestra top solo de esa tienda
- Por categoría: filtra por categoría de producto

**Tabla de resultados:**
| # | Producto | Categoría | Cantidad | Valor total | Precio promedio |
|---|----------|-----------|----------|-------------|----------------|

**Gráfica:**
- **Tipo:** dona
- Muestra distribución del valor vendido por categoría de producto

---

#### E) Cartera pendiente (cuentas por cobrar)

- Total de dinero pendiente de cobro de TODAS las órdenes no pagadas completamente
- Desglose por orden:
  | Orden | Cliente | Vendedor | Tienda | Valor total | Pagado | Saldo | Días sin pagar |
- Ordenable por: saldo mayor, días más antiguo, vendedor, tienda
- Filtro por vendedor y tienda
- Alerta visual: rojo si >15 días sin pagar, naranja 7-15 días, amarillo <7 días

---

#### F) Retrasos de producción

- Vista `v_retrasos` con días de retraso
- Alertas visuales por severidad:
  - Rojo: >7 días de retraso
  - Naranja: 3-7 días
  - Amarillo: <3 días
- Por cada retraso: cliente, teléfono, producto, vendedor, tienda, días de retraso, motivo

---

#### G) Exportar a Excel

- Cualquier sección tiene botón "Exportar" que descarga un `.xlsx` con los datos filtrados actualmente
- Usar Laravel Excel (`maatwebsite/excel`)
- Nombre del archivo: `decasa_reporte_{tipo}_{fecha_inicio}_{fecha_fin}.xlsx`

---

### 6.8 Guías visuales (aplicar a todos los módulos)

- **Sombras:** usar sombras consistentes en todas las tarjetas (`shadow-sm` como base, `shadow-md` en hover)
- **Cards:** fondo blanco, bordes redondeados (`rounded-xl` o `rounded-2xl`), padding generoso (`p-4` o `p-5`)
- **Separación:** las secciones dentro de una vista deben estar claramente separadas con espacio (`space-y-4`)
- **KPI cards:** números grandes y prominentes, label pequeño debajo, icono o color sutil de fondo
- **Colores de estado:** mismos badges en todo el sistema (amarillo pendiente, azul en_produccion, verde listo/entregado, rojo cancelado)
- **Navegación:** bottom tab bar en móvil, header sticky con nombre de la sección y botón de logout
- **Gráficas:** fondo blanco, bordes suaves, leyenda clara, tooltips al hover/touch

### 6.9 Módulo de Despacho 🚚

**Ícono:** camión/carro de reparto
**Acceso:** supervisor (gestión) + conductor (ejecución de entregas). Vendedores NO ven este módulo.

---

#### Vista del SUPERVISOR — Cola de Despacho

**Pantalla principal:**
- Lista de todas las órdenes en estado `listo_entrega` **en orden cronológico** (la que llegó primero a `listo_entrega` aparece arriba, según `ordenes.listo_entrega_at`)
- Cada card muestra: ID de orden, nombre del cliente, dirección, valor total, saldo pendiente, fecha en que quedó lista
- Las órdenes en estado `en_despacho` (ya asignadas) aparecen en una sección separada más abajo con badge de conductor asignado
- El supervisor **NO puede cambiar el estado** de ninguna orden desde aquí (salvo asignar)

**Flujo de asignación:**
1. Supervisor toca/hace clic en las órdenes que quiere asignar — se marcan con un número que indica el orden de entrega (1, 2, 3…)
2. El orden de selección determina el orden de entrega: la primera que se toca se entrega de primera
3. No es obligatorio seleccionar todas; puede asignar una o varias
4. Al tener al menos una orden seleccionada aparece el botón **"Asignar"**
5. Al pulsar "Asignar" se abre un bottom sheet / modal con la lista de conductores activos disponibles (rol `conductor`, `activo = true`)
6. Supervisor selecciona un conductor y confirma
7. Se crea un registro en `despachos` y uno en `despacho_items` por cada orden seleccionada con su `posicion`
8. Las órdenes seleccionadas pasan a estado `en_despacho`
9. El conductor recibe una **notificación push/WebSocket** en tiempo real con las nuevas entregas

**Sección de historial:**
- Tab "Completados" con despachos finalizados, filtrable por fecha y conductor
- Click en un despacho → ver sus ítems con las fotos y el estado

---

#### Vista del CONDUCTOR — Mis Entregas

**Pantalla principal:**
- Lista de sus entregas asignadas en el **orden exacto** que definió el supervisor (ordenadas por `despacho_items.posicion`)
- Cada card muestra: número de posición, nombre del cliente, dirección de entrega, valor total, saldo pendiente
- Badge de estado: `pendiente` (naranja) / `entregado` (verde)
- Solo ve las órdenes de sus despachos activos (estado `asignado` o `en_ruta`)

**Flujo de entrega por orden:**
1. Conductor toca la orden activa para abrir el detalle
2. Ve la información completa de la orden: cliente, teléfono, dirección, productos, valor total, saldo pendiente
3. Sección **"Registrar Pago"**:
   - Campo monto (pre-llenado con saldo pendiente, editable)
   - Campo método de pago (`efectivo`, `transferencia`, `tarjeta`, `otro`)
   - Campo referencia (opcional)
   - **Upload prueba de entrega:** botón para subir/tomar foto del producto en casa del cliente (`foto_producto`)
   - **Upload prueba de pago:** botón para subir/tomar foto del comprobante (`foto_pago`)
4. Cuando los 2 archivos están subidos y el pago registrado → se desbloquea el botón **"Entregado"** (antes está deshabilitado con tooltip explicativo)
5. Conductor pulsa **"Entregado"**:
   - `despacho_items.estado` → `entregado`, se guarda `entregado_at`
   - `ordenes.estado` → `entregado`
   - Se crea registro en `pagos` con el monto registrado
   - El supervisor recibe notificación WebSocket: _"Orden #123 de [Cliente] fue entregada por [Conductor]"_
6. La orden desaparece de la lista activa del conductor y el siguiente item sube al tope

**Restricciones del conductor:**
- Solo puede ver SUS entregas asignadas, no las de otros conductores
- No puede reordenar las entregas (el orden lo define el supervisor)
- No puede cancelar una entrega desde la app
- Solo puede marcar como entregado después de subir las 2 fotos y registrar el pago

---

#### Vista de detalle de orden post-entrega (supervisor y vendedor)

Cuando una orden ya fue entregada por un conductor, en `OrdenDetalleView` se muestra una sección adicional **"Pruebas de Entrega"** con:
- Foto del producto en casa del cliente (preview + botón para ampliar)
- Foto del comprobante de pago (preview + botón para ampliar)
- Nombre del conductor que realizó la entrega
- Fecha y hora exacta de entrega
- La barra de progreso de pago al 100%

---

#### WebSocket — Eventos en tiempo real

| Evento | Canal | Emisor | Receptor |
|--------|-------|--------|----------|
| `orden.lista_entrega` | `despacho` | Sistema (al cambiar estado) | Supervisor (aparece en cola de despacho sin recargar) |
| `despacho.asignado` | `conductor.{id}` | Sistema (al asignar) | Conductor (recibe sus nuevas entregas) |
| `orden.entregada` | `supervisor` | Sistema (al marcar entregado) | Supervisor (notificación + badge en módulo) |

**Tecnología:** Laravel Reverb (WebSocket nativo de Laravel) + Vue composable `useDespachoSocket.js`

---

#### Reglas de UI del módulo de Despacho

- **Ícono en la barra de navegación:** camión (`🚚` o SVG de camión/truck) — visible solo para supervisor y conductor
- **Badge de notificación** en el ícono cuando hay órdenes nuevas en `listo_entrega` (supervisor) o entregas asignadas nuevas (conductor)
- **Selección de órdenes:** al tocar una orden en la cola aparece un círculo numerado en la esquina superior derecha del card con el número de prioridad de entrega
- **Botón "Entregado" deshabilitado** visualmente diferente: gris con candado hasta que se cumplan los requisitos
- **Uploads de fotos:** usar `<input type="file" accept="image/*" capture="environment">` para abrir la cámara directamente en móvil
- Los archivos se suben al backend y se guardan en storage (Laravel `storage/app/public/entregas/`) con URL pública

### 6.10 Módulo de Surtir — Abastecimiento de Tiendas 📦

**Ícono:** caja / package
**Acceso:** supervisor (crear y ver historial) + vendedor (validar recepciones pendientes). Conductores NO ven este módulo.

---

#### Vista del SUPERVISOR — Crear Surtido

Flujo en 4 pasos (wizard):

**Paso 1 — Productos a enviar**
- Buscador de productos del catálogo (igual al de NuevaOrdenView)
- Para cada producto seleccionado:
  - Campo cantidad (número entero > 0)
  - Campos de especificaciones opcionales (colapsables): tela, color, medidas, acabado → se guardan como JSON en `surtido_items.especificaciones`
  - Botón para eliminar el ítem de la lista
- Tabla resumen de los productos añadidos

**Paso 2 — Tiendas destino**
- Lista de todas las tiendas activas con checkboxes
- Checkbox "Seleccionar todas las tiendas" en la parte superior
- Toggle: **"Misma cantidad para todas las tiendas seleccionadas"** (activado por defecto)
  - Si está activado: los productos y cantidades del Paso 1 se aplican idénticos a todas las tiendas seleccionadas
  - Si se desactiva: aparece una sección por tienda donde se pueden ajustar las cantidades individualmente (mismos productos, distinta cantidad por tienda)

**Paso 3 — Asignar vendedor validador por tienda**
- Para cada tienda seleccionada en el Paso 2, mostrar un dropdown con los vendedores activos de esa tienda
- Obligatorio seleccionar uno por tienda
- Es el vendedor que recibirá la notificación y confirmará la llegada del producto

**Paso 4 — Revisión y envío**
- Resumen completo: productos + cantidades + especificaciones, por cada tienda destino, con su vendedor validador
- Campo "Notas generales" (opcional)
- Botón **"Enviar Surtido"**
- Al confirmar: se crean los registros en `surtidos`, `surtido_tiendas` y `surtido_items`, y se emite el evento WebSocket `SurtidoEnviado` a cada vendedor validador

---

#### Vista del SUPERVISOR — Historial de Surtidos

- Tab "Historial" dentro del módulo Surtir
- Lista cronológica descendente de todos los surtidos creados
- Cada fila: fecha, N° de productos, N° de tiendas, estado global con badge de color
  - `enviado` → amarillo (hay tiendas pendientes)
  - `completado` → verde (todas aceptaron)
  - `rechazado_parcial` → rojo (alguna tienda rechazó)
- Click en un surtido → panel de detalle:
  - Lista de productos enviados con especificaciones
  - Por cada tienda: nombre, vendedor asignado, estado, notas del vendedor, fecha de respuesta
  - Badge de estado por tienda: Pendiente / Aceptado / Rechazado
- Supervisor recibe notificación WebSocket en tiempo real cuando una tienda acepta o rechaza

---

#### Vista del VENDEDOR — Validación de Surtidos

- Badge con contador de pendientes en el ícono de Inventario en la barra de navegación
- Dentro de `InventarioView` se añade un tab o panel **"Surtidos Pendientes"** visible solo cuando hay items pendientes
- Cada surtido pendiente muestra:
  - Fecha de envío y nombre del supervisor que lo creó
  - Lista completa de productos: nombre, cantidad, especificaciones (telas, color, medidas, etc.)
  - Botón **"Aceptar todo"** → confirma que los productos llegaron correctamente
  - Botón **"Rechazar"** (secundario) → abre un campo de texto para ingresar el motivo, luego confirma el rechazo

**Al hacer "Aceptar todo":**
1. Backend incrementa `inventario.cantidad_disponible` para cada producto de ese surtido en la tienda del vendedor
2. Crea una entrada en `inventario_movimientos` por cada producto (tipo: `entrada`, motivo: `Surtido #N`, usuario_id: vendedor)
3. Si no existe el registro en `inventario` para un producto de esa tienda, lo crea con esa cantidad
4. Actualiza `surtido_tiendas.estado` → `aceptado`
5. Si todas las tiendas del surtido ya respondieron → actualiza `surtidos.estado` → `completado` o `rechazado_parcial`
6. El supervisor recibe notificación WebSocket con el nombre de la tienda y el nombre del vendedor que aceptó
7. El panel de surtidos pendientes del vendedor se actualiza en tiempo real (desaparece el ítem aceptado)

**Al "Rechazar":**
- El inventario NO se modifica
- Se guarda el motivo en `surtido_tiendas.notas_vendedor`
- El supervisor recibe notificación de rechazo con el motivo
- El supervisor puede crear un nuevo surtido para reenviar (no hay reenvío automático)

---

#### WebSocket — Eventos del módulo Surtir

| Evento | Canal | Emisor | Receptor |
|--------|-------|--------|----------|
| `SurtidoEnviado` | `vendedor.{id}` | Sistema (al crear el surtido) | Vendedor(es) validadores (notificación de productos en camino) |
| `SurtidoAceptado` | `supervisor.{id}` | Sistema (al aceptar) | Supervisor (badge + toast: "Tienda X aceptó el surtido #N") |
| `SurtidoRechazado` | `supervisor.{id}` | Sistema (al rechazar) | Supervisor (alerta roja: "Tienda X rechazó el surtido #N — motivo: ...") |

---

#### Reglas de UI del módulo Surtir

- El wizard de creación tiene un stepper visual (1 → 2 → 3 → 4) con validación antes de avanzar al siguiente paso
- El toggle "Misma cantidad para todas las tiendas" está activo por defecto; al desactivarlo se expande una sección por tienda con los mismos productos pero cantidades editables
- Las especificaciones de cada producto se muestran como chips/tags compactos en la lista de revisión
- El botón "Aceptar todo" del vendedor es prominente (color primario); el botón "Rechazar" es secundario/outline para evitar toques accidentales
- En el historial del supervisor, los surtidos con `rechazado_parcial` muestran un ícono de alerta con tooltip indicando cuántas tiendas rechazaron

---

## 7. API REST — Endpoints principales

### Auth
```
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me
```

### Tiendas
```
GET    /api/tiendas
```

### Productos
```
GET    /api/productos?search=silla&tienda_id=1
GET    /api/productos/{id}
```

### Clientes
```
GET    /api/clientes?search=juan
POST   /api/clientes
GET    /api/clientes/{id}
GET    /api/clientes/{id}/ordenes
```

### Órdenes
```
GET    /api/ordenes                    (vendedor: solo las suyas | supervisor: todas)
POST   /api/ordenes
GET    /api/ordenes/{id}
PATCH  /api/ordenes/{id}/estado
```

### Pagos
```
POST   /api/ordenes/{id}/pagos
GET    /api/ordenes/{id}/pagos
```

### Inventario
```
GET    /api/inventario?tienda_id=1
POST   /api/inventario/entrada         (agregar stock manual — supervisor)

-- Módulo Surtir (supervisor)
POST   /api/inventario/surtir                             (crear surtido — body: {notas, tiendas:[{tienda_id, vendedor_validador_id, items:[{producto_id, cantidad, especificaciones}]}]})
GET    /api/inventario/surtidos                           (historial de surtidos — supervisor)
GET    /api/inventario/surtidos/{id}                      (detalle de un surtido con estado por tienda)
GET    /api/inventario/vendedores-tienda/{tienda_id}      (lista de vendedores activos de una tienda — para el selector del supervisor)

-- Módulo Surtir (vendedor)
GET    /api/inventario/surtidos/pendientes                (surtidos pendientes de validación para el vendedor autenticado)
PATCH  /api/inventario/surtido-tiendas/{id}/aceptar       (vendedor acepta — actualiza inventario + movimientos)
PATCH  /api/inventario/surtido-tiendas/{id}/rechazar      (vendedor rechaza — body: {notas_vendedor})
```

### Producción
```
GET    /api/produccion
PATCH  /api/produccion/{id}
```

### Despacho
```
-- Cola de despacho (supervisor)
GET    /api/despacho/cola                            (órdenes en listo_entrega ordenadas por listo_entrega_at)
GET    /api/despacho/asignados                       (órdenes en en_despacho con conductor asignado)
POST   /api/despacho/asignar                         (body: {conductor_id, ordenes: [{orden_id, posicion}]})
GET    /api/despacho/conductores                     (lista de conductores activos para el selector)

-- Historial de despachos (supervisor)
GET    /api/despacho/historial?desde=&hasta=&conductor_id=
GET    /api/despacho/{id}                            (detalle de un despacho con sus items)

-- Entregas del conductor (conductor autenticado)
GET    /api/despacho/mis-entregas                    (despacho_items activos del conductor, ordenados por posicion)
GET    /api/despacho/mis-entregas/{despacho_item_id} (detalle de una entrega)
POST   /api/despacho/mis-entregas/{despacho_item_id}/pago   (registrar pago — multipart: monto, metodo, referencia, foto_producto, foto_pago)
PATCH  /api/despacho/mis-entregas/{despacho_item_id}/entregar  (marcar como entregado — requiere pago + fotos previos)
```

### Reportes y Estadísticas

**Solo supervisor:**
```
GET    /api/reportes/ventas?desde=&hasta=&tienda_id=
GET    /api/reportes/vendedores?desde=&hasta=
GET    /api/reportes/productos-top?tienda_id=&limit=10
GET    /api/reportes/pendientes
GET    /api/reportes/retrasos
GET    /api/reportes/exportar?tipo=ventas&desde=&hasta=   (descarga Excel)
```

**Ambos roles (vendedor ve solo lo suyo, supervisor ve todo):**
```
GET    /api/stats/panel?periodo=hoy|semana|mes&desde=&hasta=&tienda_id=    (KPIs principales)
GET    /api/stats/tiendas?desde=&hasta=                                     (desglose por tienda)
GET    /api/stats/vendedor/{id}?desde=&hasta=                               (perfil individual)
GET    /api/stats/vendedores/me?desde=&hasta=                               (stats personales)
GET    /api/stats/cartera?desde=&hasta=&tienda_id=                          (cuentas por cobrar)
GET    /api/stats/tendencia?desde=&hasta=&agrupado=dia|semana|mes&tienda_id= (datos para gráfica de línea)
GET    /api/stats/productos?desde=&hasta=&tienda_id=&tipo=cantidad|valor    (top productos)
```

---

## 8. Estructura de carpetas

### Backend (Laravel)
```
decasa-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── OrdenController.php
│   │   │   ├── PagoController.php
│   │   │   ├── ClienteController.php
│   │   │   ├── ProductoController.php
│   │   │   ├── InventarioController.php
│   │   │   ├── SurtidoController.php
│   │   │   ├── ProduccionController.php
│   │   │   ├── DespachoController.php
│   │   │   └── ReporteController.php
│   │   └── Middleware/
│   │       └── CheckRole.php
│   ├── Models/
│   │   ├── Tienda.php
│   │   ├── Usuario.php
│   │   ├── Cliente.php
│   │   ├── Producto.php
│   │   ├── Inventario.php
│   │   ├── InventarioMovimiento.php
│   │   ├── Orden.php
│   │   ├── OrdenItem.php
│   │   ├── Pago.php
│   │   ├── Produccion.php
│   │   ├── Despacho.php
│   │   ├── DespachoItem.php
│   │   ├── Surtido.php
│   │   ├── SurtidoTienda.php
│   │   └── SurtidoItem.php
│   ├── Jobs/
│   │   └── AlertarRetrasoProduccion.php
│   ├── Events/
│   │   ├── OrdenListaParaEntrega.php    (WebSocket: nueva orden en cola de despacho)
│   │   ├── DespachoAsignado.php         (WebSocket: conductor recibe nuevo despacho)
│   │   ├── OrdenEntregada.php           (WebSocket: supervisor recibe notificación de entrega)
│   │   ├── SurtidoEnviado.php           (WebSocket: vendedor recibe surtido pendiente)
│   │   ├── SurtidoAceptado.php          (WebSocket: supervisor notificado de aceptación)
│   │   └── SurtidoRechazado.php         (WebSocket: supervisor notificado de rechazo)
│   └── Broadcasting/
│       └── (canales configurados en BroadcastServiceProvider)
│   └── Exports/
│       └── ReporteExport.php
├── database/
│   └── migrations/
│       └── (una migration por tabla)
└── routes/
    └── api.php
```

### Frontend (Vue 3)
```
decasa-app/
├── public/
│   ├── manifest.json          (PWA)
│   └── sw.js                  (Service Worker)
├── src/
│   ├── api/                   (funciones axios por módulo)
│   │   ├── auth.js
│   │   ├── ordenes.js
│   │   ├── productos.js
│   │   ├── clientes.js
│   │   ├── inventario.js
│   │   ├── surtidos.js
│   │   ├── produccion.js
│   │   ├── despacho.js
│   │   └── reportes.js
│   ├── components/
│   │   ├── common/            (botones, inputs, tablas reutilizables)
│   │   │   ├── BadgeEstado.vue
│   │   │   ├── MoneyDisplay.vue
│   │   │   └── EmptyState.vue
│   │   ├── ordenes/
│   │   │   └── RegistroPagoModal.vue
│   │   ├── despacho/
│   │   │   ├── ColaConductoresModal.vue   (selector de conductor al asignar)
│   │   │   ├── DespachoCard.vue           (card de orden en la cola)
│   │   │   └── EntregaDetalleModal.vue    (detalle + registro de pago + uploads para conductor)
│   │   ├── inventario/
│   │   │   ├── SurtidosPendientesPanel.vue  (panel del vendedor para validar surtidos)
│   │   │   └── CrearSurtidoWizard.vue       (wizard 4 pasos para el supervisor)
│   │   ├── clientes/
│   │   └── reportes/
│   ├── views/
│   │   ├── LoginView.vue
│   │   ├── DashboardView.vue
│   │   ├── OrdenesView.vue
│   │   ├── OrdenDetalleView.vue
│   │   ├── NuevaOrdenView.vue
│   │   ├── ClientesView.vue
│   │   ├── InventarioView.vue
│   │   ├── ProduccionView.vue
│   │   ├── DespachoView.vue       (supervisor: cola de despacho + asignación)
│   │   ├── MisEntregasView.vue    (conductor: lista de sus entregas asignadas)
│   │   ├── SurtirView.vue         (supervisor: wizard de creación + tab historial)
│   │   ├── ReportesView.vue       (solo supervisor - estadísticas globales)
│   │   └── StatsVendedorView.vue  (estadísticas personales del vendedor)
│   ├── router/
│   │   └── index.js           (rutas con guards por rol)
│   ├── stores/
│   │   ├── auth.js            (Pinia)
│   │   ├── tiendas.js
│   │   ├── despacho.js        (cola en tiempo real, estado de asignaciones)
│   │   └── surtidos.js        (surtidos pendientes del vendedor, notificaciones)
│   ├── composables/
│   │   ├── useDespachoSocket.js   (WebSocket: suscripción a canales de despacho)
│   │   └── useSurtidosSocket.js   (WebSocket: notificaciones de surtidos enviados/aceptados)
│   └── main.js
└── vite.config.js
```

---

## 9. Reglas de negocio críticas

1. **Reserva de inventario:** Al crear una orden con productos de stock, `inventario.cantidad_reservada` aumenta. Solo se descuenta de `cantidad_disponible` al marcar la orden como `entregado`. Si se cancela, liberar la reserva.

2. **Anticipo obligatorio:** No se puede guardar una orden sin registrar al menos el anticipo del 50%.

3. **Producción automática:** Si un `orden_item` tiene `es_personalizado = TRUE`, automáticamente crear un registro en `produccion` con `fecha_inicio = hoy` y `fecha_compromiso = hoy + 30 días`.

4. **Alerta de retraso:** Laravel Queue debe correr diariamente y marcar como `retrasado` cualquier producción cuya `fecha_compromiso < CURDATE()` y estado sea `en_proceso`.

5. **Rol en respuesta de API:** El frontend usa el campo `rol` del usuario para mostrar/ocultar secciones. El backend valida el rol en cada endpoint protegido.

6. **Búsqueda de productos:** Usar `LIKE %término%` sobre `nombre` y `categoria`. Mostrar siempre el stock de la tienda seleccionada en la orden.

7. **Cambio de tienda en orden:** El vendedor puede cambiar la tienda activa antes de confirmar la orden. El stock a verificar es el de la tienda seleccionada, no la predeterminada.

8. **Bloqueo de estado en Despacho:** Una vez que una orden está en `listo_entrega` o `en_despacho`, ningún endpoint que no sea `/api/despacho/*` puede modificar su estado. El `OrdenController@updateEstado` debe rechazar con 403 cualquier intento de cambiar el estado de una orden que ya esté en esos dos estados.

9. **Solo el conductor puede marcar como entregado:** El endpoint `PATCH /api/despacho/mis-entregas/{id}/entregar` valida que el usuario autenticado sea el conductor asignado a ese despacho. Un supervisor no puede llamar este endpoint.

10. **Prerrequisitos para marcar entregado:** Antes de permitir el cambio a `entregado`, el backend valida que el `despacho_item` tenga `foto_producto` y `foto_pago` con URL no nula y que exista al menos un pago registrado para esa orden después de la fecha de asignación del despacho.

11. **WebSocket obligatorio para Despacho:** Los tres eventos (`OrdenListaParaEntrega`, `DespachoAsignado`, `OrdenEntregada`) se emiten usando Laravel Reverb (Broadcasting). El frontend se suscribe a los canales correspondientes al montar `DespachoView` y `MisEntregasView`. No se usa polling.

12. **`listo_entrega_at`:** Al hacer `PATCH /api/ordenes/{id}/estado` con `estado = listo_entrega`, el backend debe registrar automáticamente `listo_entrega_at = NOW()` en la orden. Este campo no es editable desde el frontend.

13. **Orden de posición en despacho:** La `posicion` en `despacho_items` es inmutable después de la asignación. No se puede reordenar una vez asignado; si el supervisor quiere otro orden debe cancelar el despacho (acción de supervisor, a definir en v2) y re-asignar.

14. **Solo el supervisor crea surtidos:** El endpoint `POST /api/inventario/surtir` rechaza con 403 cualquier usuario que no sea `supervisor`.

15. **Inventario solo se actualiza al aceptar:** La creación del surtido (POST) no modifica `inventario` en ningún campo. Solo el endpoint de aceptación del vendedor (`PATCH .../aceptar`) incrementa `cantidad_disponible` y registra en `inventario_movimientos`.

16. **Auditoría de inventario por surtido:** Cada `surtido_item` aceptado genera exactamente un registro en `inventario_movimientos` con `tipo = 'entrada'`, `motivo = 'Surtido #' + surtido_id`, `usuario_id` del vendedor que aceptó, y la `tienda_id` correspondiente. Esto garantiza trazabilidad completa del origen de cada incremento de stock.

17. **Creación automática de registro de inventario:** Si al momento de aceptar no existe un registro en `inventario` para el par `(producto_id, tienda_id)`, crearlo con `cantidad_disponible = cantidad del surtido_item` y `cantidad_reservada = 0`.

18. **Un surtido rechazado no bloquea reenvíos:** El supervisor puede crear un nuevo surtido para la misma tienda con los mismos productos inmediatamente después de un rechazo. No hay restricción de tiempo ni de duplicados entre surtidos.

19. **El vendedor solo valida surtidos asignados a él:** El backend verifica que `surtido_tiendas.vendedor_validador_id = usuario_autenticado.id`. Un vendedor de tienda A no puede aceptar un surtido de tienda B aunque ambas sean del sistema.

20. **Estado global del surtido se calcula automáticamente:** Al aceptar o rechazar una `surtido_tienda`, el backend recalcula `surtidos.estado`:
    - Si todas las `surtido_tiendas` están en `aceptado` → `surtidos.estado = 'completado'`
    - Si al menos una está en `rechazado` y ninguna en `pendiente` → `surtidos.estado = 'rechazado_parcial'`
    - Si alguna sigue en `pendiente` → `surtidos.estado` permanece en `enviado`

---

## 10. Por dónde empezar

**Orden de desarrollo recomendado:**

1. `laravel new decasa-api` → configurar `.env` con BD `decasa_system`
2. Instalar: `sanctum`, `maatwebsite/excel`, `spatie/laravel-query-builder`
3. Crear migrations y ejecutarlas
4. Correr script de migración de productos desde `decasa_whatsapp`
5. Crear modelos con relaciones Eloquent
6. Crear `AuthController` + login/logout + middleware de roles
7. Crear `ProductoController` con búsqueda
8. Crear `OrdenController` con lógica de reserva de inventario
9. Crear `PagoController`
10. `npm create vue@latest decasa-app` → instalar Tailwind + Pinia + Vue Router + Axios
11. Crear store de auth con Pinia (login, token, rol)
12. Crear router con guards (si no autenticado → login; si vendedor → sin reportes)
13. Construir `NuevaOrdenView` (el flujo más complejo del frontend)
14. Construir `OrdenesView` con filtros, scroll infinito, vista de detalle y registro de pagos
15. Construir `InventarioView` y `ProduccionView`
16. Construir `ClientesView` con vista de detalle
17. Construir `ReportesView` con Chart.js (solo supervisor) — panel de estadísticas global
18. Construir `StatsVendedorView` — estadísticas personales del vendedor
19. Crear nuevos endpoints API de estadísticas (`/api/stats/*`) en el backend
20. Configurar PWA (manifest.json + service worker)
21. Refinar UI: sombras consistentes, transiciones, estados de carga, toasts de error

**Módulo de Despacho (añadir después de los pasos anteriores):**

22. Instalar Laravel Reverb (`php artisan install:broadcasting`) y configurar WebSocket
23. Crear migrations para `despachos` y `despacho_items`; modificar `ordenes` (agregar estado `en_despacho`, columna `listo_entrega_at`) y `usuarios` (agregar valor `conductor` al ENUM `rol`)
24. Crear modelos `Despacho` y `DespachoItem` con relaciones Eloquent
25. Crear eventos Broadcasting: `OrdenListaParaEntrega`, `DespachoAsignado`, `OrdenEntregada`
26. Crear `DespachoController` con todos los endpoints de la sección 7 (despacho)
27. Modificar `OrdenController@updateEstado`: emitir evento `OrdenListaParaEntrega` cuando el estado cambie a `listo_entrega`; bloquear cambios de estado si la orden ya está en `listo_entrega` o `en_despacho`
28. Configurar storage para uploads de fotos (`storage/app/public/entregas/`) y ruta pública
29. Construir `DespachoView.vue` (supervisor): cola con selección numerada + modal de conductores + sección de asignados
30. Construir `MisEntregasView.vue` (conductor): lista ordenada por posición + flujo de entrega con uploads
31. Construir `EntregaDetalleModal.vue`: registro de pago + 2 uploads de fotos + botón entregado condicional
32. Crear composable `useDespachoSocket.js` y store `despacho.js` para manejar eventos WebSocket en tiempo real
33. Añadir ruta de Despacho al router con guard de roles (solo `supervisor` y `conductor`)
34. Añadir ícono de camión a la barra de navegación con badge de contador visible para supervisor y conductor
35. Actualizar `OrdenDetalleView.vue`: agregar sección "Pruebas de Entrega" cuando la orden está en estado `entregado` y proviene de un despacho

**Módulo Surtir (añadir después del módulo de Despacho):**

36. Crear migrations para `surtidos`, `surtido_tiendas` y `surtido_items`
37. Crear modelos `Surtido`, `SurtidoTienda`, `SurtidoItem` con relaciones Eloquent (surtido → hasManyThrough surtido_tiendas → hasMany surtido_items)
38. Crear eventos Broadcasting: `SurtidoEnviado`, `SurtidoAceptado`, `SurtidoRechazado`
39. Crear `SurtidoController` con los 7 endpoints del módulo (ver sección 7)
40. Añadir lógica en `SurtidoController@aceptar`: iterar items → upsert `inventario` → insertar `inventario_movimientos` → recalcular estado del surtido → emitir evento
41. Construir `SurtirView.vue` (supervisor): stepper de 4 pasos + tab de historial con detalle por tienda
42. Construir `CrearSurtidoWizard.vue`: paso 1 productos, paso 2 tiendas + toggle de cantidades, paso 3 validadores, paso 4 revisión
43. Construir `SurtidosPendientesPanel.vue` (vendedor): lista de surtidos con botón "Aceptar todo" y "Rechazar" + modal de motivo de rechazo
44. Integrar `SurtidosPendientesPanel.vue` como tab/sección dentro de `InventarioView.vue` (visible solo para rol `vendedor`)
45. Crear composable `useSurtidosSocket.js` para manejar los 3 eventos WebSocket del módulo
46. Añadir badge de contador en el ícono de Inventario en la barra de navegación (vendedor: muestra surtidos pendientes de validación)
47. Añadir ruta `/surtir` al router con guard de roles (solo `supervisor`)

---

## 11. Notas adicionales

- **Moneda:** Pesos colombianos (COP). No se necesita conversión.
- **Zona horaria:** `America/Bogota` — configurar en Laravel (`config/app.php`) y MySQL.
- **Imágenes de productos:** Por ahora guardar solo URL en `foto_url`. Las fotos pueden estar en la BD del asistente de WhatsApp o en un bucket S3 futuro.
- **La BD del asistente de WhatsApp NO se modifica.** Solo se lee para migrar productos.
- **El sistema se llama "Decasa" internamente.** No exponer datos de una tienda a vendedores de otra (el supervisor sí ve todo).

------------------------------------------------------


