// Textos y colores de las garantías, en un solo sitio para que la orden, la
// bandeja del taller y el dictamen digan lo mismo.

export const ESTADOS = {
  pendiente:   { texto: 'Esperando dictamen',     cls: 'bg-orange-100 text-orange-800' },
  por_recoger: { texto: 'Hay que recogerlo',      cls: 'bg-amber-100 text-amber-800' },
  en_taller:   { texto: 'En el taller',           cls: 'bg-blue-100 text-blue-800' },
  a_domicilio: { texto: 'Visita a domicilio',     cls: 'bg-indigo-100 text-indigo-800' },
  cambio:      { texto: 'En cambio',              cls: 'bg-teal-100 text-teal-800' },
  por_devolver: { texto: 'Esperando el producto', cls: 'bg-red-100 text-red-800' },
  resuelta:    { texto: 'Resuelta',               cls: 'bg-green-100 text-green-800' },
  no_procede:  { texto: 'No procede',             cls: 'bg-gray-200 text-gray-700' },
}

export const TIPOS_DANO = [
  { v: 'madera',      t: 'Madera / estructura', d: 'Broma, desajuste, dilatación. 5 años (élite/promocional) o 2 (económica).' },
  { v: 'tela_espuma', t: 'Tela o espuma',       d: '6 meses desde la entrega.' },
  { v: 'otro',        t: 'Otro',                d: 'Accesorios, colchones (los responde el proveedor)…' },
]

export const LINEAS = [
  { v: 'elite_promocional', t: 'Élite / promocional (5 años)' },
  { v: 'economica',         t: 'Económica (2 años)' },
]

export const PREFERENCIAS = [
  { v: 'arreglar',      t: 'Que lo arreglen' },
  { v: 'cambiar_mismo', t: 'Otro igual' },
  { v: 'cambiar_otro',  t: 'Otro producto' },
  { v: 'reembolso',     t: 'Que le devuelvan la plata' },
]

export const PREFERENCIA_TEXTO = Object.fromEntries(PREFERENCIAS.map(p => [p.v, p.t.toLowerCase()]))

export const DECISION_TEXTO = {
  taller:       'Se arregla en el taller',
  domicilio:    'Se arregla en la casa del cliente',
  cambio_mismo: 'Se cambia por otro igual',
  cambio_otro:  'Se cambia por otro producto',
  reembolso:    'Se le devuelve la plata',
  no_procede:   'No procede',
}

export function fechaCorta(f) {
  if (!f) return ''
  const d = new Date(String(f).length === 10 ? f + 'T00:00:00' : f)
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
}

export function pesos(n) {
  return '$' + Math.round(Math.abs(n ?? 0)).toLocaleString('es-CO')
}

/** Días que faltan para una fecha (negativo si ya pasó), en el día de hoy local. */
export function diasPara(f) {
  if (!f) return null
  const hoy = new Date(); hoy.setHours(0, 0, 0, 0)
  const d = new Date(f + 'T00:00:00')
  return Math.round((d - hoy) / 86400000)
}
