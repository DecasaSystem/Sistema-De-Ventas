/**
 * Las reglas de comisión que rigen en un mes, para los TEXTOS.
 *
 * La Ayuda, el desglose, los totales de la tienda y el Excel decían "5 %",
 * "÷ 1,19" y "por días" escritos a mano. Desde que las reglas se cambian en
 * Comisiones → Reglas, con una regla cambiada la pantalla habría dicho 5 %
 * mientras se pagaba 6 % (Fase 3 de docs/plan-comisiones-personalizables.md).
 *
 * El cálculo sigue en el servidor; esto solo pone en palabras lo que rige.
 * Se resuelve igual que allá: persona > tienda > general.
 *
 * Si la petición falla (sin red, sin permiso), se usa DEFECTO: lo que hacía el
 * código antes de poder cambiarlo, que es lo que decían los textos.
 */
import api from '@/api'

/** Lo mismo que el catálogo del servidor (App\Support\ReglasComision) sin filas. */
export const DEFECTO = Object.freeze({
  pct_pool: 0.05,
  pct_individual: 0.05,
  pct_restauracion: 0.05,
  iva_divisor: 1.19,
  iva_en_venta: true,
  iva_en_restauracion: false,
  franquicia_pct: 0.055,
  franquicia_metodos: ['tarjeta', 'addi'],
  req_pagado_pct: 0.5,
  req_pagado_restauracion: true,
  meta_req_pagado_pct: 0.5,
  dia_pago: 20,
  meses_espera: 1,
  reparto_pool: 'dias',
  restauracion_suma_meta: false,
  arrastra_deuda_trimestre: true,
  formula_pool: 'excedente',
  tramos_pool: [{ desde: 1, pct: 0.05 }],
  bono_meta: 0,
  compartida_parte_vendedor: 0.5,
  restauracion_se_reparte: true,
  reparto_restauracion: 'iguales',
  parte_autor_restauracion: 0.5,
  indep_pct_venta: 0.05,
  indep_pct_bolson: 0.05,
  bolson_modo: 'cada_uno_completo',
  entra_al_bolson: true,
  bolson_incluye_almacenes: true,
  almacen_pct: 0.05,
  almacen_fraccion_meta: 0.5,
})

const SIN_CAMBIOS = Object.freeze({ mes: null, general: DEFECTO, excepciones: [] })

// Una vez por mes mientras la app esté abierta: las reglas casi nunca cambian
// y cada tarjeta de Comisiones las pide.
const memoria = new Map()

/** { mes, general, excepciones } del servidor, o las de siempre si no se pudo. */
export function cargarReglasVigentes(mes) {
  const clave = mes || 'hoy'
  if (!memoria.has(clave)) {
    memoria.set(clave, api.get('/comisiones/reglas/vigentes', { params: mes ? { mes } : {}, silencioso: true })
      .then(r => ({ ...r.data, general: { ...DEFECTO, ...(r.data?.general ?? {}) } }))
      .catch(() => { memoria.delete(clave); return SIN_CAMBIOS }))
  }
  return memoria.get(clave)
}

/** Olvida lo cargado: alguien acaba de cambiar una regla. */
export function olvidarReglasVigentes() {
  memoria.clear()
}

/**
 * Las reglas de un caso: la general, encima la de su tienda y encima la de
 * la persona. Sin tienda ni persona, la general.
 */
export function reglasPara(vigentes, { tiendaId = null, usuarioId = null } = {}) {
  const out = { ...DEFECTO, ...(vigentes?.general ?? {}) }
  const ex = vigentes?.excepciones ?? []
  for (const e of ex) {
    if (e.alcance === 'tienda' && tiendaId != null && Number(e.alcance_id) === Number(tiendaId)) out[e.clave] = e.valor
  }
  for (const e of ex) {
    if (e.alcance === 'usuario' && usuarioId != null && Number(e.alcance_id) === Number(usuarioId)) out[e.clave] = e.valor
  }
  return out
}

// ── Para escribirlas ─────────────────────────────────────────────────────────

/** 0,05 → "5", 0,055 → "5,5" (sin el signo: cada pantalla pone "5%" o "5 %"). */
export function num(fraccion) {
  return (Math.round(Number(fraccion) * 10000) / 100).toLocaleString('es-CO', { maximumFractionDigits: 2 })
}

/** 1.19 → "1,19". */
export function divisor(v) {
  return Number(v).toLocaleString('es-CO', { maximumFractionDigits: 4 })
}

/**
 * La cuenta de una venta: "÷ 1,19 × 5%", o "× 5%" si a la venta no se le
 * quita el IVA. `espacio` pone "5 %" como en la Ayuda.
 */
export function cuentaVenta(r, clavePct, { espacio = false } = {}) {
  const pct = num(r[clavePct]) + (espacio ? ' %' : '%')
  return r.iva_en_venta ? `÷ ${divisor(r.iva_divisor)} × ${pct}` : `× ${pct}`
}

/** ¿El pool de estas reglas es el de siempre (un % sobre lo que pasa de la meta, sin bono)? */
export function poolDeSiempre(r) {
  return r.formula_pool !== 'tramos' && !(Number(r.bono_meta) > 0)
}

/** "100 %: 5% · 120 %: 6%": los tramos del pool en palabras. */
export function tramosTexto(r, { espacio = false } = {}) {
  const p = espacio ? ' %' : '%'
  return (r.tramos_pool ?? []).map(t => `desde el ${num(t.desde)} %: ${num(t.pct)}${p}`).join(' · ')
}

/** "$300.000" del bono al llegar a la meta. */
export function bonoTexto(r) {
  return '$' + Math.round(Number(r.bono_meta) || 0).toLocaleString('es-CO')
}

/**
 * La cuenta del pool después de "(ventas − meta)": "÷ 1,19 × 5%", o "por
 * tramos (…)" si la tienda paga por tramos; con " + bono de $300.000" si
 * tiene bono (pieza 8). Solo tiendas mensuales: en una trimestral el pool es
 * siempre el de siempre (cuentaVenta(r, 'pct_pool')).
 */
export function cuentaPool(r, { espacio = false } = {}) {
  const base = r.formula_pool === 'tramos'
    ? `por tramos (${tramosTexto(r, { espacio })})`
    : cuentaVenta(r, 'pct_pool', { espacio })
  return Number(r.bono_meta) > 0 ? `${base} + bono de ${bonoTexto(r)}` : base
}

/** "el 20 del mes siguiente", con el día y la espera que rigen. */
export function cuandoSePaga(r) {
  const dia = Number(r.dia_pago)
  const espera = Number(r.meses_espera)
  if (espera === 0) return `el ${dia} del mismo mes`
  if (espera === 1) return `el ${dia} del mes siguiente`
  return `el ${dia}, ${espera} meses después`
}

/** "tarjeta o Addi" con los medios que pagan franquicia. */
export function mediosConFranquicia(r) {
  const nombres = { tarjeta: 'tarjeta', addi: 'Addi', efectivo: 'efectivo', transferencia: 'transferencia', otro: 'otro' }
  const lista = (r.franquicia_metodos ?? []).map(m => nombres[m] ?? m)
  if (lista.length <= 1) return lista.join('')
  return lista.slice(0, -1).join(', ') + ' o ' + lista[lista.length - 1]
}

/** "la mitad" si es 50 %, si no "el 60 %". Los textos decían "la mitad". */
export function laParte(fraccion, { espacio = false } = {}) {
  return Number(fraccion) === 0.5 ? 'la mitad' : `el ${num(fraccion)}${espacio ? ' %' : '%'}`
}

/** "la mitad pagada" o "el 60 % pagado": con "la mitad" el participio va en femenino. */
export function partePagada(fraccion, opciones = {}) {
  return Number(fraccion) === 0.5 ? 'la mitad pagada' : `${laParte(fraccion, opciones)} pagado`
}
