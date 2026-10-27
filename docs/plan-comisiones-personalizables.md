# Plan: comisiones personalizables

Estado: **Fase 0 hecha (2026-10-27, sin subir, sin migración)**; Fases 1–4 sin
empezar y esperan las decisiones del §8. Pedido del dueño: "que sea
personalizado… ahora es algo fijo que yo hago en el código; que sea totalmente
personalizable por si algún día cambian el funcionamiento de todo, sin dañar nada".

Análisis completo del módulo actual: `docs/PROYECTO.md` §6.10 y `ComisionController`.

---

## 1. Qué está fijo hoy en el código

| Regla | Valor hoy | Dónde vive |
|---|---|---|
| % del pool | 5 % | `ComisionController::PORCENTAJE_DIRECTO` |
| % individual (sin meta / tienda que no comparte) | 5 % | ídem |
| % restauración | 5 % | ídem |
| Quitar IVA a la venta antes del % | sí, ÷ 1,19 | `ComisionController::IVA`, `ComisionIndependientes::IVA` |
| Quitar IVA a la restauración | no | `enriquecer`, `ComisionIndependientes` |
| Franquicia del datáfono | 5,5 % | `COSTO_TARJETA` (también dentro de SQL en `abonadoParaMeta`) |
| Medios con franquicia | tarjeta, Addi | `Orden::METODOS_CON_FRANQUICIA` (lo comparten descuentos y Finanzas) |
| Requisito de pago del cliente | 50 % de la orden | `enriquecer`, `libroDelPool`, `totalesQueCuentan` (SQL `* 0.5`), `abonadoParaMeta` (SQL `/ 2`), `ComisionIndependientes` |
| La meta solo cuenta lo que tiene el 50 % | sí | `totalesQueCuentan`, `libroDelPool` |
| Restauraciones esperan el 50 % | sí | `enriquecer`, `ComisionIndependientes::delMes` |
| Día de pago | 20 del mes siguiente (trimestral: 20 tras el cierre) | `calcularFechaDisponible`, `fechaLiquidacion`, `ultimoDiaDePago`, `avisarDiaDePago`, `ComisionIndependientes` |
| Venta compartida | mitad y mitad | `valorComisionable`, `conLaBaseQueDeVerdadComisiona` |
| Reparto del pool | por días en la tienda | `parteDelPool`, `TiendaReemplazo::pesosDelMes` |
| Restauración en tienda que comparte | se parte entre los que estaban ese día | `sincronizarRestauracionEquipo` |
| Independientes: venta | 5 % de lo suyo (÷1,19) | `ComisionIndependientes::PORCENTAJE` |
| Independientes: bolsón | cada uno el 5 % del bolsón completo, incluye las de almacenes | `ComisionIndependientes::delMes` |
| Almacén abonado | su gente se reparte el 5 %; a la meta le suma la mitad (solo ventas) | `sincronizarAbonoAlmacen`, `abonadoParaMeta` |
| Trimestral: arrastre de déficit | desde 2026-Q3 | `TRIMESTRE_BASE` |

Ya son configurables (no se tocan, se integran): meta y equipo por tienda y mes,
`comisiones_compartidas`, `comision_periodicidad`, anticipos, reemplazos/traslados,
"fuera del pool" y "sin descontar IVA" por orden.

El texto de las reglas también está fijo en: `AyudaComisionesView.vue`,
`TotalesTienda.vue`, `DesgloseComision.vue`, `utils/excelComisiones.js` y las notas
de `resumenParaAgente` / `avanceMetaParaAgente` (asistente de IA). Si la regla pasa a
ser un dato y el texto no, la pantalla diría "5 %" mientras se paga 6 %.

## 2. La idea: las reglas son datos con fecha, el código calcula

Es el mismo patrón que ya funciona en Nómina (`CostoEmpleador`, decisión del dueño
2026-10-10: "cambia la ley y no a todos les toca lo mismo"):

1. **Cada regla es una fila con "desde qué mes"**. Un cambio se carga "desde
   noviembre" y no mueve agosto. La regla que aplica a una comisión es la vigente en
   su **mes de venta**, no la de hoy.
2. **Por alcance, la más específica gana:** persona > tienda > general.
   Ejemplo: general 5 %, Unicentro 4 %, Paola 6 %.
3. **Lo pagado queda congelado con las reglas que se usaron** (`comisiones.reglas_usadas`),
   igual que `nomina_pagos.costo_empleador_detalle`. Cambiar una regla nunca mueve lo pagado.
4. **Sin filas, todo da exactamente lo de hoy.** Los valores por defecto viven en un
   catálogo en código y son las constantes actuales. Una tabla vacía = el sistema de
   hoy. Así los tests con esquemas a mano y producción recién migrada no cambian nada.

### Hasta dónde llega lo "personalizable"

- **Números y opciones** (porcentajes, IVA sí/no, % de pago exigido, día de pago,
  reparto por días/igual/por ventas, qué entra a la meta, cómo se parte una compartida…):
  se cambian **desde la pantalla**, sin programar.
- **Formas de pagar ("esquemas")**: cada tienda y persona usa un esquema de un
  catálogo hecho en código: `pool_sobre_meta` (el de hoy), `porcentaje_directo` (hoy:
  sin meta / no comparte), `independiente` (hoy). Una forma **nueva** que el negocio
  invente (por ejemplo tramos: 3 % hasta la meta y 6 % encima) se programa **una vez**
  como esquema nuevo, con sus pruebas, y desde ahí se configura desde la pantalla.
- **Recomendado no hacer** un editor de fórmulas libre: un error de dedo pagaría mal a
  todo el mundo sin ninguna prueba que lo frene, y no se podría explicar en la Ayuda ni
  en el Excel. Con esquemas + parámetros se cubre "cambiar cómo funciona" sin ese riesgo.

## 3. Modelo de datos (solo agrega, regla 7)

```
comision_reglas
  id
  clave          string(50)   -- del catálogo: 'pct_pool', 'req_pagado_pct', 'dia_pago'…
  alcance        string(10)   -- 'general' | 'tienda' | 'usuario'
  alcance_id     bigint null  -- tienda_id o usuario_id
  valor          json         -- número, booleano o texto según el catálogo
  desde_mes      char(7)      -- 'YYYY-MM'
  nota           string(200) null
  creado_por     fk usuarios null
  timestamps
  unique(clave, alcance, alcance_id, desde_mes)

comisiones.reglas_usadas   json null   -- foto de las reglas al pagar
tiendas.comision_esquema   string null -- null = el que sale de hoy (meta/comparte)
usuarios.comision_esquema  string null -- null = el de su tienda / independiente
```

Catálogo en código (`App\Support\ReglasComision`, como `TiposNotificacion`): por cada
clave, tipo, unidad, rango permitido, valor por defecto (= el de hoy), a qué alcances
aplica y la frase que la explica. Un test falla si alguna regla del cálculo no está en
el catálogo.

Claves propuestas (todas con el valor de hoy por defecto):

| Clave | Defecto | Alcances |
|---|---|---|
| `pct_pool` | 0,05 | general, tienda |
| `pct_individual` | 0,05 | general, tienda, usuario |
| `pct_restauracion` | 0,05 | general, tienda, usuario |
| `iva_divisor` | 1,19 | general |
| `iva_en_venta` / `iva_en_restauracion` | sí / no | general, tienda |
| `franquicia_pct` | 0,055 | general |
| `franquicia_metodos` | [tarjeta, addi] | general (solo comisiones) |
| `req_pagado_pct` | 0,5 | general, tienda |
| `req_pagado_restauracion` | sí | general, tienda |
| `meta_solo_con_pagado` | sí | general, tienda |
| `dia_pago` / `meses_espera` | 20 / 1 | general, tienda |
| `compartida_parte_vendedor` | 0,5 | general |
| `reparto_pool` | 'dias' ('igual', 'ventas') | tienda |
| `restauracion_se_reparte` | sí | tienda |
| `indep_pct_venta` / `indep_pct_bolson` | 0,05 / 0,05 | general, usuario |
| `bolson_incluye_almacenes` | sí | general |
| `almacen_pct` / `almacen_fraccion_meta` | 0,05 / 0,5 | general, tienda |

## 4. Cómo se lee en el cálculo

`ReglasComision::para(string $mes, ?int $tiendaId, ?int $usuarioId): Reglas`
devuelve un objeto con todas las claves ya resueltas. La tabla es pequeña: se lee
**una vez por petición** y se resuelve en memoria (registrar el caché en
`CachesDePeticion::olvidarTodo()` y limpiarlo en `cargarTotales()`, porque el
controlador sobrevive a la petición).

Los sitios difíciles, y cómo resolverlos:

1. **Sumas en SQL con la regla adentro** (`totalesQueCuentan` con `* 0.5`,
   `abonadoParaMeta` con `/ 2` y el 5,5 % en SQL, `cargarTotales`). Si el % exigido
   puede variar por tienda y mes, una constante en el SQL ya no sirve. Solución: que la
   consulta traiga por orden `valor`, `pagado` y `con_tarjeta` (las mismas consultas,
   más filas) y el filtro se haga en PHP con la regla de cada tienda y mes. Medir con
   `scripts/perf/medir.sh` antes y después: no puede subir el número de consultas.
2. **`comisiones.valor_orden` ya trae aplicadas la franquicia y la mitad** de la
   compartida (`valorComisionable`). Con reglas, se calcula con las reglas del **mes de
   la orden**. `sincronizarValorOrden` ya pasa por ahí en cada cambio, así que basta con
   que use `ReglasComision::para(mes de la orden…)`.
3. **`fecha_disponible` está guardada.** Al cambiar `dia_pago` / `meses_espera` se
   recalcula en lo no pagado, igual que hace hoy `setPeriodicidad`.
4. **`METODOS_CON_FRANQUICIA` lo comparten tres módulos** (comisiones, descuento
   condicionado y Finanzas → ventas). La regla nueva cambia solo comisiones; descuentos
   y Finanzas siguen con la constante. Tener esto claro al implementar.
5. **Estadísticas parte la compartida en mitades** (`StatsController`, ~20 sitios con
   `es_compartida … / 2`). Eso es "cuánto vendió cada uno", no comisión: no se toca
   (decisión pendiente, §8).
6. **Trimestres ya liquidados** (`tienda_trimestres.cerrado_at`) se quedan como están:
   el pool de un trimestre usa las reglas de su último mes y se congela al liquidar.
7. **Textos**: la API devuelve con el resumen las reglas que aplican (`reglas` por
   tienda/persona) y Ayuda, `TotalesTienda`, `DesgloseComision`, el Excel y las notas
   del asistente las pintan desde ahí. Ningún "5 %" escrito a mano.

## 5. Pantalla: Comisiones → Reglas

- Lista por regla: valor general, excepciones por tienda y por persona, cada una con
  "desde" e historial (quién, cuándo, por qué).
- **Simulador antes de guardar**: "con esta regla, este mes quedaría así": comisión
  de cada persona antes y después y la diferencia. Ojo: `resumenDelMes()` escribe (abre
  renglones de pool), así que el simulador necesita un modo de solo lectura.
- **Solo hacia adelante por defecto.** Aplicar a un mes que ya tiene pagos se frena (lo
  pagado no se mueve y la tienda quedaría con dos reglas en el mismo mes). Aplicar a un
  mes pasado **sin** pagos pide confirmación explícita, como `setMeta` con `forzar`.
- Todo cambio queda en `comisiones_bitacora` (`anotar('regla', …)`).
- Patrón visual de la app (pastillas, tarjetas, hoja inferior), 390 px y modo oscuro.

## 6. Fases (cada una se puede subir sola)

**Fase 0 — Sacar las constantes, sin cambiar nada (sin migración).**
`ReglasComision` con el catálogo y los valores de hoy; reemplazar cada constante y cada
número suelto del §1 por una lectura de reglas. Verificación:
- **prueba diferencial**: `GUARDAR=dir bash scripts/perf/medir.sh` con el código viejo y
  con el nuevo, y `cmp`: tiene que dar **idéntico byte a byte** (como la fase 4 de
  rendimiento);
- las ~16 clases de pruebas de comisiones y la suite completa, sin fallos nuevos.
Es la fase más importante: si aquí algo cambia un peso, se para.

*Cómo quedó (2026-10-27):* `App\Support\ReglasComision` con el catálogo (las claves del
§3 menos `reparto_pool`, que llega con los esquemas en la Fase 4; `iva_en_venta` /
`iva_en_restauracion`, y la meta tiene su propia regla `meta_req_pagado_pct`, aparte de
`req_pagado_pct` para cobrar). `valor($clave, $mes, $tienda, $persona)` hoy devuelve el
valor por defecto; `sql()` / `sqlEnLista()` ponen la regla dentro de las sumas en SQL
como **literal** (no parámetro: MySQL trata `0.055` escrito como DECIMAL exacto y un
`?` como DOUBLE, y `ROUND(100 * 0.055)` cambiaría). Se pasaron a reglas
`ComisionController` (enriquecer, libro del pool, trimestres, fechas de pago, aviso del
20, base comisionable, datáfono, restauración de equipo, `totalesQueCuentan`) y
`ComisionIndependientes` (bolsón, ventas, almacenes, `abonadoParaMeta`). En una venta
compartida el covendedor ya lleva su propia parte (`valorComisionable($orden, $vendedorId)`).
Los textos ("÷ 1,19 × 5 %") siguen fijos: son la Fase 3.

Verificado: prueba diferencial con una base sintética de 8.000 órdenes que pasa por
todos los caminos (pool mensual y trimestral con deuda, tienda que no reparte y sin
meta, tarjeta/Addi, pagos bajo la mitad, restauraciones enteras y mezcladas,
compartidas, digitales, independientes con almacén, FV2 sin IVA, fuera del pool,
reemplazos, traslado, meses ya pagados): 301 salidas (lecturas, pagos, deshacer,
rehacer comisiones, tarea de las 6:30, aviso del 20 y las tablas después de cada
paso) idénticas byte a byte con el código viejo y el nuevo. Dos corridas del código
viejo también dieron idénticas (la prueba no tiene azar). Suite completa sin fallos y
`tests/Unit/ReglasComisionTest` fija el valor de hoy de cada regla. Los scripts de la
prueba quedaron en el scratchpad de esa sesión; para repetirla: reloj congelado
(`Carbon::setTestNow`), `CACHE_STORE=array` **y** `CACHE_LIMITER_STORE=array` (el límite
de 300/min guarda en archivos y con el reloj quieto nunca se vacía: daba 429 al azar).

Para la Fase 1, ojo: `abonadoParaMeta` agrupa por almacén y mes con alias; un CASE que
mire `created_at` dentro del `SUM` puede chocar con ONLY_FULL_GROUP_BY en MySQL (ponerlo
dentro del SUM, por fila, o agrupar por la expresión).

**Fase 1 — Tabla de reglas y foto al pagar (migración que solo agrega).**
`comision_reglas`, `comisiones.reglas_usadas`. Con la tabla vacía todo sigue igual
(se vuelve a correr la prueba diferencial). Pruebas nuevas por regla: cambiarla desde
el mes M cambia M y lo siguiente, no cambia M−1, no cambia lo pagado, y el pago guarda
la foto.

**Fase 2 — Pantalla de Reglas + simulador + bitácora.**

**Fase 3 — Textos desde las reglas** (Ayuda, desglose, Excel, asistente).

**Fase 4 — Esquemas por tienda y por persona** (`comision_esquema`) y, si el dueño lo
pide, el primer esquema nuevo (por ejemplo tramos).

## 7. Lo que NO cambia

- Las reglas de hoy (50 %, día 20, meta, pool a nivel tienda, restauraciones sin IVA…):
  con la tabla vacía se calculan igual. Cambiarlas pasa a ser una decisión explícita
  desde la pantalla, con fecha, simulador y bitácora; no un cambio de código.
- `usuarios.independiente` sigue siendo lo que decide visibilidad y caja propia; el
  esquema solo decide cómo se calcula la comisión.
- Visibilidad de órdenes, anticipos, abonos, "fuera del pool", reemplazos: igual.

## 8. Decisiones del dueño antes de la Fase 1

1. ¿Alcance? Números + opciones + esquemas (recomendado), o algo más libre.
2. ¿Quién puede cambiar reglas? ¿Cualquier supervisor con acceso a comisiones o un
   permiso nuevo (`acceso_reglas_comision`) solo para el dueño?
3. ¿Se permite aplicar una regla a un mes pasado sin pagos (con confirmación) o solo
   desde el mes en curso?
4. ¿Excepciones por persona (ej. "Paola cobra 6 %")? El modelo lo soporta; es decisión
   del negocio.
5. ¿Las Estadísticas siguen partiendo la venta compartida 50/50 aunque la comisión se
   parta distinto?
6. ¿Algún esquema nuevo que ya tengan en mente (tramos, bono por meta cumplida…)?
