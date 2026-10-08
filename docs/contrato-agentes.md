# Contrato con los agentes de WhatsApp e Instagram

Los agentes conversacionales (`Agente-ws`, `Agente-ig`, en el repo aparte
`Desktop/Agentes`) son parte del sistema: atienden a los clientes por chat y le
pasan al equipo lo que necesita una persona. Se conectan con este backend por
**dos vías**. Si vas a tocar algo de esta lista, lee esto antes.

## 1. Webhook `POST /api/redes/webhook`

`RedesController::webhook`. Protegido con `X-Agent-Token` = `AGENT_TOKEN`; en los
agentes la variable se llama `DECASA_AGENT_TOKEN`. Hay que rotarla en los tres sitios a la vez.

| Campo | Notas |
|---|---|
| `tipo` | `pedido`, `cita`, `asesor`, `personalizacion`, `otro` (lo desconocido pasa a `otro`) |
| `telefono` | WhatsApp: `+57…` · Instagram: `ig_<psid>`. Es la llave que cruza con `clientes_wa.telefono` del bot |
| `datos_cita` | `{nombre, ubicacion, sede_nombre, dia, fecha (ISO), hora, motivo, cita_agente_id}`. Al Tomar, `fecha` va a `citas.fecha_cita` y `cita_agente_id` a `citas.cita_agente_id`. **Cancelación:** mismo `tipo=cita` con `cancelada: true` y `cita_id` (= `citas_agentes.id`). Se cruza por id y, si la cita es vieja y no lo tiene, por el día |
| `carrito` | `[{producto, precio, cantidad}]` |
| `idempotencia` | UUID por notificación, el mismo en todos los reintentos. Sin él, se usa el hash viejo (teléfono + resumen + minuto) |
| `fuente` | `whatsapp` / `instagram` |
| `contacto` | (desde 2026-10-08) `{nombre, telefono, usuario_red, ciudad, forma_pago, presupuesto, espacio, interes, preferencias[], productos_interes[], categorias_interes[], no_quiso_dar_datos}`, todo opcional. Arma la ficha en **Clientes → Redes** (`clientes_redes`, `ClientesRedes::registrarDesdeAviso`). Solo se valida que sea un objeto: lo de adentro se limpia en el servicio para no dar 422 por un dato raro del chat. El resumen trae además una línea `Contacto: Nombre · +57…` |

Antes de transferir o confirmar un pedido, Elena pide **nombre y celular** (`core/contacto.js`):
si faltan, la herramienta no transfiere y le pide que los pregunte en un mensaje; si el
cliente no quiere darlos, transfiere igual con `no_quiso_dar_datos`. Los agentes **no leen**
`clientes_redes`.

### `POST /api/agentes/clientes-redes` (sin tarjeta)

`ClienteRedController::desdeAgente`, middleware `agente`. Elena pide nombre y celular
**temprano** (aunque no transfiera) y los guarda con la herramienta `guardar_contacto`; también
se llama al actualizar lo que busca (`recordar_preferencia` con `interes`) o al mandar un
catálogo. Cuerpo: `{fuente, telefono (número del chat o ig_<psid>), contacto, contacto_url}`.
No crea tarjeta ni avisa a nadie. Solo **crea** la ficha si `contacto` trae nombre o celular;
si ya existe, la actualiza. Responde `{ok, guardado}` y nada más (regla: los agentes nunca
reciben datos de clientes). Sin cola de reintentos: si falla, la próxima sincronización o el
aviso de transferencia llevan lo mismo.

Respuestas: `201` creada, `200` repetida (idempotencia), `401` token, `422`
validación. **Un 4xx hace que el agente descarte el aviso y alerte**; un 5xx o
un timeout hacen que lo reintente hasta por un día. No devuelvas 4xx por errores
transitorios.

### `GET /api/agentes/pedidos?telefono=…` (solo WhatsApp)

`AgentePedidosController`. Mismo token (middleware `agente` = `TokenDelAgente`, que ahora
protege también el webhook). "¿Cómo va mi pedido?": devuelve las órdenes del cliente cuyo
teléfono (o `contacto_telefono`) termina en los mismos 10 dígitos: `referencia`, `estado` en
palabras, `fecha_compra`, `entrega_estimada` (`Orden::fechaEntregaEstimada`), `entregados` /
`unidades` (`resumenEntrega`) y nombres de `productos`. **Nada de montos, saldo, pagos,
vendedor, tienda, dirección ni notas** (decisión del dueño, 2026-10-08). Sin borradores,
cotizaciones ni anuladas; las entregadas o canceladas solo de los últimos 60 días. El agente
consulta solo el número desde el que escribe (Twilio lo verifica), nunca otro que le dicten.

## 2. La base de datos (la misma de Aiven)

### Lo que los agentes LEEN de tablas de Laravel

No renombres ni borres estas columnas sin cambiar antes los agentes
(`core/contrato-bd.js` allá; al arrancar lo comparan con el esquema y alertan):

| Tabla | Columnas |
|---|---|
| `productos` | id, nombre, precio_base, foto_url, foto_url_2, medidas, material, categoria, activo, descripcion, piezas_por_juego, precio_pieza. Con `piezas_por_juego` el bot dice que el precio es **del juego** y da la pieza suelta como `Producto::precioPieza` |
| `inventario` | producto_id, tienda_id, cantidad_disponible, cantidad_reservada |
| `tiendas` | id, nombre, es_fabrica, activa. Los ids de las sedes están en `negocio.json` de los agentes; cada hora comparan con `activa` y **dejan de ofrecer para citas la sede cuya tienda esté cerrada**, y alertan |
| `producto_variante_configs` | producto_id, tipo_variante_id, opcion_id, precio_adicional (**precio absoluto**, 0 = precio_base), piezas_por_juego (opcional, consulta aparte). Con dos o más tipos con precio, el sistema suma; el bot **no** cotiza esa combinación, la pasa a un asesor (`core/precio-variantes.js`) |
| `tipos_variante` | id, nombre, afecta_precio, activo |
| `tipo_variante_opciones` | id, nombre, activo |
| `producto_variantes` | producto_id, medida, precio_variante, activo |
| `herramientas` | clave (`catalogo_*`), contenido, activo, orden. Es la fuente de los catálogos que manda el bot |
| `configuracion` | clave, valor. Respaldo viejo de catálogos (no borrar las filas `catalogo_*` todavía) |
| `conversaciones_wa` | telefono, estado, tipo, created_at. El bot se calla si hay una tarjeta `tomada`. "Archivar terminadas" llena `archivada_at`; **no borres filas**: el bot las mira para saber si un asesor ya atendió |

| `catalogos`, `catalogo_paginas` | nombre, slug, activo, orden · catalogo_id. Catálogos visuales de Gestión (opcionales para el bot) |

### Enlaces públicos que mandan los agentes

`enviar_catalogo` busca en este orden y manda el primero que exista:

1. Catálogo de Gestión → Catálogos (visual): `https://<app>/c/<slug>`, si está activo y
   tiene páginas. Es el catálogo oficial; reemplazó a los PDF de Drive.
2. Página de la sección del inventario: `https://<app>/catalogo/<categoría>`, la misma del
   botón Compartir de Inventario (`CatalogoPublicoController::seccion`). Existe para toda
   categoría con productos activos, aunque no tenga catálogo (p. ej. Cunas).
3. Enlace viejo de Herramientas (`herramientas.clave = catalogo_*`), solo si no hay nada de
   lo anterior. Si da 404/410 no se manda, y el bot alerta por Telegram.

Sin categoría ("el catálogo") el bot manda la portada `https://<app>/c`. La categoría se
compara por palabras (sin tildes ni conectores, singular/plural) y tienen que ser las
mismas: lo ambiguo ("sillas") se le pregunta al cliente.

`<app>` sale de `catalogoPublico.urlBase` en `negocio.json` de los agentes (o la variable
`CATALOGO_PUBLICO_URL`). **Si cambian estas rutas del front, el dominio de Vercel o la forma
de comparar la categoría en `CatalogoPublicoController::normalizar`, avisar a los agentes.**

### Lo que los agentes ESCRIBEN (tablas suyas, sin migración de Laravel)

`clientes_wa`, `estado_usuario`, `conversaciones`, `pedidos`, `citas_agentes`,
`producto_imagen_hash`, `wa_*`, `ig_*`. Las crean los agentes al arrancar.
**No las toques desde migraciones de Laravel.** Laravel solo escribe
`estado_usuario.transferido` (`RedesController::silenciarBot`, al Tomar/Terminar)
y lee `wa_eventos` / `ig_eventos` para `/redes/metricas`.

### Categorías

Los agentes conocen las categorías de `categorias` / `mapaCategoriasBD` en su
`negocio.json`. Si en Inventario aparece una categoría nueva, sus productos se venden igual
(las búsquedas y la foto usan la base), pero el bot avisa una vez por Telegram para que se
le ponga nombre en `negocio.json`.

## 3. Reglas de este sistema que el bot copia (cambiarlas en los dos lados)

| Regla aquí | Dónde está en el agente | Qué pasa si cambia solo aquí |
|---|---|---|
| Precio de variante = suma de `precio_adicional` de lo elegido (0 → `precio_base`), sin mirar `afecta_precio` (`NuevaOrdenView`) | `core/precio-variantes.js` | El bot cotiza distinto de lo que se cobra. Con 2+ tipos con precio, el bot no suma: pasa a un asesor |
| Venta por juego: `piezas_por_juego`, `Producto::precioPieza()` y `piezasDelJuego()` (por opción) | `core/precio-variantes.js → infoVentaPorJuego` | El bot dice el precio del juego como si fuera de una pieza |
| Stock libre = `cantidad_disponible − cantidad_reservada` | `consultarStock` en los `db.js` | Solo afecta `/debug-stock` (el bot no promete stock al cliente) |
| Tienda cerrada: `tiendas.activa = 0` (+ `cerrada_en`) | `negocio.json → sedes[].activa` + `verificarSedes` cada hora | El bot deja de ofrecerla solo y alerta para actualizar `negocio.json` |
| Categorías de `productos.categoria` | `negocio.json → categorias / mapaCategoriasBD` | Se vende igual; el bot alerta "categoría nueva" para nombrarla |
| Catálogos: Gestión → Catálogos (`/c/<slug>`) y sección de Inventario (`/catalogo/<cat>`) | `core/catalogos.js` | — (los lee de la base) |
| Estados de una orden y sus nombres para el cliente | `AgentePedidosController::ESTADOS` (aquí) | Un estado nuevo sale como "En proceso" hasta agregarlo |
| Formas de pago: efectivo, transferencia, tarjeta, Addi | `negocio.json → pagos` | El bot ofrece una forma de pago que no existe |
| Teléfono del cliente: se compara por los últimos 10 dígitos | `AgentePedidosController` | — |
| Descuento por pago en efectivo o transferencia: **5 %**; más lo decide un asesor (dueño, 2026-10-08). Tarjeta y Addi sin descuento | `negocio.json → pagos.descuentos.porcentaje` + `negocio.conDescuentoEfectivo` (el código calcula la cifra) | El bot promete un porcentaje que ya no es |
| Celular: `+57` + 10 dígitos (3… o 60…) | `core/contacto.js → normalizarTelefono` y `ClientesRedes::telefono` | La ficha y el bot guardan el número en formatos distintos |

Decisiones del dueño que viven en los agentes (2026-10-08): **no se abre en festivos**
(los agentes los calculan solos, Ley Emiliani); Circunvalar cerró el 2026-08-27.
