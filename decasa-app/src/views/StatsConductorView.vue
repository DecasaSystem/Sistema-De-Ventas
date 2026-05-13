<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Chart } from 'chart.js/auto'
import api from '@/api'

// ── Período ───────────────────────────────────────────────────────────────────
const presets = [
  { label: 'Hoy',      value: 'hoy' },
  { label: 'Semana',   value: 'semana' },
  { label: 'Mes',      value: 'mes' },
  { label: 'Mes ant.', value: 'mes_anterior' },
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

// ── Datos ─────────────────────────────────────────────────────────────────────
const loading = ref(false)
const stats   = ref(null)

// ── Canvas ────────────────────────────────────────────────────────────────────
const barCanvas = ref(null)
let barChart = null

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

function fmtFecha(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString('es-CO', {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
  })
}

// ── Carga ─────────────────────────────────────────────────────────────────────
async function cargar() {
  loading.value = true
  try {
    const { data } = await api.get('/stats/conductor', { params: params() })
    stats.value = data
  } finally {
    loading.value = false
  }
  await nextTick()
  buildBar()
}

function buildBar() {
  if (barChart) { barChart.destroy(); barChart = null }
  if (!barCanvas.value || !stats.value?.tendencia) return
  const { labels, serie } = stats.value.tendencia
  if (!serie.some(v => v > 0)) return  // no dibujar si todo es cero

  barChart = new Chart(barCanvas.value, {
    type: 'bar',
    data: {
      labels: labels.map(d => {
        const parts = d.split('-')
        return `${parts[2]}/${parts[1]}`
      }),
      datasets: [{
        label: 'Entregas',
        data: serie,
        backgroundColor: '#2563eb',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ` ${c.raw} entrega(s)` } },
      },
      scales: {
        x: { ticks: { font: { size: 10 }, maxTicksLimit: 10 }, grid: { display: false } },
        y: { ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: '#f3f4f6' } },
      },
    },
  })
}

onMounted(cargar)
onBeforeUnmount(() => { barChart?.destroy() })
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">

    <!-- Header -->
    <div>
      <h2 class="text-lg font-bold text-gray-800">Mis Estadísticas</h2>
      <p v-if="stats?.conductor" class="text-xs text-gray-400 mt-0.5">
        {{ stats.conductor.nombre }}
      </p>
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
    <div v-if="loading" class="flex justify-center py-10">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <template v-else-if="stats">

      <!-- KPIs -->
      <div class="grid grid-cols-3 gap-3">
        <div class="bg-white rounded-xl shadow-sm p-4 text-center">
          <p class="text-[11px] text-gray-400 mb-1 leading-tight">Entregas<br>realizadas</p>
          <p class="text-2xl font-bold text-blue-600">{{ stats.entregas }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center">
          <p class="text-[11px] text-gray-400 mb-1 leading-tight">Cobrado<br>en período</p>
          <p class="text-lg font-bold text-green-600 leading-tight">{{ copCompact(stats.cobrado) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center">
          <p class="text-[11px] text-gray-400 mb-1 leading-tight">Pendientes<br>ahora</p>
          <p class="text-2xl font-bold text-amber-500">{{ stats.pendientes }}</p>
        </div>
      </div>

      <!-- Cobrado completo -->
      <div v-if="stats.cobrado > 0" class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 flex items-center justify-between">
        <p class="text-sm text-blue-700 font-medium">Total cobrado en el período</p>
        <p class="text-lg font-bold text-blue-700">{{ cop(stats.cobrado) }}</p>
      </div>

      <!-- Gráfica tendencia -->
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Entregas por día</p>
        <div v-if="stats.tendencia?.serie.some(v => v > 0)" class="h-40">
          <canvas ref="barCanvas"></canvas>
        </div>
        <p v-else class="text-center text-sm text-gray-400 py-6">Sin entregas en este período</p>
      </div>

      <!-- Historial reciente -->
      <div v-if="stats.recientes?.length" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Entregas recientes</p>
        <ul class="space-y-0 divide-y divide-gray-50">
          <li
            v-for="e in stats.recientes"
            :key="e.id"
            class="flex items-center gap-3 py-2.5"
          >
            <div class="w-8 h-8 rounded-full bg-green-100 text-green-700 flex items-center justify-center flex-shrink-0">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ e.cliente }}</p>
              <p class="text-xs text-gray-400 truncate">{{ e.direccion }}</p>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-xs font-semibold text-gray-700">{{ cop(e.valor_total) }}</p>
              <p class="text-[11px] text-gray-400">{{ fmtFecha(e.entregado_at) }}</p>
            </div>
          </li>
        </ul>
      </div>

      <div v-else-if="!stats.entregas" class="text-center py-8 text-sm text-gray-400">
        No hay entregas registradas en este período.
      </div>

    </template>
  </div>
</template>
