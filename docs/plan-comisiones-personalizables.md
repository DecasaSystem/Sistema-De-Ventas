# Plan: comisiones personalizables

Estado: **Fase 0 hecha y subida a `main` (2026-10-27, commit 7e51c47, sin migración)**;
**Fases 1 y 2 hechas y subidas a `main` (2026-10-27, commit 12d38e3, traen migración)**;
**Fases 4 y 3 hechas y subidas a `main` (2026-10-27, commits 55f6b86 y 8723ad8, sin migración)**.
Queda solo lo que el dueño dejó para después (§9): fórmula del pool por tramos o con bono, y
forzar el cobro individual por persona.
Decisiones del dueño en el §8. Pedido del dueño: "que sea
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

*Cómo quedó (2026-10-27):* migración `2026_11_02_000001_reglas_de_comision`
(crea `comision_reglas`, agrega `comisiones.reglas_usadas` y `usuarios.acceso_reglas_comision`;
solo agrega). `ReglasComision::valor()` lee la tabla una vez por petición: por nivel
(persona > tienda > general) la última fila con `desde_mes` ≤ mes de la venta; una fila
con valor nulo en tienda o persona = "sin excepción desde aquí". `sql()` arma un CASE por
tienda y mes con la misma prioridad (literales, no `?`). En `abonadoParaMeta` la parte
del almacén pasó dentro del `SUM` (ONLY_FULL_GROUP_BY). Al pagar se guarda la foto de
todas las reglas (`ReglasComision::foto`, oculta en el JSON de `Comision`; deshacer el
pago la borra). API en `ReglaComisionController`: `GET /comisiones/reglas` (catálogo,
filas, `puede_editar`), `POST /comisiones/reglas` (crea o reemplaza la fila de esa
clave/alcance/desde; valida tipo, rango, nivel permitido y la tienda o persona),
`DELETE /comisiones/reglas/{id}`. Cada cambio: bitácora (`regla_nueva`,
`regla_cambiada`, `regla_quitada`), fechas de pago rehechas si fue `dia_pago` o
`meses_espera`, y recálculo pedido (corre por fuera, como el botón). Pruebas en
`ReglasDeComisionTest`.

**Fase 2 — Pantalla de Reglas + simulador + bitácora.** Incluye el interruptor del
permiso "Reglas de comisión" en la ficha del trabajador (el backend ya lo acepta).

*Cómo quedó (2026-10-27, sin migración nueva):* `ReglasComisionView`
(`/comisiones/reglas`, botón "Reglas" en Comisiones; la ve quien tiene Comisiones, en
solo lectura sin el permiso). Reglas por grupo (`ReglasComision::GRUPOS`) con el valor de
hoy, "Cambiada" si no es el de siempre, y la historia de cambios (para todos, tiendas,
personas; quién y por qué; quitar). Hoja para cambiar: para quién, valor según el tipo,
desde qué mes, por qué, y **simulador**: `POST /comisiones/reglas/simular` corre el resumen
del mes escogido dos veces dentro de una transacción que se deshace (sin y con la regla) y
devuelve la comisión de cada persona antes y después. Las reglas que cambian algo guardado
en cada renglón (`ReglasComision::SE_VEN_AL_RECALCULAR`: datáfono, parte de la compartida,
parte del almacén, día de pago, meses de espera) no se alcanzan a simular y se avisa. Si
el servidor pide confirmar (mes pasado sin pagos), la hoja lo muestra en ámbar con el botón
"Sí, aplicar desde…". En la ficha del trabajador, "Reglas de comisión" bajo "Acceso a
comisiones" (solo viaja si se movió). Revisada en 375 px y en oscuro con datos reales
sacados del backend.

**Fase 3 — Textos desde las reglas** (Ayuda, desglose, Excel, asistente).

*Cómo quedó (2026-10-27, commit 8723ad8, sin migración):* `GET /comisiones/reglas/vigentes?mes=`
da lo que rige ese mes (`general` = `ReglasComision::foto`) y las excepciones de tienda y
persona (`ReglasComision::excepcionesDelMes`, con nombre y valor en palabras). Quien no tiene
Comisiones recibe la general sin excepciones: la Ayuda es de todos, pero a quién se le paga
distinto no. En la app, `utils/reglasComision.js` la carga una vez por mes, la resuelve igual
que el cálculo (persona > tienda > general) y la escribe ("÷ 1,19 × 5%", "la mitad pagada",
"el 20 del mes siguiente"); sin conexión usa `DEFECTO` (lo de siempre). La leen: la Ayuda (con
los ejemplos recalculados y un aviso de las excepciones del mes), `DesgloseComision`,
`TotalesTienda` (el reparto dice "por días" o "partes iguales" con el `reparto_modo` del
servidor), el detalle de cada comisión en `ComisionesView` (incluido el "Sin IVA (÷1,19)" que
calculaba la pantalla) y el Excel (cada fila con las reglas de su tienda y persona; la hoja
"Cómo se calcula" con las generales y un aviso si hay excepciones). En el servidor:
`resumenParaAgente` / `avanceMetaParaAgente` y las reglas del prompt del asistente
(`AgentService::reglasDeComisiones`, que además lista las excepciones del mes). Con las reglas
de siempre los textos dicen lo mismo que antes. Ningún cálculo cambió: solo palabras.
Límite conocido: en una trimestral los textos usan las reglas del mes que se mira y el pool
las del último mes del trimestre; si una regla cambia a mitad de trimestre, el texto puede
quedar un mes atrás (la cifra no). Pruebas en `ReglasDeComisionTest` (vigentes y asistente).

**Fase 4 — Los repartos como opciones** (diseño 2026-10-27; **hecha y subida el 2026-10-27,
commit 55f6b86, sin migración nueva**: piezas 1–7 menos la 8 y la 9, que el dueño dejó para después;
ver §9).

*Cómo quedó:* reglas nuevas `bolson_modo` y `entra_al_bolson` (`ComisionIndependientes::delMes`),
`arrastra_deuda_trimestre` (`cargarPoolsTrimestrales`), `compartida_parte_vendedor` por tienda
y persona (`valorComisionable`; la persona es quien registró la venta, el covendedor se lleva
el resto), `reparto_pool` (`pesosDe` / `diasDe`: la parte sale del peso, los días se siguen
mostrando), `restauracion_suma_meta` (`sinRestauraciones` en SQL, `libroDelPool`,
`crearPartesDePool`, `enriquecer`, `equipoQueReparteRestauracion`) y `reparto_restauracion` +
`parte_autor_restauracion` (`sincronizarRestauracionEquipo`). Tipo nuevo `opcion` en el
catálogo, con su pantalla (tarjetas para escoger). **Semántica escogida para
`restauracion_suma_meta`** (para que no se pague doble): en esa tienda la restauración es una
venta más — suma a la meta, entra al pool sin IVA como toda venta del pool, ya no se paga
aparte ni se reparte por días; al bolsón de independientes entra igual. Cada pieza con sus
pruebas y prueba diferencial sobre una copia congelada del código de esa pieza.

La idea cambió frente al plan inicial: en vez de una columna `comision_esquema` en tiendas
y usuarios, cada forma de repartir es **una regla más del catálogo**, de un tipo nuevo
`opcion` (un valor de una lista cerrada que el código sabe calcular). Así hereda todo lo de
las Fases 1 y 2 sin hacer nada nuevo: fecha "desde", tienda/persona, simulador, foto al
pagar, bitácora, permiso. El valor por defecto de cada una es **lo que hace hoy el código**:
con la tabla vacía no cambia ni un peso (se verifica con la misma prueba diferencial).

| # | Lo que hoy está fijo | Regla nueva (tipo) | Opciones (la primera es la de hoy) | Alcance | Dónde se calcula |
|---|---|---|---|---|---|
| 1 | El pool se reparte por días | `reparto_pool` (opción) | `dias` · `iguales` (todos los del mes igual, sin mirar días) | tienda | `parteDelPool`, `crearPartesDePool` (en vivo) |
| 2 | Restauración en tienda: partes iguales entre los del día | `reparto_restauracion` (opción) + `parte_autor_restauracion` (fracción) | `iguales` · `autor_primero` (quien la hizo se lleva su parte y el resto se reparte igual) | tienda | `sincronizarRestauracionEquipo` (**guardado**: se ve tras recalcular) |
| 3 | Cada independiente cobra el % del bolsón **completo** (2 independientes = 10 % pagado) | `bolson_modo` (opción) | `cada_uno_completo` · `se_reparte` (el % del bolsón se parte entre los que entran) | general | `ComisionIndependientes::delMes` (en vivo) |
| 4 | Entran al bolsón todos los marcados independientes | `entra_al_bolson` (sí/no) | sí · no | persona | `ComisionIndependientes::delMes` (en vivo) |
| 5 | Las restauraciones nunca suman a la meta | `restauracion_suma_meta` (sí/no) | no · sí | tienda | `cargarTotales`, `totalesQueCuentan`, `libroDelPool` (SQL: el subquery de restauraciones pasa a un CASE por tienda) |
| 6 | La deuda del trimestre pasa al siguiente | `arrastra_deuda_trimestre` (sí/no) | sí · no | tienda | `cargarPoolsTrimestrales` (en vivo; lo ya liquidado no se mueve) |
| 7 | La parte de la compartida es una sola para todos | `compartida_parte_vendedor` pasa a admitir tienda y persona | — | +tienda, +persona | `valorComisionable` (**guardado**) |
| 8 | Pool = excedente sobre la meta, un solo % | `formula_pool` (opción) + `tramos_pool` (lista de tramos) + `bono_meta` (pesos) | `excedente` · `tramos` (p. ej. 0–100 % de la meta al 3 %, 100–120 % al 5 %, más de 120 % al 6 %) · `bono` (monto fijo al equipo al cumplir) | tienda | `libroDelPool`, `enriquecer`, trimestrales, `avanceDeMetas` y textos (lo más grande) |
| 9 | Quién cobra individual (fuera del equipo, supervisor en tienda con meta, tienda sin meta) | `cobro_individual` (opción) | `auto` · `siempre_individual` · `siempre_del_equipo` | persona | `cobraIndividual` (en vivo) |
| 10 | El 5 % del almacén que ayudó se parte igual entre su gente del día | — | Se queda como está: la orden no guarda quién del almacén pasó el contacto. | — | — |

Cómo se hace sin dañar nada:
- **Una pieza por vez, en este orden** (de menos a más riesgo): 4 y 3 (bolsón), 6, 7, 1,
  5, 2, 9, y 8 al final (toca la fórmula del pool en todas partes). Cada pieza con sus
  pruebas y la prueba diferencial con la tabla vacía y con la regla puesta en su valor de
  hoy (tiene que dar idéntico).
- **La pantalla** pinta las opciones con un nombre y una frase de ejemplo cada una
  ("Iguales: los tres se llevan lo mismo aunque uno haya estado 10 días"), y el simulador
  las muestra en vivo salvo las marcadas como **guardado** (2 y 7), que se suman a
  `SE_VEN_AL_RECALCULAR`.
- **Fase 3 primero o junto**: con estas opciones, la Ayuda, el desglose y el Excel ya no
  pueden decir "se reparte por días" ni "cada uno cobra el 5 % del bolsón" fijo; cada pieza
  cambia también su texto.
- La regla confirmada del pool **"todos los del equipo cobran igual, partido por días"**
  (MEMORY.md §2) es la opción por defecto; `iguales` es una variante del mismo principio.
  Repartir según lo que vendió cada uno **no se ofrece**: rompería esa regla (§9).

## 7. Lo que NO cambia

- Las reglas de hoy (50 %, día 20, meta, pool a nivel tienda, restauraciones sin IVA…):
  con la tabla vacía se calculan igual. Cambiarlas pasa a ser una decisión explícita
  desde la pantalla, con fecha, simulador y bitácora; no un cambio de código.
- `usuarios.independiente` sigue siendo lo que decide visibilidad y caja propia; el
  esquema solo decide cómo se calcula la comisión.
- Visibilidad de órdenes, anticipos, abonos, "fuera del pool", reemplazos: igual.

## 8. Decisiones del dueño (2026-10-27)

1. Alcance: números + opciones + esquemas, sin editor de fórmulas libre.
2. **Las cambia solo quien tiene un permiso aparte** (`usuarios.acceso_reglas_comision`),
   además del acceso a Comisiones. Los demás las ven. El permiso solo lo da o lo quita
   quien ya lo tiene; mientras nadie lo tenga, lo da un supervisor con acceso a
   Comisiones (`UsuarioController::update`). Así ningún supervisor se lo da a sí mismo.
3. **Hacia un mes pasado, sí, si no hay comisiones pagadas** de quienes toca la regla
   desde ese mes, y con confirmación (409 `requiere_confirmacion`). Con pagos, 422.
   Quitar una regla pasa por lo mismo.
4. **Excepciones por tienda y por persona**: gana la más específica.
5. **Estadísticas siguen 50/50** con la venta compartida: la regla solo cambia la comisión.
6. Esquemas nuevos (tramos, bono por meta…): ninguno pedido todavía (Fase 4).

## 9. Decisiones del dueño para la Fase 4 (2026-10-27)

Se hacen (todas con lo de hoy como valor por defecto):
1. **Bolsón:** sigue "cada uno cobra el % del bolsón completo", y se agrega la opción
   "el % se reparte entre ellos" (pieza 3) y sacar a una persona del bolsón (pieza 4).
2. **Pool:** "por días" (hoy) o "iguales" (pieza 1).
3. **Restauración en tienda:** "iguales" (hoy) o "quien la hizo primero", con la parte del
   autor como regla aparte que se pone al activarla (pieza 2).
4. **Restauraciones que suman a la meta**, por tienda (pieza 5).
5. **No arrastrar la deuda del trimestre**, por tienda (pieza 6).
6. La parte de la compartida por tienda y por persona (pieza 7): no se preguntó; va porque
   es pequeña y no cambia nada mientras no se use.

No se hacen por ahora: **fórmula del pool por tramos o bono** (pieza 8) y **forzar el cobro
individual por persona** (pieza 9). Quién decide: el mismo permiso "Reglas de comisión".

## 10. Diseño: pool por tramos o con bono fijo (pieza 8, 2026-10-27, sin programar)

Pedido del dueño ese día: "diseña el pool por tramos o con bono fijo y dime qué decisiones
necesitas". **No está programado**; esto es el diseño para que el dueño decida.

### Qué hay hoy

Todo pool sale de una sola cuenta: `ComisionController::ventasAPool(excedente)` =
(ventas que cuentan − meta) ÷ 1,19 × 5 %. La llaman cinco sitios: el libro del pool mensual
(`libroDelPool`), el renglón de cada comisión (`enriquecer`), el trimestre
(`cargarPoolsTrimestrales` y el trimestre viejo en `enriquecer`) y la cuenta del trimestre en
pantalla. La deuda de un trimestre se guarda **en pesos de pool** (`tienda_trimestres.deficit_*`)
y `poolAVentas` la vuelve "ventas que faltan" con la misma cuenta al revés. Eso solo funciona
porque la cuenta es una recta: con tramos o bono deja de tener un "al revés".

### La idea

Una regla más, de tipo opción y por tienda (hereda fecha, simulador, foto al pagar,
bitácora y permiso):

| Regla | Tipo | Opciones (la primera es la de hoy) |
|---|---|---|
| `formula_pool` | opción, general/tienda | `excedente` (hoy) · `tramos` · `bono` |
| `tramos_pool` | tipo nuevo **tramos**: lista de {desde % de la meta, %}, general/tienda | por defecto [{100 %, 5 %}] = lo de hoy |
| `bono_meta` | tipo nuevo **pesos**, general/tienda | por defecto 0 |

`ventasAPool` pasa a `poolDe(ventas, meta, tienda, mes)`, que mira `formula_pool`:
- `excedente`: exactamente la cuenta de hoy (la prueba diferencial lo tiene que dar idéntico).
- `tramos`: cada tramo paga su % (÷ 1,19) sobre lo que cae dentro de él.
- `bono`: un monto fijo al equipo al llegar a la meta.

Lo que **no** cambia, en ninguna opción: sin meta cumplida el pool es $0, la meta y el pool
se miden con lo que tiene el 50 % pagado, todo a nivel tienda, y el pool se reparte con
`reparto_pool` (por días o iguales). Las tres condiciones para cobrar quedan igual (dueño:
"no cambian nunca"). Por eso los tramos empiezan en el 100 % de la meta: un tramo "por
debajo de la meta" rompería la condición 3 y no se ofrece.

### Ejemplos (meta $40 M)

| La tienda llevó (con el 50 %) | Hoy (excedente al 5 %) | Tramos que suman (100–120 % al 5 %, >120 % al 6 %) | Tramos en escalón (al pasar 120 %, todo al 6 %) | Bono $300.000 | Bono + excedente |
|---|---|---|---|---|---|
| $39,9 M | $0 | $0 | $0 | $0 | $0 |
| $47,6 M (119 %) | $319.328 | $319.328 | $319.328 | $300.000 | $619.328 |
| $48,0 M (120 %) | $336.134 | $336.134 | **$403.361** | $300.000 | $636.134 |
| $50,0 M (125 %) | $420.168 | $436.975 | $504.202 | $300.000 | $720.168 |

El escalón da un salto: vender $400.000 más (de 119 % a 120 %) sube el pool $84.000. Los
tramos que suman no saltan. Es la primera decisión.

### Dónde se nota

- **Cálculo:** `poolDe` en los cinco sitios. En vivo (no guarda nada por renglón), así que el
  simulador lo muestra al instante.
- **Pantallas de avance** (Reportes, Estadísticas, Toda la tienda): hoy dicen "¡Meta
  alcanzada!" y "te falta $X". Con tramos pueden decir "te faltan $X para el 6 %".
- **Textos** (Ayuda, desglose, totales, Excel, asistente): la cuenta del pool deja de ser
  "÷ 1,19 × 5 %"; cada uno escribe los tramos o el bono (lo de la Fase 3 se amplía).
- **Pantalla de Reglas:** dos editores nuevos (lista de tramos, monto en pesos) con
  validación: tramos en orden, el primero en 100 %, % entre 0 y 20.
- **Trimestrales:** la deuda guardada en pesos de pool no se puede volver ventas con tramos
  ni con bono (ver decisión 3).

### Cómo se haría sin dañar nada

Mismo método que la Fase 4: `poolDe` con `excedente` primero y prueba diferencial idéntica
con la tabla vacía y con la regla en su valor de hoy; luego `tramos`, luego `bono`, cada uno
con sus pruebas y cifras sacadas a mano (las de la tabla de arriba). Sin migración: las
reglas nuevas son filas de `comision_reglas`.

### Decisiones que necesito del dueño

1. **Tramos: ¿suman o escalón?** Que cada tramo pague su % solo sobre lo que cae en él
   (recomendado: sin saltos), o que al pasar un tramo todo el excedente se pague con el %
   de ese tramo (más premio, pero con el salto de la tabla).
2. **Bono: ¿en vez del excedente o encima?** Solo el monto fijo, o el monto fijo más el
   pool de siempre. Y ¿un solo bono al llegar a la meta, o un bono por escalón (100 % →
   $200.000, 120 % → $400.000)?
3. **Tiendas trimestrales: ¿también o solo mensuales?** Recomendado: primero solo mensuales
   (la regla no se deja poner en una trimestral). Para las trimestrales habría que guardar
   la deuda en ventas y no en pesos de pool, lo que cambia cómo se ve la deuda de los
   trimestres ya liquidados.
4. **El bono, ¿es para el equipo o por persona?** Un monto fijo que se reparte (por días o
   iguales, como el pool), o un monto por cada integrante (el equipo más grande cuesta más).
5. **¿Las pantallas de avance muestran el siguiente tramo?** ("te faltan $1,6 M para el 6 %").
   Recomendado sí: es lo que hace que el tramo motive.

### Lo que decidió el dueño (2026-10-27)

1. **Los tramos suman**: cada tramo paga su % solo sobre lo que cae en él (sin saltos).
2. **El bono va encima del pool**: el pool de siempre (o por tramos) más el monto fijo.
3. **El bono es para el equipo**: un monto que se reparte como el pool (por días o iguales).
4. **Solo tiendas mensuales**: la regla no se deja poner en una tienda trimestral; las
   trimestrales siguen con el pool de siempre.
5. No se preguntó: se toma lo recomendado (las pantallas de avance muestran el siguiente
   tramo), y se puede quitar si no le gusta.

Con eso el diseño queda más simple: `formula_pool` = `excedente` (hoy) · `tramos`, y
`bono_meta` (pesos, 0 = sin bono) se suma aparte en cualquiera de las dos, solo cuando la
tienda llega a la meta. Ejemplo (meta $40 M, tramos 100–120 % al 5 % y >120 % al 6 %, bono
$300.000): con $50 M → $436.975 + $300.000 = $736.975 para el equipo; con $39,9 M → $0.

### Cómo quedó (2026-10-27, sin subir, sin migración)

Reglas nuevas en el grupo "Pool de la tienda": `formula_pool` (opción `excedente` · `tramos`),
`tramos_pool` (tipo nuevo `tramos`) y `bono_meta` (tipo nuevo `pesos`). Por defecto: excedente,
un tramo [100 %: 5 %] y bono $0, o sea lo de siempre. El pool mensual sale de
`ComisionController::poolDe` / `desglosePool` (en `libroDelPool` y en `enriquecer`); las
trimestrales siguen con `ventasAPool` y la regla no se deja poner en una tienda trimestral
(422). Validación: el primer tramo en 100 %, hasta 6, entre 100 % y 500 % de la meta, cada uno
entre 0 % y 20 %; bono entre $0 y $100 M. Pantallas: editor de tramos y casilla en pesos en
Reglas; "Toda la tienda" muestra un renglón por tramo, el bono y cuánto falta para el siguiente
tramo (el servidor manda `pool.cuenta` solo si la tienda usa tramos o bono); detalle de la
comisión, Ayuda, Excel y asistente (`cuentaDelPoolTexto`, `siguiente_tramo`) lo escriben.
Pruebas en `ReglasDeComisionTest` con las cifras de la tabla. Prueba diferencial con la tabla
vacía y con las reglas en su valor de hoy: ningún monto cambia (solo textos de la Fase 3 y los
campos agregados a propósito).
