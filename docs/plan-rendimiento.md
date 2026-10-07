# Plan de rendimiento — "el programa es lento para cargar"

**Estado (2026-10-07):** Fase 1 **en producción y medida** ✅. Decisión del usuario:
terminar las fases 3–5 primero y después mudar la base de región (Aiven NYC →
DigitalOcean San Francisco, `do-sfo`).
**Regla de oro:** cada fase se **mide antes y después** con los mismos comandos.
Si un número no mejora, el cambio no se queda. Nada cambia reglas de negocio.

---

## 1. Diagnóstico (medido, no supuesto)

### 1.1 Producción, desde afuera (curl, rutas públicas, mediana de 3–5)

| Petición | Qué atraviesa | Tiempo hasta el 1er byte |
|---|---|---|
| `GET /api/no-existe` (404) | arranque de Laravel, sin middlewares de ruta | **0,40 s** |
| `GET /api/push/vapid-key` | + **1** limitador de peticiones (solo devuelve un texto) | **2,1 s** |
| `POST /api/auth/login` vacío (422) | + **2** limitadores | **3,2 s** |
| `GET /` (web) | + sesión guardada en la base | 1,4 s |
| `GET /api/c` | limitador + consultas reales | **6 s** |
| Lo mismo pasando por Vercel (`/api/...` en el dominio de la app) | + salto Vercel → Render | **+0,2 s por llamada** |
| Archivos JS de la app (`/assets/*.js`) | Vercel | `Cache-Control: max-age=0, must-revalidate` (se revalidan en cada apertura) |

**Lectura:** cada paso por el limitador cuesta **~1,4–1,7 s**. Con la caché en
archivos debería costar ~0.

### 1.2 Local, con el arnés (`decasa-api/scripts/perf/medir.sh`)

Esquema real (las 262 migraciones) en SQLite + volumen sintético parecido al de
producción (8.000 órdenes, 20.000 ítems, 12.000 pagos, 6.000 clientes, 6.600
filas de inventario). Cuenta las consultas por endpoint y proyecta el tiempo con
200 ms por viaje a la base.

| Prueba | Resultado |
|---|---|
| `/push/vapid-key` con `CACHE_STORE=database` | **8 consultas** (4 `select … from cache`, 2 `insert`, …) |
| `/push/vapid-key` con `CACHE_STORE=file` | **0 consultas** |
| Cualquier petición con sesión | +3 fijas: buscar token, buscar usuario, **`UPDATE last_used_at`** |
| `/despacho/cola` | **7,7 MB** de respuesta, 5,6 s en local (columnas completas de órdenes e ítems) |
| `/stats/cartera` | ~1 MB · `/reportes/pendientes` ~860 KB · `/productos` ~200 KB |
| Resto de pantallas | 3–13 consultas: el código ya evita casi todos los N+1 ✅ |

8 consultas × ~200 ms ≈ 1,6 s: **cuadra exacto con lo medido en producción.**

### 1.3 Causas, ordenadas por impacto

| # | Causa | Evidencia | Costo por petición |
|---|---|---|---|
| **C1** | **El limitador de peticiones guarda su contador en la base (Aiven)**, no en archivos. `render.yaml` dice `CACHE_STORE=file`, pero producción se comporta como `database`: el panel de Render probablemente tiene otro valor (el YAML no se re-aplica a un servicio creado a mano). | 8 consultas locales con `database`, 0 con `file`; +1,7 s por limitador en producción | **~1,5 s en TODAS las llamadas a la API** |
| **C2** | **Cada viaje a la base cuesta ~200 ms** → la base está lejos del servidor (región distinta) y cada petición abre una conexión MySQL nueva con TLS. | (2,1 − 0,4) / 8 ≈ 0,2 s por consulta | multiplica todo lo demás |
| **C3** | Sanctum hace un `UPDATE personal_access_tokens SET last_used_at` en **cada** petición. Nadie usa ese dato. | código de `vendor/laravel/sanctum/src/Guard.php` | ~0,2 s |
| **C4** | Al abrir la app se disparan ~10 peticiones en paralelo (me, módulos, notificaciones, catálogo de telas, surtidos, pasos, consultas, redes…) y el supervisor descarga además **la cola completa de despacho** solo para pintar un número en el menú. | `App.vue` líneas 144–186, `stores/despacho.js` | 10 × (C1+C2+C3) + respuestas pesadas |
| **C5** | Respuestas sobredimensionadas: `/despacho/cola`, `/stats/cartera`, `/reportes/pendientes`, `/productos` devuelven todas las columnas y relaciones. | tamaños medidos | segundos de red y JSON en el celular |
| **C6** | Los JS de la app se sirven con `max-age=0`: en cada apertura el navegador pregunta por cada archivo. | cabeceras de Vercel | ~0,3–1 s en celulares |
| **C7** | El salto Vercel → Render agrega ~0,2 s por llamada. | 0,40 s directo vs 0,62 s por Vercel | 0,2 s |

---

## 2. Principios del plan

1. **Primero lo que afecta a todas las peticiones** (C1, C3, C2): un arreglo, todo el sistema se acelera.
2. **Cero cambios de comportamiento** en las fases 1–2: mismas respuestas, mismos permisos, mismas reglas.
3. **Medir en producción con datos reales** desde la fase 1 (cabecera `Server-Timing` + log de peticiones lentas), para no depender de suposiciones.
4. Cada fase es independiente, reversible (un `git revert`) y se sube solo cuando el usuario dice "súbelo".

---

## 3. Fases

### FASE 1 — Quitar el costo fijo de cada petición (código, bajo riesgo)

| Paso | Qué | Dónde | Riesgo |
|---|---|---|---|
| 1.1 | El limitador usa **siempre archivos** (`cache.limiter = file`), sin depender de la variable del panel. | `config/cache.php` | Bajo: es lo que `render.yaml` ya pretendía. Con un solo contenedor el contador en archivos es exacto. |
| 1.2 | `last_used_at` se escribe **como mucho cada 10 min** por token, no en cada petición. | modelo propio `App\Models\PersonalAccessToken` + `Sanctum::usePersonalAccessTokenModel` | Bajo: el dato no se usa en la app; la expiración de Sanctum mira `created_at`/`expires_at`. |
| 1.3 | **Medición en producción:** cabecera `Server-Timing` (tiempo total, tiempo en base y nº de consultas) y una línea en el log de Render para toda petición de más de 1,5 s (ruta, ms, consultas). | middleware `MedirPeticion` | Bajo: solo agrega cabeceras/log. |
| 1.4 | Archivos `/assets/*` con caché de un año (`immutable`): llevan hash en el nombre, así que un despliegue nuevo los cambia solo. `index.html` y `sw.js` siguen sin caché. | `decasa-app/vercel.json` | Bajo. |

**Criterio de aceptación (medible):**
- Local: `CACHE_STORE=database bash scripts/perf/medir.sh supervisor /push/vapid-key` → **0 consultas** (antes 8).
- Local: dos peticiones seguidas con sesión → la segunda **sin `UPDATE last_used_at`**.
- Producción (después de subir): `curl -w "%{time_starttransfer}" …/api/push/vapid-key` **< 0,6 s** (antes 2,1 s).
- Tests de autenticación y build del front en verde.

**Ganancia esperada:** ~1,5–1,7 s menos en **cada** llamada a la API. Una pantalla
que hace 6 llamadas en paralelo baja de ~4–6 s a ~1–2 s.

### FASE 2 — Base cerca del servidor (infraestructura, la decide el usuario)

> **Confirmado 2026-10-07:** Aiven = DigitalOcean **NYC** (costa este) · Render =
> **Oregon** (costa oeste). Cada consulta cruza EE. UU. Desde Colombia, Virginia
> está ~65 ms más cerca por viaje que Oregón (123 vs 188 ms, medido).
> **Decisión recomendada: mover el servicio de Render a Virginia (US East).**
> La base no se toca; servidor↔base pasa de ~200 ms a ~5–10 ms por consulta y
> el usuario queda más cerca del servidor.

#### Mudanza de Render a Virginia, sin tiempo caído

Render no cambia la región de un servicio existente: se crea uno nuevo en
paralelo, se prueba y se cambia el tráfico. El viejo queda de respaldo.

1. **Subir la Fase 1** (trae `PROGRAMADOR_ACTIVO` en `entrypoint.sh`).
2. **Crear el servicio nuevo** en Render: *New → Web Service* → mismo repo,
   rama `main`, Root Directory `decasa-api`, Docker, **Region: Virginia (US East)**,
   mismo plan/instancia que el actual.
3. **Copiar TODAS las variables de entorno** del servicio actual (*Environment →*
   copiarlas una por una o con "Add from .env"): `APP_KEY` (¡la misma! si no,
   todo lo cifrado se vuelve ilegible), `DB_*`, `MAIL_*`, `CLOUDINARY_*`,
   `OPENAI_*`, `VAPID_*`, `GOOGLE_CLIENT_ID`, `REVERB_*`, etc. Además:
   - `PROGRAMADOR_ACTIVO=0` (el viejo sigue corriendo las tareas diarias).
   - `CACHE_STORE=file`.
   - `APP_URL` = la URL nueva (`https://<nuevo>.onrender.com`).
   - Las migraciones corren solas al arrancar; como el código es el mismo que
     ya está en producción, no hay nada pendiente: no toca la base.
4. **Probar el nuevo directamente** (sin que nadie lo use todavía):
   `curl -s -o /dev/null -w "%{time_starttransfer}\n" https://<nuevo>.onrender.com/api/push/vapid-key`
   → debe dar **< 0,5 s**, y `curl -sI …/api/push/vapid-key | grep -i server-timing`.
5. **Cambiar el tráfico** (en horario sin ventas):
   - `decasa-app/vercel.json`: las dos URLs `decasa-api-b91v.onrender.com` → la nueva.
   - En Vercel, la variable `VITE_REVERB_HOST` → host nuevo (tiempo real).
   - Desplegar el front. Desde ese momento todo va al servidor nuevo.
6. **Pasar las tareas diarias al nuevo:** en el nuevo `PROGRAMADOR_ACTIVO=1`;
   en el viejo `PROGRAMADOR_ACTIVO=0`. (Nunca los dos en 1 a la vez.)
7. **Vigilar un día** (log `[lenta]`, `Server-Timing`). Vuelta atrás = revertir
   el `vercel.json` y la variable de Vercel: el viejo sigue vivo.
8. **Apagar el viejo** (*Suspend*) y, días después, borrarlo. Actualizar
   `render.yaml` (`APP_URL`, y `region: virginia`) y `MEMORY.md`.

**Riesgos de la mudanza:** sesiones abiertas no se pierden (los tokens están en
la base, compartida). Las suscripciones push tampoco (también en la base, y
las llaves VAPID se copian). Lo único "en vuelo" son los trabajos de la cola en
el instante del cambio: la cola está en la base, así que el que esté corriendo
los toma igual.

#### Seguridad, de paso

Aiven dice **"IP address allowlist: Open to all"**: la base de producción
acepta conexiones desde cualquier IP de internet (solo la protege la
contraseña). Después de la mudanza, limitarla a las **IPs de salida de Render
Virginia** (*Render → servicio → Connect → Outbound IPs*) más la IP de quien
administre. No se hace antes, para no cortar al servicio de Oregón durante la
transición.

Lo que se revisó para decidir (referencia):

1. **Render** → servicio `decasa-api` → *Settings* → **Region** (Oregon, Ohio, Virginia, Frankfurt, Singapur).
2. **Aiven** → servicio MySQL → *Overview* → **Cloud / región**.
3. **Render** → *Environment*: confirmar `CACHE_STORE=file` (y que no haya `CACHE_LIMITER_STORE` raro).

Si están en regiones distintas:
- **Opción A (recomendada):** migrar el servicio de Aiven a la misma región que
  Render. Aiven lo hace en caliente (replica y cambia), sin perder datos; se hace
  en horario sin ventas.
- **Opción B:** crear el servicio de Render en la región de Aiven (requiere un
  servicio nuevo y cambiar la URL del backend en `vercel.json`).

Con la `Server-Timing` de la fase 1 se ve el antes y el después exacto (tiempo en
base / nº de consultas). **Ganancia esperada:** cada consulta pasa de ~200 ms a
~1–5 ms; es el cambio más grande de todo el plan.

2.4 (solo si después de lo anterior la conexión sigue pesando): conexiones
persistentes a MySQL (`PDO::ATTR_PERSISTENT`) detrás de una variable, limitando
los procesos de Apache para no pasarse del máximo de conexiones del plan de
Aiven. Se mide antes de dejarlo.

### FASE 3 — Arranque de la app más liviano (front + API, riesgo medio)

| Paso | Qué |
|---|---|
| 3.1 | El supervisor **no descarga la cola de despacho al abrir la app**: el número del menú sale de un conteo liviano (`GET /despacho/cola/conteo`) y la cola completa se carga al entrar a Despacho. Solo se pide si tiene `acceso_despacho`. |
| 3.2 | Revisar cada petición del arranque (`App.vue`) y pedir solo lo que esa persona puede usar (hoy algunas responden 403 y gastan el viaje igual). |
| 3.3 | Catálogo de telas (38 KB en cada apertura): guardarlo en el aparato y refrescarlo en segundo plano, como ya se hace con `modulos`. |
| 3.4 | (Opcional) un solo `GET /arranque` que devuelva me + módulos + contadores: 1 viaje en vez de ~8. |

**Aceptación:** número de peticiones y bytes al abrir la app (medido en el
navegador) baja a la mitad o menos; los contadores muestran lo mismo que antes.

**Hecho (2026-10-07, local, sin subir):**
- 3.1 ✅ `GET /despacho/cola/conteo` + store `despacho.cargarConteo()`; `App.vue`
  lo pide solo si `isSupervisor && puedeDespacho`. Las `listo_entrega` se
  cuentan en SQL; solo las del taller revisan ítems (`cabeEnRuta`). Local:
  **7,7 MB → 14 bytes**. Tests de la cola verifican `total == len(cola)`.
- 3.2 ✅ `GET /consultas-costo/conteo` (mismo alcance por rol que la lista,
  extraído a `soloLasQueLeTocan`); el store `consultas` ya no baja todas las
  consultas con ítems y desgloses. Test `ConteoConsultasCostoTest`.
- 3.3 ✅ Catálogo de telas a demanda (`asegurarCatalogoDB()`): lo piden los
  accesos `marcasOrdenadas` / `tiposTelaDeM` / `coloresDeTela`, y Telas y
  Surtir al montarse. Ya no viaja en cada apertura.
- Push: la llave VAPID se recuerda en el aparato (un viaje menos). La
  suscripción **se sigue mandando en cada apertura a propósito** (que los
  avisos lleguen pesa más; no se pudo verificar en qué casos el servidor
  borra suscripciones).
- 3.4 (`/arranque` único): **pospuesto**. Con 3.1–3.3 el arranque ya no
  descarga listas; se reevalúa con el log `[lenta]` de producción.

### FASE 4 — Respuestas pesadas (API, riesgo medio, una pantalla a la vez)

Para cada endpoint: elegir solo las columnas que la pantalla pinta, mover filtros
de PHP a SQL y paginar donde haya listas largas. Se compara la respuesta
antes/después (mismos campos que usa la pantalla) y se prueba la pantalla.

| Endpoint | Hoy (local) | Qué hacer |
|---|---|---|
| `/despacho/cola` | 7,7 MB, 5,6 s | columnas justas de orden/ítem/producto; filtrar `cabeEnRuta` lo más posible en SQL |
| `/stats/cartera` | ~1 MB | devolver lo que se muestra; detalle bajo demanda |
| `/reportes/pendientes` | ~860 KB | columnas justas, paginar |
| `/productos` | ~200 KB | columnas justas para listas/buscadores |
| Endpoints que no se pudieron medir en SQLite (`/stats/panel`, `/stats/tiendas`, `/comisiones`, `/produccion`) | — | medirlos en producción con `Server-Timing` (fase 1) y atacar los que salgan arriba en el log de lentas |

### FASE 5 — Ajustes finos (según lo que muestre el log de lentas)

- Índices compuestos donde el log lo justifique (ej. `notificaciones(usuario_id, created_at)`).
- Caché corta (30–60 s) de datos de catálogo que casi no cambian (tiendas, roles, tipos de proceso, módulos), invalidada al guardarlos.
- Llamar a Render directo desde el navegador en vez de por Vercel (−0,2 s/llamada) **solo si** vale la pena después de las fases 2–4 (requiere CORS con `max_age` para no pagar preflights).
- Bundle: `index` 270 KB + `api` 108 KB + CSS 100 KB iniciales; revisar qué entra en el arranque.

---

## 4. Riesgos y cómo se controlan

| Riesgo | Control |
|---|---|
| El limitador en archivos se reinicia en cada despliegue | Aceptable: es un tope anti-abuso de 300/min, no un dato de negocio. |
| Dos contenedores en el futuro | Ya documentado en `render.yaml`: con dos habría que pasar caché/limitador a Redis. |
| `last_used_at` menos preciso | Nadie lo usa; queda con precisión de 10 min. |
| `Server-Timing` revela nº de consultas | Solo tiempos y conteos, sin SQL ni datos. |
| Cambios de fase 3/4 rompen una pantalla | Una pantalla por cambio, comparar respuesta antes/después y probar en 390 px. |
| Desplegar en horario de ventas | Solo cuando el usuario diga "súbelo" (ver `MEMORY.md`). |

## 5. Cómo medir (feedback loop)

```bash
# Local: consultas y tiempo proyectado por endpoint (200 ms por consulta)
cd decasa-api && bash scripts/perf/medir.sh
CACHE_STORE=database bash scripts/perf/medir.sh supervisor /push/vapid-key   # C1
VER_SQL=1 bash scripts/perf/medir.sh supervisor /ordenes                      # consultas repetidas

# Producción: costo fijo por petición (no requiere sesión)
for i in 1 2 3; do curl -s -o /dev/null -w "%{time_starttransfer}\n" https://decasa-api-b91v.onrender.com/api/push/vapid-key; done

# Producción, después de la fase 1: desglose de cualquier respuesta
curl -sI https://decasa-api-b91v.onrender.com/api/push/vapid-key | grep -i server-timing
```

## 6. Registro de avances

| Fecha | Fase | Resultado medido |
|---|---|---|
| 2026-10-07 | Diagnóstico | vapid-key 2,1 s; login 3,2 s; /api/c 6 s; 8 consultas de limitador por petición |
| 2026-10-07 | Fase 1 implementada (local, **sin subir**) | Arnés con `CACHE_STORE=database`: vapid-key 8 → **0** consultas; `/tiendas` 12 → 4 (1ª) / 3 (siguientes, sin `UPDATE`); `/ordenes` 16 → 10. Proyectado a 200 ms/consulta: vapid-key 1,65 s → 0,05 s, `/ordenes` 3,3 s → 2,1 s. Test `CostoFijoDeCadaPeticionTest` 4/4 (rojo con el código viejo). Suite: 713 tests, solo los 10 fallos preexistentes. |
| 2026-10-07 | **Fase 1 en producción** ✅ | `vapid-key` 2,1 → **0,39 s** (0 consultas, 6 ms en el servidor); por Vercel 2,8 → **0,56 s**; login 3,2 → **0,33 s**; `/api/c` 6 → **3,4 s**. Assets con caché de 1 año; asset inexistente → 404. |
| 2026-10-07 | Fase 3 implementada (local, **sin subir**) | Arranque del supervisor: cola de despacho 7,7 MB → conteo de 14 bytes; consultas: lista completa → conteo; telas: 38 KB menos en cada apertura; VAPID desde el aparato. Suite: 715 tests, solo los 10 fallos preexistentes; build OK. |
| 2026-10-07 | Dato para la fase 2 | `/api/c`: `Server-Timing: app;dur=3037, db;dur=3019;desc="17 consultas"` → **~178 ms por consulta**, el 99 % del tiempo es la base lejos. También candidato a fase 4 (17 consultas para la portada de catálogos). |

**Archivos de la fase 1:** `config/cache.php` (`limiter`), `phpunit.xml`,
`app/Models/PersonalAccessToken.php`, `app/Providers/AppServiceProvider.php`,
`app/Http/Middleware/MedirPeticion.php`, `bootstrap/app.php`,
`decasa-app/vercel.json`, `tests/Feature/CostoFijoDeCadaPeticionTest.php`,
`scripts/perf/*` (arnés). Sin migraciones.
