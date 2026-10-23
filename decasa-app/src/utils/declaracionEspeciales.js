// La declaración para la fabricación de muebles con diseños y medidas
// especiales (dueño, 2026-10-21). El vendedor escribe en la orden cómo va el
// mueble que no está en el catálogo (la FICHA) y sube los bocetos; el cliente
// lo aprueba producto por producto y lo firma con el anexo (una sola firma).
//
// Las reglas viven en el servidor (App\Support\DeclaracionEspeciales); esto
// es lo mismo dicho para la pantalla: qué campos tiene la ficha, qué le falta
// y qué productos la piden según Gestión → Ajustes.

import { ref } from 'vue'
import { getAjustesSistema } from '@/api/ajustes'

/** Los campos de la ficha, en el orden del papel (mismos que el servidor). */
export const CAMPOS_FICHA = [
  { key: 'tipo_mueble', label: 'Tipo de mueble',    grupo: 'tipo',        placeholder: 'ej: biblioteca empotrada, sofá en L' },
  { key: 'madera',      label: 'Madera',            grupo: 'descripcion', placeholder: 'ej: cedro, pino, sin madera a la vista' },
  { key: 'tela',        label: 'Tela',              grupo: 'descripcion', placeholder: 'ej: lino gris claro (referencia…)' },
  { key: 'relleno',     label: 'Relleno',           grupo: 'descripcion', placeholder: 'ej: espuma 30 kg + guata' },
  { key: 'pintura',     label: 'Pintura / acabado', grupo: 'descripcion', placeholder: 'ej: laca blanca mate' },
  { key: 'ancho',       label: 'Ancho',             grupo: 'medidas',     placeholder: 'ej: 240 cm' },
  { key: 'largo',       label: 'Largo',             grupo: 'medidas',     placeholder: 'ej: 180 cm' },
  { key: 'alto',        label: 'Alto',              grupo: 'medidas',     placeholder: 'ej: 85 cm' },
  { key: 'profundidad', label: 'Profundidad',       grupo: 'medidas',     placeholder: 'ej: 95 cm' },
  { key: 'detalles',    label: 'Detalles',          grupo: 'descripcion', placeholder: 'Todo lo que el cliente tiene que saber: forma, cajones, puertas, patas, lo que NO lleva…' },
]

export const MIN_MEDIDAS = 2

const lleno = v => typeof v === 'string' ? v.trim() !== '' : (typeof v === 'number' && Number.isFinite(v))

/** Solo los campos de la ficha, sin espacios de más. null si está vacía. */
export function limpiarFicha(ficha) {
  if (!ficha || typeof ficha !== 'object') return null
  const out = {}
  for (const c of CAMPOS_FICHA) {
    const v = ficha[c.key]
    if (lleno(v)) out[c.key] = String(v).trim()
  }
  return Object.keys(out).length ? out : null
}

/** Qué le falta a la ficha para que el cliente la pueda aprobar ('' si nada). */
export function faltaEnFicha(ficha) {
  const f = limpiarFicha(ficha) ?? {}
  if (!f.tipo_mueble) return 'el tipo de mueble'
  if (!CAMPOS_FICHA.some(c => c.grupo === 'descripcion' && f[c.key])) return 'la descripción (madera, tela, relleno, pintura o detalles)'
  if (CAMPOS_FICHA.filter(c => c.grupo === 'medidas' && f[c.key]).length < MIN_MEDIDAS) {
    return `las medidas (al menos ${MIN_MEDIDAS}: ancho, largo, alto o profundidad)`
  }
  return ''
}

/**
 * Para no escribir dos veces: lo que el vendedor ya puso en las
 * especificaciones (tela, medidas) arranca la ficha. Solo llena lo vacío.
 */
export function fichaDesdeSpecs(ficha, specs, nombre) {
  const f = { ...(ficha ?? {}) }
  const s = specs ?? {}
  const cm = v => (lleno(v) ? `${String(v).trim()}${/^\d+([.,]\d+)?$/.test(String(v).trim()) ? ' cm' : ''}` : '')
  const poner = (k, v) => { if (!lleno(f[k]) && lleno(v)) f[k] = String(v) }
  poner('tipo_mueble', nombre)
  poner('tela', s.tela || s.tela_cabecero)
  poner('madera', /madera/i.test(String(s.material ?? '')) ? s.material : '')
  poner('pintura', s.color_material || s.acabado)
  poner('ancho', cm(s.ancho_cm))
  poner('largo', cm(s.largo_cm || s.largo_total_cm))
  poner('alto', cm(s.alto_cm))
  return f
}

/** La ficha en grupos, como el papel (I descripción, II medidas, III tipo). */
export function gruposDeFicha(ficha) {
  const f = limpiarFicha(ficha) ?? {}
  const g = { descripcion: [], medidas: [], tipo: [] }
  for (const c of CAMPOS_FICHA) if (f[c.key]) g[c.grupo].push({ campo: c.key, label: c.label, value: f[c.key] })
  return g
}

// ── Qué productos la piden (Gestión → Ajustes) ───────────────────────────────

// Lo de fábrica mientras se cargan: los especiales sí, los personalizados no.
const ajustes = ref({ declaracion_disenos_especiales: true, declaracion_personalizados: false })
let cargados = null

/** Los interruptores, leídos una vez por sesión de la app. */
export function useAjustesDeclaracion() {
  if (!cargados) {
    cargados = getAjustesSistema({ silencioso: true })
      .then(({ data }) => { ajustes.value = { ...ajustes.value, ...data } })
      .catch(() => { cargados = null })
  }
  return ajustes
}

/** ¿Este tipo de producto (OrdenItem::tipo_item / tipoDeItemCarrito) la pide? */
export function aplicaDeclaracion(tipo, a = ajustes.value) {
  if (tipo === 'diseno_especial') return !!a.declaracion_disenos_especiales
  if (tipo === 'personalizado')   return !!a.declaracion_personalizados
  return false
}

/**
 * La ficha en un renglón, para el taller: "Biblioteca · Cedro · Laca blanca ·
 * Ancho 240 cm · Alto 210 cm". Es lo que el cliente aprobó y firmó.
 */
export function fichaEnUnaLinea(ficha) {
  const f = limpiarFicha(ficha)
  if (!f) return ''
  return CAMPOS_FICHA.filter(c => f[c.key])
    .map(c => (c.grupo === 'medidas' ? `${c.label} ${f[c.key]}` : f[c.key]))
    .join(' · ')
}
