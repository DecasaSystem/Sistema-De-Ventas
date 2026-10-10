// Formatos y colores de Finanzas.
import { pesos } from '@/utils/pesos'

export { pesos }

/** "$12,3 M", "$850 k": para ejes y tarjetas. El valor completo va en el title. */
export function pesosCorto(n) {
  const v = Number(n ?? 0)
  const abs = Math.abs(v)
  const signo = v < 0 ? '−' : ''
  if (abs >= 1e9) return `${signo}$${(abs / 1e9).toLocaleString('es-CO', { maximumFractionDigits: 1 })} mil M`
  if (abs >= 1e6) return `${signo}$${(abs / 1e6).toLocaleString('es-CO', { maximumFractionDigits: 1 })} M`
  if (abs >= 1e3) return `${signo}$${Math.round(abs / 1e3).toLocaleString('es-CO')} k`
  return `${signo}$${Math.round(abs).toLocaleString('es-CO')}`
}

/** 0.2284 → "22,8 %". null → "—". */
export function pct(x, decimales = 1) {
  if (x === null || x === undefined || Number.isNaN(Number(x))) return '—'
  return `${(Number(x) * 100).toLocaleString('es-CO', { maximumFractionDigits: decimales, minimumFractionDigits: 0 })} %`
}

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

export function mesActual() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export function sumarMeses(mes, n) {
  const [y, m] = mes.split('-').map(Number)
  const d = new Date(y, m - 1 + n, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export function nombreMes(mes, corto = false) {
  const [y, m] = mes.split('-').map(Number)
  const n = MESES[m - 1]
  return corto ? `${n.slice(0, 3)} ${String(y).slice(2)}` : `${n.charAt(0).toUpperCase()}${n.slice(1)} ${y}`
}

export function fechaCorta(f) {
  if (!f) return ''
  return new Date(f + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}

export function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** El modo oscuro es una clase en <html> (stores/appearance). */
export function esOscuro() {
  return document.documentElement.classList.contains('dark')
}

/** Colores de las gráficas, legibles en claro y en oscuro. */
export function coloresGrafica() {
  const oscuro = esOscuro()
  return {
    texto: oscuro ? '#cbd5e1' : '#4b5563',
    rejilla: oscuro ? 'rgba(148,163,184,0.15)' : '#f3f4f6',
    ingresos: '#2563eb',
    egresos: '#f97316',
    utilidad: '#16a34a',
    perdida: '#dc2626',
    nomina: '#8b5cf6',
    comisiones: '#ec4899',
    gastos: '#f59e0b',
    materiales: '#0ea5e9',
    financieros: '#64748b',
    iva: '#94a3b8',
    serie: ['#2563eb', '#f59e0b', '#16a34a', '#8b5cf6', '#ec4899', '#0ea5e9', '#f97316', '#64748b'],
  }
}

export const NOMBRE_AREA = {
  produccion: 'Producción',
  ventas: 'Ventas',
  administracion: 'Administración',
  financiero: 'Financiero',
}

export const FRECUENCIAS = [
  { value: 'mensual', label: 'Mensual' },
  { value: 'bimestral', label: 'Cada 2 meses' },
  { value: 'trimestral', label: 'Cada 3 meses' },
  { value: 'semestral', label: 'Cada 6 meses' },
  { value: 'anual', label: 'Anual' },
  { value: 'quincenal', label: 'Quincenal' },
  { value: 'semanal', label: 'Semanal' },
]

export const METODOS = [
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'efectivo', label: 'Efectivo' },
  { value: 'tarjeta', label: 'Tarjeta' },
  { value: 'otro', label: 'Otro' },
]
