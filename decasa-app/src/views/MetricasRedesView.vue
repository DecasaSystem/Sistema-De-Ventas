<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Chart } from 'chart.js/auto'
import { getMetricasRedes } from '@/api/stats'

// ── Período ───────────────────────────────────────────────────────────────────
const presets = [
  { label: 'Hoy',      value: 'hoy' },
  { label: 'Semana',   value: 'semana' },
  { label: 'Mes',      value: 'mes' },
  { label: 'Mes ant.', value: 'mes_anterior' },
  { label: 'Año',      value: 'anio' },
]
const periodo = ref('mes')

const data    = ref(null)
const loading = ref(true)
const error   = ref('')

let chart = null
const canvas = ref(null)

function selPeriodo(v) { periodo.value = v; cargar() }

async function cargar() {
  loading.value = true
  error.value = ''
  try {
    const { data: d } = await getMetricasRedes({ periodo: periodo.value })
    data.value = d
  } catch (e) {
    error.value = e?.response?.data?.error || 'No se pudieron cargar las métricas.'
  } finally {
    loading.value = false
  }
  // Dibujar DESPUÉS de que loading sea false: el <canvas> vive dentro del bloque
  // v-else-if="data", que no está en el DOM mientras loading es true. Si se dibuja
  // antes, canvas.value es null y la gráfica queda en blanco.
  await nextTick()
  if (data.value?.serie?.length) {
    dibujarGrafico()
  } else if (chart) {
    chart.destroy()
    chart = null
  }
}

function dibujarGrafico() {
  if (!canvas.value || !data.value) return
  const serie = data.value.serie || []
  const labels = serie.map(s => {
    const d = new Date(s.dia)
    return `${d.getUTCDate()}/${d.getUTCMonth() + 1}`
  })
  const valores = serie.map(s => Number(s.n))
  if (chart) chart.destroy()
  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels,
      datasets: [{ label: 'Solicitudes', data: valores, backgroundColor: '#2563eb', borderRadius: 4 }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
        x: { grid: { display: false } },
      },
    },
  })
}

// Etiquetas legibles para los tipos de solicitud.
const tipoLabel = { pedido: 'Pedidos', cita: 'Citas', asesor: 'Asesor', personalizacion: 'Personalización', otro: 'Otros' }
function n(obj, k) { return Number(obj?.[k] ?? 0) }

onMounted(cargar)
onBeforeUnmount(() => { if (chart) chart.destroy() })
</script>

<template>
  <div class="p-4 max-w-3xl mx-auto space-y-4 pb-10">

    <!-- Header -->
    <div>
      <h2 class="text-lg font-bold text-gray-800">Métricas de Redes</h2>
      <p v-if="data" class="text-xs text-gray-400 mt-0.5">{{ data.desde }} → {{ data.hasta }}</p>
    </div>

    <!-- Selector período -->
    <div class="flex gap-1.5 flex-wrap">
      <button
        v-for="p in presets" :key="p.value"
        @click="selPeriodo(p.value)"
        :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
          periodo === p.value ? 'bg-blue-600 text-white border-blue-600'
                              : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400']"
      >{{ p.label }}</button>
    </div>

    <div v-if="loading" class="text-center text-gray-400 text-sm py-12">Cargando…</div>
    <div v-else-if="error" class="text-center text-red-500 text-sm py-12">{{ error }}</div>

    <template v-else-if="data">

      <!-- KPIs generales -->
      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2 bg-blue-50 border border-blue-200 rounded-xl shadow-sm p-4">
          <p class="text-xs text-blue-700 font-semibold mb-1 uppercase tracking-wide">Solicitudes al equipo</p>
          <p class="text-2xl font-bold text-blue-700 leading-tight">{{ data.total }}</p>
          <p class="text-xs text-blue-600 mt-1">
            WhatsApp {{ n(data.por_fuente, 'whatsapp') }} · Instagram {{ n(data.por_fuente, 'instagram') }}
          </p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Pendientes</p>
          <p class="text-xl font-bold text-amber-500">{{ n(data.por_estado, 'pendiente') }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">En curso (tomadas)</p>
          <p class="text-xl font-bold text-blue-600">{{ n(data.por_estado, 'tomada') }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Terminadas</p>
          <p class="text-xl font-bold text-green-600">{{ n(data.por_estado, 'terminada') }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Resp. promedio</p>
          <p class="text-xl font-bold text-gray-800 leading-tight">
            {{ data.tiempo_respuesta_min !== null ? data.tiempo_respuesta_min + ' min' : '—' }}
          </p>
        </div>
      </div>

      <!-- Tendencia diaria -->
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-2">Solicitudes por día</p>
        <div v-if="data.serie?.length" class="h-48"><canvas ref="canvas"></canvas></div>
        <p v-else class="text-sm text-gray-400 text-center py-10">Sin datos en este período.</p>
      </div>

      <!-- Por tipo -->
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-2">Por tipo de solicitud</p>
        <div class="space-y-1.5">
          <div v-for="(lbl, k) in tipoLabel" :key="k" class="flex items-center justify-between text-sm">
            <span class="text-gray-600">{{ lbl }}</span>
            <span class="font-semibold text-gray-800">{{ n(data.por_tipo, k) }}</span>
          </div>
        </div>
      </div>

      <!-- Embudo del bot de Instagram -->
      <div v-if="data.instagram" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Bot de Instagram (Elena)</p>
        <div class="grid grid-cols-3 gap-3 text-center">
          <div>
            <p class="text-lg font-bold text-gray-800">{{ data.instagram.conversaciones }}</p>
            <p class="text-[11px] text-gray-400">Conversaciones</p>
          </div>
          <div>
            <p class="text-lg font-bold text-gray-800">{{ data.instagram.productos_vistos }}</p>
            <p class="text-[11px] text-gray-400">Productos vistos</p>
          </div>
          <div>
            <p class="text-lg font-bold text-gray-800">{{ data.instagram.busquedas }}</p>
            <p class="text-[11px] text-gray-400">Búsquedas</p>
          </div>
          <div>
            <p class="text-lg font-bold text-blue-600">{{ data.instagram.transferencias }}</p>
            <p class="text-[11px] text-gray-400">A asesor</p>
          </div>
          <div>
            <p class="text-lg font-bold text-green-600">{{ data.instagram.pedidos }}</p>
            <p class="text-[11px] text-gray-400">Pedidos</p>
          </div>
          <div>
            <p class="text-lg font-bold text-emerald-600">{{ data.instagram.tasa_conversion }}%</p>
            <p class="text-[11px] text-gray-400">Conversión</p>
          </div>
        </div>

        <div v-if="data.instagram_top_productos?.length" class="mt-4 pt-3 border-t border-gray-100">
          <p class="text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Más consultados</p>
          <div class="space-y-1">
            <div v-for="p in data.instagram_top_productos" :key="p.nombre" class="flex items-center justify-between text-sm">
              <span class="text-gray-600 truncate pr-2">{{ p.nombre }}</span>
              <span class="font-semibold text-gray-800 whitespace-nowrap">{{ p.veces }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Por vendedor -->
      <div v-if="data.por_vendedor?.length" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700 mb-2">Atendidas por vendedor</p>
        <div class="space-y-1.5">
          <div v-for="v in data.por_vendedor" :key="v.vendedor" class="flex items-center justify-between text-sm">
            <span class="text-gray-600">{{ v.vendedor }}</span>
            <span class="font-semibold text-gray-800">{{ v.n }}</span>
          </div>
        </div>
      </div>

    </template>
  </div>
</template>
