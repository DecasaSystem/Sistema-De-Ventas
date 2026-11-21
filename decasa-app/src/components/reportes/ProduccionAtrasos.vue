<script setup>
/**
 * Reportes → Producción: las órdenes atrasadas hoy, con gráficas.
 *
 * Qué cuenta como atrasada lo decide el servidor (ReporteController::
 * buildRetrasos): fecha de compromiso o de entrega vencida, una pieza marcada
 * "retrasado" en el taller, o sin fecha y con más de 30 días. No depende del
 * período de arriba; la tienda sí manda.
 *
 * Igual que la Cartera: cada orden trae su tipo (venta, restauración o FV2)
 * y el filtro de tipo manda sobre cifras, gráficas, lista y Excel.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api'
import { hoyBogota } from '@/utils/fechas'
import { useToast } from '@/composables/useToast'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import GraficaFinanzas from '@/components/finanzas/GraficaFinanzas.vue'
import FiltroTipoOrden from '@/components/reportes/FiltroTipoOrden.vue'
import { TIPOS_ORDEN, TIPO_ORDEN } from '@/utils/tiposOrden'
import { ArrowDownTrayIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  atrasos:  { type: Array, default: () => [] },
  tiendaId: { type: [String, Number], default: '' },
  cargando: { type: Boolean, default: false },
})

const router = useRouter()
const toast  = useToast()

const cop = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n ?? 0)
function copCorto(n) {
  const v = Math.abs(Number(n) || 0)
  if (v >= 1e6) return '$' + (n / 1e6).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + ' M'
  if (v >= 1e3) return '$' + Math.round(n / 1e3).toLocaleString('es-CO') + ' mil'
  return '$' + Math.round(n)
}
const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`
const tipoDe = (r) => TIPO_ORDEN[r.tipo] ? r.tipo : 'venta'
const dias = (r) => Number(r.dias_retraso) || 0
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
function fechaCorta(f) {
  if (!f) return ''
  const [a, m, d] = String(f).substring(0, 10).split('-').map(Number)
  return `${d} ${MESES[m - 1]}${a !== Number(hoyBogota().substring(0, 4)) ? ' ' + a : ''}`
}

// ── Filtro por tipo ──────────────────────────────────────────────────────────
const tipoFiltro = ref('')
const cuantas = computed(() => Object.fromEntries(
  TIPOS_ORDEN.map(t => [t.clave, props.atrasos.filter(r => tipoDe(r) === t.clave).length])))
const filtradas = computed(() => tipoFiltro.value
  ? props.atrasos.filter(r => tipoDe(r) === tipoFiltro.value)
  : props.atrasos)
const tiposVisibles = computed(() => tipoFiltro.value
  ? TIPOS_ORDEN.filter(t => t.clave === tipoFiltro.value)
  : TIPOS_ORDEN.filter(t => cuantas.value[t.clave]))

// ── Cifras rápidas ───────────────────────────────────────────────────────────
const valorDetenido = computed(() => filtradas.value.reduce((s, r) => s + Number(r.valor_total || 0), 0))
const graves   = computed(() => filtradas.value.filter(r => dias(r) > 15))
const sinFecha = computed(() => filtradas.value.filter(r => !r.fecha_compromiso))
const promedio = computed(() => filtradas.value.length
  ? Math.round(filtradas.value.reduce((s, r) => s + Math.max(0, dias(r)), 0) / filtradas.value.length)
  : 0)

// ── Gráfica: cuánto llevan de atraso ─────────────────────────────────────────
const TRAMOS = [
  { label: '0–3 d',   hasta: 3 },
  { label: '4–7 d',   hasta: 7 },
  { label: '8–15 d',  hasta: 15 },
  { label: '16–30 d', hasta: 30 },
  { label: '+30 d',   hasta: Infinity },
]
const tramoDe = (d) => TRAMOS.findIndex(t => d <= t.hasta)

function opcionesBarras(c, { horizontal = false, nombres = null } = {}) {
  const ejeCuenta = { stacked: true, grid: { color: c.rejilla }, ticks: { precision: 0, maxTicksLimit: 6 } }
  const ejeNombre = { stacked: true, grid: { display: false }, ticks: { autoSkip: false } }
  return {
    indexAxis: horizontal ? 'y' : 'x',
    scales: horizontal ? { x: ejeCuenta, y: ejeNombre } : { x: ejeNombre, y: ejeCuenta },
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          ...(nombres ? { title: (items) => nombres[items[0].dataIndex] ?? '' } : {}),
          label: (ctx) => ` ${ctx.dataset.label}: ${plural(ctx.raw, 'orden', 'órdenes')}`,
          footer: (items) => items.length > 1 ? `Total: ${plural(items.reduce((s, i) => s + i.raw, 0), 'orden', 'órdenes')}` : '',
        },
      },
    },
  }
}

function armarTramos(c) {
  if (!filtradas.value.length) return null
  return {
    type: 'bar',
    data: {
      labels: TRAMOS.map(t => t.label),
      datasets: tiposVisibles.value.map(t => {
        const data = TRAMOS.map(() => 0)
        for (const r of filtradas.value) if (tipoDe(r) === t.clave) data[tramoDe(dias(r))]++
        return { label: t.label, data, backgroundColor: t.color, borderRadius: 4, maxBarThickness: 44 }
      }),
    },
    options: opcionesBarras(c),
  }
}

// ── Gráfica: en qué van las piezas ───────────────────────────────────────────
// Colores aparte de los de tipo, para no confundir "verde = FV2".
const ETAPAS = [
  { clave: 'piezas_sin_empezar', label: 'Sin empezar', color: '#d14d72' },
  { clave: 'piezas_en_proceso',  label: 'En proceso',  color: '#e8a23b' },
  { clave: 'piezas_listas',      label: 'Listas',      color: '#7c5cd6' },
]
const etapas = computed(() => ETAPAS.map(e => ({
  ...e, piezas: filtradas.value.reduce((s, r) => s + Number(r[e.clave] || 0), 0),
})))
const totalPiezas = computed(() => etapas.value.reduce((s, e) => s + e.piezas, 0))
const piezasDe = (r) => ETAPAS.reduce((s, e) => s + Number(r[e.clave] || 0), 0)
// Las que no tienen nada en el taller: esperan despacho, fecha o el anticipo.
const sinTaller = computed(() => filtradas.value.filter(r => piezasDe(r) === 0))

function armarEtapas(c) {
  const lista = etapas.value.filter(e => e.piezas > 0)
  if (!lista.length) return null
  const oscuro = document.documentElement.classList.contains('dark')
  return {
    type: 'doughnut',
    data: {
      labels: lista.map(e => e.label),
      datasets: [{
        data: lista.map(e => e.piezas),
        backgroundColor: lista.map(e => e.color),
        borderWidth: 2,
        borderColor: oscuro ? '#1f2937' : '#ffffff',
      }],
    },
    options: {
      cutout: '68%',
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${plural(ctx.raw, 'pieza', 'piezas')}` } },
      },
    },
  }
}

// ── Gráfica: por tienda o por vendedor ───────────────────────────────────────
const tiendasDistintas = computed(() => new Set(props.atrasos.map(r => r.tienda || '—')).size)
const agrupar = ref('tienda')
watch(tiendasDistintas, (n) => { if (n < 2) agrupar.value = 'vendedor' }, { immediate: true })

const MAX_GRUPOS = 8
const grupos = computed(() => {
  const clave = agrupar.value
  const mapa = new Map()
  for (const r of filtradas.value) {
    const nombre = r[clave] || (clave === 'tienda' ? 'Sin tienda' : 'Sin vendedor')
    if (!mapa.has(nombre)) mapa.set(nombre, { nombre, total: 0, venta: 0, restauracion: 0, fv2: 0 })
    const g = mapa.get(nombre)
    g.total++
    g[tipoDe(r)]++
  }
  const orden = [...mapa.values()].sort((a, b) => b.total - a.total)
  if (orden.length <= MAX_GRUPOS) return orden
  const resto = orden.slice(MAX_GRUPOS - 1).reduce((acc, g) => {
    acc.total += g.total; acc.venta += g.venta; acc.restauracion += g.restauracion; acc.fv2 += g.fv2
    return acc
  }, { nombre: `Otros (${orden.length - MAX_GRUPOS + 1})`, total: 0, venta: 0, restauracion: 0, fv2: 0 })
  return [...orden.slice(0, MAX_GRUPOS - 1), resto]
})
const altoGrupos = computed(() => {
  const n = grupos.value.length
  return n <= 3 ? 'h-40' : n <= 5 ? 'h-56' : 'h-72'
})

function armarGrupos(c) {
  if (!grupos.value.length) return null
  const nombres = grupos.value.map(g => g.nombre)
  return {
    type: 'bar',
    data: {
      labels: nombres.map(n => n.length > 16 ? n.slice(0, 15) + '…' : n),
      datasets: tiposVisibles.value.map(t => ({
        label: t.label, data: grupos.value.map(g => g[t.clave]), backgroundColor: t.color,
        borderRadius: 4, maxBarThickness: 22,
      })),
    },
    options: opcionesBarras(c, { horizontal: true, nombres }),
  }
}

const datosGraficas = computed(() => [tipoFiltro.value, agrupar.value, props.atrasos.length, grupos.value.length])

// ── Lista ────────────────────────────────────────────────────────────────────
const busqueda = ref('')
const ordenLista = ref('dias')   // dias | valor
const POR_PAGINA = 30
const mostrar = ref(POR_PAGINA)
watch([tipoFiltro, busqueda, ordenLista, () => props.atrasos], () => { mostrar.value = POR_PAGINA })

const normalizar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const lista = computed(() => {
  const q = normalizar(busqueda.value.trim())
  const base = q
    ? filtradas.value.filter(r => normalizar(`${r.referencia} ${r.cliente} ${r.vendedor} ${r.tienda} ${r.telefono ?? ''}`).includes(q))
    : filtradas.value
  return [...base].sort(ordenLista.value === 'valor'
    ? (a, b) => Number(b.valor_total || 0) - Number(a.valor_total || 0)
    : (a, b) => dias(b) - dias(a))
})
const visibles = computed(() => lista.value.slice(0, mostrar.value))

function retrasoColor(d) {
  if (d > 7)  return 'bg-red-100 text-red-700'
  if (d >= 3) return 'bg-orange-100 text-orange-700'
  return 'bg-yellow-100 text-yellow-700'
}
// Por qué está en la lista, en palabras.
function motivo(r) {
  if (!r.fecha_compromiso) return `Sin fecha de entrega · creada el ${fechaCorta(r.created_at)}`
  if (r.marcada_retrasada && dias(r) <= 0) return `Marcada retrasada en el taller · prometida ${fechaCorta(r.fecha_compromiso)}`
  return `Prometida para el ${fechaCorta(r.fecha_compromiso)}${r.marcada_retrasada ? ' · marcada retrasada' : ''}`
}

// ── Excel ────────────────────────────────────────────────────────────────────
const descargando = ref(false)
async function descargarExcel() {
  if (descargando.value) return
  descargando.value = true
  try {
    const params = { tipo: 'retrasos' }
    if (props.tiendaId) params.tienda_id = props.tiendaId
    if (tipoFiltro.value) params.tipo_orden = tipoFiltro.value
    const res = await api.get('/reportes/exportar', { params, responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const a   = document.createElement('a')
    a.href     = url
    a.download = `ordenes_atrasadas${tipoFiltro.value ? '_' + tipoFiltro.value : ''}_${hoyBogota()}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch {
    toast.error('No se pudo descargar el Excel. Intenta de nuevo.')
  } finally {
    descargando.value = false
  }
}
</script>

<template>
  <div class="space-y-3">
    <p v-if="cargando" class="text-center py-8 text-gray-400 text-sm">Cargando…</p>

    <template v-else>
      <!-- Total + Excel -->
      <div class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs text-gray-500">
              Atrasadas hoy{{ tipoFiltro ? ' · ' + TIPO_ORDEN[tipoFiltro].label : '' }}
            </p>
            <p class="text-2xl font-bold text-red-500 leading-tight tabular-nums">
              {{ plural(filtradas.length, 'orden', 'órdenes') }}
            </p>
            <p class="text-xs text-gray-400 mt-0.5">
              Valen {{ cop(valorDetenido) }}<template v-if="filtradas.length"> · {{ promedio }} días de atraso en promedio</template>
            </p>
          </div>
          <button
            type="button"
            @click="descargarExcel"
            :disabled="descargando || !filtradas.length"
            class="flex items-center gap-1.5 rounded-lg bg-green-600 text-white text-xs font-semibold px-3 py-2 hover:bg-green-700 disabled:opacity-50 flex-shrink-0"
          >
            <ArrowDownTrayIcon class="w-4 h-4" />
            {{ descargando ? 'Descargando…' : 'Excel' }}
          </button>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">Es lo que está atrasado hoy, sin importar el período de arriba.</p>
      </div>

      <FiltroTipoOrden v-model="tipoFiltro" :total="atrasos.length" :cuantas="cuantas" />

      <template v-if="atrasos.length">
        <!-- Cifras rápidas -->
        <div class="grid grid-cols-3 gap-2">
          <div class="bg-white rounded-xl shadow-sm p-3">
            <p class="text-[11px] text-gray-500 leading-tight">Más de 15 días</p>
            <p class="text-sm font-bold text-red-500 tabular-nums mt-0.5">{{ graves.length }}</p>
            <p class="text-[11px] text-gray-400">{{ copCorto(graves.reduce((s, r) => s + Number(r.valor_total || 0), 0)) }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-3">
            <p class="text-[11px] text-gray-500 leading-tight">Sin fecha</p>
            <p class="text-sm font-bold text-orange-600 tabular-nums mt-0.5">{{ sinFecha.length }}</p>
            <p class="text-[11px] text-gray-400">más de 30 días</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-3">
            <p class="text-[11px] text-gray-500 leading-tight">Piezas listas</p>
            <p class="text-sm font-bold text-purple-600 tabular-nums mt-0.5">
              {{ etapas[2].piezas }}<span class="text-gray-400 font-normal text-xs"> / {{ totalPiezas }}</span>
            </p>
            <p class="text-[11px] text-gray-400">esperan salir</p>
          </div>
        </div>

        <!-- Cuánto llevan de atraso -->
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-800">Cuánto llevan de atraso</p>
          <p class="text-[11px] text-gray-400 mb-2">Órdenes según los días que pasaron de la fecha prometida.</p>
          <div v-if="tiposVisibles.length > 1" class="flex flex-wrap gap-x-3 gap-y-1 mb-2">
            <span v-for="t in tiposVisibles" :key="t.clave" class="flex items-center gap-1 text-[11px] text-gray-500">
              <span class="w-2 h-2 rounded-sm" :style="{ background: t.color }" /> {{ t.label }}
            </span>
          </div>
          <GraficaFinanzas :armar="armarTramos" :datos="datosGraficas" alto="h-52"
            descripcion="Órdenes atrasadas según los días de atraso" />
        </div>

        <!-- En qué van las piezas -->
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-800">En qué van las piezas</p>
          <p class="text-[11px] text-gray-400 mb-3">Las piezas del taller de estas órdenes.</p>
          <div v-if="totalPiezas" class="flex items-center gap-4">
            <div class="relative w-28 h-28 shrink-0">
              <GraficaFinanzas :armar="armarEtapas" :datos="datosGraficas" alto="h-28"
                descripcion="Piezas de las órdenes atrasadas por etapa del taller" />
              <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                <p class="text-[10px] text-gray-400 leading-none">Piezas</p>
                <p class="text-sm font-bold text-gray-700 tabular-nums">{{ totalPiezas }}</p>
              </div>
            </div>
            <ul class="flex-1 min-w-0 space-y-2">
              <li v-for="e in etapas" :key="e.clave" class="flex items-center gap-1.5 text-xs">
                <span class="w-2.5 h-2.5 rounded-sm shrink-0" :style="{ background: e.color }" />
                <span class="text-gray-700 font-medium flex-1">{{ e.label }}</span>
                <span class="text-gray-400 tabular-nums">{{ Math.round(e.piezas / totalPiezas * 100) }}%</span>
                <span class="font-semibold text-gray-700 tabular-nums w-6 text-right">{{ e.piezas }}</span>
              </li>
            </ul>
          </div>
          <p v-else class="text-xs text-gray-400">Ninguna de estas órdenes tiene piezas en el taller.</p>
          <p v-if="sinTaller.length && totalPiezas" class="text-[11px] text-gray-400 mt-3">
            {{ plural(sinTaller.length, 'orden no tiene', 'órdenes no tienen') }} nada en el taller: esperan despacho, fecha o el anticipo.
          </p>
        </div>

        <!-- Por tienda / por vendedor -->
        <div class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center justify-between gap-2 mb-2">
            <p class="text-sm font-semibold text-gray-800">Dónde están</p>
            <div v-if="tiendasDistintas > 1" class="bg-gray-100 rounded-xl p-1 flex gap-1 shrink-0">
              <button v-for="op in [{ v: 'tienda', l: 'Tienda' }, { v: 'vendedor', l: 'Vendedor' }]" :key="op.v"
                type="button" @click="agrupar = op.v"
                :class="['rounded-lg px-2.5 py-1 text-[11px] font-semibold',
                  agrupar === op.v ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
                {{ op.l }}
              </button>
            </div>
          </div>
          <div v-if="tiposVisibles.length > 1" class="flex flex-wrap gap-x-3 gap-y-1 mb-2">
            <span v-for="t in tiposVisibles" :key="t.clave" class="flex items-center gap-1 text-[11px] text-gray-500">
              <span class="w-2 h-2 rounded-sm" :style="{ background: t.color }" /> {{ t.label }}
            </span>
          </div>
          <GraficaFinanzas :armar="armarGrupos" :datos="datosGraficas" :alto="altoGrupos"
            :descripcion="`Órdenes atrasadas por ${agrupar}`" />
        </div>

        <!-- Lista -->
        <div class="flex gap-2">
          <div class="relative flex-1 min-w-0">
            <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
            <input
              v-model="busqueda"
              type="search"
              placeholder="Buscar cliente, orden, vendedor…"
              class="w-full rounded-lg border border-gray-200 bg-white pl-8 pr-2 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <select v-model="ordenLista"
            class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-xs text-gray-700 shrink-0">
            <option value="dias">Más atraso</option>
            <option value="valor">Mayor valor</option>
          </select>
        </div>

        <ul class="space-y-2">
          <li v-for="r in visibles" :key="r.orden_id"
            @click="router.push({ name: 'orden-detalle', params: { id: r.orden_id } })"
            class="bg-white rounded-xl shadow-sm p-3.5 cursor-pointer hover:shadow-md transition-shadow border-l-4"
            :style="{ borderLeftColor: TIPO_ORDEN[tipoDe(r)].color }">
            <div class="flex justify-between items-start gap-2">
              <div class="min-w-0">
                <p class="font-medium text-sm text-gray-800 truncate">
                  <span class="text-gray-400 font-semibold">{{ r.referencia }}</span> · {{ r.cliente }}
                </p>
                <p class="text-xs text-gray-400 truncate">{{ r.vendedor }} · {{ r.tienda }}</p>
              </div>
              <span :class="['flex-shrink-0 text-xs font-bold px-2.5 py-1 rounded-full', retrasoColor(dias(r))]">
                +{{ Math.max(0, dias(r)) }}d
              </span>
            </div>

            <p class="text-[11px] text-gray-500 mt-1.5">{{ motivo(r) }}</p>

            <!-- Las piezas del taller, por etapa -->
            <div v-if="piezasDe(r)" class="mt-2 flex items-center gap-2">
              <div class="flex-1 flex h-1.5 rounded-full overflow-hidden bg-gray-100" style="gap: 2px">
                <template v-for="e in ETAPAS" :key="e.clave">
                  <div v-if="r[e.clave]" :style="{ flex: `${r[e.clave]} 0 0`, background: e.color }" />
                </template>
              </div>
              <span class="text-[11px] text-gray-500 tabular-nums shrink-0">
                {{ r.piezas_listas }} de {{ plural(piezasDe(r), 'pieza lista', 'piezas listas') }}
              </span>
            </div>

            <div class="mt-2 flex items-center gap-1.5 flex-wrap">
              <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 flex items-center gap-1">
                <span class="w-1.5 h-1.5 rounded-full" :style="{ background: TIPO_ORDEN[tipoDe(r)].color }" />
                {{ TIPO_ORDEN[tipoDe(r)].corto }}
              </span>
              <BadgeEstado :estado="r.estado" />
              <span class="text-[11px] text-gray-400">{{ plural(r.items_count, 'ítem', 'ítems') }}</span>
              <span class="ml-auto text-xs font-semibold text-gray-700 tabular-nums">{{ cop(r.valor_total) }}</span>
            </div>
          </li>
        </ul>

        <p v-if="!lista.length" class="text-center py-6 text-gray-400 text-sm">Nada coincide con la búsqueda.</p>
        <button v-if="lista.length > mostrar" type="button"
          @click="mostrar += POR_PAGINA"
          class="w-full rounded-lg bg-white shadow-sm py-2.5 text-xs font-semibold text-blue-600">
          Ver {{ Math.min(POR_PAGINA, lista.length - mostrar) }} más de {{ lista.length - mostrar }}
        </button>
      </template>

      <p v-else class="text-center py-8 text-gray-400 text-sm">Sin órdenes atrasadas.</p>
    </template>
  </div>
</template>
