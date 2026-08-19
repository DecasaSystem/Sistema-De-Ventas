<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { getEmpleados, crearEmpleado, actualizarEmpleado, eliminarEmpleado } from '@/api/empleados'
import { getPeriodos, crearPeriodo, eliminarPeriodo } from '@/api/nomina'
import {
  BanknotesIcon,
  PlusIcon,
  PencilSquareIcon,
  TrashIcon,
  XMarkIcon,
  UsersIcon,
  CalendarDaysIcon,
  CheckCircleIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast  = useToast()

const tab = ref('periodos')

// ── Períodos ────────────────────────────────────────────────────────────
const periodos         = ref([])
const cargandoPeriodos = ref(true)
const mostrarFormPeriodo = ref(false)
const formPeriodo = ref({ nombre: '', fecha_inicio: '', fecha_fin: '' })
const guardandoPeriodo = ref(false)

async function cargarPeriodos() {
  cargandoPeriodos.value = true
  try {
    const { data } = await getPeriodos()
    periodos.value = data
  } catch {
    toast.error('No se pudo cargar la lista de períodos')
  } finally {
    cargandoPeriodos.value = false
  }
}
onMounted(cargarPeriodos)

function abrirNuevoPeriodo() {
  formPeriodo.value = { nombre: '', fecha_inicio: '', fecha_fin: '' }
  mostrarFormPeriodo.value = true
}

async function guardarPeriodo() {
  if (!formPeriodo.value.nombre.trim() || !formPeriodo.value.fecha_inicio || !formPeriodo.value.fecha_fin) {
    toast.error('Completa el nombre y las dos fechas')
    return
  }
  guardandoPeriodo.value = true
  try {
    const { data } = await crearPeriodo({
      nombre: formPeriodo.value.nombre.trim(),
      fecha_inicio: formPeriodo.value.fecha_inicio,
      fecha_fin: formPeriodo.value.fecha_fin,
    })
    mostrarFormPeriodo.value = false
    toast.success('Período creado')
    router.push({ name: 'nomina-periodo', params: { id: data.id } })
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo crear el período')
  } finally {
    guardandoPeriodo.value = false
  }
}

async function borrarPeriodo(p) {
  if (!confirm(`¿Eliminar el período "${p.nombre}"? Esto no se puede deshacer.`)) return
  try {
    await eliminarPeriodo(p.id)
    toast.success('Período eliminado')
    await cargarPeriodos()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}

// ── Trabajadores (empleados de nómina) ────────────────────────────────────
const empleados         = ref([])
const cargandoEmpleados = ref(true)
const mostrarFormEmpleado = ref(false)
const editandoEmpleado    = ref(null)
const formEmpleado = ref({ nombre: '', cedula: '', cargo: '', valor_label: 'Salario quincenal', valor_base: 0 })
const guardandoEmpleado = ref(false)

async function cargarEmpleados() {
  cargandoEmpleados.value = true
  try {
    const { data } = await getEmpleados(true)
    empleados.value = data
  } catch {
    toast.error('No se pudo cargar la lista de trabajadores')
  } finally {
    cargandoEmpleados.value = false
  }
}

let empleadosCargados = false
watch(tab, (t) => {
  if (t === 'trabajadores' && !empleadosCargados) {
    empleadosCargados = true
    cargarEmpleados()
  }
})

function abrirNuevoEmpleado() {
  editandoEmpleado.value = null
  formEmpleado.value = { nombre: '', cedula: '', cargo: '', valor_label: 'Salario quincenal', valor_base: 0 }
  mostrarFormEmpleado.value = true
}

function abrirEditarEmpleado(e) {
  editandoEmpleado.value = e.id
  formEmpleado.value = {
    nombre: e.nombre, cedula: e.cedula ?? '', cargo: e.cargo ?? '',
    valor_label: e.valor_label, valor_base: Number(e.valor_base) || 0,
  }
  mostrarFormEmpleado.value = true
}

async function guardarEmpleado() {
  if (!formEmpleado.value.nombre.trim()) {
    toast.error('El nombre es obligatorio')
    return
  }
  guardandoEmpleado.value = true
  try {
    const payload = {
      nombre: formEmpleado.value.nombre.trim(),
      cedula: formEmpleado.value.cedula.trim() || null,
      cargo: formEmpleado.value.cargo.trim() || null,
      valor_label: formEmpleado.value.valor_label.trim() || 'Salario quincenal',
      valor_base: formEmpleado.value.valor_base,
    }
    if (editandoEmpleado.value) {
      await actualizarEmpleado(editandoEmpleado.value, payload)
      toast.success('Trabajador actualizado')
    } else {
      await crearEmpleado(payload)
      toast.success('Trabajador creado')
    }
    mostrarFormEmpleado.value = false
    await cargarEmpleados()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoEmpleado.value = false
  }
}

async function borrarEmpleado(e) {
  if (!confirm(`¿Eliminar a "${e.nombre}"? Si ya tiene nómina registrada, se desactiva en vez de borrarse.`)) return
  try {
    const { data } = await eliminarEmpleado(e.id)
    toast.success(data?.message ?? 'Trabajador eliminado')
    await cargarEmpleados()
  } catch (err) {
    toast.error(err.response?.data?.message || 'No se pudo eliminar')
  }
}

async function reactivarEmpleado(e) {
  try {
    await actualizarEmpleado(e.id, { activo: true })
    toast.success('Trabajador reactivado')
    await cargarEmpleados()
  } catch (err) {
    toast.error(err.response?.data?.message || 'No se pudo reactivar')
  }
}

const empleadosActivos   = computed(() => empleados.value.filter(e => e.activo))
const empleadosInactivos = computed(() => empleados.value.filter(e => !e.activo))
const sinValor = computed(() => empleadosActivos.value.filter(e => Number(e.valor_base) <= 0).length)
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center gap-3 mb-4">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h1 class="text-lg font-bold text-gray-800 flex items-center gap-2 flex-1">
        <BanknotesIcon class="w-5 h-5 text-blue-600" />
        Nómina
      </h1>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-4 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'periodos'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'periodos' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Períodos
      </button>
      <button
        @click="tab = 'trabajadores'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'trabajadores' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Trabajadores
      </button>
    </div>

    <!-- ═══════════ PERÍODOS ═══════════ -->
    <template v-if="tab === 'periodos'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">Cada quincena es un período: se arma solo con los trabajadores activos.</p>
        <button
          @click="abrirNuevoPeriodo"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Nuevo
        </button>
      </div>

      <div v-if="cargandoPeriodos" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else-if="!periodos.length" class="text-center py-12 text-gray-400 text-sm">
        Todavía no hay períodos de nómina.
      </div>

      <div v-else class="space-y-2.5">
        <div
          v-for="p in periodos" :key="p.id"
          @click="router.push({ name: 'nomina-periodo', params: { id: p.id } })"
          class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:bg-blue-50/40 transition-colors"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate flex items-center gap-1.5">
                {{ p.nombre }}
                <CheckCircleIcon v-if="p.pagado_at" class="w-4 h-4 text-green-500 shrink-0" />
              </p>
              <p class="text-xs text-gray-500 mt-0.5">{{ p.fecha_inicio }} a {{ p.fecha_fin }} · {{ p.dias_periodo }} días · {{ p.num_empleados }} trabajadores</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <p class="font-bold text-sm text-gray-800">{{ formatoPesos(p.total_general) }}</p>
              <button v-if="!p.pagado_at" @click.stop="borrarPeriodo(p)" class="p-1 text-gray-300 hover:text-red-600 transition-colors" aria-label="Eliminar">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
          <p class="text-[11px] font-semibold mt-1.5" :class="p.pagado_at ? 'text-green-600' : 'text-amber-600'">
            {{ p.pagado_at ? 'Pagado' : 'Borrador' }}
          </p>
        </div>
      </div>

      <!-- Nuevo período -->
      <Teleport to="body">
        <Transition
          enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
          <div v-if="mostrarFormPeriodo" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormPeriodo = false">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
              <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                    <CalendarDaysIcon class="w-5 h-5 text-blue-600" />
                  </div>
                  <p class="font-semibold text-gray-800">Nuevo período</p>
                </div>
                <button @click="mostrarFormPeriodo = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>
              <div class="p-5 space-y-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre *</label>
                  <input v-model="formPeriodo.nombre" placeholder="16 al 31 de agosto de 2026" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Desde *</label>
                    <input v-model="formPeriodo.fecha_inicio" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Hasta *</label>
                    <input v-model="formPeriodo.fecha_fin" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>
                </div>
                <p class="text-[11px] text-gray-400">Se crea con todos los trabajadores activos ya cargados, listos para ajustar.</p>
              </div>
              <div class="flex gap-2.5 p-5 pt-2">
                <button @click="mostrarFormPeriodo = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
                <button
                  @click="guardarPeriodo" :disabled="guardandoPeriodo"
                  class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
                >
                  <span v-if="guardandoPeriodo" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                  {{ guardandoPeriodo ? 'Creando...' : 'Crear' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>

    <!-- ═══════════ TRABAJADORES ═══════════ -->
    <template v-else-if="tab === 'trabajadores'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">El roster de nómina — no necesitan cuenta en la app.</p>
        <button
          @click="abrirNuevoEmpleado"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Nuevo
        </button>
      </div>

      <p v-if="sinValor > 0" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
        {{ sinValor }} trabajador(es) todavía no tienen un valor de pago cargado.
      </p>

      <div v-if="cargandoEmpleados" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else class="space-y-2.5">
        <div v-for="e in empleadosActivos" :key="e.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">{{ e.nombre }}</p>
              <p class="text-xs text-gray-500 mt-0.5">{{ e.cargo || 'Sin cargo' }} <span v-if="e.cedula">· CC {{ e.cedula }}</span></p>
              <p class="text-xs mt-0.5" :class="Number(e.valor_base) > 0 ? 'text-gray-600' : 'text-amber-600'">
                {{ e.valor_label }}: {{ formatoPesos(e.valor_base) }}
              </p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click="abrirEditarEmpleado(e)" class="p-1.5 text-gray-300 hover:text-blue-600 transition-colors" aria-label="Editar">
                <PencilSquareIcon class="w-4 h-4" />
              </button>
              <button @click="borrarEmpleado(e)" class="p-1.5 text-gray-300 hover:text-red-600 transition-colors" aria-label="Eliminar">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <template v-if="empleadosInactivos.length">
          <p class="text-xs font-semibold text-gray-400 uppercase pt-2">Desactivados</p>
          <div v-for="e in empleadosInactivos" :key="e.id" class="bg-gray-50 rounded-xl p-4 flex items-center justify-between gap-2">
            <p class="text-sm text-gray-500 truncate">{{ e.nombre }}</p>
            <button @click="reactivarEmpleado(e)" class="text-xs font-semibold text-blue-600 hover:text-blue-700 shrink-0">Reactivar</button>
          </div>
        </template>
      </div>

      <!-- Nuevo / editar trabajador -->
      <Teleport to="body">
        <Transition
          enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
          <div v-if="mostrarFormEmpleado" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormEmpleado = false">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
              <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm rounded-t-3xl sm:rounded-t-2xl">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                    <UsersIcon class="w-5 h-5 text-blue-600" />
                  </div>
                  <p class="font-semibold text-gray-800">{{ editandoEmpleado ? 'Editar trabajador' : 'Nuevo trabajador' }}</p>
                </div>
                <button @click="mostrarFormEmpleado = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>
              <div class="p-5 space-y-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre *</label>
                  <input v-model="formEmpleado.nombre" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Cédula</label>
                    <input v-model="formEmpleado.cedula" inputmode="numeric" placeholder="Opcional" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Cargo</label>
                    <input v-model="formEmpleado.cargo" placeholder="Lijador, Ebanista..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre del valor a pagar</label>
                  <input v-model="formEmpleado.valor_label" placeholder="Salario quincenal, Jornal diario..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  <p class="text-[11px] text-gray-400 mt-1">Le pones el nombre que quieras — no tiene que decir "el mínimo".</p>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Valor</label>
                  <InputPesos v-model="formEmpleado.valor_base" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  <p class="text-[11px] text-gray-400 mt-1">Es el valor completo del período; si falta o entra tarde, se prorratea solo en cada quincena.</p>
                </div>
              </div>
              <div class="flex gap-2.5 p-5 pt-2">
                <button @click="mostrarFormEmpleado = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
                <button
                  @click="guardarEmpleado" :disabled="guardandoEmpleado"
                  class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
                >
                  <span v-if="guardandoEmpleado" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                  {{ guardandoEmpleado ? 'Guardando...' : 'Guardar' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>
  </div>
</template>
