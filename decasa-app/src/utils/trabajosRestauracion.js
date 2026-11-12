/**
 * Los trabajos de una restauración, separados (dueño, 2026-11-12: "en vez de
 * un solo input para escribir un montón de cosas, poder separarlo y poder
 * elegir cuántos aplica").
 *
 * En Nueva orden se marcan los trabajos de la lista de Gestión → Ajustes (o
 * uno escrito a mano), cada uno con su cantidad, y queda el espacio de notas.
 * Todo se junta en `descripcion_trabajo`, el mismo campo de siempre: así lo
 * ven igual el taller, el detalle de la orden, el PDF, la firma del cliente y
 * el cotizador de IA, sin tocar ninguna de esas pantallas. Las notas salen
 * ahí mismo, después de los trabajos.
 *
 *   Tapizado ×6 · Laca ×2. La pata trasera está suelta
 */

/** Una fila por trabajo marcado: { nombre, cantidad }. */
export function trabajosMarcados(trabajos) {
  return (trabajos ?? [])
    .filter(t => t?.marcado && String(t.nombre ?? '').trim())
    .map(t => ({ nombre: String(t.nombre).trim(), cantidad: Math.max(1, Math.floor(Number(t.cantidad) || 1)) }))
}

/**
 * El texto de "lo que hay que hacerle". La cantidad se escribe cuando el
 * mueble viene en más de una unidad: con una sola, "×1" no dice nada.
 */
export function describirTrabajo(trabajos, notas, cantidadMueble = 1) {
  const conCantidad = Number(cantidadMueble) > 1
  const lista = trabajosMarcados(trabajos)
    .map(t => conCantidad ? `${t.nombre} ×${t.cantidad}` : t.nombre)
    .join(' · ')
  const nota = String(notas ?? '').trim()

  if (lista && nota) return `${lista}. ${nota}`
  return lista || nota
}

/** La lista para el formulario, a partir de los trabajos de Ajustes. */
export function trabajosParaFormulario(nombres, cantidad = 1) {
  return (nombres ?? []).map(nombre => ({ nombre, marcado: false, cantidad, propio: false }))
}
