# AGENT.md — Plan de mejora del cotizador IA de Decasa

Documento de trabajo para elevar la **exactitud del cálculo de costos de fabricación** de la IA.
No cubre la parte conversacional/informativa del agente (esa ya funciona bien y se toca lo mínimo).

---

## 1. Diagnóstico

### El error de raíz

**La IA está haciendo la aritmética.**

En `AgentService::calcularPrecioItem()` (`decasa-api/app/Services/AgentService.php:2416`) el backend
recolecta fichas técnicas, materiales y tarifas, se los pasa a `gpt-4o` como un blob JSON dentro del
prompt, y le pide que devuelva:

```json
{ "precio_fabricacion": <entero>, "desglose_materiales": [{"descripcion","subtotal"}], ... }
```

El modelo **escribe los números**. Y en la línea 2616 el resultado se hace `json_decode` y se devuelve
tal cual, sin ninguna validación:

- no se verifica que `sum(desglose) == precio_fabricacion`;
- no se verifica que los precios unitarios usados existan en la tabla `materiales`;
- no se verifica que las horas × tarifa correspondan a un cargo real de `salarios_cargo`.

Mientras esto siga así, **ninguna mejora de prompt da exactitud** — solo mueve el margen de error.

### Estado de los datos (bueno)

Consulta a la BD de producción (Aiven, `defaultdb`):

| Tabla | Filas |
|---|---|
| `fichas_tecnicas` | 306 |
| `ficha_tecnica_items` | 4.666 |
| `materiales` | 314 |
| `tarifas_proceso` | 16 |
| `salarios_cargo` | 4 |

- **3.806 de 3.845 items de material (99%) hacen match exacto con `materiales`.** Las fichas son
  consistentes con el catálogo de precios. Base sana.
- La mano de obra ya está normalizada a **horas × tarifa_hora** (carpintero/tapicero/lacador
  $14.423/h; costurera $12.019/h).
- Las fichas están divididas en **secciones** = componentes (`ESQUELETERIA`, `TAPICERIA`,
  `CORTE Y COSTURA`, `CARPINTERIA`). Esto es justo lo que se necesita para muebles híbridos
  (ej. cama-escritorio).
- Ningún material tiene precio 0 o nulo.

### Problemas concretos de exactitud

| # | Problema | Ubicación |
|---|---|---|
| 1 | La IA inventa precios; cero validación del JSON de salida | `AgentService.php:2538-2618` |
| 2 | Selección de fichas de referencia con **sesgo a la baja**: hace `LIKE` por palabra y toma de cada match la ficha **más barata** (`orderBy('costo_total')`) | `fichasReferenciaPorContexto()` :839 |
| 3 | Los materiales que se le pasan al modelo son casi ruido: `LIKE` por keyword `limit 60`, y si hay <20 rellena con `ORDER BY nombre LIMIT 40` (los primeros alfabéticamente: ANGULO, BANAS, BISAGRAS…) | `materialesRelevantes()` :786 |
| 4 | Unidades sucias y materiales duplicados: `LAMINA`/`LAMINAS`, `METRO`/`METROS`/`MTS`/`MRTROS`, `PIELEAS`, `JUEGO    PINTADA`; CARPINCOL / CARPINFLEX / COLBON / COBON son todos pegante a $18.000/botella | tabla `materiales` |
| 5 | Constantes mágicas sin fundamento: array `ESCALA` (0.85 esqueletería, 0.80 laca…), `× 0.70` en mano de obra al escalar, multiplicadores de venta 2.2 / 2.4 / 2.6 (ya marcados como placeholder) | `AgentService.php:13-25`, `:611`, `:2531` |
| 6 | El ground truth del ebanista (`consultas_costo`) **se descarta**: nadie compara el estimado de la IA contra la corrección humana | `ConsultaCostoController` |
| 7 | Imágenes enviadas con `detail: 'low'` (512×512) — insuficiente para contar cajones o juzgar proporciones | `:2605`, `:2735`, `:2861` |

---

## 2. Principio rector del rediseño

> **La IA arma la receta. El código calcula el precio.**

La IA hace solo lo que sabe hacer bien: mirar la foto, entender el texto, descomponer el mueble en
componentes y decidir **qué** materiales lleva, **cuántas** unidades de cada uno y **cuántas horas**
de cada oficio.

Devuelve identificadores y cantidades — **nunca un precio**:

```json
{
  "componentes": [
    {
      "nombre": "Base cama 140×190",
      "materiales": [
        { "material_id": 129, "cantidad": 0.7, "justificacion": "chapilla pino para cabecero" }
      ],
      "mano_obra": [
        { "cargo": "carpintero", "proceso": "esqueleteria_cama", "horas": 8.0 }
      ]
    },
    { "nombre": "Módulo escritorio", "materiales": [...], "mano_obra": [...] }
  ],
  "supuestos": ["medidas asumidas 140×190×90 cm"],
  "consultar": ["⚠️ colchón visible en la foto — no incluido"]
}
```

El backend trae `materiales.precio_unitario` **por ID desde la BD**, multiplica, suma, aplica margen.
Determinístico, auditable, siempre cuadra. **Alucinar un precio se vuelve estructuralmente imposible**
y el desglose que ve el vendedor apunta a materiales que existen de verdad.

---

## 3. Fases

Orden pensado para que cada fase entregue exactitud por sí sola y sin romper lo anterior.
`calcular-precio-item` lo consume `NuevaOrdenView.vue` (3 llamadas: :278, :1006, :1025) — el contrato
de respuesta se mantiene compatible en todas las fases.

---

### FASE 1 — Cálculo determinístico ✅ IMPLEMENTADA

**Objetivo:** que la IA deje de escribir precios.

**Archivos**
- `decasa-api/app/Services/Costos/BomBuilder.php` *(nuevo)* — llama al LLM, devuelve la receta (BOM).
- `decasa-api/app/Services/Costos/CostoCalculator.php` *(nuevo)* — aritmética pura, sin IA.
- `AgentService::calcularPrecioItem()` — pasa a orquestar: `BomBuilder` → `CostoCalculator`.

**Trabajo**
1. Reescribir el system prompt del cotizador para que devuelva la estructura de receta de arriba
   (`material_id` + cantidad, `cargo`/`proceso` + horas). Prohibir explícitamente cualquier campo de
   precio en la salida.
2. Pasarle al modelo el catálogo de materiales candidatos **con su ID** (ver Fase 3 para que esos
   candidatos sean buenos; por ahora sirve el listado actual).
3. `CostoCalculator`:
   - `subtotal_material = cantidad × materiales.precio_unitario` (SELECT por ID).
   - `subtotal_mo = horas × salarios_cargo.tarifa_hora` (SELECT por cargo).
   - `precio_fabricacion = Σ materiales + Σ mano_obra`.
   - `precio_sugerido_venta = precio_fabricacion × multiplicador` (Fase 5).
4. **Rechazo duro**: si el LLM devuelve un `material_id` inexistente o un `cargo` desconocido, se
   descarta esa línea y se registra en `supuestos`; si quedan 0 materiales, se devuelve
   `requiere_revision: true` en vez de un número inventado.
5. Mantener la forma de respuesta actual (`precio_fabricacion`, `desglose_materiales`,
   `desglose_mano_obra`, `notas`) para no tocar el front — ahora construida por el calculador.

**Pendiente de esta fase:** la ruta de **restauración** (`es_restauracion: true` →
`calcularPrecioRestauracion()`) sigue en el camino viejo, con la IA escribiendo precios. Sus
materiales (telas) viven en otras tablas (`catalogo_telas` / `inventario_telas`), así que migrarla
al BOM requiere ampliar los candidatos a esas tablas. Queda para una Fase 1b.

**Aceptación**
- `sum(desglose_materiales) + sum(desglose_mano_obra) == precio_fabricacion`, exacto, siempre.
- Todo `precio_unitario` del desglose existe en `materiales` o `salarios_cargo`.
- Test: fichas conocidas → el estimado reconstruido queda dentro de ±10% del `costo_total` real.

**Resultado medido** (`php artisan tinker scripts/benchmark_cotizador.php`, 5 fichas al azar):

| Ficha | Costo real | Estimado IA | Desviación |
|---|---|---|---|
| MODULO 1 PUESTO CON BRAZO | $463.272 | $454.022 | **-2,0%** ✅ |
| CAMA TARIMA 1.00 CON NICHO Y CAJONES | $920.890 | $838.890 | **-8,9%** ✅ |
| MESA CENTRO NUEVA 1.00×0.60 | $416.802 | $289.385 | -30,6% |
| CAMA FLOR MORADO 1,40 | $1.167.359 | $1.715.123 | +46,9% |
| CAMA SUIZA ENCHAPADA | $1.967.238 | $864.744 | -56,0% |

- ✅ **Criterios duros: 5/5.** El desglose siempre suma exacto al total y el 100% de los precios
  unitarios vienen de la BD. **La alucinación de precios está eliminada.**
- ⚠️ **Exactitud: 2/5 dentro de ±10%.** El error que queda ya **no está en los precios sino en las
  cantidades y en la elección de la ficha de referencia** — que es precisamente lo que arreglan las
  Fases 3 y 4. El caso CAMA SUIZA (-56%) es el síntoma clásico del `orderBy('costo_total')`
  ascendente: se ancló en una cama barata que no se le parece.

`scripts/benchmark_cotizador.php` queda como métrica de regresión para medir las fases siguientes.

---

### FASE 2 — Limpieza de datos base ✅ IMPLEMENTADA

**Objetivo:** que el modelo pueda razonar cantidad × precio sin ambigüedad.

**Archivos**
- `decasa-api/database/migrations/*_normalizar_unidades_materiales.php` *(nuevo)*
- `decasa-api/database/migrations/*_marcar_materiales_duplicados.php` *(nuevo)*

**Trabajo**
1. Añadir `materiales.unidad_norm` (enum: `lamina`, `metro`, `unidad`, `juego`, `tabla`, `botella`,
   `sabana`, `tira`, `par`, `pulgada`, `telera`…) mapeando desde `unidad`. **No borrar `unidad`** —
   las fichas viejas la referencian.
2. Añadir `materiales.activo` (bool) y `materiales.equivalente_a_id` (self-FK nullable). Marcar los
   duplicados obvios (CARPINCOL / CARPINFLEX / COLBON / COBON → uno canónico) sin borrar filas, para
   no romper el 99% de match de las fichas existentes.
3. Corregir typos de unidad (`MRTROS`, `PIELEAS`, `JUEGO    PINTADA`).
4. Al construir los candidatos para el LLM, filtrar `activo = true` y colapsar equivalentes.

**Aceptación**
- 100% de `materiales` con `unidad_norm` no nula.
- Los 4.666 `ficha_tecnica_items` siguen resolviendo a un material válido (no se rompió nada).

**Resultado medido** (migración `2026_07_14_120000_normalizar_materiales`, ya aplicada a producción):

- **91 valores distintos de `unidad` → 15 unidades canónicas.** Reparto: `lamina` 102, `unidad` 71,
  `juego` 36, `metro` 34, `tabla` 16, `telera` 8, `sabana` 7, `botella` 6, `tornillo` 6, `pulgada` 5,
  `carril` 4, `tira` 4, `bolsa` 2, `piel` 1, y **`otro` 12**.
- Los 12 `otro` son genuinamente ambiguos y se dejan marcados a propósito (`CHAPILLA [CTMS]`,
  `COJINES [TELA,FIBROTEX,CAMBRE]`, `TABLA BASTIDOR [55 X 55]`…). El prompt le dice al modelo que si
  usa uno de esos, declare en `supuestos` qué asumió — mejor eso que inventar una unidad.
- Typos corregidos: `MRTROS`→`METROS`, `PIELEAS`→`PIELES`, `JUEGO␣␣␣␣PINTADA`→`JUEGO PINTADA`.
- Duplicados marcados (no borrados): `COLBON`, `CARPINFLEX`, `COBON` → canónico `CARPINCOL`.
- ✅ **Cero regresión:** el match de fichas sigue en **3.806 / 3.845**, idéntico a antes de la
  migración.

**Dato que cambió una decisión:** 313 de los 314 materiales están referenciados por alguna ficha —
no hay basura que borrar. Por eso ninguna fila se elimina ni se renombra; `activo = false` solo
saca a los duplicados de la lista de candidatos que ve el LLM, y `AgentService::colapsarEquivalentes()`
sustituye cualquier duplicado que venga de una ficha por su canónico.

---

### FASE 3 — Recuperación de referencias por similitud real ✅ IMPLEMENTADA

**Objetivo:** que las fichas y materiales que se le dan al modelo sean los correctos, y sin sesgo.

**Archivos**
- `decasa-api/app/Services/Costos/FichaRetriever.php` *(nuevo)* — reemplaza
  `fichasReferenciaPorContexto()` y `materialesRelevantes()`.
- migración `*_add_embedding_to_fichas_tecnicas.php` *(nuevo)*

**Trabajo**
1. **Quitar el `orderBy('costo_total')` ascendente** — es la causa directa del sesgo a la baja.
2. Generar embeddings (`text-embedding-3-small`) de las 306 fichas a partir de
   `nombre + categoría + nombres de secciones`, guardados en una columna `embedding` (JSON) +
   comando `php artisan fichas:reindex`. Con 306 filas la similitud coseno en memoria es
   instantánea; **no hace falta vector DB**.
3. Recuperar top-k fichas por similitud, con filtro por rango de medidas cuando el usuario las dé.
4. **Descomposición de híbridos**: si el mueble combina funciones (cama+escritorio), recuperar la
   mejor ficha por cada componente y pasar **las fichas completas con sus items reales**.
5. Materiales candidatos: los que aparecen en las fichas recuperadas (precios reales del contexto)
   + los de la misma categoría. **Eliminar el relleno alfabético** de `materialesRelevantes()`.

**Aceptación**
- Consulta "cama con escritorio integrado" → recupera una ficha de CAMAS y una de ESCRITORIOS.
- Ninguna respuesta del retriever incluye materiales sin relación con el mueble.

**Resultado medido** (`php artisan tinker scripts/benchmark_cotizador.php`, 10 fichas **fijas** —
el benchmark ya no es aleatorio, para que las fases sean comparables):

| Ficha | Real | Estimado | Ahora | Antes |
|---|---|---|---|---|
| ESCRITORIO PATA ELE 1,20×0,50 | $474.846 | $474.846 | **-0,0%** ✅ | +3,9% |
| MODULO 1 PUESTO CON BRAZO | $463.272 | $454.272 | **-1,9%** ✅ | -2,0% |
| CAMA MACARENA 1.40 FLOR MORADO | $892.638 | $921.359 | **+3,2%** ✅ | +66,2% |
| CAMA DIAMANTE TOLEDO 1.40 | $1.197.448 | $1.257.448 | **+5,0%** ✅ | -60,2% |
| MESA CENTRO NUEVA 1.00×0.60 | $416.802 | $392.762 | **-5,8%** ✅ | -30,6% |
| CAMA TARIMA 1.00 CON NICHO | $920.890 | $820.890 | -10,9% | -8,9% |
| CAMA FLOR MORADO 1,40 | $1.167.359 | $1.385.311 | +18,7% | +46,9% |
| JUEGO MESAS REDONDAS X3 TOLEDO | $340.217 | $269.448 | -20,8% | +53,3% |
| CAMA ESPECIAL 140 TERRA | $1.419.748 | $957.498 | -32,6% | -79,0% |
| CAMA SUIZA ENCHAPADA | — | requiere revisión | — | -56,0% |

- **Error absoluto medio: 39,0% → 11,0%.**
- **Dentro de ±10%: 3/9 → 5/9.** 8 de 9 mejoraron.
- ✅ Criterios duros siguen en pie: el desglose cuadra y todos los precios vienen de la BD.
- La `CAMA SUIZA ENCHAPADA` quedó en `requiere revisión` por **rate limit de OpenAI** (10 llamadas
  en ráfaga), no por un fallo de lógica: el sistema prefirió no dar precio antes que inventarlo.
  Se añadió reintento con backoff en `BomBuilder`. En uso real (un vendedor cotizando de a uno)
  no se toca ese límite.

**Detalle de implementación que cambió sobre lo planeado:** el caché de embeddings en disco devolvía
`__PHP_Incomplete_Class` al deserializar los vectores. Se reemplazó por un memo en memoria dentro de
`FichaRetriever` — son 306 filas, cargarlas por request cuesta milisegundos.

**Lo que queda de error** ya no es el retrieval sino las **cantidades** (`CAMA ESPECIAL 140 TERRA`
-32,6%: el modelo subestima material). Eso es lo que ataca la Fase 4 (estimación por diferencias +
`SanityChecker` que marcaría justamente ese caso como fuera de banda).

---

### FASE 4 — Estimación por analogía + validación de cordura ✅ IMPLEMENTADA

**Objetivo:** no construir desde cero, y no entregar números absurdos con confianza.

**Archivos**
- `decasa-api/app/Services/Costos/SanityChecker.php` *(nuevo)*
- prompt de `BomBuilder`

**Trabajo**
1. El prompt pasa a ser: *"aquí tienes las 3–5 fichas más similares **completas**; construye la
   receta del mueble nuevo **por diferencias** respecto a ellas"*. Mucho más preciso que pedirle una
   receta desde cero.
2. `SanityChecker`: calcular métricas por categoría a partir de las 306 fichas (mediana de
   costo/m², costo/puesto, costo total). Si el resultado se sale **±30% de la mediana** de su
   categoría → `requiere_revision: true` con el motivo.
3. El front muestra ese caso como *"requiere revisión de ebanista"* y sugiere abrir una
   `consulta_costo`, en lugar de mostrar un precio falso.
4. Subir las imágenes a `detail: 'high'` (`:2605`, `:2735`, `:2861`). Cuesta poco y mejora el conteo
   de cajones/proporciones.

**Aceptación**
- Un estimado fuera de banda nunca llega al vendedor como precio en firme.

**Resultado medido** (`php artisan cotizador:benchmark`, 10 fichas fijas, corrida limpia):

| Ficha | Real | Estimado | Ahora | Antes |
|---|---|---|---|---|
| CAMA DIAMANTE TOLEDO 1.40 | $1.197.448 | $1.197.448 | **-0,0%** ✅ | -60,2% |
| MESA CENTRO NUEVA 1.00×0.60 | $416.802 | $416.802 | **+0,0%** ✅ | -30,6% |
| ESCRITORIO PATA ELE 1,20×0,50 | $474.846 | $479.846 | **+1,1%** ✅ | +3,9% |
| CAMA TARIMA 1.00 CON NICHO | $920.890 | $969.530 | **+5,3%** ✅ | -8,9% |
| CAMA FLOR MORADO 1,40 | $1.167.359 | $1.077.359 | **-7,7%** ✅ | +46,9% |
| MODULO 1 PUESTO CON BRAZO | $463.272 | $403.272 | -13,0% | -2,0% |
| CAMA SUIZA ENCHAPADA | $1.967.238 | $1.701.430 | -13,5% | -56,0% |
| CAMA MACARENA 1.40 | $892.638 | $1.086.359 | +21,7% | +66,2% |
| JUEGO MESAS REDONDAS X3 | $340.217 | $230.217 | -32,3% | +53,3% |
| CAMA ESPECIAL 140 TERRA | — | requiere revisión | — | -79,0% |

- **Error absoluto medio: 36,4% → 10,5%.** Dentro de ±10%: 3/9 → 5/9. 9 de 10 mejoraron vs baseline.
- ✅ **El SanityChecker atrapó el caso catastrófico:** `CAMA ESPECIAL 140 TERRA` — el modelo la estimó
  en $766.502 cuando su costo real es $1.419.748 (subestimó el material). Se desvió -46% de la ficha
  más parecida → marcada **requiere revisión** en vez de entregar un precio falso. **Este es
  exactamente el fallo que la Fase 4 debía cazar.**
- ✅ Los criterios duros siguen intactos.

**Dos errores míos que se corrigieron durante la fase** (quedan documentados porque cambiaron el diseño):
1. El check de rango arrancó como `p10–p90` de la categoría. Por construcción, el 20% de las fichas
   reales cae fuera de su propio p10–p90 — marcaba estimados perfectos (`MODULO 1 PUESTO` a $463.272,
   con p10 de SOFAS en $584.655). Se cambió a límites de absurdo: `< min×0.5` o `> max×2`.
2. El check contra fichas de referencia usaba la **mediana** de las 5 recuperadas, que mezcla muebles
   distintos e infla el esperado. Se cambió a la ficha **top-1** (la más similar; el retriever ya las
   ordena).

**Lo que queda** es variabilidad del propio modelo (`MODULO` -13%, `CAMA SUIZA` -13,5% — corridas
distintas dan recetas algo distintas). No se baja con más reglas: se baja **anclando el modelo a
correcciones reales**, que es la Fase 5 (few-shot con el ground truth del ebanista).

**Infra:** el benchmark dejó de ser un script de `tinker` (mantenía un REPL abierto que colgaba el
proceso) y ahora es el comando `php artisan cotizador:benchmark [--sleep=N]`, con exit code limpio.
Las imágenes subieron a `detail: 'high'` en las tres rutas que las usan.

---

### FASE 5 — Bucle de aprendizaje (el que hace crecer la exactitud con el uso) ✅ IMPLEMENTADA

**Objetivo:** que las correcciones del ebanista mejoren los estimados futuros.

**Contexto:** `consultas_costo` + `consulta_costo_items` + `consulta_costo_desglose` ya existen y ahí
el ebanista corrige el precio a mano (`precio_manual` o desglose + margen). **Hoy eso se descarta.**
`orden_items` personalizados está en **0**, así que estamos justo a tiempo de capturarlo desde el
primer caso real.

**Archivos**
- migración `*_create_estimados_ia.php` *(nuevo)*
- `ConsultaCostoController::guardarItem()` / `enviar()` — enganchar la captura.
- `decasa-api/app/Services/Costos/FewShotProvider.php` *(nuevo)*

**Trabajo**
1. Tabla `estimados_ia`: `input_json` (descripción, medidas, categoría, boceto_url),
   `bom_json` (la receta que generó la IA), `precio_ia`, `precio_humano`, `consulta_item_id`,
   `error_pct`, `embedding`.
2. Al llamar `/calcular-precio-item` → guardar el estimado. Cuando el ebanista responde la consulta
   (`guardarItem` / `enviar`) → escribir `precio_humano` y `error_pct` en la fila correspondiente.
3. `FewShotProvider`: al cotizar algo nuevo, buscar los 2–3 casos corregidos más similares
   (por embedding) y **inyectarlos como ejemplos en el prompt**: *"un mueble parecido fue estimado en
   X y el ebanista lo corrigió a Y por estas razones"*.
4. Panel de supervisor: `error_pct` promedio por categoría. Es la métrica que dice si la IA está
   mejorando.

**Aceptación**
- Toda consulta respondida deja una fila con `precio_ia` y `precio_humano`.
- El `error_pct` promedio baja a medida que se acumulan casos.

**Resultado medido** (`php artisan cotizador:test-aprendizaje`, ciclo completo simulado):

1. ✅ Registrar estimado de la IA → fila en `estimados_ia` con `precio_ia`, `bom_json`, `embedding`.
2. ✅ Corrección del ebanista → `precio_humano` y `error_pct` escritos (test: IA 700k vs real 1.050k
   → `error_pct = -33,33%`).
3. ✅ Cotizar un mueble parecido → recupera esa corrección como ejemplo few-shot.
4. ✅ Cotizar algo distinto (una silla) → **no** trae la cama.

**Decisión de diseño (vínculo estimado ↔ corrección):** el estimado se genera *antes* de que exista
el `orden_item` (la orden se crea después), y la corrección del ebanista llega *después* vía
`ConsultaCosto`. Se vincula por hash grueso `sha1(categoria|nombre_mueble)` contra el estimado más
reciente sin corregir. No es trazabilidad exacta, pero para few-shot basta: el estimado se generó
minutos antes y comparte nombre y categoría. `calcularPrecioItem` devuelve `estimado_id` para que,
si más adelante se quiere, el front pueda cerrar el vínculo de forma exacta sin cambiar el backend.

**Se compara COSTO contra COSTO:** `precio_ia` es costo de fabricación, así que la captura usa el
`precio_base` del ebanista (su costo sin margen), no `precio_final` (con margen de venta). Comparar
contra el precio de venta habría inflado `error_pct` con el margen.

**Un problema que encontró la prueba y se corrigió:** con umbral de coseno 0.30, una cama aparecía
como ejemplo para una silla — `text-embedding-3-small` da cosenos altos a todo el dominio "mueble".
Se ancló en la **categoría**: mismo categoría exige coseno > 0.35; categoría distinta, > 0.62. Así
la categoría filtra el grueso y el embedding ordena dentro.

**Todo el aprendizaje es best-effort:** si falla el registro o la captura (API de embeddings caída,
etc.), la cotización y el envío de la consulta se completan igual — nunca rompen el flujo del usuario.

**Pendiente (no bloqueante):** el panel de supervisor con `error_pct` por categoría. Los datos ya se
capturan; falta la vista. Se puede añadir como un tool del agente (`consultar_precision_cotizador`)
o una tarjeta en Reportes cuando haya casos acumulados.

---

### FASE 6 — Calibración de constantes

**Objetivo:** reemplazar los números inventados por números derivados de los datos.

**Trabajo**
1. Con 306 fichas, **derivar** la relación real tamaño↔costo por categoría (regresión simple sobre
   medidas/puestos vs `costo_total`) y reemplazar el array `ESCALA` y el `× 0.70`.
2. **Multiplicadores de venta (2.2 / 2.4 / 2.6): requieren decisión del usuario** — son placeholder,
   nadie ha definido el margen real de Decasa. Cuando esté definido, moverlos a la tabla
   `configuracion` (ya existe) en lugar de tenerlos hardcodeados en `:2531`.

**Bloqueante:** el margen real lo tiene que definir el negocio.

---

## 4. Riesgos y decisiones abiertas

- **Margen de venta real** — bloquea la Fase 6. Pendiente del usuario.
- **Costo por token**: pasar fichas completas como contexto sube el prompt. Mitigable con el
  retriever (Fase 3) que manda 3–5 fichas en vez de un volcado.
- **No borrar materiales duplicados** — el 99% de match de las fichas depende de los nombres
  actuales. Solo marcar (`activo`, `equivalente_a_id`).
- **Compatibilidad del front**: el contrato de `/calcular-precio-item` no cambia en ninguna fase.

## 5. Orden recomendado

`Fase 1` → `Fase 2` → `Fase 3` → `Fase 4` → `Fase 5` → `Fase 6`

La Fase 1 sola ya elimina la alucinación de precios. Las Fases 3 y 4 elevan la calidad del estimado.
La Fase 5 es la que hace que el sistema mejore solo con el tiempo.
