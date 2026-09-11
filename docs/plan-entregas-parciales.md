# Entregas parciales, daños en la entrega y varios comprobantes

**Estado:** fases A y B implementadas (rama `feat/entregas-parciales`); C y D pendientes.

## El caso

Un cliente compra un reloj (está en la tienda) y un mueble (hay que fabricarlo).
Hoy no hay forma de decir "el reloj ya se lo llevó, el mueble va después". Lo
mismo cuando dos muebles se entregan en días distintos, o cuando de una entrega
vuelve una pieza golpeada y hay que decidir si se arregla o se cambia por otra.

Y en Nueva Orden el comprobante de pago admite una sola foto.

---

## Cómo funciona hoy (verificado en el código)

### La entrega es de la ORDEN entera, nunca de un producto

Todo el módulo está construido sobre `despacho_items`, y un `despacho_item`
es **una orden dentro de un despacho** (`despacho_id + orden_id`, único), no un
producto. El acta, las fotos, la firma y el pago cuelgan de ahí. No existe
ninguna tabla ni columna que diga "de esta orden se entregó el ítem 3 y el 5
no". `orden_items` tiene `cantidad`, `devuelto_en` (solo para cambios después
de entregada) y nada más sobre entrega.

Consecuencia: **cualquier camino que marque "entregado" lo hace para toda la
orden**, y descuenta el stock y cierra la producción de todo a la vez.

### Los cuatro caminos que hoy marcan una entrega

| Camino | Quién | Dónde en el código | Qué hace |
|---|---|---|---|
| **1. Entrega inmediata** ("se lo lleva de una") | vendedor, al crear la orden | `OrdenController::store()` :332-347, :591-629 | La orden nace `entregado`; descuenta stock en el acto. **Rechaza la orden entera si hay algún ítem personalizado/para fabricar** ("Quita los ítems personalizados…"). Es exactamente el caso reloj + mueble. |
| **2. "Entregado — se lo llevó de la tienda"** | supervisor, desde Acciones → Cambiar estado | `OrdenController::updateEstado()` :2521, `descontarStockPorEntrega()` :2472 | Cambia el estado y descuenta stock. Sin acta, sin foto. **Oculto si la orden tiene personalizados** (`opcionesNuevoEstado` en `OrdenDetalleView.vue` :793). |
| **3. Entrega directa** | vendedor/supervisor con `acceso_entregas`, botón "Entregar ahora" | `DespachoController::crearEntregaDirecta()` :553, luego `registrarPago()` :799 y `entregar()` :1138 | Crea un `despacho` sintético (`tipo='directa'`) con un `despacho_item` y usa **la misma pantalla del conductor** (`EntregaDetalleModal.vue`). Solo aparece con la orden en `listo_entrega`. |
| **4. Ruta de conductor** | supervisor arma la ruta, conductor entrega | `DespachoView.vue` → `DespachoController::asignar/enviarRuta/iniciarRuta` → `registrarPago` + `entregar` | Igual que 3 pero con camión, ruta y `en_camino`. |

Los caminos 3 y 4 comparten motor: `registrarPago()` (pago + fotos + acta +
devoluciones) y `entregar()` (estado, stock, producción). Ese motor es bueno y
**es el que hay que volver "por producto"**, no reescribirlo.

### Cuándo una orden queda "lista para entregar"

- Si tiene ítems personalizados: la pone Producción, cuando **todas** sus
  producciones están `listo` (`ProduccionController` :441-449 y :640-660). Los
  ítems de stock no cuentan para nada aquí.
- Si no tiene personalizados: la pone el supervisor a mano (camino 2).

Por eso el reloj espera al mueble: la orden no es `listo_entrega` hasta que el
taller termine, y sin `listo_entrega` no hay "Entregar ahora".

### Daños hoy

- **En el camión (a la entrega):** el formulario ya tiene "¿Se devuelve
  algo?" por producto y cantidad (`registrarPago` :818, `leerDevoluciones`
  :1028). Crea una `devolucion` en `pendiente`, la orden pasa a `devuelto`, y
  después alguien en Devoluciones decide: **`a_produccion`** (se reabre la
  producción de esa pieza; si era de catálogo se le crea una) o
  **`reembolso`** (sale de caja y del inventario como merma)
  (`DevolucionController::decidir` :150). **No existe "cambiar por otro"** —
  ni sacar otra unidad del stock ni fabricar una nueva sin devolver plata.
- **Después de entregada:** `cambiarProducto` (:2204) marca el ítem
  `devuelto_en`, reabre la orden en `pendiente_anticipo` y el vendedor agrega
  el reemplazo desde Editar. Solo supervisor, solo orden `entregado`.
- **Antes de salir (se dañó en la tienda/bodega):** no hay nada. Toca cancelar
  el ítem por Editar y volverlo a agregar.

### Comprobante de pago hoy

- `ordenes.factura_foto_url` — **una** URL (`varchar(500)`). Nueva Orden
  exige una foto y guarda una (`NuevaOrdenView.vue` :1438-1460, :4170-4210).
- `pagos.comprobante_url` — una URL por pago (`RegistroPagoModal`).
- `despacho_items.foto_pago` — una por entrega.
- Precedente de varias fotos en el mismo sistema: `orden_items.boceto_fotos`
  (texto JSON con la lista, y `boceto_url` con la primera por compatibilidad).
  Es el patrón a copiar.

### Cosas rotas que aparecieron en el camino

1. **`PagoController` :140** — si el saldo llega a $0 y la orden está en
   `listo_entrega`, la marca `entregado` sola. **Sin descontar stock, sin
   cerrar producción, sin acta.** Un pago desde la orden "entrega" sin que
   nadie entregue. Hay que quitarlo.
2. **`ProduccionController` :441-445** — si todas las producciones están
   `entregado`, la orden pasa a `entregado` sin tocar el stock de los ítems de
   catálogo que pudiera tener. Con entregas por ítem esto desaparece.
3. **Camino 2** (supervisor marca entregado) no deja acta ni foto ni quién
   recibió. Con el rediseño se unifica con el 3 y deja de existir como
   "cambio de estado".
4. `revertirEntrega` (:2353) devuelve TODO el stock de la orden; con entregas
   parciales tiene que revertir una entrega concreta.

**Datos de producción:** desde julio hay 15 órdenes mixtas (stock +
fabricación) sobre 138; 31 entregas por ruta, 1 directa pendiente, 8 con
entrega inmediata, 0 devoluciones registradas.

---

## Propuesta

### Principio

> **Se entregan productos, no órdenes.** La orden solo resume: "2 de 3
> entregados". Un solo formulario de entrega para todos los caminos.

### 1. Modelo: la entrega baja al ítem

Nueva tabla **`entrega_lineas`** (qué se entregó en cada entrega):

```
id
despacho_item_id   → la entrega (acta, fotos, pago, quién entregó, fecha)
orden_item_id      → qué producto
cantidad           → cuántas unidades en ESTA entrega
resultado          enum: entregado | con_novedad | devuelto
```

- `despacho_items` deja de ser "la orden en el camión" y pasa a ser **"una
  entrega"** (puede haber varias por orden). Se quita el `unique(despacho_id,
  orden_id)`? No hace falta: cada entrega ya es un despacho distinto en la
  directa, y en ruta una orden va una vez por ruta.
- `orden_items.cantidad_entregada` (entero, caché) para no sumar líneas cada
  vez que se lista. Se recalcula al entregar/revertir.
- **Estado de la orden derivado, sin estados nuevos**: una orden es
  `entregado` cuando **todo lo vivo está entregado**. Mientras tanto se queda
  en su estado natural (`en_produccion` si el mueble se fabrica,
  `listo_entrega` si ya está todo listo) y la API devuelve
  `entrega: { entregados: 1, total: 2, parcial: true }` para pintar el badge
  **"Entrega parcial 1/2"** en listas, detalle, despacho y reportes. No se
  agrega un enum `entregado_parcial`: mover el enum toca reportes, agente,
  filtros y el `cerrarProduccion`; el badge dice lo mismo sin romper nada.

### 2. Qué se puede entregar y cuándo (por ítem)

| Ítem | Está "entregable" cuando |
|---|---|
| De stock (no personalizado) | siempre que la orden esté confirmada (tiene reserva) |
| Personalizado / para fabricar | su `produccion.estado` es `listo` |
| Producto único | siempre |
| Ya entregado / devuelto por cambio | nunca (no aparece) |

Cambio clave: **"Entregar ahora" se habilita cuando hay al menos un ítem
entregable**, no cuando la orden está en `listo_entrega`. El reloj se entrega
el día de la venta; el mueble cuando el taller lo termine.

### 3. Un solo formulario de entrega, con selección de productos

`EntregaDetalleModal.vue` gana un primer bloque **"¿Qué se entrega hoy?"**:
lista de ítems entregables con cantidad (por defecto todos los que estén
listos, se desmarcan los que no). El resto del formulario no cambia:
foto del producto, pago (solo se exige si el cliente lo debe **y** es la
última entrega — ver decisión 2), acta, firma.

Por ítem, tres resultados (ya existen dos):

- **Entregado conforme**
- **Entregado con novedad** (llegó rayado pero se queda) — ya existe
- **No se recibió: se devuelve** — ya existe, pero ahora al marcarlo se
  pregunta ahí mismo **qué se hace**:
  - **Arreglar** → vuelve al taller (`a_produccion`, ya existe)
  - **Cambiar por otro** → *nuevo*: si es de stock, la unidad dañada sale como
    merma y se reserva otra igual (misma variante) para volver a entregar; si es
    personalizado, se abre una producción nueva para el mismo ítem con la
    anterior marcada como dañada. El ítem no se duplica en la orden: sigue
    siendo la misma línea, con `cantidad_entregada` sin subir.
  - **Dejarlo pendiente** → como hoy: lo decide después Producción en
    Devoluciones (`DevolucionController::decidir` gana la opción `cambio`).

Este mismo formulario lo usan **vendedor (entrega directa), supervisor y
conductor**. El supervisor pierde "Entregado — se lo llevó de la tienda" como
cambio de estado y gana el botón "Entregar ahora" con el formulario (acta y
foto incluidas: hoy ese camino no deja rastro).

### 4. "Se lo lleva de una" al crear la orden

En Nueva Orden, el interruptor de entrega inmediata deja de ser de toda la
orden y pasa a **cada ítem de stock**: "☑ Se lo lleva ahora". Al confirmar la
orden:

- Los marcados se entregan en el acto: se crea una entrega directa a nombre
  del vendedor con esas líneas, se descuenta stock, y queda la foto del
  comprobante de pago como foto de pago de la entrega.
- Los demás siguen el camino normal (reserva / producción).
- Ya no se rechaza la orden por tener un mueble para fabricar junto al reloj.

Un borrador se acuerda de las marcas (hoy `ordenes.entrega_inmediata`; pasa a
un flag por ítem en el JSON del borrador o una columna `llevar_ahora` en
`orden_items`).

### 5. Daños antes de la entrega (en tienda o bodega)

Acción nueva en la orden, sobre un ítem no entregado: **"Producto dañado"**
(motivo + foto). Abre las mismas dos opciones —arreglar o cambiar por otro—
reutilizando el `DevolucionController` (una devolución sin `despacho_item_id`,
que la tabla ya permite: "Null si se registra a mano desde la orden").

### 6. Varios comprobantes de pago

- `ordenes.factura_fotos` (JSON, lista de URLs). `factura_foto_url` se sigue
  llenando con la primera para no tocar PDF, reportes ni el agente.
- Igual en `pagos.comprobante_fotos` y en `despacho_items.foto_pago` →
  `fotos_pago`.
- Nueva Orden: el cuadro "Foto del comprobante" acepta varias (`multiple`),
  muestra miniaturas, se puede quitar una. Se suben una por una al mismo
  `/upload/foto`. Mínimo una, como hoy. Lo mismo en `RegistroPagoModal` y en
  el formulario de entrega.

### 7. Rutas de conductor (fase aparte)

En `DespachoView` al meter una orden a una ruta se elige qué ítems van (por
defecto los entregables no entregados). La ruta lleva líneas, no órdenes. El
conductor ve en cada parada solo lo que carga. Es la parte más grande de UI y
la que menos duele hoy (los conductores casi no usan el programa, por eso
existe la entrega directa), así que va de última.

---

## Lo que NO cambia

- El motor `registrarPago()` + `entregar()`: se le agrega "para qué líneas",
  el resto (acta, firma, descuento condicionado, notificaciones, comisiones)
  queda igual.
- Devoluciones como módulo y sus dos decisiones actuales.
- `cambiarProducto` para lo ya entregado.
- Reportes y agente: siguen leyendo `ordenes.estado`; solo ganan el badge.

---

## Fases (cada una entrega algo usable sola)

| Fase | Qué | Toca | Tamaño |
|---|---|---|---|
| **A. Comprobantes múltiples** | punto 6 | migración (3 columnas JSON), `OrdenController::store/update`, `PagoController`, `NuevaOrdenView`, `RegistroPagoModal`, `OrdenDetalleView` (galería) | chico, independiente — puede ir primero |
| **B. Entrega por ítem (directa + supervisor + "se lo lleva")** | puntos 1, 2, 3 (sin daños), 4, y quitar los bugs 1-3 | migración `entrega_lineas` + `cantidad_entregada`; `DespachoController::crearEntregaDirecta/registrarPago/entregar`; `OrdenController::store/updateEstado/revertirEntrega`; `Orden::laPuedeEntregarDirecto` → por ítem; `EntregaDetalleModal`, `OrdenDetalleView`, `NuevaOrdenView`, badge en `OrdenesView`; tests | el grueso |
| **C. Daños: arreglar o cambiar** | punto 3 (resultados por ítem) y 5 | `DevolucionController::decidir` (+`cambio`), reserva/merma de stock, `Produccion` nueva; `EntregaDetalleModal`, acción en `OrdenDetalleView`, `DevolucionesView` | mediano |
| **D. Rutas por ítem** | punto 7 | `DespachoView`, `DespachoController::agregarOrdenARuta/asignar`, `MisEntregasView` | mediano-grande |

Orden sugerido: **A → B → C → D**. A y B se pueden hacer en paralelo.

---

## Decisiones tomadas (2026-09-11)

1. **Pago en entregas parciales:** no se obliga al pago completo — al cliente
   todavía le falta recibir algo. En una entrega parcial el pago es opcional
   (se puede registrar un abono); el saldo se exige en la última entrega.
2. **Comisión:** las reglas no cambian (≥50% pagado + fecha). La entrega no
   la afecta.
3. **Daños:** el que entrega registra lo que pasó y **el vendedor deja
   anotadas las dos opciones que le dio al cliente** (arreglar o cambiar) con
   la que el cliente prefiere; **decide el supervisor**.
4. **Cambiar producto:** puede ser por el **mismo** producto (otra unidad /
   fabricarlo de nuevo) o por **otro**, que puede valer **más, menos o lo
   mismo**. Lo devuelto deja de cobrarse, lo nuevo se suma, y el saldo se
   recalcula contra lo ya pagado (misma cuenta de `cambiarProducto`): si
   vale más, paga la diferencia; si vale menos, queda a favor.
