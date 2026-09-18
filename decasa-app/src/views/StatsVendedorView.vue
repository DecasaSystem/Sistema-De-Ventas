<script setup>
/**
 * "Mis estadísticas": el Resumen de Reportes, pero de una sola persona y de
 * sus tiendas.
 *
 * Un vendedor no entra a Reportes, y antes esta pantalla le daba cuatro
 * cifras sueltas: no sabía si iba mejor o peor que el mes pasado, de qué era
 * lo vendido, ni cómo iba su tienda más allá de la barra de la meta. Ahora
 * lleva el mismo recuadro que el Resumen (vendido = cobrado + por cobrar,
 * variación, desglose por tipo) y debajo las tarjetas de sus tiendas tal como
 * las ve el supervisor, con cuánto de cada una es suyo.
 */
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { Chart } from 'chart.js/auto'
import { getStatsMe, getTendencia, getMisTiendas } from '@/api/stats'
import api from '@/api'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import DesglosePorTipo from '@/components/reportes/DesglosePorTipo.vue'
import { formatPct } from '@/utils/descuentos'
import { StarIcon } from '@heroicons/vue/24/solid'

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

// Armar el Excel tarda; sin aviso el botón parece muerto.
const exportando = ref(false)

async function exportar() {
  if (exportando.value) return
  exportando.value = true
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
  } finally {
    exportando.value = false
  }
}

// ── Datos ─────────────────────────────────────────────────────────────────────
const loading   = ref(true)
const stats     = ref(null)
const tendencia = ref(null)
const tiendas   = ref([])

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
  // Se trabaja sobre el valor absoluto y el signo se pone al final: con un
  // negativo ningún `>=` se cumplía y se devolvía el número crudo, así que
  // una cifra en rojo salía como "$-1500000", sin puntos ni abreviar.
  const n = Math.abs(Number(v) || 0)
  const signo = (Number(v) || 0) < 0 ? '-' : ''
  if (n >= 1_000_000) return `${signo}$${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000)     return `${signo}$${(n / 1_000).toFixed(0)}K`
  // Por debajo de mil no hay nada que abreviar, pero sí que puntuar y
  // redondear: es lo que se ve en los ejes y en algún indicador.
  return `${signo}$${Math.round(n).toLocaleString('es-CO')}`
}
function varColor(pct) {
  if (pct === null || pct === undefined) return 'text-gray-400'
  return pct >= 0 ? 'text-green-600' : 'text-red-500'
}
function varLabel(pct) {
  if (pct === null || pct === undefined) return 'Sin ventas en el período anterior para comparar'
  return (pct >= 0 ? '↑ ' : '↓ ') + formatPct(Math.abs(pct)) + '% vs período anterior'
}

// Color de la barra de meta según qué tan cerca va. Es el mismo criterio de
// la tarjeta de tienda en Reportes, para que un 80% se vea igual en las dos.
function metaTono(m) {
  if (!m) return { badge: 'bg-gray-100 text-gray-500', barra: 'bg-gray-300' }
  if (m.cumplida || m.pct >= 100) return { badge: 'bg-green-100 text-green-700', barra: 'bg-green-500' }
  if (m.pct >= 80) return { badge: 'bg-blue-100 text-blue-700',     barra: 'bg-blue-500' }
  if (m.pct >= 50) return { badge: 'bg-yellow-100 text-yellow-700', barra: 'bg-yellow-400' }
  return { badge: 'bg-gray-100 text-gray-500', barra: 'bg-gray-300' }
}

const TIENDA_COLORS = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2']

// ── Carga ─────────────────────────────────────────────────────────────────────
async function cargar() {
  loading.value = true
  try {
    const p = params()
    // Las tiendas no dependen del perfil: se piden a la vez para no esperar
    // dos veces.
    const [sRes, tiendasRes] = await Promise.all([getStatsMe(p), getMisTiendas(p)])
    stats.value   = sRes.data
    tiendas.value = tiendasRes.data
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
        // Desde cero: sin ventas en el rango el eje se inventaba ticks negativos.
        y: { min: 0, ticks: { callback: copCompact, font: { size: 10 } }, grid: { color: '#f3f4f6' } },
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
          {{ stats.vendedor.nombre }}<span v-if="stats.vendedor.tienda"> · {{ stats.vendedor.tienda }}</span>
        </p>
      </div>
      <button
        @click="exportar"
        :disabled="exportando"
        class="text-xs text-blue-600 font-medium hover:underline disabled:opacity-50 disabled:no-underline whitespace-nowrap"
      >{{ exportando ? 'Exportando...' : 'Exportar' }}</button>
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

      <!-- ══════ MI RESUMEN ══════ -->
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide pt-1">Mi resumen</p>

      <!-- KPI cards: el mismo recuadro del Resumen de Reportes, de una sola persona -->
      <div class="grid grid-cols-2 gap-3">
        <!-- Total vendido: valor de las órdenes hechas en el período, con sus
             dos partes (lo que ya entró y lo que falta), que lo suman. -->
        <div class="col-span-2 bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Total vendido</p>
          <p class="text-2xl font-bold text-green-600 leading-tight">{{ cop(stats.total_vendido) }}</p>
          <p class="text-xs text-gray-400 mt-1">
            {{ stats.ordenes_creadas }} {{ stats.ordenes_creadas === 1 ? 'orden' : 'órdenes' }} en el período. En las compartidas se te acredita la mitad.
          </p>
          <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-100">
            <div>
              <p class="text-[11px] text-gray-400">Cobrado</p>
              <p class="text-sm font-semibold text-blue-600">{{ cop(stats.dinero_vendido) }}</p>
            </div>
            <div class="text-right">
              <p class="text-[11px] text-gray-400">Por cobrar</p>
              <p class="text-sm font-semibold text-red-500">{{ cop(stats.cartera_periodo) }}</p>
            </div>
          </div>
          <p class="text-[11px] text-gray-400 mt-1">Cobrado y por cobrar son de lo vendido en este rango. El dinero que entró en el mes (incluidas órdenes anteriores) está en “Cobranza del período”.</p>
          <p :class="['text-xs mt-2', varColor(stats.comparativa?.variacion_pct)]">
            {{ varLabel(stats.comparativa?.variacion_pct) }}
            <span v-if="stats.comparativa?.variacion_pct != null" class="text-gray-400">
              (antes {{ cop(stats.comparativa.vendido_anterior) }})
            </span>
          </p>

          <!-- De qué tipo de orden viene lo vendido. Los tres suman el
               número grande de arriba. -->
          <DesglosePorTipo
            v-if="stats.por_tipo"
            :datos="stats.por_tipo"
            titulo="De qué es lo vendido"
            class="mt-3"
          />
        </div>

        <div class="col-span-2 bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Cobranza del período</p>
          <p class="text-xl font-bold text-blue-600 leading-tight">{{ cop(stats.cobranza_periodo) }}</p>
          <p class="text-[11px] text-gray-400 mt-1">Todo el dinero que entró en el rango por tus órdenes, incluidos abonos a órdenes de meses anteriores. Por eso puede ser mayor que lo vendido.</p>
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
          <p class="text-xs text-gray-400 mb-1">Ticket promedio</p>
          <p class="text-lg font-bold text-gray-800 leading-tight">{{ cop(stats.ticket_promedio) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Por cobrar del período</p>
          <p class="text-lg font-bold text-red-500 leading-tight">{{ cop(stats.cartera_periodo) }}</p>
          <p class="text-[11px] text-gray-400 mt-1">Saldo de lo vendido en el rango.</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Pendientes</p>
          <p class="text-xl font-bold text-amber-500">{{ stats.ordenes_pendientes }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Canceladas</p>
          <p class="text-xl font-bold text-gray-400">{{ stats.ordenes_canceladas }}</p>
        </div>
        <div class="col-span-2 bg-white rounded-xl shadow-sm p-4">
          <p class="text-xs text-gray-400 mb-1">Cartera pendiente</p>
          <p class="text-xl font-bold text-red-500 leading-tight">{{ cop(stats.cartera_pendiente) }}</p>
          <p class="text-[11px] text-gray-400 mt-1">Todo lo que te deben hoy, de cualquier mes, sin filtro de período.</p>
        </div>
      </div>

      <!-- Barra de meta mensual -->
      <div v-if="stats.meta_mes?.meta" class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex items-center justify-between mb-1">
          <div>
            <p class="text-sm font-semibold text-gray-700">Meta mensual · {{ stats.meta_mes.mes }}</p>
            <!-- De qué tienda es la meta: la del equipo que cobra el pool, no
                 la de la ficha. Sin decirlo, el vendedor compara contra otra
                 tienda en el reporte y cree que los números están mal. -->
            <p v-if="stats.meta_mes.tienda" class="text-[11px] text-gray-400">
              {{ stats.meta_mes.tienda }}<span v-if="stats.meta_mes.por_que"> · {{ stats.meta_mes.por_que }}</span>
            </p>
          </div>
          <span :class="['text-xs font-bold px-2 py-0.5 rounded-full', metaTono(stats.meta_mes).badge]">
            {{ formatPct(stats.meta_mes.pct) }}%
          </span>
        </div>

        <!-- Barra de progreso -->
        <div class="h-3 bg-gray-100 rounded-full overflow-hidden mb-2">
          <div
            class="h-full rounded-full transition-all duration-700"
            :class="metaTono(stats.meta_mes).barra"
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

      <!-- ══════ MIS TIENDAS ══════ -->
      <!-- Las mismas tarjetas de la pestaña Tiendas de Reportes, pero solo de
           las tiendas de esta persona, y con cuánto de cada una es suyo. -->
      <template v-if="tiendas.length">
        <div class="pt-1">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
            {{ tiendas.length === 1 && !tiendas[0].es_independiente ? 'Mi tienda' : tiendas[0]?.es_independiente ? 'Por mi cuenta' : 'Mis tiendas' }}
          </p>
          <p class="text-[11px] text-gray-400 mt-0.5">
            Lo de toda la tienda en el período —no solo lo tuyo— para que sepas cómo va el equipo.
          </p>
        </div>

        <div class="grid grid-cols-1 gap-3">
          <div v-for="(t, i) in tiendas" :key="t.tienda_id ?? `ind-${t.usuario_id}`"
            class="bg-white rounded-xl shadow-sm p-4 border-l-4"
            :style="{ borderColor: TIENDA_COLORS[i % TIENDA_COLORS.length] }">
            <!-- Nombre arriba y la cifra debajo, no lado a lado como en
                 Reportes: en un celular el nombre se partía en dos líneas
                 peleando el ancho con el bloque de la derecha. -->
            <div class="mb-3">
              <div class="flex items-center gap-2 flex-wrap">
                <p class="font-semibold text-gray-800 leading-tight">{{ t.nombre }}</p>
                <span v-if="t.es_mi_tienda_hoy" class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full font-semibold">Hoy aquí</span>
                <span v-if="t.ciudad" class="text-xs text-gray-400">· {{ t.ciudad }}</span>
              </div>
              <div class="flex items-baseline justify-between gap-2 mt-2">
                <p class="text-[10px] uppercase tracking-wide text-gray-400">Vendido en el período</p>
                <p class="text-lg font-bold text-green-700">{{ cop(t.total_vendido) }}</p>
              </div>
              <!-- Las dos partes del total: lo que ya entró y lo que falta. -->
              <p class="text-xs text-gray-400 text-right">
                = cobrado <span class="text-gray-600 font-medium">{{ cop(t.ingresos) }}</span>
                + por cobrar <span class="text-red-500 font-medium">{{ cop(t.cartera_pendiente) }}</span>
              </p>
            </div>

            <!-- Tu parte dentro de la tienda. Es lo que esta pantalla agrega
                 sobre la tarjeta de Reportes: ahí interesa la tienda, aquí
                 interesa cuánto de eso es tuyo. -->
            <div v-if="t.mi_parte" class="mb-3 rounded-lg bg-blue-50 border border-blue-100 px-3 py-2">
              <div class="flex items-center justify-between text-xs">
                <span class="text-blue-700 font-semibold">Tu parte</span>
                <span class="text-blue-700 font-bold">
                  {{ cop(t.mi_parte.vendido) }}
                  <span v-if="t.mi_parte.pct != null" class="font-medium text-blue-500">· {{ formatPct(t.mi_parte.pct) }}%</span>
                </span>
              </div>
              <div class="h-1.5 bg-blue-100 rounded-full overflow-hidden mt-1.5">
                <div class="h-full bg-blue-500 rounded-full transition-all duration-700"
                  :style="{ width: `${Math.min(t.mi_parte.pct ?? 0, 100)}%` }" />
              </div>
              <p class="text-[11px] text-blue-500 mt-1">
                {{ t.mi_parte.ordenes }} de {{ t.ordenes_totales }} {{ t.ordenes_totales === 1 ? 'orden' : 'órdenes' }} de la tienda
              </p>
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

            <!-- De qué es lo VENDIDO (suma el total de arriba). -->
            <DesglosePorTipo
              v-if="t.vendido_por_tipo"
              :datos="t.vendido_por_tipo"
              titulo="De qué es lo vendido"
              class="mt-3"
            />
            <div v-if="t.ingresos_por_tipo && t.ingresos > 0" class="mt-2 text-[11px] text-gray-400">
              De eso ya se cobró {{ cop(t.ingresos) }}:
              <span v-if="t.ingresos_por_tipo.venta > 0">ventas {{ cop(t.ingresos_por_tipo.venta) }}</span>
              <span v-if="t.ingresos_por_tipo.restauracion > 0"> · restauraciones {{ cop(t.ingresos_por_tipo.restauracion) }}</span>
              <span v-if="t.ingresos_por_tipo.fv2 > 0"> · FV2 {{ cop(t.ingresos_por_tipo.fv2) }}</span>
            </div>
            <p v-if="t.cobranza_periodo != null" class="mt-1 text-[11px] text-gray-400">
              Caja del período <span class="text-gray-600 font-medium">{{ cop(t.cobranza_periodo) }}</span>
              <span class="text-gray-300">— todo lo que entró en estas fechas, también abonos de órdenes de meses anteriores</span>
            </p>

            <!-- Barra meta mensual de la tienda -->
            <div v-if="t.meta_mes?.meta" class="mt-3 pt-3 border-t border-gray-100">
              <div class="flex items-center justify-between mb-1">
                <p class="text-xs font-semibold text-gray-600">Meta {{ t.meta_mes.mes }}</p>
                <span :class="['text-xs font-bold px-2 py-0.5 rounded-full', metaTono(t.meta_mes).badge]">
                  {{ formatPct(t.meta_mes.pct) }}%
                </span>
              </div>
              <div class="h-2 bg-gray-100 rounded-full overflow-hidden mb-1.5">
                <div class="h-full rounded-full transition-all duration-700"
                  :class="metaTono(t.meta_mes).barra"
                  :style="{ width: `${Math.min(t.meta_mes.pct, 100)}%` }"
                />
              </div>
              <div class="flex items-center justify-between text-[11px] text-gray-400">
                <span>Cuenta para la meta: <span class="font-semibold text-gray-600">{{ cop(t.meta_mes.total_tienda) }}</span></span>
                <span>Meta: <span class="font-semibold text-gray-600">{{ cop(t.meta_mes.meta) }}</span></span>
              </div>
              <!-- No es el "vendido" de arriba: la meta se mide como en
                   Comisiones (mes calendario, sin restauraciones ni cancelados,
                   con la mitad que abonan los independientes y sin la comisión
                   del datáfono), y el período de arriba puede ser otro. -->
              <p class="mt-1 text-[10px] text-gray-300 leading-snug">
                Es lo del mes calendario que cuenta en Comisiones: solo ventas (sin restauraciones ni canceladas),
                más la mitad que le abonan los independientes, y a lo pagado con tarjeta se le quita la comisión del datáfono.
              </p>
              <p v-if="t.meta_mes.cumplida" class="mt-1 text-[11px] font-semibold text-green-600">✓ ¡Meta alcanzada!</p>
              <p v-else class="mt-1 text-[11px] text-gray-400">
                Faltan <span class="font-semibold text-gray-600">{{ cop(t.meta_mes.meta - t.meta_mes.total_tienda) }}</span>
              </p>
            </div>

            <div v-if="t.vendedor_destacado" class="mt-3 text-xs text-gray-500 flex items-center gap-1">
              <StarIcon class="w-4 h-4 text-yellow-500 inline-block" />
              <span>{{ t.vendedor_destacado.nombre }}</span>
              <span class="text-gray-400">— {{ cop(t.vendedor_destacado.ingresos) }} cobrados</span>
              <span v-if="t.vendedor_destacado.id === stats.vendedor?.id" class="text-yellow-600 font-semibold">· ¡eres tú!</span>
            </div>
          </div>
        </div>
      </template>

      <!-- ══════ DETALLE ══════ -->

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

      <!-- Órdenes del período: las que suman "Total vendido" -->
      <div v-if="stats.ordenes_recientes?.length" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-700">Órdenes del período</p>
        <p class="text-[11px] text-gray-400 mb-3">
          Las {{ stats.ordenes_creadas }} creadas en el rango. En las compartidas se te acredita la mitad.
        </p>
        <ul class="space-y-1">
          <li
            v-for="o in stats.ordenes_recientes" :key="o.id"
            @click="router.push({ name: 'orden-detalle', params: { id: o.id } })"
            class="flex items-center justify-between rounded-lg px-2 py-2.5 hover:bg-gray-50 cursor-pointer transition-colors -mx-2"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ o.cliente }}</p>
              <p class="text-xs text-gray-400">
                {{ o.serie ? `${o.serie}-${o.serie_numero}` : `#${o.numero_orden ?? o.id}` }}
                <span v-if="o.es_compartida" class="text-indigo-500 font-medium">· compartida</span>
              </p>
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
