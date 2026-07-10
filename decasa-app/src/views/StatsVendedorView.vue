<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { Chart } from 'chart.js/auto'
import { getStatsMe, getTendencia } from '@/api/stats'
import api from '@/api'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()

// ── Período ───────────────────────────────────────────────────────────────────
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

function params() {
  if (modoCustom.value && desdeCustom.value && hastaCustom.value)
    return { desde: desdeCustom.value, hasta: hastaCustom.value }
  return { periodo: periodoActivo.value }
}

function selPreset(v) { periodoActivo.value = v; modoCustom.value = false; cargar() }

function aplicarCustom() {
  if (!desdeCustom.value || !hastaCustom.value) return
  modoCustom.value = true
  cargar()
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
    case 'semana':
      desde = new Date(hoy)
      desde.setDate(hoy.getDate() - hoy.getDay())
      break
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

async function exportar() {
  const f = resuelveFechas()
  const params = new URLSearchParams({
    tipo: 'ventas',
    desde: f.desde,
    hasta: f.hasta,
  })
  try {
    const res = await api.get(`/reportes/exportar?${params}`, {
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const a = document.createElement('a')
    a.href = url
    a.download = `mis_ventas_${f.desde}_${f.hasta}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    console.error('Error al exportar:', e)
  }
}

// ── Datos ─────────────────────────────────────────────────────────────────────
const loading   = ref(true)
const stats     = ref(null)
const tendencia = ref(null)

// ── Canvas refs + instancias ──────────────────────────────────────────────────
const lineCanvas = ref(null)
const barCanvas  = ref(null)
let lineChart = null
let barChart  = null

// ── Formato moneda COP ────────────────────────────────────────────────────────
function cop(n) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', maximumFractionDigits: 0,
  }).format(n ?? 0)
}
function copCompact(v) {
  if (v >= 1_000_000) return `$${(v / 1_000_000).toFixed(1)}M`
  if (v >= 1_000)     return `$${(v / 1_000).toFixed(0)}K`
  return `$${v}`
}

// ── Carga ─────────────────────────────────────────────────────────────────────
async function cargar() {
  loading.value = true
  try {
    const p = params()
    const sRes = await getStatsMe(p)
    stats.value = sRes.data
    const tRes = await getTendencia({ ...p, vendedor_id: stats.value.vendedor?.id })
    tendencia.value = tRes.data
  } finally {
    loading.value = false
  }
  await nextTick()
  buildLine()
  buildBar()
}

// ── Gráfica de línea ──────────────────────────────────────────────────────────
function buildLine() {
  if (lineChart) { lineChart.destroy(); lineChart = null }
  if (!lineCanvas.value || !tendencia.value) return
  const { labels, cobrado, ordenes_valor } = tendencia.value
  lineChart = new Chart(lineCanvas.value, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Cobrado',
          data: cobrado,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37,99,235,0.08)',
          fill: true, tension: 0.4,
          pointRadius: labels.length > 20 ? 0 : 3,
        },
        {
          label: 'Valor órdenes',
          data: ordenes_valor,
          borderColor: '#f59e0b',
          backgroundColor: 'rgba(245,158,11,0.07)',
          fill: true, tension: 0.4,
          pointRadius: labels.length > 20 ? 0 : 3,
        },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: (c) => ` ${cop(c.raw)}` } },
      },
      scales: {
        x: { ticks: { font: { size: 10 }, maxTicksLimit: 8 }, grid: { display: false } },
        y: { ticks: { callback: copCompact, font: { size: 10 } }, grid: { color: '#f3f4f6' } },
      },
    },
  })
}

// ── Gráfica horizontal de productos ──────────────────────────────────────────
function buildBar() {
  if (barChart) { barChart.destroy(); barChart = null }
  if (!barCanvas.value || !stats.value?.top_productos?.length) return
  const prods = stats.value.top_productos.slice(0, 5)
  barChart = new Chart(barCanvas.value, {
    type: 'bar',
    data: {
      labels: prods.map(p => p.nombre.length > 22 ? p.nombre.slice(0, 20) + '…' : p.nombre),
      datasets: [{
        label: 'Valor',
        data: prods.map(p => p.valor_total),
        backgroundColor: '#2563eb',
        borderRadius: 4,
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (c) => ` ${cop(c.raw)}` } },
      },
      scales: {
        x: { ticks: { callback: copCompact, font: { size: 10 } }, grid: { color: '#f3f4f6' } },
        y: { ticks: { font: { size: 11 } }, grid: { display: false } },
      },
    },
  })
}

onMounted(cargar)
onBeforeUnmount(() => {
  lineChart?.destroy()
  barChart?.destroy()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">

    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-bold text-gray-800">Mis Estadísticas</h2>
        <p v-if="stats?.vendedor" class="text-xs text-gray-400 mt-0.5">
          {{ stats.vendedor.nombre }} · {{ stats.vendedor.tienda }}
        </p>
      </div>
      <button @click="exportar" class="text-xs text-blue-600 font-medium hover:underline whitespace-nowrap">Exportar</button>
    </div>

    <!-- Selector período -->
    <div class="space-y-2">
      <div class="flex gap-1.5 flex-wrap">
        <button
          v-for="p in presets" :key="p.value"
          @click="selPreset(p.value)"
          :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
            !modoCustom && periodoActivo === p.value
              ? 'bg-blue-600 text-white border-blue-600'
              : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400']"
        >{{ p.label }}</button>
        <button
          @click="modoCustom = !modoCustom"
          :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
            modoCustom ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400']"
        >Personalizado</button>
      </div>
      <div v-if="modoCustom" class="flex gap-2 items-center">
        <input v-model="desdeCustom" type="date"
          class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <span class="text-gray-400 text-xs">→</span>
        <input v-model="hastaCustom" type="date"
          class="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <button @click="aplicarCustom"
          class="bg-blue-600 text-white text-xs px-3 py-1.5 rounded-lg font-semibold hover:bg-blue-700">
          Aplicar
        </button>
      </div>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <template v-else-if="stats">

      <!-- KPI cards -->
      <div class="grid grid-cols-2 gap-3">
        <!-- Total vendido: valor real de lo que vendió (cobrado + cartera pendiente) -->
        <div class="col-span-2 bg-green-50 border border-green-200 rounded-xl shadow-sm p-4">
          <p class="text-xs text-green-700 font-semibold mb-1 uppercase tracking-wide">Total vendido</p>
          <p class="text-2xl font-bold text-green-700 leading-tight">{{ cop(stats.total_vendido) }}</p>
          <p class="text-xs text-green-600 mt-1">Cobrado {{ cop(stats.dinero_vendido) }} · Pendiente {{ cop(stats.cartera_pendiente) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Dinero cobrado</p>
          <p class="text-xl font-bold text-blue-600 leading-tight">{{ cop(stats.dinero_vendido) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Órdenes creadas</p>
          <p class="text-xl font-bold text-gray-800">{{ stats.ordenes_creadas }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Entregadas</p>
          <p class="text-xl font-bold text-green-600">{{ stats.ordenes_entregadas }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Pendientes</p>
          <p class="text-xl font-bold text-amber-500">{{ stats.ordenes_pendientes }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Ticket promedio</p>
          <p class="text-xl font-bold text-gray-800 leading-tight">{{ cop(stats.ticket_promedio) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Cartera pendiente</p>
          <p class="text-xl font-bold text-red-500 leading-tight">{{ cop(stats.cartera_pendiente) }}</p>
        </div>
      </div>

      <!-- Barra de meta mensual -->
      <div v-if="stats.meta_mes?.meta" class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex items-center justify-between mb-1">
          <p class="text-sm font-semibold text-gray-700">Meta mensual · {{ stats.meta_mes.mes }}</p>
          <span
            :class="[
              'text-xs font-bold px-2 py-0.5 rounded-full',
              stats.meta_mes.cumplida
                ? 'bg-green-100 text-green-700'
                : stats.meta_mes.pct >= 80
                  ? 'bg-blue-100 text-blue-700'
                  : stats.meta_mes.pct >= 50
                    ? 'bg-yellow-100 text-yellow-700'
                    : 'bg-gray-100 text-gray-500'
            ]"
          >{{ stats.meta_mes.pct }}%</span>
        </div>

        <!-- Barra de progreso -->
        <div class="h-3 bg-gray-100 rounded-full overflow-hidden mb-2">
          <div
            class="h-full rounded-full transition-all duration-700"
            :class="[
              stats.meta_mes.cumplida
                ? 'bg-green-500'
                : stats.meta_mes.pct >= 80
                  ? 'bg-blue-500'
                  : stats.meta_mes.pct >= 50
                    ? 'bg-yellow-400'
                    : 'bg-gray-300'
            ]"
            :style="{ width: `${Math.min(stats.meta_mes.pct, 100)}%` }"
          />
        </div>

        <!-- Montos -->
        <div class="flex items-center justify-between text-xs text-gray-500">
          <span>
            Tienda: <span class="font-semibold text-gray-700">{{ cop(stats.meta_mes.total_tienda) }}</span>
          </span>
          <span>
            Meta: <span class="font-semibold text-gray-700">{{ cop(stats.meta_mes.meta) }}</span>
          </span>
        </div>

        <!-- Mensaje si ya se cumplió -->
        <p v-if="stats.meta_mes.cumplida" class="mt-2 text-xs font-semibold text-green-600 flex items-center gap-1">
          ✓ ¡Meta alcanzada! La comisión del mes está activa.
        </p>
        <p v-else class="mt-2 text-xs text-gray-400">
          Faltan <span class="font-semibold text-gray-600">{{ cop(stats.meta_mes.meta - stats.meta_mes.total_tienda) }}</span> para activar comisiones
        </p>
      </div>

      <!-- Gráfica tendencia -->
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Tendencia del período</p>
        <div class="h-48">
          <canvas ref="lineCanvas"></canvas>
        </div>
      </div>

      <!-- Top 5 productos -->
      <div v-if="stats.top_productos?.length" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Top productos</p>
        <div :style="{ height: `${stats.top_productos.slice(0,5).length * 46 + 16}px` }">
          <canvas ref="barCanvas"></canvas>
        </div>
        <!-- Lista detalle -->
        <ul class="mt-4 space-y-2 border-t border-gray-100 pt-3">
          <li v-for="(p, i) in stats.top_productos.slice(0, 5)" :key="p.id"
            class="flex items-center gap-3 text-sm">
            <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex items-center justify-center flex-shrink-0">
              {{ i + 1 }}
            </span>
            <span class="flex-1 text-gray-700 truncate">{{ p.nombre }}</span>
            <span class="text-xs text-gray-400 flex-shrink-0">x{{ p.cantidad }}</span>
            <MoneyDisplay :amount="p.valor_total" class="text-xs font-semibold flex-shrink-0" />
          </li>
        </ul>
      </div>

      <!-- Órdenes recientes -->
      <div v-if="stats.ordenes_recientes?.length" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Órdenes recientes</p>
        <ul class="space-y-1">
          <li
            v-for="o in stats.ordenes_recientes" :key="o.id"
            @click="router.push({ name: 'orden-detalle', params: { id: o.id } })"
            class="flex items-center justify-between rounded-lg px-2 py-2.5 hover:bg-gray-50 cursor-pointer transition-colors -mx-2"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ o.cliente }}</p>
              <p class="text-xs text-gray-400">#{{ o.id }}</p>
            </div>
            <div class="flex items-center gap-2 ml-2 flex-shrink-0">
              <BadgeEstado :estado="o.estado" />
              <MoneyDisplay :amount="o.valor_total" class="text-xs" />
            </div>
          </li>
        </ul>
      </div>

      <!-- Sin datos -->
      <EmptyState v-if="!stats.ordenes_creadas" message="No hay ventas en este período." />

    </template>
  </div>
</template>
