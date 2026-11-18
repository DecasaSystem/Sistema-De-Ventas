<script setup>
// Los tipos de hora extra y recargo, con su porcentaje y la fecha desde la que
// rige: igual que lo que paga la empresa por detrás, un cambio de ley se carga
// "desde" y lo de antes sigue con el viejo. Lo ya pagado no se mueve (cada pago
// guardó el porcentaje con el que se calculó).
import { ref, computed } from 'vue'
import { ChevronDownIcon, ChevronUpIcon, ClockIcon, PlusIcon } from '@heroicons/vue/24/outline'
import {
  getTiposHoraExtra, crearTipoHoraExtra, editarTipoHoraExtra,
  agregarTarifaHoraExtra, quitarTarifaHoraExtra,
} from '@/api/nomina'
import { useToast } from '@/composables/useToast'

const props = defineProps({
  // Debajo del título. En Novedades se dice otra cosa que en Sueldos.
  subtitulo: { type: String, default: 'Tipos y porcentajes · las horas se anotan en Gente → Horas extra' },
  // En la hoja de Ajustes se abre de una: es lo primero que se busca ahí.
  abiertoInicial: { type: Boolean, default: false },
})
const emit  = defineEmits(['guardado'])
const toast = useToast()

const abierto      = ref(false)
const cargando     = ref(false)
const tipos        = ref([])
const verInactivos = ref(false)
const editando     = ref(null)
const guardando    = ref(false)
const formNombre   = ref('')
const formTarifa   = ref({ porcentaje: 0, desde: '' })
const mostrarNuevo = ref(false)
const formNuevo    = ref({ nombre: '', calculo: 'extra', porcentaje: 25 })

function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function fecha(f) {
  return f ? new Date(f + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}
function pct(n) {
  return `${Number(n).toLocaleString('es-CO', { maximumFractionDigits: 2 })} %`
}
/** "hora × 1,25" o "recargo de 0,35 la hora": lo que se multiplica por el valor hora. */
function comoSeCalcula(t, p = t.porcentaje) {
  const f = (t.calculo === 'recargo' ? p / 100 : 1 + p / 100).toLocaleString('es-CO', { maximumFractionDigits: 3 })
  return t.calculo === 'recargo' ? `se suma valor hora × ${f}` : `valor hora × ${f}`
}

const visibles = computed(() => tipos.value.filter(t => verInactivos.value || t.activo))

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getTiposHoraExtra(true)
    tipos.value = data
  } catch {
    toast.error('No se pudieron cargar los tipos de hora extra')
  } finally {
    cargando.value = false
  }
}

function abrir() {
  abierto.value = !abierto.value
  if (abierto.value && !tipos.value.length) cargar()
}

if (props.abiertoInicial) abrir()

function editar(t) {
  if (editando.value === t.id) {
    editando.value = null
    return
  }
  editando.value = t.id
  formNombre.value = t.nombre
  formTarifa.value = { porcentaje: t.porcentaje, desde: hoyISO() }
}

function reemplazar(t) {
  const i = tipos.value.findIndex(x => x.id === t.id)
  if (i >= 0) tipos.value.splice(i, 1, t)
  else tipos.value.push(t)
}

async function guardarTarifa(t) {
  if (!formTarifa.value.desde) {
    toast.error('¿Desde qué fecha rige?')
    return
  }
  guardando.value = true
  try {
    const { data } = await agregarTarifaHoraExtra(t.id, formTarifa.value)
    reemplazar(data)
    toast.success(`${data.nombre}: ${pct(formTarifa.value.porcentaje)} desde ${fecha(formTarifa.value.desde)}`)
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

async function quitarTarifa(t, tarifa) {
  if (!confirm(`¿Quitar el ${pct(tarifa.porcentaje)} desde ${fecha(tarifa.desde)}?`)) return
  try {
    const { data } = await quitarTarifaHoraExtra(tarifa.id)
    reemplazar(data)
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
  }
}

async function guardarTipo(t, cambios) {
  guardando.value = true
  try {
    const { data } = await editarTipoHoraExtra(t.id, cambios)
    reemplazar(data)
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

async function cambiarCalculo(t, calculo) {
  if (t.calculo === calculo) return
  const texto = calculo === 'recargo'
    ? `"${t.nombre}" pasará a sumar solo el ${pct(t.porcentaje)} (la hora ya va en el sueldo).`
    : `"${t.nombre}" pasará a pagar la hora completa más el ${pct(t.porcentaje)}.`
  if (!confirm(`${texto}\n\nCambia las horas de este tipo que no se han pagado. ¿Seguir?`)) return
  await guardarTipo(t, { calculo })
}

async function crear() {
  if (!formNuevo.value.nombre.trim()) {
    toast.error('Ponle un nombre')
    return
  }
  guardando.value = true
  try {
    const { data } = await crearTipoHoraExtra({ ...formNuevo.value, nombre: formNuevo.value.nombre.trim() })
    tipos.value.push(data)
    mostrarNuevo.value = false
    formNuevo.value = { nombre: '', calculo: 'extra', porcentaje: 25 }
    toast.success('Tipo creado')
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo crear')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm">
    <button type="button" @click="abrir" class="w-full p-4 flex items-center justify-between gap-2 text-left">
      <span class="flex items-center gap-2.5 min-w-0">
        <span class="w-9 h-9 rounded-xl bg-gray-100 flex items-center justify-center shrink-0">
          <ClockIcon class="w-5 h-5 text-indigo-600" />
        </span>
        <span class="min-w-0">
          <span class="block font-semibold text-sm text-gray-800">Horas extra y recargos</span>
          <span class="block text-xs text-gray-400">{{ props.subtitulo }}</span>
        </span>
      </span>
      <component :is="abierto ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-300 shrink-0" />
    </button>

    <div v-if="abierto" class="px-4 pb-4 border-t border-gray-50 pt-3 space-y-4">
      <div v-if="cargando" class="flex justify-center py-6">
        <div class="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <template v-else>
        <p class="text-xs text-gray-500">
          Vienen los de ley (el dominical sube por etapas con la Ley 2466 de 2025): revísalos con el contador.
          <strong>Extra</strong> se paga la hora más el porcentaje; <strong>recargo</strong> solo suma el porcentaje,
          porque la hora ya va en el sueldo. Si la ley cambia, pon el porcentaje nuevo con la fecha desde la que rige.
        </p>

        <div class="space-y-1.5">
          <div v-for="t in visibles" :key="t.id" class="rounded-lg border border-gray-100" :class="!t.activo && 'opacity-60'">
            <button type="button" @click="editar(t)" class="w-full flex items-start justify-between gap-2 px-3 py-2 text-left">
              <span class="min-w-0">
                <span class="block text-sm text-gray-800">{{ t.nombre }}</span>
                <span class="block text-[11px] text-gray-400">
                  {{ t.calculo === 'recargo' ? 'Recargo' : 'Extra' }} · {{ comoSeCalcula(t) }}
                  <template v-if="!t.activo"> · apagado</template>
                </span>
                <span v-if="t.proximo" class="inline-block mt-1 rounded-full bg-amber-100 text-amber-800 text-[10px] font-semibold px-2 py-0.5">
                  {{ pct(t.proximo.porcentaje) }} desde {{ fecha(t.proximo.desde) }}
                </span>
              </span>
              <span class="text-sm font-semibold text-gray-700 shrink-0">{{ pct(t.porcentaje) }}</span>
            </button>

            <div v-if="editando === t.id" class="px-3 pb-3 pt-1 border-t border-gray-50 space-y-3">
              <div>
                <p class="text-xs font-semibold text-gray-500 mb-1">Cambiar el porcentaje</p>
                <div class="flex gap-1.5">
                  <div class="flex items-center gap-1 w-24 shrink-0">
                    <input v-model.number="formTarifa.porcentaje" type="number" step="0.5" min="0" max="500" inputmode="decimal"
                      class="w-full text-right text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    <span class="text-xs text-gray-400">%</span>
                  </div>
                  <input v-model="formTarifa.desde" type="date"
                    class="flex-1 min-w-0 text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <p class="text-[11px] text-gray-400 mt-1">Así queda: {{ comoSeCalcula(t, Number(formTarifa.porcentaje) || 0) }}.</p>
                <button type="button" @click="guardarTarifa(t)" :disabled="guardando"
                  class="mt-1.5 w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2 hover:bg-blue-700 disabled:opacity-50">
                  Guardar porcentaje desde esa fecha
                </button>
              </div>

              <div v-if="t.tarifas.length">
                <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1">Historial</p>
                <div v-for="x in [...t.tarifas].reverse()" :key="x.id" class="flex items-center justify-between text-xs py-0.5">
                  <span class="text-gray-500">Desde {{ fecha(x.desde) }}</span>
                  <span class="flex items-center gap-2 shrink-0">
                    <span class="text-gray-700 font-medium">{{ pct(x.porcentaje) }}</span>
                    <button v-if="x.desde > hoyISO()" type="button" @click="quitarTarifa(t, x)" class="text-[11px] text-red-500">Quitar</button>
                  </span>
                </div>
              </div>

              <div>
                <p class="text-xs font-semibold text-gray-500 mb-1">Cómo se calcula</p>
                <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
                  <button type="button" @click="cambiarCalculo(t, 'extra')" :disabled="guardando"
                    :class="['flex-1 text-xs font-semibold rounded-lg py-1.5', t.calculo === 'extra' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">Extra (hora + %)</button>
                  <button type="button" @click="cambiarCalculo(t, 'recargo')" :disabled="guardando"
                    :class="['flex-1 text-xs font-semibold rounded-lg py-1.5', t.calculo === 'recargo' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">Recargo (solo %)</button>
                </div>
                <p class="text-[11px] text-gray-400 mt-1">Cambia las horas de este tipo que no se han pagado; lo pagado no se mueve.</p>
              </div>

              <div class="space-y-2">
                <input v-model="formNombre" type="text" maxlength="80"
                  class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <div class="flex gap-2">
                  <button type="button" @click="guardarTipo(t, { activo: !t.activo })" :disabled="guardando"
                    class="flex-1 bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg py-2 hover:bg-gray-200 disabled:opacity-50">
                    {{ t.activo ? 'Apagar' : 'Prender' }}
                  </button>
                  <button type="button" @click="guardarTipo(t, { nombre: formNombre.trim() })" :disabled="guardando || !formNombre.trim()"
                    class="flex-1 bg-blue-600 text-white text-xs font-semibold rounded-lg py-2 hover:bg-blue-700 disabled:opacity-50">
                    Guardar nombre
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="flex items-center justify-between gap-2">
          <button type="button" @click="verInactivos = !verInactivos" class="text-xs text-gray-500">
            {{ verInactivos ? 'Esconder los apagados' : 'Ver los apagados' }}
          </button>
          <button type="button" @click="mostrarNuevo = !mostrarNuevo" class="text-xs font-semibold text-blue-600 flex items-center gap-1">
            <PlusIcon class="w-3.5 h-3.5" />Nuevo tipo
          </button>
        </div>

        <div v-if="mostrarNuevo" class="rounded-lg border border-gray-100 p-3 space-y-2">
          <input v-model="formNuevo.nombre" type="text" maxlength="80" placeholder="Recargo nocturno dominical..."
            class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
          <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
            <button type="button" @click="formNuevo.calculo = 'extra'"
              :class="['flex-1 text-xs font-semibold rounded-lg py-1.5', formNuevo.calculo === 'extra' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">Extra (hora + %)</button>
            <button type="button" @click="formNuevo.calculo = 'recargo'"
              :class="['flex-1 text-xs font-semibold rounded-lg py-1.5', formNuevo.calculo === 'recargo' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">Recargo (solo %)</button>
          </div>
          <div class="flex items-center gap-1 w-28">
            <input v-model.number="formNuevo.porcentaje" type="number" step="0.5" min="0" max="500" inputmode="decimal"
              class="w-full text-right text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <span class="text-xs text-gray-400">%</span>
          </div>
          <button type="button" @click="crear" :disabled="guardando"
            class="w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2 hover:bg-blue-700 disabled:opacity-50">
            Crear tipo
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
