<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Chart } from 'chart.js/auto'

import {
  getPanel, getTendencia, getStatsVendedores,
  getStatsTiendas, getProductos, getCartera, getStatsCategorias, getInteresados,
  getStatsConductores,
} from '@/api/stats'
import api from '@/api'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import { useAuthStore } from '@/stores/auth'
import { StarIcon } from '@heroicons/vue/24/solid'

const router = useRouter()
const auth = useAuthStore()

const esPrimeroDelMes = new Date().getDate() === 1

// ── Filtros globales ──────────────────────────────────────────────────────────
const presets = [
  { label: 'Hoy',      value: 'hoy' },
  { label: 'Semana',   value: 'semana' },
  { label: 'Mes',      value: 'mes' },
  { label: 'Mes ant.', value: 'mes_anterior' },
  { label: 'Año',      value: 'anio' },
]
const periodoActivo = ref('mes')
const modoCustom    = ref(false)
const desdeCustom   = ref('')
const hastaCustom   = ref('')
const tiendaFiltro  = ref('')
const tiendas       = ref([])

function paramsFiltro() {
  const p = modoCustom.value && desdeCustom.value && hastaCustom.value
    ? { desde: desdeCustom.value, hasta: hastaCustom.value }
    : { periodo: periodoActivo.value }
  if (tiendaFiltro.value) p.tienda_id = tiendaFiltro.value
  return p
}
function selPreset(v) { periodoActivo.value = v; modoCustom.value = false; cargarTodo() }
function aplicarCustom() { if (desdeCustom.value && hastaCustom.value) { modoCustom.value = true; cargarTodo() } }

// ── Tabs ──────────────────────────────────────────────────────────────────────
const todosTabs = [
  { id: 'resumen',          label: 'Resumen' },
  { id: 'vendedores',       label: 'Vendedores' },
  { id: 'tiendas',          label: 'Tiendas' },
  { id: 'productos',        label: 'Productos' },
  { id: 'cartera',          label: 'Cartera' },
  { id: 'produccion',       label: 'Producción' },
  { id: 'canales',           label: 'Canales' },
  { id: 'conductores',      label: 'Conductores' },
  { id: 'interesados',      label: 'Interesados' },
  ...(esPrimeroDelMes ? [{ id: 'resumen-mensual', label: 'Resumen mensual' }] : []),
]

const tabsVisibles = computed(() => {
  if (auth.isSupervisor) return todosTabs
  return todosTabs.filter(t => ['resumen', 'productos', 'cartera', 'produccion'].includes(t.id))
})
const tabActivo = ref('resumen')

async function switchTab(id) {
  tabActivo.value = id
  if (id === 'resumen-mensual') cargarResumenMensual()
  if (id === 'interesados' && !interesados.value) cargarInteresados()
  if (id === 'conductores') cargarConductores()
  if (id === 'canales') cargarCanales()
  await nextTick()
  rebuildCharts(id)
}

// ── Datos ─────────────────────────────────────────────────────────────────────
const loading    = ref(true)
const panel      = ref(null)
const tendencia  = ref(null)
const vendedores = ref([])
const tiendasData = ref([])
const productos  = ref([])
const categorias  = ref([])
const categoriaFiltro = ref('')
const busquedaProducto = ref('')
const cartera    = ref([])
const retrasos   = ref([])
const interesados = ref(null)

const tiendaFiltroNombre = computed(() => {
  if (!tiendaFiltro.value) return null
  return tiendas.value.find(t => String(t.id) === String(tiendaFiltro.value))?.nombre ?? null
})

let _busquedaTimer = null
function onBusquedaInput() {
  clearTimeout(_busquedaTimer)
  _busquedaTimer = setTimeout(() => buscarProducto(), 350)
}

async function buscarProducto() {
  const p = { ...paramsFiltro() }
  if (categoriaFiltro.value) p.categoria = categoriaFiltro.value
  if (busquedaProducto.value.trim()) p.q = busquedaProducto.value.trim()
  const { data } = await getProductos({ ...p, limit: busquedaProducto.value.trim() ? 50 : 20 })
  productos.value = data
}

// ── Resumen mensual (solo 1° de mes, supervisor) ──────────────────────────────
const resumenMensual    = ref(null)
const cargandoResumen   = ref(false)

async function cargarResumenMensual() {
  if (resumenMensual.value) return
  cargandoResumen.value = true
  try {
    const { data } = await api.get('/reportes/resumen-mensual')
    resumenMensual.value = data
  } catch {} finally {
    cargandoResumen.value = false
  }
}

const canalesData         = ref(null)
const cargandoCanales     = ref(false)

const CANAL_LABELS = {
  fisica:     'Física',
  whatsapp:   'WhatsApp',
  instagram:  'Instagram',
  facebook:   'Facebook',
  pagina:     'Página web',
  red_social: 'Red social',
  otro:       'Otro',
}
const CANAL_COLORS = {
  fisica:     '#2563eb',
  whatsapp:   '#16a34a',
  instagram:  '#e1306c',
  facebook:   '#1877f2',
  pagina:     '#7c3aed',
  red_social: '#d97706',
  otro:       '#6b7280',
}

const conductores         = ref(null)
const cargandoConductores = ref(false)

async function cargarCanales() {
  cargandoCanales.value = true
  try {
    const p = paramsFiltro()
    const { data } = await api.get('/reportes/canales', { params: p })
    canalesData.value = data
  } catch {} finally {
    cargandoCanales.value = false
  }
}

async function cargarConductores() {
  cargandoConductores.value = true
  try {
    const p = paramsFiltro()
    const { data } = await getStatsConductores(p)
    conductores.value = data
  } catch {} finally {
    cargandoConductores.value = false
  }
}

const cargandoInteresados = ref(false)
async function cargarInteresados() {
  cargandoInteresados.value = true
  try {
    const f = resuelveFechas()
    const { data } = await getInteresados({
      tienda_id: tiendaFiltro.value || undefined,
      desde: f.desde,
      hasta: f.hasta,
    })
    interesados.value = data
  } catch {} finally {
    cargandoInteresados.value = false
  }
}

async function exportarResumenMensual() {
  try {
    const res = await api.get('/reportes/resumen-mensual/exportar', { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const a   = document.createElement('a')
    a.href    = url
    a.download = `decasa_resumen_mensual_${resumenMensual.value?.desde ?? 'mes_anterior'}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) { console.error('Error al exportar resumen mensual:', e) }
}

// ── Canvas refs + instancias ──────────────────────────────────────────────────
const lineCanvas  = ref(null)
const vendCanvas  = ref(null)
const tiendCanvas = ref(null)
const donaCanvas  = ref(null)
let lineChart = null
let vendChart = null
let tiendChart = null
let donaChart  = null

// ── Formateo ──────────────────────────────────────────────────────────────────
function cop(n) {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n ?? 0)
}
function copCompact(v) {
  if (v >= 1_000_000) return `$${(v / 1_000_000).toFixed(1)}M`
  if (v >= 1_000)     return `$${(v / 1_000).toFixed(0)}K`
  return `$${v}`
}
function varColor(pct) {
  if (pct === null || pct === undefined) return 'text-gray-400'
  return pct >= 0 ? 'text-green-600' : 'text-red-500'
}
function varLabel(pct) {
  if (pct === null || pct === undefined) return 'Sin datos anteriores'
  return (pct >= 0 ? '↑ ' : '↓ ') + Math.abs(pct) + '% vs período anterior'
}
function diasColor(d) {
  if (d > 15) return 'bg-red-100 text-red-700'
  if (d > 7)  return 'bg-orange-100 text-orange-700'
  return 'bg-yellow-100 text-yellow-700'
}
function retrasoColor(d) {
  if (d > 7)  return 'bg-red-100 text-red-700'
  if (d >= 3) return 'bg-orange-100 text-orange-700'
  return 'bg-yellow-100 text-yellow-700'
}

// ── Carga de datos ────────────────────────────────────────────────────────────
async function cargarTodo() {
  loading.value = true
  categoriaFiltro.value = ''
  busquedaProducto.value = ''
  interesados.value = null
  conductores.value = null
  canalesData.value = null
  if (tabActivo.value === 'interesados') cargandoInteresados.value = true
  if (tabActivo.value === 'conductores') cargarConductores()
  if (tabActivo.value === 'canales')    cargarCanales()
  try {
    const p = paramsFiltro()
    const promises = [
      getPanel(p),
      getTendencia(p),
      auth.isSupervisor ? getStatsVendedores(p) : Promise.resolve({ data: [] }),
      auth.isSupervisor ? getStatsTiendas(p) : Promise.resolve({ data: [] }),
      getProductos({ ...p, limit: 10 }),
      getCartera(p),
      api.get('/reportes/retrasos'),
      getStatsCategorias(p),
    ]
    const [panelRes, tendRes, vendRes, tiendRes, prodRes, cartRes, retRes, catRes] = await Promise.all(promises)
    panel.value       = panelRes.data
    tendencia.value   = tendRes.data
    vendedores.value  = vendRes.data
    tiendasData.value = tiendRes.data
    productos.value   = prodRes.data
    cartera.value     = cartRes.data
    retrasos.value    = retRes.data
    categorias.value  = catRes.data
  } finally {
    loading.value = false
  }
  await nextTick()
  rebuildCharts(tabActivo.value)
  if (tabActivo.value === 'interesados') cargarInteresados()
}

async function filtrarPorCategoria(cat) {
  categoriaFiltro.value = cat
  busquedaProducto.value = ''
  const p = { ...paramsFiltro(), ...(cat ? { categoria: cat } : {}) }
  const { data } = await getProductos({ ...p, limit: 20 })
  productos.value = data
  await nextTick()
  buildDona()
}

function rebuildCharts(tab) {
  if (tab === 'resumen')    buildLine()
  if (tab === 'vendedores') buildVend()
  if (tab === 'tiendas')    buildTiend()
  if (tab === 'productos')  buildDona()
}

// ── Chart: línea tendencia (Resumen) ─────────────────────────────────────────
function buildLine() {
  if (lineChart) { lineChart.destroy(); lineChart = null }
  if (!lineCanvas.value || !tendencia.value) return
  const { labels, cobrado, ordenes_valor } = tendencia.value
  lineChart = new Chart(lineCanvas.value, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Cobrado', data: cobrado, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.08)', fill: true, tension: 0.4, pointRadius: labels.length > 20 ? 0 : 3 },
        { label: 'Valor órdenes', data: ordenes_valor, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.07)', fill: true, tension: 0.4, pointRadius: labels.length > 20 ? 0 : 3 },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } }, tooltip: { callbacks: { label: (c) => ` ${cop(c.raw)}` } } },
      scales: {
        x: { ticks: { font: { size: 10 }, maxTicksLimit: 8 }, grid: { display: false } },
        y: { ticks: { callback: copCompact, font: { size: 10 } }, grid: { color: '#f3f4f6' } },
      },
    },
  })
}

// ── Chart: barras horizontales vendedores ─────────────────────────────────────
const vendMetrica = ref('total_vendido') // 'total_vendido' | 'ingresos'

function buildVend() {
  if (vendChart) { vendChart.destroy(); vendChart = null }
  if (!vendCanvas.value || !vendedores.value.length) return
  const top = vendedores.value.slice(0, 8)
  const usaTotal = vendMetrica.value === 'total_vendido'
  vendChart = new Chart(vendCanvas.value, {
    type: 'bar',
    data: {
      labels: top.map(v => v.nombre.length > 18 ? v.nombre.slice(0, 16) + '…' : v.nombre),
      datasets: [{ label: usaTotal ? 'Total vendido' : 'Cobrado', data: top.map(v => usaTotal ? v.total_vendido : v.ingresos), backgroundColor: usaTotal ? '#16a34a' : '#2563eb', borderRadius: 4 }],
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ` ${cop(c.raw)}` } } },
      scales: { x: { ticks: { callback: copCompact, font: { size: 10 } }, grid: { color: '#f3f4f6' } }, y: { ticks: { font: { size: 11 } }, grid: { display: false } } },
    },
  })
}

watch(vendMetrica, () => buildVend())

// ── Chart: barras por tienda ──────────────────────────────────────────────────
const TIENDA_COLORS = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2']
const tiendMetrica = ref('total_vendido') // 'total_vendido' | 'ingresos'

function buildTiend() {
  if (tiendChart) { tiendChart.destroy(); tiendChart = null }
  if (!tiendCanvas.value || !tiendasData.value.length) return
  const usaTotal = tiendMetrica.value === 'total_vendido'
  tiendChart = new Chart(tiendCanvas.value, {
    type: 'bar',
    data: {
      labels: tiendasData.value.map(t => t.nombre),
      datasets: [{ label: usaTotal ? 'Total vendido' : 'Cobrado', data: tiendasData.value.map(t => usaTotal ? t.total_vendido : t.ingresos), backgroundColor: tiendasData.value.map((_, i) => TIENDA_COLORS[i % TIENDA_COLORS.length]), borderRadius: 6 }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ` ${cop(c.raw)}` } } },
      scales: { x: { ticks: { font: { size: 11 } }, grid: { display: false } }, y: { ticks: { callback: copCompact, font: { size: 10 } }, grid: { color: '#f3f4f6' } } },
    },
  })
}

watch(tiendMetrica, () => buildTiend())

// ── Chart: dona por categoría (Productos) ─────────────────────────────────────
function buildDona() {
  if (donaChart) { donaChart.destroy(); donaChart = null }
  const src = categorias.value.length ? categorias.value : productos.value
  if (!donaCanvas.value || !src.length) return
  const labels = src.map(c => c.categoria || 'Sin categoría')
  const data   = src.map(c => Number(c.valor_total))
  donaChart = new Chart(donaCanvas.value, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{ data, backgroundColor: TIENDA_COLORS.slice(0, labels.length), borderWidth: 2 }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } }, tooltip: { callbacks: { label: (c) => ` ${cop(c.raw)}` } } },
    },
  })
}

// ── Exportar ──────────────────────────────────────────────────────────────────
function resuelveFechas() {
  if (modoCustom.value && desdeCustom.value && hastaCustom.value) {
    return { desde: desdeCustom.value, hasta: hastaCustom.value }
  }
  const hoy = new Date()
  let desde
  switch (periodoActivo.value) {
    case 'hoy':
      desde = new Date(hoy)
      break
    case 'semana': {
      // Lunes como inicio de semana (igual que Carbon::startOfWeek en el backend)
      const diaSemana = hoy.getDay() === 0 ? 7 : hoy.getDay() // 1=Lun ... 7=Dom
      desde = new Date(hoy)
      desde.setDate(hoy.getDate() - (diaSemana - 1))
      break
    }
    case 'mes':
      desde = new Date(hoy.getFullYear(), hoy.getMonth(), 1)
      break
    case 'mes_anterior': {
      const primerDiaMesAnt = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1)
      const ultimoDiaMesAnt = new Date(hoy.getFullYear(), hoy.getMonth(), 0)
      return {
        desde: primerDiaMesAnt.toISOString().split('T')[0],
        hasta: ultimoDiaMesAnt.toISOString().split('T')[0],
      }
    }
    case 'anio':
      desde = new Date(hoy.getFullYear(), 0, 1)
      break
    default:
      desde = new Date(hoy)
      desde.setDate(hoy.getDate() - 30)
  }
  return { desde: desde.toISOString().split('T')[0], hasta: hoy.toISOString().split('T')[0] }
}

async function exportar(tipo) {
  const f = resuelveFechas()
  const params = new URLSearchParams({
    tipo,
    desde: f.desde,
    hasta: f.hasta,
    ...(tiendaFiltro.value ? { tienda_id: tiendaFiltro.value } : {}),
  })
  try {
    const res = await api.get(`/reportes/exportar?${params}`, {
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const a = document.createElement('a')
    a.href = url
    a.download = `decasa_reporte_${tipo}_${f.desde}_${f.hasta}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    console.error('Error al exportar:', e)
  }
}

onMounted(async () => {
  const { data } = await api.get('/tiendas')
  tiendas.value = data
  cargarTodo()
})
onBeforeUnmount(() => {
  lineChart?.destroy(); vendChart?.destroy(); tiendChart?.destroy(); donaChart?.destroy()
})
</script>

<template>
  <div class="p-4 max-w-3xl mx-auto space-y-4 pb-8">

    <!-- Header -->
    <h2 class="text-lg font-bold text-gray-800">Reportes</h2>

    <!-- Filtros globales -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <!-- Período -->
      <div class="flex gap-1.5 flex-wrap">
        <button v-for="p in presets" :key="p.value"
          @click="selPreset(p.value)"
          :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
            !modoCustom && periodoActivo === p.value
              ? 'bg-blue-600 text-white border-blue-600'
              : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400']"
        >{{ p.label }}</button>
        <button @click="modoCustom = !modoCustom"
          :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
            modoCustom ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300']"
        >Personalizado</button>
      </div>
      <div v-if="modoCustom" class="flex gap-2 items-center">
        <input v-model="desdeCustom" type="date" class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <span class="text-gray-400 text-xs">→</span>
        <input v-model="hastaCustom" type="date" class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <button @click="aplicarCustom" class="bg-blue-600 text-white text-xs px-3 py-1.5 rounded-lg font-semibold">Aplicar</button>
      </div>
      <!-- Tienda (solo supervisor) -->
      <select v-if="auth.isSupervisor" v-model="tiendaFiltro" @change="cargarTodo"
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        <option value="">Todas las tiendas</option>
        <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
      </select>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 overflow-x-auto pb-1">
      <button v-for="tab in tabsVisibles" :key="tab.id"
        @click="switchTab(tab.id)"
        :class="['px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-colors flex-shrink-0',
          tabActivo === tab.id ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 shadow-sm hover:bg-gray-50']"
      >{{ tab.label }}</button>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <template v-else>

      <!-- ══════ TAB: RESUMEN ══════ -->
      <div v-show="tabActivo === 'resumen'" class="space-y-4">

        <!-- KPI cards -->
        <div v-if="panel" class="grid grid-cols-2 gap-3">
          <div class="bg-white rounded-xl shadow-sm p-4 col-span-2">
            <p class="text-xs text-gray-400 mb-1">Total vendido</p>
            <p class="text-2xl font-bold text-green-600">{{ cop(panel.total_vendido) }}</p>
            <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-100">
              <div>
                <p class="text-[11px] text-gray-400">Cobrado</p>
                <p class="text-sm font-semibold text-blue-600">{{ cop(panel.ingresos_totales) }}</p>
              </div>
              <div class="text-right">
                <p class="text-[11px] text-gray-400">Cartera</p>
                <p class="text-sm font-semibold text-red-500">{{ cop(panel.cartera_pendiente) }}</p>
              </div>
            </div>
            <p :class="['text-xs mt-2', varColor(panel.comparativa?.variacion_pct)]">
              {{ varLabel(panel.comparativa?.variacion_pct) }}
            </p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">Órdenes totales</p>
            <p class="text-xl font-bold text-gray-800">{{ panel.ordenes_totales }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">Entregadas</p>
            <p class="text-xl font-bold text-green-600">{{ panel.ordenes_entregadas }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">Ticket promedio</p>
            <p class="text-lg font-bold text-gray-800 leading-tight">{{ cop(panel.ticket_promedio) }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">Cartera pendiente</p>
            <p class="text-lg font-bold text-red-500 leading-tight">{{ cop(panel.cartera_pendiente) }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">Pendientes</p>
            <p class="text-xl font-bold text-amber-500">{{ panel.ordenes_pendientes }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-400 mb-1">Canceladas</p>
            <p class="text-xl font-bold text-gray-400">{{ panel.ordenes_canceladas }}</p>
          </div>
        </div>

        <!-- Gráfica línea -->
        <div class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-semibold text-gray-700">Tendencia del período</p>
            <button @click="exportar('ventas')" class="text-xs text-blue-600 font-medium hover:underline">Exportar</button>
          </div>
          <div class="h-52">
            <canvas ref="lineCanvas"></canvas>
          </div>
        </div>
      </div>

      <!-- ══════ TAB: VENDEDORES ══════ -->
      <div v-show="tabActivo === 'vendedores' && auth.isSupervisor" class="space-y-4">

        <!-- Gráfica horizontal -->
        <div v-if="vendedores.length" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-semibold text-gray-700">Ventas por vendedor / supervisor</p>
            <div class="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-semibold">
              <button
                @click="vendMetrica = 'total_vendido'"
                :class="vendMetrica === 'total_vendido' ? 'bg-green-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'"
                class="px-3 py-1 transition-colors"
              >Total vendido</button>
              <button
                @click="vendMetrica = 'ingresos'"
                :class="vendMetrica === 'ingresos' ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'"
                class="px-3 py-1 border-l border-gray-200 transition-colors"
              >Cobrado</button>
            </div>
          </div>
          <div :style="{ height: `${Math.min(vendedores.length, 8) * 44 + 20}px` }">
            <canvas ref="vendCanvas"></canvas>
          </div>
        </div>

        <!-- Tabla ranking -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700">Ranking de ventas</p>
            <button @click="exportar('vendedores')" class="text-xs text-blue-600 font-medium hover:underline">Exportar</button>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500">
                <tr>
                  <th class="px-3 py-2 text-left">#</th>
                  <th class="px-3 py-2 text-left">Nombre</th>
                  <th class="px-3 py-2 text-left">Tienda</th>
                  <th class="px-3 py-2 text-right">Total vendido</th>
                  <th class="px-3 py-2 text-right">Cobrado</th>
                  <th class="px-3 py-2 text-right">Órdenes</th>
                  <th class="px-3 py-2 text-right">Cartera</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(v, i) in vendedores" :key="v.id"
                  class="hover:bg-gray-50 cursor-pointer"
                  @click="router.push({ name: 'stats-vendedor', params: { id: v.id } })">
                  <td class="px-3 py-2.5">
                    <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold',
                      i === 0 ? 'bg-yellow-100 text-yellow-700' :
                      i === 1 ? 'bg-gray-100 text-gray-600' :
                      i === 2 ? 'bg-orange-100 text-orange-700' : 'text-gray-400']">
                      {{ i + 1 }}
                    </span>
                  </td>
                  <td class="px-3 py-2.5 font-medium text-gray-800">
                    {{ v.nombre }}
                    <span v-if="v.rol === 'supervisor'" class="ml-1 text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded-full font-semibold">Sup</span>
                    <span v-if="v.rol === 'ebanista'" class="ml-1 text-xs bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded-full font-semibold">Eba</span>
                  </td>
                  <td class="px-3 py-2.5 text-gray-500 text-xs">{{ v.tienda }}</td>
                  <td class="px-3 py-2.5 text-right font-bold text-green-700">{{ cop(v.total_vendido) }}</td>
                  <td class="px-3 py-2.5 text-right font-semibold text-blue-600">{{ cop(v.ingresos) }}</td>
                  <td class="px-3 py-2.5 text-right text-gray-600">{{ v.ordenes_totales }}</td>
                  <td class="px-3 py-2.5 text-right text-red-500 text-xs">{{ cop(v.cartera_pendiente) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══════ TAB: TIENDAS ══════ -->
      <div v-show="tabActivo === 'tiendas' && auth.isSupervisor" class="space-y-4">

        <!-- Gráfica barras -->
        <div v-if="tiendasData.length" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-semibold text-gray-700">Ventas por tienda</p>
            <div class="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-semibold">
              <button
                @click="tiendMetrica = 'total_vendido'"
                :class="tiendMetrica === 'total_vendido' ? 'bg-green-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'"
                class="px-3 py-1 transition-colors"
              >Total vendido</button>
              <button
                @click="tiendMetrica = 'ingresos'"
                :class="tiendMetrica === 'ingresos' ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'"
                class="px-3 py-1 border-l border-gray-200 transition-colors"
              >Cobrado</button>
            </div>
          </div>
          <div class="h-48">
            <canvas ref="tiendCanvas"></canvas>
          </div>
        </div>

        <!-- Cards por tienda -->
        <div class="grid grid-cols-1 gap-3">
          <div v-for="(t, i) in tiendasData" :key="t.tienda_id"
            class="bg-white rounded-xl shadow-sm p-4 border-l-4"
            :style="{ borderColor: TIENDA_COLORS[i % TIENDA_COLORS.length] }">
            <div class="flex justify-between items-start mb-3">
              <div>
                <p class="font-semibold text-gray-800">{{ t.nombre }}</p>
                <p v-if="t.ciudad" class="text-xs text-gray-400">{{ t.ciudad }}</p>
              </div>
              <div class="text-right">
                <p class="text-lg font-bold text-green-700">{{ cop(t.total_vendido) }}</p>
                <p class="text-xs text-gray-400">Cobrado {{ cop(t.ingresos) }} · Cartera {{ cop(t.cartera_pendiente) }}</p>
              </div>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs">
              <div class="bg-gray-50 rounded-lg py-1.5">
                <p class="font-semibold text-gray-800">{{ t.ordenes_totales }}</p>
                <p class="text-gray-400">Órdenes</p>
              </div>
              <div class="bg-gray-50 rounded-lg py-1.5">
                <p class="font-semibold text-green-600">{{ t.ordenes_entregadas }}</p>
                <p class="text-gray-400">Entregadas</p>
              </div>
              <div class="bg-gray-50 rounded-lg py-1.5">
                <p class="font-semibold text-gray-800">{{ cop(t.ticket_promedio) }}</p>
                <p class="text-gray-400">Ticket</p>
              </div>
            </div>
            <!-- Barra meta mensual -->
            <div v-if="t.meta_mes?.meta" class="mt-3 pt-3 border-t border-gray-100">
              <div class="flex items-center justify-between mb-1">
                <p class="text-xs font-semibold text-gray-600">Meta {{ t.meta_mes.mes }}</p>
                <span :class="['text-xs font-bold px-2 py-0.5 rounded-full',
                  t.meta_mes.pct >= 100 ? 'bg-green-100 text-green-700' :
                  t.meta_mes.pct >= 80  ? 'bg-blue-100 text-blue-700' :
                  t.meta_mes.pct >= 50  ? 'bg-yellow-100 text-yellow-700' :
                  'bg-gray-100 text-gray-500']">
                  {{ t.meta_mes.pct }}%
                </span>
              </div>
              <div class="h-2 bg-gray-100 rounded-full overflow-hidden mb-1.5">
                <div class="h-full rounded-full transition-all duration-700"
                  :class="[
                    t.meta_mes.pct >= 100 ? 'bg-green-500' :
                    t.meta_mes.pct >= 80  ? 'bg-blue-500' :
                    t.meta_mes.pct >= 50  ? 'bg-yellow-400' : 'bg-gray-300'
                  ]"
                  :style="{ width: `${Math.min(t.meta_mes.pct, 100)}%` }"
                />
              </div>
              <div class="flex items-center justify-between text-[11px] text-gray-400">
                <span>Vendido: <span class="font-semibold text-gray-600">{{ cop(t.meta_mes.total_tienda) }}</span></span>
                <span>Meta: <span class="font-semibold text-gray-600">{{ cop(t.meta_mes.meta) }}</span></span>
              </div>
              <p v-if="t.meta_mes.cumplida" class="mt-1 text-[11px] font-semibold text-green-600">✓ ¡Meta alcanzada!</p>
              <p v-else class="mt-1 text-[11px] text-gray-400">
                Faltan <span class="font-semibold text-gray-600">{{ cop(t.meta_mes.meta - t.meta_mes.total_tienda) }}</span>
              </p>
            </div>

            <div v-if="t.vendedor_destacado" class="mt-3 text-xs text-gray-500 flex items-center gap-1">
              <StarIcon class="w-4 h-4 text-yellow-500 inline-block" />
              <span>{{ t.vendedor_destacado.nombre }}</span>
              <span class="text-gray-400">— {{ cop(t.vendedor_destacado.ingresos) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════ TAB: PRODUCTOS ══════ -->
      <div v-show="tabActivo === 'productos'" class="space-y-4">

        <!-- Stats por categoría -->
        <div v-if="categorias.length" class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-700 mb-3">Ventas por categoría</p>

          <!-- Dona chart -->
          <div class="h-52 mb-4">
            <canvas ref="donaCanvas"></canvas>
          </div>

          <!-- Category cards -->
          <div class="space-y-2">
            <div
              v-for="cat in categorias" :key="cat.categoria"
              @click="filtrarPorCategoria(categoriaFiltro === cat.categoria ? '' : cat.categoria)"
              :class="['flex items-center gap-3 rounded-xl px-3 py-2.5 cursor-pointer border transition-colors',
                categoriaFiltro === cat.categoria
                  ? 'bg-blue-50 border-blue-300'
                  : 'bg-gray-50 border-transparent hover:bg-blue-50 hover:border-blue-200']"
            >
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ cat.categoria }}</p>
                <p class="text-xs text-gray-400">{{ cat.num_productos }} producto{{ cat.num_productos !== 1 ? 's' : '' }} · {{ cat.cantidad }} uds. vendidas</p>
              </div>
              <MoneyDisplay :amount="cat.valor_total" class="text-sm font-bold flex-shrink-0 text-blue-600" />
            </div>
          </div>

          <p v-if="categoriaFiltro" class="text-xs text-center text-blue-600 mt-2 font-medium">
            Filtro activo: {{ categoriaFiltro }} —
            <button @click="filtrarPorCategoria('')" class="underline">Quitar filtro</button>
          </p>
        </div>

        <!-- Buscar producto -->
        <div class="relative">
          <input
            v-model="busquedaProducto"
            @input="onBusquedaInput"
            type="search"
            placeholder="Buscar producto por nombre..."
            class="w-full rounded-xl border border-gray-300 px-4 py-2.5 text-sm pr-9 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <svg v-if="!busquedaProducto" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>

        <!-- Tabla top productos -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-700">
              <template v-if="busquedaProducto">Resultados: "{{ busquedaProducto }}"</template>
              <template v-else-if="categoriaFiltro">Top productos — {{ categoriaFiltro }}</template>
              <template v-else>Top 10 productos</template>
            </p>
            <button @click="exportar('productos-top')" class="text-xs text-blue-600 font-medium hover:underline">Exportar</button>
          </div>
          <ul class="divide-y divide-gray-100">
            <li v-for="(p, i) in productos" :key="p.producto_id"
              class="flex items-center gap-3 px-4 py-3">
              <span class="w-6 h-6 rounded-full bg-blue-50 text-blue-600 text-xs font-bold flex items-center justify-center flex-shrink-0">{{ i + 1 }}</span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ p.nombre }}</p>
                <p class="text-xs text-gray-400">{{ p.categoria }} · x{{ p.cantidad }} uds.</p>
              </div>
              <MoneyDisplay :amount="p.valor_total" class="text-sm font-semibold flex-shrink-0" />
            </li>
          </ul>
          <p v-if="!productos.length" class="text-center py-6 text-sm text-gray-400">Sin ventas en este período.</p>
        </div>
      </div>

      <!-- ══════ TAB: CARTERA ══════ -->
      <div v-show="tabActivo === 'cartera'" class="space-y-3">
        <div class="flex items-center justify-between">
          <p class="text-sm text-gray-500">{{ cartera.length }} orden{{ cartera.length !== 1 ? 'es' : '' }} con saldo pendiente</p>
          <button @click="exportar('pendientes')" class="text-xs text-blue-600 font-medium hover:underline">Exportar</button>
        </div>
        <ul class="space-y-2">
          <li v-for="o in cartera" :key="o.orden_id"
            @click="router.push({ name: 'orden-detalle', params: { id: o.orden_id } })"
            class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:shadow-md transition-shadow">
            <div class="flex justify-between items-start mb-2">
              <div>
                <p class="font-medium text-sm text-gray-800">{{ o.cliente }}</p>
                <p class="text-xs text-gray-400">{{ o.vendedor }} · {{ o.tienda }}</p>
              </div>
              <div class="flex flex-col items-end gap-1">
                <BadgeEstado :estado="o.estado" />
                <span :class="['text-xs font-semibold px-2 py-0.5 rounded-full', diasColor(o.dias_sin_pagar)]">
                  {{ o.dias_sin_pagar }}d
                </span>
              </div>
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs text-center">
              <div>
                <p class="text-gray-400">Total</p>
                <p class="font-semibold text-gray-700">{{ cop(o.valor_total) }}</p>
              </div>
              <div>
                <p class="text-gray-400">Pagado</p>
                <p class="font-semibold text-green-600">{{ cop(o.total_pagado) }}</p>
              </div>
              <div>
                <p class="text-gray-400">Saldo</p>
                <p class="font-bold text-red-500">{{ cop(o.saldo_pendiente) }}</p>
              </div>
            </div>
          </li>
        </ul>
        <p v-if="!cartera.length" class="text-center py-8 text-gray-400 text-sm">No hay cartera pendiente.</p>
      </div>

      <!-- ══════ TAB: PRODUCCIÓN ══════ -->
      <div v-show="tabActivo === 'produccion'" class="space-y-3">
        <div class="flex items-center justify-between">
          <p class="text-sm text-gray-500">{{ retrasos.length }} orden{{ retrasos.length !== 1 ? 'es' : '' }} atrasada{{ retrasos.length !== 1 ? 's' : '' }}</p>
          <button @click="exportar('retrasos')" class="text-xs text-blue-600 font-medium hover:underline">Exportar</button>
        </div>
        <ul class="space-y-2">
          <li v-for="r in retrasos" :key="r.orden_id"
            @click="router.push({ name: 'orden-detalle', params: { id: r.orden_id } })"
            class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:shadow-md transition-shadow">
            <div class="flex justify-between items-start mb-1">
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 mb-0.5">
                  <p class="font-semibold text-sm text-gray-800">Orden #{{ r.numero_orden ?? r.orden_id }}</p>
                  <span class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{{ r.items_count }} item{{ r.items_count !== 1 ? 's' : '' }}</span>
                </div>
                <p class="text-xs text-gray-600">{{ r.cliente }} · {{ r.vendedor }}</p>
                <p class="text-xs text-gray-400">
                  {{ r.tienda }} ·
                  <span v-if="r.fecha_compromiso">Compromiso: {{ r.fecha_compromiso }}</span>
                  <span v-else>Sin fecha de producción · creada {{ r.created_at?.substring(0, 10) }}</span>
                </p>
              </div>
              <span :class="['ml-2 flex-shrink-0 text-xs font-bold px-2.5 py-1 rounded-full', retrasoColor(r.dias_retraso)]">
                +{{ r.dias_retraso }}d
              </span>
            </div>
          </li>
        </ul>
        <p v-if="!retrasos.length" class="text-center py-8 text-gray-400 text-sm">Sin órdenes atrasadas.</p>
      </div>

      <!-- ══════ TAB: CANALES ══════ -->
      <div v-show="tabActivo === 'canales' && auth.isSupervisor" class="space-y-4">

        <div v-if="cargandoCanales" class="flex justify-center py-10">
          <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
        </div>

        <template v-else-if="canalesData">

          <!-- KPI total -->
          <div class="grid grid-cols-2 gap-3">
            <div class="bg-white rounded-xl shadow-sm p-4">
              <p class="text-xs text-gray-400 mb-1">Total órdenes</p>
              <p class="text-2xl font-bold text-gray-800">{{ canalesData.total_ordenes }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-4">
              <p class="text-xs text-gray-400 mb-1">Valor bruto</p>
              <p class="text-xl font-bold text-blue-600">{{ cop(canalesData.total_valor) }}</p>
            </div>
          </div>

          <!-- Lista por canal -->
          <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
              <p class="text-sm font-semibold text-gray-700">Ventas por canal</p>
            </div>
            <div class="divide-y divide-gray-50">
              <div
                v-for="c in canalesData.por_canal"
                :key="c.canal"
                class="px-4 py-3.5"
              >
                <div class="flex items-center justify-between mb-2">
                  <div class="flex items-center gap-2">
                    <span
                      class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                      :style="{ background: CANAL_COLORS[c.canal] ?? '#6b7280' }"
                    />
                    <span class="text-sm font-semibold text-gray-800">
                      {{ CANAL_LABELS[c.canal] ?? c.canal }}
                    </span>
                  </div>
                  <div class="text-right">
                    <p class="text-sm font-bold text-blue-600">{{ cop(c.valor_bruto) }}</p>
                    <p class="text-xs text-gray-400">{{ c.total_ordenes }} orden{{ c.total_ordenes !== 1 ? 'es' : '' }}</p>
                  </div>
                </div>
                <!-- Barra de progreso -->
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full transition-all"
                    :style="{
                      width: c.pct_valor + '%',
                      background: CANAL_COLORS[c.canal] ?? '#6b7280',
                    }"
                  />
                </div>
                <div class="flex justify-between text-[11px] text-gray-400 mt-1">
                  <span>{{ c.pct_ordenes }}% de órdenes</span>
                  <span>{{ c.pct_valor }}% del valor</span>
                </div>
              </div>
            </div>
            <p v-if="!canalesData.por_canal.length" class="text-center py-8 text-sm text-gray-400">
              Sin órdenes en este período.
            </p>
          </div>

        </template>

        <div v-else-if="!cargandoCanales" class="text-center py-12 text-gray-400 text-sm">
          Haz clic en el tab para cargar los datos de canales.
        </div>
      </div>

      <!-- ══════ TAB: CONDUCTORES ══════ -->
      <div v-show="tabActivo === 'conductores' && auth.isSupervisor" class="space-y-3">

        <div v-if="cargandoConductores" class="flex justify-center py-10">
          <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
        </div>

        <template v-else-if="conductores">
          <p class="text-sm text-gray-500">{{ conductores.length }} conductor{{ conductores.length !== 1 ? 'es' : '' }} activo{{ conductores.length !== 1 ? 's' : '' }}</p>

          <div v-if="!conductores.length" class="text-center py-10 text-gray-400 text-sm">
            No hay conductores activos registrados.
          </div>

          <div v-else class="space-y-3">
            <div
              v-for="(c, i) in conductores"
              :key="c.id"
              class="bg-white rounded-xl shadow-sm p-4 border-l-4"
              :style="{ borderColor: TIENDA_COLORS[i % TIENDA_COLORS.length] }"
            >
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                  <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold',
                    i === 0 ? 'bg-yellow-100 text-yellow-700' :
                    i === 1 ? 'bg-gray-100 text-gray-600' :
                    i === 2 ? 'bg-orange-100 text-orange-700' : 'bg-blue-50 text-blue-600']">
                    {{ i + 1 }}
                  </span>
                  <p class="font-semibold text-gray-800 text-sm">{{ c.nombre }}</p>
                </div>
                <span v-if="c.pendientes > 0" class="text-xs bg-amber-100 text-amber-700 font-semibold px-2 py-0.5 rounded-full">
                  {{ c.pendientes }} pendiente{{ c.pendientes !== 1 ? 's' : '' }}
                </span>
              </div>

              <div class="grid grid-cols-3 gap-2 text-center text-xs">
                <div class="bg-gray-50 rounded-lg py-2">
                  <p class="text-lg font-bold text-gray-800">{{ c.entregas }}</p>
                  <p class="text-gray-400 mt-0.5">Entregas</p>
                </div>
                <div class="bg-green-50 rounded-lg py-2 col-span-2">
                  <p class="text-lg font-bold text-green-600">{{ cop(c.cobrado) }}</p>
                  <p class="text-gray-400 mt-0.5">Cobrado en ruta</p>
                </div>
              </div>
            </div>
          </div>
        </template>

        <div v-else class="text-center py-12 text-gray-400 text-sm">
          Haz clic en el tab para cargar los datos de conductores.
        </div>
      </div>

      <!-- ══════ TAB: INTERESADOS ══════ -->
      <div v-show="tabActivo === 'interesados'" class="space-y-4">

        <div v-if="cargandoInteresados" class="flex justify-center py-10">
          <div class="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" />
        </div>

        <template v-else-if="interesados">

          <!-- KPIs -->
          <div class="grid grid-cols-2 gap-3">
            <div class="bg-white rounded-xl shadow-sm p-4 col-span-2">
              <p v-if="tiendaFiltroNombre" class="text-xs font-semibold text-amber-600 mb-2">
                Tienda: {{ tiendaFiltroNombre }}
              </p>
              <div class="flex items-center gap-4">
                <div class="flex-1">
                  <p class="text-xs text-gray-400 mb-1">Leads activos</p>
                  <p class="text-2xl font-bold text-amber-600">{{ interesados.total }}</p>
                </div>
                <div class="flex-1 border-l border-gray-100 pl-4">
                  <p class="text-xs text-gray-400 mb-1">Nuevos en el período</p>
                  <p class="text-2xl font-bold text-gray-800">{{ interesados.nuevos_periodo }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Top categorías de interés -->
          <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
              <p class="text-sm font-semibold text-gray-700">Lo que más preguntan</p>
              <p class="text-xs text-gray-400 mt-0.5">Categorías de interés registradas al guardar el prospecto</p>
            </div>
            <div v-if="interesados.top_categorias.length" class="divide-y divide-gray-50">
              <div
                v-for="(cat, i) in interesados.top_categorias"
                :key="cat.categoria"
                class="flex items-center gap-3 px-4 py-3"
              >
                <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0',
                  i === 0 ? 'bg-amber-100 text-amber-700' :
                  i === 1 ? 'bg-gray-100 text-gray-600' :
                  i === 2 ? 'bg-orange-100 text-orange-700' : 'bg-gray-50 text-gray-400']">
                  {{ i + 1 }}
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 capitalize">{{ cat.categoria.replace(/_/g, ' ') }}</p>
                  <div class="mt-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div
                      class="h-full bg-amber-400 rounded-full"
                      :style="{ width: `${Math.round((cat.total / interesados.top_categorias[0].total) * 100)}%` }"
                    />
                  </div>
                </div>
                <span class="text-sm font-bold text-gray-700 flex-shrink-0">{{ cat.total }}</span>
              </div>
            </div>
            <p v-else class="text-center py-8 text-sm text-gray-400">
              Ningún interesado tiene categorías registradas aún.
            </p>
          </div>

          <!-- Por tienda -->
          <div v-if="interesados.por_tienda.length" class="space-y-3">
            <p class="text-sm font-semibold text-gray-700">Por tienda</p>
            <div
              v-for="(t, i) in interesados.por_tienda"
              :key="t.tienda_id"
              class="bg-white rounded-xl shadow-sm p-4 border-l-4"
              :style="{ borderColor: TIENDA_COLORS[i % TIENDA_COLORS.length] }"
            >
              <div class="flex items-center justify-between mb-2">
                <p class="font-semibold text-gray-800 text-sm">{{ t.tienda }}</p>
                <span class="text-sm font-bold text-amber-600">{{ t.total }} {{ t.total === 1 ? 'persona' : 'personas' }}</span>
              </div>
              <div v-if="t.top_categorias.length" class="flex flex-wrap gap-1.5">
                <span
                  v-for="cat in t.top_categorias"
                  :key="cat.categoria"
                  class="inline-flex items-center gap-1 text-xs bg-amber-50 text-amber-800 border border-amber-200 px-2.5 py-1 rounded-full font-medium"
                >
                  {{ cat.categoria }}
                  <span class="bg-amber-200 text-amber-900 text-[10px] font-bold px-1.5 py-0.5 rounded-full leading-none">{{ cat.total }}</span>
                </span>
              </div>
              <p v-else class="text-xs text-gray-400 italic">Sin categorías registradas</p>
            </div>
          </div>

          <!-- Interesados sin tienda asignada -->
          <p
            v-if="interesados.total > 0 && !interesados.por_tienda.length"
            class="text-xs text-center text-gray-400 py-2"
          >
            Los interesados existentes no tienen tienda asignada. Los nuevos se asignarán automáticamente.
          </p>

        </template>

        <div v-else-if="!cargandoInteresados" class="text-center py-12 text-gray-400 text-sm">
          Haz clic en el tab para cargar los datos de interesados.
        </div>

      </div>

      <!-- ══════ TAB: RESUMEN MENSUAL ══════ -->
      <div v-show="tabActivo === 'resumen-mensual'" class="space-y-4">

        <!-- Loading -->
        <div v-if="cargandoResumen" class="flex justify-center py-10">
          <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
        </div>

        <template v-else-if="resumenMensual">

          <!-- Header + export -->
          <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-bold text-blue-800">Resumen mensual — {{ resumenMensual.mes }}</p>
              <p class="text-xs text-blue-600 mt-0.5">Del {{ resumenMensual.desde }} al {{ resumenMensual.hasta }}</p>
            </div>
            <button
              @click="exportarResumenMensual"
              class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-lg hover:bg-blue-700 transition-colors flex-shrink-0"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              Exportar Excel
            </button>
          </div>

          <!-- Top 20 general -->
          <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100">
              <p class="text-sm font-semibold text-gray-700">Top 20 productos — General</p>
            </div>
            <ul class="divide-y divide-gray-100">
              <li
                v-for="(p, i) in resumenMensual.general"
                :key="p.producto_id"
                class="flex items-center gap-3 px-4 py-3"
              >
                <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0',
                  i === 0 ? 'bg-yellow-100 text-yellow-700' :
                  i === 1 ? 'bg-gray-200 text-gray-600' :
                  i === 2 ? 'bg-orange-100 text-orange-700' : 'bg-blue-50 text-blue-500']">
                  {{ i + 1 }}
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 truncate">{{ p.nombre }}</p>
                  <p class="text-xs text-gray-400">{{ p.categoria }} · {{ p.total_unidades }} uds. vendidas</p>
                </div>
                <MoneyDisplay :amount="p.total_valor" class="text-sm font-semibold flex-shrink-0" />
              </li>
            </ul>
            <p v-if="!resumenMensual.general.length" class="text-center py-6 text-sm text-gray-400">
              Sin ventas registradas el mes anterior.
            </p>
          </div>

          <!-- Top 10 por tienda -->
          <div v-if="resumenMensual.por_tienda.length" class="space-y-3">
            <p class="text-sm font-semibold text-gray-700">Top 10 por tienda</p>
            <div
              v-for="t in resumenMensual.por_tienda"
              :key="t.tienda_id"
              class="bg-white rounded-xl shadow-sm overflow-hidden"
            >
              <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100">
                <p class="text-sm font-semibold text-gray-700">{{ t.tienda_nombre }}</p>
              </div>
              <ul class="divide-y divide-gray-100">
                <li
                  v-for="(p, i) in t.top"
                  :key="p.producto_id"
                  class="flex items-center gap-3 px-4 py-2.5"
                >
                  <span :class="['w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0',
                    i === 0 ? 'bg-yellow-100 text-yellow-700' :
                    i === 1 ? 'bg-gray-200 text-gray-600' :
                    i === 2 ? 'bg-orange-100 text-orange-700' : 'bg-blue-50 text-blue-500']">
                    {{ i + 1 }}
                  </span>
                  <div class="flex-1 min-w-0">
                    <p class="text-xs font-medium text-gray-800 truncate">{{ p.nombre }}</p>
                    <p class="text-[10px] text-gray-400">{{ p.categoria }} · {{ p.total_unidades }} uds.</p>
                  </div>
                  <MoneyDisplay :amount="p.total_valor" class="text-xs font-semibold flex-shrink-0" />
                </li>
              </ul>
            </div>
          </div>

        </template>
      </div>

    </template>
  </div>
</template>
