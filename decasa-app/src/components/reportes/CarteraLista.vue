<script setup>
/**
 * La cartera: las órdenes que todavía deben, con gráficas y su Excel.
 *
 * La usan Reportes (supervisor, con el filtro de tienda) y Mis estadísticas
 * (vendedor, la de su tienda). Qué órdenes entran y quién ve cuáles lo decide
 * el servidor (App\Services\Cartera), y el Excel sale de la misma regla: lo
 * que se descarga es lo que se ve, también con el filtro de tipo.
 *
 * Cada orden trae su tipo (venta, restauración o FV2) con la regla de
 * Orden::sqlTipo, la misma del desglose de Reportes. El filtro de tipo
 * manda sobre las cifras, las gráficas, la lista y el Excel; la dona
 * siempre muestra los tres para que se vea cuánto pesa lo escogido.
 */
import { computed, ref, watch } from 'vue'
import { hoyBogota } from '@/utils/fechas'
import { useRouter } from 'vue-router'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import GraficaFinanzas from '@/components/finanzas/GraficaFinanzas.vue'
import { TIPOS_ORDEN, TIPO_ORDEN } from '@/utils/tiposOrden'
import { ArrowDownTrayIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  ordenes:  { type: Array, default: () => [] },
  // La tienda elegida en el filtro (supervisor). Al vendedor el servidor le
  // da siempre la suya, venga lo que venga.
  tiendaId: { type: [String, Number], default: '' },
  cargando: { type: Boolean, default: false },
})

const router = useRouter()
const toast  = useToast()

const cop = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n ?? 0)
// Para los ejes: "$12,5 M" cabe en un celular, "$12.500.000" no.
function copCorto(n) {
  const v = Math.abs(Number(n) || 0)
  if (v >= 1e6) return '$' + (n / 1e6).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + ' M'
  if (v >= 1e3) return '$' + Math.round(n / 1e3).toLocaleString('es-CO') + ' mil'
  return '$' + Math.round(n)
}
const saldo = (o) => Number(o.saldo_pendiente || 0)
const sumar = (lista) => lista.reduce((s, o) => s + saldo(o), 0)
const tipoDe = (o) => TIPO_ORDEN[o.tipo] ? o.tipo : 'venta'

// ── Filtro por tipo ──────────────────────────────────────────────────────────
const tipoFiltro = ref('')   // '' = todo

const porTipo = computed(() => TIPOS_ORDEN.map(t => {
  const suyas = props.ordenes.filter(o => tipoDe(o) === t.clave)
  return { ...t, ordenes: suyas.length, monto: sumar(suyas) }
}))
const totalTodo = computed(() => sumar(props.ordenes))

const filtradas = computed(() => tipoFiltro.value
  ? props.ordenes.filter(o => tipoDe(o) === tipoFiltro.value)
  : props.ordenes)
const totalSaldo = computed(() => sumar(filtradas.value))
// Las series de las gráficas apiladas: solo el tipo escogido, o los tres.
const tiposVisibles = computed(() => tipoFiltro.value
  ? TIPOS_ORDEN.filter(t => t.clave === tipoFiltro.value)
  : TIPOS_ORDEN.filter(t => porTipo.value.find(p => p.clave === t.clave)?.ordenes))

// ── Cifras rápidas ───────────────────────────────────────────────────────────
// "Ya entregado" es lo que más preocupa: el mueble salió y la plata no.
const entregadas  = computed(() => filtradas.value.filter(o => o.estado === 'entregado'))
const viejas      = computed(() => filtradas.value.filter(o => (o.dias_sin_pagar ?? 0) > 30))
const pctCobrado  = computed(() => {
  const total  = filtradas.value.reduce((s, o) => s + Number(o.valor_total || 0), 0)
  const pagado = filtradas.value.reduce((s, o) => s + Number(o.total_pagado || 0), 0)
  return total > 0 ? Math.round(pagado / total * 100) : 0
})

// ── Gráfica: de qué es la deuda (dona) ───────────────────────────────────────
const esOscuro = () => document.documentElement.classList.contains('dark')
const conDeuda = computed(() => porTipo.value.filter(t => t.monto > 0))

function armarDona(c) {
  const lista = conDeuda.value
  if (!lista.length) return null
  const apagado = esOscuro() ? '#334155' : '#e5e7eb'
  return {
    type: 'doughnut',
    data: {
      labels: lista.map(t => t.label),
      datasets: [{
        data: lista.map(t => t.monto),
        backgroundColor: lista.map(t => !tipoFiltro.value || tipoFiltro.value === t.clave ? t.color : apagado),
        borderWidth: 2,
        borderColor: esOscuro() ? '#1f2937' : '#ffffff',
      }],
    },
    options: {
      cutout: '68%',
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${cop(ctx.raw)}` } },
      },
      onClick: (_e, els) => {
        if (!els.length) return
        const clave = lista[els[0].index].clave
        tipoFiltro.value = tipoFiltro.value === clave ? '' : clave
      },
    },
  }
}

// ── Gráfica: antigüedad ──────────────────────────────────────────────────────
// Días desde que se hizo la orden, los mismos de la pastilla de cada tarjeta.
const TRAMOS = [
  { label: '0–15 d',  hasta: 15 },
  { label: '16–30 d', hasta: 30 },
  { label: '31–60 d', hasta: 60 },
  { label: '61–90 d', hasta: 90 },
  { label: '+90 d',   hasta: Infinity },
]
const tramoDe = (d) => TRAMOS.findIndex(t => (d ?? 0) <= t.hasta)

const antiguedad = computed(() => tiposVisibles.value.map(t => {
  const montos = TRAMOS.map(() => 0)
  for (const o of filtradas.value) {
    if (tipoDe(o) === t.clave) montos[tramoDe(o.dias_sin_pagar)] += saldo(o)
  }
  return { ...t, montos }
}))

function opcionesBarras(c, { horizontal = false } = {}) {
  const ejeMonto = {
    stacked: true,
    grid: { color: c.rejilla },
    ticks: { callback: (v) => copCorto(v), maxTicksLimit: 5 },
  }
  const ejeNombre = { stacked: true, grid: { display: false }, ticks: { autoSkip: false } }
  return {
    indexAxis: horizontal ? 'y' : 'x',
    scales: horizontal ? { x: ejeMonto, y: ejeNombre } : { x: ejeNombre, y: ejeMonto },
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: (ctx) => ` ${ctx.dataset.label}: ${cop(ctx.raw)}`,
          footer: (items) => items.length > 1 ? `Total: ${cop(items.reduce((s, i) => s + i.raw, 0))}` : '',
        },
      },
    },
  }
}

function armarAntiguedad(c) {
  if (!filtradas.value.length) return null
  return {
    type: 'bar',
    data: {
      labels: TRAMOS.map(t => t.label),
      datasets: antiguedad.value.map(t => ({
        label: t.label, data: t.montos, backgroundColor: t.color, borderRadius: 4, maxBarThickness: 44,
      })),
    },
    options: opcionesBarras(c),
  }
}

// ── Gráfica: por tienda o por vendedor ───────────────────────────────────────
const tiendasDistintas = computed(() => new Set(props.ordenes.map(o => o.tienda || '—')).size)
const agrupar = ref('tienda')
watch(tiendasDistintas, (n) => { if (n < 2) agrupar.value = 'vendedor' }, { immediate: true })

const MAX_GRUPOS = 8
const grupos = computed(() => {
  const clave = agrupar.value
  const mapa = new Map()
  for (const o of filtradas.value) {
    const nombre = o[clave] || (clave === 'tienda' ? 'Sin tienda' : 'Sin vendedor')
    if (!mapa.has(nombre)) mapa.set(nombre, { nombre, total: 0, venta: 0, restauracion: 0, fv2: 0 })
    const g = mapa.get(nombre)
    g.total += saldo(o)
    g[tipoDe(o)] += saldo(o)
  }
  const orden = [...mapa.values()].sort((a, b) => b.total - a.total)
  if (orden.length <= MAX_GRUPOS) return orden
  // Los demás se juntan: diez barras finitas en un celular no se leen.
  const resto = orden.slice(MAX_GRUPOS - 1).reduce((r, g) => {
    r.total += g.total; r.venta += g.venta; r.restauracion += g.restauracion; r.fv2 += g.fv2
    return r
  }, { nombre: `Otros (${orden.length - MAX_GRUPOS + 1})`, total: 0, venta: 0, restauracion: 0, fv2: 0 })
  return [...orden.slice(0, MAX_GRUPOS - 1), resto]
})
const altoGrupos = computed(() => {
  const n = grupos.value.length
  return n <= 3 ? 'h-40' : n <= 5 ? 'h-56' : 'h-72'
})

function armarGrupos(c) {
  if (!grupos.value.length) return null
  return {
    type: 'bar',
    data: {
      // Nombres largos se cortan: el tooltip trae el completo.
      labels: grupos.value.map(g => g.nombre.length > 16 ? g.nombre.slice(0, 15) + '…' : g.nombre),
      datasets: tiposVisibles.value.map(t => ({
        label: t.label, data: grupos.value.map(g => g[t.clave]), backgroundColor: t.color,
        borderRadius: 4, maxBarThickness: 22,
      })),
    },
    options: {
      ...opcionesBarras(c, { horizontal: true }),
      plugins: {
        ...opcionesBarras(c, { horizontal: true }).plugins,
        tooltip: {
          callbacks: {
            title: (items) => grupos.value[items[0].dataIndex]?.nombre ?? '',
            label: (ctx) => ` ${ctx.dataset.label}: ${cop(ctx.raw)}`,
            footer: (items) => items.length > 1 ? `Total: ${cop(items.reduce((s, i) => s + i.raw, 0))}` : '',
          },
        },
      },
    },
  }
}

// Lo que se vigila para redibujar (y el modo oscuro, que lo vigila la gráfica).
const datosGraficas = computed(() => [tipoFiltro.value, agrupar.value, props.ordenes.length, totalTodo.value])

// ── Lista ────────────────────────────────────────────────────────────────────
const busqueda = ref('')
const ordenLista = ref('saldo')   // saldo | dias
const POR_PAGINA = 30
const mostrar = ref(POR_PAGINA)
watch([tipoFiltro, busqueda, ordenLista, () => props.ordenes], () => { mostrar.value = POR_PAGINA })

const normalizar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const lista = computed(() => {
  const q = normalizar(busqueda.value.trim())
  const base = q
    ? filtradas.value.filter(o => normalizar(`${o.referencia} ${o.cliente} ${o.vendedor} ${o.tienda} ${o.telefono ?? ''}`).includes(q))
    : filtradas.value
  return [...base].sort(ordenLista.value === 'dias'
    ? (a, b) => (b.dias_sin_pagar ?? 0) - (a.dias_sin_pagar ?? 0)
    : (a, b) => saldo(b) - saldo(a))
})
const visibles = computed(() => lista.value.slice(0, mostrar.value))

// Los mismos cortes que ya tenía la cartera de Reportes.
function diasColor(d) {
  if (d > 15) return 'bg-red-100 text-red-700'
  if (d > 7)  return 'bg-orange-100 text-orange-700'
  return 'bg-yellow-100 text-yellow-700'
}
const pctPagado = (o) => {
  const t = Number(o.valor_total || 0)
  return t > 0 ? Math.min(100, Math.round(Number(o.total_pagado || 0) / t * 100)) : 0
}

// ── Excel ────────────────────────────────────────────────────────────────────
const descargando = ref(false)
async function descargarExcel() {
  if (descargando.value) return
  descargando.value = true
  try {
    const params = { tipo: 'pendientes' }
    if (props.tiendaId) params.tienda_id = props.tiendaId
    if (tipoFiltro.value) params.tipo_orden = tipoFiltro.value
    const res = await api.get('/reportes/exportar', { params, responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const a   = document.createElement('a')
    a.href     = url
    a.download = `cartera_por_cobrar${tipoFiltro.value ? '_' + tipoFiltro.value : ''}_${hoyBogota()}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch {
    // Antes el error solo iba a la consola: el botón parecía no hacer nada.
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
              Por cobrar{{ tipoFiltro ? ' · ' + TIPO_ORDEN[tipoFiltro].label : '' }}
            </p>
            <p class="text-2xl font-bold text-red-500 leading-tight tabular-nums">{{ cop(totalSaldo) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">
              {{ filtradas.length }} {{ filtradas.length !== 1 ? 'órdenes' : 'orden' }} con saldo
              <template v-if="tipoFiltro && totalTodo > 0">
                · {{ Math.round(totalSaldo / totalTodo * 100) }}% de toda la cartera
              </template>
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
        <p class="text-[11px] text-gray-400 mt-2">Es lo que deben hoy, entregado o no: no depende del período.</p>
      </div>

      <!-- Filtro por tipo -->
      <!-- Cuatro columnas fijas, nombre arriba y cuántas abajo: en 390 px
           una fila de pastillas con "Restauraciones 9" se salía de la pantalla. -->
      <div class="bg-gray-100 rounded-xl p-1 grid grid-cols-4 gap-1">
        <button
          type="button"
          @click="tipoFiltro = ''"
          :class="['rounded-lg px-1 py-1.5 text-[11px] font-semibold transition leading-tight',
            !tipoFiltro ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >
          Todo
          <span class="block text-gray-400 font-normal">{{ ordenes.length }}</span>
        </button>
        <button
          v-for="t in porTipo" :key="t.clave"
          type="button"
          @click="tipoFiltro = t.clave"
          :disabled="!t.ordenes"
          :class="['rounded-lg px-1 py-1.5 text-[11px] font-semibold transition leading-tight min-w-0 disabled:opacity-40',
            tipoFiltro === t.clave ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >
          <span class="block truncate">{{ t.corto }}</span>
          <span class="flex items-center justify-center gap-1 text-gray-400 font-normal">
            <span class="w-1.5 h-1.5 rounded-sm shrink-0" :style="{ background: t.color }" />
            {{ t.ordenes }}
          </span>
        </button>
      </div>

      <template v-if="ordenes.length">
        <!-- Cifras rápidas -->
        <div class="grid grid-cols-3 gap-2">
          <div class="bg-white rounded-xl shadow-sm p-3">
            <p class="text-[11px] text-gray-500 leading-tight">Ya entregado</p>
            <p class="text-sm font-bold text-red-500 tabular-nums mt-0.5">{{ copCorto(sumar(entregadas)) }}</p>
            <p class="text-[11px] text-gray-400">{{ entregadas.length }} {{ entregadas.length !== 1 ? 'órdenes' : 'orden' }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-3">
            <p class="text-[11px] text-gray-500 leading-tight">Más de 30 días</p>
            <p class="text-sm font-bold text-orange-600 tabular-nums mt-0.5">{{ copCorto(sumar(viejas)) }}</p>
            <p class="text-[11px] text-gray-400">{{ viejas.length }} {{ viejas.length !== 1 ? 'órdenes' : 'orden' }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-3">
            <p class="text-[11px] text-gray-500 leading-tight">Ya abonaron</p>
            <p class="text-sm font-bold text-green-600 tabular-nums mt-0.5">{{ pctCobrado }}%</p>
            <p class="text-[11px] text-gray-400">de lo que valen</p>
          </div>
        </div>

        <!-- De qué es la deuda -->
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-800">De qué es la deuda</p>
          <p class="text-[11px] text-gray-400 mb-3">Toca un tipo para ver solo ese.</p>
          <div class="flex items-center gap-4">
            <div class="relative w-28 h-28 shrink-0">
              <GraficaFinanzas :armar="armarDona" :datos="datosGraficas" alto="h-28"
                descripcion="Saldo por cobrar por tipo de orden" />
              <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                <p class="text-[10px] text-gray-400 leading-none">Total</p>
                <p class="text-xs font-bold text-gray-700 tabular-nums">{{ copCorto(totalTodo) }}</p>
              </div>
            </div>
            <ul class="flex-1 min-w-0 space-y-1.5">
              <li v-for="t in porTipo" :key="t.clave">
                <button
                  type="button"
                  @click="tipoFiltro = tipoFiltro === t.clave ? '' : t.clave"
                  :disabled="!t.ordenes"
                  :class="['w-full text-left rounded-lg px-2 py-1 transition disabled:opacity-40',
                    tipoFiltro === t.clave ? 'bg-gray-100' : 'hover:bg-gray-100',
                    tipoFiltro && tipoFiltro !== t.clave ? 'opacity-50' : '']"
                >
                  <div class="flex items-center gap-1.5 text-xs">
                    <span class="w-2.5 h-2.5 rounded-sm shrink-0" :style="{ background: t.color }" />
                    <span class="text-gray-700 font-medium flex-1 truncate">{{ t.label }}</span>
                    <span class="text-gray-400 tabular-nums">{{ totalTodo ? Math.round(t.monto / totalTodo * 100) : 0 }}%</span>
                  </div>
                  <div class="flex justify-between text-[11px] pl-4">
                    <span class="text-gray-400">{{ t.ordenes }} {{ t.ordenes !== 1 ? 'órdenes' : 'orden' }}</span>
                    <span class="font-semibold text-gray-700 tabular-nums">{{ cop(t.monto) }}</span>
                  </div>
                </button>
              </li>
            </ul>
          </div>
        </div>

        <!-- Antigüedad -->
        <div class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-800">Hace cuánto deben</p>
          <p class="text-[11px] text-gray-400 mb-2">Saldo según los días desde que se hizo la orden.</p>
          <div v-if="tiposVisibles.length > 1" class="flex flex-wrap gap-x-3 gap-y-1 mb-2">
            <span v-for="t in tiposVisibles" :key="t.clave" class="flex items-center gap-1 text-[11px] text-gray-500">
              <span class="w-2 h-2 rounded-sm" :style="{ background: t.color }" /> {{ t.label }}
            </span>
          </div>
          <GraficaFinanzas :armar="armarAntiguedad" :datos="datosGraficas" alto="h-52"
            descripcion="Saldo por cobrar según la antigüedad de la orden" />
        </div>

        <!-- Por tienda / por vendedor -->
        <div class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center justify-between gap-2 mb-2">
            <p class="text-sm font-semibold text-gray-800">Dónde está la deuda</p>
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
          <GraficaFinanzas :armar="armarGrupos" :datos="[...datosGraficas, grupos.length]" :alto="altoGrupos"
            :descripcion="`Saldo por cobrar por ${agrupar}`" />
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
            <option value="saldo">Mayor saldo</option>
            <option value="dias">Más antiguas</option>
          </select>
        </div>

        <ul class="space-y-2">
          <li v-for="o in visibles" :key="o.orden_id"
            @click="router.push({ name: 'orden-detalle', params: { id: o.orden_id } })"
            class="bg-white rounded-xl shadow-sm p-3.5 cursor-pointer hover:shadow-md transition-shadow border-l-4"
            :style="{ borderLeftColor: TIPO_ORDEN[tipoDe(o)].color }">
            <div class="flex justify-between items-start gap-2">
              <div class="min-w-0">
                <p class="font-medium text-sm text-gray-800 truncate">
                  <span class="text-gray-400 font-semibold">{{ o.referencia }}</span> · {{ o.cliente }}
                </p>
                <p class="text-xs text-gray-400 truncate">{{ o.vendedor }} · {{ o.tienda }}</p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-sm font-bold text-red-500 tabular-nums">{{ cop(o.saldo_pendiente) }}</p>
                <p class="text-[11px] text-gray-400">de {{ cop(o.valor_total) }}</p>
              </div>
            </div>

            <!-- Cuánto lleva pagado -->
            <div class="mt-2 flex items-center gap-2">
              <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                <div class="h-full bg-green-500 rounded-full" :style="{ width: pctPagado(o) + '%' }" />
              </div>
              <span class="text-[11px] text-green-600 font-medium tabular-nums shrink-0">
                Pagó {{ cop(o.total_pagado) }} · {{ pctPagado(o) }}%
              </span>
            </div>

            <div class="mt-2 flex items-center gap-1.5 flex-wrap">
              <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 flex items-center gap-1">
                <span class="w-1.5 h-1.5 rounded-full" :style="{ background: TIPO_ORDEN[tipoDe(o)].color }" />
                {{ TIPO_ORDEN[tipoDe(o)].corto }}
              </span>
              <BadgeEstado :estado="o.estado" />
              <span v-if="o.dias_sin_pagar != null"
                :class="['text-[11px] font-semibold px-2 py-0.5 rounded-full', diasColor(o.dias_sin_pagar)]">
                {{ o.dias_sin_pagar }}d
              </span>
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

      <p v-else class="text-center py-8 text-gray-400 text-sm">No hay cartera pendiente.</p>
    </template>
  </div>
</template>
