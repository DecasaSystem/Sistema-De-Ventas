# Plan — Elena, asesora experta en ventas + Clientes de redes

Fecha: **2026-10-08**. Pedido del dueño (textual, resumido):

> Que los agentes sean una **experta asesora de ventas**, con lenguaje natural y amigable,
> que tenga toda la información, que **no deje ir al cliente tan fácil**, que **entienda qué
> busca** para saber qué decir, que sepa que hay **5 % de descuento pagando en efectivo o
> transferencia** (y que un poco más lo decide un asesor humano), que **siempre dé la opción
> de un asesor humano**, que cierre con las **preguntas de enganche** típicas de ChatGPT, y que
> **antes de transferir pida nombre y teléfono** y eso quede en el sistema de ventas, módulo
> Clientes, en un apartado de **clientes de redes** bien armado.

Estado: ⬜ pendiente · 🔄 en curso · ✅ hecho (sin subir).
Toca **dos repos**: `Sistema-De-Ventas` (esta rama) y `Desktop/Agentes` (Agente-ws, Agente-ig, core).
Nada se sube ni se despliega sin que el dueño lo pida (AGENT.md regla 1).

---

## 0. Diagnóstico (lo que hay hoy)

| Tema | Hoy | Problema |
|---|---|---|
| Método de venta | Reglas sueltas: "ofrece 2-3 opciones", "cierra con pregunta" | Muestra productos sin entender la necesidad; no hay un método (descubrir → recomendar → resolver → cerrar) |
| Objeciones | Solo "busca algo más barato" | "Lo consulto con mi pareja", "en otra tienda", "lo pienso" sin respuesta propia |
| Despedida sin compra | "Cierra con calidez y deja la puerta abierta" | Se deja ir al cliente sin un solo intento de retenerlo |
| Descuento | "El valor varía, lo da un asesor" | El dueño confirma: **5 %** con efectivo/transferencia; más, lo decide un humano |
| Asesor humano | Solo se transfiere en casos listados | No se ofrece como opción visible |
| Datos de contacto | Al transferir se manda el nombre del perfil (WA) o el username (IG) | En Instagram **no hay teléfono**: el asesor no tiene cómo llamar. No se pide nombre real ni número |
| Clientes de redes | Las tarjetas de Redes se archivan; no hay una ficha del interesado | No hay base de prospectos de redes: nombre, teléfono, qué quiere, estado del seguimiento |
| Promesas falsas | "Es de los más pedidos" (sin dato), "te lo aparto" (no puede) | Se inventa urgencia y se promete algo que no existe |
| Seguimiento carrito | Se programa a las 24 h | A las 24 h la ventana de WhatsApp/IG ya cerró → **casi nunca se envía** |
| Seguimiento reprogramado | `ON DUPLICATE KEY` conserva datos/hora viejos si está pendiente | Habla del primer producto, no del último |
| Seguimiento "lo pienso" | No existe | El cliente que duda sin carrito nunca vuelve a saber de Elena |

---

## 1. Método de venta de Elena (prompt) — `core/prompt.js`, `core/negocio.json`

Se reescribe la parte comercial del prompt alrededor de un **método consultivo** (el que
usa un buen asesor de muebles en tienda), sin tocar las reglas de seguridad que ya
funcionan (no inventar precios, variantes, nombres exactos, horario, citas, visión).

### 1.1 Personaje y lenguaje
- Asesora de DeCasa, colombiana, tutea, cálida, segura, **conversa** (no recita fichas).
- Mensajes cortos tipo WhatsApp: 2-4 frases; listas solo para comparar productos.
- Refleja al cliente: usa su nombre, retoma lo que dijo ("como me contaste que tienes perrito…").
- Una sola pregunta (máx. dos) por mensaje.

### 1.2 Las 5 etapas
1. **Conectar** — saludo cálido, nombre si lo sabe.
2. **Entender (descubrimiento)** — si la petición es amplia ("busco un sofá") hace 1 pregunta
   clave antes de mostrar: espacio, medidas/puestos, estilo/color, presupuesto, para cuándo,
   ciudad. Si es concreta (nombre del producto, foto), responde directo y descubre después.
   Registra lo aprendido con `recordar_preferencia` (ya existe).
3. **Recomendar** — 2-3 opciones, cada una con **por qué le sirve a él** (beneficio ligado a lo
   que contó). Precio con valor: beneficio + precio + beneficio.
4. **Resolver dudas y objeciones** — técnica: *empatizar → preguntar qué lo frena → responder
   concreto → proponer el siguiente paso*. Respuestas por tipo:
   - *Caro* → valor (madera/fabricación propia/garantía de taller), 5 % efectivo/transferencia,
     ADDI, opciones de menor precio (`buscar_por_presupuesto`).
   - *Lo consulto con mi pareja/familia* → ofrecer fotos y el catálogo para compartir, o
     agendar visita juntos.
   - *En otra tienda* → lo que nos diferencia (fabricamos, a la medida, envío gratis en
     Quindío/Risaralda, 5 %), sin hablar mal de nadie.
   - *Lo pienso / más adelante* → preguntar qué le falta para decidir; ofrecer escribirle luego.
   - Sigue llamando `reportar_objecion` (alerta interna, ya existe).
5. **Cerrar** — cierre por alternativa ("¿la prefieres en 1.40 o 1.60?"), resumen de valor y
   un siguiente paso concreto: carrito/pedido, visita a la tienda o asesor.

### 1.3 No dejar ir al cliente
- Ante "gracias", "ok", "lo pienso", "chao" **sin compra ni cita**: **un** intento de retenerlo
  — pregunta qué le faltó + oferta de valor (fotos, catálogo, comparar, apartar visita,
  asesor, escribirle mañana). Si vuelve a decir que no → despedida cálida, sin presionar.
- Nunca dos intentos seguidos (presionar espanta y daña la cuenta).

### 1.4 Preguntas de enganche al final (estilo ChatGPT)
- Cada respuesta termina con una pregunta que ofrece **1-2 siguientes pasos concretos**,
  variando: "¿Te mando fotos de cerca?", "¿Te la comparo con la Roma?", "¿Quieres verla en
  gris?", "¿Te calculo con el 5 %?", "¿O prefieres que un asesor te llame?".
- Prohibidas las preguntas vacías ("¿algo más?", "¿deseas agendar?") como única salida.

### 1.5 Asesor humano siempre a mano
- En la bienvenida, al presentar productos, ante objeciones, dudas y en el cierre, una de las
  opciones de la pregunta final es hablar con un asesor. No en cada mensaje de datos (pedirle
  la hora de la cita no lleva el ofrecimiento), para que no suene a robot.
- Si el cliente acepta → flujo de contacto (sección 3).

### 1.6 Descuento 5 % (regla del dueño, 2026-10-08)
- `negocio.json → pagos.descuentos.porcentaje = 5` y `masLoDecideAsesor = true`.
- Elena **puede decir** "pagando en efectivo o transferencia tienes 5 % de descuento" y usarlo
  como argumento de cierre. **No promete más**: "si quieres un poquito más, un asesor lo revisa
  contigo" → ofrece transferir.
- La cifra con descuento la calcula **el código**, no el modelo (regla "la IA arma, el código
  calcula"): `ver_carrito`, `agregar_al_carrito` y `confirmar_pedido` devuelven
  `total_con_descuento_efectivo`; los productos de precio único traen `precio_pagando_efectivo`.
  Así `validarPrecios` no lo marca como inventado.
- Tarjeta y ADDI: sin descuento (igual que hoy).

### 1.7 Honestidad comercial
- Quitar "es de los más pedidos" (no hay dato). Urgencia honesta: el 5 % es pagando en
  efectivo/transferencia, el envío es gratis en la zona, lo que no está en tienda se fabrica.
- Quitar "te lo aparto": Elena no puede apartar. Ofrece "te escribo mañana" o el asesor.

---

## 2. Toda la información a la mano
- Ya llegan: nombre exacto, precio/variantes, material, medidas, descripción del equipo,
  categoría, catálogos, sedes, horario, envíos, pagos, ADDI.
- Se agrega al prompt (desde `negocio.json`) un bloque **"POR QUÉ DECASA"**: fabricación
  propia, a la medida, restauración, envío gratis en la zona, 5 % efectivo/transferencia,
  ADDI — para que tenga argumentos frente a la competencia sin inventar.
- Fuera de alcance de esta ronda (anotado como siguiente paso): "más vendidos" reales a
  partir de las órdenes del sistema (requiere ampliar el contrato de lectura de la BD).

---

## 3. Nombre y teléfono antes de transferir — agentes

### 3.1 Módulo compartido `core/contacto.js` (nuevo, con pruebas)
- `normalizarTelefono(texto)` → `+57XXXXXXXXXX` para celulares colombianos (10 dígitos que
  empiezan por 3, con o sin 57/+57, espacios, guiones) y fijos `60X…`; acepta extranjeros con
  `+` y 8-15 dígitos. Devuelve `null` si no es un número.
- `nombreValido(texto)` → descarta "hola", "si", números, cadenas de 1 letra.
- `datosFaltantes({ canal, nombre, telefono, telefonoCanal })` → lista de lo que falta.
  En WhatsApp el número del chat sirve si el cliente confirma "a este mismo".

### 3.2 Herramienta `transferir_asesor` (WS) / `solicitar_asesor` (IG)
- Nuevos parámetros: `nombre`, `telefono_contacto` (en WA acepta `"este_mismo"`),
  `cliente_no_quiso_dar_datos` (bool).
- Si falta algo y no lo negó: **no transfiere**; devuelve `faltan_datos` + instrucción de
  pedirlos en **un solo mensaje** natural ("¡Claro! Para que el asesor te contacte, ¿me
  regalas tu nombre y un celular? 😊"). Si ya los tiene en el perfil (`LO QUE YA SABES`), los
  usa sin volver a preguntar (solo confirma).
- Si el cliente no quiere darlos → transfiere igual (`cliente_no_quiso_dar_datos`): nunca se
  pierde un cliente por un formulario. En WA queda su número de chat.
- Se guardan en el perfil del agente (`nombre`, `telefono_contacto`) para no repetir.

### 3.3 `confirmar_pedido`
- Mismo requisito (un pedido también lo atiende un asesor): nombre + teléfono, junto con
  ciudad y forma de pago que ya se piden, en un solo mensaje.

### 3.4 Lo que viaja al sistema
- Nuevo campo opcional del webhook `contacto`:
  `{ nombre, telefono, ciudad, forma_pago, presupuesto, espacio, preferencias[], productos_interes[], no_quiso_dar_datos }`.
- El `resumen` de la tarjeta lleva además una línea `📇 Nombre · Tel` (se ve en Redes aunque
  el backend esté en la versión vieja).
- Compatibilidad: Laravel viejo ignora `contacto` (la validación lo descarta); Laravel nuevo
  con bot viejo crea el cliente de redes con lo que haya (nombre del perfil, número del chat).

---

## 4. Clientes de redes — sistema de ventas

### 4.1 Base (migración nueva, solo crea tabla — compatible hacia adelante)
`2026_10_16_000001_create_clientes_redes_table`:

| Columna | Tipo | Para qué |
|---|---|---|
| `canal` | string(15) | whatsapp / instagram |
| `identificador` | string(80) | número del chat (WA) o `ig_<psid>`; único con `canal` |
| `nombre`, `telefono` | string | lo que dio el cliente (teléfono normalizado) |
| `contacto_url` | string | enlace wa.me / ig.me para escribirle |
| `ciudad`, `forma_pago`, `espacio` | string | lo que contó |
| `presupuesto` | bigint | en pesos |
| `preferencias`, `productos_interes` | json | gustos y productos vistos |
| `ultimo_interes` | text | resumen de la última tarjeta |
| `ultimo_tipo` | string(20) | asesor / pedido / cita / personalizacion |
| `estado` | string(20) | `nuevo` · `contactado` · `compro` · `perdido` |
| `notas` | text | del asesor |
| `tienda_id`, `cliente_id` | fk null | tienda (citas) y cliente oficial vinculado |
| `ultima_conversacion_id`, `total_conversaciones` | | historial con Redes |
| `primer_contacto_at`, `ultimo_contacto_at`, timestamps | | |

Ningún agente lee esta tabla: es del sistema (no entra al contrato de lectura).

### 4.2 Servicio `App\Services\ClientesRedes::registrarDesdeAviso($conv, $contacto)`
- Lo llama el webhook **después** de crear la tarjeta, dentro de `try/catch`: si falla, la
  tarjeta y los avisos salen igual.
- Upsert por (`canal`, `identificador`). Lo nuevo manda salvo que venga vacío.
- Un aviso nuevo de alguien `perdido` o `compro` lo vuelve a `nuevo` (es otra oportunidad);
  si está `contactado` se respeta (alguien lo está trabajando).
- Si existe un `Cliente` con el mismo teléfono (últimos 10 dígitos) se vincula `cliente_id`.
- No se crea con los avisos de proveedores (`tipo otro` con "PROVEEDOR") ni con cancelaciones
  de cita sin datos nuevos.

### 4.3 API (`ClienteRedController`, `permiso:acceso_redes,supervisor`)
- `GET /clientes-redes` — búsqueda (nombre, teléfono), filtros `estado`, `canal`, paginado,
  orden por último contacto, conteo por estado para los chips.
- `GET /clientes-redes/{id}` — ficha + últimas 20 tarjetas de Redes de esa persona.
- `PUT /clientes-redes/{id}` — `estado`, `notas`, `nombre`, `telefono`, `ciudad`.
- `POST /clientes-redes/{id}/convertir` — crea el `Cliente` (tipo `interesado`, canal
  `whatsapp`/`red_social`, notas de interés) o vincula el existente con el mismo teléfono.
- Vendedor con tienda: ve los de su tienda y los sin tienda (mismo criterio que Redes).

### 4.4 Pantalla — `ClientesView` → pestaña **Redes**
- Pestañas en pastilla `Clientes | Redes` (Redes solo con `acceso_redes` o supervisor).
- `components/clientes/ClientesRedesPanel.vue`: buscador, chips de estado con conteo,
  filtro de canal, tarjetas (nombre, teléfono, chip de canal, qué le interesa, hace cuánto,
  chip de estado). Scroll infinito como la lista de clientes.
- `components/clientes/ClienteRedModal.vue` (hoja inferior): llamar / WhatsApp / Instagram,
  estado en chips, lo que contó (presupuesto, espacio, ciudad, pago, gustos, productos),
  historial de tarjetas, notas, botón **Convertir en cliente** / **Ver cliente**.
- Patrón visual del programa, revisado a 390 px y modo oscuro.

---

## 5. Seguimientos (retener al cliente) — `core/seguimientos.js`, `db.js` ×2
- **Carrito abandonado a las 20 h** (configurable `seguimientos.horasCarrito`), dentro de la
  ventana de 24 h.
- Reprogramar uno **pendiente** actualiza producto y hora (arreglo del `ON DUPLICATE KEY`).
- Nuevo tipo **`interes_pendiente`** ("lo pienso" sin carrito): se programa en
  `reportar_objecion` a las 20 h; se cancela si agrega al carrito, confirma pedido, agenda
  cita o se transfiere. Plantilla en `negocio.json`, editable.
- Se mantienen las reglas: uno por motivo, nunca encima de un asesor, nunca fuera de la ventana.

---

## 6. Pruebas
- **Agentes** (`npm test` en `Desktop/Agentes`): `contacto` (normalización, faltantes),
  transferencia sin datos → pide datos; con datos → transfiere y manda `contacto`; negó datos
  → transfiere; pedido exige contacto; descuento 5 % calculado por código; seguimientos
  (20 h, reprogramación, interés pendiente); prompt contiene el método, el 5 % y no contiene
  "más pedidos"/"te lo aparto".
- **Casos de evaluación** (`core/evaluacion/casos.json`, se corren con `npm run eval`, cuesta
  dinero → solo si el dueño lo autoriza): descubrimiento antes de mostrar, objeción pareja,
  competencia, despedida sin compra (un intento), pide asesor → pide nombre y teléfono antes de
  transferir, descuento 5 % sin prometer más.
- **Backend**: `ClientesRedesTest` (webhook crea/actualiza el cliente de redes, vincula
  cliente oficial, no rompe la tarjeta si falla, permisos, convertir, filtros).
  `RedesWebhookAgentesTest` sigue verde.
- **Front**: `npx vite build`; captura a 390 px claro/oscuro si el entorno lo permite.

---

## 7. Documentación y memoria
- `docs/contrato-agentes.md`: campo `contacto` del webhook y tabla `clientes_redes`.
- `MEMORY.md`: regla del 5 %, contacto antes de transferir, clientes de redes, estado.
- `docs/PROYECTO.md`: sección Clientes → Redes.
- `Desktop/Agentes/PLAN-INTEGRACION-SISTEMA-VENTAS.md`: Fase 3 con lo de este plan.

## 8. Despliegue (cuando el dueño lo pida)
1. Backend primero (la migración solo crea una tabla; el webhook acepta `contacto`).
2. Agentes después (`npm run sync` ya aplicado; cada uno a su repo).
3. Front.
Cualquier orden funciona (todo es compatible hacia atrás); este es el que da datos completos
desde el primer minuto.

## 9. Bitácora

| Paso | Estado | Notas |
|---|---|---|
| 1 Método de venta (prompt) | ✅ | `core/prompt.js`: CÓMO VENDES (5 etapas), PREGUNTA DE ENGANCHE, ASESOR HUMANO SIEMPRE A LA MANO, NO DEJES IR AL CLIENTE, MANEJO DE OBJECIONES, LENGUAJE NATURAL. Sin "más pedidos" ni "te lo aparto". Saludos con la opción de asesor |
| 1.6 Descuento 5 % | ✅ | `negocio.json → pagos.descuentos.porcentaje`; `negocio.conDescuentoEfectivo`; productos con `precio_pagando_efectivo`, carrito y pedido con `total_con_descuento_efectivo` |
| 1.7 Catálogo primero | ✅ | Pedido del dueño (2026-10-08): ver el catálogo despierta el interés del cliente. Si pide o busca una categoría ("quiero ver camas"), Elena manda `enviar_catalogo` de esa categoría de una vez y en el mismo mensaje hace una pregunta para entender qué busca. Ambiguos ("sillas") primero preguntan el tipo. Un catálogo una sola vez por conversación. Casos de evaluación `quiero-ver-camas-catalogo` y `descubrir-antes-de-mostrar` |
| 2 Por qué DeCasa | ✅ | `negocio.json → porQueNosotros` |
| 3 Contacto antes de transferir | ✅ | `core/contacto.js`; `transferir_asesor` / `solicitar_asesor` / `confirmar_pedido` con `nombre`, `telefono_contacto`, `cliente_no_quiso_dar_datos`; el perfil recuerda celular y ciudad; webhook con `contacto`; el resumen lleva `Contacto: Nombre · +57…` |
| 3b Datos temprano y lo que busca | ✅ | Segunda revisión del dueño (2026-10-08): el cliente debe quedar en Clientes → Redes **apenas da sus datos**, aunque no pida asesor, y la IA debe llevar en qué está interesado. Antes la ficha solo nacía con un aviso de Redes (transferencia, pedido, cita, objeción). Ahora: Elena pide nombre y celular temprano (una vez, con un motivo para el cliente); herramienta `guardar_contacto` → `POST /api/agentes/clientes-redes` (sin tarjeta ni avisos; solo crea ficha si trae nombre o celular); `recordar_preferencia` lleva `interes` (una frase actualizada de lo que busca) y `enviar_catalogo` guarda la categoría; si el cliente no quiere dar datos no se le vuelven a pedir, ni al transferir. Columnas nuevas en la misma migración (aún sin desplegar): `interes`, `categorias_interes`. La ficha muestra "Lo que busca" |
| 3c No inventar | ✅ | Dueño (2026-10-08): "muy importante que si la IA no sabe alguna información la transfiera a un asesor, que no se invente nada". Sección SI NO LO SABES, NO LO INVENTES (regla absoluta, por encima de las técnicas de venta): solo afirma lo que devuelven las herramientas o dicen las instrucciones; lista de datos que nunca inventa (tiempos de entrega, garantía, colores no listados, etc.); lo dice con honestidad y lo pasa a un asesor con el motivo exacto. Casos `no-inventa-tiempo-de-entrega` y `no-inventa-color` |
| 3d Evaluación con el modelo real | ✅ | `npm run eval` (gpt-4o, 39 casos por canal, 2026-10-08). 1ª corrida: WhatsApp 28/39 (72 %), Instagram 26/39 (67 %). El fallo más grave: decía "aquí tienes el catálogo" sin enviarlo y una vez inventó un enlace. Arreglos: regla 5c (nunca afirmar una acción no hecha ni escribir un enlace que no dio una herramienta), repaso final al cierre del prompt, aviso por turno para pedir el nombre (`memoria.notaPedirDatos`), carrito/objeciones/asesor más explícitos; el evaluador ahora usa `solicitar_asesor` en IG, vacía el carrito entre casos y sus expresiones distinguen "no te doy el 90 %" de prometerlo. 2ª corrida: 38/39 (97 %) y 36/39 (92 %). Lo que quedó (proveedor y catálogo "prometidos" sin la herramienta) llevó a la **revisión antes de enviar** (`core/verificacion.js`): si la respuesta dice que envió, agregó, agendó o notificó algo sin haber llamado la herramienta en el turno, o trae un enlace que ninguna herramienta devolvió, no se envía y el modelo la corrige (una vez por turno; queda el evento `respuesta_corregida`). 3ª corrida: **WhatsApp 39/39 (100 %), Instagram 38/39 (97 %)**. El único fallo (no aclaró que las sillas del comedor se venden aparte) se aseguró en el código: la búsqueda de bases de comedor trae la nota (`negocio.notaDeVenta`, `servicios.notaVentaPorUnidadEn`); ese arreglo no se ha vuelto a evaluar con el modelo real |
| 4 Clientes de redes | ✅ | Migración `2026_10_16_000001_create_clientes_redes_table`, `ClientesRedes`, `ClienteRedController`, pestaña Redes en Clientes (revisada a 390 px, claro y oscuro) |
| 5 Seguimientos | ✅ | Carrito a 20 h; los pendientes se actualizan; referencia fija (con NULL se duplicaban los recordatorios); nuevo `interes_pendiente` |
| 6 Pruebas | ✅ | Agentes 356 (WS) + 62 (IG), antes 315 + 55. Backend `ClientesRedesTest` (16) + `RedesWebhookAgentesTest` verdes; suite completa 749 con los 10 fallos viejos de siempre. `vite build` OK. **Evals de ventas sin correr** (cuestan dinero) |
| 7 Docs y memoria | ✅ | contrato-agentes, PROYECTO §6.3, MEMORY |

Encontrado y no tocado: los recordatorios de cita parecen calcularse con la hora en UTC
(`programarRecordatoriosCita` hace `setUTCHours` con la hora de Bogotá), así que el de
"2 h antes" saldría 7 h antes. Vale la pena revisarlo aparte.
