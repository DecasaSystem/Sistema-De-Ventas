/**
 * Promociones por tienda (Inventario → Promociones): qué promoción le toca a
 * una línea de la orden y cuánto le rebaja. El servidor guarda el precio ya
 * rebajado, como con el descuento por producto, más `promocion_id`.
 *
 * La misma cuenta que Promocion::precioCon en el backend.
 */
import { formatearPesos } from '@/utils/pesos'

/**
 * ¿Esta línea puede llevar promoción? Lo del catálogo a su precio: de stock o
 * mandado a fabricar igual. Lo personalizado (otro precio), lo que espera
 * precio del taller, los regalos y las restauraciones no.
 * `_sin_promo`: vino de un borrador o cotización guardados, con el precio ya
 * rebajado (o no): volver a aplicarla lo rebajaría dos veces.
 */
export function puedeLlevarPromo(item) {
  if (!item?.producto_id || item._sin_promo) return false
  if (item._regalo || item._cotizarPrecio || item._es_restauracion) return false
  if (item.es_personalizado && !item._fabricar_pedido) return false
  return true
}

/** Las opciones elegidas de la línea: una sola, o varias (1.40 y con baúl). */
function opcionesDe(item) {
  const ids = [...(item._config_ids ?? []), item._config_id].filter(Boolean).map(Number)
  return new Set(ids)
}

/** Piezas en que se parte el precio de la promoción si se vende por pieza suelta. */
function partes(item) {
  const n = Number(item._piezas_por_juego) || 0
  return n > 1 && item._por_juego === false ? n : 1
}

/** Cuánto le quita la promoción a una unidad de la línea, en pesos. */
export function rebajaDePromo(promo, item) {
  if (!promo) return 0
  const base  = Number(item.precio_unitario) || 0
  const valor = Number(promo.valor) || 0
  const n     = partes(item)
  let rebaja = 0
  if (promo.tipo === 'porcentaje') rebaja = Math.round(base * valor / 100)
  else if (promo.tipo === 'monto') rebaja = Math.round(valor / n)
  else if (promo.tipo === 'precio') rebaja = base - Math.round(valor / n)
  return Math.max(0, Math.min(base, rebaja))
}

/**
 * La promoción de la línea entre las que corren en la tienda de la venta. La
 * de una medida o tela gana sobre la del producto entero; entre dos del mismo
 * nivel, la que más rebaja.
 */
export function promoParaItem(promos, item) {
  if (!promos?.length || !puedeLlevarPromo(item)) return null
  const delProducto = promos.filter(p => Number(p.producto_id) === Number(item.producto_id))
  if (!delProducto.length) return null

  const opciones = opcionesDe(item)
  const concretas = delProducto.filter(p =>
    (p.config_id && opciones.has(Number(p.config_id))) ||
    (p.variante_id && Number(p.variante_id) === Number(item.variante_id))
  )
  const generales = delProducto.filter(p => !p.config_id && !p.variante_id)
  const candidatas = concretas.length ? concretas : generales
  if (!candidatas.length) return null
  return candidatas.reduce((mejor, p) => rebajaDePromo(p, item) > rebajaDePromo(mejor, item) ? p : mejor)
}

/** Las promociones de un producto (para marcarlo en la búsqueda). */
export function promosDelProducto(promos, productoId) {
  return (promos ?? []).filter(p => Number(p.producto_id) === Number(productoId))
}

/** "−15 %", "−$200.000", "a $1.290.000". */
export function textoRebaja(promo) {
  if (!promo) return ''
  const v = Number(promo.valor) || 0
  if (promo.tipo === 'porcentaje') return `−${Number.isInteger(v) ? v : v.toFixed(1)} %`
  if (promo.tipo === 'monto') return `−$${formatearPesos(v)}`
  return `a $${formatearPesos(v)}`
}

/** "31 oct": hasta cuándo va, corto. */
export function textoHasta(fecha) {
  if (!fecha) return ''
  const [a, m, d] = String(fecha).slice(0, 10).split('-').map(Number)
  const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
  return `${d} ${meses[m - 1]}${a !== new Date().getFullYear() ? ' ' + a : ''}`
}
