<script setup>
// Liquidar a quien se va: primero se calcula y se ve todo el desglose; solo
// después se registra. El servidor recalcula al registrar (no se mandan montos,
// salvo la indemnización de un contrato fijo y lo que se acuerde aparte).
import { ref, computed, onMounted } from 'vue'
import { XMarkIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { getEmpleados } from '@/api/empleados'
import { calcularLiquidacion, registrarLiquidacion } from '@/api/nomina'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['cerrar', 'guardado'])
const toast = useToast()

const MOTIVOS = [
  ['renuncia', 'Renuncia'], ['despido_sin_justa_causa', 'Despido sin justa causa'],
  ['despido_justa_causa', 'Despido con justa causa'], ['fin_contrato', 'Terminación del contrato'], ['mutuo_acuerdo', 'Mutuo acuerdo'],
]
const CONTRATOS = [
  ['indefinido', 'Término indefinido'], ['fijo', 'Término fijo'], ['obra_labor', 'Obra o labor'],
  ['aprendizaje', 'Aprendizaje'], ['servicios', 'Prestación de servicios'],
]

function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function pesos(n) { return (n < 0 ? '−' : '') + '$' + Math.abs(Math.round(n ?? 0)).toLocaleString('es-CO') }

const empleados = ref([])
const form = ref({
  usuario_id: null, fecha_retiro: hoyISO(), motivo: 'renuncia', tipo_contrato: 'indefinido',
  indemnizacion_manual: null, descontar_prestamos: true, otros: [], notas: '',
})
const calculo = ref(null)
const calculando = ref(false)
const guardando = ref(false)

onMounted(async () => {
  try {
    empleados.value = (await getEmpleados(false)).data.filter(e => e.nomina_sueldo_id)
  } catch {
    toast.error('No se pudo cargar la lista de trabajadores')
  }
})

function elegir() {
  const e = empleados.value.find(x => x.id === form.value.usuario_id)
  if (e?.nomina_tipo_contrato) form.value.tipo_contrato = e.nomina_tipo_contrato
  calculo.value = null
}

const pideIndemnizacionManual = computed(() => form.value.motivo === 'despido_sin_justa_causa' && form.value.tipo_contrato !== 'indefinido')

function payload() {
  const f = form.value
  return {
    ...f,
    indemnizacion_manual: f.indemnizacion_manual === '' ? null : f.indemnizacion_manual,
    otros: f.otros.filter(o => o.nombre && o.monto),
  }
}

async function calcular() {
  if (!form.value.usuario_id) return toast.error('Elige a quién se liquida')
  calculando.value = true
  try {
    calculo.value = (await calcularLiquidacion(payload())).data
  } catch (e) {
    const err = e.response?.data?.errors
    toast.error(err ? Object.values(err)[0][0] : 'No se pudo calcular')
  } finally {
    calculando.value = false
  }
}

async function registrar() {
  if (!confirm(`¿Registrar la liquidación de ${calculo.value.nombre} por ${pesos(calculo.value.total)}?\n\nSe pagan sus ciclos pendientes, queda fuera de nómina y se guarda para el PDF. Si te equivocas, se puede anular.`)) return
  guardando.value = true
  try {
    await registrarLiquidacion(payload())
    toast.success('Liquidación registrada')
    emit('guardado')
  } catch (e) {
    const err = e.response?.data?.errors
    toast.error(err ? Object.values(err)[0][0] : 'No se pudo registrar')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-white flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <p class="font-semibold text-gray-800">Liquidación</p>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100" aria-label="Cerrar">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-3">
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">¿Quién se va?</label>
            <select v-model="form.usuario_id" @change="elegir" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
              <option :value="null" disabled>Elige…</option>
              <option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}<template v-if="e.cargo"> — {{ e.cargo }}</template></option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Último día</label>
              <input v-model="form.fecha_retiro" @change="calculo = null" type="date" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Contrato</label>
              <select v-model="form.tipo_contrato" @change="calculo = null" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
                <option v-for="[v, l] in CONTRATOS" :key="v" :value="v">{{ l }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Motivo</label>
            <select v-model="form.motivo" @change="calculo = null" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
              <option v-for="[v, l] in MOTIVOS" :key="v" :value="v">{{ l }}</option>
            </select>
          </div>
          <div v-if="pideIndemnizacionManual">
            <label class="block text-xs font-semibold text-gray-500 mb-1">Indemnización (salarios del tiempo que faltaba)</label>
            <input v-model.number="form.indemnizacion_manual" @change="calculo = null" type="number" min="0" inputmode="numeric" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
          </div>
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input v-model="form.descontar_prestamos" @change="calculo = null" type="checkbox" class="rounded" /> Descontar lo que debe de préstamos
          </label>

          <div>
            <div class="flex items-center justify-between">
              <span class="text-xs font-semibold text-gray-500">Otros acordados (+ o −)</span>
              <button @click="form.otros.push({ nombre: '', monto: null }); calculo = null" class="text-xs font-semibold text-blue-600 flex items-center gap-0.5"><PlusIcon class="w-3.5 h-3.5" /> Agregar</button>
            </div>
            <div v-for="(o, i) in form.otros" :key="i" class="flex gap-1.5 mt-1.5">
              <input v-model="o.nombre" @change="calculo = null" placeholder="Concepto" class="flex-1 min-w-0 text-sm border border-gray-200 rounded-lg px-2 py-1.5" />
              <input v-model.number="o.monto" @change="calculo = null" type="number" placeholder="Monto" class="w-28 text-sm border border-gray-200 rounded-lg px-2 py-1.5" />
              <button @click="form.otros.splice(i, 1); calculo = null" class="text-gray-400" aria-label="Quitar"><TrashIcon class="w-4 h-4" /></button>
            </div>
          </div>

          <button @click="calcular" :disabled="calculando" class="w-full bg-gray-800 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50">
            {{ calculando ? 'Calculando…' : 'Calcular' }}
          </button>

          <!-- El desglose -->
          <div v-if="calculo" class="rounded-xl border border-gray-200 p-3 space-y-1.5">
            <p class="text-xs text-gray-500">
              {{ calculo.nombre }} · ingresó el {{ calculo.fecha_ingreso }} · {{ calculo.dias_servicio }} días de servicio ·
              sueldo {{ pesos(calculo.sueldo.mensual) }}/mes
            </p>
            <div v-for="(c, i) in calculo.conceptos" :key="i" class="flex justify-between gap-2 text-sm">
              <span class="min-w-0">
                <span class="text-gray-700">{{ c.nombre }}</span>
                <span class="block text-[11px] text-gray-400">{{ c.detalle }}</span>
              </span>
              <span class="shrink-0 text-gray-800">{{ pesos(c.monto) }}</span>
            </div>
            <div v-for="(c, i) in calculo.deducciones" :key="'d' + i" class="flex justify-between gap-2 text-sm">
              <span class="text-gray-700">− {{ c.nombre }}</span><span class="shrink-0 text-red-600">−{{ pesos(c.monto) }}</span>
            </div>
            <p v-if="calculo.indemnizacion?.monto === 0" class="text-[11px] text-gray-400">Indemnización: {{ calculo.indemnizacion.detalle }}</p>
            <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-bold">
              <span class="text-gray-800">Total a pagar</span><span class="text-gray-900">{{ pesos(calculo.total) }}</span>
            </div>
            <p class="text-[10px] text-gray-400">Prima, cesantías, intereses y vacaciones salen de lo provisionado en cada pago de nómina del periodo.</p>
          </div>
        </div>

        <div v-if="calculo" class="sticky bottom-0 bg-white px-5 py-4 border-t border-gray-100">
          <button @click="registrar" :disabled="guardando" class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50">
            {{ guardando ? 'Registrando…' : `Registrar liquidación · ${pesos(calculo.total)}` }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
