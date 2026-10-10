<script setup>
// Prestaciones sociales que se pagan: prima (junio y diciembre), cesantías
// (al fondo, febrero), intereses (al trabajador, enero), vacaciones y
// liquidaciones de quien se retira. Lo que se debe lo calcula el servidor con
// lo que se fue provisionando en cada pago de nómina: si cambian los
// porcentajes o a alguien se le quita un concepto, cambia solo.
import { ref, computed, watch } from 'vue'
import { ChevronLeftIcon, ChevronRightIcon, ArrowDownTrayIcon, PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import {
  getPrestaciones, pagarPrestacion, getVacaciones, registrarVacaciones,
  getLiquidaciones, anularLiquidacion, pdfLiquidacion, getAjustesPrestaciones, guardarAjustesPrestaciones,
} from '@/api/nomina'
import LiquidacionForm from './LiquidacionForm.vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const SUB = [
  { id: 'prima', label: 'Prima' },
  { id: 'cesantias', label: 'Cesantías' },
  { id: 'intereses_cesantias', label: 'Intereses' },
  { id: 'vacaciones', label: 'Vacaciones' },
  { id: 'liquidaciones', label: 'Liquidaciones' },
]
const sub = ref('prima')

function pesos(n) { return '$' + Math.round(n ?? 0).toLocaleString('es-CO') }
function fecha(f) { return f ? new Date(f + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : '' }
function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// ── Prima, cesantías, intereses: un periodo a la vez ─────────────────────────
// La fecha de referencia cae dentro del periodo que se mira (semestre o año).
const ref_ = ref(hoyISO())
const resumen = ref(null)
const cargando = ref(false)
const elegidos = ref([])
const pagando = ref(false)

function moverPeriodo(n) {
  const d = new Date(ref_.value + 'T00:00:00')
  if (sub.value === 'prima') d.setMonth(d.getMonth() + 6 * n)
  else d.setFullYear(d.getFullYear() + n)
  ref_.value = d.toISOString().slice(0, 10)
}

const nombrePeriodo = computed(() => {
  if (!resumen.value) return ''
  const d = new Date(resumen.value.desde + 'T00:00:00')
  return sub.value === 'prima'
    ? `${d.getMonth() < 6 ? '1.er' : '2.º'} semestre ${d.getFullYear()}`
    : `Año ${d.getFullYear()}`
})

const diasParaLimite = computed(() => {
  if (!resumen.value) return null
  return Math.round((new Date(resumen.value.fecha_limite + 'T00:00:00') - new Date(hoyISO() + 'T00:00:00')) / 86400000)
})

async function cargarPeriodo() {
  if (!['prima', 'cesantias', 'intereses_cesantias'].includes(sub.value)) return
  cargando.value = true
  try {
    resumen.value = (await getPrestaciones(sub.value, ref_.value)).data
    elegidos.value = resumen.value.trabajadores.filter(t => t.saldo > 0).map(t => t.usuario_id)
  } catch {
    toast.error('No se pudo cargar')
  } finally {
    cargando.value = false
  }
}

const totalElegido = computed(() => (resumen.value?.trabajadores ?? [])
  .filter(t => elegidos.value.includes(t.usuario_id)).reduce((s, t) => s + t.saldo, 0))

async function pagar() {
  const verbo = sub.value === 'cesantias' ? 'consignar al fondo' : 'pagar'
  if (!confirm(`¿Marcar como ${sub.value === 'cesantias' ? 'consignadas' : 'pagadas'} ${resumen.value.nombre.toLowerCase()} de ${elegidos.value.length} persona(s) por ${pesos(totalElegido.value)}?\n\nSe registra lo que el sistema calculó; Finanzas lo cuenta como salida de caja.`)) return
  pagando.value = true
  try {
    await pagarPrestacion({ tipo: sub.value, fecha: ref_.value, usuarios: elegidos.value })
    toast.success(`Listo: ${verbo} ${pesos(totalElegido.value)}`)
    cargarPeriodo()
  } catch (e) {
    const err = e.response?.data?.errors
    toast.error(err ? Object.values(err)[0][0] : 'No se pudo registrar')
  } finally {
    pagando.value = false
  }
}

// ── Vacaciones ──────────────────────────────────────────────────────────────
const vacaciones = ref([])
const formVac = ref(null)

async function cargarVacaciones() {
  cargando.value = true
  try { vacaciones.value = (await getVacaciones()).data } catch { toast.error('No se pudieron cargar las vacaciones') } finally { cargando.value = false }
}

function abrirVacaciones(v) {
  formVac.value = { v, usuario_id: v.usuario_id, dias: Math.min(15, Math.floor(v.dias_pendientes)), desde: hoyISO(), hasta: hoyISO(), forma: 'con_nomina' }
}

async function guardarVacaciones() {
  try {
    await registrarVacaciones(formVac.value)
    toast.success('Vacaciones registradas')
    formVac.value = null
    cargarVacaciones()
  } catch (e) {
    const err = e.response?.data?.errors
    toast.error(err ? Object.values(err)[0][0] : 'No se pudo registrar')
  }
}

// ── Liquidaciones ───────────────────────────────────────────────────────────
const liquidaciones = ref([])
const nuevaLiquidacion = ref(false)

async function cargarLiquidaciones() {
  cargando.value = true
  try { liquidaciones.value = (await getLiquidaciones()).data } catch { toast.error('No se pudieron cargar') } finally { cargando.value = false }
}

async function bajarPdf(l) {
  try {
    const { data } = await pdfLiquidacion(l.id)
    const url = URL.createObjectURL(data)
    const a = document.createElement('a'); a.href = url; a.download = `liquidacion_${l.nombre}.pdf`; a.click()
    URL.revokeObjectURL(url)
  } catch { toast.error('No se pudo bajar el PDF') }
}

async function anular(l) {
  const motivo = prompt(`¿Por qué se anula la liquidación de ${l.nombre}?\nVuelve a nómina con su sueldo y los pagos de esta liquidación se deshacen.`)
  if (!motivo?.trim()) return
  try { await anularLiquidacion(l.id, motivo.trim()); toast.success('Liquidación anulada'); cargarLiquidaciones() }
  catch (e) { toast.error(e.response?.data?.message || 'No se pudo anular') }
}

// ── Ajustes (fechas, días, indemnización) ───────────────────────────────────
const ajustes = ref(null)
const verAjustes = ref(false)
async function abrirAjustes() {
  verAjustes.value = !verAjustes.value
  if (verAjustes.value && !ajustes.value) ajustes.value = (await getAjustesPrestaciones()).data
}
async function guardarAjustes() {
  try { ajustes.value = (await guardarAjustesPrestaciones(ajustes.value)).data; toast.success('Guardado'); cargarPeriodo() }
  catch (e) { const err = e.response?.data?.errors; toast.error(err ? Object.values(err)[0][0] : 'No se pudo guardar') }
}

watch([sub, ref_], () => {
  if (sub.value === 'vacaciones') cargarVacaciones()
  else if (sub.value === 'liquidaciones') cargarLiquidaciones()
  else cargarPeriodo()
}, { immediate: true })
</script>

<template>
  <div class="space-y-3">
    <div class="flex gap-1 overflow-x-auto pb-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <button v-for="s in SUB" :key="s.id" @click="sub = s.id"
        :class="['shrink-0 rounded-full text-xs font-semibold px-3 py-1.5 border',
          sub === s.id ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']">
        {{ s.label }}
      </button>
    </div>

    <div v-if="cargando && !resumen && !vacaciones.length && !liquidaciones.length" class="flex justify-center py-10">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <!-- Prima, cesantías, intereses -->
    <template v-if="['prima', 'cesantias', 'intereses_cesantias'].includes(sub) && resumen">
      <div class="bg-white rounded-xl shadow-sm p-4" :class="cargando && 'opacity-60'">
        <div class="flex items-center justify-between">
          <button @click="moverPeriodo(-1)" class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Periodo anterior"><ChevronLeftIcon class="w-5 h-5" /></button>
          <div class="text-center">
            <p class="text-sm font-semibold text-gray-800">{{ resumen.nombre }} · {{ nombrePeriodo }}</p>
            <p :class="['text-[11px]', diasParaLimite < 0 && resumen.saldo > 0 ? 'text-red-600 font-semibold' : 'text-gray-400']">
              {{ sub === 'cesantias' ? 'Consignar al fondo' : 'Pagar' }} a más tardar el {{ fecha(resumen.fecha_limite) }}
              <template v-if="resumen.saldo > 0 && diasParaLimite >= 0"> · faltan {{ diasParaLimite }} días</template>
              <template v-else-if="resumen.saldo > 0"> · vencido</template>
            </p>
          </div>
          <button @click="moverPeriodo(1)" class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Periodo siguiente"><ChevronRightIcon class="w-5 h-5" /></button>
        </div>
        <div class="grid grid-cols-3 gap-2 mt-3 text-center">
          <div><p class="text-[11px] text-gray-400">Provisionado</p><p class="text-sm font-bold text-gray-800">{{ pesos(resumen.causado) }}</p></div>
          <div><p class="text-[11px] text-gray-400">Pagado</p><p class="text-sm font-bold text-gray-800">{{ pesos(resumen.pagado) }}</p></div>
          <div><p class="text-[11px] text-gray-400">Se debe</p><p :class="['text-sm font-bold', resumen.saldo > 0 ? 'text-blue-600' : 'text-green-600']">{{ pesos(resumen.saldo) }}</p></div>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">
          Sale de lo que se fue provisionando en cada pago de nómina{{ new Date(resumen.hasta) > new Date() ? '; el periodo no ha terminado y sigue sumando' : '' }}.
        </p>
      </div>

      <div v-if="resumen.trabajadores.length" class="bg-white rounded-xl shadow-sm divide-y divide-gray-100">
        <label v-for="t in resumen.trabajadores" :key="t.usuario_id" class="flex items-center gap-3 px-4 py-2.5" :class="t.saldo <= 0 && 'opacity-60'">
          <input type="checkbox" :value="t.usuario_id" v-model="elegidos" :disabled="t.saldo <= 0" class="rounded" />
          <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-800 truncate">{{ t.nombre }}<span v-if="t.retirado" class="text-[10px] text-gray-400"> · retirado</span></p>
            <p class="text-[11px] text-gray-400 truncate">
              {{ t.dias }} días<template v-if="t.pagado"> · pagado {{ pesos(t.pagado) }}</template>
              <template v-if="sub === 'cesantias'"> · {{ t.fondo || 'sin fondo (ponlo en su ficha)' }}</template>
            </p>
          </div>
          <p class="text-sm font-semibold shrink-0" :class="t.saldo > 0 ? 'text-gray-800' : 'text-green-600'">{{ t.saldo > 0 ? pesos(t.saldo) : 'Al día' }}</p>
        </label>
      </div>
      <p v-else class="text-center text-sm text-gray-400 py-6">Nadie tiene esta prestación en el periodo.</p>

      <button v-if="elegidos.length" @click="pagar" :disabled="pagando"
        class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50">
        {{ pagando ? 'Guardando…' : `${sub === 'cesantias' ? 'Consignar' : 'Pagar'} ${elegidos.length} · ${pesos(totalElegido)}` }}
      </button>
    </template>

    <!-- Vacaciones -->
    <template v-if="sub === 'vacaciones'">
      <p class="text-[11px] text-gray-400 px-1">15 días hábiles por cada año trabajado (editable abajo). El valor de un día es el sueldo del día, sin auxilio.</p>
      <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100">
        <div v-for="v in vacaciones" :key="v.usuario_id" class="flex items-center gap-3 px-4 py-2.5">
          <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-800 truncate">{{ v.nombre }}</p>
            <p class="text-[11px] text-gray-400 truncate">
              Desde {{ fecha(v.desde) }} · ganó {{ v.dias_causados.toLocaleString('es-CO') }} · tomó {{ v.dias_tomados.toLocaleString('es-CO') }}
              <template v-if="!v.aplica"> · sin vacaciones (prestación de servicios)</template>
            </p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-semibold text-gray-800">{{ v.dias_pendientes.toLocaleString('es-CO') }} días</p>
            <button v-if="v.dias_pendientes >= 1 && !v.retirado" @click="abrirVacaciones(v)" class="text-[11px] font-semibold text-blue-600">Registrar</button>
          </div>
        </div>
        <p v-if="!vacaciones.length && !cargando" class="text-center text-sm text-gray-400 py-6">Sin trabajadores con vacaciones.</p>
      </div>
    </template>

    <!-- Liquidaciones -->
    <template v-if="sub === 'liquidaciones'">
      <button @click="nuevaLiquidacion = true" class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 flex items-center justify-center gap-1.5">
        <PlusIcon class="w-4 h-4" /> Liquidar a alguien que se va
      </button>
      <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100">
        <div v-for="l in liquidaciones" :key="l.id" class="flex items-center gap-3 px-4 py-2.5" :class="l.estado === 'anulada' && 'opacity-50'">
          <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-800 truncate">{{ l.nombre }}<span v-if="l.estado === 'anulada'" class="text-[10px] text-red-600"> · anulada</span></p>
            <p class="text-[11px] text-gray-400 truncate">{{ l.motivo }} · se retiró el {{ fecha(l.fecha_retiro) }}</p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-semibold text-gray-800">{{ pesos(l.total) }}</p>
            <div class="flex gap-2 justify-end">
              <button @click="bajarPdf(l)" class="text-[11px] text-blue-600 flex items-center gap-0.5"><ArrowDownTrayIcon class="w-3 h-3" /> PDF</button>
              <button v-if="l.estado !== 'anulada'" @click="anular(l)" class="text-[11px] text-gray-400 hover:text-red-600">Anular</button>
            </div>
          </div>
        </div>
        <p v-if="!liquidaciones.length && !cargando" class="text-center text-sm text-gray-400 py-6">No hay liquidaciones.</p>
      </div>
    </template>

    <!-- Ajustes -->
    <div class="bg-white rounded-xl shadow-sm">
      <button @click="abrirAjustes" class="w-full px-4 py-3 text-left text-xs font-semibold text-gray-500">
        {{ verAjustes ? 'Ocultar' : 'Fechas límite, días de vacaciones e indemnización' }}
      </button>
      <div v-if="verAjustes && ajustes" class="px-4 pb-4 space-y-2">
        <p class="text-[11px] text-gray-400">Los de ley 2026. Cámbialos si la ley o el contador lo indican.</p>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <label>Prima 1 (MM-DD)<input v-model="ajustes.prima_limite_1" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Prima 2 (MM-DD)<input v-model="ajustes.prima_limite_2" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Intereses (MM-DD)<input v-model="ajustes.intereses_limite" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Cesantías (MM-DD)<input v-model="ajustes.cesantias_limite" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Vacaciones por año (días)<input v-model.number="ajustes.dias_vacaciones_anio" type="number" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Salario mínimo<input v-model.number="ajustes.smmlv" type="number" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Indemn. 1.er año (días)<input v-model.number="ajustes.indemnizacion_primer_anio" type="number" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Indemn. año adicional<input v-model.number="ajustes.indemnizacion_anio_adicional" type="number" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>Desde (mínimos)<input v-model.number="ajustes.indemnizacion_tope_smmlv" type="number" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5" /></label>
          <label>…1.er año / adicional<div class="flex gap-1 mt-0.5"><input v-model.number="ajustes.indemnizacion_primer_anio_alto" type="number" class="w-1/2 border border-gray-200 rounded-lg px-2 py-1.5" /><input v-model.number="ajustes.indemnizacion_anio_adicional_alto" type="number" class="w-1/2 border border-gray-200 rounded-lg px-2 py-1.5" /></div></label>
        </div>
        <button @click="guardarAjustes" class="w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2">Guardar</button>
      </div>
    </div>

    <!-- Registrar vacaciones -->
    <Teleport to="body">
      <div v-if="formVac" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="formVac = null">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-3">
          <div class="flex items-center justify-between">
            <p class="font-semibold text-gray-800">Vacaciones · {{ formVac.v.nombre }}</p>
            <button @click="formVac = null" class="text-gray-400" aria-label="Cerrar"><XMarkIcon class="w-5 h-5" /></button>
          </div>
          <p class="text-xs text-gray-500">Tiene {{ formVac.v.dias_pendientes.toLocaleString('es-CO') }} días pendientes.</p>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label>Días hábiles<input v-model.number="formVac.dias" type="number" min="1" :max="formVac.v.dias_pendientes" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 mt-0.5 text-sm" /></label>
            <label>Desde<input v-model="formVac.desde" type="date" class="w-full border border-gray-200 rounded-lg px-1 py-1.5 mt-0.5 text-sm" /></label>
            <label>Hasta<input v-model="formVac.hasta" type="date" class="w-full border border-gray-200 rounded-lg px-1 py-1.5 mt-0.5 text-sm" /></label>
          </div>
          <div class="space-y-1.5 text-sm text-gray-700">
            <label class="flex items-start gap-2"><input v-model="formVac.forma" type="radio" value="con_nomina" class="mt-1" />
              <span>Se pagan con la nómina normal<span class="block text-[11px] text-gray-400">Descansa y su quincena sale igual: no sale plata aparte.</span></span></label>
            <label class="flex items-start gap-2"><input v-model="formVac.forma" type="radio" value="pago" class="mt-1" />
              <span>Se pagan aparte<span class="block text-[11px] text-gray-400">Por adelantado o en dinero: {{ pesos(formVac.dias * formVac.v.valor_dia) }}.</span></span></label>
          </div>
          <button @click="guardarVacaciones" class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5">Registrar</button>
        </div>
      </div>
    </Teleport>

    <LiquidacionForm v-if="nuevaLiquidacion" @cerrar="nuevaLiquidacion = false" @guardado="nuevaLiquidacion = false; cargarLiquidaciones()" />
  </div>
</template>
