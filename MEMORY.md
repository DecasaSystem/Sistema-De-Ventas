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
  c8eb083). Las trimestrales no usan el libro, pero su pool sí mide solo lo que tiene
  el 50 % pagado (`totalesQueCuentan`).
- **Revisado a fondo el 2026-10-13 (dueño: "si no ha pagado el 50 % es venta pero no
  suma a la meta"):** comisiones ya lo cumplían; Reportes (tarjeta de tienda) y
  Estadísticas del vendedor mostraban el avance con **todo lo vendido** ("¡Meta
  alcanzada!" sin pool). Ahora todas las pantallas salen de
  `ComisionController::avanceDeMetas()` (cuenta + vendido) y muestran aparte lo vendido
  sin el 50 %. Las bonificaciones de Nómina son por **producción del taller**, no por
  ventas: esta regla no les aplica. Trampa vista: Laravel reusa el controlador entre
  peticiones del mismo proceso; un caché en una propiedad del controlador sirve datos
  viejos (Stats ahora guarda el avance en `request()->attributes`).
- Se pagan todas de una por persona, no orden por orden.
- *Historia:* el 2026-09-23 se intentó quitar el requisito del 50 % (ca838c2) y el
  usuario lo rechazó con fuerza (revertido en 177f7f9).
- Explicación larga para negocio: `explicacion_modulo_comisiones.txt` (raíz).

### Trimestre de Pereira: hasta cuándo cuentan los abonos (dueño, 2026-10-24)
- El trimestre es de ventas del 1 de julio al 30 de septiembre (octubre ya es el siguiente).
- **Los abonos que completan la mitad cuentan hasta el día en que se paga el trimestre**,
  como en las mensuales. Antes el trimestre quedaba quieto el 20 aunque nadie hubiera
  cobrado (Unicentro Q3: Federico Márquez #1251 completó la mitad el 21 oct y no contaba).
  Ahora `cargarPoolsTrimestrales` lo sigue calculando si llegó a la meta y no se ha pagado
  nada de su pool; queda quieto al primer pago o si el 20 no llegó a la meta (la deuda pasa
  fija). Unicentro Q3 con esto: pool $1.340.100 (antes $1.230.856). Tests en
  `PoolTrimestralSePagaUnaVezTest`.

### Restauraciones (dueño, 2026-10-23, al conciliar Norte)
- **Esperan el 50 % pagado**, como las ventas (tiendas y bolsón de independientes). Antes se
  pagaban con solo llegar el 20; se cambió en `enriquecer` y `ComisionIndependientes::delMes`.
- **No se les quita el IVA** (5 % del valor completo: es mano de obra). El Excel de Norte las
  dividía por 1,19: el Excel estaba mal.
- **La tarjeta débito sí paga el 5,5 %** de franquicia, como la crédito (en el sistema se
  registra como "tarjeta").

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

### Garantías (lo que se daña después de entregado) — dueño, 2026-10-09
Plan y flujo: `docs/plan-garantias-posventa.md`. Decisiones del dueño:
- Cambio por **otro producto** de distinto precio: **lo decide quien aprueba**, que
  es un supervisor (mueve el valor y la comisión). Por defecto, el precio de lista.
- **No procede** (vencida o exclusión del anexo): se cierra con la causal; un
  arreglo pagado es una orden de restauración aparte.
- Se arregla **en el taller o a domicilio**.
- La vigencia sale del tipo de daño (y la línea, si es madera) que se escoge al
  reportar; el sistema avisa si está vencida, pero decide la persona.
- **Reembolso**: lo aprueba un supervisor (monto sugerido = lo que pagó por esas
  unidades, editable); la plata sale **cuando el cliente devuelve el producto**; lo
  devuelto **deja de contar como venta** (total, meta, comisión pendiente; la ya
  pagada no se toca); lo devuelto va a inventario o merma según se decida al
  recibirlo. Se guarda como **pago negativo `tipo=reembolso`** (así saldo, caja,
  cartera y el 50 % lo cuentan solos): no editarlo como un abono.
- **El anexo de garantías es obligatorio solo en ventas** (dueño, 2026-10-16): en venta
  presencial se pide firmado en el sistema o la foto del papel; en una **restauración**
  (serie R o todos los ítems `es_restauracion`) es opcional. Una orden que mezcla producto
  y restauración es venta y sí lo pide. Regla en `Orden::esSoloRestauracion()` (confirmar
  cotización, completar borrador, convertir cotización), en `OrdenController::store`
  (crear desde Nueva orden; antes no lo exigía) y repetida en Nueva orden / OrdenDetalle /
  CotizacionDetalle. No se pide al guardar borrador ni con precio por consultar (se pide
  al confirmar), ni en venta virtual. Las pruebas que crean ventas presenciales mandan
  `anexo_foto_url`.
La regla técnica que no hay que romper: al arreglar en el taller el producto se
**reactiva** en la misma orden y se vuelve a entregar por el camino normal, **sin
descontar stock otra vez** (`orden_items.cantidad_en_garantia`, `EntregaService`).

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
- **Elena puede ofrecer 5 % pagando en efectivo o transferencia** (dueño,
  2026-10-08). "Un poco más" lo decide un asesor humano: el bot nunca promete más.
  La cifra con descuento la calcula el código (`negocio.conDescuentoEfectivo`).
- Numeración: consecutivo por grupo (Armenia vs Pereira). FV2 y R (restauración
  pura) llevan serie propia. Borradores y "esperando precio" no gastan número.
- Un vendedor no cambia plata solo: lo pide (`SolicitudCambio`) y un supervisor
  aprueba.
- **Reasignar el vendedor se lleva la tienda** (dueño, 2026-10-16, caso R-1093:
  la subió Flabio y era de Gladys, de El Edén). Si el vendedor nuevo tiene tienda,
  la orden pasa a esa tienda (también entregada) y la comisión se rehace ahí: en
  una tienda que reparte, la restauración se parte entre el equipo. Si el
  supervisor escoge otra tienda a mano, manda la suya. Lo apartado **no** se muda:
  a los ítems se les fija `tienda_origen_id` = tienda vieja (de ahí sigue saliendo
  el mueble). Tests en `ReasignarVendedorTest`.

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
- **Elena asesora experta + Clientes de redes** (`docs/plan-elena-asesora-ventas.md`),
  hecho el 2026-10-08, **sin subir**, rama `claude/ai-sales-improvement-feddff` + repo
  `Desktop/Agentes`. Trae **migración** (`2026_10_16_000001_create_clientes_redes_table`,
  solo crea tabla). Pedido del dueño: método de venta consultivo, pregunta de enganche al
  final, asesor humano siempre a la mano, un intento de retener al que se va, 5 % efectivo,
  y **nombre + celular antes de transferir** → ficha en Clientes → Redes. Si el cliente no
  quiere dar datos se transfiere igual. **El catálogo va primero**: si el cliente pide una
  categoría ("quiero ver camas"), Elena lo manda de una vez y en el mismo mensaje pregunta
  para entender qué busca (dueño: el catálogo despierta el interés; **no usar la palabra
  "antojar"**, le parece rara). El cliente queda en Clientes → Redes **apenas da nombre o
  celular**, aunque no pida asesor (`guardar_contacto` → `POST /api/agentes/clientes-redes`,
  sin tarjeta), y Elena lleva un resumen de lo que busca (`interes`). **Regla del dueño:
  si la IA no sabe un dato, no lo inventa: lo pasa a un asesor** (está por encima de
  cualquier técnica de venta; sección SI NO LO SABES del prompt). Evaluación con el modelo
  real (`npm run eval`, la clave la pone el dueño en su PowerShell; aquí no hay
  `OPENAI_API_KEY`): 72 %/67 % → 97 %/92 %. Trampa: aunque el prompt lo prohíba, gpt-4o
  decía "te envío el catálogo" sin llamar la herramienta; por eso existe
  `core/verificacion.js` (revisión antes de enviar). Orden de despliegue
  sugerido: backend, agentes, front.
  Los `npm run eval` nuevos (9 casos de ventas) **no se han corrido** (cuestan dinero).
- **Clientes → Redes también a mano** (dueño, 2026-10-13): el apartado es la base de datos
  de interesados, no solo lo que guarda Elena. Botón *Agregar*: llamada, vino a la tienda,
  referido, otro, o WhatsApp/Instagram sin pasar por Elena. Un registrado a mano con
  WhatsApp usa el celular como identificador (igual que el agente) y Elena sigue llenando
  esa ficha. No se repite celular. Sin migración. Detalle en `docs/PROYECTO.md` (Clientes).
- **Garantías posventa** (`docs/plan-garantias-posventa.md`), hechas y **subidas** el
  2026-10-09 (commit 95e0c15, rama `claude/warranty-post-sale-management-b20972`). Trae **migración**
  (`2026_10_17_000001_garantias_posventa`, solo agrega). De paso se corrigió que
  `ProduccionController::crearPasos` borraba los pasos completados (y en cascada las
  horas/calificaciones de `paso_trabajadores`) al rearrancar una pieza: ahora solo quita
  lo no hecho y agrega después (`agregarPasos`). Recoger el mueble de la casa no es ruta
  de conductor: se marca a mano "Ya llegó al taller".
- **Firma remota de la orden** (2026-10-09, **subida** junto con el arreglo de fotos de
  borrador y la revisión del anexo; misma rama, sin migración).
  Decisiones del dueño: orden y anexo se firman **juntos, una sola firma**; se manda
  desde Nueva orden **y** desde una orden ya creada sin firma; **sin firma no se crea
  la orden** (venta virtual: se manda el enlace y se guarda como borrador si el cliente
  se demora); el cliente ve productos completos, plata, entrega y sus datos (si algo
  está mal, se lo dice al asesor: no edita nada). Detalle en `docs/PROYECTO.md` §6.16.
  Trampa: SQLite convierte en texto las columnas que no existen entre comillas; en las
  pruebas, crear de verdad las columnas que se leen (`clientes.telefono`...).
- **Chat de la orden con varias fotos** (2026-10-09, **subido**). Trae **migración**
  `2026_10_18_000001_varias_fotos_en_chat_de_orden` (`orden_mensajes.imagenes`, solo
  agrega; `imagen_url` sigue con la primera). Qué fallaba: una sola foto; el input no se
  limpiaba si algo salía mal (elegir la misma foto otra vez no hacía nada); una foto que
  fallaba borraba a otra que sí subió; `fetch` a mano sin reintento. Además el chat se
  escondía en silencio a Producción y a los compañeros de tienda. Ahora participan
  supervisores, `gestiona_produccion`/`acceso_produccion` y, entre vendedores,
  `Orden::laPuedeEditar` (no conductores); la pantalla pinta el chat y se esconde sola
  si el servidor responde 403.
- **Módulo de Finanzas** (`docs/plan-gestion-financiera.md`): **implementado el
  2026-10-10 y subido a `main` ese día** (el dueño pidió subirlo al terminar). Trae **3
  migraciones** (`2026_10_19_000001_costo_empleador_en_nomina`,
  `2026_10_20_000001_crear_finanzas`, `2026_10_21_000001_prestaciones_y_liquidaciones`;
  solo crean/agregan). Incluye las recomendaciones: herramienta `resumen_financiero` del
  asistente, cierre de mes (foto congelada; no se registran gastos en meses cerrados),
  resumen del día 1, cuentas por pagar a proveedores (la factura es gasto del mes de la
  factura; sus abonos solo cuentan como caja), garantías, rentabilidad por canal y
  estacionalidad. **El costo de producción real NO está listo** (dueño, 2026-10-10): sigue
  el estimado por fichas, apagable en Ajustes. Nómina ahora tiene **Prestaciones**
  (prima, cesantías, intereses, vacaciones: debido = provisionado en pagos − pagado) y
  **liquidaciones** al retiro (`LiquidacionContrato`: ciclos pendientes, prestaciones,
  vacaciones, indemnización art. 64 configurable, préstamos; se puede anular). Falta
  que el dueño/contador confirme los valores por defecto de Finanzas → Ajustes y de
  Prestaciones (SMMLV, fechas límite, días de vacaciones, indemnización). El simulador usa el factor real de la nómina, no un % fijo. "+ Gasto" va en
  el encabezado y no flotando: abajo a la derecha está el asistente de IA. Junta ventas,
  nómina, comisiones, caja y compras con gastos fijos (plantillas recurrentes calculadas
  como los ciclos de nómina) y variables; P&G (devengado) y flujo de caja, calendario de
  pagos y proyecciones. Fase 0 (huecos) **hecha el 2026-10-09, sin subir**; faltan P1, P2,
  P4–P10 (§12). Decisiones del dueño ese día:
  - **Los reportes no cuentan órdenes canceladas** (ni lo vendido ni lo abonado):
    `Orden::ESTADOS_FUERA_DE_REPORTES` en Stats, Reportes y el asistente. El conteo de
    "canceladas" sigue saliendo aparte.
  - **La nómina cuesta más de lo que se le paga al trabajador**: aportes del empleador y
    prestaciones se calculan **por detrás** en Nómina (`Services/CostoEmpleador`), sin tocar
    el `total`, y se congelan en `nomina_pagos.costo_empleador(_detalle)`. **Trae migración**
    `2026_10_19_000001_costo_empleador_en_nomina` (solo crea tablas y agrega columnas).
    **Todo personalizable** (pedido del dueño: cambia la ley y no a todos les toca lo mismo):
    conceptos como filas, porcentaje con fecha de vigencia (un cambio de ley se carga
    "desde" y no mueve lo de antes), excepciones por trabajador (sin pensión, ARL del
    taller). Nunca volver a meter porcentajes fijos en el código. Vienen los de ley 2026
    (salud/ICBF/SENA apagados por exoneración, ARL 1): **revisar con el contador**.
  - **La caja de tienda no entra a Finanzas** (es el efectivo de las ventas, ya en `pagos`).
  Trampas: `nomina_pagos.total` es NETO (sin seguridad social ni cuotas de préstamo);
  `ComisionController::resumenDelMes()` escribe (no llamarlo desde lecturas: Finanzas lee
  `comisiones.monto_comision`, que `comisiones:poner-al-dia` deja al día a las 6:30).
- **Corregir abonos, no solo el anticipo** (2026-10-13, **subido a `main`** ese día,
  commits 230be13 + d3ccfcb, rama `claude/edit-order-payments-1cf4f1`, sin migración). Pedido del dueño: el vendedor lo
  pide (motivo + soporte, como el anticipo), el supervisor lo hace directo; queda en el
  historial. Se reutilizó todo el camino de `SolicitudCambio` / `PATCH /pagos/{id}`; lo
  nuevo es la pantalla ("corregir" en el historial de pagos) y que la solicitud se frena
  al pedirla si al aprobarla no se podría aplicar. Decisiones del dueño ese día: el
  monto se corrige también en `listo_entrega` y `en_camino` (no en `entregado`); y
  **corregir un pago a tarjeta/Addi/otro quita el descuento condicionado**, igual que al
  registrarlo (`Pago::pierdeDescuentoSiPasaA`, aviso 409 + `aceptar_perdida_descuento`;
  al aprobar la solicitud de un vendedor se acepta solo, porque el resumen ya lo decía).
  **Y al revés (dueño, mismo día): corregir de tarjeta a efectivo/transferencia
  devuelve el descuento** ("que tal que se haya equivocado el cliente o el vendedor"),
  si ya no queda ningún pago con tarjeta/Addi/otro y si con el total rebajado no queda
  pagado de más (si pagó el precio completo, se corrige solo el medio). La cifra sale de
  `descuento_condicionado` o, si editar la orden la dejó en 0, del historial
  (`DescuentoCondicionadoService::montoPerdido` / `devolver`, `Pago::recuperaDescuentoSiPasaA`).
- **Promociones por tienda** (2026-10-16, **sin subir**, rama
  `claude/inventory-promotions-by-store-d04803`). Trae **migración**
  `2026_10_23_000001_promociones_por_tienda` (crea `promociones` y `promocion_tienda`,
  agrega `orden_items.promocion_id`; solo agrega). Pedido del dueño: escoger producto o
  variante (la cama Bali de 1.40), tiendas, cuánto y fechas, "para no cambiarle el valor a
  cada rato". Decisiones que tomé por defecto y **falta que el dueño confirme**: solo un
  supervisor las programa; cuenta la tienda donde se vende; el descuento manual del
  vendedor y el 5 % de efectivo se aplican **encima** de la promoción (no se cambió ninguna
  regla de plata existente); lo mandado a fabricar igual sí la lleva, lo personalizado no.
  `precio_adicional` de una opción es el **precio completo** de esa versión (commit
  8be6205; en 0 = el del producto). Inventario → Gestionar, las pastillas de la tarjeta,
  Reserva, Editar orden, el Excel de inventario y el **precio sugerido del cambio por
  garantía** todavía lo trataban como recargo ("+$", base + opción); se corrigieron el
  2026-10-16 (mismo trabajo). Nunca volver a sumarlo. **Elena todavía no
  sabe de promociones** (lee precios directo de la BD): cotiza a precio de lista.
- **Perfiles alternos guardados en la cuenta** (2026-10-16, **sin subir**, rama
  `claude/dual-profiles-persistence-61ee57`). Trae **migración**
  `2026_10_24_000001_perfiles_alternos_autorizados` (agrega `perfiles_alternos.autorizado_at`).
  Queja del usuario: la sesión de cada perfil vivía en el localStorage; en otro aparato o si
  el navegador lo borraba, había que reactivarlos. Ahora: la principal anota (PATCH
  `mis-perfiles-alternos`) y la otra persona **autoriza con su propia sesión**
  (`POST /auth/perfiles-alternos/confirmar`); al entrar en cualquier aparato,
  `POST /auth/perfiles-alternos/sesiones` le abre la sesión a los autorizados que falten.
  Seguridad: anotar a alguien no lo autoriza (antes cualquiera podía anotar al supervisor);
  desactivarlo o que cambie su contraseña retira el permiso. Los vínculos viejos se
  autorizan solos la primera vez que abre la app el aparato que tiene esa sesión.
- **Comisiones atrasadas: dos meses juntos** (2026-10-15, **sin subir**, sin migración).
  Pedido del dueño: si se demoran en pagar y se juntan dos pagos, que se sepa qué es de
  qué fecha. Hecho: Listas muestra todo lo por pagar con un bloque por mes y la fecha
  en que se debía pagar; se paga del más viejo al más nuevo con un recibo por mes; pagar
  en desorden se frena. **No cambió ninguna regla de plata**: cada mes se calcula y se paga
  igual que antes. Por qué el orden: los anticipos se descuentan "hasta el mes que se
  paga", así que pagar octubre antes que septiembre le descontaba los dos anticipos a
  octubre y lo entregado ese día cambiaba. Las bonificaciones de Nómina no entran (van
  en cada pago de nómina). Tests en `ComisionesAtrasadasTest`.
- **Abonos de comisión** (2026-10-19, **subido a `main`** ese día, commit 8f59fdd, sin migración, rama
  `claude/compassionate-blackwell-a0a2df`). Pedido del dueño: pasó el día de pago de agosto
  y a los vendedores les dieron un abono, no todo; que Listas siga mostrando lo que falta.
  No es todos los meses: se anota el día que se da (Listas → "Registrar abono"). Se guarda
  como un anticipo más del mes de esa comisión, en **su propio renglón** (clave `abono:…`):
  antes `asegurarHasta` buscaba el anticipo fijo por mes y un segundo renglón del mismo mes
  lo habría pisado; ahora busca por clave. No cambia ninguna regla de plata (comisión,
  pool, partes del equipo iguales); solo lo que se entrega al pagar. Trampa evitada: anotarlo
  con el mes de hoy (octubre) no lo descontaba de agosto (el corte de agosto es agosto).
  Tests en `ComisionesAtrasadasTest` (sección abono).
- **Conciliación de comisiones contra los Excel de ventas** (empezó 2026-10-19, pedido del
  dueño: tienda por tienda, mes por mes). Excel `Downloads/VENTAS EDEN 2026.xlsx` (una hoja por
  mes: ventas, restauración, compartidas). La "Hoja1" es un **borrador de alguien, no la
  cuenta oficial**: no compararse contra ella. Agosto El Edén: cuadra (18 ventas $53,59 M +
  R-1093); se corrigió a mano el pago de Laura Parra #4297 a tarjeta; quedó Gladys $301.019,
  Sebastián $257.709, Manuela (5 días de reemplazo) $11.033 por pagar. **Septiembre El Edén
  cuadra** (2026-10-20): 24 ventas $98,25 M + R-1116 $1,8 M; corregidos Natalia #4315 (Addi),
  Claudia #4359 (tarjeta), abonos de Paola #4356; Lorena #4340 se dejó como tarjeta (el Excel
  dice transferencia: pendiente de verificar). Quedó $1.247.352 para Gladys y para Sebastián.
  R-1116 la subió Henry compartida con el Edén y el Excel dice Gladys: **da lo mismo en plata**
  (toda restauración entra al bolsón de independientes, la suba quien la suba; la tienda cobra
  su 5 % igual). Archivo para el usuario: `Downloads/Conciliacion comisiones Eden ago-sep 2026.xlsx`
  (lo arma un script de PHP con PhpSpreadsheet del vendor; marca VERIFICAR lo corregido).
  **Pereira** (2026-10-22, solo órdenes, no comisiones: el dueño pidió "si las órdenes están
  bien subidas"). Excel `Downloads/VENTAS DE CASA JUNIO 2026 (1).xlsx` (hojas AGOSTO 2026 y
  SEPTIEMBRE 2026: Circunvalar + Unicentro) y, para cruzar, `FORMATO INFORME DE VENTAS MENSUAL
  DE CASA UNICENTRO 2025} (1).xlsx` (hasta agosto; necesita `-d memory_limit=2G`). Agosto: sistema
  $57,914 M vs Excel $52,214 M = #1239 Julieth Paola Morales $5,7 M que no está en el Excel.
  Septiembre: $90,127 M vs $89,897 M = Iván Delgado #1255 (+$220.000) y #1242 Karolay ($10.000).
  Las órdenes de Génesis del 27–31 ago están en Unicentro (traslado registrado al cerrar
  Circunvalar) y el Excel las pone en Circunvalar. Q3 de Pereira **sin pagar** a esa fecha.
  2026-10-23: el usuario corrigió todo lo demás de Pereira. **Decidido (dueño, 2026-10-24):
  Circunvalar y Unicentro NO se juntan**, cada una con su meta y su pool, aunque Circunvalar no
  comisione nada: no llegó a la meta de $40 M ni en julio (~$34 M) ni en agosto (~$23 M; cerró
  el 27). Las órdenes de Génesis del 27–31 ago (José Norbey #1235, Yamil Avid
  #1236, Choconta #1238, Nancy Obando #1240, R-1100 Clara Muñoz) **se quedan en Unicentro**
  (ella se cambió de tienda esos días; el traslado del 27–31 ago está bien registrado). La
  mitad de Génesis de la #1224 (julio, compartida con Juan Esteban) sigue en Circunvalar.
  **#1215 de Génesis (WhatsApp, 17 jul, $3,2 M)** contaba para Unicentro (le daba $134.454 en
  julio): la migración de `tienda_vendedor_id` (2026_10_04) llenó las digitales viejas con la
  tienda donde estaba la comisión, y esa ya estaba en la de hoy. Se corrige con la migración
  `2026_11_01_000001_venta_1215_cuenta_para_circunvalar` (solo esa orden; **sin subir** al
  2026-10-24). Desde julio era la única digital, de cualquier tienda, con la tienda del
  vendedor distinta de la de la orden.
  **Norte agosto** (2026-10-23): la fuente es la pestaña NORTE de `Downloads/08 DE AGOSTO 2026.xlsx`
  (reporte de agosto de todas las tiendas, ya validado contra Alegra/caja). Todas las órdenes
  están en el sistema menos la restauración de Alba Lucía Ovalle ($6 M, "no está en Sodege").
  Sistema: $684.368 por persona (Paola, Martha, NN = empresa) vs Excel $682.683 (Martha $650.120:
  el Excel le resta un 2,5 % por un "reemplazo de Jessica" que no está registrado). Diferencias:
  R-1101 cuenta como venta porque un cojín de $0 no está marcado como restauración; tarjeta en
  Aura #4301 y Omar #4310 (Excel) vs efectivo (sistema); Janeth #4309 con tarjeta solo en el
  sistema. **Norte ago+sep con el Excel de la tienda** (`Downloads/RELACION VENTAS DECASA NORTE
  2026 (1).xlsx`, hojas AGOSTO 2026 y SEPTIEMBRE 2026, hechas el 21 oct; la hoja "CORRECCION
  SEPTIEMBRE" es de 2025): todas las órdenes están (sep cuadra exacto $107,516 M). La comisión
  NO cuadra por **reglas que decide el dueño** (no tocar sin que lo diga): (1) ventas de **Jessica
  Rincón** (ago #4275, #4276, #4306, FV2-1 = $17,78 M; sep FV2-14) ya se le pagaron aparte y la
  tienda las saca; en el sistema suman al pool de Norte (las de agosto a nombre de Paola);
  (2) FV2 de **Margarita** en agosto (FV2-7/12/13) la tienda las saca; (3) Martha en agosto
  cobra menos en el Excel ("ingresó 4 agosto") y Paola se lleva sola la venta de Olga #4277
  ("trabajó sola"); (4) la tienda divide las **restauraciones** por 1,19 y el sistema no; (5) en
  septiembre la tienda no resta la tarjeta. Sistema: ago $684.368 c/u, sep $919.713 c/u; tienda:
  ago Paola/NN $503.946 y Martha $245.963, sep $876.414 c/u. Nada de Norte ago/sep pagado aún.
  **Decisión del dueño (2026-10-23):** las ventas de Jessica (ya pagadas aparte) y las FV2 de
  Margarita **no suman al pool de Norte**. Se hizo la marca por orden "fuera del pool" (no
  suma a meta ni pool y no paga comisión; ver `docs/PROYECTO.md`), **sin subir**, con
  **migración** `2026_10_31_000001_ordenes_fuera_del_pool` (solo agrega). Subida a `main` el 2026-10-23 (ver git log). Falta que
  un supervisor marque #4275, #4306, FV2-1, FV2-14 (Jessica: las que se le pagaron) y FV2-7,
  FV2-12, FV2-13 (Margarita). **#4276 (César Martínez) es de Paola: NO se marca** (dueño,
  2026-10-23). Más decisiones de ese día: **Martha no estuvo del 1 al 4 de agosto** (27 días;
  se registra como reemplazo de Paola a Martha del 1 al 4, así Paola no pasa de 31 y solo baja
  Martha); **Helena Grosso #4293 vale $4.640.000** (los $2.190.000 del Excel son lo que le falta
  pagar); Aura #4307 ya está pagada. Con eso agosto da Paola/NN $306.817 y Martha $271.097
  (pool $794.731 + R-1094 $30.000 c/u). La hoja de la tienda ($503.946 Paola/NN) no se puede
  reproducir: lo más probable es que les dejaron las ventas de Jessica dentro del pool.
  Débito sí paga 5,5 %; restauraciones sin IVA y con el 50 % (ver §2 Restauraciones).
  **Norte ago y sep CERRADOS (2026-10-23)**, confirmado en Listas: ago Paola/NN $306.816,
  Martha $271.098; sep Martha/Manuela/NN $892.262; Jessica $0. Las 7 órdenes quedaron marcadas
  "fuera del pool" y el reemplazo "Paola cubre a Martha 1–4 ago" registrado (en realidad la
  cubrió Jessica, de vacaciones; entonces la reemplazo cobraba el 5 % de sus ventas, ya pagado:
  registrar a Jessica le habría dado ~$34.000 más del pool). Desde septiembre, los reemplazos
  se registran con quien de verdad cubrió. Quedan sueltos (no cambian la comisión): pagos que
  faltan en sep (#4348 $3,28 M, #4361 $1,4 M, #4343 $900.000), restauración de Alba Lucía
  Ovalle sin subir, R-1101 con un cojín de $0 sin marcar como restauración.
  Archivo: `Downloads/Conciliacion Norte ago-sep 2026.xlsx`. Script reutilizable:
  `conciliar.php` + un archivo de filas por tienda (en el scratchpad de la sesión; se pierde con ella).
  Archivo: `Downloads/Conciliacion ordenes Pereira ago-sep 2026.xlsx`.
  Leer producción solo lectura con `.env.lectura-produccion` (raíz del repo principal), con
  permiso del usuario.
- **Ayuda de Comisiones** (2026-10-15, pedido del dueño: "todo, absolutamente todo, bien
  explicado"): `/comisiones/ayuda`, sin migración. Cada regla se verificó contra el
  código (`enriquecer`, `libroDelPool`, `cobraIndividual`, `ComisionIndependientes`), no
  contra `explicacion_modulo_comisiones.txt`, que quedó viejo en partes (dice que el pool
  se parte "en partes iguales" y se prorratea por las ventas de cada uno; hoy es por días
  y a nivel tienda con el 50 % pagado). Si cambia una regla, cambiar la ayuda.
- **Gestión → Documentos** (2026-10-15, **sin subir**, trae **migración**
  `2026_10_22_000001_crear_plantillas_documento`, solo crea tabla). Pedido del dueño:
  que Gestión sea un lugar administrativo con cosas editables; empezó por el texto del
  anexo de garantías y la orden de entrega del conductor. Decisiones: cada guardado es
  una **versión nueva** (nunca se pisa: hay anexos firmados con cada texto) y cada anexo
  se lee, firma e imprime con **su** versión; en la orden de entrega solo se edita la
  redacción, los datos salen de la orden. Detalle en `docs/PROYECTO.md` §6.19. Trampa: los
  plazos de `Garantia::MESES` no salen del texto del anexo (si el dueño cambia un plazo
  en el texto, hay que cambiarlo también ahí; está en la lista de candidatos). Trampa de
  pruebas: `AnexoGarantiaController::pdf` hace `set_time_limit(120)` y en la suite
  completa (un proceso) mataba las pruebas siguientes: después de pedir ese PDF en una
  prueba, `set_time_limit(0)`. Trampa Blade: `@php(...)` en línea antes de un bloque
  `@php … @endphp` rompe el bloque ("Undefined variable"): usar bloque.
  El mismo día el dueño escogió los 5 recomendados y quedaron hechos (mismo worktree,
  **sin subir**, sin migración nueva): datos de la empresa (quita los "SODEGE" que veía
  el cliente), plazos de garantía (`Garantia::meses()`), condiciones y días de la
  cotización, acta de satisfacción (por fecha de entrega) y mensajes de WhatsApp. Detalle
  en `docs/PROYECTO.md` §6.19. Pendientes de la lista (no pedidos aún): leyenda de firma de
  la orden, correos completos, textos de la página pública de firma, días del enlace (7).
  Trampa de entorno: la herramienta Bash de este equipo se come las barras invertidas
  (`\App\...` queda `AppSupport...`) en sed/perl/heredocs; para código con `\` usar Edit
  o Write.
- **Reserva de Fábrica con la vista de Inventario** (2026-10-16, commit 42b4b69 subido a su
  rama, **no a `main`**, rama
  `claude/reserva-vista-inventario-209f05`, sin migración). Pedido del usuario: que la
  Reserva acomode por stock de más a menos y colapse las variantes como Inventario. Antes
  "Todas" iba por nombre (los de 0 revueltos). El orden/filtro salió de
  `InventarioController` a `App\Support\OrdenExistencias` (los dos lo usan; cambiar uno
  cambia el otro). Tests en `ReservaOrdenStockTest`.
- **Apagar las puntuaciones del taller** (2026-10-16, **subido a `main`** ese día, rama
  `claude/sharp-mcclintock-2308ce`, sin migración). Pedido del dueño: "que se pueda apagar
  lo de las puntuaciones de los trabajadores cuando de producción pasan de un paso a otro",
  desde Gestión. Se interpretó como el formulario de "Terminar paso": tiempo, estrellas y
  opinión de cada trabajador (lo que arma la "puntuación" en Usuarios y el orden de quién
  conviene poner). Apagado se sigue pidiendo **quién lo hizo** (es el registro del paso y
  el backend lo exige). Si el dueño quería quitar también eso, es otro cambio. Nueva
  pestaña Gestión → Ajustes (`Support/AjustesSistema`), pensada para más interruptores.
  Verificado que no toca plata: Nómina no lee `paso_trabajadores`. Tests en
  `AjustesCalificarTallerTest`.
- **Visor único de fotos con Descargar y Compartir** (2026-10-17, **subido a `main`** ese día, rama
  `claude/image-download-share-buttons-481f08`, sin migración, solo front). Queja del
  usuario: las fotos se bajaban "en un formato raro" (es el WebP/AVIF que entrega
  Cloudinary por el `f_auto` de `cloudinaryOpt`). Ahora toda foto en grande va por
  `useVisorFoto().abrirFoto(...)` (`components/common/VisorFoto.vue`, montado en App.vue):
  descarga el original en **JPG** (`f_jpg`, o se convierte en el navegador) con nombre
  legible y comparte el archivo con la hoja del celular (`utils/fotos.js`). App.vue además
  intercepta los `<a href="…foto" target="_blank">` (PDF no); `data-sin-visor` lo evita.
  Se quitaron los visores propios de Inventario, Nueva orden, Reserva, Telas, Despacho,
  Orden, Consultas, Costos, soportes de cambios, chat de IA, catálogo público y firma del
  anexo. **Para una foto nueva en grande, usar el visor; no hacer otro lightbox.**
- **Retomar la firma remota en Nueva orden** (2026-10-19, **sin subir**, rama
  `claude/nueva-orden-data-persistence-d8771d`, sin migración). Queja: si la página se
  recargaba o el vendedor salía, Nueva orden volvía a ofrecer "Enviar al cliente" en vez de
  "Esperando firma"; reenviar anulaba el enlace que el cliente ya tenía. La espera vivía
  solo en la pantalla/respaldo del teléfono. Ahora `GET /anexos/en-curso?cliente_id=`
  (remoto, de ese vendedor, sin orden, pendiente vigente o firmado ≤ 3 días) y la pantalla
  lo retoma al escoger el cliente; al recuperar el respaldo pregunta el estado ya; el aviso
  "el cliente firmó" sin orden abre Nueva orden.
- **Nueva orden a medio hacer se sigue en otro aparato** (2026-10-22, **subido a `main`** ese día, rama
  `claude/cross-device-order-sync-2ede47`, trae **migración**
  `2026_10_29_000001_crear_borradores_en_curso`, solo crea tabla). Queja del usuario: se
  recargaba en el celular, en el computador "me da la opción de seguir pero no se guarda bien".
  Causa: el respaldo vivía solo en el IndexedDB de cada aparato; el otro ofrecía **su** copia
  vieja, al seguir un borrador del servidor la copia vieja pisaba lo nuevo, y al crear la orden
  en uno el otro la seguía ofreciendo (se podía crear dos veces). Ahora `useBorradorLocal` con
  `nube: true` guarda también en la cuenta (`BorradorEnCursoController`, una fila por persona
  y clave, `revision` para no pisar: 409 = otro aparato siguió o terminó → aviso "¿con cuál
  sigues?"). Fotos a Cloudinary carpeta `borradores` (quedan ahí; no se limpian). Al crear la
  orden o "Empezar de cero" se borra en todos lados. Consecuencia: **una sola orden a medio
  hacer por persona** entre todos sus aparatos (antes una por aparato). Tests en `BorradorEnCursoTest`.
- **Anexo: "yo ya marqué todo"** (2026-10-22, subido a `main` ese día, solo front). La última
  sección se abre sola al marcar la penúltima; el cliente la leía y bajaba a firmar sin tocar
  "Leí esta sección", y la firma apagada no decía nada. Ahora `AnexoFirma` lleva a lo primero
  que falta (producto, sección, pregunta, datos) al tocar la firma o el botón, lo nombra y lo
  marca en ámbar. No cambió qué se exige para firmar.
- **Apagar notificaciones por persona** (2026-10-22, **subido a `main`** ese día, trae **migración**
  `2026_10_30_000001_notificaciones_apagadas_en_usuarios`, solo agrega columna JSON). Pedido
  del dueño: "hay supervisores que no necesitan las de cambios de dinero, ni productos
  atrasados ni las de próximos de entrega pero sí las de nuevas ventas, chats". Se apaga por
  **grupo** (22 grupos que juntan los ~60 tipos) en Trabajadores → ficha → Notificaciones.
  Catálogo en `App\Support\TiposNotificacion`; el filtro está en `NotificacionService::crear`
  (lo apagado no se guarda ni sale al celular; lo que ya tiene no se borra). **Un tipo de aviso
  nuevo hay que meterlo en un grupo** (`NotificacionesApagablesTest` falla si no). Los dos
  interruptores viejos (`notif_asignar_fecha`, `notif_stock`) quedaron como grupos del panel
  pero siguen en su columna y se revisan donde se manda el aviso; se quitaron del formulario
  Editar. Decisión que tomé por defecto (**falta que el dueño confirme**): todo se puede apagar,
  también lo que pide una acción (solicitudes, consultas de precio, traslados, rutas); la
  pantalla advierte en ámbar. Lo cambia solo un supervisor.
- **Declaración de diseños y medidas especiales** (2026-10-21, **subido a `main`** ese día, rama
  `claude/special-warranty-client-signature-ed7f21`, trae **migración**
  `2026_10_26_000001_declaracion_disenos_especiales`, solo agrega). Pedido del dueño: el papel
  "suministré las medidas… los exonero" que se firma en lo que no está en el catálogo, en el
  sistema, con ficha + bocetos, que el cliente lo vea y firme, PDF o imagen, editable en Gestión.
  Decisiones del dueño: **seguro en diseños especiales; en personalizados "que sea activable"**
  (Gestión → Ajustes, apagado). Decisiones que tomé por defecto (**falta que el dueño confirme**):
  una sola firma con el anexo pero dos documentos (igual que orden+anexo, 2026-10-09); se exige
  donde se exige el anexo, y en la tienda vale la foto del papel; el cliente no edita: "pide un
  cambio" y el asesor corrige y reenvía; los bocetos no entran a la huella (quedan congelados en
  el anexo). Al subirlo, las órdenes presenciales con diseño especial empiezan a pedir la
  declaración. Detalle en `docs/PROYECTO.md` §6.16. Trampa vista: la clase CSS `input` solo existe
  en el `<style>` de NuevaOrdenView; en la página pública del cliente las cajas salían sin borde
  (los componentes nuevos y AnexoFirma usan clases explícitas).
  `MisEstadisticasVendedorTest::test_se_compara_con_el_periodo_anterior` falla también en el repo
  principal (depende de la fecha): no es de este trabajo.
- **Vender la medida sin la tela** (2026-10-20, **sin subir**, rama
  `claude/orden-creation-422-error-1b173c`). Trae **migración**
  `2026_10_25_000001_tela_por_definir_en_orden_items` (solo agrega `orden_items.tela_por_definir`).
  Queja del usuario: Nueva orden daba 422 "Stock insuficiente para variante ID 36" con una
  cama de 1.40 que sí tenía stock por medida; el selector obligaba a escoger tela × medida y,
  sin combinaciones registradas, todo salía "Sin stock" ("muchas veces se sabe el tamaño de la
  cama pero no la tela"). Hecho y aprobado por el usuario: la medida se revisa aparte
  (`Support/StockMedida`), fila "1.40 · tela por definir" en el selector (`?para_venta=1`; sin
  el parámetro Inventario ve lo de siempre), botón "Definir tela" en la orden
  (`POST /ordenes/{id}/items/{itemId}/definir-tela`) y **no se despacha sin la tela**
  (`EntregaService::entregablesDe`). Mensajes de stock con nombre, medida y tienda (nada de IDs).
  Trampas: (1) las ventas **nunca apartaban ni descontaban la medida**; al entregar,
  `StockVariantes::cuadrar` recortaba la del montón más grande (se vendía la 1.60 y bajaba la
  1.40). Ahora la entrega baja la medida vendida. (2) Lo apartado de la medida **no lleva
  contador**: se cuenta de las órdenes abiertas (así las órdenes viejas cuentan bien); no
  agregarle un `increment` a `inventario_variante_configs.cantidad_reservada`. (3) Si ninguna
  medida del producto tiene stock anotado en esa tienda, no se frena por medida (manda el total).
  `completarBorrador` (camino viejo) sigue sin revisar stock, como antes. Tests en
  `VentaPorMedidaSinTelaTest`. Falla aparte: `MisEstadisticasVendedorTest::test_se_compara_con_el_periodo_anterior`
  falla solo el día 20 de cada mes (sus dos fechas caen el mismo día); no es de este cambio.
- **Cambio de producto entregado, con reemplazo** (2026-10-21, **subido a `main`** ese día, rama
  `claude/order-detail-return-function-8db111`, trae **migración**
  `2026_10_27_000001_recogida_de_devoluciones`, solo agrega columnas). Pedido del usuario: un
  respaldo de la devolución del conductor para cuando el cliente ya recibió y "no le
  sirvió" (no es garantía), cambiándolo por catálogo (stock o fabricar) o diseño especial;
  si vale más, la venta sube. Se amplió `cambiarProducto` (`Services/CambioDeProducto`,
  `CambioProductoModal`); sin reemplazo hace lo mismo de antes. Decisiones que tomé por
  defecto (**falta que el dueño confirme**): solo supervisor (como antes: mueve plata);
  el precio del nuevo lo pone él (por defecto el de lista); si vale menos, queda **a
  favor** (no se reembolsa aquí). Mismo día, pedido del usuario: escoger qué pasa con
  lo devuelto (a qué tienda, a la fábrica/bodega o merma) y **programar la recogida**
  "como opción porque puede que sea de una": la orden cambia ya, el inventario espera a
  "Ya llegó" (Despacho → Por recoger). Recoger es manual, no parada de ruta (igual que
  en garantías). Detalle en
  `docs/PROYECTO.md` §6.9c. De paso: el modal "Definir tela" (6b92f09) estaba dentro del
  `<Transition>` del de daño y en desarrollo Detalle de la orden no compilaba (en
  producción sí); ahora cada uno tiene el suyo. Tests en `CambioProductoConReemplazoTest`.
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
- **Recontado 2026-10-08: 734 tests, 10 fallos preexistentes** (las mismas 6 clases).
- **Recontado 2026-10-09 (rama de garantías): 767 tests, 10 fallos preexistentes** (las mismas 6 clases).
- **Recontado 2026-10-10 (finanzas + prestaciones): 817 tests, 10 fallos preexistentes** (las mismas 6 clases).
- **2026-10-16: 871 tests, 0 fallos** (rama `claude/dual-profiles-persistence-61ee57`). Se
  arreglaron los 10: `APP_KEY` de prueba en `phpunit.xml`; `assertJsonPath(x, 6.0)` → `6`
  (PHP manda 6.0 como 6 en el JSON); esquemas a mano con columna duplicada o filas de
  `insert` con llaves distintas; AnularOrden con el grupo vacío (caso irreal). Y un **bug
  real de comisiones** en `TiendaReemplazo::pesosDelMes`: a quien llegaba trasladado y
  luego se iba a otra tienda el mismo mes se le restaban los días de la otra tienda (y
  dependía del orden de lectura); ahora solo se resta al equipo fijo. Desde aquí, **un
  fallo es nuevo**: ya no hay "preexistentes".
- Trampa MySQL vs SQLite (2026-10-10, 500 en `/finanzas/resumen`): `GROUP BY alias` en
  MySQL busca **primero una columna de las tablas** con ese nombre; SQLite usa el alias.
  `->selectRaw('… AS mes')->groupBy('mes')` con una tabla que tiene columna `mes`
  (`comision_anticipos`, `presupuestos_gasto`, `metas_tienda`…) revienta en producción
  (ONLY_FULL_GROUP_BY) y pasa en las pruebas. Agrupar por la expresión (`groupByRaw`).
- Rendimiento de Finanzas (2026-10-10): lo recordado por petición va con
  `Periodo::recordar` / `porMesRecordado`. Trampa: guardar sobre lo que haya al
  terminar de cargar, no sobre una copia de antes (lo que se carga recuerda cosas
  por dentro y se perdían). `Esquema` guarda en la caché solo los "sí existe"
  (llave con la versión de las migraciones). Resumen: 55 → ~26 consultas.
  2026-10-13 (con nómina sembrada, ~1.200 pagos sin costo congelado): `CostoEmpleador`
  recuerda los conceptos por trabajador + porcentajes vigentes + excepciones (dePago
  2,4 s → 0,4 s), `Periodo::repartir` sin Carbon (fuzz de 20.000 rangos idéntico; Carbon
  3 da días float: dividir por float), nómina en una sola lectura, `Configuracion::valor`
  trae las 3 claves de Finanzas en un viaje, el resumen trae la serie de 12 meses
  (`?serie=1`) y el front pinta lo último visto mientras recarga (`utils/memoriaFinanzas`,
  solo memoria; Gastos y "Próximos pagos" nunca con datos viejos: tienen botón Pagar).
  Queda ~45 consultas × 200 ms: **lo que falta es terminar la mudanza a Virginia**
  (vercel.json sigue apuntando a `decasa-api-b91v`, Oregón).
- Trampa SQLite: Eloquent guarda las fechas como `Y-m-d H:i:s`; comparar columnas de fecha con `whereDate`, no con `where(fecha, "<=", "Y-m-d")`.
- **Trampa (2026-10-10): Finanzas daba 502 en producción** recién subido. Los pagos de nómina de
  antes de la migración no traen el costo congelado y `CostoEmpleador::dePago` buscaba trabajador y
  excepciones pago por pago: ~5.000 consultas en /finanzas/resumen. Con la base de `medir.sh` no se
  veía porque **no tiene nómina**: para medir Finanzas, sembrar `nomina_sueldos`, trabajadores con
  `nomina_sueldo_id` y ~1.000 `nomina_pagos` sin `costo_empleador_detalle`. Arreglo: `with(
  CostoEmpleador::relacionesDePago())` en toda lista de pagos que llame `dePago`, y cada fuente "por
  mes" se carga una vez por petición (`Periodo::porMesRecordado` / `recordar`, `App\Support\Esquema`).
  Prueba diferencial: respuestas idénticas byte a byte.
- Trampa (2026-10-10): `php -r` con `preg_replace` desde Git Bash rompe los `$` y, si el
  patrón falla, `file_put_contents` escribe vacío: así se vació `UsuarioController.php`
  (se restauró con `git checkout`). Para cambiar código, usar la herramienta Edit.
- Herramientas instaladas el 2026-10-08 (skills, graphify, claude-security): ver `AGENT.md` §9.
  Los agentes de chat tienen su propio `AGENT.md` en `Desktop/Agentes/`.
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

## 5b. Agentes de chat (repo aparte `C:\Users\LENOVO\Desktop\Agentes`)

Revisado 2026-10-07. `Agente-ws` (WhatsApp/Twilio) y `Agente-ig` (Instagram/Meta),
Node + OpenAI, cada uno con su repo en GitHub (DecasaSystem/Agente-ws, Agente-ig);
`core/` se copia a los dos con `npm run sync`. Se conectan con este sistema por
**dos vías**:
1. **HTTP:** `POST /api/redes/webhook` con `X-Agent-Token` (= `AGENT_TOKEN` aquí,
   `DECASA_AGENT_TOKEN` allá) → tarjeta en `conversaciones_wa` + aviso `redes`.
2. **La misma BD de Aiven:** leen `productos`, `inventario`, `tiendas`,
   variantes, `configuracion` y `conversaciones_wa`; escriben sus propias tablas
   (`clientes_wa`, `estado_usuario`, `pedidos`, `citas_agentes`, `wa_*`, `ig_*`,
   creadas por ellos, no por migraciones). `RedesController::silenciarBot`
   escribe en `estado_usuario` del bot al Tomar/Terminar.
**Renombrar columnas de esas tablas rompe a los bots sin aviso** (no hay tests
cruzados). El contrato está en [`docs/contrato-agentes.md`](docs/contrato-agentes.md)
y en `core/contrato-bd.js` allá (los bots lo verifican al arrancar y alertan).
Plan y bitácora: `Desktop/Agentes/PLAN-INTEGRACION-SISTEMA-VENTAS.md`. Fase 1 hecha
el 2026-10-07, **sin subir**: catálogos desde `herramientas`, `idempotencia` por
aviso, cancelación de cita, métricas en hora de Bogotá. Fase 2 hecha el
2026-10-08, **sin subir** y con 2 migraciones (`citas.cita_agente_id`,
`conversaciones_wa.archivada_at`). El dueño delegó las decisiones al criterio "el
sistema es dueño de la plata; los bots se adaptan; donde hay plata decide una
persona": archivar en vez de borrar; citas cruzadas por id; el pedido del bot solo
**pre-llena** Nueva orden (nunca crea órdenes solo); el bot no cotiza combinaciones
de dos o más tipos de variante con precio (las pasa a un asesor); stock por variante
no se construye hasta que el bot lo necesite.

Decisiones del dueño del 2026-10-08:
- **No abren en festivos** (Colombia). Los agentes los calculan solos (`fechas.js`) y no
  agendan citas esos días.
- **El agente de WhatsApp puede consultar los pedidos del cliente por su número**
  (`GET /api/agentes/pedidos`), "sin dar información de más": nada de montos ni datos
  internos, y solo el número verificado desde el que escribe.
- **"Eléctrica" no es una categoría**: es una opción de variante (silla reclinable Manual
  con palanca / Eléctrica con botón) que cambia el precio, como las medidas de las camas.
- Circunvalar cerró el 2026-08-27; los agentes ya no la ofrecen.
- Trampa: Nueva orden cobra la suma de `precio_adicional` sin mirar `afecta_precio` (la
  marca solo esconde el campo en Inventario).
- Trampa (2026-10-08): en `wa_seguimientos`/`ig_seguimientos` el índice único incluye
  `referencia`, y en MySQL dos NULL no chocan: con `referencia = NULL` cada llamada creaba
  otra fila (un cliente con 3 productos recibía 3 recordatorios). Los seguimientos de "uno
  por cliente" usan referencia fija (`carrito`, `interes`). Y nunca programar a 24 h exactas:
  la ventana de WhatsApp/IG ya cerró y se descarta (se usan 20 h).
- Lección de la auditoría: antes de tocar un bot, contrastar **cada dato que le dice al
  cliente** con su dueño en el sistema (catálogos, sedes, categorías, precios, juegos).

## 6. Trampas conocidas

- **Orden trabada en `devuelto` (#1231, 2026-10-21, subido a `main` ese día)**. En la entrega los 2
  tomacorrientes volvieron en el camión; la orden quedó en `devuelto` (a propósito no se
  sale a mano: solo decidiendo la devolución en Producción). Pero ninguna salida servía:
  "Otra igual" pedía OTRAS unidades libres y las únicas eran las apartadas para la orden;
  "Otro producto" la dejaba en `pendiente_anticipo` con todo entregado y "Marcar entregado"
  decía "No queda nada por entregar" (trabada para siempre). Arreglo: decisiones nuevas
  `reentregar` (estaba bien, se lleva otra vez, no mueve inventario: lo que vuelve en el
  camión nunca salió y sigue apartado) y `quitar` (el cliente se queda sin eso; al
  inventario soltando el apartado, o merma; lo pagado de más queda a favor); y "entregado"
  cierra una orden que ya tiene todo en la casa. Lo que vuelve en el camión ≠ lo que el
  cliente ya tenía: mirar `pendienteEntregar()` antes de mover inventario.
  **Causa de fondo (hallada después de subir):** `GET /devoluciones` pedía
  `orden:id,referencia,…` y `referencia` NO es columna (es accesor): en MySQL daba 500,
  la pantalla lo tragaba y la lista salía vacía en la orden y en Producción. Nadie podía
  ver ni decidir la devolución. Nunca poner `referencia` en un select/`with(':cols')`;
  test `test_la_lista_no_pide_columnas_que_no_existen` revisa el SQL (SQLite no falla).

- **"Sin conexión" al crear la orden que era una foto borrada** (2026-10-21, consola:
  `/api/upload/foto net::ERR_FILE_NOT_FOUND` + `AxiosError: Network Error`, repetido en
  cada reintento). No es la red ni el servidor: el `File` del selector apunta al archivo
  del teléfono y se lee al subir; si Android lo borró (temporales de la cámara), se movió
  o era de la nube, la subida falla siempre. Solo pasaba con fotos de **menos de 1 MB**
  (capturas de transferencias, WhatsApp): las grandes se comprimían y quedaban en memoria.
  Arreglo (**subido a `main`** el 2026-10-21, commit a08967a): `comprimirAlTomar` copia a
  memoria también las chicas, y Nueva orden revisa cada foto antes de subirla
  (`fotoSigueAhi`), quita la perdida y lo dice claro. Para fotos nuevas: tomarlas siempre
  con `comprimirAlTomar`.

- **Enlace de firma con la dirección de Render** (2026-10-21, queja: "el enlace no abre
  nada"). `AnexoGarantiaController::enlace` usaba `FRONTEND_URL` (no está en Render) o el
  `Origin`, y si no, `app.url` (la API). Los navegadores solo mandan `Origin` en POST: al
  preguntar el estado por GET (cada 5 s, y al retomar la espera) el enlace bueno se cambiaba
  por `…onrender.com/firmar/…`. Ahora `baseApp()`: FRONTEND_URL → Origin → Referer → la app en
  Vercel, nunca el host de la API; y `routes/web.php` redirige `/firmar/{token}` de Render a la
  app (los enlaces ya mandados abren). Para links al cliente, usar `baseApp()`.

- **Recalcular comisiones daba 502** (2026-10-19, **sin subir**, sin migración). Pone al día
  todas las comisiones sin pagar orden por orden; con ~300 renglones abiertos y ~200 ms por
  consulta son minutos y el proxy cortaba la petición (la bitácora no tenía ni un
  "recalcular" que hubiera terminado). La tarea de las 6:30 sí funcionaba (sin límite). Ahora
  el botón deja el pedido en caché (`ComisionController::CLAVE_RECALCULO`), la tarea
  `comisiones:recalcular-pedido` (cada minuto, en segundo plano) lo corre y la pantalla
  pregunta en `GET /comisiones/recalcular/estado`. No se metió a la cola: con un solo
  trabajador habría frenado los push varios minutos. Algo pesado en una petición = lo mismo.

- **Recarga en bucle de una pantalla** (2026-10-17, queja: "en otro PC Producción se
  queda cargando en bucle rápido"). `router.onError` recargaba la página cada vez que una
  pantalla no cargaba su archivo, sin límite: si fallaba siempre (navegador viejo, la
  compilación de Vite 8 + Tailwind 4 pide Chrome/Edge ≥ 111; Windows 7 se queda en
  Chrome 109), recargaba sin fin. Ahora (subido a `main` el 2026-10-17) recarga una vez por pantalla por minuto y luego
  avisa. Si vuelve a pasar, preguntar navegador y versión de ese PC.

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
