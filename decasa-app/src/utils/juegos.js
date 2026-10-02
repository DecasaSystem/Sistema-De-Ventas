/**
 * Productos que se venden en juego (unas mesas de noche de a 2).
 *
 * Su stock se cuenta por PIEZAS: es lo que físicamente hay, y lo único que
 * sigue siendo cierto si el cliente se lleva el juego o solo una. "Juego" es
 * cómo se vende y cómo se muestra. Aquí está la cuenta, para que inventario y
 * la venta digan exactamente lo mismo.
 */

/** Piezas por juego del producto, o 0 si se vende por unidad. */
export function piezasPorJuego(producto) {
  const n = Number(producto?.piezas_por_juego) || 0
  return n > 1 ? n : 0
}

/**
 * Piezas del juego de una opción de variante: unas alas vintage que vienen de
 * a 4 en un producto de a 2. Sin número propio, el del producto.
 */
export function piezasDeOpcion(producto, configId) {
  const n = piezasPorJuego(producto)
  if (!n || !configId) return n
  const propio = Number(producto?.piezas_opciones?.[configId]) || 0
  return propio > 1 ? propio : n
}

/** "2 juegos + 1 suelta", "1 juego", "1 suelta", "0". */
export function enJuegos(piezas, n) {
  const total = Math.max(0, Number(piezas) || 0)
  if (!n) return String(total)
  const juegos = Math.floor(total / n)
  const sueltas = total % n
  const partes = []
  if (juegos) partes.push(`${juegos} ${juegos === 1 ? 'juego' : 'juegos'}`)
  if (sueltas) partes.push(`${sueltas} ${sueltas === 1 ? 'suelta' : 'sueltas'}`)
  return partes.join(' + ') || '0'
}

/**
 * Precio de una pieza suelta: el que se le puso, o el del juego repartido.
 * Se redondea hacia abajo al centavo, igual que el servidor: así N piezas
 * nunca suman más que el juego.
 */
export function precioPieza(producto, precioJuego = producto?.precio_base, configId = null) {
  const n = piezasDeOpcion(producto, configId)
  if (!n) return Number(precioJuego) || 0
  // El precio de pieza del producto es para su juego; una opción con otro
  // número de piezas reparte el precio de su propio juego.
  const propia = n !== piezasPorJuego(producto)
  if (!propia && producto?.precio_pieza != null && producto.precio_pieza !== '') return Number(producto.precio_pieza)
  return Math.floor((Number(precioJuego) || 0) / n * 100) / 100
}
