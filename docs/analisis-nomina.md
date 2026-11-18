# Análisis del módulo de Nómina

Fecha: 2026-11-18. Solo análisis: no se cambió código, no hay commit y no se tocó producción.

Alcance: `Nomina*Controller`, `EmpleadoController` (la parte de Nómina), `NominaLiquidador`,
`CicloNomina`, `CostoEmpleador`, `Prestaciones`, `LiquidacionContrato`, préstamos y abonos,
adelantos, vacaciones, bonificaciones por producción, `Finanzas/FuenteNomina` y `NominaView.vue`.

**Pruebas:** las 57 pruebas de Nómina, Prestaciones, Liquidación, Bonos y Finanzas pasan
(`phpunit --filter 'Nomina|Prestaciones|Liquidacion|BonoConCuadro|Finanzas|Incapacidad|AuxilioYSeguridad|CostoEmpleador'`).
Los hallazgos marcados **[verificado]** se reprodujeron con una prueba temporal que después se
borró (no queda en el repo).

---

## 1. Cómo funciona

### 1.1 Ciclos y liquidación (`CicloNomina`, `NominaLiquidador`)
- No hay "periodos" guardados. El ciclo de cada persona sale del calendario según su
  `periodicidad` (diario, semanal lunes–domingo, quincenal 1–15 / 16–fin, cada 20 días anclado
  al 2026-09-01, mensual). Todo se cuenta en días de Bogotá.
- `liquidar()` calcula un ciclo en el momento: días = días corridos desde
  `max(inicio, nomina_desde)` hasta `min(fin, hoy)`, con tope de los días de la convención
  (15 la quincena, 30 el mes). Total =
  `sueldo × días + auxilio − faltas (horas × valor hora) − auxilio de los días de incapacidad
  − seguridad social (valor mensual fijo del sueldo × días/30) ± ajustes + bono − cuotas de préstamo`.
- El sueldo es un catálogo compartido (`nomina_sueldos`: valor por día u hora, auxilio y
  seguridad social al mes). Nada se congela hasta que se paga.
- `pendientes()` busca, por trabajador activo con sueldo, los ciclos cerrados sin pago **a partir
  del día siguiente al último pago** (o desde `nomina_desde`, con un piso de 90 días).
- El bono por producción se mide en "ventanas" (el ciclo, o una ventana fija: mensual, quincenal…)
  y se cobra en el pago donde esa ventana cierra.

### 1.2 Pagar (`NominaPagoController`)
- `store` paga un ciclo; `lote` paga varios (la pantalla los manda de a 5). El servidor recalcula
  todo; el front solo manda persona e inicio del ciclo.
- `registrar()` valida que sea un inicio real de ciclo, que ya haya cerrado, que no esté pagado y
  que no quede uno más viejo sin pagar; congela el desglose (incluido el costo del empleador) y
  engancha faltas, ajustes, producción, crea la cuota del préstamo y descuenta adelantos (lo más
  viejo primero; si el adelanto es mayor, se parte y el resto pasa al siguiente ciclo).
- `destroy` deshace un pago: suelta faltas/ajustes/producción/adelantos y borra la cuota.

### 1.3 Novedades
- **Faltas e incapacidades** (`nomina_ausencias`, una fila por día). La falta descuenta horas ×
  valor hora; la incapacidad solo el auxilio de ese día. No se pueden anotar en un ciclo pagado.
- **Ajustes** (+/−) con fecha, igual regla. "Anticipo" y "Préstamo" ya no se aceptan como ajuste.
- **Producción** (concepto × cantidad) para el bono.

### 1.4 Préstamos, abonos y adelantos
- Préstamo = monto + valor de cuota. El saldo no se guarda: `monto − Σ cuotas`. Cada pago de
  nómina descuenta `min(cuota, saldo)`. Se puede pausar. Abonos por fuera = filas `tipo=abono`
  sin pago (bajan el saldo, la cuota no cambia).
- Adelanto de sueldo: se entrega hoy, tope = ciclos sin pagar + ciclo en curso completo − lo ya
  adelantado. El pago guarda su `total` completo y aparte `adelantos`; se entrega la diferencia.

### 1.5 Costo del empleador (`CostoEmpleador`)
- Conceptos configurables con vigencia (pensión 12 %, ARL, caja 4 %, salud/ICBF/SENA apagados;
  prima y cesantías 8,33 % sobre salario + auxilio; intereses 12 % de las cesantías; vacaciones
  4,17 % sobre salario) y excepciones por persona. Base salarial = sueldo − faltas (+ bono solo si
  Ajustes dice que es salario). Se congela en `nomina_pagos.costo_empleador_detalle`.

### 1.6 Prestaciones y vacaciones (`Prestaciones`, `NominaPrestacionController`)
- Lo que se debe = lo provisionado en los pagos (y lo que va sin pagar) − lo pagado en
  `nomina_prestaciones_pagos`. Prima por semestre; cesantías e intereses por año.
- Vacaciones en días: `días pagados × 15 / 360 − días tomados`. Tres formas: `con_nomina`,
  `adelantado` (crea un adelanto), `dinero` (tope: mitad de lo causado). La fecha de regreso
  salta domingos y festivos (y el sábado según Ajustes).

### 1.7 Liquidación al retiro (`LiquidacionContrato`)
- Paga los ciclos pendientes con corte al día del retiro, prima/cesantías/intereses del periodo y
  del anterior si quedó saldo, vacaciones pendientes, indemnización art. 64 (contrato indefinido)
  y descuenta préstamos y adelantos. Saca a la persona de nómina. Se puede anular.

### 1.8 Finanzas (`FuenteNomina`)
- **Costo** (devengado): `sueldo + auxilio − faltas − incapacidad + bono ± ajustes` + costo del
  empleador, repartido por días entre meses; lo sin pagar va como estimado.
- **Caja**: el día del pago, `total − adelantos + seguridad social + aportes`; adelantos el día que
  se dieron; prestaciones el día que se pagaron (salvo las vacaciones `con_nomina`/`adelantado`).

### 1.9 Pantalla (`NominaView.vue` + `components/nomina/*`)
Pestañas de Pagos (agrupados por frecuencia y por persona; con ciclos acumulados solo deja pagar el
más viejo), Gente (ficha, préstamos, adelantos, novedades), Sueldos, Bonificaciones, Costo del
empleador y Prestaciones/Liquidaciones. Pagar todos va en tandas de 5 y siempre recarga.

---

## 2. Problemas encontrados (de lo más grave a lo menos)

### A. Se paga de más o de menos

**A1. Cambiar la frecuencia de pago paga días dos veces o se los come** — [verificado] · **ARREGLADO 2026-11-18**: el ciclo de transición arranca el día siguiente al último pago (`NominaLiquidador::rangoSinLoPagado`) y `registrar` frena todo ciclo que se cruce con un pago hecho.
`EmpleadoController.php:124` (y `:192`, el lote) deja cambiar `periodicidad` sin mirar lo ya pagado;
`NominaLiquidador::pendientes` (`NominaLiquidador.php:390-396`) arma el ciclo nuevo con la frecuencia
nueva desde el día siguiente al último pago, pero `liquidar()` cuenta desde el inicio del ciclo.
- Semanal → quincenal: se pagó la semana del 2 al 8 de nov; al pasarlo a quincenal, la quincena
  1–15 nov sale con **14 días** (del 2 al 15). Los días 2–8 se pagan dos veces (prueba: 14 días,
  $817.089 con un sueldo diario de $58.363).
- Quincenal → mensual a mitad de mes: pagado el 1–15; el ciclo mensual empieza el 1, que ya
  "está pagado" (la marca es por fecha de inicio): del 16 al 30 **no se paga nunca**, ni deja
  registrarlo ("ya estaba pagado").
- Recomendación: no dejar cambiar la frecuencia si el ciclo nuevo que contiene el día siguiente
  al último pago no empieza justo ahí (o cambiarla "desde" el próximo corte), y que `liquidar`
  arranque en `max(inicio, último pago + 1, nomina_desde)`.

**A2. Deshacer un pago que no es el último lo esconde para siempre** — [verificado] · **ARREGLADO 2026-11-18**: solo se deshace el último pago de la persona, y nunca uno de una liquidación al retiro (también cubre D2).
`NominaPagoController.php:201-220`. Pendientes arrancan después del **último** pago
(`NominaLiquidador.php:373-376, 486-490`) y `cicloAnteriorSinPagar` tampoco mira antes de él.
Caso: pagados 16–31 oct y 1–15 nov; se deshace el de 16–31 oct → la lista de pendientes queda
vacía. La persona nunca cobra esa quincena y la pantalla dijo "El ciclo vuelve a quedar pendiente"
(`NominaView.vue:241`). También pasa si se deshace un pago creado por una liquidación al retiro
(no se revisa). Recomendación: permitir deshacer solo el último pago de la persona (o hacer que
pendientes busque huecos desde el piso de 90 días), y frenar los que pertenecen a una liquidación.

**A3. La segunda quincena de febrero y el mes de febrero se pagan con 13 y 28 días** — [verificado]
`NominaLiquidador.php:73-75`: `dias = min(días corridos, 15)`. El comentario de
`CicloNomina::diasPagados` (`CicloNomina.php:70-75`) dice lo contrario ("la del 16 al 28 de febrero,
que son 13, se pagan 15"). Con el mínimo, cada febrero son ~2 días menos (~$117.000 por persona
quincenal o mensual). La convención de 30 días se cumple en meses de 31 (16 corridos → 15) pero
no en febrero. **Pregunta para el dueño/contador**: ¿febrero se paga con 15/30 días? (lo usual en
Colombia es sí). Lo mismo pasa con quien entra a mitad de un mes de 31 (entra el 20: 12 días
corridos, por convención 11).

**A4. Los intereses de cesantías se provisionan al doble (o más) en años incompletos**
`CostoEmpleador.php:69` y la migración `2026_10_19_000001_costo_empleador_en_nomina.php:45`:
intereses = 12 % de las cesantías de cada ciclo. La ley (Ley 52/1975) es
`cesantías × días × 12 % / 360`. En un año completo da lo mismo; en uno incompleto no:
alguien que trabajó de julio a diciembre con $2.000.000/mes (sueldo + auxilio) tiene ~$1.000.000
de cesantías; por ley los intereses son ~$60.000 y el sistema calcula ~$120.000. Afecta a todo el
que entra o sale a mitad de año (liquidaciones incluidas, `LiquidacionContrato.php:88-107`) y al
costo que ve Finanzas. **Confirmar con el contador** antes de tocarlo (es regla de plata).

**A5. Una cuota de préstamo mayor que el pago deja el pago en negativo** — [verificado] · **ARREGLADO 2026-11-18**: se descuenta como máximo lo que el ciclo deja; el resto sigue en el saldo y la pantalla lo avisa.
`NominaLiquidador.php:207-210` resta la cuota sin piso. Caso: quincena de $875.453 y cuota de
$2.000.000 → pago registrado por **−$1.124.547**. Los adelantos sí tienen piso en 0
(`NominaPagoController.php:375`). Pasa con faltas largas, incapacidades o una cuota mal escrita.
Recomendación: descontar como máximo lo que deja el pago en 0 y dejar el resto de la cuota para
el siguiente.

**A6. Con varios ciclos sin pagar, cada uno muestra la cuota completa del préstamo** — [verificado] · **ARREGLADO 2026-11-18**: cada ciclo descuenta sobre el saldo que deja el anterior (también en la liquidación) y al pagar se guarda esa misma cuota.
`NominaLiquidador.php:133-135` calcula la cuota sobre el saldo de hoy en cada ciclo pendiente.
Préstamo de $100.000 en 1 cuota y 3 quincenas pendientes: las tres muestran −$100.000. Al pagar
de verdad solo se descuenta una (el lote relee), pero:
- el "Total a pagar" y el `confirm` de Pagar todos salen $200.000 por debajo;
- la **liquidación al retiro** suma el `total` de esos ciclos (`LiquidacionContrato.php:77`) con
  las cuotas repetidas, y aunque `resto` no baja de 0 (`:141-145`), el total de la liquidación queda
  por debajo de lo que de verdad se le debe; el `NominaPago` creado y la liquidación no cuadran.
Recomendación: en `pendientes()` y en `calcular()` ir restando la cuota del saldo ciclo por ciclo,
como ya se hace con los adelantos (`conAdelantos`).

**A7. El préstamo se descuenta de ciclos anteriores a la fecha del préstamo**
`NominaLiquidador.php:133-135` no mira `nomina_prestamos.fecha`. Si la quincena del 1–15 nov
sigue sin pagar y el 20 se presta plata, la quincena vieja ya trae la cuota. **Pregunta:** ¿debe
empezar a descontar en el ciclo que contiene la fecha del préstamo? Igual con la frecuencia: un
trabajador **diario** paga una cuota por día (la pantalla habla de "cuotas por quincena").

### B. Reglas que hay que confirmar con el dueño o el contador (no son bugs seguros)

**B1. Subir un sueldo mueve también ciclos ya cerrados sin pagar.** `NominaSueldoController::update`
lo dice en el comentario. Si el aumento de enero se carga el 2 de enero y la quincena 16–31 dic
no se ha pagado, se paga con el sueldo nuevo. ¿Es lo que quieren? Una salida es que el sueldo
tenga vigencia ("desde"), como ya la tienen los porcentajes del costo del empleador.

**B2. La falta de un día completo no quita el auxilio de transporte ni la seguridad social de ese
día** (`NominaLiquidador.php:99-103, 125`). Por ley el auxilio se paga por día trabajado.

**B3. La incapacidad se paga completa por la empresa** (`NominaLiquidador.php:126-127`): solo se
quita el auxilio. Desde el día 3 la paga la EPS al 66,67 %. ¿La empresa paga el 100 % y recobra, o
debería bajarse? Afecta también la base del costo del empleador.

**B4. El bono por producción no es salario por defecto** (`CostoEmpleador.php:142-148`, apagado si
no hay valor). Un bono habitual por producción suele ser salario (art. 127 CST) salvo pacto
(art. 128, tope del 40 % de la Ley 1393). Si es salario, cambian aportes, prima, cesantías y la
indemnización (que hoy usa solo el sueldo, `LiquidacionContrato.php:198`).

**B5. Los ajustes positivos (horas extra, recargos) no entran a la base salarial** · **ATENDIDO 2026-11-18**: las horas extra ahora tienen su propia pestaña y entran a la base salarial (otros ajustes positivos siguen fuera).
(`CostoEmpleador.php:275`). Si se usan para eso, faltan aportes y prestaciones sobre ellos.

**B6. La seguridad social del trabajador es un valor fijo al mes del sueldo**, no el 8 % de lo
devengado: con bono o ajustes no cambia. Coherente con B4/B5, pero conviene que el contador lo vea.

**B7. Vacaciones antes del año.** Se pueden tomar desde que se causa el primer día
(`NominaPrestacionController.php:168-172`). La ley las da al cumplir el año (se pueden anticipar
por acuerdo). ¿Se deja así?

**B8. Indemnización con años de 365 días.** `LiquidacionContrato.php:202` divide días calendario
entre 360: 5 años reales son 5,07 "años" (~1,4 % de más). Los años de servicio se cuentan en
meses de 30 días.

**B9. Valores por defecto sin confirmar** (ya en MEMORY): SMMLV 2026, fechas límite, 15 días,
sábado hábil, sin auxilio en vacaciones, indemnización, porcentajes de ley.

### C. Contabilidad y Finanzas

**C1. Vacaciones `adelantado` y `con_nomina` se restan del costo con montos distintos**
(`FuenteNomina.php:117-121`, montos en `NominaPrestacionController.php:220-222`): `con_nomina`
guarda `días hábiles × valor día`; `adelantado` guarda `días calendario × valor día − seguridad
social` (un neto) y se resta del bruto. 15 días hábiles con adelanto restan ~3 días de más y
mezclan neto con bruto. Además se restan en el mes de `fecha_pago`, que puede no ser el de la
quincena. Recomendación: guardar siempre el valor de los días hábiles (lo provisionado) para
Finanzas y aparte lo entregado.

**C2. El área (producción, ventas, administración) sale del rol de hoy** (`FuenteNomina.php:215-226`):
si alguien pasa del taller a ventas, sus pagos viejos cambian de área en los estados de resultados
cerrados. Menor; se puede congelar el área en el pago.

**C3. Préstamos fuera de caja** (ya conocido en MEMORY): el día que se presta no sale plata en el
flujo, y la cuota descontada tampoco entra.

**C4. Ajustes negativos que no son costo** ("Herramienta") bajan el costo de la nómina; los
anticipos viejos guardados como ajuste también (conocido).

### D. Robustez

**D1. Quitar a alguien de nómina esconde lo que se le debe.** `EmpleadoController::destroy` quita el
sueldo; `empleadosLiquidables()` exige sueldo, así que sus ciclos cerrados sin pagar desaparecen de
Pagos, Finanzas y Prestaciones. Recomendación: avisar o frenar si tiene pendientes (o mandar a
Liquidación).

**D2. Deshacer un pago no revisa liquidaciones ni prestaciones ya pagadas** sobre ese pago
(relacionado con A2).

**D3. Faltas antes de entrar a nómina se descuentan** (`NominaLiquidador.php:113` usa `inicio`, no
`desde`). Caso: entra el 20 y por error se anota una falta el 18: se descuenta un día que no se pagó.

**D4. Sin bloqueo entre pagos simultáneos de la misma persona.** Dos ciclos de la misma persona
pagados a la vez (dos pestañas) calculan la cuota sobre el mismo saldo (`NominaPagoController.php:337-348`)
y pueden pasarse del saldo. Lo mismo `prestaciones/pagar` con doble clic exacto. Bajo, se arregla con
`lockForUpdate` sobre el trabajador como ya hace `NominaAdelantoController`.

**D5. Rendimiento:** `Prestaciones::vacaciones($id)` llama a `sinPagar($id)`, que liquida **a todos**
los trabajadores para quedarse con uno (`Prestaciones.php:176-191, 292`). Se llama varias veces al
registrar vacaciones o liquidar (con ~200 ms por consulta en Aiven). Pasar el trabajador ya cargado.

**D6. Bonificaciones con huecos entre tramos** (`NominaBonificacion::metaPara`): si un tramo termina
en $2.800.000 y el siguiente empieza en $2.900.000, $2.850.000 no paga nada. Verificar que el cuadro
no deje huecos al guardar.

### E. Pantalla
- `NominaView.vue:241`: "El ciclo vuelve a quedar pendiente" es falso si no es el último pago (A2);
  además muestra `p.total` y no lo entregado (`a_entregar`).
- El historial trae solo los 100 pagos más recientes de todos (`limite: 100`), sin paginar.
- El "Total a pagar" sale bajo cuando hay varios ciclos con préstamo (A6).

---

## 3. Recomendaciones, en orden

1. ~~**A1 y A2**~~ (hechos el 2026-11-18, `NominaCambioDeFrecuenciaYDeshacerTest`) (pagan doble o dejan sin pagar, sin aviso): bloquear el cambio de frecuencia a mitad
   de ciclo y deshacer solo el último pago. Cambios pequeños y sin regla de plata nueva.
2. ~~**A5 y A6**~~ (hechos el 2026-11-18): piso en 0 para la cuota y cuota ciclo por ciclo en pendientes y en la liquidación.
3. Preguntar al dueño/contador **A3** (febrero), **A4** (intereses), **A7** y **B1–B8**; con las
   respuestas, ajustar. A4 cambia lo que se debe: hacer prueba diferencial antes y después.
4. **D1** (avisar antes de quitar a alguien con pagos pendientes) y **C1**.
5. Pruebas que faltan: cambio de frecuencia, deshacer un pago del medio, febrero, cuota mayor que
   el pago, varios ciclos con préstamo, liquidación con préstamo y dos ciclos pendientes,
   intereses de un año incompleto.
