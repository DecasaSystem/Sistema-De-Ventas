<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { getEmpleados, crearEmpleado, actualizarEmpleado, eliminarEmpleado } from '@/api/empleados'
import {
  getPeriodos, crearPeriodo, eliminarPeriodo,
  getSueldos, crearSueldo, actualizarSueldo, eliminarSueldo,
  crearAusencia,
} from '@/api/nomina'
import {
  BanknotesIcon,
  PlusIcon,
  PencilSquareIcon,
  TrashIcon,
  XMarkIcon,
  UsersIcon,
  CalendarDaysIcon,
  CheckCircleIcon,
  TagIcon,
  CalendarIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast  = useToast()

const tab = ref('periodos')

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}

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

// ── Sueldos (catálogo con nombre) ─────────────────────────────────────────
const sueldos         = ref([])
const cargandoSueldos = ref(true)
const mostrarFormSueldo = ref(false)
const editandoSueldo    = ref(null)
const formSueldo = ref({ nombre: '', valor: 0, unidad: 'dia', horas_dia: 8 })
const guardandoSueldo = ref(false)

async function cargarSueldos() {
  cargandoSueldos.value = true
  try {
    const { data } = await getSueldos(true)
    sueldos.value = data
  } catch {
    toast.error('No se pudo cargar la lista de sueldos')
  } finally {
    cargandoSueldos.value = false
  }
}

function abrirNuevoSueldo() {
  editandoSueldo.value = null
  formSueldo.value = { nombre: '', valor: 0, unidad: 'dia', horas_dia: 8 }
  mostrarFormSueldo.value = true
}

function abrirEditarSueldo(s) {
  editandoSueldo.value = s.id
  formSueldo.value = {
    nombre: s.nombre,
    valor: Number(s.valor) || 0,
    unidad: s.unidad || 'dia',
    horas_dia: Number(s.horas_dia) || 8,
  }
  mostrarFormSueldo.value = true
}

async function guardarSueldo() {
  if (!formSueldo.value.nombre.trim()) {
    toast.error('Ponle un nombre al sueldo')
    return
  }
  guardandoSueldo.value = true
  try {
    const payload = {
      nombre: formSueldo.value.nombre.trim(),
      valor: formSueldo.value.valor,
      unidad: formSueldo.value.unidad,
      horas_dia: formSueldo.value.horas_dia,
    }
    if (editandoSueldo.value) {
      await actualizarSueldo(editandoSueldo.value, payload)
      toast.success('Sueldo actualizado')
    } else {
      await crearSueldo(payload)
      toast.success('Sueldo creado')
    }
    mostrarFormSueldo.value = false
    await cargarSueldos()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoSueldo.value = false
  }
}

async function borrarSueldo(s) {
  if (!confirm(`¿Eliminar el sueldo "${s.nombre}"? Si algún trabajador lo tiene asignado, se desactiva en vez de borrarse.`)) return
  try {
    const { data } = await eliminarSueldo(s.id)
    toast.success(data?.message ?? 'Sueldo eliminado')
    await cargarSueldos()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

async function reactivarSueldo(s) {
  try {
    await actualizarSueldo(s.id, { activo: true })
    toast.success('Sueldo reactivado')
    await cargarSueldos()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo reactivar')
  }
}

const sueldosActivos   = computed(() => sueldos.value.filter(s => s.activo))
const sueldosInactivos = computed(() => sueldos.value.filter(s => !s.activo))

// ── Trabajadores (empleados de nómina) ────────────────────────────────────
const empleados         = ref([])
const cargandoEmpleados = ref(true)
const mostrarFormEmpleado = ref(false)
const editandoEmpleado    = ref(null)
const CUSTOM = 'personalizado'
const formEmpleado = ref({
  nombre: '', cedula: '', cargo: '', fuente: CUSTOM, nomina_sueldo_id: '',
  valor_label: 'Personalizado', valor: 0, unidad: 'dia', horas_dia: 8,
})
const guardandoEmpleado = ref(false)

// ── Registrar falta ────────────────────────────────────────────────────
const mostrarFormFalta = ref(false)
const empleadoFalta     = ref(null)
const formFalta = ref({ fecha_inicio: '', fecha_fin: '', horas: 8, motivo: '' })
const guardandoFalta = ref(false)

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
let sueldosCargados   = false
watch(tab, (t) => {
  if (t === 'trabajadores' && !empleadosCargados) {
    empleadosCargados = true
    cargarEmpleados()
  }
  if ((t === 'sueldos' || t === 'trabajadores') && !sueldosCargados) {
    sueldosCargados = true
    cargarSueldos()
  }
})

function abrirNuevoEmpleado() {
  editandoEmpleado.value = null
  formEmpleado.value = {
    nombre: '', cedula: '', cargo: '', fuente: CUSTOM, nomina_sueldo_id: '',
    valor_label: 'Personalizado', valor: 0, unidad: 'dia', horas_dia: 8,
  }
  mostrarFormEmpleado.value = true
}

function abrirEditarEmpleado(e) {
  editandoEmpleado.value = e.id
  formEmpleado.value = {
    nombre: e.nombre, cedula: e.cedula ?? '', cargo: e.cargo ?? '',
    fuente: e.nomina_sueldo_id ? e.nomina_sueldo_id : CUSTOM,
    nomina_sueldo_id: e.nomina_sueldo_id ?? '',
    valor_label: e.valor_label ?? 'Personalizado',
    valor: Number(e.valor) || 0,
    unidad: e.unidad || 'dia',
    horas_dia: Number(e.horas_dia) || 8,
  }
  mostrarFormEmpleado.value = true
}

async function guardarEmpleado() {
  if (!formEmpleado.value.nombre.trim()) {
    toast.error('El nombre es obligatorio')
    return
  }
  const esPersonalizado = formEmpleado.value.fuente === CUSTOM
  if (!esPersonalizado && !formEmpleado.value.fuente) {
    toast.error('Elige un sueldo o marca "Personalizado"')
    return
  }
  guardandoEmpleado.value = true
  try {
    const payload = {
      nombre: formEmpleado.value.nombre.trim(),
      cedula: formEmpleado.value.cedula.trim() || null,
      cargo: formEmpleado.value.cargo.trim() || null,
      nomina_sueldo_id: esPersonalizado ? null : formEmpleado.value.fuente,
      valor_label: esPersonalizado ? (formEmpleado.value.valor_label.trim() || 'Personalizado') : null,
      valor: esPersonalizado ? formEmpleado.value.valor : null,
      unidad: esPersonalizado ? formEmpleado.value.unidad : null,
      horas_dia: esPersonalizado ? formEmpleado.value.horas_dia : null,
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
const sinValor = computed(() => empleadosActivos.value.filter(e => Number(e.valor_dia_efectivo) <= 0).length)

// Vista previa en vivo de quincena/mes mientras se escribe el valor día —
// puro cálculo en cliente, no pide nada al backend.
function quincena(valorDia) { return (Number(valorDia) || 0) * 15 }
function mes(valorDia)      { return (Number(valorDia) || 0) * 30 }

// Un sueldo/valor personalizado puede cargarse por día o por hora — no hay
// una jornada estándar, así que las horas por día se escriben ahí mismo.
function valorDiaEquivalente(valor, unidad, horasDia) {
  return unidad === 'hora' ? (Number(valor) || 0) * (Number(horasDia) || 0) : (Number(valor) || 0)
}

const sueldoElegidoEmpleado = computed(() =>
  sueldosActivos.value.find(s => s.id === formEmpleado.value.fuente) ?? null
)

// ── Registrar falta ────────────────────────────────────────────────────
function abrirFalta(e) {
  empleadoFalta.value = e
  formFalta.value = {
    fecha_inicio: new Date().toISOString().slice(0, 10),
    fecha_fin: '',
    horas: Number(e.horas_dia_efectivo) || 8,
    motivo: '',
  }
  mostrarFormFalta.value = true
}

async function guardarFalta() {
  if (!formFalta.value.fecha_inicio) {
    toast.error('Elige la fecha')
    return
  }
  guardandoFalta.value = true
  try {
    const { data } = await crearAusencia({
      empleado_id: empleadoFalta.value.id,
      fecha_inicio: formFalta.value.fecha_inicio,
      fecha_fin: formFalta.value.fecha_fin || null,
      horas: formFalta.value.horas,
      motivo: formFalta.value.motivo.trim() || null,
    })
    mostrarFormFalta.value = false
    if (data.no_aplicadas?.length) {
      toast.error(`${data.no_aplicadas.length} fecha(s) caen en un período ya pagado y no se aplicaron`)
    } else {
      toast.success(data.guardadas.length > 1 ? `${data.guardadas.length} faltas registradas` : 'Falta registrada')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar la falta')
  } finally {
    guardandoFalta.value = false
  }
}
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
      <button
        @click="tab = 'sueldos'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'sueldos' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Sueldos
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
              <p class="text-xs mt-0.5" :class="Number(e.valor_dia_efectivo) > 0 ? 'text-gray-600' : 'text-amber-600'">
                {{ e.label_efectivo }}: {{ formatoPesos(e.valor_dia_efectivo) }}/día · {{ formatoPesos(e.valor_hora_efectivo) }}/hora
                <span class="text-gray-400">· {{ formatoPesos(quincena(e.valor_dia_efectivo)) }} quincena · {{ formatoPesos(mes(e.valor_dia_efectivo)) }} mes</span>
              </p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click="abrirFalta(e)" class="p-1.5 text-gray-300 hover:text-amber-600 transition-colors" aria-label="Registrar falta">
                <CalendarIcon class="w-4 h-4" />
              </button>
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
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Sueldo</label>
                  <select v-model="formEmpleado.fuente" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                    <option v-for="s in sueldosActivos" :key="s.id" :value="s.id">{{ s.nombre }} — {{ formatoPesos(s.valor) }}/{{ s.unidad === 'hora' ? 'hora' : 'día' }}</option>
                    <option :value="CUSTOM">Personalizado (escribir un valor propio)</option>
                  </select>
                </div>

                <!-- Del catálogo: solo mostrar, no se edita aquí -->
                <div v-if="sueldoElegidoEmpleado" class="bg-blue-50 border border-blue-100 rounded-xl px-3.5 py-2.5 text-xs text-blue-700">
                  {{ formatoPesos(sueldoElegidoEmpleado.valor) }}/{{ sueldoElegidoEmpleado.unidad === 'hora' ? 'hora' : 'día' }}
                  <span v-if="sueldoElegidoEmpleado.unidad === 'hora'">({{ sueldoElegidoEmpleado.horas_dia }}h/día)</span> ·
                  {{ formatoPesos(quincena(valorDiaEquivalente(sueldoElegidoEmpleado.valor, sueldoElegidoEmpleado.unidad, sueldoElegidoEmpleado.horas_dia))) }} quincena ·
                  {{ formatoPesos(mes(valorDiaEquivalente(sueldoElegidoEmpleado.valor, sueldoElegidoEmpleado.unidad, sueldoElegidoEmpleado.horas_dia))) }} mes
                </div>

                <!-- Personalizado: nombre + valor propios -->
                <template v-else>
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre del valor</label>
                    <input v-model="formEmpleado.valor_label" placeholder="Comisión especial, Jornal..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>

                  <div class="flex gap-2 bg-gray-100 rounded-xl p-1">
                    <button
                      type="button" @click="formEmpleado.unidad = 'dia'"
                      :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', formEmpleado.unidad === 'dia' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
                    >Por día</button>
                    <button
                      type="button" @click="formEmpleado.unidad = 'hora'"
                      :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', formEmpleado.unidad === 'hora' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
                    >Por hora</button>
                  </div>

                  <div class="grid gap-3" :class="formEmpleado.unidad === 'hora' ? 'grid-cols-2' : 'grid-cols-1'">
                    <div>
                      <label class="block text-xs font-semibold text-gray-500 mb-1.5">Valor por {{ formEmpleado.unidad === 'hora' ? 'hora' : 'día' }}</label>
                      <InputPesos v-model="formEmpleado.valor" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                    </div>
                    <div v-if="formEmpleado.unidad === 'hora'">
                      <label class="block text-xs font-semibold text-gray-500 mb-1.5">Horas por día</label>
                      <input
                        v-model.number="formEmpleado.horas_dia" type="number" step="0.5" min="0.5" max="24"
                        class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                      />
                    </div>
                  </div>
                  <p class="text-[11px] text-gray-400 -mt-2">
                    = {{ formatoPesos(quincena(valorDiaEquivalente(formEmpleado.valor, formEmpleado.unidad, formEmpleado.horas_dia))) }} quincena ·
                    {{ formatoPesos(mes(valorDiaEquivalente(formEmpleado.valor, formEmpleado.unidad, formEmpleado.horas_dia))) }} mes
                    <span v-if="formEmpleado.unidad === 'hora'">· jornada completa = {{ formatoPesos(valorDiaEquivalente(formEmpleado.valor, formEmpleado.unidad, formEmpleado.horas_dia)) }}/día</span>
                  </p>
                </template>

                <p class="text-[11px] text-gray-400">No hay una jornada estándar: cada trabajador tiene sus propias horas por día. Las horas por hora se usan también para descontar faltas parciales.</p>
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

    <!-- ═══════════ SUELDOS ═══════════ -->
    <template v-else-if="tab === 'sueldos'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">Sueldos con nombre para asignar rápido al crear un trabajador.</p>
        <button
          @click="abrirNuevoSueldo"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Nuevo
        </button>
      </div>

      <div v-if="cargandoSueldos" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else class="space-y-2.5">
        <div v-for="s in sueldosActivos" :key="s.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex items-center gap-2.5">
              <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                <TagIcon class="w-5 h-5 text-blue-600" />
              </div>
              <div class="min-w-0">
                <p class="font-semibold text-sm text-gray-800 truncate">{{ s.nombre }}</p>
                <p class="text-xs text-gray-500 mt-0.5">
                  {{ formatoPesos(s.valor) }}/{{ s.unidad === 'hora' ? 'hora' : 'día' }}
                  <span v-if="s.unidad === 'hora'">({{ s.horas_dia }}h/día)</span> ·
                  {{ formatoPesos(quincena(valorDiaEquivalente(s.valor, s.unidad, s.horas_dia))) }} quincena ·
                  {{ formatoPesos(mes(valorDiaEquivalente(s.valor, s.unidad, s.horas_dia))) }} mes
                </p>
              </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click="abrirEditarSueldo(s)" class="p-1.5 text-gray-300 hover:text-blue-600 transition-colors" aria-label="Editar">
                <PencilSquareIcon class="w-4 h-4" />
              </button>
              <button @click="borrarSueldo(s)" class="p-1.5 text-gray-300 hover:text-red-600 transition-colors" aria-label="Eliminar">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <p v-if="!sueldosActivos.length" class="text-center py-8 text-gray-400 text-sm">Todavía no hay sueldos creados.</p>

        <template v-if="sueldosInactivos.length">
          <p class="text-xs font-semibold text-gray-400 uppercase pt-2">Desactivados</p>
          <div v-for="s in sueldosInactivos" :key="s.id" class="bg-gray-50 rounded-xl p-4 flex items-center justify-between gap-2">
            <p class="text-sm text-gray-500 truncate">{{ s.nombre }}</p>
            <button @click="reactivarSueldo(s)" class="text-xs font-semibold text-blue-600 hover:text-blue-700 shrink-0">Reactivar</button>
          </div>
        </template>
      </div>

      <!-- Nuevo / editar sueldo -->
      <Teleport to="body">
        <Transition
          enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
          <div v-if="mostrarFormSueldo" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormSueldo = false">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
              <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                    <TagIcon class="w-5 h-5 text-blue-600" />
                  </div>
                  <p class="font-semibold text-gray-800">{{ editandoSueldo ? 'Editar sueldo' : 'Nuevo sueldo' }}</p>
                </div>
                <button @click="mostrarFormSueldo = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>
              <div class="p-5 space-y-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre *</label>
                  <input v-model="formSueldo.nombre" placeholder="Mínimo" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>

                <div class="flex gap-2 bg-gray-100 rounded-xl p-1">
                  <button
                    type="button" @click="formSueldo.unidad = 'dia'"
                    :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', formSueldo.unidad === 'dia' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
                  >Por día</button>
                  <button
                    type="button" @click="formSueldo.unidad = 'hora'"
                    :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', formSueldo.unidad === 'hora' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
                  >Por hora</button>
                </div>

                <div class="grid gap-3" :class="formSueldo.unidad === 'hora' ? 'grid-cols-2' : 'grid-cols-1'">
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Valor por {{ formSueldo.unidad === 'hora' ? 'hora' : 'día' }}</label>
                    <InputPesos v-model="formSueldo.valor" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>
                  <div v-if="formSueldo.unidad === 'hora'">
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Horas por día</label>
                    <input
                      v-model.number="formSueldo.horas_dia" type="number" step="0.5" min="0.5" max="24"
                      class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                    />
                  </div>
                </div>
                <p class="text-[11px] text-gray-400 -mt-2">
                  = {{ formatoPesos(quincena(valorDiaEquivalente(formSueldo.valor, formSueldo.unidad, formSueldo.horas_dia))) }} quincena ·
                  {{ formatoPesos(mes(valorDiaEquivalente(formSueldo.valor, formSueldo.unidad, formSueldo.horas_dia))) }} mes
                  <span v-if="formSueldo.unidad === 'hora'">· jornada completa = {{ formatoPesos(valorDiaEquivalente(formSueldo.valor, formSueldo.unidad, formSueldo.horas_dia)) }}/día</span>
                </p>
                <p class="text-[11px] text-gray-400">Editar el valor solo afecta a los períodos que se creen de ahora en adelante — los ya hechos quedan con lo que tenían.</p>
              </div>
              <div class="flex gap-2.5 p-5 pt-2">
                <button @click="mostrarFormSueldo = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
                <button
                  @click="guardarSueldo" :disabled="guardandoSueldo"
                  class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
                >
                  <span v-if="guardandoSueldo" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                  {{ guardandoSueldo ? 'Guardando...' : 'Guardar' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>

    <!-- Registrar falta -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarFormFalta" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormFalta = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
              <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center shrink-0">
                  <CalendarIcon class="w-5 h-5 text-amber-600" />
                </div>
                <p class="font-semibold text-gray-800">Registrar falta{{ empleadoFalta ? ' — ' + empleadoFalta.nombre : '' }}</p>
              </div>
              <button @click="mostrarFormFalta = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="p-5 space-y-4">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Desde *</label>
                  <input v-model="formFalta.fecha_inicio" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Hasta</label>
                  <input v-model="formFalta.fecha_fin" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
              </div>
              <p class="text-[11px] text-gray-400 -mt-2">Deja "Hasta" vacío si es un solo día. Si el rango cruza varias quincenas, cada fecha se descuenta en la suya.</p>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Horas que faltó (cada día del rango)</label>
                <input
                  v-model.number="formFalta.horas" type="number" step="0.5" min="0.5" max="24"
                  class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                />
                <p class="text-[11px] text-gray-400 mt-1">Prellenado con su jornada completa ({{ empleadoFalta?.horas_dia_efectivo }}h) — bájalo si solo faltó unas horas.</p>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Motivo</label>
                <input v-model="formFalta.motivo" placeholder="Cita médica, permiso..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
              </div>
              <p v-if="empleadoFalta" class="text-[11px] text-gray-400">
                Se descuentan {{ formatoPesos((Number(formFalta.horas) || 0) * (Number(empleadoFalta.valor_hora_efectivo) || 0)) }} por día de este rango.
              </p>
            </div>
            <div class="flex gap-2.5 p-5 pt-2">
              <button @click="mostrarFormFalta = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="guardarFalta" :disabled="guardandoFalta"
                class="flex-1 bg-amber-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-amber-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <span v-if="guardandoFalta" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                {{ guardandoFalta ? 'Guardando...' : 'Registrar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
