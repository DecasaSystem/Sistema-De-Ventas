<script setup>
// Lo que la empresa paga por detrás de la nómina, configurable porque la ley
// cambia: cada concepto (pensión, ARL, prima…) con su porcentaje y la fecha
// desde la que rige. Se pueden crear conceptos nuevos y desactivar los que
// la ley quite. No mueve lo que cobra nadie: es el costo real que lee Finanzas,
// y lo ya pagado no cambia (cada pago congeló el suyo). Las excepciones de
// cada persona (a uno no se le paga pensión) van en su ficha.
import { ref, computed } from 'vue'
import { ChevronDownIcon, ChevronUpIcon, BuildingOfficeIcon, PlusIcon } from '@heroicons/vue/24/outline'
import {
  getConceptosEmpleador, crearConceptoEmpleador, editarConceptoEmpleador,
  agregarTarifaConcepto, quitarTarifaConcepto, guardarAjustesConceptos,
} from '@/api/nomina'
import { useToast } from '@/composables/useToast'

const emit  = defineEmits(['guardado'])
const toast = useToast()

const abierto        = ref(false)
const cargando       = ref(false)
const conceptos      = ref([])
const bonoEsSalario  = ref(false)
const verInactivos   = ref(false)
const editando       = ref(null)   // id del concepto abierto
const guardando      = ref(false)
const formEdit       = ref({})
const formTarifa     = ref({})
const mostrarNuevo   = ref(false)
const formNuevo      = ref({})

const GRUPOS = [
  { value: 'aportes',      label: 'Aportes (planilla)' },
  { value: 'prestaciones', label: 'Prestaciones (provisión)' },
  { value: 'otros',        label: 'Otros' },
]
const BASES = [
  { value: 'salario',         label: 'Sueldo' },
  { value: 'salario_auxilio', label: 'Sueldo + auxilio' },
  { value: 'concepto',        label: 'Otro concepto' },
]

function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function fecha(f) {
  return f ? new Date(f + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}
function pct(n) {
  return `${Number(n).toLocaleString('es-CO', { maximumFractionDigits: 3 })} %`
}
function baseTexto(c) {
  if (c.base === 'concepto') return `sobre ${c.base_concepto ?? 'otro concepto'}`
  return c.base === 'salario_auxilio' ? 'sobre sueldo + auxilio' : 'sobre el sueldo'
}

const visibles = computed(() => conceptos.value.filter(c => verInactivos.value || c.activo))
const porGrupo = computed(() => GRUPOS
  .map(g => ({ ...g, items: visibles.value.filter(c => c.grupo === g.value) }))
  .filter(g => g.items.length))
const activos = computed(() => conceptos.value.filter(c => c.activo))

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getConceptosEmpleador(true)
    conceptos.value = data.conceptos
    bonoEsSalario.value = data.bono_es_salario
  } catch {
    toast.error('No se pudieron cargar los conceptos')
  } finally {
    cargando.value = false
  }
}

function abrir() {
  abierto.value = !abierto.value
  if (abierto.value && !conceptos.value.length) cargar()
}

function editar(c) {
  if (editando.value === c.id) { editando.value = null; return }
  editando.value = c.id
  formEdit.value = { nombre: c.nombre, aplica_por_defecto: c.aplica_por_defecto, nota: c.nota ?? '' }
  formTarifa.value = { porcentaje: c.porcentaje, desde: hoyISO(), nota: '' }
}

function reemplazar(c) {
  conceptos.value = conceptos.value.map(x => x.id === c.id ? { ...c, excepciones: x.excepciones } : x)
}

async function guardarConcepto(c, cambios = formEdit.value) {
  guardando.value = true
  try {
    const { data } = await editarConceptoEmpleador(c.id, cambios)
    reemplazar(data)
    toast.success('Concepto guardado')
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

async function desactivar(c) {
  if (c.activo && !confirm(`¿Desactivar "${c.nombre}"?\n\nDeja de calcularse en los ciclos que no se han pagado. Lo ya pagado no cambia.`)) return
  await guardarConcepto(c, { activo: !c.activo })
}

async function guardarTarifa(c) {
  if (formTarifa.value.porcentaje === '' || formTarifa.value.porcentaje == null) {
    toast.error('Pon el porcentaje')
    return
  }
  guardando.value = true
  try {
    const { data } = await agregarTarifaConcepto(c.id, formTarifa.value)
    reemplazar(data)
    toast.success(`${c.nombre}: ${pct(formTarifa.value.porcentaje)} desde el ${fecha(formTarifa.value.desde)}`)
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar el porcentaje')
  } finally {
    guardando.value = false
  }
}

async function quitarTarifa(c, t) {
  if (!confirm(`¿Quitar el cambio a ${pct(t.porcentaje)} programado para el ${fecha(t.desde)}?`)) return
  try {
    await quitarTarifaConcepto(t.id)
    await cargar()
    toast.success('Cambio quitado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
  }
}

function abrirNuevo() {
  mostrarNuevo.value = !mostrarNuevo.value
  formNuevo.value = {
    nombre: '', grupo: 'aportes', base: 'salario', base_concepto_id: null,
    porcentaje: '', desde: hoyISO(), aplica_por_defecto: true,
  }
}

async function crear() {
  if (!formNuevo.value.nombre.trim()) { toast.error('Ponle un nombre'); return }
  guardando.value = true
  try {
    await crearConceptoEmpleador(formNuevo.value)
    mostrarNuevo.value = false
    await cargar()
    toast.success('Concepto creado')
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo crear')
  } finally {
    guardando.value = false
  }
}

async function cambiarBono() {
  const nuevo = !bonoEsSalario.value
  try {
    await guardarAjustesConceptos({ bono_es_salario: nuevo })
    bonoEsSalario.value = nuevo
    emit('guardado')
  } catch {
    toast.error('No se pudo guardar')
  }
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm">
    <button type="button" @click="abrir" class="w-full p-4 flex items-center justify-between gap-2 text-left">
      <span class="flex items-center gap-2.5 min-w-0">
        <span class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
          <BuildingOfficeIcon class="w-5 h-5 text-blue-600" />
        </span>
        <span class="min-w-0">
          <span class="block font-semibold text-sm text-gray-800">Lo que paga la empresa por detrás</span>
          <span class="block text-xs text-gray-400">Aportes y prestaciones · no se le paga al trabajador</span>
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
          Vienen los de ley 2026. Si la ley cambia, toca el concepto y pon el porcentaje nuevo con la fecha
          desde la que rige: lo de antes se sigue calculando con el viejo y lo ya pagado no se mueve.
          A quien no le toque algo (por ejemplo, sin pensión) se le quita en su ficha.
        </p>

        <div v-for="g in porGrupo" :key="g.value">
          <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1.5">{{ g.label }}</p>
          <div class="space-y-1.5">
            <div v-for="c in g.items" :key="c.id" class="rounded-lg border border-gray-100" :class="!c.activo && 'opacity-60'">
              <button type="button" @click="editar(c)" class="w-full flex items-start justify-between gap-2 px-3 py-2 text-left">
                <span class="min-w-0">
                  <span class="block text-sm text-gray-800">{{ c.nombre }}</span>
                  <span class="block text-[11px] text-gray-400">
                    {{ baseTexto(c) }} ·
                    {{ c.aplica_por_defecto ? 'a todos' : 'solo a quien se le ponga' }}
                    <template v-if="c.excepciones"> · {{ c.excepciones }} con excepción</template>
                    <template v-if="!c.activo"> · desactivado</template>
                  </span>
                  <span v-if="c.proximo" class="inline-block mt-1 rounded-full bg-amber-100 text-amber-800 text-[10px] font-semibold px-2 py-0.5">
                    {{ pct(c.proximo.porcentaje) }} desde {{ fecha(c.proximo.desde) }}
                  </span>
                </span>
                <span class="text-sm font-semibold text-gray-700 shrink-0">{{ pct(c.porcentaje) }}</span>
              </button>

              <div v-if="editando === c.id" class="px-3 pb-3 pt-1 border-t border-gray-50 space-y-3">
                <!-- Cambio de porcentaje con su fecha: así entra un cambio de ley. -->
                <div>
                  <p class="text-xs font-semibold text-gray-500 mb-1">Cambiar el porcentaje</p>
                  <div class="flex gap-1.5">
                    <div class="flex items-center gap-1 w-24 shrink-0">
                      <input v-model.number="formTarifa.porcentaje" type="number" step="0.001" min="0" max="100" inputmode="decimal"
                        class="w-full text-right text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                      <span class="text-xs text-gray-400">%</span>
                    </div>
                    <input v-model="formTarifa.desde" type="date"
                      class="flex-1 min-w-0 text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <input v-model="formTarifa.nota" type="text" maxlength="200" placeholder="Nota (ej. Decreto 2027)"
                    class="w-full mt-1.5 text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  <button type="button" @click="guardarTarifa(c)" :disabled="guardando"
                    class="mt-1.5 w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2 hover:bg-blue-700 disabled:opacity-50">
                    Guardar porcentaje desde esa fecha
                  </button>
                </div>

                <div v-if="c.tarifas.length">
                  <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1">Historial</p>
                  <div v-for="t in [...c.tarifas].reverse()" :key="t.id" class="flex items-center justify-between text-xs py-0.5">
                    <span class="text-gray-500">
                      Desde {{ fecha(t.desde) }}<span v-if="t.nota"> · {{ t.nota }}</span>
                    </span>
                    <span class="flex items-center gap-2 shrink-0">
                      <span class="text-gray-700 font-medium">{{ pct(t.porcentaje) }}</span>
                      <button v-if="t.desde > hoyISO()" type="button" @click="quitarTarifa(c, t)" class="text-[11px] text-red-500">Quitar</button>
                    </span>
                  </div>
                </div>

                <div class="space-y-2">
                  <input v-model="formEdit.nombre" type="text" maxlength="80"
                    class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  <label class="flex items-center gap-2.5 cursor-pointer select-none">
                    <button type="button" @click="formEdit.aplica_por_defecto = !formEdit.aplica_por_defecto"
                      :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0', formEdit.aplica_por_defecto ? 'bg-blue-600' : 'bg-gray-300']">
                      <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', formEdit.aplica_por_defecto ? 'translate-x-5' : 'translate-x-0.5']" />
                    </button>
                    <span class="text-sm text-gray-700">
                      Le aplica a todos
                      <span class="block text-xs text-gray-400">Apagado: solo a quien se le active en su ficha (ej. salud si la empresa está exonerada).</span>
                    </span>
                  </label>
                  <div class="flex gap-2">
                    <button type="button" @click="desactivar(c)" :disabled="guardando"
                      class="flex-1 bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg py-2 hover:bg-gray-200 disabled:opacity-50">
                      {{ c.activo ? 'Desactivar' : 'Reactivar' }}
                    </button>
                    <button type="button" @click="guardarConcepto(c)" :disabled="guardando"
                      class="flex-1 bg-blue-600 text-white text-xs font-semibold rounded-lg py-2 hover:bg-blue-700 disabled:opacity-50">
                      Guardar
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <button type="button" @click="verInactivos = !verInactivos" class="text-xs font-semibold text-gray-500">
          {{ verInactivos ? 'Ocultar desactivados' : 'Ver desactivados' }}
        </button>

        <!-- Concepto nuevo: si la ley trae otro aporte. -->
        <div>
          <button type="button" @click="abrirNuevo"
            class="flex items-center gap-1.5 text-xs font-semibold text-blue-600">
            <PlusIcon class="w-4 h-4" /> Concepto nuevo
          </button>
          <div v-if="mostrarNuevo" class="mt-2 rounded-lg border border-gray-100 p-3 space-y-2">
            <input v-model="formNuevo.nombre" type="text" maxlength="80" placeholder="Nombre (ej. Fondo de solidaridad)"
              class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <div class="flex gap-1">
              <button v-for="g in GRUPOS" :key="g.value" type="button" @click="formNuevo.grupo = g.value"
                :class="['flex-1 text-[11px] font-semibold rounded-lg py-1.5', formNuevo.grupo === g.value ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600']">
                {{ g.label.split(' ')[0] }}
              </button>
            </div>
            <div class="flex gap-1">
              <button v-for="b in BASES" :key="b.value" type="button" @click="formNuevo.base = b.value"
                :class="['flex-1 text-[11px] font-semibold rounded-lg py-1.5', formNuevo.base === b.value ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600']">
                {{ b.label }}
              </button>
            </div>
            <select v-if="formNuevo.base === 'concepto'" v-model="formNuevo.base_concepto_id"
              class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option :value="null" disabled>¿Sobre cuál concepto?</option>
              <option v-for="c in activos" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
            <div class="flex gap-1.5">
              <div class="flex items-center gap-1 w-24 shrink-0">
                <input v-model.number="formNuevo.porcentaje" type="number" step="0.001" min="0" max="100" inputmode="decimal" placeholder="0"
                  class="w-full text-right text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <span class="text-xs text-gray-400">%</span>
              </div>
              <input v-model="formNuevo.desde" type="date"
                class="flex-1 min-w-0 text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700 select-none">
              <input v-model="formNuevo.aplica_por_defecto" type="checkbox" class="rounded" /> Le aplica a todos
            </label>
            <button type="button" @click="crear" :disabled="guardando"
              class="w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2 hover:bg-blue-700 disabled:opacity-50">
              Crear concepto
            </button>
          </div>
        </div>

        <label class="flex items-start gap-2.5 cursor-pointer select-none">
          <button type="button" @click="cambiarBono"
            :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0 mt-0.5', bonoEsSalario ? 'bg-blue-600' : 'bg-gray-300']">
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', bonoEsSalario ? 'translate-x-5' : 'translate-x-0.5']" />
          </button>
          <span class="text-sm text-gray-700">
            El bono de producción cuenta como salario
            <span class="block text-xs text-gray-400">Apagado si se pactó como no salarial (lo usual).</span>
          </span>
        </label>
      </template>
    </div>
  </div>
</template>
