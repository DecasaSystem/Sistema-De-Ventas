<script setup>
// Lo que viene: ventas proyectadas con su margen de error, tres escenarios de
// utilidad y un simulador. Lo de nómina y gastos fijos ya se sabe (sale de
// Nómina y de las plantillas); lo de las ventas es estadística, y se dice.
import { ref, computed, watch } from 'vue'
import { getProyeccion } from '@/api/finanzas'
import { pesos, pesosCorto, pct, nombreMes } from '@/utils/finanzas'
import GraficaFinanzas from './GraficaFinanzas.vue'
import InputPesos from '@/components/common/InputPesos.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({ version: Number })
const toast = useToast()

const cargando = ref(true)
const datos = ref(null)

async function cargar() {
  cargando.value = true
  try {
    datos.value = (await getProyeccion(3)).data
  } catch {
    toast.error('No se pudo cargar la proyección')
  } finally {
    cargando.value = false
  }
}
watch(() => props.version, cargar, { immediate: true })

const v = computed(() => datos.value?.ventas)
const prop = computed(() => datos.value?.proporciones)

const METODO = {
  tendencia: 'Tendencia de los últimos meses (mínimos cuadrados)',
  promedio_ponderado: 'Promedio de los últimos 3 meses, pesando más el más reciente',
  sin_historia: 'Todavía no hay meses cerrados para proyectar',
  tendencia_con_temporada: 'Tendencia de los últimos 12 meses, ajustada por la temporada (cómo fue ese mismo mes el año pasado)',
  promedio_ponderado_con_temporada: 'Promedio de los últimos 3 meses, ajustado por la temporada (cómo fue ese mismo mes el año pasado)',
}

function armarVentas(c) {
  if (!v.value) return null
  const hist = v.value.historia
  const fut = v.value.futuro
  const labels = [...hist.map(h => nombreMes(h.mes, true)), nombreMes(v.value.actual.mes, true), ...fut.map(f => nombreMes(f.mes, true))]
  const n = hist.length
  const vacio = (k) => Array(k).fill(null)
  // La línea punteada arranca en el mes en curso para que se vea continua.
  const base = [...vacio(n), v.value.actual.cierre_estimado, ...fut.map(f => f.base)]
  return {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Vendido', data: [...hist.map(h => h.vendido), v.value.actual.vendido_hasta_hoy, ...vacio(fut.length)],
          borderColor: c.ingresos, backgroundColor: c.ingresos, tension: 0.3, pointRadius: 3 },
        { label: 'Optimista', data: [...vacio(n), v.value.actual.cierre_estimado, ...fut.map(f => f.optimista)],
          borderColor: 'transparent', backgroundColor: 'rgba(37,99,235,0.12)', fill: '+1', pointRadius: 0 },
        { label: 'Pesimista', data: [...vacio(n), v.value.actual.cierre_estimado, ...fut.map(f => f.pesimista)],
          borderColor: 'transparent', pointRadius: 0 },
        { label: 'Proyección', data: base, borderColor: c.ingresos, borderDash: [6, 4], tension: 0.3, pointRadius: 3,
          pointBackgroundColor: c.ingresos },
      ],
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 }, filter: (i) => ['Vendido', 'Proyección'].includes(i.text) } },
        tooltip: { callbacks: { label: (x) => (x.raw === null ? null : ` ${x.dataset.label}: ${pesos(x.raw)}`) } },
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 6 } },
        y: { ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla }, beginAtZero: true },
      },
    },
  }
}

function armarEscenarios(c) {
  const ms = datos.value?.meses ?? []
  if (!ms.length) return null
  return {
    type: 'bar',
    data: {
      labels: ms.map(m => nombreMes(m.mes, true)),
      datasets: [
        { label: 'Pesimista', data: ms.map(m => m.utilidad.pesimista), backgroundColor: c.egresos, borderRadius: 3 },
        { label: 'Base', data: ms.map(m => m.utilidad.base), backgroundColor: c.ingresos, borderRadius: 3 },
        { label: 'Optimista', data: ms.map(m => m.utilidad.optimista), backgroundColor: c.utilidad, borderRadius: 3 },
      ],
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: (x) => ` ${x.dataset.label}: ${pesos(x.raw)}` } },
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
        y: { ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla } },
      },
    },
  }
}

// ── Simulador: "¿qué pasa si…?" sobre el próximo mes ─────────────────────────
const sim = ref({ ventasPct: 0, personas: 0, sueldo: 1_750_905, gastoNuevo: 0 })
// Lo que le cuesta a la empresa cada peso de sueldo (aportes y prestaciones),
// sacado de la nómina real de los últimos meses: no hay un porcentaje fijo
// que se desactualice cuando cambie la ley. Sin historia, el sueldo solo.
const FACTOR_EMPLEADOR = computed(() => prop.value?.factor_nomina ?? 1)

const proximo = computed(() => datos.value?.meses?.find(m => !m.en_curso))
const simulado = computed(() => {
  if (!proximo.value || !prop.value) return null
  const p = prop.value
  const ventas = proximo.value.ventas.base * (1 + sim.value.ventasPct / 100)
  const nomina = proximo.value.nomina + sim.value.personas * sim.value.sueldo * FACTOR_EMPLEADOR.value
  const gastos = proximo.value.gastos + (sim.value.gastoNuevo || 0)
  const utilidad = ventas * (1 - p.iva - p.costo - p.comisiones - p.franquicia) - nomina - gastos
  const cf = (p.costos_fijos_promedio ?? 0) + sim.value.personas * sim.value.sueldo * FACTOR_EMPLEADOR.value + (sim.value.gastoNuevo || 0)
  const mc = p.margen_contribucion
  return {
    ventas, utilidad,
    base: proximo.value.utilidad.base,
    equilibrio: mc > 0 ? cf / mc : null,
  }
})
</script>

<template>
  <div v-if="cargando && !datos" class="flex justify-center py-16"><AppSpinner /></div>

  <div v-else-if="datos" class="space-y-4" :class="cargando && 'opacity-60'">
    <!-- Mes en curso al cierre -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">{{ nombreMes(v.actual.mes) }} al cierre</p>
      <p class="text-2xl font-bold text-gray-800 mt-1">~{{ pesosCorto(v.actual.cierre_estimado) }}</p>
      <p class="text-xs text-gray-500">
        Van {{ pesosCorto(v.actual.vendido_hasta_hoy) }} en {{ v.actual.dias_corridos }} de {{ v.actual.dias_habiles }} días hábiles
        (lunes a sábado, sin festivos). A este ritmo, el mes cierra en esa cifra.
      </p>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">Ventas: lo que pasó y lo que viene</p>
      <p class="text-xs text-gray-400 mb-2">La franja es el margen de error: lo normal es caer adentro.</p>
      <GraficaFinanzas :armar="armarVentas" :datos="v" alto="h-60" descripcion="Ventas reales y proyectadas con su margen de error" />
      <p class="text-[11px] text-gray-500 mt-2">
        <b>Cómo se calcula:</b> {{ METODO[v.metodo] }}, con {{ v.meses_historia }} mes(es) de historia
        (error típico ±{{ pesosCorto(v.error) }}).
        <template v-if="v.meses_historia < 13">Con menos de 13 meses de datos todavía no se puede ver la temporada (diciembre, la prima de junio); se activa sola cuando los haya.</template>
        <template v-else-if="v.futuro.some(f => f.temporada)">
          Temporada: {{ v.futuro.filter(f => f.temporada).map(f => `${f.nombre} ${f.temporada >= 1 ? '+' : ''}${Math.round((f.temporada - 1) * 100)} %`).join(' · ') }}.
        </template>
      </p>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">Utilidad de los próximos meses</p>
      <p class="text-xs text-gray-400 mb-2">La nómina y los gastos fijos ya se saben; lo que cambia entre escenarios son las ventas.</p>
      <GraficaFinanzas :armar="armarEscenarios" :datos="datos.meses" alto="h-56" descripcion="Utilidad proyectada en tres escenarios" />
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
        <div v-for="m in datos.meses" :key="m.mes" class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-700">{{ nombreMes(m.mes) }}<span v-if="m.en_curso" class="text-gray-400 font-normal"> · al cierre</span></p>
          <div class="grid grid-cols-3 gap-1 text-[11px] mt-1.5">
            <div><p class="text-gray-400">Ventas</p><p class="font-semibold text-gray-800">{{ pesosCorto(m.ventas.base) }}</p></div>
            <div><p class="text-gray-400">Nómina</p><p class="font-semibold text-gray-800">{{ pesosCorto(m.nomina) }}</p></div>
            <div><p class="text-gray-400">Gastos</p><p class="font-semibold text-gray-800">{{ pesosCorto(m.gastos) }}</p></div>
          </div>
          <p :class="['text-sm font-bold mt-1.5', m.utilidad.base >= 0 ? 'text-green-600' : 'text-red-600']">
            Utilidad ~{{ pesosCorto(m.utilidad.base) }}
            <span class="text-[11px] font-normal text-gray-400">({{ pesosCorto(m.utilidad.pesimista) }} a {{ pesosCorto(m.utilidad.optimista) }})</span>
          </p>
        </div>
      </div>
    </div>

    <!-- Simulador -->
    <div v-if="simulado" class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">¿Qué pasa si…?</p>
      <p class="text-xs text-gray-400">Sobre {{ nombreMes(proximo.mes) }}. No guarda nada: es para pensar decisiones.</p>

      <div class="mt-3 space-y-4">
        <div>
          <div class="flex justify-between text-xs"><span class="text-gray-600">Las ventas cambian</span>
            <span class="font-semibold" :class="sim.ventasPct >= 0 ? 'text-green-600' : 'text-red-600'">{{ sim.ventasPct > 0 ? '+' : '' }}{{ sim.ventasPct }} %</span></div>
          <input v-model.number="sim.ventasPct" type="range" min="-50" max="50" step="5" class="w-full accent-blue-600" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs text-gray-600 mb-1">Contratar personas</label>
            <input v-model.number="sim.personas" type="number" min="0" max="20" inputmode="numeric" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5" />
          </div>
          <div>
            <label class="block text-xs text-gray-600 mb-1">Sueldo de cada una</label>
            <InputPesos v-model="sim.sueldo" />
          </div>
        </div>
        <div>
          <label class="block text-xs text-gray-600 mb-1">Un gasto fijo nuevo al mes (arriendo, otra tienda…)</label>
          <InputPesos v-model="sim.gastoNuevo" />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-2 mt-4">
        <div class="rounded-lg bg-gray-100 p-3">
          <p class="text-[11px] text-gray-500">Utilidad del mes</p>
          <p :class="['text-lg font-bold', simulado.utilidad >= 0 ? 'text-green-600' : 'text-red-600']">{{ pesosCorto(simulado.utilidad) }}</p>
          <p class="text-[11px] text-gray-500">{{ simulado.utilidad >= simulado.base ? '+' : '' }}{{ pesosCorto(simulado.utilidad - simulado.base) }} vs hoy</p>
        </div>
        <div class="rounded-lg bg-gray-100 p-3">
          <p class="text-[11px] text-gray-500">Hay que vender para no perder</p>
          <p class="text-lg font-bold text-gray-800">{{ simulado.equilibrio ? pesosCorto(simulado.equilibrio) : '—' }}</p>
          <p class="text-[11px] text-gray-500">al mes, con IVA</p>
        </div>
      </div>
      <p class="text-[10px] text-gray-400 mt-2">
        Cada persona nueva cuesta su sueldo × {{ FACTOR_EMPLEADOR.toLocaleString('es-CO') }}: lo que de verdad cuesta cada peso de sueldo en la nómina de los últimos meses, con aportes y prestaciones.
        Proporciones de los últimos {{ prop.meses }} mes(es): materiales {{ pct(prop.costo) }}, comisiones {{ pct(prop.comisiones) }} de lo vendido.
      </p>
    </div>
  </div>
</template>
