/**
 * Descuentos: el monto en pesos es la fuente de verdad y el porcentaje se deriva
 * de él solo para mostrarlo.
 *
 * El vendedor negocia "te descuento cien mil", no "te descuento 7,4%", así que
 * el monto es lo que se guarda. El porcentaje que se muestra es aproximado por
 * naturaleza: 7,4% de 1.350.000 son 99.900, no los 100.000 reales. Por eso el
 * monto va siempre primero y el porcentaje entre paréntesis.
 */

/** Porcentaje que representa un monto sobre una base. */
export function pctDeMonto(monto, base) {
  const b = Number(base) || 0
  if (b <= 0) return 0
  return (Number(monto) || 0) / b * 100
}

/** Monto que representa un porcentaje de una base, en pesos redondos. */
export function montoDePct(pct, base) {
  return Math.round((Number(base) || 0) * (Number(pct) || 0) / 100)
}

/**
 * Formatea un porcentaje para mostrar: un decimal como máximo y sin el ",0"
 * cuando es entero. 10 → "10", 7.4074 → "7,4", 0 → "0".
 */
export function formatPct(pct) {
  const n = Number(pct) || 0
  const redondeado = Math.round(n * 10) / 10
  return Number.isInteger(redondeado)
    ? String(redondeado)
    : redondeado.toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
}

/** "− $100.000 (7,4%)" para mostrar junto al campo. */
export function resumenDescuento(monto, base) {
  const m = Number(monto) || 0
  if (m <= 0) return ''
  const pesos = '$' + m.toLocaleString('es-CO')
  const pct   = formatPct(pctDeMonto(m, base))
  return `− ${pesos} (${pct}%)`
}
