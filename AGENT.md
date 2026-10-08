# AGENT.md — Cómo trabajar en el Sistema de Ventas Decasa

Instrucciones que **todo agente** (Claude Code, Codex, Cursor, etc.) debe seguir
siempre en este repositorio. Léelo completo antes de tocar código.

- Contexto vivo (decisiones, reglas de negocio, estado del trabajo): [`MEMORY.md`](MEMORY.md)
- Documentación completa módulo por módulo: [`docs/PROYECTO.md`](docs/PROYECTO.md)
- Plan del cotizador IA (las "Fase N" que citan los comentarios del código): [`docs/plan-cotizador-ia.md`](docs/plan-cotizador-ia.md)
- Qué leen y llaman los agentes de WhatsApp/Instagram de este sistema: [`docs/contrato-agentes.md`](docs/contrato-agentes.md)
- Herramientas para agentes (skills, mapa de código, revisión de seguridad): §9

---

## 1. Qué es esto en 30 segundos

Decasa es una fábrica de muebles con tiendas en **Armenia** y **Pereira**
(Colombia). Este sistema lleva todo el negocio: órdenes de venta, cotizaciones,
inventario por tienda, producción en el taller, despacho y entregas, pagos y
caja, comisiones, nómina, costos de fabricación con IA, y más.

| Parte | Carpeta | Stack | Dónde corre |
|---|---|---|---|
| API | `decasa-api/` | Laravel 13, PHP 8.4, MySQL (Aiven), Sanctum, Reverb, colas en BD | Render (Docker) — `https://decasa-api-b91v.onrender.com` |
| App | `decasa-app/` | Vue 3 + Vite 8 + Pinia + Tailwind 4, PWA | Vercel — `https://sistema-de-ventas-olive.vercel.app` |
| Agentes de chat (Elena) | **otro repo:** `Desktop/Agentes` (`Agente-ws`, `Agente-ig`) | Node + Express + OpenAI, Twilio / Meta | Render, uno por canal |

La app llama a `/api/*` y Vercel lo reescribe al backend de Render (`decasa-app/vercel.json`).

Los agentes atienden a los clientes por WhatsApp e Instagram y son **parte del sistema**:
leen la misma base de datos (productos, precios, variantes, inventario, tiendas,
catálogos) y le hablan a esta API por `POST /api/redes/webhook` (tarjetas de Redes) y
`GET /api/agentes/pedidos` ("¿cómo va mi pedido?"). Un cambio aquí puede cambiar lo que
Elena le dice a un cliente: ver `docs/contrato-agentes.md` y la regla 9.

---

## 2. Reglas que NO se rompen

1. **Nunca hacer commit, push ni deploy sin que el usuario lo pida en ese momento.**
   Un push a `main` redespliega Render y tumba las peticiones de los vendedores
   que estén creando órdenes (se han visto 503). Al terminar: verificar y
   reportar "listo, sin subir". Un "súbelo" autoriza **solo esa** subida.
2. **No tocar la base de producción** (Aiven) salvo lectura y con permiso
   explícito. Nunca correr migraciones, seeders, `limpiar:ordenes`,
   `decasa:limpiar-datos` ni scripts de datos contra producción por tu cuenta.
3. **No cambiar reglas de negocio de plata** (comisiones, descuentos, pagos,
   numeración, IVA) sin preguntar. Si una regla parece un bug, pregunta antes:
   en este proyecto casi siempre es una decisión del negocio (ver `MEMORY.md`).
4. **Notificaciones siempre con `NotificacionService::crear`** (guarda + broadcast
   + push al PWA). Nunca `Notificacion::create` directo. Nunca borrar avisos
   automáticamente: solo los borra la persona.
5. **Visibilidad de órdenes siempre con `Orden::visiblesPara()` / `laPuedeVer()` /
   `laPuedeCobrar()` / `laPuedeEditar()`.** Nunca un `where('vendedor_id', ...)`
   propio: las ventas de una tienda son de la tienda **y** de quien las vendió.
6. **Secretos fuera del repo.** `.env`, credenciales de Aiven, Cloudinary, OpenAI,
   Gmail, VAPID, `AGENT_TOKEN` y scripts tipo `dbcheck.mjs` nunca se versionan.
   (`.vercel/project.json` sí está versionado desde antes; solo trae ids, no secretos.)
7. **Migraciones: solo hacia adelante y compatibles.** En Render corren solas al
   arrancar (`entrypoint.sh` → `php artisan migrate --force`). No borrar ni
   renombrar columnas que el código viejo todavía lee; no borrar filas de datos
   de negocio (ej. `materiales` duplicados se marcan `activo=false`, no se borran).
8. **No reescribir en bloque.** Cambios mínimos y localizados; los controladores
   son grandes (OrdenController ~4.400 líneas) y llenos de casos de negocio
   documentados en comentarios. Lee el comentario antes de "simplificar".
9. **No romper a los agentes de chat.** Antes de renombrar o quitar columnas de
   `productos`, `inventario`, `tiendas`, variantes, `herramientas`, `catalogos` o
   `conversaciones_wa`, o de cambiar las rutas públicas `/c/:slug` y
   `/catalogo/:seccion`, lee `docs/contrato-agentes.md`. Los agentes verifican el
   esquema al arrancar y alertan, pero el cambio igual hay que coordinarlo en los dos
   repos. Las rutas de los agentes van con el middleware `agente` (`TokenDelAgente`,
   cabecera `X-Agent-Token` = `AGENT_TOKEN`) y **nunca devuelven plata ni datos
   internos** (`AgentePedidosController`).
10. **Lo que Elena le dice al cliente sale de aquí.** Cerrar una tienda (`tiendas.activa`),
    crear una categoría, cambiar un precio de variante o un catálogo de Gestión cambia
    lo que responde el bot. Ver la tabla de reglas compartidas en `docs/contrato-agentes.md`.

---

## 3. Flujo de trabajo obligatorio

1. **Entender antes de cambiar.** Lee `MEMORY.md` y la sección del módulo en
   `docs/PROYECTO.md`. Busca el comentario del método que vas a tocar: casi
   todos explican *por qué* son así (bugs reales que corrigieron).
2. **Ramas** (`docs/flujo-de-ramas-git.md`): `main` = producción, `develop` =
   integración. Trabajo en `feature/…`, `fix/…`, `chore/…` desde `develop`;
   `hotfix/…` desde `main`.
3. **Commits** en español: `tipo(alcance): mensaje` — tipos `feat`, `fix`,
   `chore`, `docs`, `refactor`. Alcances usuales: `ordenes`, `comisiones`,
   `inventario`, `produccion`, `nomina`, `telas`, `reportes`, `entregas`,
   `despacho`, `cotizador`, `pwa`…
4. **Verificar antes de decir "listo":**
   - Backend: `cd decasa-api && /c/php/php.exe artisan test --filter NombreDelTest`
     (SQLite en memoria, no necesita `.env`). Suite completa:
     `/c/php/php.exe -d memory_limit=1G vendor/bin/phpunit`.
   - Frontend: `cd decasa-app && npx vite build` sin errores.
   - Hay fallos de tests **preexistentes** (ver `MEMORY.md` → Entorno). Compara
     **por clase de test**, no por número total.
   - **En un worktree** (`.claude/worktrees/…`): copia el `vendor` del repo principal
     y corre `composer dump-autoload` adentro. Nunca un enlace (junction): los tests
     correrían el `app/` viejo del principal sin avisar. Para el front, `npm ci`.
   - Si el cambio toca algo de `docs/contrato-agentes.md`, corre también las pruebas
     de los agentes (`npm test` en `Desktop/Agentes`).
5. **Si la rama trae migración**, dilo explícitamente en el resumen/PR.
6. **Actualiza `MEMORY.md`** cuando aprendas algo durable: una decisión del
   usuario, una regla de negocio, una trampa del código, el estado de un trabajo
   a medias. Ver §8.

---

## 4. Convenciones del backend (`decasa-api`)

- **Idioma:** todo en español (modelos, columnas, métodos, comentarios, mensajes).
  Los comentarios explican el *porqué* con casos reales ("Manuela vendió el 31…").
  Mantén ese estilo y esa densidad.
- **Autorización:**
  - `role:supervisor,vendedor` → compara `usuarios.rol` (string sincronizado con
    `roles.clave`).
  - `permiso:acceso_x[,rolExtra]` → bandera booleana por usuario
    (`acceso_costos`, `acceso_despacho`, `acceso_nomina`, `acceso_compras`…).
  - Reglas finas viven **en el modelo** (`Orden::laPuede*`, `Usuario::veProduccion()`,
    `soloVeSusOrdenes()`) o dentro del controlador. No dupliques la regla.
- **Rutas:** todas en `routes/api.php`. Rutas literales (`/lote`, `/pendientes`,
  `/sugerencias`) **antes** de las de `{id}`, y usa `->whereNumber('id')`.
- **Transacciones** para todo lo que mueva stock, plata o numeración
  (`DB::transaction`, `lockForUpdate` en consecutivos).
- **Lógica compartida en `app/Services/`** — una sola puerta por operación:
  `EntregaService` (entregar), `MovimientoTraslado` (mover stock entre tiendas),
  `ConsumoTelas` (apartar/descontar tela), `NumeracionOrdenes` (corregir números),
  `Cartera`, `RangoFechas` (filtros de fecha en hora de Bogotá), `CambiosDePlata`.
  Si vas a repetir una cuenta, búscala primero ahí.
- **Fechas:** la BD guarda UTC; el negocio vive en `America/Bogota`. Usa
  `RangoFechas` / `CicloNomina::fecha()` y nunca compares medianoches de zonas
  distintas.
- **Estados que no son venta:** `cotizacion` y `borrador` (`Orden::ESTADOS_NO_COMERCIALES`,
  `scopeComerciales`). Exclúyelos de reportes, comisiones y cartera.
- **Columnas opcionales:** el código revisa `Schema::hasColumn(...)` en varios
  sitios porque los tests montan esquemas a mano. Si agregas una columna que
  escriben órdenes/entregas, agrégala también a `tests/TestCase::completarEsquemaDeEntregas()`.
- **Archivos/fotos:** se suben a Cloudinary vía `POST /upload/foto`
  (`UploadController`, lista blanca de carpetas). Varias fotos = columna JSON
  (`factura_fotos`, `comprobante_fotos`) + la primera en la columna vieja por compatibilidad.
- **Tiempo real:** eventos en `app/Events` → Reverb. Si Reverb falla, el flujo
  sigue (try/catch). La app tiene polling de respaldo.
- **PDFs:** DomPDF con vistas en `resources/views/pdf`. Imágenes remotas se
  convierten a base64 (`ConvierteImagenesPdf`, solo dominios permitidos).
- **IA:** OpenAI (`gpt-4o`, `text-embedding-3-small`). **La IA arma la receta,
  el código calcula el precio** — nunca dejes que el modelo escriba cifras de costo
  (ver `docs/plan-cotizador-ia.md`).

## 5. Convenciones del frontend (`decasa-app`)

- Vue 3 `<script setup>`, Pinia (stores en `src/stores`), llamadas HTTP solo por
  los módulos de `src/api/*.js` (axios con token Bearer en `src/api/index.js`).
- **Permisos en la UI** salen de `useAuthStore()` (`isSupervisor`, `puedeCostos`,
  `puedeDespacho`…); el guard de rutas está en `src/router/index.js`. El backend
  vuelve a validar siempre: la UI solo esconde.
- **Patrón visual** (el usuario pide seguirlo, no inventar estética):
  contenedor `p-4 max-w-2xl mx-auto space-y-4`, título `h2 text-lg font-bold`,
  pestañas en pastilla (`bg-gray-100 rounded-xl p-1`, activa `bg-white shadow-sm`),
  tarjetas `bg-white rounded-xl shadow-sm`, chips `rounded-full text-xs font-semibold`,
  botón primario `bg-blue-600 rounded-lg text-xs font-semibold`, modales como hoja
  inferior (`fixed inset-0 bg-black/40 … rounded-t-2xl sm:rounded-2xl`),
  carga con `AppSpinner`, avisos con `useToast` (no `alert`), `confirm()` solo
  para borrar/descartar.
- **Modo oscuro:** no hay variantes `dark:`; son overrides en `src/assets/main.css`
  (solo grises y blanco). Evita rellenos grandes de color claro (`*-50`).
- **Mobile first:** se usa en celulares (390 px). Revisa ahí y en oscuro.
- Muchas peticiones a la vez → `src/utils/colaPeticiones.js` (`crearCola`,
  `conReintento`). Peticiones de fondo con `{ silencioso: true }`.
- Dinero con `src/utils/pesos.js` / `MoneyDisplay` / `InputPesos`.

---

## 6. Entorno local (máquina Windows del usuario)

- PHP 8.4 en `C:\php\php.exe` (en Git Bash: `/c/php/php.exe`), Composer en
  `C:\php\composer.phar`, Node 24. **No hay Python del sistema**: solo el que maneja
  `uv` (`~/.local/bin/uv.exe`) para `graphify`. No uses Python para scripts del proyecto.
- Sin `winget` (Windows 10 LTSC). Herramientas de terceros: descargar de la release
  oficial y verificar el checksum.
- Comandos que necesitan red (`npx skills`, `vercel`, `curl` a GitHub) fallan dentro
  del sandbox ("Not authorized" en Vercel): corren fuera de él.
- No hay backend local corriendo (sin `.env`, producción es MySQL). Para ver
  pantallas: servidor falso en Node + Vite con proxy + Chrome headless (ver
  `MEMORY.md` → "Ver pantallas sin backend").
- Deploy del front (solo si lo piden): `npx -y vercel@latest deploy --prod --yes`
  desde la **raíz** del repo (`.vercelignore` deja subir solo `decasa-app`).
- Deploy del back: push a `main` → Render reconstruye el Docker y migra solo.

---

## 7. Mapa rápido: ¿dónde está…?

| Necesito… | Backend | Frontend |
|---|---|---|
| Crear/editar órdenes | `OrdenController` (store/update/updateEstado) | `NuevaOrdenView`, `OrdenDetalleView`, `components/ordenes/*` |
| Entregas / rutas | `DespachoController`, `EntregaService` | `DespachoView`, `MisEntregasView`, `components/despacho/*` |
| Taller | `ProduccionController`, `TipoProcesoController`, `RetornoAlTaller` | `ProduccionView`, `EbanistaView` (Mis pasos) |
| Inventario | `InventarioController`, `VarianteController`, `ProductoVarianteConfigController`, `Support/StockVariantes` | `InventarioView`, `components/inventario/*` |
| Surtir / traslados / reserva | `SurtidoController`, `TrasladoController`, `ReservaController` | `SurtirView`, `ReservaView` |
| Comisiones | `ComisionController`, `ComisionIndependientes`, `AnticiposComision` | `ComisionesView`, `components/comisiones/*` |
| Pagos / caja | `PagoController`, `CajaController`, `DescuentoCondicionadoService` | `RegistroPagoModal`, `CajaView` |
| Costos / IA | `AgentService`, `Services/Costos/*`, `FichaTecnicaController`, `MaterialController` | `CostosView`, `components/costos/*`, `AgentChat` |
| Nómina | `Nomina*Controller`, `NominaLiquidador`, `CicloNomina` | `NominaView` |
| Reportes / stats | `ReporteController`, `StatsController` | `ReportesView`, `StatsVendedorView` |
| Redes (tarjetas de los agentes), citas | `RedesController` (webhook, tomar/terminar, archivar, métricas), `CitaController` | `RedesView`, `MetricasRedesView`, `CitasView` |
| Lo que consultan los agentes | `AgentePedidosController`, middleware `TokenDelAgente` | — |
| Catálogos públicos | `CatalogoPublicoController` (`/catalogo/:seccion`, `/c`, `/c/:slug`), `CatalogoVisualController` (Gestión) | `CatalogoPublicoView`, `CatalogosPortadaView`, `CatalogoVisorView` |

Detalle completo en [`docs/PROYECTO.md`](docs/PROYECTO.md).

---

## 8. Cómo mantener la memoria del proyecto

`MEMORY.md` es la memoria compartida entre agentes y sesiones. Reglas:

- **Agrega** una entrada cuando: el usuario confirma/rechaza una regla de negocio,
  da una preferencia de trabajo, se termina o se deja a medias un trabajo, o
  descubres una trampa que costó tiempo.
- **Formato:** fecha absoluta (`2026-10-07`), qué, **por qué**, y cómo aplicarlo.
- **Corrige** en vez de duplicar; borra lo que resulte falso.
- No guardes lo que ya dice el código o `git log`; guarda lo que **no** se deduce
  de ahí (decisiones, razones, estado).
- Si cambias un módulo de forma importante, actualiza también su sección en
  `docs/PROYECTO.md`.

---

## 9. Herramientas para agentes

Instaladas el 2026-10-08. Se cargan al **abrir una sesión nueva**.

- **Skills del proyecto** (`.agents/skills/`, registro en `skills-lock.json`; los accesos
  de `.claude/skills/` los ignora git): `laravel-specialist`, `laravel-security`,
  `vue-best-practices`, `sql-optimization`, las 25 de `addyosmani/agent-skills`
  (revisión de código, TDD, depuración, seguridad, rendimiento, migraciones sin caída,
  planeación…) y las de antes (`deploy-to-vercel`, `diagnosing-bugs`, `ui-taste`…).
  Úsalas como guía, pero **este archivo manda**: si una skill dice "haz commit", "abre un
  PR" o "despliega", la regla 1 sigue valiendo.
- **Mapa de código (graphify):** `graphify-out/` (no se versiona). Para preguntas de
  "¿qué toca X?" o "¿dónde se usa Y?": `graphify query "…"`, `graphify explain "X"`,
  `graphify path "A" "B"`. Después de cambiar código: `graphify update .` (local, sin
  costo). Vista para el usuario: `graphify-out/graph.html`. `CLAUDE.md` y
  `.claude/settings.json` traen su sección y sus hooks (solo recuerdan, no bloquean).
- **Revisión de seguridad:** plugin `claude-security` (Anthropic, a nivel de usuario).
  Hace escaneo de vulnerabilidades con hallazgos verificados y parches que **el usuario**
  decide aplicar.
- **Buscar más skills:** `npx skills find <tema>`. Antes de instalar, revisar
  instalaciones, estrellas del repo y el contenido (ver lo que se hizo en `MEMORY.md`).
  **No instalar OmniRoute** ni gateways que reenvíen el código a proveedores de
  terceros (decisión del usuario, 2026-10-08).
