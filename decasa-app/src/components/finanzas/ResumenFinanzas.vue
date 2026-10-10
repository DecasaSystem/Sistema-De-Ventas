<script setup>
// "¿Cómo vamos?" en cinco segundos: el semáforo, cuatro cifras, cuánto falta
// para no perder, a dónde se va cada peso y lo próximo por pagar.
import { ref, computed, watch } from 'vue'
import {
  ArrowTrendingUpIcon, ArrowTrendingDownIcon, ExclamationTriangleIcon, CheckCircleIcon,
  InformationCircleIcon, ChevronRightIcon,
} from '@heroicons/vue/24/outline'
import { getResumen, getEstadoResultados } from '@/api/finanzas'
import { pesos, pesosCorto, pct, sumarMeses, nombreMes, fechaCorta } from '@/utils/finanzas'
import GraficaFinanzas from './GraficaFinanzas.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({ mes: String, version: Number })
const emit = defineEmits(['ir', 'pagar'])
const toast = useToast()

const cargando = ref(true)
const datos = ref(null)
const serie = ref([])
const verTodoSemaforo = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const [r, er] = await Promise.all([
      getResumen(props.mes),
      getEstadoResultados(sumarMeses(props.mes, -11), props.mes),
    ])
    datos.value = r.data
    // Desde el primer mes con ventas: antes no existía el sistema.
    const desdeVentas = er.data.meses.findIndex(x => x.ventas > 0)
    serie.value = desdeVentas > 0 ? er.data.meses.slice(desdeVentas) : er.data.meses
  } catch {
    toast.error('No se pudo cargar el resumen')
  } finally {
    cargando.value = false
  }
}
watch(() => [props.mes, props.version], cargar, { immediate: true })

const m = computed(() => datos.value?.mes)
const ind = computed(() => datos.value?.indicadores)

const costosTotales = computed(() => {
  if (!m.value) return 0
  return m.value.costo_produccion.monto + m.value.nomina.total + m.value.comisiones.total
    + m.value.gastos.total + m.value.financieros.total
})

const semaforo = computed(() => {
  const s = ind.value?.semaforo ?? []
  return verTodoSemaforo.value ? s : s.filter(x => x.nivel !== 'bien').slice(0, 3)
})

const ICONO_NIVEL = { alerta: ExclamationTriangleIcon, atencion: ExclamationTriangleIcon, info: InformationCircleIcon, bien: CheckCircleIcon }
const COLOR_NIVEL = {
  alerta: 'border-red-200 text-red-700', atencion: 'border-amber-200 text-amber-700',
  info: 'border-blue-200 text-blue-700', bien: 'border-green-200 text-green-700',
}

function variacion(v) {
  if (v === null || v === undefined) return null
  return { texto: `${v >= 0 ? '+' : ''}${pct(v, 0)}`, sube: v >= 0 }
}

const pe = computed(() => ind.value?.punto_equilibrio)
const avancePe = computed(() => Math.min(1, pe.value?.avance ?? 0))

// ── Cascada: del peso vendido a la utilidad ──────────────────────────────────
function armarCascada(c) {
  if (!m.value) return null
  const pasos = [
    ['Ventas', m.value.ventas, 'total'],
    ['IVA', -m.value.iva],
    ['Materiales', -m.value.costo_produccion.monto],
    ['Nómina', -m.value.nomina.total],
    ['Comisiones', -m.value.comisiones.total],
    ['Gastos', -m.value.gastos.total],
    ['Financieros', -m.value.financieros.total],
    ['Utilidad', m.value.utilidad_operativa, 'total'],
  ]
  let acum = 0
  const barras = []
  const colores = []
  for (const [, valor, tipo] of pasos) {
    if (tipo === 'total') {
      barras.push([0, valor])
      colores.push(valor >= 0 ? (barras.length === 1 ? c.ingresos : c.utilidad) : c.perdida)
      acum = valor
    } else {
      barras.push([acum + valor, acum])
      colores.push(c.egresos)
      acum += valor
    }
  }
  return {
    type: 'bar',
    data: { labels: pasos.map(p => p[0]), datasets: [{ data: barras, backgroundColor: colores, borderRadius: 4, borderSkipped: false }] },
    // Horizontal: en el celular las etiquetas se leen derechas.
    options: {
      indexAxis: 'y',
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => ' ' + pesos(pasos[ctx.dataIndex][1]) } },
      },
      scales: {
        y: { ticks: { font: { size: 11 } }, grid: { display: false } },
        x: { ticks: { callback: pesosCorto, font: { size: 10 }, maxTicksLimit: 5 }, grid: { color: c.rejilla } },
      },
    },
  }
}

// ── Dona: a dónde se va cada peso vendido ────────────────────────────────────
const reparto = computed(() => {
  if (!m.value || !m.value.ventas) return []
  const partes = [
    ['IVA', m.value.iva, 'iva'],
    ['Materiales', m.value.costo_produccion.monto, 'materiales'],
    ['Nómina', m.value.nomina.total, 'nomina'],
    ['Comisiones', m.value.comisiones.total, 'comisiones'],
    ['Gastos', m.value.gastos.total, 'gastos'],
    ['Financieros', m.value.financieros.total, 'financieros'],
  ]
  if (m.value.utilidad_operativa > 0) partes.push(['Utilidad', m.value.utilidad_operativa, 'utilidad'])
  return partes.filter(p => p[1] > 0).map(([nombre, valor, color]) => ({ nombre, valor, color, parte: valor / m.value.ventas }))
})

function armarDona(c) {
  if (!reparto.value.length) return null
  return {
    type: 'doughnut',
    data: {
      labels: reparto.value.map(r => r.nombre),
      datasets: [{ data: reparto.value.map(r => r.valor), backgroundColor: reparto.value.map(r => c[r.color]), borderWidth: 0 }],
    },
    options: {
      cutout: '62%',
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${pesos(ctx.raw)}` } } },
    },
  }
}

// ── 12 meses: ingresos netos contra costos, y la utilidad ────────────────────
function armarTendencia(c) {
  if (!serie.value.length) return null
  return {
    type: 'bar',
    data: {
      labels: serie.value.map(s => nombreMes(s.mes, true)),
      datasets: [
        { type: 'line', label: 'Utilidad', data: serie.value.map(s => s.utilidad_operativa), borderColor: c.utilidad,
          backgroundColor: c.utilidad, tension: 0.3, pointRadius: 2, yAxisID: 'y', order: 0 },
        { label: 'Ingresos netos', data: serie.value.map(s => s.ingresos_netos), backgroundColor: c.ingresos, borderRadius: 3, order: 1 },
        { label: 'Costos y gastos', data: serie.value.map(s => s.ingresos_netos - s.utilidad_operativa), backgroundColor: c.egresos, borderRadius: 3, order: 1 },
      ],
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${pesos(ctx.raw)}` } },
      },
      scales: {
        x: { ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 6 }, grid: { display: false } },
        y: { ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla } },
      },
    },
  }
}

const mejorMes = computed(() => {
  const con = serie.value.filter(s => s.ventas > 0 && !s.parcial)
  if (!con.length) return null
  return con.reduce((a, b) => (b.utilidad_operativa > a.utilidad_operativa ? b : a))
})

// ── Nómina por componentes y cartera por antigüedad (barras apiladas) ───────
function armarApilada(etiqueta, partes, c) {
  return {
    type: 'bar',
    data: {
      labels: [etiqueta],
      datasets: partes.map((p, i) => ({ label: p.nombre, data: [p.valor], backgroundColor: p.color ?? c.serie[i], borderRadius: 3, barThickness: 22 })),
    },
    options: {
      indexAxis: 'y',
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${pesos(ctx.raw)}` } },
      },
      scales: {
        x: { stacked: true, display: false },
        y: { stacked: true, display: false },
      },
    },
  }
}

const armarNomina = (c) => m.value && m.value.nomina.total ? armarApilada('Nómina', [
  { nombre: 'Sueldos', valor: m.value.nomina.sueldos, color: c.nomina },
  { nombre: 'Aportes', valor: m.value.nomina.aportes, color: c.comisiones },
  { nombre: 'Prestaciones', valor: m.value.nomina.prestaciones, color: c.gastos },
], c) : null

const armarCartera = (c) => ind.value?.cartera?.total ? armarApilada('Cartera', [
  { nombre: '0–30 días', valor: ind.value.cartera.tramos['0_30'], color: c.utilidad },
  { nombre: '31–90 días', valor: ind.value.cartera.tramos['31_90'], color: c.gastos },
  { nombre: 'Más de 90', valor: ind.value.cartera.tramos['mas_90'], color: c.perdida },
], c) : null

const ICONO_TIPO = { nomina: '👥', comisiones: '🎯', gasto: '🧾', impuesto: '🏛️', prestaciones: '🎁', proveedor: '📦' }
</script>

<template>
  <div v-if="cargando && !datos" class="flex justify-center py-16"><AppSpinner /></div>

  <div v-else-if="m" class="space-y-4" :class="cargando && 'opacity-60'">
    <!-- Primer uso: sin gastos, la utilidad engaña. -->
    <div v-if="m.sin_gastos_registrados" class="bg-white rounded-xl shadow-sm p-4 border border-amber-200">
      <p class="text-sm font-semibold text-gray-800">Empieza por los gastos fijos</p>
      <p class="text-xs text-gray-500 mt-1">
        Todavía no hay gastos registrados en este mes, así que la utilidad sale más alta de lo que es.
        Agrega el arriendo, los servicios, el internet y las licencias una sola vez: cada mes aparecen solos por pagar.
      </p>
      <button @click="emit('ir', 'gastos')" class="mt-3 bg-blue-600 text-white text-xs font-semibold rounded-lg px-3 py-2">Registrar gastos fijos</button>
    </div>

    <!-- Semáforo -->
    <div v-if="semaforo.length" class="space-y-2">
      <div v-for="s in semaforo" :key="s.clave" :class="['bg-white rounded-xl shadow-sm border-l-4 px-3 py-2.5 flex gap-2.5', COLOR_NIVEL[s.nivel]]">
        <component :is="ICONO_NIVEL[s.nivel]" class="w-5 h-5 shrink-0 mt-0.5" />
        <div class="min-w-0">
          <p class="text-sm font-semibold">{{ s.titulo }}</p>
          <p class="text-xs text-gray-600">{{ s.texto }}</p>
        </div>
      </div>
      <button v-if="(ind.semaforo ?? []).length > semaforo.length || verTodoSemaforo"
        @click="verTodoSemaforo = !verTodoSemaforo" class="text-xs font-semibold text-blue-600">
        {{ verTodoSemaforo ? 'Ver menos' : 'Ver todos los indicadores' }}
      </button>
    </div>

    <!-- Cuatro cifras -->
    <div class="grid grid-cols-2 gap-2.5">
      <div class="bg-white rounded-xl shadow-sm p-3">
        <p class="text-xs text-gray-400">Vendido</p>
        <p class="text-lg font-bold text-gray-800" :title="pesos(m.ventas)">{{ pesosCorto(m.ventas) }}</p>
        <p v-if="variacion(ind.variacion?.ventas)" :class="['text-[11px] font-semibold flex items-center gap-0.5', variacion(ind.variacion.ventas).sube ? 'text-green-600' : 'text-red-600']">
          <component :is="variacion(ind.variacion.ventas).sube ? ArrowTrendingUpIcon : ArrowTrendingDownIcon" class="w-3.5 h-3.5" />
          {{ variacion(ind.variacion.ventas).texto }} vs {{ nombreMes(datos.anterior.mes, true) }}
        </p>
        <p class="text-[11px] text-gray-400">{{ m.ordenes }} órdenes</p>
      </div>
      <div class="bg-white rounded-xl shadow-sm p-3">
        <p class="text-xs text-gray-400">Entró a caja</p>
        <p class="text-lg font-bold text-gray-800" :title="pesos(m.cobrado)">{{ pesosCorto(m.cobrado) }}</p>
        <p class="text-[11px] text-gray-400">{{ pct(ind.recaudo, 0) }} de lo vendido</p>
      </div>
      <div class="bg-white rounded-xl shadow-sm p-3">
        <p class="text-xs text-gray-400">Costos y gastos</p>
        <p class="text-lg font-bold text-gray-800" :title="pesos(costosTotales)">{{ pesosCorto(costosTotales) }}</p>
        <p class="text-[11px] text-gray-400">nómina {{ pct(m.margenes.nomina, 0) }} de lo neto</p>
      </div>
      <div class="bg-white rounded-xl shadow-sm p-3">
        <p class="text-xs text-gray-400">Utilidad operativa</p>
        <p :class="['text-lg font-bold', m.utilidad_operativa >= 0 ? 'text-green-600' : 'text-red-600']" :title="pesos(m.utilidad_operativa)">
          {{ pesosCorto(m.utilidad_operativa) }}
        </p>
        <p class="text-[11px] text-gray-400">margen {{ pct(m.margenes.operativo) }}</p>
      </div>
    </div>

    <!-- Punto de equilibrio -->
    <div v-if="pe?.ventas_necesarias" class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold text-gray-800">Punto de equilibrio</p>
        <p class="text-xs font-semibold" :class="avancePe >= 1 ? 'text-green-600' : 'text-gray-500'">{{ pct(pe.avance, 0) }}</p>
      </div>
      <div class="mt-2 h-3 rounded-full bg-gray-100 overflow-hidden">
        <div class="h-full rounded-full transition-all" :class="avancePe >= 1 ? 'bg-green-500' : 'bg-blue-500'" :style="{ width: `${avancePe * 100}%` }" />
      </div>
      <p class="text-xs text-gray-500 mt-2">
        Hay que vender <b>{{ pesos(pe.ventas_necesarias) }}</b> al mes (con IVA) para cubrir los costos.
        <template v-if="avancePe >= 1">Este mes ya se cubrieron: lo que se venda de aquí en adelante deja utilidad.</template>
        <template v-else-if="pe.faltan">
          Faltan <b>{{ pesos(pe.faltan) }}</b><template v-if="pe.por_dia_habil">: unos {{ pesos(pe.por_dia_habil) }} por cada uno de los {{ pe.dias_habiles_restantes }} días hábiles que quedan</template>.
        </template>
      </p>
      <p class="text-[10px] text-gray-400 mt-1">
        Costos fijos {{ pesosCorto(pe.costos_fijos) }}/mes ÷ margen de contribución {{ pct(pe.margen_contribucion) }}
        (promedio de {{ pe.meses_base }} mes(es)).
      </p>
    </div>

    <!-- Cascada -->
    <div v-if="m.ventas" class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">De lo vendido a la utilidad</p>
      <p class="text-xs text-gray-400 mb-2">Cada barra naranja es lo que se lleva cada rubro.</p>
      <GraficaFinanzas :armar="armarCascada" :datos="m" alto="h-72" descripcion="Cascada de ventas a utilidad" />
      <p v-if="m.costo_produccion.cobertura < 1" class="text-[11px] text-gray-400 mt-2">
        Materiales estimados con las fichas técnicas ({{ pct(m.costo_produccion.cobertura, 0) }} de lo vendido tiene ficha).
      </p>
    </div>

    <!-- Dona -->
    <div v-if="reparto.length" class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">¿A dónde se va cada $100 vendidos?</p>
      <div class="flex items-center gap-4 mt-2">
        <div class="w-36 shrink-0">
          <GraficaFinanzas :armar="armarDona" :datos="reparto" alto="h-36" descripcion="Reparto de cada peso vendido" />
        </div>
        <ul class="flex-1 min-w-0 space-y-1">
          <li v-for="r in reparto" :key="r.nombre" class="flex items-center justify-between text-xs gap-2">
            <span class="truncate text-gray-600">{{ r.nombre }}</span>
            <span class="font-semibold text-gray-800 shrink-0">${{ Math.round(r.parte * 100) }}</span>
          </li>
        </ul>
      </div>
    </div>

    <!-- 12 meses -->
    <div v-if="serie.some(s => s.ventas)" class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">Últimos 12 meses</p>
      <p v-if="mejorMes" class="text-xs text-gray-400 mb-2">
        El mejor mes fue {{ nombreMes(mejorMes.mes) }}: {{ pesosCorto(mejorMes.utilidad_operativa) }} de utilidad.
      </p>
      <GraficaFinanzas :armar="armarTendencia" :datos="serie" alto="h-64" descripcion="Ingresos, costos y utilidad de los últimos 12 meses" />
      <button @click="emit('ir', 'resultados')" class="mt-2 text-xs font-semibold text-blue-600 flex items-center gap-0.5">
        Ver el estado de resultados <ChevronRightIcon class="w-3.5 h-3.5" />
      </button>
    </div>

    <!-- Nómina y ventas por tipo -->
    <div class="grid sm:grid-cols-2 gap-4">
      <div v-if="m.nomina.total" class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-gray-800">Nómina</p>
          <p class="text-sm font-bold text-gray-800">{{ pesosCorto(m.nomina.total) }}</p>
        </div>
        <p class="text-xs text-gray-400">{{ m.nomina.personas }} personas · incluye lo que pone la empresa por detrás</p>
        <GraficaFinanzas :armar="armarNomina" :datos="m.nomina" alto="h-20" descripcion="Nómina por componentes" />
      </div>
      <div v-if="m.ventas" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-800 mb-2">Qué se vendió</p>
        <div v-for="(nombre, clave) in { venta: 'Muebles', restauracion: 'Restauraciones', fv2: 'FV2' }" :key="clave" class="mb-2">
          <div class="flex justify-between text-xs">
            <span class="text-gray-600">{{ nombre }}</span>
            <span class="font-semibold text-gray-800">{{ pesosCorto(m.por_tipo[clave]) }}</span>
          </div>
          <div class="h-1.5 rounded-full bg-gray-100 mt-1 overflow-hidden">
            <div class="h-full bg-blue-500 rounded-full" :style="{ width: `${m.ventas ? (m.por_tipo[clave] / m.ventas) * 100 : 0}%` }" />
          </div>
        </div>
      </div>
    </div>

    <!-- Cartera y caja -->
    <div class="grid sm:grid-cols-2 gap-4">
      <div v-if="ind.cartera.total" class="bg-white rounded-xl shadow-sm p-4">
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-gray-800">Lo que deben los clientes</p>
          <p class="text-sm font-bold text-gray-800">{{ pesosCorto(ind.cartera.total) }}</p>
        </div>
        <p class="text-xs text-gray-400">
          Equivale a {{ ind.cartera.dias ?? '—' }} días de ventas. Lo de más de 90 días es lo más difícil de cobrar.
        </p>
        <GraficaFinanzas :armar="armarCartera" :datos="ind.cartera" alto="h-20" descripcion="Cartera por antigüedad" />
      </div>
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-800">Caja</p>
        <template v-if="ind.caja.saldo !== null">
          <p class="text-lg font-bold text-gray-800 mt-1">{{ pesos(ind.caja.saldo) }}</p>
          <p class="text-xs text-gray-500">
            Alcanza para <b>{{ ind.caja.meses_cobertura?.toLocaleString('es-CO') ?? '—' }} meses</b> de salidas
            (un mes típico sale {{ pesosCorto(ind.caja.salidas_promedio) }}).
          </p>
        </template>
        <template v-else>
          <p class="text-xs text-gray-500 mt-1">
            Pon cuánto hay en caja y bancos a un mes y el sistema dirá hasta cuándo alcanza y en qué semana podría faltar.
          </p>
          <button @click="emit('ir', 'flujo')" class="mt-2 text-xs font-semibold text-blue-600">Ir a flujo de caja</button>
        </template>
      </div>
    </div>

    <!-- Garantías: lo que se daña y lo que cuesta en plata -->
    <div v-if="datos.garantias && (datos.garantias.reportadas || datos.garantias.abiertas)" class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold text-gray-800">Garantías</p>
        <p class="text-xs text-gray-400">{{ datos.garantias.abiertas }} abiertas</p>
      </div>
      <div class="grid grid-cols-3 gap-2 mt-2 text-center">
        <div><p class="text-[11px] text-gray-400">Reportadas</p><p class="text-sm font-bold text-gray-800">{{ datos.garantias.reportadas }}</p></div>
        <div><p class="text-[11px] text-gray-400">Por cada 100 ventas</p><p class="text-sm font-bold text-gray-800">{{ datos.garantias.por_cien_ordenes?.toLocaleString('es-CO') ?? '—' }}</p></div>
        <div><p class="text-[11px] text-gray-400">Reembolsos</p><p class="text-sm font-bold text-gray-800">{{ pesosCorto(datos.garantias.reembolsos) }}</p></div>
      </div>
      <p v-if="Object.keys(datos.garantias.por_tipo).length" class="text-[11px] text-gray-500 mt-2">
        Lo que más se daña:
        {{ Object.entries(datos.garantias.por_tipo).sort((a, b) => b[1] - a[1]).map(([t, n]) => `${{ madera: 'madera', tela_espuma: 'tela y espuma', otro: 'otro' }[t] ?? t} (${n})`).join(', ') }}.
        El costo de los arreglos en el taller se verá cuando esté el costo real de producción.
      </p>
    </div>

    <!-- Próximos pagos -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between mb-2">
        <p class="text-sm font-semibold text-gray-800">Próximos pagos</p>
        <button @click="emit('ir', 'flujo')" class="text-xs font-semibold text-blue-600">Ver calendario</button>
      </div>
      <p v-if="!datos.proximos.length" class="text-xs text-gray-400">Nada por pagar en los próximos días.</p>
      <ul class="divide-y divide-gray-100">
        <li v-for="(p, i) in datos.proximos" :key="i" class="py-2 flex items-center gap-2.5">
          <span class="text-lg w-6 text-center shrink-0" aria-hidden="true">{{ ICONO_TIPO[p.tipo] }}</span>
          <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-800 truncate">{{ p.titulo }}</p>
            <p class="text-[11px] truncate" :class="p.vencido ? 'text-red-600 font-semibold' : 'text-gray-400'">
              {{ p.vencido ? 'Vencido · ' : '' }}{{ fechaCorta(p.fecha) }}<template v-if="p.detalle"> · {{ p.detalle }}</template>
            </p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-semibold text-gray-800">{{ p.estimado ? '~' : '' }}{{ pesosCorto(p.monto) }}</p>
            <button v-if="p.tipo === 'gasto'" @click="emit('pagar', p)" class="text-[11px] font-semibold text-blue-600">Pagar</button>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
