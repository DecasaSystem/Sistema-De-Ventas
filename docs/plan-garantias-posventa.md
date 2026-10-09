# Garantías: lo que se daña después de entregado

**Estado:** implementado y **subido** el 2026-10-09 (commit 95e0c15), rama
`claude/warranty-post-sale-management-b20972`. Trae **migración**
(`2026_10_17_000001_garantias_posventa`: crea `garantias` y agrega
`orden_items.cantidad_en_garantia`, `entrega_lineas.unidades_garantia`,
`produccion_pasos.garantia_id` y el valor `reembolso` al enum `pagos.tipo`, leído
de la tabla real; solo agrega, no cambia ni borra nada existente).

## El caso

La señora Pérez recibió el 1 de septiembre una cama hecha a la medida y dos
mesas de noche de la tienda. A la semana llama: a la cama se le despegó el
espaldar y una mesa tiene la pata floja.

## Lo que había antes (y por qué no servía)

| Camino | Para qué era | Problema con algo ya entregado |
|---|---|---|
| Devoluciones (`DevolucionController`) | Lo que vuelve en el camión o se daña antes de salir | La pantalla no deja reportar en una orden `entregado`. Forzado por la API: "arreglar" dejaba la pieza sin poderse volver a entregar (seguía contando como entregada); "otro igual" de catálogo descontaba el stock **dos veces** y dejaba una reserva fantasma |
| Cambiar producto (`OrdenController::cambiarProducto`) | El cliente cambia algo entregado por otro | Solo supervisor, solo "por otro producto", sin arreglo ni consulta de inventario |
| Arrancar producción (`ProduccionController::crearPasos`) | Armar los pasos de una pieza | **Borraba todos los pasos** de la pieza y, en cascada, las horas y calificaciones de quien los trabajó. Una pieza que volvía al taller perdía su historia |
| Entregar (`DespachoController::entregar`) | Cierra la entrega | Volver a entregar algo arreglado mandaba otra vez "pendiente de facturación" |

## Cómo funciona ahora

### La idea: reactivar el producto

Cuando se decide arreglarlo en el taller, esas unidades vuelven a estar
**"por entregar" en la misma orden** (`cantidad_entregada` baja) y el mueble
recorre el camino de siempre: pasos del taller → listo → entrega con acta y
fotos. No hay un flujo paralelo de entregas de garantía. La orden pasa sola a
"en producción" / "lista para entrega" y vuelve a "entregado" al entregarlo.

Esas unidades quedan marcadas en `orden_items.cantidad_en_garantia`: ya
salieron del inventario la primera vez, así que **al volverlas a entregar no
se descuenta stock** (`EntregaService::entregar`). La línea de la entrega
guarda cuántas eran de garantía (`entrega_lineas.unidades_garantia`) para que
deshacerla tampoco le sume al inventario.

### Flujo

```
Vendedor (o quien vea la orden) reporta en la orden → "Garantía"
   tipo de daño (madera + línea / tela-espuma / otro), qué pasó, fotos,
   dónde está el mueble, qué pide el cliente
   → el sistema calcula vigencia (desde la última entrega) y el plazo legal
     para responder (15 días hábiles, Ley 1480 art. 58, con festivos)
   → aviso urgente a quien decide
        │
Dictamen (gestiona producción o supervisor)
   ├─ Taller ───────── reactiva el producto; reabre SU producción (la misma)
   │                   ├─ mueble en la casa → "por recoger" → [Ya llegó al taller]
   │                   └─ mueble en la tienda → entra de una
   │                   → pasos del arreglo DESPUÉS de los de fabricación (+ despacho),
   │                     marcados con garantia_id → taller → listo → entrega con acta
   │                     → la garantía se cierra sola al entregar
   ├─ Domicilio ────── quién va y qué día (le llega el aviso; lo ve en "Mis pasos")
   │                   → [Registrar visita] con notas y fotos → resuelta
   │                   (si allá no se pudo, se cambia la decisión: taller o cambio)
   ├─ Otro igual ───── fabricado: se hace de nuevo (reactiva + todos los procesos)
   │                   catálogo: renglón nuevo al MISMO precio, apartado en la tienda
   │                   que tenga libres (o "mandar a fabricar" si no hay en ninguna)
   ├─ Devolver la plata ─ SOLO SUPERVISOR. Fija el monto (sugerido: lo que pagó por
   │                   esas unidades, con descuentos y lo que aún debiera). Queda
   │                   "esperando el producto": la plata NO sale todavía
   │                   → [Llegó el producto] método (efectivo/transferencia/otro) y
   │                     qué se hace con lo devuelto (inventario de una tienda o merma)
   │                   → sale como pago NEGATIVO tipo `reembolso` a nombre de quien
   │                     vendió y en su tienda (si es efectivo baja esa caja); el
   │                     renglón deja de cobrarse, el total baja, meta y comisión
   │                     pendiente lo siguen. Si no le queda nada → orden cancelada
   ├─ Otro producto ── SOLO SUPERVISOR. Busca el producto, ve libres por tienda,
   │                   pone el precio (sugerido: el de lista). Lo dañado deja de
   │                   cobrarse, lo nuevo se suma; la comisión sigue el valor
   └─ No procede ───── causal del anexo (vencida, sol/humedad, mal uso, golpe,
                       intervenido, fuerza mayor, tercero, arrastre, proveedor…)
```

### Reglas que quedan

- **Quién decide:** quien gestiona producción o un supervisor. **Cambiar por
  otro producto** (mueve plata) **solo supervisor**, y el precio lo pone él
  (decisión del dueño, 2026-10-09: "lo decide quien aprueba").
- **Reembolso** (dueño, 2026-10-09): lo devuelto **deja de contar como venta**
  (total, meta y comisión pendiente; una comisión **ya pagada no se toca**); la plata
  sale **al recibir el producto**, no al aprobarlo; lo devuelto se decide al
  recibirlo (inventario o merma); el monto lo aprueba un supervisor (sugerido y
  editable). Se modela como pago negativo para que saldo, caja, cartera y el 50 %
  de comisiones lo cuenten solos; ese pago no se edita desde Pagos.
- **No procede** se cierra con la causal; si el cliente quiere el arreglo
  pagado, se le hace una orden de restauración aparte (dueño, 2026-10-09).
- **Dónde se arregla:** taller o domicilio (dueño, 2026-10-09).
- **Vigencia:** se escoge el tipo de daño al reportar, y la línea si es madera
  (los productos no tienen línea). Madera 5 años élite/promocional, 2
  económica; tela/espuma 6 meses; "otro" sin plazo propio. El sistema avisa
  si está vencida, pero **quien decide tiene la última palabra** (dueño,
  2026-10-09).
- Lo dañado de catálogo que se cambia **no** vuelve al inventario: ya había
  salido al entregarlo (merma). Se recoge al llevar el reemplazo.
- No se puede **deshacer la entrega original** si después se registró una
  garantía sobre algo de ella (`GarantiaService::bloqueaDeshacer`), ni
  **revertir** una orden con garantía (`OrdenController::revertirEntrega`).
- Volver a entregar algo arreglado **no** avisa "pendiente de facturación".
  Un cambio con diferencia de valor avisa "revisar la factura" a facturación.
- Si al cliente le queda saldo pendiente, la entrega final (también la del
  mueble arreglado) lo sigue exigiendo, como cualquier última entrega.

### Plazos que muestra la pantalla

- **Responder antes de:** 15 días hábiles desde el día siguiente al reporte
  (`App\Support\FestivosColombia`, misma cuenta que `fechas.js` de los agentes).
- **Devolver antes de:** 30 días desde que el taller recibe el mueble (anexo).

## Dónde está

| Parte | Archivo |
|---|---|
| Toda la lógica | `decasa-api/app/Services/GarantiaService.php` |
| API | `GarantiaController` — `GET /garantias` (`estado=abiertas`, `orden_id`), `GET /garantias/opciones`, `GET /garantias/stock`, `POST /garantias`, `POST /garantias/{id}/decidir`, `/recibir`, `/visita`, `/recibir-devolucion` |
| Modelo | `Garantia` (vigencias, causales) |
| Enganches | `EntregaService::entregar/revertir`, `OrdenItem::pasaPorElTaller`, `Orden::estadoTrasEntrega`, `ProduccionController::agregarPasos` (ya no borra lo hecho) |
| Pantallas | `OrdenDetalleView` (botón "Garantía" por producto, sección Garantías), `ProduccionView` y `EbanistaView` (bandeja / visitas propias), `components/garantias/*` |
| Pruebas | `GarantiaPosventaTest` (15), `FestivosColombiaTest` (2) |

## Pendiente / ideas para después

- **Recoger el mueble** no es una ruta del conductor: se marca a mano "Ya llegó
  al taller". Si se vuelve frecuente, llevar recogidas en `DespachoView`.
- Reporte de garantías (cuántas, por producto, por causal, tiempos) para ver
  qué falla más.
- La línea (élite/promocional/económica) como campo del producto, si se quiere
  que la vigencia salga sola.
- Reparaciones a domicilio fuera de Armenia: el anexo dice que el transporte lo
  paga el cliente; hoy no se cobra nada desde aquí.
