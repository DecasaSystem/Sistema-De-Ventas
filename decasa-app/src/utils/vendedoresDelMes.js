/**
 * El selector de vendedores de Bonificaciones, armado con la lista del mes que
 * ya llegó (GET /comisiones?mes=) en vez de pedirle al servidor que vuelva a
 * hacer toda la cuenta del mes solo para contar (GET /comisiones/vendedores:
 * ~33 consultas, ~8 s; 2026-11-19).
 *
 * Es la misma cuenta que ComisionController::vendedores: una fila por persona,
 * con el nombre y la tienda de su primera bonificación, cuántas están
 * pendientes, listas y pagadas (estado calculado), ordenadas por nombre.
 * Solo sirve con la lista SIN filtro de vendedor: filtrada, faltan los demás.
 */
export function vendedoresDelMes(comisiones) {
  const grupos = new Map()
  for (const c of comisiones ?? []) {
    const id = Number(c.vendedor_id)
    if (!grupos.has(id)) {
      grupos.set(id, { id, nombre: c.vendedor_nombre, tienda: c.tienda_nombre, pendiente: 0, lista: 0, pagada: 0 })
    }
    const g = grupos.get(id)
    if (c.estado_calculado === 'pendiente') g.pendiente++
    else if (c.estado_calculado === 'lista') g.lista++
    else if (c.estado_calculado === 'pagada') g.pagada++
  }
  // Como sortBy('nombre') de PHP: comparación simple de texto, estable.
  return [...grupos.values()].sort((a, b) => {
    const x = a.nombre ?? '', y = b.nombre ?? ''
    return x < y ? -1 : x > y ? 1 : 0
  })
}
