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

Respuestas: `201` creada, `200` repetida (idempotencia), `401` token, `422`
validación. **Un 4xx hace que el agente descarte el aviso y alerte**; un 5xx o
un timeout hacen que lo reintente hasta por un día. No devuelvas 4xx por errores
transitorios.

## 2. La base de datos (la misma de Aiven)

### Lo que los agentes LEEN de tablas de Laravel

No renombres ni borres estas columnas sin cambiar antes los agentes
(`core/contrato-bd.js` allá; al arrancar lo comparan con el esquema y alertan):

| Tabla | Columnas |
|---|---|
| `productos` | id, nombre, precio_base, foto_url, foto_url_2, medidas, material, categoria, activo |
| `inventario` | producto_id, tienda_id, cantidad_disponible, cantidad_reservada |
| `tiendas` | id, nombre, es_fabrica, activa (los ids 1–5 están fijos en `negocio.json` de los agentes) |
| `producto_variante_configs` | producto_id, tipo_variante_id, opcion_id, precio_adicional (**precio absoluto**, 0 = precio_base). Con dos o más tipos con precio, el sistema suma; el bot **no** cotiza esa combinación, la pasa a un asesor (`core/precio-variantes.js`) |
| `tipos_variante` | id, nombre, afecta_precio, activo |
| `tipo_variante_opciones` | id, nombre, activo |
| `producto_variantes` | producto_id, medida, precio_variante, activo |
| `herramientas` | clave (`catalogo_*`), contenido, activo, orden. Es la fuente de los catálogos que manda el bot |
| `configuracion` | clave, valor. Respaldo viejo de catálogos (no borrar las filas `catalogo_*` todavía) |
| `conversaciones_wa` | telefono, estado, tipo, created_at. El bot se calla si hay una tarjeta `tomada`. "Archivar terminadas" llena `archivada_at`; **no borres filas**: el bot las mira para saber si un asesor ya atendió |

### Lo que los agentes ESCRIBEN (tablas suyas, sin migración de Laravel)

`clientes_wa`, `estado_usuario`, `conversaciones`, `pedidos`, `citas_agentes`,
`producto_imagen_hash`, `wa_*`, `ig_*`. Las crean los agentes al arrancar.
**No las toques desde migraciones de Laravel.** Laravel solo escribe
`estado_usuario.transferido` (`RedesController::silenciarBot`, al Tomar/Terminar)
y lee `wa_eventos` / `ig_eventos` para `/redes/metricas`.
