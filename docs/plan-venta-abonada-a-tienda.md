# Ventas de un independiente que se le abonan a una tienda

**Estado:** plan, sin implementar. Falta una decisión (ver "Lo que hay que decidir").

## El caso

Flavio es vendedor independiente. A veces:

- Va a un almacén, le pasan el contacto de un cliente, él cierra la venta.
- **La venta se le abona a ese almacén** y le ayuda a cumplir su meta.
- Pero **la venta sigue siendo de Flavio**: es su trabajo y su comisión.

Otras veces vende solo, y entonces la venta es únicamente suya.

Lo mismo aplicará a cualquier otro independiente que se cree desde Trabajadores.

**Ojo con una distinción que ya está resuelta:** una cosa es a qué tienda se le
abona la venta para la meta, y otra dónde entró el dinero físico. El sistema ya
las lleva por separado — ver "Lo que ya funciona".

## Cómo funciona hoy (verificado en el código)

### Lo que alimenta la meta de una tienda

`ComisionController::cargarTotales()`:

```php
$totalesTienda = DB::table('comisiones')
    ->selectRaw('tienda_id, mes_venta, SUM(valor_orden) as total')
    ->groupBy('tienda_id', 'mes_venta')
```

O sea: **lo alcanzado por una tienda sale de `comisiones.tienda_id`**, que a su
vez se copia de `ordenes.tienda_id` al crear la comisión.

Conclusión importante: **abonarle una venta a una tienda ya es posible hoy** —
basta con que la orden lleve el `tienda_id` de esa tienda. No hace falta
inventar nada para eso.

### Cómo se reparte la comisión

```php
$metaCumplida   = $totalTienda >= $meta;
$comisionPool   = $metaCumplida ? ($totalTienda - $meta) / 1.19 * 0.05 : 0;
$comisionAsesor = $comisionPool / $divisor;      // divisor = asesores asignados
$montoComision  = $comisionAsesor * (valor_orden / total_del_vendedor_en_esa_tienda);
```

Los asesores que se reparten el pool salen de `tienda_asesores_comision`
(tabla `TiendaAsesor`, por tienda y mes).

**Aquí está el problema.** Si la orden de Flavio lleva `tienda_id = Vía El Edén`,
Flavio pasa a cobrar como si fuera un asesor de Edén: se lleva
`pool_de_Edén / divisor`. Casi seguro no es lo que se quiere, y además ata su
comisión a que Edén cumpla su meta.

## Lo que ya funciona y no hay que tocar

| Cosa | Cómo está resuelto |
|---|---|
| **La caja** | `pagos.tienda_id` guarda dónde entró el dinero, aparte del de la orden. Si Flavio recibe el efectivo, su caja lo debe, aunque la venta se le abone a Edén. |
| **De qué bodega salió el mueble** | `orden_items.tienda_origen_id`. Ya se puede vender de Edén y que la orden sea de otra tienda. |
| **La numeración** | Independientes y las tiendas de Armenia están en el mismo grupo, así que el consecutivo no cambia según a quién se le abone. |
| **Las estadísticas personales** | `StatsController` filtra por `vendedor_id`. Los números de Flavio salen bien se abone donde se abone. |

## La regla (confirmada)

**Es una venta compartida, pero con una tienda en vez de con una persona.**
Mitad y mitad:

```
Flavio vende $5.000.000 y la abona a Vía El Edén

  $2.500.000  ->  se le suman a Vía El Edén (su meta)
  $2.500.000  ->  se le suman a Flavio (lo suyo)
```

Es exactamente lo que ya hace `es_compartida` entre dos vendedores
(`valor_total / 2` para cada uno), solo que la otra mitad no va a otra persona
sino a una tienda.

Eso responde a cómo se le paga: **Flavio cobra sobre su mitad**, con su propio
esquema. No entra en el reparto del pool de Edén ni le baja la comisión a los
asesores de allá — al contrario, su mitad les ayuda a llegar a la meta.

## Plan propuesto

Asumiendo **(a)**, que es lo que encaja con lo descrito.

### 1. Base de datos

Una columna nueva en `ordenes`:

```
tienda_abonada_id  (nullable, FK a tiendas)
```

- `tienda_id` sigue siendo **de quién es la venta** → Independientes para Flavio.
- `tienda_abonada_id` es **a qué tienda se le abona para la meta** → Edén.
- En una venta normal va nula, y todo se comporta igual que hoy.

Por qué una columna nueva y no reusar `tienda_id`: si se pusiera Edén en
`tienda_id`, la comisión de Flavio saldría del pool de Edén (el problema de
arriba) y la venta desaparecería de la fila de independientes en los reportes.
Separarlas deja que cada número vaya a donde corresponde.

### 2. Meta de la tienda

`cargarTotales()` pasa a sumar también lo abonado:

- lo propio: `comisiones.tienda_id = X`
- más lo abonado: órdenes con `tienda_abonada_id = X`

Cuidado con no contar dos veces: la venta abonada suma **a la meta de Edén** y
**a la fila de Flavio**, pero en el total de la empresa se cuenta una sola vez.
Son consultas distintas, así que es contenible, pero hay que revisarlo una por una.

### 3. Quién puede abonar y a quién

- Solo vendedores con `independiente = 1`.
- Solo a tiendas activas que no sean Independientes ni Bodega Fábrica.
- Se puede cambiar mientras la comisión no esté liquidada (`lista` o `pagada`),
  igual que ya se hace al reasignar vendedor.

### 4. Pantallas

- **Nueva orden**: si el vendedor es independiente, un selector opcional
  *"¿Esta venta se le abona a alguna tienda?"* con la lista de tiendas y la
  opción *"Solo mía"*. Por defecto: solo mía.
- **Editar orden**: el mismo selector, para corregirlo después.
- **Detalle de la orden**: que se lea claro, algo como
  *"Venta de Flavio · abonada a Decasa Vía El Edén"*.
- **Comisiones**: en la fila de la tienda, distinguir lo propio de lo abonado,
  para que se entienda de dónde salió el cumplimiento.

### 5. Órdenes que ya existen

Ninguna se toca: `tienda_abonada_id` nace nula y todo sigue igual. Si hay
ventas viejas que debieron abonarse a una tienda, se corrigen desde Editar una
por una.

### 6. Cómo se prueba antes de subir

1. Venta de Flavio sin abonar → cuenta solo para Independientes, como hoy.
2. Venta de Flavio abonada a Edén → suma a la meta de Edén, la comisión sigue
   siendo de Flavio y **no** sale del pool de Edén.
3. La misma venta no se cuenta dos veces en el total de la empresa.
4. El dinero sigue en la caja de quien lo recibió, sin importar el abono.
5. Cambiar el abono recalcula lo alcanzado por las dos tiendas.
6. Un vendedor no independiente no puede abonar a otra tienda.
7. No se puede cambiar el abono si la comisión ya está liquidada.
8. El consecutivo no cambia según a quién se abone.

## Cómo está el mes hoy (agosto 2026, datos reales)

| Tienda | Meta | Alcanzado | Asesores asignados |
|---|---|---|---|
| Decasa Norte | — | $12.940.000 | ninguno |
| Decasa Vía El Edén | — | $0 | ninguno |
| Decasa Vía Jardines | — | $0 | ninguno |
| Decasa Unicentro Pereira | — | $16.270.000 | ninguno |
| Decasa Circunvalar | — | $13.180.000 | ninguno |
| Independientes | — | $18.780.000 | ninguno |

**Ninguna tienda tiene meta puesta este mes, y no hay asesores asignados.**

Eso tiene dos consecuencias que conviene tener claras antes de programar nada:

1. **Hoy el pool da $0 para todo el mundo.** Sin meta, `metaCumplida` es falso y
   la comisión sale en cero. Abonarle ventas a Edén no le va a servir de nada
   hasta que Edén tenga una meta cargada.
2. **Un independiente hoy no cobra comisión por esta vía.** Independientes lleva
   $18.780.000 vendidos y ninguna meta, así que su pool también es $0. Sea cual
   sea la decisión de arriba, esto hay que resolverlo aparte: o se le pone meta
   a Independientes, o los independientes cobran por otro esquema.

Conviene cargar las metas del mes antes de medir si esto funciona; si no, todas
las pruebas van a dar cero y no se va a poder distinguir lo que está bien de lo
que está mal.

## Riesgo principal

Tocar el cálculo de metas afecta lo que cobra **todo el mundo**, no solo Flavio.
Antes de subir hay que comparar, con datos reales, lo alcanzado y el pool de
cada tienda antes y después del cambio: **si no hay ventas abonadas, ninguna
cifra puede moverse ni un peso**.
