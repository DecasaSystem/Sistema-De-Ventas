# Sistema de Ventas Decasa — Documentación completa

Análisis del repositorio módulo por módulo y carpeta por carpeta (2026-10-07).
Reglas para agentes: [`../AGENT.md`](../AGENT.md). Contexto y decisiones:
[`../MEMORY.md`](../MEMORY.md).

**Índice**
1. [El negocio](#1-el-negocio)
2. [Arquitectura](#2-arquitectura)
3. [Estructura del repositorio](#3-estructura-del-repositorio)
4. [Backend `decasa-api`](#4-backend-decasa-api)
5. [Frontend `decasa-app`](#5-frontend-decasa-app)
6. [Módulos funcionales](#6-módulos-funcionales)
7. [Usuarios, roles y permisos](#7-usuarios-roles-y-permisos)
8. [Tiempo real, notificaciones y tareas programadas](#8-tiempo-real-notificaciones-y-tareas-programadas)
9. [Base de datos](#9-base-de-datos)
10. [Pruebas](#10-pruebas)
11. [Despliegue e infraestructura](#11-despliegue-e-infraestructura)
12. [Documentos existentes](#12-documentos-existentes)

---

## 1. El negocio

**Decasa** fabrica y vende muebles (salas, camas, comedores, escritorios…) y
hace **restauraciones** (retapizar o arreglar el mueble del cliente).

**Tiendas** (tabla `tiendas`):

| Tienda | Ciudad | Notas |
|---|---|---|
| Decasa Norte | Armenia | `es_fabrica = true` (junto al taller) |
| Decasa Vía El Edén | Armenia | |
| Decasa Vía Jardines | Armenia | |
| Decasa Unicentro Pereira | Pereira | comisión **trimestral** |
| Decasa Circunvalar | Pereira | comisión **trimestral** |
| Bodega Fábrica | Armenia | Reserva de Fábrica (stock terminado) |
| Tienda Virtual | — | ventas digitales |
| Independientes | — | `es_independientes = true`: "sede" de los vendedores independientes; no es punto de venta |

**Personas:** vendedores (asesores) de tienda, vendedores **independientes**
(venden por su cuenta, caja propia, comisión fija), supervisores, conductores,
gente del taller (ebanista, tapicero, costurera, lacador, despachador…), y
trabajadores de fábrica que **no usan el programa** (solo aparecen en Nómina).

**Ciclo de vida de una venta:**

```
cotización ─convertir─▶ borrador ─completar─▶ pendiente_anticipo ─▶ en_produccion ─▶ listo_entrega ─▶ en_camino ─▶ entregado
     (COT-N)           (sin número)          (gasta consecutivo)      (taller)          (producción lista)  (ruta)
                                    pendiente_cotizacion (esperando precio del taller, sin número)
                         cancelado · devuelto (volvió en el camión, espera decisión)
```

---

## 2. Arquitectura

```
 Celular / PC (PWA)                     Vercel                         Render (Docker)                    Aiven
 ┌──────────────────┐  /api/*   ┌──────────────────┐  rewrite  ┌──────────────────────────────┐   ┌─────────┐
 │ decasa-app (Vue) │──────────▶│ estáticos + SPA  │──────────▶│ Apache + Laravel (decasa-api) │──▶│  MySQL  │
 │ Pinia, Echo, SW  │◀── ws ────┼──────────────────┼───────────│ Reverb :8080 (proxy /app)     │   └─────────┘
 └──────────────────┘   push    └──────────────────┘           │ queue:work · schedule:run     │
                                                               └──────┬────────────┬──────────┘
                                                                Cloudinary     OpenAI · Gmail SMTP · Web Push (VAPID)
```

- **Autenticación:** tokens de Sanctum (Bearer, guardados en `localStorage`).
  Login con contraseña o Google (`GOOGLE_CLIENT_ID`). Varias personas pueden
  turnarse una misma sesión ("perfiles alternos", hasta 4).
- **Archivos:** fotos a Cloudinary (`/upload/foto`), PDFs generados al vuelo con DomPDF.
- **Tiempo real:** Laravel Reverb (protocolo Pusher) + `laravel-echo` en la app;
  si no hay Reverb, la app hace polling.
- **Push:** `minishlink/web-push` + service worker `public/sw.js`.
- **IA:** `openai-php/laravel` (`gpt-4o`, `text-embedding-3-small`).

---

## 3. Estructura del repositorio

```
/
├── AGENT.md                 Reglas para agentes (léelo primero)
├── MEMORY.md                Memoria compartida: decisiones, estado, trampas
├── docs/
│   ├── PROYECTO.md          Este documento
│   ├── flujo-de-ramas-git.md
│   ├── plan-cotizador-ia.md        (antes AGENT.md) fases 1-7 del cotizador
│   ├── plan-entregas-parciales.md  fases A-D implementadas
│   └── plan-venta-abonada-a-tienda.md  (histórico; ya implementado)
├── explicacion_modulo_comisiones.txt   explicación de comisiones para negocio
├── render.yaml              Servicio de Render (Docker, variables)
├── .vercelignore            Vercel solo sube decasa-app
├── skills-lock.json, .agents/skills/   skills de agentes (vercel, ui-taste, etc.)
├── decasa-api/              Backend Laravel
└── decasa-app/              Frontend Vue
```

---

## 4. Backend `decasa-api`

Laravel 13 / PHP 8.4. Dependencias clave: `laravel/sanctum`, `laravel/reverb`,
`barryvdh/laravel-dompdf`, `maatwebsite/excel` + `phpoffice/phpspreadsheet`,
`minishlink/web-push`, `openai-php/laravel`, `symfony/brevo-mailer`.

| Carpeta | Contenido |
|---|---|
| `app/Http/Controllers/` | 62 controladores (~25.000 líneas). Los grandes: `OrdenController` (4.4k), `ComisionController` (4.2k), `DespachoController` (2k), `StatsController` (1.7k), `ProduccionController` (1.6k), `InventarioController` (1.4k) |
| `app/Http/Middleware/` | `CheckRole` (`role:`), `CheckPermiso` (`permiso:`), `SecurityHeaders`, `MedirPeticion` (cabecera `Server-Timing` + log `[lenta]` > 1,5 s) |
| `app/Models/` | 76 modelos Eloquent (ver §9) |
| `app/Services/` | Lógica de negocio compartida (ver abajo) |
| `app/Services/Costos/` | Motor del cotizador: `BomBuilder`, `CostoCalculator`, `FichaRetriever`, `SanityChecker`, `FewShotProvider` |
| `app/Support/` | Utilidades: `AnexoGarantiaTexto`, `ConvierteImagenesPdf`, `NoEncontrado`, `NombresParecidos` ("¿quisiste decir…?"), `PdfOrdenUnaHoja`, `StockVariantes`, `CachesDePeticion` (limpia los cachés estáticos antes de cada trabajo de la cola y de cada prueba) |
| `scripts/perf/` | Arnés de rendimiento: esquema real en SQLite + datos sintéticos; cuenta consultas por endpoint, detecta N+1 y consultas repetidas, y guarda respuestas para pruebas diferenciales (ver `docs/plan-rendimiento.md`) |
| `app/Events/` | 14 eventos de broadcast (OrdenActualizada, ProduccionActualizada, NuevaNotificacion, Surtido*, DespachoAsignado…) |
| `app/Jobs/` | `EnviarPush`, alertas diarias, traslados/surtidos programados |
| `app/Console/Commands/` | Comandos artisan de soporte (ver §8) |
| `app/Mail/` + `resources/views/emails/` | Cotización por correo, anexo para firmar |
| `resources/views/pdf/` | `orden`, `cotizacion`, `acta_entrega`, `orden_entrega`, `hoja_ruta`, `surtido`, `anexo_garantia` |
| `routes/api.php` | ~300 rutas (todas las de la app) |
| `routes/console.php` | Agenda de tareas (scheduler) |
| `routes/channels.php` | Canales de Reverb |
| `database/migrations/` | 262 migraciones (2026-05 → 2026-10) |
| `database/seeders/` | Tiendas, usuarios, productos, inventario, salarios/tarifas |
| `tests/` | 83 Feature + 6 Unit (ver §10) |
| `Dockerfile`, `entrypoint.sh` | Imagen de Render (Apache + OPcache + procesos de fondo) |

### Servicios (`app/Services`)

| Servicio | Responsabilidad |
|---|---|
| `AgentService` | Chat de IA con ~25 tools (ventas, inventario, producción, comisiones, fichas…) y `calcularPrecioItem()` (cotizador determinístico) |
| `EntregaService` | **Única puerta para entregar**: cierra una entrega (`DespachoItem` + `EntregaLinea`), baja stock por línea, cierra producción, deja la orden en su estado |
| `NumeracionOrdenes` | Corregir números: convertir a/de FV2 o R y correr consecutivos sin dejar huecos |
| `ComisionIndependientes` | Comisión de independientes (5 % fijo, bolsón de restauraciones, abono a almacén) |
| `AnticiposComision` | Libro de anticipos de comisión y su descuento al pagar |
| `CambiosDePlata` | Detecta qué cambios de una orden mueven plata (requieren aprobación) |
| `DescuentoCondicionadoService` | Descuento que se pierde si se paga con tarjeta/Addi/otro |
| `Cartera` | Órdenes que deben plata (misma regla para pantalla y Excel) |
| `ConsumoTelas` | Apartar / descontar / soltar metros de tela según las ventas |
| `MovimientoTraslado` | Mover stock entre tiendas (con tela y apartados) |
| `ReservaDeposito` | Dejar piezas terminadas en la Reserva de Fábrica |
| `RetornoAlTaller` | Devolver una pieza al taller antes de entregarla (a qué paso, qué se rehace) |
| `NominaLiquidador`, `CicloNomina` | Liquidación de nómina calculada del calendario (hora Bogotá) |
| `RevisionEncargos` | Cuándo le toca revista a cada trabajador |
| `RangoFechas` | Filtros de periodo de reportes, en hora del negocio |
| `AvisoFacturacion`, `AvisoProduccion`, `AvisoTraslado` | Avisos a quien debe enterarse de un cambio |
| `NotificacionService`, `PushService` | Crear notificación + broadcast + push |
| `Cloudinary` | Subir firmas (sin archivo) a Cloudinary |

---

## 5. Frontend `decasa-app`

Vue 3.5 + Vite 8 + Pinia 3 + vue-router 5 + Tailwind 4. Chart.js para gráficas,
ExcelJS/xlsx para exportar, `qrcode`, `dompurify`. PWA (manifest + `public/sw.js`).

| Carpeta | Contenido |
|---|---|
| `src/main.js` | Monta la app, registra el service worker, `AppSpinner` global |
| `src/App.vue` | Layout: barra superior, navegación inferior (favoritos por usuario), campana, chat IA |
| `src/router/index.js` | ~50 rutas con guards por permiso (`meta.requiresX`) |
| `src/api/` | Un módulo por recurso (`ordenes.js`, `inventario.js`, `comisiones` vía `stats.js`…). `index.js` = axios con Bearer, barra de carga global, 401 → login |
| `src/stores/` | `auth` (sesión, perfiles alternos, todos los `puedeX`), `notificaciones`, `modulos` (nombres personalizados), `pasos`, `despacho`, `surtidos`, `consultas`, `appearance` (modo oscuro, tamaño de letra) |
| `src/composables/` | `useRealtime` (Echo), `useToast`, `usePushNotifications`, `usePwaInstall`, `useBorradorLocal` (borradores en el aparato), `useFiltrosRecordados`, `useTelas`, `useTiposProceso`, sockets de despacho/surtidos |
| `src/components/` | Por dominio: `ordenes/`, `despacho/`, `produccion/`, `inventario/`, `costos/`, `comisiones/`, `reportes/`, `gestion/`, `encargos/`, `anexo/`, `common/` (BadgeEstado, InputPesos, MoneyDisplay, ComboInput, IconoPicker…) + `AgentChat`, `BocetoCanvas`, `FirmaCanvas`, `DireccionColombia` |
| `src/utils/` | `pesos`, `descuentos`, `juegos` (venta por juego/pieza), `colaPeticiones`, `comprimirImagen`, `cloudinary`, `exportarExcel`, `excelComisiones`, `whatsapp`, `navegacion` |
| `src/constants/` | `iconos.js`, `specsConfig.js` (especificaciones por categoría de mueble) |
| `src/data/` | `colombia.js` (departamentos/ciudades), `telasCatalogo.js` |
| `src/assets/main.css` | Tailwind + overrides de modo oscuro |

### Pantallas (`src/views`)

| Ruta | Vista | Para qué |
|---|---|---|
| `/` | `DashboardView` | Inicio con accesos según permisos |
| `/ordenes`, `/ordenes/:id`, `/ordenes/nueva`, `/ordenes/eliminadas` | `OrdenesView`, `OrdenDetalleView` (4.3k), `NuevaOrdenView` (6.2k), `OrdenesEliminadasView` | Ventas |
| `/cotizaciones`, `/cotizaciones/:id` | `CotizacionesView`, `CotizacionDetalleView` | Cotizaciones |
| `/clientes`, `/clientes/:id` | `ClientesView`, `ClienteDetalleView` | Clientes |
| `/inventario` | `InventarioView` (4.9k) | Stock por tienda, variantes, telas, descuadres |
| `/surtir` | `SurtirView` | Surtidos de fábrica a tiendas y traslados |
| `/reserva` | `ReservaView` | Reserva de Fábrica |
| `/telas`, `/m/:clave` | `TelasView`, `ModuloView` | Inventario de telas en metros y módulos clonados (Espumas…) |
| `/produccion`, `/mis-pasos` | `ProduccionView`, `EbanistaView` | Taller: tablero y "Mis pasos" |
| `/despacho`, `/mis-entregas` | `DespachoView`, `MisEntregasView` | Rutas, conductores, entregas |
| `/caja` | `CajaView` | Caja de tienda / propia |
| `/comisiones` | `ComisionesView` | Metas, pools, pagos, anticipos, reemplazos |
| `/nomina` | `NominaView` | Sueldos, pagos, ausencias, préstamos, bonos |
| `/finanzas` | `FinanzasView` | Estado de resultados, gastos fijos y variables, flujo de caja, proyecciones, rentabilidad por tienda |
| `/costos`, `/consultas-costo` | `CostosView`, `ConsultasView`, `ConsultaDetalleView` | Fichas, materiales, tarifas, precisión IA; consultas al ebanista |
| `/reportes`, `/mis-stats`, `/stats/vendedor/:id`, `/mis-stats-conductor` | `ReportesView`, `StatsVendedor*`, `StatsConductorView` | Reportes y estadísticas |
| `/facturacion` | `FacturacionView` | Para vendedores con `facturacion` |
| `/redes`, `/redes/metricas` | `RedesView`, `MetricasRedesView` | Bandeja de WhatsApp |
| `/citas` | `CitasView` | Citas con clientes |
| `/proveedores`, `/compras` | `ProveedoresView`, `ComprasView` | Libreta de proveedores y lista de compras |
| `/encargos`, `/encargos/:id` | `EncargosView`, `EncargoTrabajadorView` | Herramientas a cargo y revistas |
| `/usuarios`, `/usuarios/crear`, `/usuarios/:id` | `Usuarios*` | Trabajadores y permisos |
| `/gestion` | `GestionView` | Módulos, herramientas, catálogos visuales, roles, tiendas |
| `/perfil` | `PerfilView` | Cuenta, firma, perfiles alternos |
| `/catalogo/:seccion`, `/c`, `/c/:slug`, `/firmar/:token` | Públicas (sin sesión) | Catálogo para clientes, catálogos visuales, firma del anexo |

---

## 6. Módulos funcionales

### 6.1 Órdenes de venta
`OrdenController`, modelos `Orden`, `OrdenItem`, `OrdenEdicion`, `OrdenMensaje`, `OrdenFijada`.

- **Crear** (`store`, transacción atómica): valida cliente, tienda, canal
  (`fisica, whatsapp, instagram, facebook, pagina, red_social, otro`), ítems
  (de catálogo con variante/opción, personalizados, restauraciones, por juego o
  pieza), anticipo en uno o varios pagos (`efectivo, transferencia, tarjeta, addi, otro`),
  comprobantes (varias fotos), firma, anexo de garantía, descuento comercial y
  condicionado, venta compartida (`covendedor_id`), FV2, abono a tienda
  (`tienda_abonada_id`), entrega inmediata por ítem. Reserva stock, crea
  producción para lo que se fabrica, crea comisiones, aparta tela.
  Protegida contra doble envío (`clave_envio`).
- **Numeración:** consecutivo por grupo (`armenia` / `pereira`, tabla
  `orden_secuencias` con `lockForUpdate`). Series especiales: **FV2** (descuento
  especial) y **R** (solo restauración). Cotizaciones: `COT-N`. Corrección desde
  la app (`/numeracion`, `NumeracionOrdenes`). Anular puede "correr" las siguientes.
- **Borradores** (`guardar_borrador`): no reservan stock ni gastan número; se
  completan con `completarBorrador`. También hay borradores locales en el aparato.
- **Editar** (`update`): lo que mueve plata lo pide el vendedor como
  `SolicitudCambio` (con motivo y foto) y lo aprueba un supervisor. Avisa al
  taller (`AvisoProduccion`) y a facturación (`AvisoFacturacion`).
- **Estados** (`updateEstado`), **cancelar/anular**, **eliminar** (supervisor,
  queda en `ordenes_eliminadas`), **cambiar producto** después de entregado,
  **revertir entrega**, **fechas de entrega** por ítem, **PDF** de una hoja,
  **chat** de la orden, **fijar** órdenes, búsqueda con "¿quisiste decir?".
- **Chat de la orden** (`OrdenMensajeController`, `ChatOrden.vue`): dudas entre vendedores,
  supervisores y Producción; varias fotos por mensaje (`orden_mensajes.imagenes`), cada
  una se sube al elegirla con reintento. Se cierra al quedar lista para entrega.
- **Visibilidad:** `Orden::scopeVisiblesPara` / `laPuedeVer` / `laPuedeCobrar` /
  `laPuedeEditar` (ver `MEMORY.md` §2).

### 6.2 Cotizaciones
`CotizacionController`. Propuestas que **no** reservan stock, no crean
producción ni comisión, no gastan consecutivo (usan COT-N), cliente opcional.
Estados propios (`cotizacion_estado`: `abierta, enviada, convertida, perdida`), validez
(`esta_vencida` se calcula), envío por correo, PDF, conversión a orden.
Job diario avisa las que van a vencer.

### 6.3 Clientes
`ClienteController`: CRUD, verificación de duplicados, exportar a Excel,
órdenes del cliente. Borrar solo supervisor.

**Clientes de redes** (2026-10-08, pestaña *Redes* en `ClientesView`,
`components/clientes/ClientesRedesPanel.vue` + `ClienteRedModal.vue`): las personas
que escribieron por WhatsApp o Instagram, con el nombre y el celular que le dieron a
Elena antes de pasar con un asesor. Tabla `clientes_redes` (una fila por `canal` +
`identificador`), la arma el webhook de Redes con `ClientesRedes::registrarDesdeAviso`
(nunca tumba la tarjeta) y, apenas el cliente da nombre o celular aunque no pida asesor,
`POST /api/agentes/clientes-redes` → `ClientesRedes::registrarDesdeAgente` (sin tarjeta).
Elena mantiene `interes` (lo que busca, en una frase) y `categorias_interes`. `ClienteRedController`: listado con filtros y conteos por
estado (`nuevo`, `contactado`, `compro`, `perdido`), ficha con sus tarjetas de Redes,
estado/notas/contacto y **Pasar a clientes** (crea un `Cliente` "interesado" o lo enlaza
con el que ya tiene ese celular). Permiso `acceso_redes` o supervisor; un vendedor con
tienda ve las de su tienda y las sin tienda. Prueba: `ClientesRedesTest`.

### 6.4 Pagos, caja y facturación
- `PagoController`: abonos, anticipo de órdenes que quedaron en $0,
  `verificar-pago` (avisa si se pierde el descuento condicionado), editar pago
  (con solicitud si es vendedor), facturación de pagos (`tomar-facturacion`, `marcar-facturada`).
  Varias fotos de comprobante.
- `CajaController`: balance y movimientos por tienda; los independientes (y el
  ebanista) llevan **caja propia**. `pagos.tienda_id` = dónde entró el dinero
  (independiente de a quién se abona la venta). Pagos cobrados en una entrega se
  marcan aparte (`caja:cobros-de-entrega`).
- `FacturacionController`: lista para vendedores con `facturacion = true`.

### 6.5 Inventario y variantes
`InventarioController`, `VarianteController`, `ProductoVarianteConfigController`,
`TipoVarianteController`, `ProductoController`.

- `inventario`: por producto y tienda — `cantidad_disponible`, `cantidad_reservada`,
  `stock_minimo`. Movimientos en `inventario_movimientos` (nombran la orden).
- Variantes en tres niveles:
  - **Tela/color** (`producto_variantes` + `inventario_variantes`).
  - **Tipos configurables** (`tipos_variante` + `tipo_variante_opciones`: medida,
    alerones, tipo de madera…) asignados a productos con precio
    (`producto_variante_configs` + `inventario_variante_configs`).
  - **Combinaciones** de opciones (`inventario_variante_combinaciones`).
  - El desglose nunca supera el total (`Support/StockVariantes`).
- **Venta por juego** (comedor de 6 sillas = juego o piezas sueltas).
- **Productos únicos** (piezas irrepetibles ya vendidas).
- **Auditoría de descuadres** (supervisor): reservas fantasma y entregas que no
  descontaron, con corrección.

### 6.6 Surtidos, traslados y Reserva de Fábrica
- **Surtido** (`SurtidoController`): fábrica → tiendas. Lo crea quien tenga
  `acceso_surtir`; lo validan vendedores de la tienda destino (aceptar/rechazar).
  Recomendaciones de surtido, remisión en PDF, surtidos programados (job).
- **Traslado** (`TrasladoController` + `MovimientoTraslado`): entre tiendas, con
  tela. Supervisor lo ejecuta en el acto; otros quedan pendientes de aceptar;
  pueden programarse (job `EjecutarTrasladoProgramado`).
- **Reserva de Fábrica** (`ReservaController`, `ReservaDeposito`): stock
  terminado en la Bodega Fábrica. Producción con `destino = 'reserva'` deposita
  ahí al terminar ("producir contra stock").

### 6.7 Telas y módulos clonados
- `CatalogoTelaController` (marca → tipo → color), `InventarioTelaController`
  (metros disponibles/reservados, recargar, descontar), `ConsumoTelaController`
  (metros por producto; interruptor `telas_consumo_activo`).
- `ConsumoTelas`: al mandar al taller un producto tapizado con tela del
  catálogo, aparta metros (`tela_reservas`); al terminar descuenta; al cancelar suelta.
- **Módulos clonados** (`PersonalizacionController`, `ModuloItemController`):
  la empresa crea "Espumas", "Hilos"… a partir de la plantilla Telas, con los
  mismos permisos (`modulo_items`).

### 6.8 Producción (taller)
`ProduccionController`, `TipoProcesoController`, modelos `Produccion`,
`ProduccionPaso`, `PasoTrabajador`, `ProduccionRetorno`, `TipoProceso`.

- Cada ítem que se fabrica crea una `produccion` con **pasos** según los
  `tipos_proceso` configurables (ebanistería, tapizado, laca, costura,
  despacho…). Estados de producción: `pendiente, en_proceso, listo, retrasado,
  entregado, cancelado, pendiente_despachador, en_reserva`; pasos:
  `pendiente, en_proceso, completado`.
- Encargados por proceso (`proceso_trabajadores`), opcionalmente separados por
  **línea** (restauración vs mueble nuevo).
- "**Mis pasos**" (`EbanistaView`): cada trabajador ve y completa sus pasos,
  apunta participantes con horas y calificación (hoja de vida del taller).
  Devolver un paso; **regresar al taller** desde despacho (`RetornoAlTaller`).
- Cuando todas las producciones de una orden quedan `listo` → `listo_entrega`.
- Permisos: `acceso_produccion` (ver tablero), `gestiona_produccion` (mover),
  o tener pasos asignados. Job diario marca retrasos.

### 6.9 Despacho y entregas
`DespachoController`, `EntregaService`, modelos `Despacho`, `DespachoItem`,
`EntregaLinea`, `Camion`, `Devolucion`.

- **Rutas**: el supervisor (o quien tenga `acceso_despacho`) arma rutas
  (borrador → enviada), asigna conductor y camión, reordena paradas, imprime hoja de ruta.
- **Conductor** (`MisEntregasView`): inicia la ruta, registra pago, fotos, acta
  firmada, devoluciones, y entrega.
- **Entrega directa** (`acceso_entregas`): el vendedor entrega sin ruta
  (despacho sintético `tipo='directa'`), mismo formulario.
- **Por producto**: cada entrega lleva `entrega_lineas`; la orden muestra
  "Entrega parcial 1/2"; se puede deshacer una entrega (supervisor o quien la
  hizo en 24 h).
- **Devoluciones** (`DevolucionController`): lo que vuelve dañado queda
  `pendiente`; producción decide `a_produccion`, `reembolsada`, `cambio`,
  `cambio_mismo`. La orden queda `devuelto` mientras tanto.
- PDFs: acta de entrega, orden de entrega, hoja de ruta.

### 6.9b Garantías (posventa)
`GarantiaController`, `GarantiaService`, modelo `Garantia`. Detalle y decisiones:
[`plan-garantias-posventa.md`](plan-garantias-posventa.md).

- Lo que se daña **después de entregado** (las devoluciones son para lo que vuelve
  en el camión o se daña antes de salir). Se reporta por producto desde la orden
  (botón "Garantía"); el sistema calcula la vigencia del anexo y el plazo legal
  (15 días hábiles, con festivos: `Support/FestivosColombia`).
- Dictamen (gestiona producción o supervisor): **taller** (el producto se
  reactiva en la orden, su producción se reabre con pasos de arreglo marcados
  `garantia_id` y se vuelve a entregar con acta, sin descontar stock otra vez:
  `orden_items.cantidad_en_garantia`), **domicilio** (quién va y la visita),
  **otro igual** / **otro producto** (renglón nuevo apartado en la tienda que
  tenga libres o para fabricar; el de otro producto solo supervisor, con precio)
  o **devolver la plata** (supervisor; sale al recibir el producto como pago
  negativo `tipo=reembolso`, y lo devuelto deja de ser venta) o **no procede**
  (causal del anexo).
- Se cierra sola al entregar el arreglo o el reemplazo (`EntregaService`).
  Bandeja en Producción; las visitas propias en "Mis pasos".

### 6.10 Comisiones
`ComisionController` (el más complejo), `ComisionIndependientes`,
`AnticiposComision`, modelos `Comision`, `MetaTienda`, `TiendaAsesor`,
`TiendaReemplazo`. Explicación de negocio: `explicacion_modulo_comisiones.txt`.

- Al confirmar una orden se crean filas en `comisiones` (venta compartida = mitad
  para cada uno).
- **Tiendas con meta**: pool = (ventas con 50 % pagado − meta) ÷ 1,19 × 5 %,
  repartido entre el equipo de la tienda (`tienda_asesores_comision`) por días
  trabajados; reemplazos entre tiendas (`tienda_reemplazos`); periodicidad
  mensual o trimestral por tienda; cada tienda decide si comparte o es individual.
  Disponible el día 20 (o cierre de trimestre).
- **Independientes**: 5 % fijo (ver `MEMORY.md`).
- Pagos por persona (`pagar-listas`), deshacer pago, anticipos mensuales que se
  descuentan, bitácora de cambios, recalcular, y `comisiones:poner-al-dia` diario 06:30.
- Datáfono: lo pagado con tarjeta/Addi descuenta la franquicia del comisionable.

### 6.11 Costos de fabricación y cotizador IA
`FichaTecnicaController`, `MaterialController`, `ConfiguracionCostosController`,
`ConsultaCostoController`, `PrecisionCotizadorController`, `PrecioItemController`,
`AgentController` + `AgentService` + `Services/Costos/*`.

- **Fichas técnicas** (~306): receta real de cada mueble por secciones
  (esqueletería, tapicería, corte y costura, carpintería), con materiales y mano
  de obra (horas × tarifa de `salarios_cargo`, `tarifas_proceso`).
- **Materiales** (~314) con unidad normalizada, historial de precios, duplicados marcados.
- **Cotizador** (`POST /calcular-precio-item` y tool `cotizar_fabricacion` del chat):
  `FichaRetriever` (embeddings) → `BomBuilder` (LLM arma receta con IDs y
  cantidades, ve la foto en `detail:high`) → `CostoCalculator` (precios de la BD)
  → `SanityChecker` (marca `requiere_revision`) → `FewShotProvider` (aprende de
  correcciones del ebanista en `estimados_ia`).
- **Consultas de costo**: el vendedor pregunta el precio de un personalizado al
  ebanista/supervisor (chat + desglose + margen); la respuesta alimenta el aprendizaje.
- **Precisión IA** (pestaña en Costos): error medio, sesgo y % dentro de ±10 %.
- Benchmark: `php artisan cotizador:benchmark`. Detalle: `docs/plan-cotizador-ia.md`.

### 6.12 Chat de IA (asistente)
`AgentChat.vue` → `POST /agent/chat`. GPT-4o con tools sobre los datos reales:
fichas, comisiones, inventario, ventas por producto/categoría, clientes top,
producción, órdenes, trabajadores, reportes, telas, caja, interesados, rotación.
Las cifras de costo salen del motor determinístico, nunca del modelo.

### 6.13 Nómina
`Nomina*Controller` (sueldos, empleados, pagos, ausencias, préstamos, ajustes,
producciones, bonificaciones), `NominaLiquidador`, `CicloNomina`. Bajo `acceso_nomina`.

- Los trabajadores se crean una vez en **Trabajadores** (`usuarios`) y aparecen
  solos en Nómina (sueldo, frecuencia, auxilio de transporte, seguridad social).
- Ciclos calculados del calendario (semanal, quincenal…), en hora de Bogotá; nada
  se guarda hasta marcar "Pagado" (`nomina_pagos`).
- Ausencias/incapacidades, préstamos por cuotas, ajustes, bonos con escalera de
  metas según producción. Encargos perdidos pueden descontarse en nómina.
- **Costo del empleador, por detrás** (2026-10-09, `Services/CostoEmpleador`):
  aportes (pensión, ARL por clase de riesgo, caja; salud/ICBF/SENA si no está
  exonerada) y prestaciones (prima, cesantías, intereses, vacaciones). No cambia
  lo que cobra el trabajador; se congela en `nomina_pagos.costo_empleador` y lo
  lee Finanzas. Todo configurable porque la ley cambia: conceptos
  (`nomina_conceptos_empleador`), porcentaje con fecha de vigencia
  (`nomina_concepto_tarifas`) y excepciones por persona
  (`nomina_concepto_trabajador`). Se maneja en Sueldos y en la ficha del
  trabajador (`/nomina/conceptos-empleador`, `NominaConceptoEmpleadorController`).
- **Prestaciones y liquidaciones** (2026-10-10, pestaña Prestaciones,
  `NominaPrestacionController`, `Services/Prestaciones`, `Services/LiquidacionContrato`):
  lo que se debe de prima, cesantías, intereses y vacaciones = lo provisionado en
  los pagos − lo pagado (`nomina_prestaciones_pagos`), con sus fechas límite. La
  liquidación al retiro paga los ciclos pendientes hasta el último día, las
  prestaciones del periodo, las vacaciones pendientes, la indemnización (art. 64,
  configurable) y descuenta préstamos; queda en `nomina_liquidaciones`, con PDF, y
  se puede anular (deshace todo). Valores (SMMLV, fechas, días) en
  `configuracion.nomina_prestaciones_config`. Finanzas lee lo pagado.

### 6.14 Encargos (herramientas a cargo)
`EncargoController`, `RevisionEncargos`. Qué herramientas/equipos tiene cada
trabajador, revistas periódicas (no se editan), lo dañado/perdido y su descuento.
Niveles: propio (`/encargos/mios`), `acceso_encargos` (ver), `revisa_encargos` (operar).

### 6.15 Redes (WhatsApp), citas y catálogos
- `RedesController`: webhook público con token para el agente de WhatsApp;
  bandeja de conversaciones (tomar/terminar), métricas (`acceso_redes`).
  Los agentes de WhatsApp/Instagram leen tablas de este sistema directo de la
  BD: **antes de migrar `productos`, `inventario`, variantes, `tiendas` o
  `herramientas`, lee [`contrato-agentes.md`](contrato-agentes.md)**.
- `CitaController`: citas presenciales o por mensaje; recordatorios diarios.
- **Catálogo público** (`/catalogo/:seccion`) y **catálogos visuales** tipo
  revista (`/c`, `/c/:slug`, gestionados en Gestión → Catálogos, con QR).

### 6.16 Anexo de garantías
`AnexoGarantiaController`, `Support/AnexoGarantiaTexto` (texto versionado).
Firma presencial (en el aparato del vendedor) o remota (enlace `/firmar/:token`
por WhatsApp/correo). Se pega a la orden al crearla; PDF y envío por correo.

**Firma remota de la orden (2026-10-09):** el enlace es "revisa tu pedido y fírmalo":
el cliente ve la orden completa (sus datos, productos con tela/medida, foto del
catálogo —la pone el servidor—, especificaciones y bocetos de lo personalizado,
subtotal/descuentos/total/anticipo/saldo, fecha y dirección de entrega), lee el anexo
y firma **una vez**: esa firma es la de la orden y la del anexo. Se manda desde Nueva
orden (antes de crearla; sin firma no se crea) o desde una orden ya creada que quedó
sin firma (`POST/GET /ordenes/{id}/firma-remota`, `components/ordenes/FirmaRemotaOrden.vue`;
al firmar queda en `ordenes.firma_url` y "Confirmar" ya no pide firma ni foto del
anexo). La huella (`AnexoGarantia::huella`/`coincideCon`) cubre productos, tela,
opción, cantidades, precios y descuentos (redondeados); si la orden cambia, la firma
no vale (al crear) o el enlace no deja firmar (orden existente, 409). Un enlace vivo
por cliente/orden: los anteriores se anulan; "Cancelar envío" lo anula
(`POST /anexos/{id}/anular`). El vendedor ve las respuestas "No" del check list.

### 6.17 Proveedores y compras
`ProveedorController` (libreta; crear/editar con `acceso_proveedores`, borrar
supervisor). `CompraController`: lista de "hay que comprar", marcar comprado con
costo y factura (`acceso_compras`, sin excepción para supervisor).

### 6.18 Reportes y estadísticas
`ReporteController` (ventas, vendedores, productos top, pendientes, interesados,
canales, resumen mensual + Excel, retrasos) y `StatsController` (panel, tendencia,
categorías, cartera, tiendas, vendedores, conductores, "mis estadísticas").
Desglose por tipo (venta / FV2 / restauración) con `Orden::sqlTipo()`.
Periodos con `RangoFechas`. **Nada de lo cancelado cuenta** (ni vendido ni
abonado): filtro `Orden::ESTADOS_FUERA_DE_REPORTES`; las canceladas solo salen
en su conteo.

### 6.18b Finanzas
`FinanzasController`, `GastoController`, `GastoRecurrenteController`,
`CategoriaGastoController`, servicios `app/Services/Finanzas/*`. Plan y decisiones:
[`plan-gestion-financiera.md`](plan-gestion-financiera.md). Solo supervisores con
`acceso_finanzas`.

- **No recalcula nada por su cuenta:** ventas y cobros con el criterio de Reportes
  (`FuenteVentas`, prueba "cuadra con Reportes"), nómina de `nomina_pagos` +
  `NominaLiquidador` + `CostoEmpleador` (`FuenteNomina`), comisiones leyendo
  `comisiones.monto_comision` sin escribir (`FuenteComisiones`).
- **Dos lentes:** estado de resultados devengado (`EstadoResultados`: cada peso a su
  mes; materiales estimados con fichas, con cobertura) y flujo de caja (`FlujoDeCaja`:
  el mes en que entró o salió; saldo si hay saldo de partida).
- **Gastos:** sueltos y plantillas recurrentes (`gastos_recurrentes`) cuyas
  obligaciones se **calculan** del calendario (`ObligacionesRecurrentes`) y solo se
  guardan al pagar u omitir; prorrateo de licencias anuales; anular con motivo (nunca
  borrar); bitácora; presupuesto por categoría. La caja de tienda no entra.
- **Proyección** (`Proyeccion`): lo determinístico (nómina por ciclos, plantillas,
  comisiones del 20) más ventas por promedio ponderado (<6 meses) o tendencia lineal,
  con banda de error; flujo de 13 semanas; punto de equilibrio e indicadores con
  semáforo (`Indicadores`); calendario (`CalendarioPagos`); rentabilidad por tienda.
- Ajustes del negocio (IVA, franquicia, reparto, umbrales, saldo) en
  `configuracion.finanzas_config` (`ConfigFinanzas`). Aviso diario 08:15 de gastos por
  vencer. Prueba: `FinanzasTest`.

### 6.19 Gestión y personalización
`GestionView`: tiendas (`TiendaController`), roles configurables
(`RolController`), módulos renombrables con icono y orden, herramientas del
asesor (textos para copiar), catálogos visuales. `PersonalizacionController`.

---

## 7. Usuarios, roles y permisos

- `usuarios.rol` (string) — sincronizado con `roles.clave` vía `rol_id`. Los roles
  los crea cada empresa ("Metalero", "Soldador"…), pero cada uno tiene un
  **arquetipo** que define el comportamiento: `vendedor`, `supervisor`,
  `conductor`, `taller`, `despachador`.
- **Banderas por usuario** (`usuarios.*`): `facturacion`, `independiente`,
  `acceso_redes`, `acceso_comisiones`, `recarga_telas`, `acceso_telas`,
  `acceso_surtir`, `acceso_costos`, `acceso_proveedores`, `acceso_despacho`,
  `acceso_entregas`, `acceso_produccion`, `gestiona_produccion`, `acceso_reserva`,
  `acceso_nomina`, `acceso_finanzas` (además de ser supervisor), `acceso_compras`, `lleva_encargos`, `acceso_encargos`,
  `revisa_encargos`, `ve_todas_ordenes`, `puede_fv2_sin_iva`, `apto_comisiones`,
  `apto_produccion`, `no_usa_programa`, `notif_*`.
- Backend: middleware `role:` y `permiso:` + reglas en modelos/controladores.
  Frontend: computeds de `stores/auth.js` + guards del router.
- Independiente solo puede ser arquetipo vendedor o supervisor.
- Trabajador de fábrica: `no_usa_programa = true`, sin email/contraseña, no entra.

---

## 8. Tiempo real, notificaciones y tareas programadas

**Canales Reverb** (`routes/channels.php`): `ordenes`, `inventario`,
`produccion`, `despacho`, `notificaciones`, `notificaciones.{userId}`,
`supervisor`, `conductor.{id}`, `redes`.

**Notificaciones**: `NotificacionService::crear()` → fila en `notificaciones`
+ evento `NuevaNotificacion` + job `EnviarPush` (Web Push a todos los aparatos
suscritos). Sin `usuarioId` va a todos los supervisores activos.

**Agenda** (`routes/console.php`, hora Bogotá; corre con el bucle
`schedule:run` del contenedor):

| Hora | Tarea |
|---|---|
| 03:00 | `respaldo:base` — volcado de la BD comprimido al correo |
| 06:30 | `comisiones:poner-al-dia` — marca listas y avisa |
| 07:00 | `AlertarRetrasoProduccion` |
| 07:30 | `AlertarRutasAtrasadas` |
| 08:00 | `RecordatoriosCitas` |
| 08:30 | `AvisarCotizacionesPorVencer` |
| 08:45 | `AvisarRevisionesEncargos` |

**Comandos artisan útiles**: `cotizador:benchmark`, `cotizador:test-aprendizaje`,
`fichas:reindex`, `fichas:importar`, `db:exportar-costos`, `excel:inspect`,
`ordenes:revisar-restauraciones`, `caja:cobros-de-entrega`, `decasa:surtir-tienda`,
`produccion:revisar-retrasos`. **Destructivos** (nunca en producción sin orden
expresa): `limpiar:ordenes`, `decasa:limpiar-datos`.

---

## 9. Base de datos

MySQL en producción (Aiven), SQLite en memoria en pruebas. 262 migraciones.
Tablas por dominio:

| Dominio | Tablas |
|---|---|
| Personas y acceso | `usuarios`, `roles`, `perfiles_alternos`, `personal_access_tokens`, `push_subscriptions`, `sessions` |
| Tiendas | `tiendas`, `tienda_trimestres` |
| Ventas | `ordenes`, `orden_items`, `orden_secuencias`, `orden_ediciones`, `orden_mensajes`, `orden_fijadas`, `ordenes_eliminadas`, `solicitudes_cambio`, `clientes`, `citas`, `anexos_garantia`, `garantias` |
| Plata | `pagos`, `caja_movimientos`, `comisiones`, `comisiones_bitacora`, `comision_anticipos`, `comision_anticipos_config`, `metas_tienda`, `tienda_asesores_comision`, `tienda_reemplazos` |
| Catálogo e inventario | `productos`, `producto_variantes`, `tipos_variante`, `tipo_variante_opciones`, `producto_variante_configs`, `inventario`, `inventario_variantes`, `inventario_variante_configs`, `inventario_variante_combinaciones`, `inventario_movimientos` |
| Telas y módulos | `catalogo_telas`, `inventario_telas`, `tela_reservas`, `producto_consumo_telas`, `modulos`, `modulo_items`, `herramientas` |
| Logística | `surtidos`, `surtido_tiendas`, `surtido_items`, `traslados`, `traslado_items`, `despachos`, `despacho_items`, `entrega_lineas`, `camiones`, `devoluciones` |
| Taller | `produccion`, `produccion_pasos`, `paso_trabajadores`, `produccion_retornos`, `tipos_proceso`, `proceso_trabajadores`, `perfiles_produccion` |
| Costos / IA | `fichas_tecnicas`, `ficha_tecnica_items`, `materiales`, `material_precio_historial`, `tarifas_proceso`, `salarios_cargo`, `configuracion`, `consultas_costo`, `consulta_costo_items`, `consulta_costo_desglose`, `consulta_costo_mensajes`, `estimados_ia` |
| Nómina | `nomina_sueldos`, `nomina_pagos`, `nomina_ausencias`, `nomina_prestamos`, `nomina_prestamo_cuotas`, `nomina_ajustes`, `nomina_producciones`, `nomina_bonificaciones`, `nomina_bonificacion_metas` (+ heredadas `empleados`, `nomina_items`, `nomina_periodos`) |
| Otros | `notificaciones`, `conversaciones_wa`, `proveedores`, `compras`, `encargos`, `encargo_revisiones`, `encargo_revision_items`, `catalogos`, `catalogo_paginas`, colas/caché de Laravel |

Estados importantes:
- `ordenes.estado`: `cotizacion, borrador, pendiente_cotizacion, pendiente_anticipo, en_produccion, listo_entrega, en_camino, devuelto, entregado, cancelado`.
- `devoluciones.estado`: `pendiente, a_produccion, reembolsada, cambio, cambio_mismo`.
- `garantias.estado`: `pendiente, por_recoger, en_taller, a_domicilio, cambio, resuelta, no_procede`.

---

## 10. Pruebas

- `tests/Feature` (83 clases) — nombradas por el caso de negocio que protegen
  (`ManuelaCubrioASebastianEnElEdenTest`, `PoolTrimestralSePagaUnaVezTest`,
  `NoSeDuplicaLaOrdenTest`, `EntregaPorProductoTest`…). Cada una arma su esquema
  SQLite a mano; `TestCase::completarEsquemaDeEntregas()` agrega lo que trajo
  "entregas por producto" y `prestarleASqliteLoQueEsDeMysql()` emula
  `CONVERT_TZ` / `DATE_FORMAT`.
- `tests/Unit` (6): nómina (auxilio, seguridad social, incapacidad), `RangoFechas`, tiempos de paso, variantes.
- Cómo correr y fallos preexistentes: `MEMORY.md` §4.
- No hay tests de frontend; la verificación es `npx vite build` + revisión visual.

---

## 11. Despliegue e infraestructura

- **Backend → Render** (`render.yaml`, `decasa-api/Dockerfile`): `php:8.4-apache`
  con OPcache (`validate_timestamps=0`), límites de subida altos (fotos de entregas),
  proxy `/app` → Reverb `:8080`. `entrypoint.sh`: `migrate --force`, cachés de
  config/rutas/vistas/eventos, y en bucle con reinicio: `queue:work`,
  `reverb:start`, `schedule:run` cada 60 s. Variables secretas en el panel de Render.
- **Frontend → Vercel** (`decasa-app/vercel.json`): reescribe `/api/*` y
  `/storage/*` al backend; el resto a `index.html` (SPA). Despliega por push a
  `main` o con el CLI desde la raíz.
- **Ramas**: `main` (producción) ← `develop` ← `feature/*`, `fix/*`, `chore/*`;
  `hotfix/*` desde `main`. Ver `docs/flujo-de-ramas-git.md`.

---

## 12. Documentos existentes

| Documento | Estado |
|---|---|
| `docs/flujo-de-ramas-git.md` | Vigente |
| `docs/plan-cotizador-ia.md` | Fases 1–7 implementadas; pendiente Fase 1b (restauración) |
| `docs/plan-entregas-parciales.md` | Fases A–D implementadas |
| `docs/plan-garantias-posventa.md` | Implementado y subido 2026-10-09 |
| `docs/plan-gestion-financiera.md` | Plan del módulo de Finanzas (2026-10-09). Fase 0 hecha (reportes sin canceladas, costo del empleador en Nómina); el módulo está sin implementar y quedan decisiones del dueño (§12) |
| `docs/plan-venta-abonada-a-tienda.md` | Implementado (el documento dice lo contrario: es histórico) |
| `explicacion_modulo_comisiones.txt` | Explicación para negocio del cálculo de comisiones |
