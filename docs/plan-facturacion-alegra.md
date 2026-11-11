# Facturación electrónica con Alegra

Estado: **implementado el 2026-11-09, sin subir** (rama `claude/alegra-billing-integration-a51e78`).
Trae **migración** `2026_11_10_000001_facturacion_electronica` (solo crea `facturas_electronicas` y
agrega columnas opcionales a `clientes`). Viene **apagado**: hasta que un supervisor lo prenda y se
pongan las credenciales, el sistema funciona como antes.

---

## 1. Lo que pidió el dueño (2026-11-09)

- Se factura **al final, cuando la orden se entrega**.
- **Las FV2 no se facturan.**
- **Antes de facturar, el facturador revisa**: la orden entregada le aparece en una lista, entra, ve
  toda la información y las pruebas, y toca **Facturar**; en ese momento sale en Alegra.
- **Todo personalizable desde el programa** ("puede que algún día cambien la manera de facturar"):
  si las FV2, las restauraciones o las de independientes se facturan; si se factura al entregar, al
  principio o por cada pago; si hace falta la revisión.

## 2. Cómo funciona

```
Orden entregada ──► Facturación electrónica → "Por facturar" (QueSeFactura, según Ajustes)
                      │
                      ▼
               Revisión (FacturaOrdenView ← ArmarFactura)
               cliente fiscal · líneas sin IVA + IVA · pagos con comprobantes · fotos de entrega
               · cambios de plata · alertas (bloqueos / advertencias a marcar)
                      │  [✓ Revisé]  [Facturar]
                      ▼
               EmitirFactura: toma la fila (lock) → contacto en Alegra (busca por cédula o lo crea)
               → POST /invoices (+ stamp si "Mandar a la DIAN") → número, CUFE, estado DIAN, PDF
               → marca los pagos como facturados (el flujo viejo de Facturación queda al día)
```

| Pieza | Archivo |
|---|---|
| Reglas (Ajustes) | `app/Support/ReglasFacturacion.php` (clave `facturacion_electronica` en `configuracion`) |
| Qué está por facturar | `app/Services/Facturacion/QueSeFactura.php` |
| Armar y revisar la factura | `app/Services/Facturacion/ArmarFactura.php` |
| Mandarla a Alegra | `app/Services/Facturacion/EmitirFactura.php` |
| HTTP con Alegra | `app/Services/Alegra/ClienteAlegra.php` |
| API | `FacturacionElectronicaController`, rutas `/facturacion-electronica/*` |
| Factura automática | comando `facturacion:emitir-pendientes` (cada 5 min; no hace nada si la revisión está prendida) |
| Pantallas | `FacturacionElectronicaView`, `FacturaOrdenView`, `components/facturacion/FacturacionAjustes.vue` |
| Pruebas | `tests/Feature/FacturacionElectronicaTest.php` (22, con Alegra simulado) |

Quién: facturan el facturador (`usuarios.facturacion`) y los supervisores; **solo un supervisor
cambia los Ajustes**.

## 3. Ajustes (todo lo personalizable)

| Ajuste | De fábrica | Qué hace |
|---|---|---|
| Prendida | No | Apagada no sale nada en la lista |
| Modo | **Solo borrador** | Borrador: se crea en Alegra sin ir a la DIAN (para probar). "Mandar a la DIAN": ya es legal |
| Desde | hoy al prenderla | Lo de antes ya se facturó a mano |
| Cuándo | Al entregar | Al entregar · apenas entra el primer pago · una factura por cada pago |
| FV2 / restauraciones / independientes | No / Sí / Sí | Si esas órdenes se facturan |
| Revisión del facturador | Sí | Apagada, lo que no tiene advertencias sale solo |
| Cliente sin cédula | Pedirla | O facturar a "Consumidor final" (contacto escogido de Alegra) |
| IVA, IVA en restauraciones | 19 %, sí | |
| Decimales del precio sin IVA | 2 | Más decimales = total más exacto |
| Registrar pagos en Alegra | Sí | Cada pago entra a la caja/banco escogido |
| Días de crédito | 30 | Si queda saldo, sale a crédito |
| Alerta de precio | 50 % | "Se les fue un cero": precio lejos del de lista |
| Cambio de plata reciente | 24 h | Pide mirar otra vez |
| Correo al cliente | No | Alegra le manda la factura |
| Nota del PDF | "Orden de venta {orden}" | |
| Alegra | — | numeración (general y por tienda), impuesto de IVA, producto genérico de ventas y de restauraciones, caja/banco por medio de pago (general y por tienda), medio de pago DIAN y de Alegra por medio |

## 4. Las cuentas (criterio contable)

- Los precios del sistema **traen el IVA**: a Alegra va el precio **sin IVA** (`precio ÷ 1,19`) y el
  impuesto aparte.
- Los descuentos (manual y condicionado) se **reparten en proporción** entre los productos, para que
  la factura sume exactamente lo que el cliente paga (`valor_total`). Si por decimales hay más de $1
  de diferencia, se avisa.
- Lo devuelto (`devuelto_en`) no va; lo que vale $0 (regalos) tampoco, y se avisa.
- Restauración = servicio (ítem de servicio de Alegra); FV2 "sin descontar IVA" = sin IVA.
- Contado si ya pagó todo; crédito si queda saldo.
- Cliente: CC/NIT/CE/PP…, el **dígito de verificación del NIT se calcula solo** (algoritmo DIAN), el
  nombre se parte en nombres/apellidos (se ve en la revisión), dirección/municipio/departamento
  (los pide la DIAN; si faltan en el cliente, se toman de la orden).

## 5. Revisión: bloqueos y advertencias

**Bloquean** (no se puede facturar): faltan datos del cliente, Alegra sin conectar o sin producto/IVA
escogido, un pago sin caja de Alegra, solicitud de cambio sin decidir, valor $0, la regla dice que
no se factura.

**Numeración (resolución DIAN)** (2026-11-11, `Services/Alegra/EstadoNumeracion`): en cada revisión
se pregunta a Alegra por la numeración de la tienda (`endDate`, `maxInvoiceNumber`,
`nextInvoiceNumber`, `status`; si faltan, se leen del texto de la resolución; se recuerda 10 min).
Vencida, agotada o inactiva **bloquea** en modo "Mandar a la DIAN" (en borrador solo avisa); por
vencer (30 días) o con pocos números (100) avisa sin frenar; los dos umbrales están en Ajustes. En
Ajustes cada numeración sale con su fecha, los números que le quedan y su estado, y una vencida no
se deja escoger. Caso real ese día: FEU vencida el 7 nov 2026, FP PEREIRA vencida en feb 2026, FEN
con 83 números y vigente hasta el 20 feb 2027. En Ajustes por tienda solo salen las tiendas
activas y sin cerrar.

**Hay que marcarlas** (se guardan en la factura como aceptadas): precio lejos del de lista, la plata
cambió hace poco, queda saldo (sale a crédito), pagos marcados facturados a mano, reembolso o pagos
de más (los pagos no se registran), diferencia por decimales, consumidor final.

## 5c. Revisión a fondo del 2026-11-11 (arreglado)

Pedido del usuario: "analiza bien a fondo como un experto en Alegra y un contador" (empezó por las
tiendas desactivadas que salían en Ajustes). Trae **migración** `2026_11_12_000001_pagos_alegra_en_facturas_electronicas`
(solo agrega `facturas_electronicas.pagos_alegra`).

| # | Qué pasaba | Arreglo |
|---|---|---|
| 1 | Cambiar "cuándo se factura" (por pago → al entregar) volvía a facturar la orden completa | `QueSeFactura::motivoParaNo` mira las facturas de la otra forma: una orden con facturas por pago no se factura completa, y un pago de una orden facturada completa no se factura aparte |
| 2 | Una re-entrega por garantía revivía órdenes viejas facturadas a mano | "La última entrega" ignora las de `garantias.despacho_item_id` |
| 3 | La lista traía las 300 últimas entregadas (con las facturadas) y las pendientes viejas desaparecían | Lo facturado se descarta en la consulta; hasta 500 pendientes, lo más viejo primero |
| 4 | Una consulta por orden para la fecha de entrega (lenta con Aiven) | La fecha viene en la misma consulta (`ultima_entrega`) y pagos/ítems precargados |
| 5 | Orden cancelada después de facturar no salía en Diferencias | Se compara contra $0 y avisa "anular con nota crédito" (también en el aviso de cancelación) |
| 6 | Bancos, impuestos y productos inactivos en Alegra salían para escoger; uno desactivado después hacía fallar la factura | Ajustes solo muestra lo activo; la revisión revisa lo escogido contra lo de hoy en Alegra (`CatalogoAlegra`, 10 min) |
| 7 | Los pagos iban dentro de la factura y por centavos de redondeo podían pasar el total (rechazo) | La factura se crea sin pagos; después `POST /payments` uno por uno, recortando al total que dice Alegra. Los que no entran quedan en "Problemas" con botón "Registrar pagos" (uno sin respuesta solo se reintenta si el facturador confirma que no está en Alegra) |
| 8 | Municipio y departamento a mano: Alegra rechaza nombres que no son de su catálogo | Selector oficial (`DireccionColombia`) en la revisión y traducción al nombre exacto de Alegra (`MunicipiosAlegra`, `resources/data/municipios-alegra.json` sacado del catálogo de Alegra: 33 departamentos, 1.123 municipios) |
| 9 | Si el cliente ya existía en Alegra, salía con los datos viejos de allá | Antes de facturar se actualiza el contacto (`PUT /contacts/{id}`) |
| 10 | No se revisaba que el impuesto fuera del mismo % que el IVA de los precios | Bloqueo `iva_distinto` y aviso en Ajustes |
| 11 | El producto genérico podía llevar inventario en Alegra | Aviso en la revisión y en la búsqueda de Ajustes |
| 12 | "DIAN validando" y "sin confirmar" había que actualizarlas a mano | Tarea `facturacion:actualizar-estados` cada 10 min |

Cambios de valores de fábrica: **"Registrar pagos en Alegra" viene apagado** (si hoy registran los
recibos a mano, duplicaría la caja de Alegra) y la **fecha de los pagos** es la de la factura (con
el día real en las notas); los dos los decide el contador en Ajustes.

**Preguntas para el contador** (no se cambian sin él): ¿registran hoy los recibos en Alegra? ¿cómo
llevan los anticipos (anticipo de clientes o pago con su fecha)? ¿las ventas de independientes son
ingresos de Decasa o ingresos para terceros? ¿retenciones de clientes empresa? ¿cuenta puente para
datáfono y Addi?

## 5d. Cómo lleva Decasa los pagos (contador, 2026-11-11)

Respuestas del contador y lo que cambió (trae **migración**
`2026_11_13_000001_retencion_del_cliente_en_facturas_electronicas`, solo agrega una columna):

| Respuesta | Qué hace el sistema |
|---|---|
| Cada pago se digita a mano en Alegra → Pagos recibidos, con su aclaración. Esa es la marca **Tomar → Marcar facturado** del detalle de la orden | La marca quiere decir "recibo digitado en Alegra", no "tiene factura". **Arreglado:** al emitir ya no se marcan los pagos (antes los marcaba todos y un recibo sin digitar desaparecía de la lista del facturador); "por cada pago" ya no esconde los pagos marcados; la revisión avisa qué pagos faltan por digitar como recibo |
| Los pagos se llevan como **anticipo de clientes** y al facturar se cruzan | La API de Alegra no documenta cruzar anticipos en Colombia (solo México), así que el cruce sigue a mano. Después de facturar, la orden muestra **"Para terminar en Alegra"**: cada anticipo a cruzar (monto, medio, fecha, referencia) y la retención. "Registrar pagos en Alegra" queda **apagado** (prenderlo duplicaría la caja) |
| **Retención:** compró $1.000.000, retención $25.000, paga $975.000; se cruza al pagar la factura | En la revisión se anota la retención del cliente: deja de contar como deuda (la factura sale de **contado**), se guarda en la factura (`retencion_cliente`) y sale en "Para terminar en Alegra". Sugiere el 2,5 % de la base; no deja anotar más de lo que falta |
| Datáfono → Bold → Davivienda de Decasa (llega después); Addi parecido | No cambia nada mientras los pagos no se registren desde el sistema. Si algún día se prende, tarjeta y Addi deberían ir a una cuenta puente ("Bold por cobrar", "Addi por cobrar"), no directo a Davivienda |

Sin respuesta todavía: si las ventas de **independientes** son ingresos de Decasa o para terceros.

## 6. Nunca dos facturas de lo mismo

Alegra no tiene llave de idempotencia y una factura emitida no se borra. Por eso:
1. `facturas_electronicas.clave` ("orden:ID" / "pago:ID") es única y la fila se toma con
   `lockForUpdate` antes de llamar a Alegra (doble clic o dos personas = 409).
2. Las observaciones llevan `[DECASA-ORDEN-ID]`. Si Alegra no responde, la factura queda
   **incierta** y el siguiente intento la busca primero allá (por cliente y fecha) y la adopta.
3. Si la DIAN la rechaza, Alegra deja un borrador: el reintento lo borra y lo rehace con los datos
   corregidos (un borrador no gasta numeración DIAN).

## 7. Cuando cambia la plata después de facturar

La factura no cambia sola. La orden sale en **Diferencias** (se compara el valor de hoy con el que
tenía al facturar) y el aviso de "Cambió la plata" lo dice. El facturador hace la nota crédito (si
bajó) o la factura por la diferencia (si subió) **en Alegra** y la marca resuelta con una nota.
Emitir la nota crédito desde el sistema es lo siguiente (Fase 2, §9).

## 8. Para ponerlo a andar

1. En Alegra → Configuración → "API - Integraciones con otros sistemas" → **Crear token** (JWT,
   el recomendado). Permisos mínimos: Facturas (todo), Configuración de facturación > Numeraciones
   (ver), Contactos (ver, crear, editar), Transacciones (ver, crear), Bancos (ver), Ítems (ver),
   Impuestos (ver); Nota de crédito opcional. Se creó así el 2026-11-11 ("Sodege", vence 11 nov 2027:
   **antes de esa fecha hay que crear otro y cambiarlo en Render**, o el botón deja de funcionar).
2. En Render: variable `ALEGRA_TOKEN` con el JWT (empieza por `eyJ`; se manda como `Bearer`). Con el
   token clásico de "Credenciales de acceso" (sin permisos finos) va también `ALEGRA_EMAIL` y se
   entra con Basic. Nunca en el repo. La documentación de Alegra solo describe el clásico: si el JWT
   da 401 en Ajustes, usar el clásico.
3. Subir (trae migración).
4. Un supervisor, en Facturación electrónica → Ajustes: escoge numeración, IVA, producto genérico
   ("Venta de muebles"), cajas/bancos por medio de pago; deja **Solo borrador** y prende.
5. Unas semanas comparando los borradores con lo que se hace a mano.
6. Cambiar a **Mandar a la DIAN**.

## 9. Pendiente / por confirmar

- **Con una cuenta real de Alegra** (no hay aquí): el nombre exacto de los campos del CUFE y del PDF
  en la respuesta (se leen con cuidado: `stamp.cufe`, `stamp.legalStatus`, `pdf`), y el formato del
  400 con la factura en borrador. Las pruebas usan lo documentado.
- **Fase 2**: nota crédito desde el sistema (reembolsos, cambio de producto, valor que bajó),
  webhooks de Alegra.
- **Con el contador**: el modo "por cada pago" (factura de anticipo) y el trato de Addi.
- Por defecto mío (**falta que el dueño confirme**): ventas de independientes y restauraciones sí se
  facturan; sin cédula se pide la cédula; los pagos se registran en Alegra.

Fuentes: [developer.alegra.com](https://developer.alegra.com/) (autenticación Basic, límite 150/min,
`POST /invoices` con `stamp.generateStamp`, catálogo de Colombia).
