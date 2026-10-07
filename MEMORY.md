# MEMORY.md — Memoria compartida del proyecto

Lo que un agente necesita saber y **no** se deduce leyendo el código o el
`git log`: decisiones del negocio, preferencias del usuario, trampas y estado
del trabajo. Reglas para mantenerlo en [`AGENT.md` §8](AGENT.md#8-cómo-mantener-la-memoria-del-proyecto).

Última revisión completa: **2026-10-07** (análisis módulo por módulo del repo).

---

## 1. Preferencias del usuario (cómo quiere que se trabaje)

- **No commit / push / deploy sin permiso explícito en ese momento.** El push a
  `main` redespliega Render y corta órdenes en curso (503). Cada "súbelo"
  autoriza solo esa subida. *(confirmado 2026-09-24)*
- **Notificaciones siempre al PWA** (push al celular), con
  `NotificacionService::crear`. Ninguna se borra ni se esconde sola; la campana
  pagina con `antes_de` / `siguiente`. *(pedido 2026-09-30)*
- **Diseño:** actuar como diseñador profesional siguiendo el patrón del programa,
  no inventar estética nueva. Revisar en 390 px y en modo oscuro. *(2026-10-06)*
- **Preguntar antes de cambiar una regla de plata.** Confirmar con datos reales
  (BD de producción en Aiven, **solo lectura**) cuando se pueda.
- Todo en español, con comentarios que explican el porqué con casos reales.

## 2. Reglas de negocio confirmadas (no "arreglarlas")

### Visibilidad de órdenes — "las ventas de la tienda son de la tienda"
Las ventas de una tienda son de la tienda **y** de quien las vendió: los
compañeros de la misma tienda las ven, cobran/abonan y editan. Si alguien se
cambia de tienda, lo vendido en la anterior sigue siendo de esa tienda y suyo;
lo nuevo ya no le sale a los de la vieja. No aplica a cotizaciones, borradores
ni independientes (lo de ellos es solo suyo). Implementado 2026-10-03 en
`Orden::laDeSuTienda()` + `scopeVisiblesPara` / `laPuedeCobrar` / `laPuedeEditar`;
tests en `VerOrdenesCompartidasTest`. El usuario lo ha pedido "siempre": nunca
limitar a un vendedor a solo lo suyo.

### Comisiones de tiendas con meta (pool)
- Pool = (ventas − meta) ÷ 1,19 × 5 %. Sin meta cumplida → $0.
- **Las 3 condiciones para cobrar no cambian nunca:** (1) el cliente pagó el
  50 % de la orden, (2) llegó el día 20 (o el cierre de trimestre en las tiendas
  trimestrales: Unicentro Pereira y Circunvalar), (3) la tienda llegó a la meta.
- El 50 % se mira **a nivel de TIENDA**, no por persona: una venta entra a la
  cuenta de la tienda cuando su cliente pagó la mitad; con esas ventas se mide
  la meta y se saca el pool. Todos los del equipo cobran igual (partido por días).
  Nunca dos compañeros con montos "listos" distintos por el pool.
- La META también se mide solo con ventas que tienen el 50 % pagado
  (ej. El Edén ago-2026: vendió $49,5 M, con 50 % pagado $39,6 M < $40 M → $0).
  Implementado como "libro del pool" (`ComisionController::libroDelPool`, commit
  c8eb083). Las tiendas trimestrales **todavía no** usan el libro.
- Se pagan todas de una por persona, no orden por orden.
- *Historia:* el 2026-09-23 se intentó quitar el requisito del 50 % (ca838c2) y el
  usuario lo rechazó con fuerza (revertido en 177f7f9).
- Explicación larga para negocio: `explicacion_modulo_comisiones.txt` (raíz).

### Comisiones de independientes (`ComisionIndependientes`)
Porcentaje fijo, sin meta. Venta → 5 % de lo suyo (÷1,19) para quien vendió.
Restauración → bolsón común de todas las restauraciones del mes; cada
independiente cobra el 5 % del bolsón completo. Si se comparte con un almacén
(`tienda_abonada_id`), a la meta del almacén le suma la mitad de la venta y el
almacén cobra su propio 5 %.

### Entregas por producto (plan `docs/plan-entregas-parciales.md`, fases A–D hechas)
Se entregan **productos, no órdenes** (`entrega_lineas`). Decisiones 2026-09-11:
en entrega parcial el pago es opcional, el saldo se exige en la última; la
comisión no cambia por la entrega (≥50 % pagado + fecha); en daños, el vendedor
anota las opciones que dio al cliente y **decide el supervisor**; cambiar producto
puede ser por el mismo o por otro de distinto valor (se recalcula el saldo).

### Cotizador IA de costos (`docs/plan-cotizador-ia.md`, fases 1–7 hechas)
"La IA arma la receta, el código calcula el precio." El sistema da **costo de
fabricación real**; la ganancia la pone el supervisor. Precio de venta sugerido =
costo × `factor_venta_sugerido` (default 2.0, editable en Costos → Tarifas).
Duplicados de `materiales` se marcan (`activo`, `equivalente_a_id`), nunca se
borran. Pendiente: la ruta de **restauración** del cotizador sigue con la IA
escribiendo precios (Fase 1b).

### Otras
- Descuento condicionado: solo vale si pagan en efectivo/transferencia; tarjeta,
  Addi u "otro" lo hacen perder. Tarjeta y Addi cobran franquicia (5,5 %) y no
  se comisiona sobre eso.
- Numeración: consecutivo por grupo (Armenia vs Pereira). FV2 y R (restauración
  pura) llevan serie propia. Borradores y "esperando precio" no gastan número.
- Un vendedor no cambia plata solo: lo pide (`SolicitudCambio`) y un supervisor
  aprueba.

## 3. Trabajo abierto / a medias

- **Venta de independiente abonada a una tienda** (`docs/plan-venta-abonada-a-tienda.md`):
  el documento dice "sin implementar", pero **ya está hecho**: migración
  `2026_08_06_000004_agregar_tienda_abonada_a_ordenes`, selector en
  `NuevaOrdenView` / `EditarOrdenModal`, y comisiones en
  `ComisionIndependientes` + `ComisionController::sincronizarAbonoAlmacen`.
  El plan quedó como histórico; la regla vigente es la de §2 "independientes".
- Ramas remotas sin cerrar: `develop`, `feat/entregas-parciales`,
  `fix/comisiones-reemplazos`, `seguridad/hardening-auditoria`. Confirmar con el
  usuario si siguen vivas antes de basarse en ellas.
- Cotizador: Fase 1b (restauración) pendiente; constantes `ESCALA` y `× 0.70`
  en `AgentService` quedaron sin efecto y se pueden limpiar.

- **Rendimiento** (`docs/plan-rendimiento.md`): la causa n.º 1 de la lentitud
  era el limitador de peticiones guardando su contador en Aiven (8 consultas,
  ~1,7 s por llamada) y cada consulta cuesta ~200 ms (base lejos del servidor).
  **Fases 1, 3, 4 y 5 hechas el 2026-10-07** (1: d72eea0+57dfcdb, 3: d804596,
  4: 3dd6fef; 5 pendiente de subir al cierre de ese día). vapid-key 2,1 → 0,39 s,
  login 3,2 → 0,33 s, /api/c 6 → 1,2 s, comisiones y estadísticas con la mitad o
  menos de consultas. **Queda solo mudar Aiven a DigitalOcean San Francisco**
  (`do-sfo`, en vivo, la URI no cambia). Lo evaluado y descartado (cachear
  token, conexiones persistentes antes de mudar, índice de notificaciones…)
  está en el plan, Fase 5: no repetir ese análisis.
  Regiones confirmadas por
  el usuario (2026-10-07): **Aiven = DigitalOcean NYC, Render = Oregon** →
  recomendado mudar Render a Virginia (pasos en el plan, Fase 2). Aiven tiene
  la allowlist de IPs **abierta a todo internet**: cerrarla tras la mudanza.
  Falta saber el valor de `CACHE_STORE` en el panel de Render.

## 4. Entorno y pruebas

- Windows (LENOVO). PHP 8.4.25 en `C:\php\php.exe` (en Git Bash `/c/php/php.exe`
  o `export PATH="/c/php:$PATH"`), Composer `C:\php\composer.phar`, Node 24. Sin Python.
- `composer install` desde el sandbox falla con "Permission denied" al escribir
  zips; funciona sin sandbox.
- Tests: `/c/php/php.exe artisan test --filter Clase` (SQLite en memoria). La
  suite entera se queda sin memoria con `artisan test`: usar
  `/c/php/php.exe -d memory_limit=1G vendor/bin/phpunit`.
- **Fallos preexistentes** (recontado 2026-10-06: 690 tests, 10 fallos) en
  `AnularOrdenTest`, `CambioDeSedeTest`, `ConsumoTelasTest`, `ExampleTest`,
  `RegistrarAnticipoTest`, `SupervisorIndependienteTest`. Son esquemas SQLite
  montados a mano desactualizados. No atribuírselos a cambios nuevos; comparar
  por clase.
- Front: `cd decasa-app && npx vite build`.
- **Recontado 2026-10-07: 713 tests, 10 fallos preexistentes** (las mismas 6 clases).
- **Trampa en worktrees:** no enlazar (junction) el `vendor` del repo principal.
  Laravel deduce la ruta base de la ubicación del `vendor` y el classmap apunta
  al `app/` del principal: los tests corren el código VIEJO sin avisar. Copiar
  el `vendor` y correr `composer dump-autoload` dentro del worktree.
- **Prueba diferencial antes de optimizar algo de plata:** `GUARDAR=dir` en
  `medir.sh` guarda cada respuesta; capturar con el código viejo (`git stash
  push -m <tag> -- app`), restaurar, capturar con el nuevo y `cmp`. Tiene que
  dar idéntico byte a byte. Así se hizo la fase 4 de rendimiento (comisiones).
- **Cachés estáticos** (por petición): registrar cada uno nuevo en
  `App\Support\CachesDePeticion::olvidarTodo()` — se limpia antes de cada
  trabajo de la cola y de cada prueba.
- **Medir rendimiento sin producción:** `bash decasa-api/scripts/perf/medir.sh`
  (esquema real en SQLite + datos sintéticos; cuenta consultas por endpoint y
  detecta N+1). Emula las funciones de MySQL que usa la app (FIELD,
  DATE_FORMAT, CONVERT_TZ, DATEDIFF…) y la vista `v_saldo_ordenes`, así que
  `/stats/*` y `/comisiones*` ya se pueden medir. En producción: cabecera
  `Server-Timing` y log `[lenta]` en Render.

### Ver pantallas sin backend (lo que funcionó 2026-10-06)
Servidor falso en Node (`http.createServer`, puerto 8787) que responde los
`/api/...` de la pantalla; Vite con config aparte (en scratchpad) con `root` =
decasa-app, alias `@`, proxy `/api` → 8787, plugins importados por ruta absoluta
y export de objeto plano; Chrome headless por CDP con el `WebSocket` de Node 24
(390×844, sesión falsa en localStorage: `token`, `usuario`, `perfiles`,
`perfilActivo`). Modo oscuro = clase `dark` en `<html>` (clave `app-dark`).

## 5. Infra y despliegue

- Front: Vercel, proyecto `sistema-de-ventas`, rootDirectory `decasa-app`.
  `npx -y vercel@latest deploy --prod --yes` desde la raíz (`.vercel/project.json`).
  Sin `.vercelignore` falla por `decasa-api/storage/logs/laravel.log` (>100 MB).
  Verificar con `vercel inspect <url>` = Ready.
- Back: Render (Docker, `render.yaml`). El contenedor corre Apache + `queue:work`
  + `reverb:start` + `schedule:run` cada 60 s, relanzándose si caen. Logs a
  stderr (pestaña Logs de Render). Caché en archivos (un solo contenedor).
- Regiones (2026-10-07): Render **Oregon (US West)**; Aiven **DigitalOcean NYC**.
  Si se mudan, actualizar esto.
- Variable `PROGRAMADOR_ACTIVO` (default 1) en `entrypoint.sh`: con dos
  servidores vivos, solo uno puede tenerla en 1 o las tareas diarias se duplican.
- BD: MySQL en Aiven (`defaultdb`). Respaldo diario 03:00 por correo
  (`respaldo:base`). **Los correos salen por Brevo (API), que rechaza adjuntos
  `.gz`**: el respaldo estuvo fallando ("Unsupported file format: gz", visto
  el 2026-10-07). Desde entonces va en `.zip` (permitido por Brevo). Antes de
  cualquier operación grande en la base (mudanza, cambio de plan), confirmar
  que llegó el correo del respaldo de ese día.
- Variables del front en Vercel: `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`,
  `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` (sin key → sin tiempo real, polling).

## 6. Trampas conocidas

- Basura versionada por accidente (comandos de tinker mal escritos):
  `decasa-api/count())`, `decasa-api/get()`, `decasa-api/pluck('categoria')`,
  `decasa-api/pluck('tarifa'`, y en la raíz `C:UsersLenovoDesktopconfiguracion_export.sql`.
  No contienen nada útil; se pueden borrar **si el usuario lo aprueba**.
- `decasa-api/costos_seed.sql` (4,7 MB) es el export de costos
  (`db:exportar-costos`); no lo regeneres sin motivo.
- `usuarios.rol` (string) se sincroniza solo con `roles.clave` al guardar
  `rol_id`; ~40 sitios comparan `$usuario->rol === '...'`.
- La venta digital cuenta para la tienda **del vendedor cuando vendió**
  (`ordenes.tienda_vendedor_id`), no la de hoy.
- Fechas: guardar UTC, razonar en Bogotá (después de las 7 p. m. UTC ya es otro día).
- El modelo `Orden` tiene `$appends` (`referencia`, `contacto_display`…): no
  dispares relaciones dentro de esos accesores (N+1 en listados).
