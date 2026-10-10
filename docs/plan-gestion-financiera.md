# Plan — Módulo de Gestión Financiera ("Finanzas")

Fecha: 2026-10-09 · Estado: **Fases 0 a 4 y casi toda la 5 implementadas el 2026-10-10, sin subir** (ver §10) · Rama: `claude/financial-management-module-1a9529`

Un módulo nuevo, solo para supervisores, que junta en una sola pantalla **cuánto
entra y cuánto sale** de Decasa cada mes. Saca los datos de Reportes (ventas y
cobros), Nómina (sueldos y cada cuánto se pagan), Comisiones (pool, día 20,
anticipos) y Compras, y suma lo que hoy no existe en el sistema: **gastos
fijos** (internet, arriendo, licencias…) y **gastos variables** (agua, luz,
fletes…). Con eso arma el estado de resultados del mes, el flujo de caja, el
calendario de pagos, gráficas y proyecciones.

> **Alcance:** esto es **gestión financiera gerencial**: para que el dueño sepa
> cómo va la empresa y decida. **No** es contabilidad tributaria (no lleva PUC
> completo ni partida doble, no declara IVA ni manda nada a la DIAN). Cada
> categoría puede llevar el código PUC que le dé el contador para que la
> exportación le sirva (§5.1).

Reglas de `AGENT.md` que mandan sobre este plan: §2.1 (nada se sube sin
permiso), §2.3 (**ninguna regla de plata se decide sin preguntar**: ver §12),
§2.4 (avisos con `NotificacionService::crear`), §2.7 (migraciones solo hacia
adelante) y §2.8 (cambios mínimos en los controladores grandes).

---

## Índice

1. [Diagnóstico: lo que ya existe y lo que falta](#1-diagnóstico-lo-que-ya-existe-y-lo-que-falta)
2. [Principios del módulo](#2-principios-del-módulo)
3. [El modelo financiero: de dónde sale cada número](#3-el-modelo-financiero-de-dónde-sale-cada-número)
4. [Gastos fijos y variables: cómo se registran](#4-gastos-fijos-y-variables-cómo-se-registran)
5. [Modelo de datos (migraciones)](#5-modelo-de-datos-migraciones)
6. [Backend: servicios, controladores y rutas](#6-backend-servicios-controladores-y-rutas)
7. [Indicadores, gráficas y proyecciones (la matemática)](#7-indicadores-gráficas-y-proyecciones-la-matemática)
8. [Experiencia de usuario y pantallas](#8-experiencia-de-usuario-y-pantallas)
9. [Permisos y seguridad](#9-permisos-y-seguridad)
10. [Fases de implementación](#10-fases-de-implementación)
11. [Pruebas](#11-pruebas)
12. [Decisiones que tiene que tomar el dueño](#12-decisiones-que-tiene-que-tomar-el-dueño)
13. [Riesgos y trampas conocidas](#13-riesgos-y-trampas-conocidas)
14. [Ideas para después](#14-ideas-para-después)

---

## 1. Diagnóstico: lo que ya existe y lo que falta

Análisis hecho leyendo el código (2026-10-09). Lo importante de cada módulo
para las finanzas:

### 1.1 Ventas y cobros (Reportes / Stats)

| Qué | Dónde | Cómo se cuenta hoy |
|---|---|---|
| **Vendido** del periodo | `StatsController::kpis()` | `SUM(ordenes.valor_total)` de órdenes **creadas** en el rango (`created_at`), sin cotizaciones, borradores **ni canceladas** (`Orden::ESTADOS_FUERA_DE_REPORTES`). **Arreglado el 2026-10-09:** antes contaba las canceladas (conservan su `valor_total` y sus pagos). Ahora ninguna pantalla de Reportes ni el asistente cuentan lo vendido ni lo abonado a una cancelada; solo aparecen en el conteo "canceladas" (`ReportesNoCuentanCanceladasTest`) |
| **Cobranza** del periodo | `kpis()` → `cobranza_periodo` | `SUM(pagos.monto)` por `pagos.created_at`, venga de la orden que venga. Es el **flujo de caja** |
| **Cobrado de lo vendido** | `kpis()` → `ingresos_totales` | Pagos de las órdenes creadas en el rango |
| Cartera | `v_saldo_ordenes`, `Services/Cartera` | Saldo pendiente por orden |
| Desglose por tipo | `Orden::sqlTipo()`, `selectMontosPorTipo` | venta / restauración / FV2 |
| Fechas | `RangoFechas` | Días de Bogotá; la BD guarda UTC |
| Reembolsos de garantía | `GarantiaService` | Pago **negativo** `tipo=reembolso` y baja `valor_total`: lo devuelto deja de ser venta solo |
| Tarjeta / Addi | `ComisionController::COSTO_TARJETA = 0.055`, `Orden::METODOS_CON_FRANQUICIA` | 5,5 % de franquicia (hoy solo afecta la comisión) |
| IVA | Comisiones divide por **1,19** | Los precios **incluyen IVA**. FV2 con `sin_descontar_iva` no se divide |

**Conclusión:** el sistema ya distingue bien entre **lo vendido** (devengado) y
**lo cobrado** (caja). El módulo de Finanzas tiene que dar **exactamente las
mismas cifras** que Reportes. Si Finanzas y Reportes muestran números distintos
para el mismo mes, la gente deja de creerle a las dos pantallas.

### 1.2 Nómina

| Qué | Dónde |
|---|---|
| Frecuencias | `CicloNomina::FRECUENCIAS`: diario, semanal, quincenal, 20 días (anclado al 2026-09-01), mensual |
| Ciclos | Se **calculan** del calendario; no se guardan hasta que se marca "Pagado" |
| Pago congelado | `nomina_pagos` (desglose completo + `pagado_at`) |
| Liquidación | `NominaLiquidador::liquidar()`: sueldo × días + auxilio − faltas − incapacidad − seguridad social ± ajustes + bono − cuotas de préstamo |
| Lo que falta por pagar | `NominaLiquidador::pendientes()`: ciclos cerrados sin pago |
| Lo que lleva cada uno | `NominaLiquidador::cicloActual()` |

**Hallazgos que importan para las finanzas:**

1. `nomina_pagos.total` es lo que recibe el trabajador (**neto**), no lo que le
   cuesta a la empresa:
   - Ya le restó la **seguridad social del trabajador** (`descuento_seguridad_social`).
     Esa plata no se queda en la empresa: se paga a la planilla (PILA). Es salida
     de caja igual, solo que hacia otro destino.
   - Ya le restó las **cuotas de préstamo**. Eso no es un gasto: es el trabajador
     devolviendo un préstamo. El gasto (en realidad, una cuenta por cobrar) fue
     el día que se le prestó.
   - **El costo de nómina es el devengado bruto**, no el `total` (fórmula en §3.3).
2. ~~El sistema no calcula la parte que pone el empleador.~~ **Hecho el
   2026-10-09** (pedido del dueño: "se paga más de lo que sale en nómina").
   Nómina ahora calcula **por detrás** los aportes del empleador (pensión 12 %,
   ARL por clase de riesgo, caja 4 %, y salud/ICBF/SENA si la empresa no está
   exonerada) y las prestaciones (prima 8,33 %, cesantías 8,33 %, intereses
   12 % de las cesantías, vacaciones 4,17 %). **No cambia lo que se le paga al
   trabajador.** Se congela en cada pago (`nomina_pagos.costo_empleador` +
   `costo_empleador_detalle`) y se ve plegado en el detalle del pago.
   - Servicio: `App\Services\CostoEmpleador` (`deLiquidacion`, `dePago`,
     `conceptosDe`, `conceptos`).
   - **Todo personalizable** (dueño, 2026-10-09: "puede cambiar según la ley y
     el trabajador"):
     - **Conceptos como datos** (`nomina_conceptos_empleador`): nombre, grupo
       (aportes / prestaciones / otros), base (sueldo, sueldo + auxilio, u otro
       concepto), si aplica a todos por defecto. Si la ley trae un aporte nuevo
       se crea; si quita uno se desactiva (no se borra).
     - **Porcentaje con vigencia** (`nomina_concepto_tarifas`): un cambio de ley
       se carga "desde tal fecha". Cada ciclo usa el que regía el día que cierra;
       un cambio futuro se puede programar y quitar antes de que rija; lo que ya
       rigió no se borra.
     - **Excepciones por trabajador** (`nomina_concepto_trabajador`): a uno no se
       le paga pensión, otro tiene la ARL del taller con su porcentaje. Sin
       excepción rige lo de la empresa; los aportes por defecto solo a quien
       aporta seguridad social.
     - Si el bono de producción es salario: `configuracion.nomina_bono_es_salario`.
   - Pantallas: Nómina → Sueldos → "Lo que paga la empresa por detrás" (conceptos,
     historial de porcentajes, concepto nuevo) y la ficha del trabajador
     ("Empresa / Sí / No" por concepto y su propio porcentaje).
   - API (`acceso_nomina`): `GET/POST /nomina/conceptos-empleador`,
     `PATCH /{id}`, `POST /{id}/tarifas`, `DELETE /tarifas/{id}` (solo futuras),
     `PUT /ajustes`; excepciones con `PATCH /nomina/empleados/{id}`
     (`conceptos_empleador`).
   - Pruebas: `CostoEmpleadorLiquidacionTest` (quincena del mínimo hecha a mano:
     $357.726 por detrás), `NominaCongelaCostoEmpleadorTest` (congelado, excepción
     por trabajador, cambio de ley con fecha, concepto nuevo, permisos).
   - **Para Finanzas:** las líneas del detalle traen `clave`, `grupo` y
     `porcentaje`, así que un concepto nuevo aparece solo en el P&G sin tocar
     código. Las **proyecciones** usan la tarifa que regirá en cada mes futuro
     (un cambio de ley programado para enero ya sale en la proyección de enero).
   - Los pagos de antes de la migración `2026_10_19_000001_costo_empleador_en_nomina`
     se calculan con su desglose congelado y los porcentajes de hoy, y lo dicen.
3. Los trabajadores de fábrica (`no_usa_programa`) **sí** están en nómina:
   el costo del taller se ve completo.
4. Los ciclos semanales y de 20 días **cruzan de mes**: hay que repartirlos
   entre los dos meses por días (§3.3).

### 1.3 Comisiones

| Qué | Dónde |
|---|---|
| Fila por orden (y por vendedor si es compartida) | `comisiones` (`mes_venta`, `valor_orden`, `fecha_disponible`, `estado`, `monto_comision`, `fecha_pago`) |
| Pool de tienda | (ventas con 50 % pagado − meta) ÷ 1,19 × 5 %, repartido por días |
| Independientes | 5 % fijo + bolsón de restauraciones (`ComisionIndependientes`) |
| Cuándo se pagan | Día 20 del mes siguiente; trimestrales al cierre del trimestre |
| Anticipos | `comision_anticipos` (libro: `anticipo` = plata que se llevó ese mes; `descuento` = lo que se le descontó al pagar). **La comisión bruta no se toca** |
| Cálculo del mes | `ComisionController::resumenDelMes($mes)` |

**Hallazgos:**

1. **Costo de comisiones** (devengado) = comisión **bruta** del mes de venta.
   **Salida de caja** = anticipos entregados ese mes + (bruta pagada − descuentos
   de anticipo de esos pagos). Sumar "anticipos + bruta pagada" contaría la
   misma plata dos veces.
2. `resumenDelMes()` **escribe** en la BD (`asegurarPartesDePool` abre
   renglones con candado) y además es caro. Finanzas **no** lo llama.
   **Resuelto (verificado 2026-10-09):** la tarea `comisiones:poner-al-dia`
   (06:30, `ComisionController::ponerAlDia`) abre los renglones de equipo y deja
   `monto_comision` y `estado` al día en **todas** las filas no pagadas. Finanzas
   lee `comisiones` directo (una consulta agrupada por `mes_venta`) y rotula la
   cifra "al día de hoy, 6:30 a. m.".
3. Mientras no se paga, el monto de la comisión **se recalcula** (cambia con
   los pagos de los clientes y la meta). La comisión del mes en curso es una
   **estimación** y así se tiene que mostrar.

### 1.4 Caja, compras y otros egresos

| Qué | Dónde | Problema para finanzas |
|---|---|---|
| Caja de tienda | `caja_movimientos`, `CajaController` | **Fuera de Finanzas (decisión del dueño, 2026-10-09):** la caja es solo el efectivo de las ventas, que ya entra por `pagos`. Finanzas no lee `caja_movimientos` y Caja no se toca |
| Compras | `compras` (`precio`, `fecha_compra`, `factura_foto_url`, `estado`) | Sin categoría ni tienda; `precio` puede ser total o unitario (texto `cantidad`) |
| Proveedores | `proveedores` | Libreta, sin cuentas por pagar |
| Costo de fabricación | `fichas_tecnicas.costo_total` (+ `producto_id`) | Permite **estimar** el costo de lo vendido (§3.5) |
| Telas | `inventario_telas` | No guarda costo de compra |

### 1.5 Lo que no existe

- Gastos fijos recurrentes (internet, arriendo, licencias, software, contador…).
- Gastos variables (agua, luz, gas, fletes, publicidad, mantenimiento…).
- Un estado de resultados mensual (P&G).
- Un flujo de caja que junte nómina + comisiones + gastos + cobros.
- Un calendario de pagos que se vienen.
- Proyecciones, presupuesto y punto de equilibrio.

---

## 2. Principios del módulo

1. **Una sola fuente por número.** Finanzas **no recalcula** ventas, nómina ni
   comisiones con su propia lógica: lee la de cada módulo (o un servicio
   compartido que se saca de ahí, sin cambiar el resultado). Prueba obligatoria:
   *"Finanzas cuadra con Reportes"* (§11).
2. **Dos lentes, siempre rotulados.** Todo número dice si es
   **Devengado** (a qué mes pertenece: "¿gané o perdí en octubre?") o
   **Caja** (cuándo entró o salió la plata: "¿me alcanza para la quincena?").
   Mezclarlos es el error financiero más común en una pyme.
3. **Lo calculado no se guarda; lo pagado sí.** La misma filosofía de Nómina:
   las obligaciones de un gasto recurrente "existen porque hoy es una fecha".
   Solo cuando se paga queda una fila. Así no hay tarea que crear meses, no se
   duplican filas y no queda basura que limpiar.
4. **Real vs. estimado, a la vista.** Lo estimado (comisión del mes en curso,
   costo de producción por ficha, proyección) se pinta distinto (borde punteado,
   chip "estimado") y explica de dónde sale.
5. **Nada se borra.** Un gasto mal registrado se **anula** con motivo (queda en
   la bitácora). La regla 7 aplica también a los datos de plata.
6. **Hora de Bogotá** en todo (`RangoFechas`, `CicloNomina::fecha()`).
7. **Pocas consultas.** Aiven cobra unos 200 ms por consulta
   (`docs/plan-rendimiento.md`). Cada fuente trae **12 meses agrupados por mes
   en una sola consulta**, nunca un ciclo de una consulta por mes.
8. **El código calcula, la IA explica.** Si después se le da una herramienta al
   asistente, las cifras salen del servicio, nunca del modelo (igual que el
   cotizador).

---

## 3. El modelo financiero: de dónde sale cada número

### 3.1 Estado de resultados del mes (Devengado)

```
  Ventas brutas (con IVA)                         Σ valor_total del mes, sin canceladas (= "Vendido" de Reportes)
− IVA incluido en las ventas                      ventas gravadas × 19/119          [P1]
= INGRESOS NETOS
− Costo de producción estimado                    Σ unidades × ficha.costo_total    [estimado, §3.5]
= UTILIDAD BRUTA (estimada)                       → margen bruto %
− Nómina (costo empresa)                          §3.3
− Comisiones causadas                             §3.4
− Gastos fijos del mes                            §4 (prorrateados si aplica)
− Gastos variables del mes                        §4
− Gastos financieros                              franquicia datáfono/Addi 5,5 %    [P5]
= UTILIDAD OPERATIVA                              → margen operativo %
```

`[Pn]` = depende de una decisión del dueño (§12).

**Por qué "ventas del mes" y no "cobrado del mes":** una sala vendida en
octubre y pagada en noviembre es ingreso de **octubre** en el P&G. El cobro de
noviembre es **caja** (§3.2). Es el mismo criterio que ya usa Reportes con
"Total vendido".

**Restauraciones y FV2:** van dentro de ventas, pero se muestran separadas
(`Orden::sqlTipo()`), igual que en Reportes.

### 3.2 Flujo de caja del mes (Caja)

```
  Saldo inicial                                   [P6] saldo que pone el dueño, o se omite
+ Cobros a clientes                               Σ pagos.monto por pagos.created_at, todos los medios (= "Cobranza" de Reportes; sin canceladas, ya resta reembolsos)
+ Otros ingresos                                  ingresos manuales registrados en Finanzas (no la caja de tienda)
− Nómina pagada                                   nomina_pagos por pagado_at: neto al trabajador + seguridad social del trabajador (PILA)
− Aportes del empleador                           nomina_pagos.costo_empleador_detalle → grupo "aportes" (se giran con la PILA)
− Prestaciones (provisión)                        grupo "prestaciones": se muestran como provisión; la salida real es en jun/dic (prima), feb (cesantías) y al salir a vacaciones
− Préstamos a empleados                           nomina_prestamos entregados en el mes (cuenta por cobrar)
+ Cuotas de préstamo recuperadas                  (ya vienen restadas en el neto: no se suman aparte)
− Comisiones pagadas                              bruta pagada − descuentos de anticipo
− Anticipos de comisión                           comision_anticipos tipo anticipo del mes
− Gastos pagados                                  gastos.fecha_pago en el mes
− Compras pagadas                                 compras.fecha_compra en el mes
= FLUJO NETO DEL MES
```

**Cobros por medio de pago:** el desglose efectivo / transferencia / tarjeta /
Addi dice qué tan rápido se vuelve plata disponible lo que se cobra. La tarjeta
y Addi llegan días después y con la franquicia ya descontada.

### 3.3 Nómina: costo empresa vs. lo pagado

De un `nomina_pagos` (o de una liquidación calculada):

```
devengado_bruto = subtotal + auxilio_transporte − descuento_faltas − descuento_incapacidad
                  + bonificacion + total_ajustes

costo_empresa   = devengado_bruto + costo_empleador.total     (ya lo calcula Nómina: CostoEmpleador)

salida_de_caja  = total (neto al trabajador) + descuento_seguridad_social (parte del trabajador, a la PILA)
                  + costo_empleador.aportes (parte del empleador, a la PILA)
                  [las prestaciones salen cuando se pagan: prima jun/dic, cesantías feb, vacaciones al tomarlas]
```

- Pagos sin `costo_empleador_detalle` (anteriores a la migración):
  `CostoEmpleador::dePago()` lo calcula con su desglose congelado y lo marca
  `calculado_despues`.
- Ciclos no pagados (mes en curso, proyección): `liquidar()` ya trae
  `costo_empleador`.

- `total_ajustes` puede ser negativo (descuento por herramienta perdida). Se
  deja tal cual: baja el costo.
- Las **cuotas de préstamo** no entran a ninguna de las tres cuentas: son
  devolución de plata prestada.
- **Mes de un ciclo que cruza meses** (devengado): se reparte por días. Un ciclo
  semanal del 29-sep al 05-oct con 7 días pone 2/7 en septiembre y 5/7 en
  octubre. Quincenal y mensual nunca cruzan. Los de 20 días casi siempre cruzan.
- **Mes en curso:** lo pagado + `NominaLiquidador::cicloActual()` de cada
  trabajador (lo que lleva devengado) + los ciclos de `pendientes()`. Se rotula
  "parcial".
- **Por área:** se agrupa por el rol del trabajador (`rolAsignado`) y por su
  arquetipo (vendedor = gasto de ventas; taller = costo de producción;
  supervisor = administración). Así el P&G puede separar mano de obra del taller
  de gasto administrativo (§7.1).

### 3.4 Comisiones: causadas vs. pagadas

| Lente | Fórmula | Fuente |
|---|---|---|
| Devengado (mes M) | Σ comisión **bruta** de las filas con `mes_venta = M` (pool + independientes) | Pagadas: `monto_comision` congelado. No pagadas: cálculo vigente, **estimado** |
| Caja (mes M) | Σ (`monto_comision` de las pagadas con `fecha_pago` en M) − Σ descuentos de anticipo atados a esas comisiones + Σ anticipos con `mes = M` | `comisiones`, `comision_anticipos` |
| Por pagar | Filas `lista` + `pendiente` con su `fecha_disponible` | Calendario (§7.4) |

**Ojo con el devengado de la comisión.** Por la regla del 50 % y la meta, la
comisión de septiembre puede seguir moviéndose hasta que se paga en octubre. El
P&G de un mes cerrado muestra la cifra **vigente** con la marca "puede cambiar
hasta el día 20". Una vez pagada, queda fija.

### 3.5 Costo de producción estimado (margen bruto)

```
costo_estimado_item = cantidad × ficha_tecnica.costo_total   (ficha con producto_id = item.producto_id)
cobertura           = Σ ventas de ítems con ficha / Σ ventas del mes
```

- **Solo es una estimación**: la ficha es la receta de referencia, no lo que
  realmente se gastó. Se muestra con su **cobertura** ("estimado sobre el 68 %
  de lo vendido") y para el resto se aplica el margen promedio de lo que sí
  tiene ficha.
- **No se suma con las compras de material:** sería contar dos veces. Compras es
  **caja**; el costo por ficha es **devengado**. Cada uno va en su lente.
- Las restauraciones casi nunca tienen ficha: se reportan aparte, sin costo
  estimado, hasta que exista la Fase 1b del cotizador.
- La mano de obra de la ficha y la nómina del taller **se pisan**. Para no
  restar dos veces: el costo de producción usa solo `costo_materiales` y la mano
  de obra sale de la nómina real del taller (§3.3). Así lo estimado son solo los
  materiales.

### 3.6 IVA

Los precios **incluyen** IVA (el pool de comisiones divide por 1,19). El IVA no
es ingreso de la empresa: se le debe a la DIAN. Por eso:

- **Ingresos netos** = ventas gravadas ÷ 1,19 + ventas no gravadas.
- La pantalla muestra una línea de "IVA por pagar (estimado)" del periodo
  bimestral o cuatrimestral, como **provisión de caja**, para que no sorprenda
  el día de la declaración.
- Si la FV2 va sin IVA, si se descuenta el IVA de las compras, el régimen y el
  periodo de declaración los define el dueño con el contador → **P1, P2**.

---

## 4. Gastos fijos y variables: cómo se registran

### 4.1 Qué es fijo y qué es variable (en este sistema)

| | Gasto **fijo** | Gasto **variable** |
|---|---|---|
| Monto | Casi igual cada periodo | Cambia cada vez |
| Ejemplos | Arriendo, internet, licencias, Render/Vercel/Aiven/OpenAI, contador, seguros, vigilancia | Agua, luz, gas, fletes, publicidad (Meta Ads), mantenimiento, papelería, aseo, domicilios |
| Se repite | Sí, con frecuencia conocida | A veces (servicios públicos sí, un flete no) |
| Cómo se registra | **Plantilla recurrente** → cada periodo aparece "por pagar" con el monto → un toque para pagar | Plantilla con **monto estimado** (luz: promedio de los últimos 3 recibos) **o** registro suelto |

> **Nota de finanzas:** en contabilidad de costos, "variable" quiere decir "sube
> con las ventas" (comisiones, materia prima, fletes, franquicia del datáfono).
> La luz y el agua son en realidad *semivariables*. Para el **punto de
> equilibrio** (§7.3) el sistema usa la clasificación técnica (cada categoría
> dice si se mueve con las ventas). En la pantalla se usa el lenguaje del
> negocio: fijo = mismo monto, variable = cambia.

### 4.2 Gastos recurrentes (plantillas)

Una plantilla dice: *qué* (nombre, categoría), *de dónde* (tienda o general),
*cuánto* (monto fijo o estimado), *cada cuánto* (frecuencia + día de pago), y
*desde/hasta cuándo*.

Las obligaciones del periodo **se calculan** como los ciclos de nómina:

```
para cada plantilla activa:
  para cada periodo entre max(desde, hoy − 3 meses) y hoy + horizonte:
    si no existe gasto pagado con (plantilla_id, periodo) → obligación "por pagar"
       estado: vencida (vencimiento < hoy) · por vencer (≤ avisar_dias_antes) · programada
```

- **Pagar** = crear la fila en `gastos` con el monto real (prellenado con el de
  la plantilla o el estimado), la fecha de pago, el medio y la foto del recibo.
- **Saltar periodo** (ej. el internet que ese mes no se cobró) = fila en `gastos`
  con `estado='omitido'` y monto 0, para que deje de aparecer como vencida.
- **Prorratear** (licencia anual de $1.200.000): en **caja** sale entera el mes
  que se paga; en el **P&G** se reparte $100.000 por mes durante los 12 meses que
  cubre (`cubre_desde`/`cubre_hasta` en el gasto). Sin esto, el mes de la
  licencia parece una pérdida y los otros 11 meses una ganancia que no es.
- **Frecuencias:** semanal, quincenal, mensual, bimestral, trimestral, semestral
  y anual (las de nómina más las comunes en servicios). El periodo de una
  bimestral se ancla al mes de `desde`.
- **Cambio de precio** (el arriendo sube en enero): se edita la plantilla con
  "desde el periodo X". Lo ya pagado no se mueve. Mismo principio que
  `nomina_pagos`.

### 4.3 Gastos sueltos

Botón "+ Gasto": categoría, concepto, monto, fecha, tienda o general, medio de
pago, proveedor (opcional, de la libreta), foto del recibo (Cloudinary carpeta
`gastos`), notas. Si es de un periodo distinto al del pago (el recibo de luz de
septiembre que se paga en octubre), se elige "corresponde a: septiembre".

### 4.4 Para no contar dos veces

| Situación | Regla |
|---|---|
| Caja de tienda (`caja_movimientos`) | **No entra a Finanzas** (dueño, 2026-10-09): es el efectivo de las ventas, que ya está en `pagos`. Los gastos se registran **solo** en Finanzas |
| Compras (`compras` comprado con precio) | Entra al flujo como "Compras de taller" (categoría por defecto, editable). No se registra otra vez como gasto |
| Franquicia del datáfono | Se calcula sola (5,5 % de los pagos con tarjeta/Addi del mes). **No** se registra a mano [P5] |
| Reembolsos de garantía | Ya bajan ventas y cobros (pago negativo). **No** son gasto |
| Préstamos a trabajadores | Salida de caja, **no** gasto (cuenta por cobrar) |
| Anticipos de comisión | Salida de caja; el gasto es la comisión bruta (§3.4) |

### 4.5 Categorías iniciales (sembradas, editables)

| Grupo | Categorías | Se mueve con ventas | Naturaleza por defecto |
|---|---|---|---|
| Locales | Arriendo, Administración, Vigilancia, Seguros | No | Fijo |
| Servicios públicos | Energía, Agua, Gas, Internet y telefonía | No (semi) | Variable (internet: fijo) |
| Tecnología | Software y licencias, Servidores y nube (Render, Vercel, Aiven, Cloudinary, OpenAI, Twilio, Brevo), Dominio | No | Fijo |
| Ventas | Publicidad y redes, Material POP, Domicilios y fletes de venta | Sí | Variable |
| Producción | Insumos de taller, Mantenimiento de máquinas, Herramientas, Fletes de materiales | Sí | Variable |
| Administración | Honorarios (contador, abogado), Papelería, Aseo y cafetería, Bancarios | No | Variable |
| Impuestos | Industria y comercio, Predial, Otros impuestos | No | Fijo |
| Automáticos (solo lectura) | Nómina (sueldos), Aportes y prestaciones (de Nómina), Comisiones, Franquicia datáfono, Compras de taller | — | — |

---

## 5. Modelo de datos (migraciones)

Todas las migraciones **solo agregan**: tablas nuevas y columnas *nullable* en
tablas existentes. Ninguna toca columnas que lean los agentes de chat
(`docs/contrato-agentes.md` no se afecta).

### 5.1 Tablas nuevas

```php
// 2026_10_20_000001_crear_finanzas.php

Schema::create('categorias_gasto', function (Blueprint $t) {
    $t->id();
    $t->string('nombre', 80);
    $t->string('grupo', 30);                       // locales, servicios, tecnologia, ventas, produccion, administracion, impuestos
    $t->enum('naturaleza', ['fijo', 'variable'])->default('variable');
    $t->boolean('varia_con_ventas')->default(false); // para punto de equilibrio
    $t->string('area', 20)->default('administracion'); // produccion | ventas | administracion | financiero (línea del P&G)
    $t->string('codigo_puc', 12)->nullable();      // para el contador
    $t->string('icono', 40)->nullable();
    $t->string('color', 9)->nullable();
    $t->unsignedSmallInteger('orden')->default(0);
    $t->boolean('activo')->default(true);          // se desactiva, no se borra
    $t->timestamps();
    $t->unique('nombre');
});

Schema::create('gastos_recurrentes', function (Blueprint $t) {
    $t->id();
    $t->string('nombre', 120);                     // "Internet Norte — Claro"
    $t->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
    $t->foreignId('tienda_id')->nullable()->constrained('tiendas');      // null = general / administración
    $t->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
    $t->decimal('monto', 15, 2);                   // fijo, o el estimado inicial
    $t->boolean('monto_estimado')->default(false); // luz/agua: el monto cambia; se sugiere el promedio de los últimos 3
    $t->enum('frecuencia', ['semanal','quincenal','mensual','bimestral','trimestral','semestral','anual']);
    $t->unsignedTinyInteger('dia_pago')->nullable(); // 1..31 (31 = último día del mes)
    $t->date('desde');
    $t->date('hasta')->nullable();
    $t->boolean('prorratear')->default(false);     // P&G reparte el pago en los meses que cubre
    $t->string('metodo_pago', 20)->nullable();     // el habitual, para prellenar
    $t->unsignedTinyInteger('avisar_dias_antes')->default(3);
    $t->text('notas')->nullable();
    $t->boolean('activo')->default(true);
    $t->foreignId('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
    $t->timestamps();
});

Schema::create('gastos', function (Blueprint $t) {
    $t->id();
    $t->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
    $t->foreignId('gasto_recurrente_id')->nullable()->constrained('gastos_recurrentes')->nullOnDelete();
    $t->string('periodo', 10)->nullable();         // 'YYYY-MM' o 'YYYY-MM-DD' (inicio): el periodo de la plantilla que paga
    $t->foreignId('tienda_id')->nullable()->constrained('tiendas');
    $t->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
    $t->string('concepto', 160);
    $t->decimal('monto', 15, 2);
    $t->enum('estado', ['pagado', 'omitido', 'anulado'])->default('pagado');
    $t->date('fecha_pago');                        // caja
    $t->date('cubre_desde');                       // P&G: a qué meses pertenece
    $t->date('cubre_hasta');                       //   (igual a fecha_pago si no se prorratea)
    $t->string('metodo_pago', 20)->nullable();     // efectivo, transferencia, tarjeta, otro
    $t->string('comprobante_url', 500)->nullable();
    $t->json('comprobante_fotos')->nullable();
    $t->text('notas')->nullable();
    $t->string('motivo_anulacion', 200)->nullable();
    $t->foreignId('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
    $t->foreignId('anulado_por')->nullable()->constrained('usuarios')->nullOnDelete();
    $t->timestamp('anulado_at')->nullable();
    $t->timestamps();

    $t->index(['fecha_pago']);
    $t->index(['cubre_desde', 'cubre_hasta']);
    // Un solo pago por periodo de una plantilla. `periodo` NUNCA va NULL en
    // un gasto de plantilla: en MySQL dos NULL no chocan en un índice único
    // (la misma trampa de wa_seguimientos, MEMORY.md §5b).
    $t->unique(['gasto_recurrente_id', 'periodo']);
});

Schema::create('gastos_bitacora', function (Blueprint $t) {   // quién cambió qué (como comisiones_bitacora)
    $t->id();
    $t->string('entidad', 20);                     // gasto | recurrente | categoria | presupuesto
    $t->unsignedBigInteger('entidad_id');
    $t->string('accion', 20);                      // crear, editar, anular, omitir
    $t->json('antes')->nullable();
    $t->json('despues')->nullable();
    $t->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
    $t->timestamp('created_at')->useCurrent();
    $t->index(['entidad', 'entidad_id']);
});
```

**Fase 4:**

```php
Schema::create('presupuestos_gasto', function (Blueprint $t) {
    $t->id();
    $t->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
    $t->foreignId('tienda_id')->nullable()->constrained('tiendas');
    $t->char('mes', 7);                            // 'YYYY-MM'
    $t->decimal('monto', 15, 2);
    $t->timestamps();
    $t->unique(['categoria_gasto_id', 'tienda_id', 'mes']); // tienda_id NULL: validar en código (dos NULL no chocan)
});
```

**Fase 5 (cierre de mes):** `cierres_financieros` (`mes`, `snapshot` JSON,
`cerrado_por`, `cerrado_at`, `reabierto_*`) para congelar un mes ya revisado.

### 5.2 Columnas nuevas en tablas existentes (nullable, solo agregan)

| Tabla | Columna | Para qué |
|---|---|---|
| `usuarios` | `acceso_finanzas` boolean default false | Permiso del módulo (§9). La migración lo prende a los supervisores **solo si el dueño lo decide** [P7] |
| `compras` | `categoria_gasto_id` nullable FK | Por defecto "Compras de taller" |
| `compras` | `tienda_id` nullable FK | Centro de costo |
| `modulos` (fila) | `finanzas`, 'Finanzas', `PresentationChartLineIcon` | Módulo renombrable desde Gestión (igual que la migración `modulos_con_nombre_propio`) |

Configuración (`configuracion`, clave-valor que ya existe):
`finanzas.iva_pct` (19), `finanzas.fv2_gravada` [P1],
`finanzas.franquicia_pct` (0,055) [P5] (los porcentajes de nómina ya viven en
`nomina_costo_empleador`, ver §1.2),
`finanzas.saldo_inicial` + `finanzas.saldo_inicial_fecha` [P6],
`finanzas.reparto_generales` (`ventas` | `partes_iguales` | `ninguno`) [P8].

`tests/TestCase::completarEsquemaDeEntregas()`: agregar las columnas nuevas de
`compras` (AGENT.md §4, columnas que se escriben desde otros flujos).

---

## 6. Backend: servicios, controladores y rutas

### 6.1 Estructura

```
app/Services/Finanzas/
  Periodo.php                 meses en hora de Bogotá → rangos UTC (usa RangoFechas)
  FuenteVentas.php            vendido, cobrado, por tipo, por medio de pago, IVA, franquicia     (12 meses / 1-2 consultas)
  FuenteNomina.php            pagado (bruto, neto, PILA), devengado por mes (reparto de ciclos), proyección
  FuenteComisiones.php        causada (bruta) por mes de venta, pagada, anticipos, por pagar con fecha — SIN escrituras
  FuenteGastos.php            gastos pagados (caja), devengados (prorrateo), obligaciones calculadas de plantillas
  FuenteCompras.php           compras pagadas por mes
  CostoProduccion.php         costo estimado por fichas + cobertura
  EstadoResultados.php        arma el P&G (§3.1) de un mes o de 12
  FlujoDeCaja.php             arma el flujo (§3.2)
  CalendarioPagos.php         lo que se viene: nómina, comisiones día 20, plantillas, IVA
  ObligacionesRecurrentes.php periodos de una plantilla (como CicloNomina)
  Proyeccion.php              la matemática de §7.2
  Indicadores.php             márgenes, punto de equilibrio, cobertura, DSO…
app/Http/Controllers/
  FinanzasController.php      lectura: resumen, estado-resultados, flujo, calendario, proyeccion, por-tienda, exportar
  GastoController.php         crear, editar, anular, pagar obligación, omitir
  GastoRecurrenteController.php
  CategoriaGastoController.php
app/Jobs/AvisarGastosPorVencer.php    08:15 diario → NotificacionService::crear (push)
```

### 6.2 Cómo se reutiliza sin romper lo que ya existe

- **Ventas** (`FuenteVentas`): las consultas de "vendido" y "cobranza" se
  **copian** de `StatsController::kpis()` a la fuente, agrupadas por mes, y una
  prueba compara que para cualquier mes den **igual** que `/stats/panel`. No se
  toca `StatsController` (regla 8). Si más adelante se quiere un solo lugar, se
  hace con la prueba diferencial de MEMORY.md §4 (respuestas idénticas byte a
  byte).
- **Nómina** (`FuenteNomina`): lee `nomina_pagos` directo (una consulta,
  agrupada) y para lo que no se ha pagado usa `NominaLiquidador::liquidar()` /
  `cicloActual()` / `pendientes()` tal cual. Para proyectar: `CicloNomina::rango()`
  y `siguiente()` con el sueldo vigente (sin faltas futuras, con las ya
  avisadas).
- **Comisiones** (`FuenteComisiones`):
  - Pagadas: `comisiones` donde `estado='pagada'` (monto congelado) + descuentos
    de `comision_anticipos` atados → consulta directa.
  - Anticipos entregados: `comision_anticipos` tipo `anticipo` por `mes`.
    Si el libro de un mes todavía no está escrito, se usa
    `AnticiposComision::vigentesEn()` (es de solo lectura) para estimar.
  - **Causada no pagada:** `resumenDelMes()` **no se llama** desde Finanzas
    (escribe y es caro). Se lee `comisiones.monto_comision` de las filas no
    pagadas: la tarea diaria `comisiones:poner-al-dia` (06:30,
    `ComisionController::ponerAlDia`) lo deja al día en **todas**, incluidos los
    renglones de equipo del pool (verificado 2026-10-09). Una consulta agrupada
    por `mes_venta`, rotulada "al día de hoy, 6:30 a. m.". Sin fórmula paralela.
- **Caja de tienda:** no se lee (decisión del dueño). Los cobros salen de `pagos`.
- **Cachés estáticos:** si alguna fuente cachea por petición, se registra en
  `CachesDePeticion::olvidarTodo()`.

### 6.3 Rutas (`routes/api.php`)

```php
// Finanzas — gestión financiera de la empresa. Supervisor con acceso_finanzas.
Route::middleware(['role:supervisor', 'permiso:acceso_finanzas'])->prefix('finanzas')->group(function () {
    // Lectura (literales antes de {id})
    Route::get('/resumen',            [FinanzasController::class, 'resumen']);          // ?mes=YYYY-MM&tienda_id=
    Route::get('/estado-resultados',  [FinanzasController::class, 'estadoResultados']); // ?desde=YYYY-MM&hasta=YYYY-MM
    Route::get('/flujo-caja',         [FinanzasController::class, 'flujoCaja']);
    Route::get('/calendario',         [FinanzasController::class, 'calendario']);       // ?dias=45
    Route::get('/proyeccion',         [FinanzasController::class, 'proyeccion']);       // ?meses=3
    Route::get('/por-tienda',         [FinanzasController::class, 'porTienda']);        // ?mes=
    Route::get('/exportar',           [FinanzasController::class, 'exportar']);         // Excel para el contador

    Route::get('/categorias',         [CategoriaGastoController::class, 'index']);
    Route::post('/categorias',        [CategoriaGastoController::class, 'store']);
    Route::patch('/categorias/{id}',  [CategoriaGastoController::class, 'update'])->whereNumber('id');

    Route::get('/recurrentes',        [GastoRecurrenteController::class, 'index']);
    Route::post('/recurrentes',       [GastoRecurrenteController::class, 'store']);
    Route::patch('/recurrentes/{id}', [GastoRecurrenteController::class, 'update'])->whereNumber('id');

    Route::get('/gastos/pendientes',  [GastoController::class, 'pendientes']);   // obligaciones calculadas
    Route::get('/gastos',             [GastoController::class, 'index']);
    Route::post('/gastos',            [GastoController::class, 'store']);        // suelto o pago de obligación (gasto_recurrente_id + periodo)
    Route::post('/gastos/omitir',     [GastoController::class, 'omitir']);
    Route::patch('/gastos/{id}',      [GastoController::class, 'update'])->whereNumber('id');
    Route::post('/gastos/{id}/anular',[GastoController::class, 'anular'])->whereNumber('id');
});
```

- Escrituras de plata dentro de `DB::transaction` (pagar una obligación crea el
  gasto y su bitácora juntos).
- `throttle` en las escrituras (como en despacho).
- `UploadController`: agregar `gastos` a la lista blanca de carpetas.

### 6.4 Respuesta de `/finanzas/resumen` (forma)

```json
{
  "mes": "2026-10", "parcial": true, "actualizado_a": "2026-10-09T14:00:00-05:00",
  "devengado": {
    "ventas_brutas": 182400000, "iva": 29122000, "ingresos_netos": 153278000,
    "por_tipo": {"venta": {...}, "restauracion": {...}, "fv2": {...}},
    "costo_produccion": {"monto": 61300000, "estimado": true, "cobertura": 0.68},
    "nomina":      {"monto": 38200000, "parcial": true, "por_area": {"produccion": ..., "ventas": ..., "administracion": ...}},
    "comisiones":  {"monto": 4100000, "estimado": true},
    "gastos_fijos": 9800000, "gastos_variables": 3100000, "financieros": 1450000,
    "utilidad_operativa": 35008000, "margen_operativo": 0.228
  },
  "caja": { "cobros": ..., "salidas": {...}, "flujo_neto": ... },
  "comparativa": { "mes_anterior": {...}, "mismo_mes_anio_anterior": null },
  "proximos_pagos": [ {"fecha": "2026-10-15", "concepto": "Nómina quincenal (18 personas)", "monto": 14300000, "tipo": "nomina"} ],
  "alertas": [ {"nivel": "aviso", "texto": "Energía Norte vence en 2 días"} ]
}
```

Meta de rendimiento: **≤ 12 consultas** para `/resumen` y `/estado-resultados`
de 12 meses. Se mide con `scripts/perf/medir.sh` antes de dar la fase por
hecha.

---

## 7. Indicadores, gráficas y proyecciones (la matemática)

### 7.1 Indicadores (tarjetas)

| Indicador | Fórmula | Lectura |
|---|---|---|
| Margen bruto (est.) | (Ingresos netos − costo de producción) ÷ ingresos netos | Cuánto deja cada peso antes de los gastos |
| Margen operativo | Utilidad operativa ÷ ingresos netos | Salud general |
| Nómina / ventas | Costo de nómina ÷ ingresos netos | En una fábrica de muebles se suele mirar con alerta por encima de ~30–35 % (umbral editable) |
| Comisiones / ventas | Comisiones causadas ÷ ingresos netos | |
| Gastos fijos / ventas | | |
| Punto de equilibrio | §7.3 | "Hay que vender $X para no perder" |
| Avance contra el equilibrio | Ventas del mes ÷ punto de equilibrio | Barra de progreso del mes |
| Cobertura de gastos fijos (meses) | Saldo disponible ÷ egresos fijos mensuales [P6] | "Con lo que hay en caja aguantamos N meses" |
| Recaudo | Cobros del mes ÷ ventas del mes | Si baja, la cartera crece |
| Días de cartera (DSO) | Cartera ÷ ventas promedio diarias (últimos 90 días) | Cuánto se demora en entrar la plata |
| Ticket promedio | Ya existe en Stats | |

Los umbrales se configuran. El semáforo (verde/ámbar/rojo) dice qué tan lejos
está el número del umbral **y con palabras** ("La nómina se come el 38 % de lo
vendido; el mes pasado fue 31 %"). No basta con el color.

### 7.2 Proyecciones

Decasa tiene datos desde **mayo de 2026**: unos 5–6 meses. Con eso **no se
puede** estimar estacionalidad (diciembre, prima de junio) con rigor. La
pantalla lo dice. Se usan métodos simples, que se pueden explicar y que tienen
su banda de incertidumbre:

**a) Lo que ya se sabe (determinístico): la parte fuerte de la proyección**

| Rubro | Cómo se proyecta |
|---|---|
| Nómina | `CicloNomina` + sueldo vigente de cada trabajador activo → ciclos futuros exactos (quincena del 15, del 30…). Bono: promedio de los últimos 3 pagos. Costo del empleador con `CostoEmpleador` y la tarifa que rija en la fecha de cada ciclo futuro (incluye cambios de ley ya programados) |
| Gastos fijos | Plantillas activas → obligaciones futuras exactas |
| Gastos variables recurrentes | Promedio de los últimos 3 periodos de la plantilla |
| Comisiones | Filas `lista`/`pendiente` con su `fecha_disponible` (el 20) + estimado del mes en curso |
| Cobros de cartera | Saldo de cada orden abierta × probabilidad de cobro por antigüedad (0–30 días: alta, 31–90: media, >90: baja), con las tasas **medidas** en la historia de pagos del sistema |
| IVA | Provisión del periodo de declaración [P2] |

**b) Ventas futuras (estadística)**

1. Si hay **< 6 meses** de historia: promedio móvil ponderado de los últimos 3
   meses (pesos 0,5 / 0,3 / 0,2).
2. Con **≥ 6 meses**: tendencia lineal por mínimos cuadrados sobre las ventas
   mensuales,
   `ŷ(t) = a + b·t`, con `b = Σ(t−t̄)(y−ȳ) / Σ(t−t̄)²`, `a = ȳ − b·t̄`.
3. **Banda:** ± 1 error estándar de los residuos (`s = √(Σ e² / (n−2))`),
   pintada como un área clara alrededor de la línea. Escenarios
   **pesimista / base / optimista** = −1s / ŷ / +1s.
4. Con **≥ 13 meses** (desde mediados de 2027): se agrega un índice estacional
   por mes (`venta del mes ÷ promedio móvil centrado de 12`), Holt-Winters
   aditivo simple. Queda como Fase 6.
5. Los meses en curso se proyectan "al cierre" con el ritmo diario, ajustado a
   días hábiles (sin domingos ni festivos: `Support/FestivosColombia`):
   `cierre ≈ vendido_hasta_hoy ÷ días_hábiles_corridos × días_hábiles_del_mes`.

**c) Flujo de caja proyectado (semanas 1–13)**

`saldo(s) = saldo(s−1) + cobros esperados(s) − nómina(s) − comisiones(s) − gastos(s)`.
La pantalla marca la **primera semana en que el saldo quedaría negativo** ("En
la semana del 16 de noviembre, con la quincena y el arriendo, la caja quedaría
en −$3,2 M"). Necesita el saldo inicial [P6]. Sin él, se muestra solo el **flujo
neto** por semana, sin saldo.

**d) "¿Qué pasa si…?" (simulador, Fase 4)**

Controles: ventas ±%, contratar N personas con sueldo X, subir el arriendo, un
gasto nuevo. Recalcula utilidad, punto de equilibrio y caja en el navegador con
la misma fórmula (los datos base vienen del servidor; la cuenta es una suma).
No guarda nada.

### 7.3 Punto de equilibrio

```
Costos fijos (CF)            = nómina de producción y administración + gastos con varia_con_ventas = false
Costos variables (% ventas)  = (costo de producción est. + comisiones + franquicia + gastos con varia_con_ventas = true) ÷ ingresos netos
Margen de contribución (mc)  = 1 − % variables
Punto de equilibrio (netas)  = CF ÷ mc
Punto de equilibrio (con IVA)= × 1,19 (solo sobre la parte gravada)   → la cifra que entiende el negocio
```

Las comisiones de pool **no** son lineales (solo existen sobre la meta).
Tratarlas como un % promedio está bien para un mes típico, y la pantalla lo
aclara. Se toma el promedio de los últimos 3 meses para que un mes raro no
mueva el equilibrio.

### 7.4 Calendario de pagos

Una lista de los próximos 45 días, agrupada por semana:

- **Nómina:** por cada frecuencia, el fin de ciclo (`CicloNomina::rango`) con
  cuántas personas y cuánto suman (las del mismo ciclo van juntas).
- **Comisiones:** el **20** (y los cierres trimestrales), con el total listo o
  estimado.
- **Gastos recurrentes:** por `dia_pago` (31 = último día del mes; 30 en
  febrero → último día).
- **IVA / impuestos:** según P2.
- Cada renglón dice si es seguro o estimado.

### 7.5 Gráficas (Chart.js 4, que ya está instalado)

| Gráfica | Tipo | Pestaña |
|---|---|---|
| Ingresos vs. egresos, 12 meses | Barras agrupadas + línea de utilidad | Resumen |
| Cascada del mes (ventas → … → utilidad) | Barras flotantes (`data: [[desde, hasta]]`, Chart.js las soporta sin plugin) | Resumen |
| Gastos por categoría | Dona (máx. 6 + "otros") con lista al lado | Gastos |
| Composición de egresos por mes | Barras apiladas (nómina, comisiones, fijos, variables) | Resultados |
| Flujo de caja semanal + saldo | Barras (entradas/salidas) + línea (saldo) | Flujo |
| Proyección de ventas | Línea real + línea punteada + banda (`fill` entre dos datasets) | Proyección |
| Avance hacia el equilibrio | Barra de progreso (HTML, no Chart.js) | Resumen |
| Rentabilidad por tienda | Barras horizontales ordenadas | Por tienda |

Reglas: colores de la paleta de la app, legibles en modo oscuro (rehacer el
gráfico al cambiar el tema, como `ReportesView::rebuildCharts`). Cifras en
pesos con `src/utils/pesos.js` (formato corto "$12,3 M" en los ejes). Cada
gráfica tiene un texto que la resume ("Octubre va 12 % arriba de septiembre")
para el que no lee gráficas, y para accesibilidad.

### 7.6 Rentabilidad por tienda (Fase 5)

- **Directos:** ventas de la tienda (por `tienda_id`, y las abonadas según la
  regla de comisiones), nómina de quienes tienen esa `tienda_default_id`,
  comisiones de esa tienda, gastos con esa `tienda_id`.
- **Generales** (administración, fábrica, tecnología): se reparten según P8
  (por defecto **proporcional a las ventas**).
- **Taller / fábrica:** la nómina del taller es costo de producción. Se reparte
  según el costo estimado de lo que vendió cada tienda (o según ventas, si no
  hay ficha).
- Se muestra **antes** y **después** del reparto: el reparto siempre se puede
  discutir. "Contribución de la tienda" (antes del reparto) es la cifra más
  honesta para decidir si una tienda se sostiene (sirve, por ejemplo, para
  mirar casos como el cierre de Circunvalar).

---

## 8. Experiencia de usuario y pantallas

### 8.1 Dónde vive

- Ruta `/finanzas`, vista `FinanzasView.vue`, meta `requiresFinanzas` (guard en
  `src/router/index.js`).
- Acceso desde **Inicio → Administración** (al lado de Reportes, Comisiones y
  Nómina) con `modulo: 'finanzas'`, icono `PresentationChartLineIcon`.
  Renombrable y ocultable desde Gestión.
- API en `src/api/finanzas.js`.
- Componentes en `src/components/finanzas/`.

### 8.2 Estructura (mobile first, 390 px)

Patrón del programa: `p-4 max-w-2xl mx-auto space-y-4` (en escritorio se deja
crecer a `max-w-5xl` solo esta vista, por las gráficas). Pestañas en pastilla.

```
┌────────────────────────────────────┐
│ Finanzas                     ⤓ ⚙  │  h2 + exportar + configurar
│ ‹  Octubre 2026  ›   [Todas ▾]     │  selector de mes + tienda
│ ( Resumen | Gastos | Flujo | Proy.)│  pestañas pastilla (scroll horizontal)
├────────────────────────────────────┤
│ ┌───────────┐ ┌───────────┐        │
│ │ Vendido   │ │ Cobrado   │        │  tarjetas KPI 2×2
│ │ $182,4 M  │ │ $141,0 M  │        │   ▲ 12 % vs sep
│ └───────────┘ └───────────┘        │
│ ┌───────────┐ ┌───────────┐        │
│ │ Egresos   │ │ Utilidad  │        │
│ │ $118,2 M  │ │ $35,0 M   │ 22,8 % │
│ └───────────┘ └───────────┘        │
│ ⚖ Equilibrio  ████████░░  84 %     │  "Faltan $29 M para cubrir los costos del mes"
│                                    │
│ Cascada del mes  [Devengado|Caja]  │  conmutador de lente
│ ▇ ventas ▁ iva ▂ prod ▃ nóm …      │
│                                    │
│ Próximos pagos                     │
│ 15 oct  Nómina quincenal  $14,3 M  │
│ 18 oct  Energía Norte ~$410 k est. │
│ 20 oct  Comisiones        $4,1 M   │
│                         Ver todo › │
│                                    │
│ Alertas                            │
│ ⚠ Internet Edén venció hace 2 días │
└────────────────────────────────────┘
                              (＋)     botón flotante "Gasto"
```

**Pestañas:**

1. **Resumen**: lo de arriba. La respuesta a "¿cómo vamos?" en 5 segundos.
2. **Gastos**: tres bloques:
   - *Por pagar*: obligaciones de las plantillas (vencidas en rojo, esta
     semana, después). Botón "Pagar" que abre una hoja con el monto prellenado,
     y "Omitir este mes".
   - *Pagados del mes*: lista con filtro por categoría y tienda. Dona por
     categoría.
   - Entrada a **Gastos fijos (plantillas)**: lista con frecuencia, próximo
     pago y monto. Crear/editar en hoja inferior.
3. **Resultados**: el P&G del mes en tabla (`EstadoResultados.vue`) con columna
   del mes anterior y variación, y la vista de 12 meses (barras apiladas).
   Cada línea se puede tocar → abre el detalle (qué órdenes, qué pagos de
   nómina, qué gastos). Así el número se puede revisar a mano.
4. **Flujo**: entradas y salidas del mes por semana, y el flujo proyectado de 13
   semanas con la alerta de "semana en rojo".
5. **Proyección**: ventas próximas con banda, escenarios, punto de equilibrio y
   (Fase 4) simulador "¿qué pasa si…?".
6. **Tiendas** (Fase 5): rentabilidad por tienda.

### 8.3 Formulario de gasto (hoja inferior)

Orden pensado para el celular y para registrar en 10 segundos:

1. **Monto** grande primero (`InputPesos`, teclado numérico).
2. **Categoría**: chips con icono de las 8 más usadas + "Más…".
3. Concepto (sugiere el último usado en esa categoría).
4. Tienda: General / cada tienda (chips).
5. Fecha de pago (hoy por defecto). "¿Corresponde a otro mes?" (desplegable)
   → periodo / prorrateo.
6. Medio de pago (efectivo, transferencia, tarjeta, otro).
7. Foto del recibo (varias, mismo componente que comprobantes).
8. "Repetir cada mes" → lo convierte en plantilla sin salir del formulario.

Validación en el cliente y en el servidor. `useToast` para confirmar. Al
guardar, la hoja se cierra y la cifra del resumen se actualiza (sin recargar
la página). Borrador local con `useBorradorLocal` por si se cierra la app a la
mitad.

### 8.4 Detalles de diseño

- Modo oscuro por overrides de `main.css` (sin `dark:`). Las tarjetas de KPI
  son blancas con texto de color, sin rellenos `*-50` grandes.
- Positivo/negativo con color **y** signo/flecha (nunca solo color).
- "Estimado" = chip gris `rounded-full text-xs` + borde punteado en la
  barra/tarjeta + un `ⓘ` que explica de dónde sale.
- Estados vacíos que enseñan: "Todavía no hay gastos fijos. Agrega el arriendo,
  el internet y las licencias para ver cuánto cuesta abrir las puertas cada
  mes." + botón.
- **Primer uso (asistente de 3 pasos):** 1) plantillas sugeridas con un toque
  (arriendo por tienda, internet por tienda, energía, agua, Render, Vercel,
  Aiven, OpenAI, contador); 2) saldo inicial de caja (opcional); 3) listo. Sin
  esto el módulo nace vacío y la utilidad sale inflada.
- Carga: `AppSpinner` en la primera carga; esqueletos en las tarjetas al
  cambiar de mes; las pestañas cargan su dato al entrar (como Reportes).
- Mes en curso con chip "parcial · al 9 de oct".
- Cifras grandes abreviadas ("$182,4 M") con el valor completo al tocarlas.

---

## 9. Permisos y seguridad

- **Backend:** `role:supervisor` **y** `permiso:acceso_finanzas` en todo
  `/finanzas/*`. La utilidad y los márgenes de la empresa son la información más
  sensible del sistema. No todo supervisor tiene por qué verla [P7].
- **Nómina dentro de Finanzas:** se muestra **agregada** (total y por área). El
  detalle por persona (cuánto gana cada quien) solo aparece si además tiene
  `acceso_nomina`. Si no, el detalle lleva a "ver en Nómina", que ya tiene su
  propio guard.
- **Frontend:** `auth.puedeFinanzas = isSupervisor && acceso_finanzas`. El guard
  `requiresFinanzas` está en el router. Recordar que la UI solo esconde: el
  backend vuelve a validar siempre.
- **Usuarios:** el interruptor `acceso_finanzas` va en la ficha del trabajador
  (`UsuarioDetalleView`), al lado de `acceso_nomina`.
- **Asistente de IA:** su herramienta de finanzas (Fase 5) valida el mismo
  permiso. Un vendedor que le pregunta al chat "¿cuánto ganó la empresa?" no
  recibe nada.
- **Agentes de chat (Elena):** no leen ninguna tabla nueva. Nada de este módulo
  sale por `/api/agentes/*`.
- Fotos de recibos a Cloudinary carpeta `gastos` (lista blanca).
- Bitácora de todo cambio de plata (`gastos_bitacora`).
- Exportación Excel solo con el mismo permiso. El archivo no incluye cédulas.

---

## 10. Fases de implementación

Cada fase se puede usar sola, trae sus pruebas y se entrega "lista, sin subir"
(regla 1).

### Estado (2026-10-10): implementado, sin subir

- **Backend** (`app/Services/Finanzas/*`, `FinanzasController`, `GastoController`,
  `GastoRecurrenteController`, `CategoriaGastoController`, job
  `AvisarGastosPorVencer` 08:15). **Trae migración** `2026_10_20_000001_crear_finanzas`
  (crea `categorias_gasto` sembradas, `gastos_recurrentes`, `gastos`,
  `gastos_bitacora`, `presupuestos_gasto`; agrega `usuarios.acceso_finanzas`, prendido a
  los supervisores de hoy; fila `finanzas` en `modulos`).
- **Front** (`/finanzas`, `FinanzasView` + `components/finanzas/*`): pestañas Resumen,
  Gastos, Resultados, Flujo de caja, Proyección y Tiendas; hoja de gasto, de gasto fijo
  y de ajustes; permiso en Trabajadores; entrada en Inicio; avisos `finanzas` al PWA.
  Revisado a 390 px en claro y oscuro con datos de ejemplo.
- **Gráficas:** cascada de la utilidad (horizontal para el celular), dona "¿a dónde se va
  cada $100?", 12 meses ingresos contra costos, nómina y cartera apiladas, gastos por
  categoría, fijos contra variables, presupuesto contra real, composición de costos por
  mes, márgenes, costos por área, flujo mensual con saldo, 13 semanas con alerta en rojo,
  medios de pago, ventas con banda de error, escenarios y contribución por tienda.
- **Pruebas:** `FinanzasTest` (13: cuadra con Reportes, P&G hecho a mano, nómina en caja,
  plantillas, anular, prorrateo anual, mes que corresponde, comisión con anticipo,
  permisos, pantallas, aviso, la matemática). Suite: 806 pruebas, 10 fallos
  preexistentes (las mismas 6 clases).
- **Pendiente:** herramienta del asistente de IA (`resumen_financiero`), cierre de mes
  (`cierres_financieros`), estacionalidad cuando haya 13 meses, y las decisiones de §12
  que quedan con un valor por defecto editable en Finanzas → Ajustes (P1, P2, P4–P10).

### Fase 0 — Arreglar los huecos y decidir (hecho en parte el 2026-10-09)
- [x] **Reportes sin canceladas** (P11, dueño): `Orden::ESTADOS_FUERA_DE_REPORTES`
      en `StatsController`, `ReporteController` y el reporte de ventas del
      asistente. De paso: `ordenes_canceladas` de la tabla de vendedores salía
      siempre 0 (leía un arreglo como objeto). Prueba `ReportesNoCuentanCanceladasTest`.
- [x] **Costo del empleador en Nómina** (P3, dueño): §1.2. **Trae migración**
      `2026_10_19_000001_costo_empleador_en_nomina` (solo agrega).
- [x] **Comisiones sin escribir:** verificado que `comisiones:poner-al-dia`
      deja `monto_comision` al día (§1.3).
- [x] **Caja fuera** (dueño): Finanzas no lee `caja_movimientos`.
- [ ] Responder §12 (P1, P2, P4–P10).
- [ ] Confirmar con datos reales (Aiven, **solo lectura**, con permiso) cuántas
      órdenes de un mes tienen ficha con costo (cobertura real de §3.5).
- [x] **Costo del empleador personalizable** (dueño): conceptos, porcentajes con
      fecha de vigencia y excepciones por trabajador (§1.2).
- [ ] Revisar con el contador los conceptos de Nómina → Sueldos → "Lo que paga
      la empresa por detrás" (vienen los de ley 2026: salud/ICBF/SENA apagados por
      exoneración, ARL riesgo 1) y poner en cada ficha las excepciones (quién no
      tiene pensión, la ARL del taller).

### Fase 1 — Registrar gastos (base)
- [ ] Migración `crear_finanzas` (tablas §5.1, columnas §5.2, fila de `modulos`, categorías sembradas). **Trae migración.**
- [ ] Modelos `CategoriaGasto`, `GastoRecurrente`, `Gasto`; `ObligacionesRecurrentes` (periodos calculados).
- [ ] `GastoController`, `GastoRecurrenteController`, `CategoriaGastoController` + rutas + `UploadController` (`gastos`).
- [ ] Front: `FinanzasView` con pestaña **Gastos**, hoja de gasto, plantillas, permiso, guard, entrada en Inicio.
- [ ] Asistente de primer uso.
- [ ] Pruebas §11 (grupo A).

### Fase 2 — Estado de resultados y resumen
- [ ] `FuenteVentas`, `FuenteNomina`, `FuenteComisiones`, `FuenteGastos`, `FuenteCompras`, `CostoProduccion`, `EstadoResultados`.
- [ ] `/finanzas/resumen`, `/finanzas/estado-resultados`.
- [ ] Front: pestañas **Resumen** y **Resultados**, KPI, cascada, 12 meses, detalle por línea.
- [ ] Medir consultas con `medir.sh` (≤ 12).
- [ ] Pruebas grupo B (incluida "cuadra con Reportes").

### Fase 3 — Flujo de caja, calendario y avisos
- [ ] `FlujoDeCaja`, `CalendarioPagos`, `/finanzas/flujo-caja`, `/finanzas/calendario`.
- [ ] Job `AvisarGastosPorVencer` 08:15 (`routes/console.php`) → `NotificacionService::crear` a quienes tienen `acceso_finanzas` (push). Clave de idempotencia por obligación y día, para no avisar dos veces.
- [ ] Front: pestaña **Flujo** + bloque "Próximos pagos" + alertas.
- [ ] Pruebas grupo C.

### Fase 4 — Proyecciones, equilibrio y presupuesto
- [ ] `Proyeccion`, `Indicadores` (§7.1–7.3), `/finanzas/proyeccion`.
- [ ] Tabla `presupuestos_gasto` + presupuesto vs. real por categoría (barra de avance; aviso al pasar del 90 %). **Trae migración.**
- [ ] Front: pestaña **Proyección** (banda, escenarios, equilibrio, simulador).
- [ ] Pruebas grupo D (matemática con números a mano).

### Fase 5 — Tiendas, exportación e IA
- [ ] Rentabilidad por tienda (§7.6), `/finanzas/por-tienda`, pestaña **Tiendas**.
- [ ] Exportar a Excel para el contador (P&G, flujo, gastos con PUC y foto del recibo como enlace). En el back con `maatwebsite/excel` (ya está instalado).
- [ ] Herramienta `resumen_financiero` en `AgentService` (cifras del servicio, mismo permiso).
- [ ] Cierre de mes (`cierres_financieros`): congelar un mes revisado; reabrir solo supervisor con motivo. **Trae migración.**
- [ ] Actualizar `docs/PROYECTO.md` (§6 nuevo módulo, §7 permiso, §9 tablas) y `MEMORY.md`.

### Fase 6 — Cuando haya ≥ 13 meses de datos (mediados de 2027)
- [ ] Estacionalidad (Holt-Winters), comparación contra el mismo mes del año anterior.

---

## 11. Pruebas

Nombradas por el caso de negocio, como el resto de la suite. Esquema SQLite a
mano con `TestCase::completarEsquemaDeEntregas()` y
`prestarleASqliteLoQueEsDeMysql()`.

**A. Gastos (Fase 1)**
- `InternetMensualApareceComoPendienteCadaMesTest`: plantilla del día 5 → en
  octubre hay obligación; al pagarla desaparece; el mes siguiente vuelve.
- `NoSePuedePagarDosVecesElMismoPeriodoTest`: único (`gasto_recurrente_id`,
  `periodo`), y `periodo` nunca NULL en gastos de plantilla.
- `GastoAnuladoNoSumaPeroQuedaEnBitacoraTest`.
- `LicenciaAnualSeProrrateaEnDoceMesesTest`: caja $1,2 M en enero; P&G $100 k
  por mes.
- `DiaDePago31EnFebreroCaeElUltimoDiaTest`.
- `VendedorNoEntraAFinanzasTest`, `SupervisorSinPermisoNoEntraTest`,
  `FinanzasSinAccesoNominaNoVeSueldosPorPersonaTest`.

**B. Estado de resultados (Fase 2)**
- `FinanzasCuadraConReportesTest`: vendido y cobranza del mes = `/stats/panel`.
- `NominaCuentaComoCostoBrutoNoNetoTest`: un pago con seguridad social y cuota
  de préstamo → costo = devengado bruto; caja = neto + PILA; la cuota no suma.
- `CicloSemanalQueCruzaMesSeParteTest`: 29-sep→05-oct = 2/7 y 5/7.
- `ComisionConAnticipoNoSeCuentaDosVecesTest`: anticipo $200 k en octubre +
  comisión bruta $500 k pagada el 20-nov con descuento $200 k → caja oct
  $200 k, nov $300 k; devengado $500 k en el mes de venta.
- `FinanzasNoEscribeComisionesTest`: abrir `/finanzas/resumen` no crea ni
  cambia filas en `comisiones`.
- `ReembolsoDeGarantiaNoEsGastoTest`.
- `FranquiciaDatafonoSeCalculaSolaTest`.
- `CostoProduccionSoloConFichaYConCoberturaTest`.
- `VentaDeLasOchoDeLaNocheCuentaEnSuDiaTest` (hora Bogotá).

**C. Flujo y calendario (Fase 3)**
- `CalendarioMuestraQuincenaDel15YComisionesDel20Test`.
- `AvisoDeGastoPorVencerUnaSolaVezTest` (idempotencia + `NotificacionService`).

**D. Proyección (Fase 4)**
- `TendenciaLinealConNumerosAManoTest` (serie conocida → a, b, s exactos).
- `PuntoDeEquilibrioConNumerosAManoTest`.
- `ProyeccionNominaUsaSueldoVigenteTest`.

Front: `npx vite build` sin errores + revisión a 390 px en claro y oscuro con
el servidor falso (MEMORY.md §4 "Ver pantallas sin backend").

---

## 12. Decisiones que tiene que tomar el dueño

Son reglas de plata (AGENT.md §2.3): **no se implementan sin respuesta.**

| # | Pregunta | Por qué importa | Propuesta |
|---|---|---|---|
| **P1** | ¿Todas las ventas llevan IVA del 19 %? ¿La **FV2** lleva IVA? ¿Las restauraciones? | Define los ingresos netos (÷ 1,19 o no) | Todo gravado salvo lo que el dueño diga; FV2 según `sin_descontar_iva` |
| **P2** | ¿El IVA se declara **bimestral** o **cuatrimestral**? ¿Se descuenta el IVA de las compras? ¿Retenciones, ICA? | Provisión de impuestos en el flujo y el calendario | Mostrar la provisión del IVA de ventas, sin descontables, hasta hablar con el contador |
| ~~P3~~ | **Resuelta (dueño, 2026-10-09):** sí se paga más de lo que sale en nómina (seguridad social, pensión, salud…). Se puso en Nómina, por detrás, siguiendo el patrón del módulo (§1.2) | — | Pendiente solo confirmar con el contador los porcentajes y quién va por prestación de servicios (se apaga en su ficha) |
| **P4** | ¿El costo de producción por fichas sirve como estimado del margen, o se prefiere no mostrar margen bruto hasta tener costo real? | Estimar puede confundir si las fichas están desactualizadas | Mostrarlo con cobertura y chip "estimado" |
| **P5** | ¿El 5,5 % de franquicia aplica igual a tarjeta y a Addi? ¿Se registra como gasto financiero? | Hoy solo baja la comisión; para finanzas es un costo real | Sí, como gasto financiero automático |
| **P6** | ¿Quiere registrar el **saldo de caja/bancos** (al menos una vez al mes) para proyectar cuánto alcanza? | Sin saldo, el flujo proyectado no dice "en qué semana quedamos en rojo" | Opcional; sin saldo se muestra solo el flujo neto |
| **P7** | ¿Quiénes ven Finanzas? ¿Todos los supervisores o solo algunos? | La utilidad de la empresa es lo más sensible | Permiso aparte `acceso_finanzas`, apagado por defecto; el dueño lo prende a quien quiera |
| **P8** | En la rentabilidad por tienda, ¿cómo se reparten los gastos generales (administración, fábrica, tecnología)? | Cambia cuál tienda "da" o "no da" | Por ventas, mostrando antes y después del reparto |
| **P9** | ¿Quién registra los gastos? ¿Solo quien ve Finanzas, o también un administrativo que no debe ver la utilidad? | Separar "registrar" de "ver resultados" | Si hace falta, un permiso `registra_gastos` que solo abre la pestaña Gastos (se decide en la Fase 1) |
| ~~P11~~ | **Resuelta (dueño, 2026-10-09):** los reportes no cuentan órdenes canceladas. Corregido en Reportes; Finanzas usa el mismo filtro | — | — |
| **P10** | ¿Desde qué mes se quiere ver el histórico? Los gastos de antes no estarán registrados | Meses sin gastos parecen muy rentables | Marcar como "sin gastos registrados" los meses anteriores al primer gasto |

---

## 13. Riesgos y trampas conocidas

| Riesgo | Mitigación |
|---|---|
| Cifras distintas a Reportes | Prueba "cuadra con Reportes"; reutilizar las mismas consultas |
| Doble conteo (anticipo ↔ comisión, compras ↔ costo por ficha, préstamos, prestaciones provisionadas ↔ pagadas) | Reglas §4.4 y §3.3–3.5, cada una con su prueba |
| `resumenDelMes()` escribe y es caro | No se llama: se lee `comisiones.monto_comision` (§6.2) |
| Utilidad inflada por meses sin gastos registrados o porcentajes de nómina sin revisar | P10, aviso en pantalla mientras falten datos ("faltan gastos fijos por registrar"), revisión con el contador (Fase 0) |
| Lentitud (Aiven lejos, ~200 ms por consulta) | Consultas agrupadas por mes; ≤ 12 por pantalla; medir con `medir.sh`; caché de 5 min solo para meses **cerrados** |
| Fechas UTC vs Bogotá (venta de las 8 p. m. del 31) | `RangoFechas` / `CicloNomina::fecha()`; prueba explícita |
| Índice único con NULL en MySQL | `periodo` obligatorio en gastos de plantilla; validar en código el presupuesto con `tienda_id` NULL |
| Columnas nuevas en tablas que los tests montan a mano | `TestCase::completarEsquemaDeEntregas()`; `Schema::hasColumn` donde el flujo viejo escriba |
| Proyecciones con pocos datos tomadas como certezas | Banda visible, aviso "con N meses de historia", la parte determinística separada de la estadística |
| Se registra a medias y nadie lo usa | Asistente de primer uso, plantillas sugeridas, avisos de vencimiento, registrar un gasto en 10 segundos |
| Gastos borrados para "cuadrar" | No hay borrado: se anula con motivo y queda en la bitácora |

---

## 14. Ideas para después

- **Cuentas por pagar a proveedores:** facturas de proveedores con vencimiento
  (crédito a 30/60 días), atadas a `proveedores` y a Compras.
- **Costo real de garantías:** reembolsos + horas de taller de los pasos con
  `garantia_id` × tarifa → "lo que cuestan las garantías al mes", por tipo de
  daño. Sirve para mejorar calidad.
- **Costo real de producción:** horas de `paso_trabajadores` × tarifa + tela
  descontada (`tela_reservas`) por orden → margen real por producto, no
  estimado. Es la evolución natural de §3.5.
- **Rentabilidad por canal** (WhatsApp, Instagram, tienda física), cruzando
  ventas por `canal` con el gasto en publicidad y el costo de los agentes
  (OpenAI, Twilio).
- **Rentabilidad por vendedor:** ventas − comisión − sueldo.
- **Conciliación bancaria:** subir el extracto (Excel) y emparejar con pagos y
  gastos.
- **Resumen mensual por correo/push** el día 1 al dueño: utilidad del mes
  anterior, 3 alertas y el calendario de la quincena.
- **Metas financieras:** utilidad objetivo del mes → "hay que vender $X por día
  hábil".
- **Préstamos a empleados como cartera interna:** saldo total prestado.
