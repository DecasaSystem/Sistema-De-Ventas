/**
 * Con quién se comparte una restauración, para mostrarlo en la tarjeta y en
 * el detalle de la orden (no en el PDF: eso es para el cliente).
 *
 * Se comparte de dos formas: con el almacén que pasó el contacto
 * (`tienda_abonada`) o con otro asesor (`covendedor`, si `es_compartida`).
 * Devuelve los nombres juntos, o null si no es una restauración compartida.
 */
export function esRestauracion(o) {
  return o?.tipo === 'restauracion' || o?.serie === 'R'
}

export function restauracionCompartidaCon(o) {
  if (!esRestauracion(o)) return null

  const con = [
    o.tienda_abonada?.nombre,
    o.es_compartida ? o.covendedor?.nombre : null,
  ].filter(Boolean)

  return con.length ? con.join(' y ') : null
}
