<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { getEmpleados, crearEmpleado, actualizarEmpleado, eliminarEmpleado } from '@/api/empleados'
import {
  getSueldos, crearSueldo, actualizarSueldo, eliminarSueldo,
  getPagosPendientes, pagar, pagarLote, getHistorialPagos, deshacerPago,
  crearAusencia, getAusencias, eliminarAusencia,
  getAjustes, crearAjuste, eliminarAjuste,
} from '@/api/nomina'
import {
  BanknotesIcon, PlusIcon, PencilSquareIcon, TrashIcon, XMarkIcon,
  UsersIcon, CheckCircleIcon, TagIcon, CalendarIcon, MagnifyingGlassIcon,
  ChevronDownIcon, ChevronUpIcon, ClockIcon, ArrowUturnLeftIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast  = useToast()

const tab = ref('pagos')

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}
function formatoFecha(fecha) {
  if (!fecha) return ''
  return new Date(fecha + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' })
}
function formatoFechaCorta(fecha) {
  if (!fecha) return ''
  return new Date(fecha + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}
function formatoFechaHora(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('es-CO', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function hoyISO() {
  // Fecha local, no toISOString(): en Colombia (UTC-5) el UTC ya va en el
  // día siguiente desde las 7 de la noche.
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const PERIODICIDADES = [
  { value: 'diario', label: 'Diario' },
  { value: 'semanal', label: 'Semanal' },
  { value: 'quincenal', label: 'Quincenal' },
  { value: '20_dias', label: 'Cada 20 días' },
  { value: 'mensual', label: 'Mensual' },
]

// ── Pagos pendientes ──────────────────────────────────────────────────────
const pendientes     = ref([])
const totalGeneral   = ref(0)
const sinSueldo      = ref(0)
const cargandoPagos  = ref(true)
const pagandoClave   = ref(null)
const pagandoTodos   = ref(false)
const pagoAbierto    = ref(null)

const clave = (p) => `${p.empleado_id}|${p.fecha_inicio}`

async function cargarPendientes() {
  cargandoPagos.value = true
  try {
    const { data } = await getPagosPendientes()
    pendientes.value   = data.pendientes
    totalGeneral.value = data.total_general
    sinSueldo.value    = data.sin_sueldo
  } catch {
    toast.error('No se pudo cargar lo que hay por pagar')
  } finally {
    cargandoPagos.value = false
  }
}
onMounted(cargarPendientes)

// Se agrupa por frecuencia para que la quincena no se mezcle con la semana.
const gruposPagos = computed(() => {
  const grupos = new Map()
  for (const p of pendientes.value) {
    if (!grupos.has(p.periodicidad_label)) grupos.set(p.periodicidad_label, [])
    grupos.get(p.periodicidad_label).push(p)
  }
  return [...grupos.entries()].map(([label, items]) => ({
    label,
    items,
    total: items.reduce((s, i) => s + i.total, 0),
  }))
})

async function pagarUno(p) {
  pagandoClave.value = clave(p)
  try {
    await pagar(p.empleado_id, p.fecha_inicio)
    toast.success(`${p.empleado_nombre}: ${formatoPesos(p.total)} pagado`)
    empleadosCargados = false
    await cargarPendientes()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar el pago')
  } finally {
    pagandoClave.value = null
  }
}

async function pagarTodos() {
  const n = pendientes.value.length
  if (!confirm(`¿Marcar como pagados los ${n} pagos pendientes, por ${formatoPesos(totalGeneral.value)}?`)) return
  pagandoTodos.value = true
  try {
    // Se manda la lista exacta que está en pantalla: nunca se cobra algo
    // que el usuario no llegó a ver.
    const { data } = await pagarLote(
      pendientes.value.map(p => ({ empleado_id: p.empleado_id, fecha_inicio: p.fecha_inicio }))
    )
    if (data.omitidos?.length) {
      toast.error(`${data.pagados.length} pagados, ${data.omitidos.length} omitidos (${data.omitidos[0].motivo})`)
    } else {
      toast.success(`${data.pagados.length} pagos registrados por ${formatoPesos(data.total)}`)
    }
    empleadosCargados = false
    await cargarPendientes()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudieron registrar los pagos')
  } finally {
    pagandoTodos.value = false
  }
}

// ── Historial de pagos ────────────────────────────────────────────────────
const mostrarHistorial   = ref(false)
const historial          = ref([])
const cargandoHistorial2 = ref(false)

async function abrirHistorial() {
  mostrarHistorial.value = true
  cargandoHistorial2.value = true
  try {
    const { data } = await getHistorialPagos({ limite: 100 })
    historial.value = data
  } catch {
    toast.error('No se pudo cargar el historial')
  } finally {
    cargandoHistorial2.value = false
  }
}

async function deshacer(p) {
  if (!confirm(`¿Deshacer el pago de ${p.empleado_nombre} (${p.nombre}) por ${formatoPesos(p.total)}?\n\nEl ciclo vuelve a quedar pendiente con sus faltas.`)) return
  try {
    await deshacerPago(p.id)
    toast.success('Pago deshecho')
    empleadosCargados = false
    await Promise.all([abrirHistorial(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo deshacer')
  }
}

// ── Sueldos (catálogo con nombre) ─────────────────────────────────────────
const sueldos           = ref([])
const cargandoSueldos   = ref(true)
const mostrarFormSueldo = ref(false)
const editandoSueldo    = ref(null)
const formSueldo        = ref({ nombre: '', valor: 0, unidad: 'dia', horas_dia: 8 })
const guardandoSueldo   = ref(false)

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
    empleadosCargados = false
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

// Vista previa de lo que da un sueldo en cada frecuencia. Son los mismos
// días que usa el backend para liquidar (CicloNomina::diasPagados).
const DIAS_POR_FRECUENCIA = { diario: 1, semanal: 7, quincenal: 15, '20_dias': 20, mensual: 30 }
function valorDiaEquivalente(valor, unidad, horasDia) {
  return unidad === 'hora' ? (Number(valor) || 0) * (Number(horasDia) || 0) : (Number(valor) || 0)
}
function porFrecuencia(valorDia, frecuencia) {
  return (Number(valorDia) || 0) * (DIAS_POR_FRECUENCIA[frecuencia] ?? 15)
}

// ── Trabajadores ──────────────────────────────────────────────────────────
const empleados           = ref([])
const cargandoEmpleados   = ref(true)
const mostrarFormEmpleado = ref(false)
const editandoEmpleado    = ref(null)
const formEmpleado        = ref({ nombre: '', cedula: '', cargo: '', nomina_sueldo_id: '', periodicidad: 'quincenal' })
const guardandoEmpleado   = ref(false)

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
  // Solo se manda a Sueldos si de verdad no hay ninguno — no mientras la
  // lista todavía viene en camino.
  if (!cargandoSueldos.value && !sueldosActivos.value.length) {
    toast.error('Primero crea un sueldo en la pestaña Sueldos')
    tab.value = 'sueldos'
    return
  }
  editandoEmpleado.value = null
  formEmpleado.value = { nombre: '', cedula: '', cargo: '', nomina_sueldo_id: '', periodicidad: 'quincenal' }
  mostrarFormEmpleado.value = true
}

function abrirEditarEmpleado(e) {
  editandoEmpleado.value = e.id
  formEmpleado.value = {
    nombre: e.nombre,
    cedula: e.cedula ?? '',
    cargo: e.cargo ?? '',
    nomina_sueldo_id: e.nomina_sueldo_id ?? '',
    periodicidad: e.periodicidad || 'quincenal',
  }
  mostrarFormEmpleado.value = true
}

async function guardarEmpleado() {
  if (!formEmpleado.value.nombre.trim()) {
    toast.error('El nombre es obligatorio')
    return
  }
  if (!formEmpleado.value.nomina_sueldo_id) {
    toast.error('Elige el sueldo de este trabajador')
    return
  }
  guardandoEmpleado.value = true
  try {
    const payload = {
      nombre: formEmpleado.value.nombre.trim(),
      cedula: formEmpleado.value.cedula.trim() || null,
      cargo: formEmpleado.value.cargo.trim() || null,
      nomina_sueldo_id: formEmpleado.value.nomina_sueldo_id,
      periodicidad: formEmpleado.value.periodicidad,
    }
    if (editandoEmpleado.value) {
      await actualizarEmpleado(editandoEmpleado.value, payload)
      toast.success('Trabajador actualizado')
    } else {
      await crearEmpleado(payload)
      toast.success('Trabajador creado')
    }
    mostrarFormEmpleado.value = false
    await Promise.all([cargarEmpleados(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoEmpleado.value = false
  }
}

async function borrarEmpleado(e) {
  if (!confirm(`¿Eliminar a "${e.nombre}"? Si ya tiene pagos registrados, se desactiva en vez de borrarse.`)) return
  try {
    const { data } = await eliminarEmpleado(e.id)
    toast.success(data?.message ?? 'Trabajador eliminado')
    await Promise.all([cargarEmpleados(), cargarPendientes()])
  } catch (err) {
    toast.error(err.response?.data?.message || 'No se pudo eliminar')
  }
}

async function reactivarEmpleado(e) {
  try {
    await actualizarEmpleado(e.id, { activo: true })
    toast.success('Trabajador reactivado')
    await Promise.all([cargarEmpleados(), cargarPendientes()])
  } catch (err) {
    toast.error(err.response?.data?.message || 'No se pudo reactivar')
  }
}

const busquedaEmpleado = ref('')
function coincideBusqueda(e) {
  const q = busquedaEmpleado.value.trim().toLowerCase()
  if (!q) return true
  return (e.nombre ?? '').toLowerCase().includes(q)
    || (e.cargo ?? '').toLowerCase().includes(q)
    || (e.cedula ?? '').toLowerCase().includes(q)
}

const empleadosActivos   = computed(() => empleados.value.filter(e => e.activo && coincideBusqueda(e)))
const empleadosInactivos = computed(() => empleados.value.filter(e => !e.activo && coincideBusqueda(e)))
const empleadosSinSueldo = computed(() => empleados.value.filter(e => e.activo && !e.nomina_sueldo_id).length)

const sueldoElegido = computed(() =>
  sueldosActivos.value.find(s => s.id === formEmpleado.value.nomina_sueldo_id) ?? null
)

// ── Novedades del trabajador: faltas y ajustes ────────────────────────────
const mostrarNovedades  = ref(false)
const empleadoNovedades = ref(null)
const novedadTab        = ref('falta')
const formFalta         = ref({ fecha_inicio: '', fecha_fin: '', horas: 8, motivo: '' })
const formAjuste        = ref({ fecha: '', nombre: '', monto: 0, signo: 1 })
const guardandoNovedad  = ref(false)
const historialFaltas   = ref([])
const historialAjustes  = ref([])
const cargandoHistorial = ref(false)

async function abrirNovedades(e) {
  empleadoNovedades.value = e
  novedadTab.value = 'falta'
  resetFormsNovedad()
  mostrarNovedades.value = true
  await cargarNovedades()
}

function resetFormsNovedad() {
  const e = empleadoNovedades.value
  formFalta.value  = { fecha_inicio: hoyISO(), fecha_fin: '', horas: Number(e?.horas_dia_efectivo) || 8, motivo: '' }
  formAjuste.value = { fecha: hoyISO(), nombre: '', monto: 0, signo: 1 }
}

async function cargarNovedades() {
  if (!empleadoNovedades.value) return
  cargandoHistorial.value = true
  try {
    const [f, a] = await Promise.all([
      getAusencias({ empleado_id: empleadoNovedades.value.id }),
      getAjustes({ empleado_id: empleadoNovedades.value.id }),
    ])
    historialFaltas.value  = f.data
    historialAjustes.value = a.data
  } catch {
    toast.error('No se pudo cargar el historial')
  } finally {
    cargandoHistorial.value = false
  }
}

async function guardarFalta() {
  if (!formFalta.value.fecha_inicio) {
    toast.error('Elige la fecha')
    return
  }
  guardandoNovedad.value = true
  try {
    const { data } = await crearAusencia({
      empleado_id: empleadoNovedades.value.id,
      fecha_inicio: formFalta.value.fecha_inicio,
      fecha_fin: formFalta.value.fecha_fin || null,
      horas: formFalta.value.horas,
      motivo: formFalta.value.motivo.trim() || null,
    })
    if (data.no_aplicadas?.length) {
      toast.error(`${data.no_aplicadas.length} fecha(s) ya estaban pagadas y no se aplicaron`)
    } else {
      toast.success(data.guardadas.length > 1 ? `${data.guardadas.length} faltas registradas` : 'Falta registrada')
    }
    resetFormsNovedad()
    await Promise.all([cargarNovedades(), cargarPendientes(), cargarEmpleados()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar la falta')
  } finally {
    guardandoNovedad.value = false
  }
}

async function guardarAjuste() {
  if (!formAjuste.value.nombre.trim()) {
    toast.error('Ponle un nombre al ajuste')
    return
  }
  if (!Number(formAjuste.value.monto)) {
    toast.error('El valor no puede ser cero')
    return
  }
  guardandoNovedad.value = true
  try {
    await crearAjuste({
      empleado_id: empleadoNovedades.value.id,
      fecha: formAjuste.value.fecha,
      nombre: formAjuste.value.nombre.trim(),
      monto: Number(formAjuste.value.monto) * formAjuste.value.signo,
    })
    toast.success(formAjuste.value.signo > 0 ? 'Bono registrado' : 'Descuento registrado')
    resetFormsNovedad()
    await Promise.all([cargarNovedades(), cargarPendientes(), cargarEmpleados()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar el ajuste')
  } finally {
    guardandoNovedad.value = false
  }
}

async function quitarFalta(id) {
  try {
    await eliminarAusencia(id)
    await Promise.all([cargarNovedades(), cargarPendientes(), cargarEmpleados()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
  }
}

async function quitarAjuste(id) {
  try {
    await eliminarAjuste(id)
    await Promise.all([cargarNovedades(), cargarPendientes(), cargarEmpleados()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
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
      <button @click="abrirHistorial" class="text-xs font-semibold text-gray-400 hover:text-blue-600 flex items-center gap-1">
        <ClockIcon class="w-4 h-4" /> Historial
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-4 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'pagos'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'pagos' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Pagos
        <span v-if="pendientes.length" class="ml-1 text-[10px] bg-amber-500 text-white rounded-full px-1.5 py-0.5">{{ pendientes.length }}</span>
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

    <!-- ═══════════ PAGOS ═══════════ -->
    <template v-if="tab === 'pagos'">
      <div v-if="cargandoPagos" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <template v-else>
        <p v-if="sinSueldo > 0" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
          {{ sinSueldo }} trabajador(es) no tienen sueldo asignado y no aparecen aquí.
          <button @click="tab = 'trabajadores'" class="font-semibold underline">Asignarles uno</button>
        </p>

        <div v-if="!pendientes.length" class="text-center py-12 px-6">
          <CheckCircleIcon class="w-10 h-10 text-green-400 mx-auto mb-3" />
          <p class="text-gray-500 text-sm font-medium">No hay nada por pagar ahora mismo.</p>
          <p class="text-xs text-gray-400 mt-2">
            Cada trabajador aparece aquí solo el día que se le cierra el ciclo: la quincena el 15 y el último del mes,
            la semana el domingo, el diario ese mismo día.
          </p>
        </div>

        <template v-else>
          <!-- Total y pagar todos -->
          <div class="bg-white rounded-xl shadow-sm p-4 mb-3">
            <div class="flex items-center justify-between mb-3">
              <div>
                <p class="text-xs text-gray-400">Total por pagar</p>
                <p class="text-2xl font-bold text-gray-800">{{ formatoPesos(totalGeneral) }}</p>
              </div>
              <p class="text-xs text-gray-400 text-right">{{ pendientes.length }} pago(s)<br>pendiente(s)</p>
            </div>
            <button
              @click="pagarTodos" :disabled="pagandoTodos"
              class="w-full bg-green-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-green-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
            >
              <span v-if="pagandoTodos" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
              <CheckCircleIcon v-else class="w-4 h-4" />
              {{ pagandoTodos ? 'Registrando...' : 'Marcar todos como pagados' }}
            </button>
          </div>

          <div v-for="grupo in gruposPagos" :key="grupo.label" class="mb-4">
            <div class="flex items-center justify-between px-1 mb-2">
              <p class="text-xs font-semibold text-gray-400 uppercase">{{ grupo.label }}</p>
              <p class="text-xs font-semibold text-gray-500">{{ formatoPesos(grupo.total) }}</p>
            </div>

            <div class="space-y-2.5">
              <div v-for="p in grupo.items" :key="clave(p)" class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="p-4 flex items-start justify-between gap-2 cursor-pointer" @click="pagoAbierto = pagoAbierto === clave(p) ? null : clave(p)">
                  <div class="min-w-0">
                    <p class="font-semibold text-sm text-gray-800 truncate">{{ p.empleado_nombre }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ p.empleado_cargo || 'Sin cargo' }} · {{ p.nombre }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">
                      {{ p.dias }} de {{ p.dias_ciclo }} días × {{ formatoPesos(p.valor_dia) }}
                      <span v-if="p.descuento_faltas" class="text-red-500 font-medium">· −{{ formatoPesos(p.descuento_faltas) }} faltas</span>
                      <span v-if="p.total_ajustes" :class="p.total_ajustes > 0 ? 'text-green-600 font-medium' : 'text-red-500 font-medium'">
                        · {{ p.total_ajustes > 0 ? '+' : '' }}{{ formatoPesos(p.total_ajustes) }} ajustes
                      </span>
                    </p>
                  </div>
                  <div class="flex items-center gap-1.5 shrink-0">
                    <p class="font-bold text-sm text-gray-800">{{ formatoPesos(p.total) }}</p>
                    <component :is="pagoAbierto === clave(p) ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-300" />
                  </div>
                </div>

                <div v-if="pagoAbierto === clave(p)" class="px-4 pb-4 border-t border-gray-50 pt-3 space-y-2.5">
                  <div class="flex justify-between text-xs">
                    <span class="text-gray-500">{{ p.sueldo_nombre }} · {{ p.dias }} días × {{ formatoPesos(p.valor_dia) }}</span>
                    <span class="text-gray-700 font-medium">{{ formatoPesos(p.subtotal) }}</span>
                  </div>

                  <div v-if="p.faltas.length">
                    <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1">Faltas ({{ formatoPesos(p.valor_hora) }}/hora)</p>
                    <div v-for="f in p.faltas" :key="f.id" class="flex justify-between text-xs bg-amber-50 rounded-lg px-2.5 py-1.5 mb-1">
                      <span class="text-gray-600 truncate">{{ formatoFechaCorta(f.fecha) }} · {{ f.horas }}h<span v-if="f.motivo"> · {{ f.motivo }}</span></span>
                      <span class="text-red-600 font-semibold shrink-0 ml-2">−{{ formatoPesos(f.monto) }}</span>
                    </div>
                  </div>

                  <div v-if="p.ajustes.length">
                    <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1">Ajustes</p>
                    <div v-for="a in p.ajustes" :key="a.id" class="flex justify-between text-xs bg-gray-50 rounded-lg px-2.5 py-1.5 mb-1">
                      <span class="text-gray-600 truncate">{{ formatoFechaCorta(a.fecha) }} · {{ a.nombre }}</span>
                      <span :class="['font-semibold shrink-0 ml-2', a.monto >= 0 ? 'text-green-600' : 'text-red-600']">
                        {{ a.monto >= 0 ? '+' : '' }}{{ formatoPesos(a.monto) }}
                      </span>
                    </div>
                  </div>

                  <p class="text-[11px] text-gray-400">
                    Para agregar una falta o un bono a este ciclo, hazlo desde el trabajador en la pestaña Trabajadores.
                  </p>

                  <div class="flex items-center justify-between pt-2 border-t border-gray-50">
                    <p class="text-sm font-bold text-gray-800">{{ formatoPesos(p.total) }}</p>
                    <button
                      @click.stop="pagarUno(p)" :disabled="pagandoClave === clave(p)"
                      class="bg-green-600 text-white text-xs font-semibold px-3 py-1.5 rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center gap-1"
                    >
                      <span v-if="pagandoClave === clave(p)" class="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                      <CheckCircleIcon v-else class="w-3.5 h-3.5" />
                      {{ pagandoClave === clave(p) ? 'Guardando...' : 'Pagado' }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </template>
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

      <div class="relative mb-3">
        <MagnifyingGlassIcon class="w-4 h-4 text-gray-300 absolute left-3 top-1/2 -translate-y-1/2" />
        <input
          v-model="busquedaEmpleado" placeholder="Buscar por nombre, cargo o cédula..."
          class="w-full rounded-xl border border-gray-200 pl-9 pr-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
        />
      </div>

      <p v-if="empleadosSinSueldo > 0" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
        {{ empleadosSinSueldo }} trabajador(es) todavía no tienen sueldo asignado: no se les puede liquidar hasta elegirles uno.
      </p>

      <div v-if="cargandoEmpleados" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <p v-else-if="!empleadosActivos.length && !empleadosInactivos.length" class="text-center py-12 text-gray-400 text-sm">
        Nadie coincide con "{{ busquedaEmpleado }}".
      </p>

      <div v-else class="space-y-2.5">
        <div v-for="e in empleadosActivos" :key="e.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-sm text-gray-800 truncate flex items-center gap-1.5">
                {{ e.nombre }}
                <span class="text-[10px] font-semibold text-blue-600 bg-blue-50 rounded-full px-1.5 py-0.5 shrink-0">{{ e.periodicidad_label }}</span>
              </p>
              <p class="text-xs text-gray-500 mt-0.5">{{ e.cargo || 'Sin cargo' }} <span v-if="e.cedula">· CC {{ e.cedula }}</span></p>
              <p class="text-xs mt-0.5" :class="e.nomina_sueldo_id ? 'text-gray-600' : 'text-amber-600 font-medium'">
                {{ e.label_efectivo }}<span v-if="e.nomina_sueldo_id">: {{ formatoPesos(e.valor_dia_efectivo) }}/día · {{ formatoPesos(e.valor_hora_efectivo) }}/hora</span>
              </p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click="abrirNovedades(e)" class="p-1.5 text-gray-300 hover:text-amber-600 transition-colors" aria-label="Faltas y ajustes">
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

          <!-- Lo que lleva devengado en el ciclo en curso -->
          <div v-if="e.ciclo" class="mt-2.5 pt-2.5 border-t border-gray-50">
            <div class="flex items-center justify-between gap-2">
              <div class="min-w-0">
                <p class="text-[11px] text-gray-400 truncate">{{ e.ciclo.nombre }}</p>
                <p class="text-xs text-gray-600 mt-0.5">
                  Lleva <span class="font-semibold text-gray-800">{{ e.ciclo.dias }}</span> de {{ e.ciclo.dias_ciclo }} días
                  <span v-if="e.ciclo.descuento_faltas" class="text-red-500">· −{{ formatoPesos(e.ciclo.descuento_faltas) }} faltas</span>
                  <span v-if="e.ciclo.total_ajustes" :class="e.ciclo.total_ajustes > 0 ? 'text-green-600' : 'text-red-500'">
                    · {{ e.ciclo.total_ajustes > 0 ? '+' : '' }}{{ formatoPesos(e.ciclo.total_ajustes) }}
                  </span>
                </p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-[10px] text-gray-400">Acumulado</p>
                <p class="font-bold text-sm text-green-700">{{ formatoPesos(e.ciclo.total) }}</p>
              </div>
            </div>
            <p v-if="e.ciclo.faltas_programadas.length" class="text-[11px] text-amber-600 mt-1">
              {{ e.ciclo.faltas_programadas.length }} falta(s) avisada(s) en este ciclo, aún sin descontar
              (−{{ formatoPesos(e.ciclo.faltas_programadas.reduce((s, f) => s + f.monto, 0)) }})
            </p>
            <p v-if="e.faltas_futuras" class="text-[11px] text-gray-400 mt-1">
              {{ e.faltas_futuras }} falta(s) avisada(s) para ciclos siguientes.
            </p>
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
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Frecuencia de pago *</label>
                  <select v-model="formEmpleado.periodicidad" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                    <option v-for="op in PERIODICIDADES" :key="op.value" :value="op.value">{{ op.label }}</option>
                  </select>
                  <p class="text-[11px] text-gray-400 mt-1">Cada cuánto se le paga. Aparece solo en Pagos el día que se le cierra el ciclo.</p>
                </div>

                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Sueldo *</label>
                  <select v-model="formEmpleado.nomina_sueldo_id" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                    <option value="">Elegir sueldo...</option>
                    <option v-for="s in sueldosActivos" :key="s.id" :value="s.id">
                      {{ s.nombre }} — {{ formatoPesos(s.valor) }}/{{ s.unidad === 'hora' ? 'hora' : 'día' }}
                    </option>
                  </select>
                  <p class="text-[11px] text-gray-400 mt-1">
                    Los sueldos se crean una sola vez en la pestaña Sueldos y se reutilizan aquí.
                  </p>
                </div>

                <div v-if="sueldoElegido" class="bg-blue-50 border border-blue-100 rounded-xl px-3.5 py-2.5 text-xs text-blue-700 space-y-0.5">
                  <p class="font-semibold">
                    {{ formatoPesos(sueldoElegido.valor) }}/{{ sueldoElegido.unidad === 'hora' ? 'hora' : 'día' }}
                    <span v-if="sueldoElegido.unidad === 'hora'">· jornada de {{ sueldoElegido.horas_dia }}h</span>
                  </p>
                  <p>
                    Cobra {{ formatoPesos(porFrecuencia(valorDiaEquivalente(sueldoElegido.valor, sueldoElegido.unidad, sueldoElegido.horas_dia), formEmpleado.periodicidad)) }}
                    {{ PERIODICIDADES.find(p => p.value === formEmpleado.periodicidad)?.label.toLowerCase() }}
                    ({{ DIAS_POR_FRECUENCIA[formEmpleado.periodicidad] }} días × {{ formatoPesos(valorDiaEquivalente(sueldoElegido.valor, sueldoElegido.unidad, sueldoElegido.horas_dia)) }})
                  </p>
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

    <!-- ═══════════ SUELDOS ═══════════ -->
    <template v-else-if="tab === 'sueldos'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">Se crean una vez y se reutilizan al dar de alta trabajadores.</p>
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
                  <span v-if="s.unidad === 'hora'">({{ s.horas_dia }}h/día)</span>
                </p>
                <p class="text-[11px] text-gray-400 mt-0.5">
                  {{ formatoPesos(porFrecuencia(valorDiaEquivalente(s.valor, s.unidad, s.horas_dia), 'semanal')) }} semana ·
                  {{ formatoPesos(porFrecuencia(valorDiaEquivalente(s.valor, s.unidad, s.horas_dia), 'quincenal')) }} quincena ·
                  {{ formatoPesos(porFrecuencia(valorDiaEquivalente(s.valor, s.unidad, s.horas_dia), 'mensual')) }} mes
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

        <p v-if="!sueldosActivos.length" class="text-center py-8 text-gray-400 text-sm">
          Todavía no hay sueldos creados. Crea uno para poder dar de alta trabajadores.
        </p>

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
                  <p class="text-[11px] text-gray-400 mt-1">Este nombre es el que se elige después en cada trabajador.</p>
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
                  = {{ formatoPesos(porFrecuencia(valorDiaEquivalente(formSueldo.valor, formSueldo.unidad, formSueldo.horas_dia), 'semanal')) }} semana ·
                  {{ formatoPesos(porFrecuencia(valorDiaEquivalente(formSueldo.valor, formSueldo.unidad, formSueldo.horas_dia), 'quincenal')) }} quincena ·
                  {{ formatoPesos(porFrecuencia(valorDiaEquivalente(formSueldo.valor, formSueldo.unidad, formSueldo.horas_dia), 'mensual')) }} mes
                  <span v-if="formSueldo.unidad === 'hora'">· jornada completa = {{ formatoPesos(valorDiaEquivalente(formSueldo.valor, formSueldo.unidad, formSueldo.horas_dia)) }}/día</span>
                </p>
                <p class="text-[11px] text-gray-400">
                  Las horas por día también se usan para descontar faltas parciales (media jornada, una cita médica).
                  Cambiar el valor mueve lo que todavía no se ha cobrado; lo ya pagado no se toca.
                </p>
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

    <!-- ═══════════ NOVEDADES: faltas y ajustes ═══════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarNovedades" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarNovedades = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm rounded-t-3xl sm:rounded-t-2xl">
              <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center shrink-0">
                  <CalendarIcon class="w-5 h-5 text-amber-600" />
                </div>
                <p class="font-semibold text-gray-800 truncate">{{ empleadoNovedades?.nombre }}</p>
              </div>
              <button @click="mostrarNovedades = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="px-5 pt-4">
              <div class="flex gap-2 bg-gray-100 rounded-xl p-1">
                <button
                  type="button" @click="novedadTab = 'falta'"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', novedadTab === 'falta' ? 'bg-white text-amber-600 shadow-sm' : 'text-gray-500']"
                >Falta</button>
                <button
                  type="button" @click="novedadTab = 'ajuste'"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', novedadTab === 'ajuste' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
                >Bono o descuento</button>
              </div>
            </div>

            <!-- Registrar falta -->
            <div v-if="novedadTab === 'falta'" class="p-5 space-y-4 border-b border-gray-100">
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
              <p class="text-[11px] text-gray-400 -mt-2">
                Deja "Hasta" vacío si es un solo día. Puede ser una fecha futura: si avisa que va a faltar la quincena
                que viene, se descuenta sola cuando esa quincena se pague.
              </p>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Horas que faltó</label>
                  <input
                    v-model.number="formFalta.horas" type="number" step="0.5" min="0.5" max="24"
                    class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                  />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Motivo</label>
                  <input v-model="formFalta.motivo" placeholder="Cita médica..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
              </div>
              <p class="text-[11px] text-gray-400 -mt-2">
                Jornada completa: {{ empleadoNovedades?.horas_dia_efectivo }}h.
                Se descuentan {{ formatoPesos((Number(formFalta.horas) || 0) * (Number(empleadoNovedades?.valor_hora_efectivo) || 0)) }} por cada día del rango.
              </p>
              <button
                @click="guardarFalta" :disabled="guardandoNovedad"
                class="w-full bg-amber-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-amber-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <span v-if="guardandoNovedad" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                {{ guardandoNovedad ? 'Registrando...' : 'Registrar falta' }}
              </button>
            </div>

            <!-- Registrar bono o descuento -->
            <div v-else class="p-5 space-y-4 border-b border-gray-100">
              <div class="flex gap-2 bg-gray-100 rounded-xl p-1">
                <button
                  type="button" @click="formAjuste.signo = 1"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', formAjuste.signo === 1 ? 'bg-white text-green-600 shadow-sm' : 'text-gray-500']"
                >Bono (suma)</button>
                <button
                  type="button" @click="formAjuste.signo = -1"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', formAjuste.signo === -1 ? 'bg-white text-red-600 shadow-sm' : 'text-gray-500']"
                >Descuento (resta)</button>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Fecha *</label>
                  <input v-model="formAjuste.fecha" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Valor</label>
                  <InputPesos v-model="formAjuste.monto" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Concepto *</label>
                <input v-model="formAjuste.nombre" :placeholder="formAjuste.signo === 1 ? 'Hora extra, bonificación...' : 'Préstamo, herramienta...'" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
              </div>
              <p class="text-[11px] text-gray-400">
                Entra en el ciclo que contenga esa fecha, igual que una falta.
              </p>
              <button
                @click="guardarAjuste" :disabled="guardandoNovedad"
                :class="['w-full text-white text-sm font-semibold rounded-xl px-4 py-2.5 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5', formAjuste.signo === 1 ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700']"
              >
                <span v-if="guardandoNovedad" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                {{ guardandoNovedad ? 'Registrando...' : (formAjuste.signo === 1 ? 'Registrar bono' : 'Registrar descuento') }}
              </button>
            </div>

            <!-- Historial -->
            <div class="p-5 space-y-2.5">
              <p class="text-xs font-semibold text-gray-500 uppercase">Historial</p>

              <div v-if="cargandoHistorial" class="flex justify-center py-8">
                <div class="w-5 h-5 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" />
              </div>

              <template v-else>
                <p v-if="!historialFaltas.length && !historialAjustes.length" class="text-center py-6 text-gray-400 text-sm">
                  Sin faltas ni ajustes registrados todavía.
                </p>

                <div v-for="a in historialFaltas" :key="'f' + a.id" class="bg-amber-50/60 rounded-xl px-3.5 py-3">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-gray-800">Falta · {{ formatoFecha(a.fecha) }} · {{ a.horas }}h</p>
                      <p v-if="a.motivo" class="text-xs text-gray-500 italic mt-0.5">{{ a.motivo }}</p>
                      <p class="text-[11px] text-gray-400 mt-1">Registrada el {{ formatoFechaHora(a.registrada_en) }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <p class="text-sm font-semibold text-red-600">−{{ formatoPesos(a.monto) }}</p>
                      <button v-if="!a.pagada" @click="quitarFalta(a.id)" class="text-gray-300 hover:text-red-600 transition-colors">
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                  <p class="text-[11px] font-semibold mt-1.5" :class="a.pagada ? 'text-green-600' : 'text-blue-500'">
                    {{ a.pagada ? `Ya descontada · ${a.ciclo}` : `Se descuenta en: ${a.ciclo}` }}
                  </p>
                </div>

                <div v-for="a in historialAjustes" :key="'a' + a.id" class="bg-gray-50 rounded-xl px-3.5 py-3">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-gray-800 truncate">{{ a.nombre }}</p>
                      <p class="text-xs text-gray-500 mt-0.5">{{ formatoFecha(a.fecha) }}</p>
                      <p class="text-[11px] text-gray-400 mt-1">Registrado el {{ formatoFechaHora(a.registrado_en) }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <p class="text-sm font-semibold" :class="a.monto >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ a.monto >= 0 ? '+' : '' }}{{ formatoPesos(a.monto) }}
                      </p>
                      <button v-if="!a.pagado" @click="quitarAjuste(a.id)" class="text-gray-300 hover:text-red-600 transition-colors">
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                  <p v-if="a.pagado" class="text-[11px] font-semibold text-green-600 mt-1.5">Ya pagado · {{ a.ciclo }}</p>
                </div>
              </template>
            </div>

            <div class="flex gap-2.5 p-5 pt-2">
              <button @click="mostrarNovedades = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cerrar</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ═══════════ HISTORIAL DE PAGOS ═══════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarHistorial" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarHistorial = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm rounded-t-3xl sm:rounded-t-2xl">
              <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-green-50 flex items-center justify-center shrink-0">
                  <ClockIcon class="w-5 h-5 text-green-600" />
                </div>
                <p class="font-semibold text-gray-800">Pagos hechos</p>
              </div>
              <button @click="mostrarHistorial = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-2.5">
              <div v-if="cargandoHistorial2" class="flex justify-center py-8">
                <div class="w-5 h-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin" />
              </div>

              <p v-else-if="!historial.length" class="text-center py-8 text-gray-400 text-sm">Todavía no se ha pagado nada.</p>

              <div v-else v-for="p in historial" :key="p.id" class="bg-gray-50 rounded-xl px-3.5 py-3">
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-800 truncate">{{ p.empleado_nombre }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ p.nombre }} · {{ p.periodicidad_label }}</p>
                    <p class="text-[11px] text-gray-400 mt-0.5">
                      {{ p.dias }} días × {{ formatoPesos(p.valor_dia) }}
                      <span v-if="p.descuento_faltas">· −{{ formatoPesos(p.descuento_faltas) }} faltas</span>
                      <span v-if="p.total_ajustes">· {{ p.total_ajustes > 0 ? '+' : '' }}{{ formatoPesos(p.total_ajustes) }} ajustes</span>
                    </p>
                    <p class="text-[11px] text-gray-400 mt-1">Pagado el {{ formatoFechaHora(p.pagado_at) }}</p>
                  </div>
                  <div class="flex flex-col items-end gap-1.5 shrink-0">
                    <p class="font-bold text-sm text-gray-800">{{ formatoPesos(p.total) }}</p>
                    <button @click="deshacer(p)" class="text-[11px] text-gray-400 hover:text-red-600 transition-colors flex items-center gap-1">
                      <ArrowUturnLeftIcon class="w-3 h-3" /> Deshacer
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex gap-2.5 p-5 pt-2">
              <button @click="mostrarHistorial = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cerrar</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
