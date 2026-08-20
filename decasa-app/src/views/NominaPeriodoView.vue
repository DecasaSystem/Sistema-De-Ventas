<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import {
  getPeriodo, marcarPeriodoPagado, agregarEmpleadoAPeriodo,
  actualizarItem, eliminarItem, crearAjuste, eliminarAjuste,
  crearAusencia, eliminarAusencia,
} from '@/api/nomina'
import { getEmpleados } from '@/api/empleados'
import {
  CalendarDaysIcon, PlusIcon, TrashIcon, XMarkIcon,
  CheckCircleIcon, ChevronDownIcon, ChevronUpIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()

const periodo   = ref(null)
const cargando  = ref(true)
const abiertoId = ref(null)
const guardandoItemId = ref(null)

const mostrarAgregar   = ref(false)
const empleadosDisponibles = ref([])
const empleadoSeleccionado = ref('')
const agregando = ref(false)

const nuevoAjuste = ref({}) // { [itemId]: { nombre, monto } }
const nuevaFalta  = ref({}) // { [itemId]: { fecha, horas, motivo } }

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getPeriodo(route.params.id)
    periodo.value = data
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo cargar el período')
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}

const pagado = computed(() => !!periodo.value?.pagado_at)

function toggle(item) {
  abiertoId.value = abiertoId.value === item.id ? null : item.id
  if (!nuevoAjuste.value[item.id]) nuevoAjuste.value[item.id] = { nombre: '', monto: '' }
  if (!nuevaFalta.value[item.id]) nuevaFalta.value[item.id] = { fecha: '', horas: item.horas_dia, motivo: '' }
}

async function guardarItem(item) {
  guardandoItemId.value = item.id
  try {
    await actualizarItem(item.id, {
      valor_label: item.valor_label,
      valor_dia: item.valor_dia,
      dias_trabajados: item.dias_trabajados,
      observaciones: item.observaciones,
    })
    toast.success('Guardado')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoItemId.value = null
  }
}

async function quitarItem(item) {
  if (!confirm(`¿Quitar a "${item.empleado_nombre}" de este período?`)) return
  try {
    await eliminarItem(item.id)
    toast.success('Quitado del período')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
  }
}

async function agregarAjuste(item) {
  const a = nuevoAjuste.value[item.id]
  if (!a?.nombre?.trim() || a.monto === '' || a.monto === null) {
    toast.error('Ponle nombre y valor al ajuste')
    return
  }
  try {
    await crearAjuste(item.id, { nombre: a.nombre.trim(), monto: a.monto })
    nuevoAjuste.value[item.id] = { nombre: '', monto: '' }
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo agregar el ajuste')
  }
}

async function quitarAjuste(ajusteId) {
  try {
    await eliminarAjuste(ajusteId)
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
  }
}

async function agregarFalta(item) {
  const f = nuevaFalta.value[item.id]
  if (!f?.fecha) {
    toast.error('Elige la fecha de la falta')
    return
  }
  try {
    const { data } = await crearAusencia({
      empleado_id: item.empleado_id,
      fecha_inicio: f.fecha,
      horas: f.horas || item.horas_dia,
      motivo: f.motivo?.trim() || null,
    })
    if (data.no_aplicadas?.length) {
      toast.error('Esa fecha cae en un período ya pagado')
    } else {
      nuevaFalta.value[item.id] = { fecha: '', horas: item.horas_dia, motivo: '' }
      await cargar()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar la falta')
  }
}

async function quitarFalta(ausenciaId) {
  try {
    await eliminarAusencia(ausenciaId)
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
  }
}

async function abrirAgregarEmpleado() {
  try {
    const { data } = await getEmpleados()
    const yaEstan = new Set(periodo.value.items.map(i => i.empleado_id))
    // Se filtra a la misma frecuencia del período para no mezclar por
    // accidente — el que de verdad necesite mezclar frecuencias puede
    // seguir creando el período a mano con la frecuencia que quiera.
    empleadosDisponibles.value = data.filter(e => !yaEstan.has(e.id) && e.periodicidad === periodo.value.periodicidad)
    empleadoSeleccionado.value = ''
    mostrarAgregar.value = true
  } catch {
    toast.error('No se pudo cargar la lista de trabajadores')
  }
}

async function confirmarAgregarEmpleado() {
  if (!empleadoSeleccionado.value) return
  agregando.value = true
  try {
    await agregarEmpleadoAPeriodo(periodo.value.id, empleadoSeleccionado.value)
    mostrarAgregar.value = false
    toast.success('Trabajador agregado')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo agregar')
  } finally {
    agregando.value = false
  }
}

async function pagar() {
  if (!confirm('¿Marcar este período como pagado? Después no se va a poder editar.')) return
  try {
    await marcarPeriodoPagado(periodo.value.id)
    toast.success('Período marcado como pagado')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo marcar como pagado')
  }
}
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center gap-3 mb-4">
      <button @click="router.push({ name: 'nomina' })" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h1 class="text-lg font-bold text-gray-800 flex-1 truncate">{{ periodo?.nombre ?? 'Cargando...' }}</h1>
    </div>

    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <template v-else-if="periodo">
      <div class="bg-white rounded-xl shadow-sm p-4 mb-4">
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-2">
          <CalendarDaysIcon class="w-4 h-4" />
          {{ periodo.fecha_inicio }} a {{ periodo.fecha_fin }} · {{ periodo.dias_periodo }} días
          <span class="text-[10px] font-semibold text-blue-600 bg-blue-50 rounded-full px-1.5 py-0.5">{{ periodo.periodicidad_label }}</span>
        </div>
        <div class="flex items-center justify-between">
          <p class="text-2xl font-bold text-gray-800">{{ formatoPesos(periodo.total_general) }}</p>
          <span
            class="text-xs font-semibold px-2.5 py-1 rounded-full flex items-center gap-1"
            :class="pagado ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'"
          >
            <CheckCircleIcon v-if="pagado" class="w-3.5 h-3.5" />
            {{ pagado ? 'Pagado' : 'Borrador' }}
          </span>
        </div>
      </div>

      <div class="flex items-center gap-2 mb-3">
        <button
          v-if="!pagado"
          @click="abrirAgregarEmpleado"
          class="flex items-center gap-1.5 bg-white border border-gray-200 text-gray-700 text-xs font-semibold px-3 py-2 rounded-xl hover:bg-gray-50 transition-colors"
        >
          <PlusIcon class="w-4 h-4" /> Agregar trabajador
        </button>
        <button
          v-if="!pagado"
          @click="pagar"
          class="flex items-center gap-1.5 bg-green-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-green-700 transition-colors shadow-sm ml-auto"
        >
          <CheckCircleIcon class="w-4 h-4" /> Marcar como pagado
        </button>
      </div>

      <div class="space-y-2.5">
        <div v-for="item in periodo.items" :key="item.id" class="bg-white rounded-xl shadow-sm overflow-hidden">
          <div class="p-4 flex items-start justify-between gap-2 cursor-pointer" @click="toggle(item)">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">{{ item.empleado_nombre }}</p>
              <p class="text-xs text-gray-500 mt-0.5">{{ item.empleado_cargo || 'Sin cargo' }} · {{ item.dias_trabajados }}/{{ periodo.dias_periodo }} días</p>
              <p v-if="item.observaciones" class="text-xs text-gray-400 italic mt-1 truncate">{{ item.observaciones }}</p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
              <p class="font-bold text-sm text-gray-800">{{ formatoPesos(item.total) }}</p>
              <component :is="abiertoId === item.id ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-300" />
            </div>
          </div>

          <div v-if="abiertoId === item.id" class="px-4 pb-4 border-t border-gray-50 pt-3 space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-[11px] text-gray-500 mb-1">{{ item.valor_label }} (por día)</label>
                <InputPesos v-model="item.valor_dia" :disabled="pagado" class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50" />
              </div>
              <div>
                <label class="block text-[11px] text-gray-500 mb-1">Días trabajados</label>
                <input
                  v-model.number="item.dias_trabajados" type="number" step="0.5" min="0" :max="periodo.dias_periodo * 2"
                  :disabled="pagado"
                  class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50"
                />
              </div>
            </div>
            <div>
              <label class="block text-[11px] text-gray-500 mb-1">Observaciones</label>
              <textarea
                v-model="item.observaciones" rows="2" :disabled="pagado"
                placeholder="Faltó tal día, hora extra, vacaciones..."
                class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50"
              />
            </div>

            <div>
              <label class="block text-[11px] text-gray-500 mb-1">Subtotal ({{ item.dias_trabajados }} días × {{ formatoPesos(item.valor_dia) }})</label>
              <p class="text-sm text-gray-700">{{ formatoPesos(item.subtotal) }}</p>
            </div>

            <!-- Ajustes -->
            <div>
              <label class="block text-[11px] text-gray-500 mb-1.5">Ajustes (bonos y descuentos)</label>
              <div v-if="item.ajustes.length" class="space-y-1.5 mb-2">
                <div v-for="a in item.ajustes" :key="a.id" class="flex items-center justify-between gap-2 bg-gray-50 rounded-lg px-2.5 py-1.5">
                  <p class="text-xs text-gray-700 truncate">{{ a.nombre }}</p>
                  <div class="flex items-center gap-2 shrink-0">
                    <p class="text-xs font-semibold" :class="a.monto >= 0 ? 'text-green-600' : 'text-red-600'">
                      {{ a.monto >= 0 ? '+' : '' }}{{ formatoPesos(a.monto) }}
                    </p>
                    <button v-if="!pagado" @click="quitarAjuste(a.id)" class="text-gray-300 hover:text-red-600 transition-colors">
                      <XMarkIcon class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>
              </div>
              <div v-if="!pagado" class="flex gap-1.5">
                <input
                  v-model="nuevoAjuste[item.id].nombre" placeholder="Ej: Hora extra"
                  class="flex-1 min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <InputPesos
                  v-model="nuevoAjuste[item.id].monto" permite-vacio placeholder="Valor"
                  class="w-24 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <button @click="agregarAjuste(item)" class="shrink-0 text-blue-600 hover:text-blue-700">
                  <PlusIcon class="w-5 h-5" />
                </button>
              </div>
              <p class="text-[11px] text-gray-400 mt-1">Un valor negativo se resta (ej: -20000 para un préstamo).</p>
            </div>

            <!-- Faltas -->
            <div>
              <label class="block text-[11px] text-gray-500 mb-1.5">Faltas ({{ formatoPesos(item.valor_hora) }}/hora)</label>
              <div v-if="item.ausencias.length" class="space-y-1.5 mb-2">
                <div v-for="a in item.ausencias" :key="a.id" class="flex items-center justify-between gap-2 bg-amber-50 rounded-lg px-2.5 py-1.5">
                  <p class="text-xs text-gray-700 truncate">{{ a.fecha }} · {{ a.horas }}h<span v-if="a.motivo"> · {{ a.motivo }}</span></p>
                  <div class="flex items-center gap-2 shrink-0">
                    <p class="text-xs font-semibold text-red-600">-{{ formatoPesos(a.monto) }}</p>
                    <button v-if="!pagado" @click="quitarFalta(a.id)" class="text-gray-300 hover:text-red-600 transition-colors">
                      <XMarkIcon class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>
              </div>
              <div v-if="!pagado" class="flex gap-1.5">
                <input
                  v-model="nuevaFalta[item.id].fecha" type="date"
                  class="flex-1 min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <input
                  v-model.number="nuevaFalta[item.id].horas" type="number" step="0.5" min="0.5" max="24" placeholder="Horas"
                  class="w-16 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <input
                  v-model="nuevaFalta[item.id].motivo" placeholder="Motivo"
                  class="w-20 min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <button @click="agregarFalta(item)" class="shrink-0 text-blue-600 hover:text-blue-700">
                  <PlusIcon class="w-5 h-5" />
                </button>
              </div>
              <p class="text-[11px] text-gray-400 mt-1">Se resta aparte de los días trabajados — no hace falta bajar el número de días a mano.</p>
            </div>

            <div class="flex items-center justify-between pt-2 border-t border-gray-50">
              <p class="text-sm font-bold text-gray-800">Total: {{ formatoPesos(item.total) }}</p>
              <div v-if="!pagado" class="flex items-center gap-3">
                <button @click="quitarItem(item)" class="text-xs text-red-500 hover:text-red-700 flex items-center gap-1">
                  <TrashIcon class="w-3.5 h-3.5" /> Quitar
                </button>
                <button
                  @click="guardarItem(item)" :disabled="guardandoItemId === item.id"
                  class="bg-blue-600 text-white text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-blue-700 disabled:opacity-50"
                >{{ guardandoItemId === item.id ? 'Guardando...' : 'Guardar' }}</button>
              </div>
            </div>
          </div>
        </div>

        <p v-if="!periodo.items.length" class="text-center py-8 text-gray-400 text-sm">Sin trabajadores en este período.</p>
      </div>
    </template>

    <!-- Agregar trabajador al período -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarAgregar" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarAgregar = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
              <p class="font-semibold text-gray-800">Agregar trabajador al período</p>
              <button @click="mostrarAgregar = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="p-5">
              <select v-model="empleadoSeleccionado" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">Seleccionar...</option>
                <option v-for="e in empleadosDisponibles" :key="e.id" :value="e.id">{{ e.nombre }} — {{ e.cargo || 'Sin cargo' }}</option>
              </select>
              <p v-if="!empleadosDisponibles.length" class="text-xs text-gray-400 mt-2">Todos los trabajadores activos de esta frecuencia ({{ periodo.periodicidad_label }}) ya están en este período.</p>
            </div>
            <div class="flex gap-2.5 p-5 pt-2">
              <button @click="mostrarAgregar = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200">Cancelar</button>
              <button
                @click="confirmarAgregarEmpleado" :disabled="agregando || !empleadoSeleccionado"
                class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 disabled:opacity-50"
              >{{ agregando ? 'Agregando...' : 'Agregar' }}</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
