<script setup>
// La plata que de verdad entra y sale, y lo que viene: "¿me alcanza para la
// quincena?". Se puede ganar plata y quedarse sin caja si los clientes deben
// mucho; por eso va aparte del estado de resultados.
import { ref, computed, watch } from 'vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { getFlujoCaja, getCalendario, getAjustes, guardarAjustes } from '@/api/finanzas'
import { pesos, pesosCorto, pct, mesActual, sumarMeses, nombreMes, fechaCorta } from '@/utils/finanzas'
import InputPesos from '@/components/common/InputPesos.vue'
import GraficaFinanzas from './GraficaFinanzas.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({ version: Number })
const toast = useToast()

const cargando = ref(true)
const flujo = ref(null)
const calendario = ref([])
const editandoSaldo = ref(false)
const formSaldo = ref({ saldo_inicial: null, saldo_fecha: sumarMeses(mesActual(), -1) })

async function cargar() {
  cargando.value = true
  try {
    const actual = mesActual()
    const [f, c, a] = await Promise.all([getFlujoCaja(sumarMeses(actual, -5), actual), getCalendario(45), getAjustes()])
    flujo.value = f.data
    calendario.value = c.data
    formSaldo.value = { saldo_inicial: a.data.saldo_inicial, saldo_fecha: a.data.saldo_fecha ?? sumarMeses(actual, -1) }
  } catch {
    toast.error('No se pudo cargar el flujo de caja')
  } finally {
    cargando.value = false
  }
}
watch(() => props.version, cargar, { immediate: true })

const meses = computed(() => flujo.value?.meses ?? [])
const semanas = computed(() => flujo.value?.semanal?.semanas ?? [])
const semanaRoja = computed(() => flujo.value?.semanal?.primera_semana_negativa)

function armarMensual(c) {
  if (!meses.value.length) return null
  const conSaldo = meses.value.some(m => m.saldo !== undefined)
  return {
    type: 'bar',
    data: {
      labels: meses.value.map(m => nombreMes(m.mes, true)),
      datasets: [
        ...(conSaldo ? [{ type: 'line', label: 'Saldo', data: meses.value.map(m => m.saldo ?? null), borderColor: c.utilidad,
          backgroundColor: c.utilidad, tension: 0.3, pointRadius: 3, order: 0 }] : []),
        { label: 'Entró', data: meses.value.map(m => m.entradas), backgroundColor: c.ingresos, borderRadius: 3, order: 1 },
        { label: 'Salió', data: meses.value.map(m => m.salidas.total), backgroundColor: c.egresos, borderRadius: 3, order: 1 },
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

function armarSemanas(c) {
  if (!semanas.value.length) return null
  const conSaldo = semanas.value.some(s => s.saldo !== null)
  return {
    type: 'bar',
    data: {
      labels: semanas.value.map(s => fechaCorta(s.inicio)),
      datasets: [
        ...(conSaldo ? [{ type: 'line', label: 'Saldo', data: semanas.value.map(s => s.saldo), borderColor: c.ingresos,
          backgroundColor: c.ingresos, tension: 0.25, pointRadius: 2, order: 0,
          segment: { borderColor: (ctx) => (ctx.p1.parsed.y < 0 ? c.perdida : c.ingresos) } }] : []),
        { label: 'Neto de la semana', data: semanas.value.map(s => s.neto), order: 1, borderRadius: 3,
          backgroundColor: semanas.value.map(s => (s.neto >= 0 ? c.utilidad : c.perdida)) },
      ],
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: {
          title: (x) => `Semana del ${x[0].label}`,
          label: (x) => ` ${x.dataset.label}: ${pesos(x.raw)}`,
          afterBody: (x) => {
            const s = semanas.value[x[0].dataIndex]
            return [`Entra ~${pesosCorto(s.entradas)}`, `Nómina ${pesosCorto(s.salidas.nomina)}`,
              `Comisiones ${pesosCorto(s.salidas.comisiones)}`, `Gastos ${pesosCorto(s.salidas.gastos)}`]
          },
        } },
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
        y: { ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla } },
      },
    },
  }
}

// Por qué medio entra la plata (el mes en curso): la tarjeta y Addi llegan
// después y con la franquicia descontada.
const medios = computed(() => {
  const ultimo = meses.value[meses.value.length - 1]
  return Object.entries(ultimo?.por_metodo ?? {}).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1])
})
function armarMedios(c) {
  if (!medios.value.length) return null
  return {
    type: 'doughnut',
    data: { labels: medios.value.map(m => m[0]), datasets: [{ data: medios.value.map(m => m[1]), backgroundColor: c.serie, borderWidth: 0 }] },
    options: { cutout: '60%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (x) => ` ${x.label}: ${pesos(x.raw)}` } } } },
  }
}
const totalMedios = computed(() => medios.value.reduce((s, m) => s + m[1], 0))

// El calendario, por semana.
const porSemana = computed(() => {
  const grupos = []
  for (const p of calendario.value) {
    const d = new Date(p.fecha + 'T00:00:00')
    const lunes = new Date(d); lunes.setDate(d.getDate() - ((d.getDay() + 6) % 7))
    const clave = p.vencido ? 'vencido' : lunes.toISOString().slice(0, 10)
    let g = grupos.find(x => x.clave === clave)
    if (!g) grupos.push(g = { clave, titulo: p.vencido ? 'Atrasado' : `Semana del ${fechaCorta(clave)}`, items: [], total: 0 })
    g.items.push(p); g.total += p.monto
  }
  return grupos
})

async function guardarSaldo() {
  try {
    await guardarAjustes(formSaldo.value)
    editandoSaldo.value = false
    toast.success('Saldo guardado')
    cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  }
}

const ICONO_TIPO = { nomina: '👥', comisiones: '🎯', gasto: '🧾', impuesto: '🏛️', prestaciones: '🎁', proveedor: '📦' }
</script>

<template>
  <div v-if="cargando && !flujo" class="flex justify-center py-16"><AppSpinner /></div>

  <div v-else-if="flujo" class="space-y-4" :class="cargando && 'opacity-60'">
    <!-- Saldo de partida -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between gap-2">
        <div>
          <p class="text-sm font-semibold text-gray-800">Saldo en caja y bancos</p>
          <p v-if="flujo.semanal.saldo_inicial !== null" class="text-lg font-bold text-gray-800">{{ pesos(flujo.semanal.saldo_inicial) }} <span class="text-xs font-normal text-gray-400">hoy (estimado)</span></p>
          <p v-else class="text-xs text-gray-500">Sin él solo se ve el flujo; con él, hasta cuándo alcanza.</p>
        </div>
        <button @click="editandoSaldo = !editandoSaldo" class="text-xs font-semibold text-blue-600 shrink-0">
          {{ flujo.con_saldo ? 'Cambiar' : 'Ponerlo' }}
        </button>
      </div>
      <div v-if="editandoSaldo" class="mt-3 space-y-2">
        <p class="text-[11px] text-gray-400">Cuánto había en caja y bancos al cierre de un mes. Desde ahí se suman las entradas y se restan las salidas.</p>
        <div class="grid grid-cols-2 gap-2">
          <InputPesos v-model="formSaldo.saldo_inicial" />
          <input v-model="formSaldo.saldo_fecha" type="month" class="text-sm border border-gray-200 rounded-lg px-2 py-2" />
        </div>
        <button @click="guardarSaldo" class="w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2">Guardar</button>
      </div>
    </div>

    <!-- Alerta de semana en rojo -->
    <div v-if="semanaRoja" class="bg-white rounded-xl shadow-sm border-l-4 border-red-300 px-3 py-2.5 flex gap-2.5 text-red-700">
      <ExclamationTriangleIcon class="w-5 h-5 shrink-0 mt-0.5" />
      <div>
        <p class="text-sm font-semibold">La caja podría quedar en rojo</p>
        <p class="text-xs text-gray-600">En la semana del {{ fechaCorta(semanaRoja) }}, con lo que hay que pagar, el saldo bajaría de cero. Revisa qué cobrar antes o qué pago correr.</p>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">Próximas 13 semanas</p>
      <p class="text-xs text-gray-400 mb-2">
        Las salidas tienen fecha (quincenas, gastos fijos, comisiones del 20); las entradas son el ritmo de cobro de los últimos meses
        ({{ pct(flujo.semanal.recaudo, 0) }} de lo que se vende).
      </p>
      <GraficaFinanzas :armar="armarSemanas" :datos="semanas" alto="h-60" descripcion="Flujo de caja proyectado por semana" />
    </div>

    <div class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">Lo que entró y salió</p>
      <GraficaFinanzas :armar="armarMensual" :datos="meses" alto="h-56" descripcion="Entradas y salidas de caja por mes" />
      <div class="overflow-x-auto mt-3">
        <table class="text-xs w-full">
          <thead>
            <tr class="text-gray-400">
              <th class="text-left font-semibold py-1 pr-2">Mes</th>
              <th class="text-right font-semibold py-1 px-2">Entró</th>
              <th class="text-right font-semibold py-1 px-2">Nómina</th>
              <th class="text-right font-semibold py-1 px-2">Comisiones</th>
              <th class="text-right font-semibold py-1 px-2">Gastos</th>
              <th class="text-right font-semibold py-1 pl-2">Neto</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in [...meses].reverse()" :key="m.mes" class="border-t border-gray-100">
              <td class="py-1.5 pr-2 text-gray-700 whitespace-nowrap">{{ nombreMes(m.mes, true) }}</td>
              <td class="py-1.5 px-2 text-right text-gray-700">{{ pesosCorto(m.entradas) }}</td>
              <td class="py-1.5 px-2 text-right text-gray-500">{{ pesosCorto(m.salidas.nomina) }}</td>
              <td class="py-1.5 px-2 text-right text-gray-500">{{ pesosCorto(m.salidas.comisiones) }}</td>
              <td class="py-1.5 px-2 text-right text-gray-500">{{ pesosCorto(m.salidas.gastos) }}</td>
              <td :class="['py-1.5 pl-2 text-right font-semibold', m.neto >= 0 ? 'text-green-600' : 'text-red-600']">{{ pesosCorto(m.neto) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="medios.length" class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">Cómo pagan los clientes (este mes)</p>
      <div class="flex items-center gap-4 mt-2">
        <div class="w-32 shrink-0">
          <GraficaFinanzas :armar="armarMedios" :datos="medios" alto="h-32" descripcion="Cobros por medio de pago" />
        </div>
        <ul class="flex-1 min-w-0 space-y-1">
          <li v-for="m in medios" :key="m[0]" class="flex justify-between text-xs gap-2">
            <span class="capitalize text-gray-600">{{ m[0] }}</span>
            <span class="font-semibold text-gray-800">{{ pct(m[1] / totalMedios, 0) }}</span>
          </li>
        </ul>
      </div>
      <p class="text-[11px] text-gray-400 mt-2">Tarjeta y Addi llegan días después y con la franquicia descontada.</p>
    </div>

    <!-- Calendario de pagos -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <p class="text-sm font-semibold text-gray-800">Calendario de pagos (45 días)</p>
      <p v-if="!calendario.length" class="text-xs text-gray-400 mt-1">Nada por pagar.</p>
      <div v-for="g in porSemana" :key="g.clave" class="mt-3">
        <div class="flex justify-between text-[11px] font-semibold uppercase mb-1" :class="g.clave === 'vencido' ? 'text-red-600' : 'text-gray-400'">
          <span>{{ g.titulo }}</span><span>{{ pesosCorto(g.total) }}</span>
        </div>
        <ul class="divide-y divide-gray-100">
          <li v-for="(p, i) in g.items" :key="i" class="py-1.5 flex items-center gap-2.5">
            <span class="w-6 text-center shrink-0" aria-hidden="true">{{ ICONO_TIPO[p.tipo] }}</span>
            <div class="min-w-0 flex-1">
              <p class="text-sm text-gray-800 truncate">{{ p.titulo }}</p>
              <p class="text-[11px] text-gray-400 truncate">{{ fechaCorta(p.fecha) }}<template v-if="p.detalle"> · {{ p.detalle }}</template></p>
            </div>
            <p class="text-sm font-semibold text-gray-800 shrink-0">{{ p.estimado ? '~' : '' }}{{ pesosCorto(p.monto) }}</p>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
