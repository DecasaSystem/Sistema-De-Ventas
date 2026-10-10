<script setup>
// El estado de resultados (P&G): ¿se ganó o se perdió, mes por mes? Es
// devengado: cada peso va al mes al que pertenece, no al que entró o salió.
import { ref, computed, watch } from 'vue'
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import { getEstadoResultados, exportarResultados, cerrarMes, reabrirMes } from '@/api/finanzas'
import { pesos, pesosCorto, pct, mesActual, sumarMeses, nombreMes, NOMBRE_AREA } from '@/utils/finanzas'
import GraficaFinanzas from './GraficaFinanzas.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({ version: Number })
const toast = useToast()

const meses = ref(6)
const cargando = ref(true)
const datos = ref(null)
const bajando = ref(false)

const hasta = mesActual()
const desde = computed(() => sumarMeses(hasta, -(meses.value - 1)))

async function cargar() {
  cargando.value = true
  try {
    datos.value = (await getEstadoResultados(desde.value, hasta)).data
  } catch {
    toast.error('No se pudo cargar el estado de resultados')
  } finally {
    cargando.value = false
  }
}
watch(() => [meses.value, props.version], cargar, { immediate: true })

const filas = computed(() => datos.value?.meses ?? [])
// Los más recientes primero en la tabla: es lo que se mira.
const columnas = computed(() => [...filas.value].reverse())

const LINEAS = [
  { t: 'Ventas (con IVA)',      v: f => f.ventas, fuerte: true },
  { t: '− IVA',                 v: f => -f.iva, sub: true },
  { t: 'Ingresos netos',        v: f => f.ingresos_netos, fuerte: true },
  { t: '− Materiales (est.)',   v: f => -f.costo_produccion.monto, sub: true },
  { t: 'Utilidad bruta',        v: f => f.utilidad_bruta, fuerte: true, pct: f => f.margenes.bruto },
  { t: '− Sueldos',             v: f => -f.nomina.sueldos, sub: true },
  { t: '− Aportes',             v: f => -f.nomina.aportes, sub: true },
  { t: '− Prestaciones',        v: f => -f.nomina.prestaciones, sub: true },
  { t: '− Comisiones',          v: f => -f.comisiones.total, sub: true },
  { t: '− Gastos fijos',        v: f => -f.gastos.fijos, sub: true },
  { t: '− Gastos variables',    v: f => -f.gastos.variables, sub: true },
  { t: '− Financieros',         v: f => -f.financieros.total, sub: true },
  { t: 'Utilidad operativa',    v: f => f.utilidad_operativa, fuerte: true, final: true, pct: f => f.margenes.operativo },
  { t: 'Entró a caja',          v: f => f.cobrado, nota: true },
]

// ── Gráficas ─────────────────────────────────────────────────────────────────
function armarComposicion(c) {
  if (!filas.value.length) return null
  const etiquetas = filas.value.map(f => nombreMes(f.mes, true))
  const serie = (label, color, fn) => ({ label, backgroundColor: color, data: filas.value.map(fn), stack: 'costos', borderRadius: 2 })
  return {
    type: 'bar',
    data: {
      labels: etiquetas,
      datasets: [
        { type: 'line', label: 'Ingresos netos', data: filas.value.map(f => f.ingresos_netos), borderColor: c.ingresos,
          backgroundColor: c.ingresos, pointRadius: 2, tension: 0.3, order: 0 },
        serie('Materiales', c.materiales, f => f.costo_produccion.monto),
        serie('Nómina', c.nomina, f => f.nomina.total),
        serie('Comisiones', c.comisiones, f => f.comisiones.total),
        serie('Gastos', c.gastos, f => f.gastos.total),
        serie('Financieros', c.financieros, f => f.financieros.total),
      ],
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
        tooltip: { callbacks: { label: (x) => ` ${x.dataset.label}: ${pesos(x.raw)}` } },
      },
      scales: {
        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 } } },
        y: { stacked: true, ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla } },
      },
    },
  }
}

function armarMargenes(c) {
  if (!filas.value.length) return null
  const p = (fn) => filas.value.map(f => (fn(f) === null ? null : Math.round(fn(f) * 1000) / 10))
  return {
    type: 'line',
    data: {
      labels: filas.value.map(f => nombreMes(f.mes, true)),
      datasets: [
        { label: 'Margen bruto', data: p(f => f.margenes.bruto), borderColor: c.materiales, backgroundColor: c.materiales, tension: 0.3 },
        { label: 'Margen operativo', data: p(f => f.margenes.operativo), borderColor: c.utilidad, backgroundColor: c.utilidad, tension: 0.3 },
        { label: 'Nómina / ventas', data: p(f => f.margenes.nomina), borderColor: c.nomina, backgroundColor: c.nomina, borderDash: [4, 3], tension: 0.3 },
      ],
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
        tooltip: { callbacks: { label: (x) => ` ${x.dataset.label}: ${x.raw} %` } },
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
        y: { ticks: { callback: v => `${v} %`, font: { size: 10 } }, grid: { color: c.rejilla } },
      },
    },
  }
}

// Costo por área en todo el rango.
const porArea = computed(() => {
  const t = { produccion: 0, ventas: 0, administracion: 0, financiero: 0 }
  for (const f of filas.value) for (const k in t) t[k] += f.por_area?.[k] ?? 0
  return Object.entries(t).map(([k, v]) => ({ k, nombre: NOMBRE_AREA[k], v })).filter(x => x.v > 0).sort((a, b) => b.v - a.v)
})

function armarAreas(c) {
  if (!porArea.value.length) return null
  return {
    type: 'bar',
    data: {
      labels: porArea.value.map(a => a.nombre),
      datasets: [{ data: porArea.value.map(a => a.v), backgroundColor: [c.materiales, c.comisiones, c.nomina, c.financieros], borderRadius: 4 }],
    },
    options: {
      indexAxis: 'y',
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (x) => ' ' + pesos(x.raw) } } },
      scales: {
        x: { ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla } },
        y: { grid: { display: false }, ticks: { font: { size: 11 } } },
      },
    },
  }
}

// ── Cierre de mes ───────────────────────────────────────────────────────────
// Un mes ya revisado se congela: lo que llegue después no lo mueve.
const cerrables = computed(() => columnas.value.filter(f => !f.parcial))

async function cerrar(f) {
  if (!confirm(`¿Cerrar ${f.nombre}?\n\nSu estado de resultados queda congelado tal como está hoy y no se pueden registrar ni anular gastos de ese mes. Se puede reabrir.`)) return
  try { await cerrarMes(f.mes); toast.success(`${f.nombre} cerrado`); cargar() }
  catch (e) { const err = e.response?.data?.errors; toast.error(err ? Object.values(err)[0][0] : 'No se pudo cerrar') }
}

async function reabrir(f) {
  const motivo = prompt(`¿Por qué se reabre ${f.nombre}? (queda anotado)`)
  if (!motivo?.trim()) return
  try { await reabrirMes(f.mes, motivo.trim()); toast.success(`${f.nombre} reabierto`); cargar() }
  catch (e) { toast.error(e.response?.data?.message || 'No se pudo reabrir') }
}

async function exportar() {
  bajando.value = true
  try {
    const { data } = await exportarResultados(desde.value, hasta)
    const url = URL.createObjectURL(data)
    const a = document.createElement('a')
    a.href = url
    a.download = `estado_resultados_${desde.value}_${hasta}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.error('No se pudo exportar')
  } finally {
    bajando.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-2">
      <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
        <button v-for="n in [3, 6, 12]" :key="n" @click="meses = n"
          :class="['text-xs font-semibold rounded-lg px-3 py-1.5', meses === n ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">
          {{ n }} meses
        </button>
      </div>
      <button @click="exportar" :disabled="bajando" class="flex items-center gap-1 text-xs font-semibold text-blue-600 disabled:opacity-50">
        <ArrowDownTrayIcon class="w-4 h-4" /> Excel
      </button>
    </div>

    <div v-if="cargando && !datos" class="flex justify-center py-16"><AppSpinner /></div>

    <template v-else-if="datos">
      <!-- Totales del rango -->
      <div class="grid grid-cols-3 gap-2">
        <div class="bg-white rounded-xl shadow-sm p-3">
          <p class="text-[11px] text-gray-400">Ingresos netos</p>
          <p class="text-sm font-bold text-gray-800">{{ pesosCorto(datos.total.ingresos_netos) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3">
          <p class="text-[11px] text-gray-400">Utilidad</p>
          <p :class="['text-sm font-bold', datos.total.utilidad_operativa >= 0 ? 'text-green-600' : 'text-red-600']">{{ pesosCorto(datos.total.utilidad_operativa) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3">
          <p class="text-[11px] text-gray-400">Margen</p>
          <p class="text-sm font-bold text-gray-800">{{ pct(datos.total.margen_operativo) }}</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-800">Ingresos contra costos</p>
        <p class="text-xs text-gray-400 mb-2">Si la línea azul pasa por encima de las barras, ese mes se ganó plata.</p>
        <GraficaFinanzas :armar="armarComposicion" :datos="filas" alto="h-64" descripcion="Ingresos netos contra composición de costos por mes" />
      </div>

      <!-- La tabla: primera columna fija, las demás se deslizan en el celular. -->
      <div class="bg-white rounded-xl shadow-sm">
        <p class="text-sm font-semibold text-gray-800 px-4 pt-4">Estado de resultados</p>
        <p class="text-[11px] text-gray-400 px-4 mb-2">Desliza de lado para ver más meses.</p>
        <div class="overflow-x-auto">
          <table class="text-xs w-full">
            <thead>
              <tr class="text-gray-400">
                <th class="sticky left-0 bg-white text-left font-semibold px-4 py-2 min-w-[9.5rem]">Concepto</th>
                <th v-for="f in columnas" :key="f.mes" class="text-right font-semibold px-3 py-2 whitespace-nowrap">
                  {{ nombreMes(f.mes, true) }}<span v-if="f.parcial" class="text-amber-500">*</span><span v-if="f.cerrado" title="Mes cerrado"> 🔒</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in LINEAS" :key="l.t" :class="[l.final && 'border-t border-gray-200', l.nota && 'border-t border-dashed border-gray-200']">
                <td :class="['sticky left-0 bg-white px-4 py-1.5', l.fuerte ? 'font-semibold text-gray-800' : 'text-gray-500', l.nota && 'italic']">{{ l.t }}</td>
                <td v-for="f in columnas" :key="f.mes" class="text-right px-3 py-1.5 whitespace-nowrap">
                  <span :class="[l.fuerte ? 'font-semibold' : '', l.final ? (l.v(f) >= 0 ? 'text-green-600' : 'text-red-600') : 'text-gray-700']" :title="pesos(l.v(f))">
                    {{ pesosCorto(l.v(f)) }}
                  </span>
                  <span v-if="l.pct" class="block text-[10px] text-gray-400">{{ pct(l.pct(f)) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="text-[10px] text-gray-400 px-4 py-3">
          * Mes en curso. Materiales estimados con las fichas técnicas; comisiones sin pagar, al día de hoy.
          <template v-if="filas.some(f => f.sin_gastos_registrados)">Los meses sin gastos registrados salen con más utilidad de la real.</template>
        </p>
      </div>

      <!-- Cierre de mes -->
      <div v-if="cerrables.length" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-800">Cierre de mes</p>
        <p class="text-xs text-gray-400 mb-2">Cuando revises un mes (con el contador), ciérralo: sus cifras quedan congeladas y no se le pueden cargar gastos tarde.</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="f in cerrables" :key="f.mes" class="py-2 flex items-center justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm text-gray-800">{{ f.nombre }}</p>
              <p class="text-[11px] text-gray-400 truncate">
                <template v-if="f.cerrado">🔒 Cerrado<template v-if="f.cerrado_por"> por {{ f.cerrado_por }}</template></template>
                <template v-else>Abierto · utilidad {{ pesosCorto(f.utilidad_operativa) }}</template>
              </p>
            </div>
            <button v-if="f.cerrado" @click="reabrir(f)" class="text-xs font-semibold text-gray-500 shrink-0">Reabrir</button>
            <button v-else @click="cerrar(f)" class="text-xs font-semibold text-blue-600 shrink-0">Cerrar</button>
          </li>
        </ul>
      </div>

      <div class="grid sm:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-800">Márgenes</p>
          <p class="text-xs text-gray-400 mb-2">Qué parte de cada peso neto queda, y cuánto se lleva la nómina.</p>
          <GraficaFinanzas :armar="armarMargenes" :datos="filas" alto="h-52" descripcion="Márgenes por mes" />
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-800">¿Dónde se va la plata?</p>
          <p class="text-xs text-gray-400 mb-2">Costo por área en los {{ meses }} meses.</p>
          <GraficaFinanzas :armar="armarAreas" :datos="porArea" alto="h-52" descripcion="Costos por área" />
        </div>
      </div>
    </template>
  </div>
</template>
