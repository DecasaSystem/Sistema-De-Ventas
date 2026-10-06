/**
 * Estado y cálculos compartidos del módulo de Costos de producción.
 *
 * Las tarifas (oficios, incentivo por hora, trabajos) y el factor de venta los
 * usan varias pestañas a la vez —Productos para agregar mano de obra, Márgenes
 * para colorear, Tarifas para editarlos—, así que viven aquí una sola vez.
 */
import { ref, computed } from 'vue'
import { getCostos } from '@/api/configuracion'

const salarios       = ref([])
const procesos       = ref([])
const factorVenta    = ref(2)
const tarifasCargadas = ref(false)
const cargandoTarifas = ref(false)

/** Trae oficios, trabajos y factor. Con `forzar` ignora lo que ya hay. */
async function cargarTarifas(forzar = false) {
  if (tarifasCargadas.value && !forzar) return
  cargandoTarifas.value = true
  try {
    const { data } = await getCostos()
    salarios.value = data.salarios.map(s => ({
      ...s,
      _salario:    Number(s.salario_mensual) || 0,
      _dias:       String(s.dias_laborales_mes || 26),
      _tarifaHora: Number(s.tarifa_hora) || 0,
    }))
    // En la BD el tiempo se guarda en días (dias_por_unidad); en pantalla, en horas
    procesos.value = data.procesos.map(p => ({ ...p, _horas: String(Math.round((p.dias_por_unidad ?? 0) * 8 * 100) / 100) }))
    if (data.factor_venta_sugerido) factorVenta.value = Number(data.factor_venta_sugerido)
    tarifasCargadas.value = true
  } finally {
    cargandoTarifas.value = false
  }
}

/** Incentivo por hora de un oficio: lo que cuesta cada hora de mano de obra. */
function tarifaHoraDe(cargo) {
  const s = salarios.value.find(s => s.cargo === cargo)
  return s ? (parseFloat(s._tarifaHora) || 0) : 0
}

/** Lo que cuesta un trabajo: incentivo/hora × horas por unidad. */
function costoProceso(p) {
  return Math.round(tarifaHoraDe(p.cargo) * (parseFloat(p._horas) || 0))
}

/** Trabajos agrupados por oficio: [[cargo, [procesos]], …] */
const procesosPorCargo = computed(() => {
  const grupos = {}
  for (const p of procesos.value) {
    const key = p.cargo || 'sin_cargo'
    ;(grupos[key] ??= []).push(p)
  }
  return Object.entries(grupos)
})

/** "tapicero_auxiliar" → "Tapicero auxiliar" */
function nombreCargo(cargo) {
  if (!cargo || cargo === 'sin_cargo') return 'Sin oficio'
  const t = cargo.replace(/_/g, ' ')
  return t.charAt(0).toUpperCase() + t.slice(1)
}

/** Nombre corto de un trabajo para mostrarlo en listas. */
function nombreProceso(p) {
  return p.descripcion || p.proceso.replace(/_/g, ' ')
}

/**
 * Margen de un costo contra un precio de venta.
 *   nivel: 'perdida' cuesta más de lo que se cobra · 'bajo' por debajo del factor
 *          sugerido en Tarifas · 'ok' igual o por encima.
 * null si falta el precio o el costo.
 */
function margenDe(costo, precio) {
  const c = Number(costo) || 0
  const p = Number(precio) || 0
  if (!c || !p) return null
  const factor = p / c
  return {
    factor,
    pct:      ((p - c) / p) * 100,
    ganancia: p - c,
    nivel:    p <= c ? 'perdida' : factor < (Number(factorVenta.value) || 0) ? 'bajo' : 'ok',
  }
}

/** Clases del distintivo de margen. Mismos colores que los estados del resto de la app. */
const COLOR_MARGEN = {
  perdida: 'bg-red-100 text-red-700',
  bajo:    'bg-amber-100 text-amber-700',
  ok:      'bg-green-100 text-green-700',
}
const TEXTO_MARGEN = {
  perdida: 'Se vende a pérdida',
  bajo:    'Por debajo del factor sugerido',
  ok:      'Margen sano',
}

/** "$ 1.240.000" sin decimales: las cantidades fraccionarias dejaban "$ 1.562,5". */
function formatPeso(valor) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', minimumFractionDigits: 0, maximumFractionDigits: 0,
  }).format(Math.round(Number(valor) || 0))
}

/** 2 → "2", 0.125 → "0,125" */
function formatCantidad(valor) {
  const n = parseFloat(valor) || 0
  return n.toLocaleString('es-CO', { maximumFractionDigits: 4 })
}

/** ×1,6 */
function formatFactor(f, decimales = 1) {
  return '×' + Number(f).toLocaleString('es-CO', { minimumFractionDigits: decimales, maximumFractionDigits: decimales })
}

/** "2026-10-06 12:00:00" → "6 oct 2026". Safari no lee la fecha con espacio. */
function formatFecha(f) {
  const d = f ? new Date(String(f).replace(' ', 'T')) : null
  return d && !isNaN(d) ? d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}

/** Cuántos problemas tiene una ficha del listado (ítems sin precio, materiales fuera del catálogo). */
function porRevisar(f) {
  return (Number(f.sin_precio) || 0) + (Number(f.fuera_catalogo) || 0)
}

export function useCostos() {
  return {
    salarios, procesos, factorVenta, tarifasCargadas, cargandoTarifas, cargarTarifas,
    tarifaHoraDe, costoProceso, procesosPorCargo, nombreCargo, nombreProceso,
    margenDe, COLOR_MARGEN, TEXTO_MARGEN,
    formatPeso, formatCantidad, formatFactor, formatFecha, porRevisar,
  }
}
