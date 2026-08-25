<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { getEmpleados, actualizarEmpleado, eliminarEmpleado, asignarEnLote } from '@/api/empleados'
import { getPrestamos, crearPrestamo, editarPrestamo, borrarPrestamo } from '@/api/nomina'
import {
  getSueldos, crearSueldo, actualizarSueldo, eliminarSueldo,
  getPagosPendientes, pagar, pagarLote, getHistorialPagos, deshacerPago,
  crearAusencia, getAusencias, eliminarAusencia,
  getAjustes, crearAjuste, eliminarAjuste,
  getProducciones, crearProduccion, eliminarProduccion,
  getBonificaciones, crearBonificacion, actualizarBonificacion, eliminarBonificacion,
  agregarMeta, actualizarMeta, eliminarMeta,
} from '@/api/nomina'
import {
  BanknotesIcon, PlusIcon, PencilSquareIcon, TrashIcon, XMarkIcon,
  UsersIcon, CheckCircleIcon, TagIcon, CalendarIcon, MagnifyingGlassIcon,
  ChevronDownIcon, ChevronUpIcon, ClockIcon, ArrowUturnLeftIcon,
  TrophyIcon, WrenchScrewdriverIcon,
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

// ── Bonificaciones por producción ─────────────────────────────────────────
const bonificaciones       = ref([])
const cargandoBonos        = ref(true)
const mostrarFormBono      = ref(false)
const editandoBono         = ref(null)
// Sobre qué ventana se mide el tope. 'ciclo' sigue el ciclo de pago de cada
// trabajador; el resto son ventanas fijas iguales para todos.
const PERIODOS_BONO = [
  { value: 'ciclo', label: 'Por ciclo de pago' },
  { value: 'diario', label: 'Diario' },
  { value: 'semanal', label: 'Semanal' },
  { value: 'quincenal', label: 'Quincenal' },
  { value: '20_dias', label: 'Cada 20 días' },
  { value: 'mensual', label: 'Mensual' },
]
const formBono             = ref({ nombre: '', periodo: 'ciclo', tope: 0, tope_activo: true })
const guardandoBono        = ref(false)
const bonoAbierto          = ref(null)
// Formulario de meta nueva, uno por esquema: { [bonoId]: { desde, hasta, monto } }
const formMeta             = ref({})

async function cargarBonificaciones() {
  cargandoBonos.value = true
  try {
    const { data } = await getBonificaciones(true)
    bonificaciones.value = data
  } catch {
    toast.error('No se pudieron cargar las bonificaciones')
  } finally {
    cargandoBonos.value = false
  }
}

const bonosActivos   = computed(() => bonificaciones.value.filter(b => b.activo))
const bonosInactivos = computed(() => bonificaciones.value.filter(b => !b.activo))

function abrirNuevoBono() {
  editandoBono.value = null
  formBono.value = { nombre: '', periodo: 'ciclo', tope: 0, tope_activo: true }
  mostrarFormBono.value = true
}

function abrirEditarBono(b) {
  editandoBono.value = b.id
  formBono.value = {
    nombre: b.nombre,
    periodo: b.periodo || 'ciclo',
    tope: Number(b.tope) || 0,
    tope_activo: !!b.tope_activo,
  }
  mostrarFormBono.value = true
}

async function guardarBono() {
  if (!formBono.value.nombre.trim()) {
    toast.error('Ponle un nombre a la bonificación')
    return
  }
  guardandoBono.value = true
  try {
    const payload = {
      nombre: formBono.value.nombre.trim(),
      periodo: formBono.value.periodo,
      tope: formBono.value.tope,
      tope_activo: formBono.value.tope_activo,
    }
    if (editandoBono.value) {
      await actualizarBonificacion(editandoBono.value, payload)
      toast.success('Bonificación actualizada')
    } else {
      const { data } = await crearBonificacion(payload)
      bonoAbierto.value = data.id
      toast.success('Bonificación creada. Ahora agrégale las metas.')
    }
    mostrarFormBono.value = false
    empleadosCargados = false
    await Promise.all([cargarBonificaciones(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoBono.value = false
  }
}

async function borrarBono(b) {
  if (!confirm(`¿Eliminar "${b.nombre}"? Si algún trabajador la tiene asignada, se desactiva en vez de borrarse.`)) return
  try {
    const { data } = await eliminarBonificacion(b.id)
    toast.success(data?.message ?? 'Bonificación eliminada')
    empleadosCargados = false
    await Promise.all([cargarBonificaciones(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

async function reactivarBono(b) {
  try {
    await actualizarBonificacion(b.id, { activo: true })
    toast.success('Bonificación reactivada')
    await cargarBonificaciones()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo reactivar')
  }
}

function abrirBono(b) {
  bonoAbierto.value = bonoAbierto.value === b.id ? null : b.id
  if (!formMeta.value[b.id]) formMeta.value[b.id] = { desde: 0, hasta: '', monto: 0 }
}

async function guardarMeta(b) {
  const m = formMeta.value[b.id]
  if (!Number(m?.monto)) {
    toast.error('Falta cuánto se paga en esta meta')
    return
  }
  try {
    await agregarMeta(b.id, {
      desde: m.desde,
      // Vacío = "de aquí en adelante": el último escalón no tiene techo.
      hasta: m.hasta === '' || m.hasta === null ? null : m.hasta,
      monto: m.monto,
    })
    formMeta.value[b.id] = { desde: 0, hasta: '', monto: 0 }
    empleadosCargados = false
    await Promise.all([cargarBonificaciones(), cargarPendientes()])
    toast.success('Meta agregada')
  } catch (e) {
    const msg = e.response?.data?.message
      || Object.values(e.response?.data?.errors ?? {}).flat()[0]
      || 'No se pudo agregar la meta'
    toast.error(msg)
  }
}

async function alternarMeta(meta) {
  try {
    await actualizarMeta(meta.id, { activo: !meta.activo })
    empleadosCargados = false
    await Promise.all([cargarBonificaciones(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo cambiar')
  }
}

async function borrarMeta(meta) {
  if (!confirm(`¿Eliminar la meta ${meta.etiqueta}?`)) return
  try {
    await eliminarMeta(meta.id)
    empleadosCargados = false
    await Promise.all([cargarBonificaciones(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

// ── Trabajadores ──────────────────────────────────────────────────────────
const empleados           = ref([])
const cargandoEmpleados   = ref(true)
const mostrarFormEmpleado = ref(false)
const editandoEmpleado    = ref(null)
const SIN_BONO = ''
const formEmpleado        = ref({
  nombre: '', cedula: '', cargo: '', nomina_sueldo_id: '',
  nomina_bonificacion_id: SIN_BONO, periodicidad: 'quincenal',
})
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
let bonosCargados     = false
watch(tab, (t) => {
  if (t === 'trabajadores' && !empleadosCargados) {
    empleadosCargados = true
    cargarEmpleados()
  }
  if ((t === 'sueldos' || t === 'trabajadores') && !sueldosCargados) {
    sueldosCargados = true
    cargarSueldos()
  }
  // El modal de trabajador necesita la lista para el select de bonificación.
  if ((t === 'bonos' || t === 'trabajadores') && !bonosCargados) {
    bonosCargados = true
    cargarBonificaciones()
  }
})

function abrirEditarEmpleado(e) {
  // Sin sueldos cargados no hay nada que asignarle.
  if (!cargandoSueldos.value && !sueldosActivos.value.length) {
    toast.error('Primero crea un sueldo en la pestaña Sueldos')
    tab.value = 'sueldos'
    return
  }
  editandoEmpleado.value = e.id
  formEmpleado.value = {
    nombre: e.nombre,
    cedula: e.cedula ?? '',
    cargo: e.cargo ?? '',
    nomina_sueldo_id: e.nomina_sueldo_id ?? '',
    nomina_bonificacion_id: e.nomina_bonificacion_id ?? SIN_BONO,
    periodicidad: e.periodicidad || 'quincenal',
  }
  mostrarFormEmpleado.value = true
}

// Desde acá NO se crea gente ni se le cambia el nombre, la cédula o el
// cargo: eso vive en Trabajadores, que es el único sitio donde una persona
// existe. Acá solo se asigna lo de nómina.
async function guardarEmpleado() {
  if (!formEmpleado.value.nomina_sueldo_id) {
    toast.error('Elige el sueldo de este trabajador')
    return
  }
  guardandoEmpleado.value = true
  try {
    await actualizarEmpleado(editandoEmpleado.value, {
      nomina_sueldo_id: formEmpleado.value.nomina_sueldo_id,
      // Vacío = no aplica para bonificación.
      nomina_bonificacion_id: formEmpleado.value.nomina_bonificacion_id || null,
      periodicidad: formEmpleado.value.periodicidad,
    })
    toast.success('Trabajador actualizado')
    mostrarFormEmpleado.value = false
    await Promise.all([cargarEmpleados(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoEmpleado.value = false
  }
}

// No borra a la persona —eso es en Trabajadores—: le quita el sueldo, con
// lo cual deja de aparecer para cobrar pero sigue existiendo.
async function borrarEmpleado(e) {
  if (!confirm(`¿Sacar a "${e.nombre}" de nómina?\n\nSe le quita el sueldo y deja de aparecer para cobrar. La persona y su historial de pagos se conservan; para borrarla del todo hay que ir a Trabajadores.`)) return
  try {
    const { data } = await eliminarEmpleado(e.id)
    toast.success(data?.message ?? 'Trabajador eliminado')
    await Promise.all([cargarEmpleados(), cargarPendientes()])
  } catch (err) {
    toast.error(err.response?.data?.message || 'No se pudo eliminar')
  }
}

// Reactivar a una persona es de Trabajadores, no de Nómina: acá solo se
// muestra quién está inactivo para que se sepa por qué no aparece.

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

// ── Agregar gente a nómina ────────────────────────────────────────────────
// La lista principal son los que YA están en nómina. Los demás se piden
// aparte: cargar 32 personas de fábrica una por una es lo que hace que nómina
// no arranque nunca, así que se les pone el sueldo a varios de una.
const modalAgregar   = ref(false)
const porAgregar     = ref([])
const cargandoAgregar = ref(false)
const seleccionados  = ref([])
const loteSueldo     = ref('')
const lotePeriodo    = ref('quincenal')
const guardandoLote  = ref(false)
const busquedaAgregar = ref('')

const porAgregarFiltrados = computed(() => {
  const q = busquedaAgregar.value.trim().toLowerCase()
  return porAgregar.value.filter(u => !q || (u.nombre ?? '').toLowerCase().includes(q))
})

async function abrirAgregar() {
  modalAgregar.value = true
  seleccionados.value = []
  busquedaAgregar.value = ''
  cargandoAgregar.value = true
  try {
    const { data } = await getEmpleados(false, { sin_sueldo: 1 })
    porAgregar.value = Array.isArray(data) ? data : []
  } catch { porAgregar.value = [] } finally { cargandoAgregar.value = false }
}

function alternarSeleccion(id) {
  seleccionados.value = seleccionados.value.includes(id)
    ? seleccionados.value.filter(x => x !== id)
    : [...seleccionados.value, id]
}
function seleccionarTodos() {
  const ids = porAgregarFiltrados.value.map(u => u.id)
  seleccionados.value = seleccionados.value.length === ids.length ? [] : ids
}

async function guardarLote() {
  if (!seleccionados.value.length) { toast.error('Elige a quién le vas a poner sueldo.'); return }
  if (!loteSueldo.value) { toast.error('Elige el sueldo.'); return }
  guardandoLote.value = true
  try {
    const { data } = await asignarEnLote({
      usuarios: seleccionados.value,
      nomina_sueldo_id: loteSueldo.value,
      periodicidad: lotePeriodo.value,
    })
    toast.success(data?.message ?? 'Listo')
    modalAgregar.value = false
    await Promise.all([cargarEmpleados(), cargarPendientes()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo asignar')
  } finally { guardandoLote.value = false }
}

// ── Préstamos ─────────────────────────────────────────────────────────────
// "Présteme 200.000 y me los descuenta en dos meses": se registra una vez y el
// sistema descuenta una cuota en cada pago, hasta saldarlo.
const modalPrestamo   = ref(false)
const empPrestamo     = ref(null)
const prestamosDe     = ref([])
const cargandoPrest   = ref(false)
const guardandoPrest  = ref(false)
const formPrestamo    = ref({ monto: '', cuotas: '', motivo: '' })

/** Lo que se le va a descontar en cada pago, para verlo antes de guardar. */
const cuotaCalculada = computed(() => {
  const m = Number(formPrestamo.value.monto) || 0
  const c = Number(formPrestamo.value.cuotas) || 0
  return m > 0 && c > 0 ? Math.ceil(m / c) : 0
})

async function abrirPrestamos(e) {
  empPrestamo.value  = e
  formPrestamo.value = { monto: '', cuotas: '', motivo: '' }
  modalPrestamo.value = true
  cargandoPrest.value = true
  try {
    const { data } = await getPrestamos(e.id, true)
    prestamosDe.value = Array.isArray(data) ? data : []
  } catch { prestamosDe.value = [] } finally { cargandoPrest.value = false }
}

async function guardarPrestamo() {
  const m = Number(formPrestamo.value.monto)
  const c = Number(formPrestamo.value.cuotas)
  if (!(m > 0)) { toast.error('¿Cuánto se le presta?'); return }
  if (!(c > 0)) { toast.error('¿En cuántos pagos se le descuenta?'); return }
  guardandoPrest.value = true
  try {
    await crearPrestamo({
      usuario_id: empPrestamo.value.id, monto: m, cuotas: c,
      motivo: formPrestamo.value.motivo.trim() || null,
    })
    toast.success('Préstamo registrado: se descuenta solo en cada pago.')
    formPrestamo.value = { monto: '', cuotas: '', motivo: '' }
    const { data } = await getPrestamos(empPrestamo.value.id, true)
    prestamosDe.value = Array.isArray(data) ? data : []
    await cargarEmpleados()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar')
  } finally { guardandoPrest.value = false }
}

async function pausarPrestamo(pr) {
  try {
    await editarPrestamo(pr.id, { activo: !pr.activo })
    const { data } = await getPrestamos(empPrestamo.value.id, true)
    prestamosDe.value = Array.isArray(data) ? data : []
    toast.success(pr.activo ? 'Pausado: deja de descontarse.' : 'Reactivado.')
  } catch (e) { toast.error(e.response?.data?.message || 'No se pudo cambiar') }
}

async function quitarPrestamo(pr) {
  if (!confirm(`¿Borrar el préstamo de ${formatoPesos(pr.monto)}?`)) return
  try {
    await borrarPrestamo(pr.id)
    const { data } = await getPrestamos(empPrestamo.value.id, true)
    prestamosDe.value = Array.isArray(data) ? data : []
    toast.success('Préstamo eliminado')
    await cargarEmpleados()
  } catch (e) { toast.error(e.response?.data?.message || 'No se pudo borrar') }
}

// ── Novedades del trabajador: faltas y ajustes ────────────────────────────
const mostrarNovedades  = ref(false)
const empleadoNovedades = ref(null)
const novedadTab        = ref('produccion')
const formFalta         = ref({ fecha_inicio: '', fecha_fin: '', horas: 8, motivo: '' })
const formAjuste        = ref({ fecha: '', nombre: '', monto: 0, signo: 1 })
const formProduccion    = ref({ fecha: '', concepto: '', valor_unitario: 0, cantidad: 1 })
const guardandoNovedad  = ref(false)
const historialFaltas   = ref([])
const historialAjustes  = ref([])
const historialProd     = ref([])
const cargandoHistorial = ref(false)

// Atajos para lo que más se repite, y para que "préstamo" no haya que
// escribirlo cada vez ni acordarse de ponerle el signo menos.
const CONCEPTOS_DESCUENTO = ['Préstamo', 'Herramienta', 'Anticipo']
const CONCEPTOS_BONO      = ['Hora extra', 'Bonificación', 'Recargo']

async function abrirNovedades(e) {
  empleadoNovedades.value = e
  // Si aplica para bonificación, lo que más se va a registrar es producción.
  novedadTab.value = e.nomina_bonificacion_id ? 'produccion' : 'falta'
  resetFormsNovedad()
  mostrarNovedades.value = true
  await cargarNovedades()
}

function resetFormsNovedad() {
  const e = empleadoNovedades.value
  formFalta.value      = { fecha_inicio: hoyISO(), fecha_fin: '', horas: Number(e?.horas_dia_efectivo) || 8, motivo: '' }
  formAjuste.value     = { fecha: hoyISO(), nombre: '', monto: 0, signo: 1 }
  formProduccion.value = { fecha: hoyISO(), concepto: '', valor_unitario: 0, cantidad: 1 }
}

const totalProduccionForm = computed(() =>
  (Number(formProduccion.value.valor_unitario) || 0) * (Number(formProduccion.value.cantidad) || 0)
)

async function cargarNovedades() {
  if (!empleadoNovedades.value) return
  cargandoHistorial.value = true
  try {
    const [f, a, p] = await Promise.all([
      getAusencias({ empleado_id: empleadoNovedades.value.id }),
      getAjustes({ empleado_id: empleadoNovedades.value.id }),
      getProducciones({ empleado_id: empleadoNovedades.value.id }),
    ])
    historialFaltas.value  = f.data
    historialAjustes.value = a.data
    historialProd.value    = p.data
  } catch {
    toast.error('No se pudo cargar el historial')
  } finally {
    cargandoHistorial.value = false
  }
}

async function guardarProduccion() {
  if (!formProduccion.value.concepto.trim()) {
    toast.error('Escribe qué hizo')
    return
  }
  if (!Number(formProduccion.value.valor_unitario)) {
    toast.error('Falta cuánto vale cada una')
    return
  }
  if (!Number(formProduccion.value.cantidad)) {
    toast.error('Falta cuántas hizo')
    return
  }
  guardandoNovedad.value = true
  try {
    await crearProduccion({
      empleado_id: empleadoNovedades.value.id,
      fecha: formProduccion.value.fecha,
      concepto: formProduccion.value.concepto.trim(),
      valor_unitario: formProduccion.value.valor_unitario,
      cantidad: formProduccion.value.cantidad,
    })
    toast.success('Producción registrada')
    resetFormsNovedad()
    await Promise.all([cargarNovedades(), cargarPendientes(), cargarEmpleados()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar')
  } finally {
    guardandoNovedad.value = false
  }
}

async function quitarProduccion(id) {
  try {
    await eliminarProduccion(id)
    await Promise.all([cargarNovedades(), cargarPendientes(), cargarEmpleados()])
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
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
    <div class="flex gap-1 mb-4 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'pagos'"
        :class="['flex-1 text-xs font-semibold rounded-lg py-2 transition-colors', tab === 'pagos' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Pagos
        <span v-if="pendientes.length" class="ml-0.5 text-[10px] bg-amber-500 text-white rounded-full px-1.5 py-0.5">{{ pendientes.length }}</span>
      </button>
      <button
        @click="tab = 'trabajadores'"
        :class="['flex-1 text-xs font-semibold rounded-lg py-2 transition-colors', tab === 'trabajadores' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Gente
      </button>
      <button
        @click="tab = 'sueldos'"
        :class="['flex-1 text-xs font-semibold rounded-lg py-2 transition-colors', tab === 'sueldos' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Sueldos
      </button>
      <button
        @click="tab = 'bonos'"
        :class="['flex-1 text-xs font-semibold rounded-lg py-2 transition-colors', tab === 'bonos' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Bonos
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
                      <span v-if="p.bonificacion" class="text-green-600 font-medium">· +{{ formatoPesos(p.bonificacion) }} bono</span>
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

                  <div v-if="p.prestamos?.length">
                    <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1">Préstamos</p>
                    <div v-for="pr in p.prestamos" :key="pr.id" class="flex justify-between text-xs bg-purple-50 rounded-lg px-2.5 py-1.5 mb-1">
                      <span class="text-gray-600 truncate">
                        {{ pr.motivo || 'Préstamo' }} · queda {{ formatoPesos(pr.saldo) }}
                      </span>
                      <span class="text-red-600 font-semibold shrink-0 ml-2">−{{ formatoPesos(pr.cuota_ahora) }}</span>
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

                  <!-- Producción y bono del ciclo -->
                  <div v-if="p.bono.aplica">
                    <p class="text-[11px] font-semibold text-gray-400 uppercase mb-1">
                      Producción<span v-if="p.producciones.length"> del ciclo</span>
                    </p>
                    <div v-for="pr in p.producciones" :key="pr.id" class="flex justify-between text-xs bg-gray-50 rounded-lg px-2.5 py-1.5 mb-1">
                      <span class="text-gray-600 truncate">
                        {{ formatoFechaCorta(pr.fecha) }} · {{ pr.concepto }}
                        <span v-if="pr.cantidad !== 1" class="text-gray-400">× {{ pr.cantidad }}</span>
                      </span>
                      <span class="text-gray-700 font-medium shrink-0 ml-2">{{ formatoPesos(pr.total) }}</span>
                    </div>
                    <div class="flex justify-between text-xs font-semibold px-2.5 py-1">
                      <span class="text-gray-600">Total producido</span>
                      <span class="text-gray-800">{{ formatoPesos(p.produccion_total) }}</span>
                    </div>
                    <!-- El bono no siempre se mide sobre el ciclo: si la
                         ventana es mensual, aquí se ve el mes completo. -->
                    <template v-if="p.bono.cierra_aqui">
                      <div v-for="(v, i) in p.bono.ventanas" :key="i" class="mt-1">
                        <div
                          class="flex justify-between text-xs rounded-lg px-2.5 py-1.5"
                          :class="v.monto ? 'bg-green-50' : 'bg-amber-50'"
                        >
                          <span class="text-gray-600 truncate">
                            <template v-if="v.monto">Bono {{ v.nombre }} · {{ v.meta }}</template>
                            <template v-else>
                              {{ v.nombre }}: produjo {{ formatoPesos(v.produccion) }}, sin bono
                            </template>
                          </span>
                          <span :class="['font-semibold shrink-0 ml-2', v.monto ? 'text-green-600' : 'text-gray-400']">
                            {{ v.monto ? '+' + formatoPesos(v.monto) : formatoPesos(0) }}
                          </span>
                        </div>
                        <p v-if="p.bono.periodo !== 'ciclo'" class="text-[10px] text-gray-400 px-2.5 mt-0.5">
                          Medido sobre {{ v.nombre.toLowerCase() }} ({{ formatoPesos(v.produccion) }} producidos),
                          no solo sobre este ciclo.
                        </p>
                      </div>
                    </template>
                    <p v-else class="text-[11px] text-gray-500 bg-gray-50 rounded-lg px-2.5 py-1.5 mt-1">
                      El bono es {{ p.bono.periodo_label.toLowerCase() }} y esa ventana no cierra en este ciclo:
                      se paga completo en el pago que la cierre.
                    </p>
                  </div>

                  <p class="text-[11px] text-gray-400">
                    Para agregar una falta, un ajuste o producción a este ciclo, hazlo desde el trabajador en la pestaña Gente.
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
      <div class="mb-3">
        <p class="text-xs text-gray-400">
          Los trabajadores se dan de alta en <span class="font-semibold text-gray-500">Trabajadores</span> y aparecen
          acá solos. Desde esta pantalla se les asigna el sueldo, la bonificación y cada cuánto se les paga.
        </p>
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

      <button v-if="empleadosActivos.length || empleadosInactivos.length" @click="abrirAgregar"
        class="w-full mb-2 flex items-center justify-center gap-2 py-2.5 rounded-xl border-2 border-dashed border-blue-300 text-blue-600 text-sm font-semibold hover:bg-blue-50 transition-colors">
        <PlusIcon class="w-4 h-4" /> Agregar gente a nómina
      </button>

      <div v-else-if="!empleadosActivos.length && !empleadosInactivos.length" class="text-center py-12 px-4">
        <template v-if="busquedaEmpleado.trim()">
          <p class="text-gray-400 text-sm">Nadie en nómina coincide con "{{ busquedaEmpleado }}".</p>
        </template>
        <template v-else>
          <p class="text-gray-600 text-sm font-medium">Todavía no hay nadie en nómina.</p>
          <p class="text-gray-400 text-xs mt-1 max-w-xs mx-auto">
            Estar en nómina es tener un sueldo asignado. Elige a quién le vas a pagar
            y con qué sueldo; puedes hacerlo con varios a la vez.
          </p>
          <button @click="abrirAgregar"
            class="mt-4 bg-blue-600 text-white text-sm font-semibold rounded-xl px-5 py-2.5 hover:bg-blue-700 transition-colors">
            Agregar gente a nómina
          </button>
        </template>
      </div>

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
              <p v-if="e.bonificacion_nombre" class="text-[11px] text-purple-600 mt-0.5 flex items-center gap-1">
                <TrophyIcon class="w-3 h-3 shrink-0" /> {{ e.bonificacion_nombre }}
              </p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click="abrirNovedades(e)" class="p-1.5 text-gray-300 hover:text-amber-600 transition-colors" aria-label="Faltas y ajustes">
                <CalendarIcon class="w-4 h-4" />
              </button>
              <button @click="abrirPrestamos(e)" class="p-1.5 text-gray-300 hover:text-purple-600 transition-colors" aria-label="Préstamos">
                <BanknotesIcon class="w-4 h-4" />
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
            <!-- Producción acumulada y cómo va para el bono -->
            <div v-if="e.ciclo.bono.aplica" class="mt-1.5 pt-1.5 border-t border-dashed border-gray-100">
              <div class="flex items-center justify-between gap-2">
                <p class="text-[11px] text-gray-500 truncate flex items-center gap-1">
                  <TrophyIcon class="w-3 h-3 text-purple-500 shrink-0" />
                  Produjo <span class="font-semibold text-gray-700">{{ formatoPesos(e.ciclo.bono.produccion_medida) }}</span>
                  <span v-if="e.ciclo.bono.periodo !== 'ciclo'" class="text-gray-400">({{ e.ciclo.bono.periodo_label.toLowerCase() }})</span>
                </p>
                <p v-if="e.ciclo.bonificacion" class="text-xs font-bold text-purple-600 shrink-0">
                  +{{ formatoPesos(e.ciclo.bonificacion) }}
                </p>
              </div>
              <p class="text-[11px] mt-0.5" :class="e.ciclo.bonificacion ? 'text-purple-500' : 'text-gray-400'">
                <template v-if="!e.ciclo.bono.cierra_aqui">
                  Bono {{ e.ciclo.bono.periodo_label.toLowerCase() }}: se cobra en el ciclo que cierre esa ventana.
                </template>
                <template v-else-if="e.ciclo.bonificacion">Bono {{ e.ciclo.bono.meta }}</template>
                <template v-else-if="!e.ciclo.bono.alcanzo_tope">
                  Le faltan {{ formatoPesos(e.ciclo.bono.falta_para_tope) }} para el tope de {{ formatoPesos(e.ciclo.bono.tope) }}
                </template>
                <template v-else>Pasó el tope, pero no cae en ninguna meta activa</template>
              </p>
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
            <span class="text-[11px] text-gray-400 shrink-0">Se reactiva en Trabajadores</span>
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
                  <p class="font-semibold text-gray-800">Nómina de {{ formEmpleado.nombre }}</p>
                </div>
                <button @click="mostrarFormEmpleado = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>
              <div class="p-5 space-y-4">
                <!-- Identidad: se ve, no se toca. Se edita en Trabajadores,
                     que es donde la persona existe. -->
                <div class="bg-gray-50 border border-gray-200 rounded-xl px-3.5 py-2.5 text-xs text-gray-600 space-y-0.5">
                  <p class="font-semibold text-gray-800">{{ formEmpleado.nombre }}</p>
                  <p>
                    {{ formEmpleado.cargo || 'Sin cargo' }}
                    <span v-if="formEmpleado.cedula">· CC {{ formEmpleado.cedula }}</span>
                  </p>
                  <p class="text-[11px] text-gray-400 pt-1">
                    El nombre, la cédula y el cargo se cambian en Trabajadores.
                  </p>
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

                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Bonificación por producción</label>
                  <select v-model="formEmpleado.nomina_bonificacion_id" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                    <option :value="SIN_BONO">No aplica</option>
                    <option v-for="b in bonosActivos" :key="b.id" :value="b.id">
                      {{ b.nombre }}{{ b.tope_activo ? ` — tope ${formatoPesos(b.tope)}` : ' — sin tope' }}
                    </option>
                  </select>
                  <p class="text-[11px] text-gray-400 mt-1">
                    Si aplica, lo que produzca en el ciclo se compara contra la escalera de ese esquema.
                    Los esquemas se crean en la pestaña Bonos.
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

    <!-- ═══════════ BONOS ═══════════ -->
    <template v-else-if="tab === 'bonos'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">Bonificaciones por producción: un tope y una escalera de metas.</p>
        <button
          @click="abrirNuevoBono"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Nueva
        </button>
      </div>

      <div v-if="cargandoBonos" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else class="space-y-2.5">
        <p v-if="!bonosActivos.length" class="text-center py-8 text-gray-400 text-sm px-6">
          Todavía no hay bonificaciones. Crea una con su tope y sus metas, y después asígnasela a los trabajadores que apliquen.
        </p>

        <div v-for="b in bonosActivos" :key="b.id" class="bg-white rounded-xl shadow-sm overflow-hidden">
          <div class="p-4 flex items-start justify-between gap-2 cursor-pointer" @click="abrirBono(b)">
            <div class="min-w-0 flex items-center gap-2.5">
              <div class="w-9 h-9 rounded-xl bg-purple-50 flex items-center justify-center shrink-0">
                <TrophyIcon class="w-5 h-5 text-purple-600" />
              </div>
              <div class="min-w-0">
                <p class="font-semibold text-sm text-gray-800 truncate">{{ b.nombre }}</p>
                <p class="text-xs text-gray-500 mt-0.5">
                  <span v-if="b.tope_activo">Tope {{ formatoPesos(b.tope) }}</span>
                  <span v-else class="text-amber-600">Tope desactivado</span>
                  · {{ b.periodo_label.toLowerCase() }}
                </p>
                <p class="text-[11px] text-gray-400 mt-0.5">
                  {{ b.metas.filter(m => m.activo).length }} meta(s) · {{ b.num_trabajadores }} trabajador(es)
                </p>
              </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click.stop="abrirEditarBono(b)" class="p-1.5 text-gray-300 hover:text-blue-600 transition-colors" aria-label="Editar">
                <PencilSquareIcon class="w-4 h-4" />
              </button>
              <button @click.stop="borrarBono(b)" class="p-1.5 text-gray-300 hover:text-red-600 transition-colors" aria-label="Eliminar">
                <TrashIcon class="w-4 h-4" />
              </button>
              <component :is="bonoAbierto === b.id ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-300" />
            </div>
          </div>

          <div v-if="bonoAbierto === b.id" class="px-4 pb-4 border-t border-gray-50 pt-3 space-y-3">
            <p class="text-[11px] text-gray-400">
              <span v-if="b.tope_activo">
                Hay que producir al menos {{ formatoPesos(b.tope) }}
                {{ b.periodo === 'ciclo' ? 'en el ciclo de pago' : `por ${b.periodo_label.toLowerCase()}` }}
                para recibir bono.
              </span>
              <span v-else>Sin tope: el bono depende solo de en qué meta caiga lo producido.</span>
            </p>

            <!-- La escalera -->
            <div v-if="b.metas.length" class="space-y-1.5">
              <div
                v-for="m in b.metas" :key="m.id"
                :class="['flex items-center justify-between gap-2 rounded-lg px-2.5 py-2', m.activo ? 'bg-purple-50' : 'bg-gray-50 opacity-60']"
              >
                <div class="min-w-0">
                  <p class="text-xs text-gray-700 truncate">{{ m.etiqueta }}</p>
                  <p class="text-[11px] text-gray-400">paga {{ formatoPesos(m.monto) }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                  <button
                    @click="alternarMeta(m)"
                    :class="['text-[11px] font-semibold', m.activo ? 'text-gray-400 hover:text-amber-600' : 'text-blue-600 hover:text-blue-700']"
                  >{{ m.activo ? 'Desactivar' : 'Activar' }}</button>
                  <button @click="borrarMeta(m)" class="text-gray-300 hover:text-red-600 transition-colors">
                    <XMarkIcon class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>
            <p v-else class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-2">
              Sin metas todavía: aunque lleguen al tope, no se les paga nada.
            </p>

            <!-- Meta nueva -->
            <div v-if="formMeta[b.id]" class="border-t border-gray-50 pt-3">
              <p class="text-[11px] font-semibold text-gray-500 uppercase mb-1.5">Agregar meta</p>
              <div class="grid grid-cols-3 gap-1.5">
                <div>
                  <label class="block text-[10px] text-gray-400 mb-0.5">Desde</label>
                  <InputPesos v-model="formMeta[b.id].desde" class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label class="block text-[10px] text-gray-400 mb-0.5">Hasta</label>
                  <InputPesos v-model="formMeta[b.id].hasta" permite-vacio placeholder="Sin tope" class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label class="block text-[10px] text-gray-400 mb-0.5">Paga</label>
                  <InputPesos v-model="formMeta[b.id].monto" class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <p class="text-[11px] text-gray-400 mt-1.5">Deja "Hasta" vacío para el último escalón (de ahí en adelante).</p>
              <button
                @click="guardarMeta(b)"
                class="w-full mt-2 bg-purple-600 text-white text-xs font-semibold rounded-lg px-3 py-2 hover:bg-purple-700 transition-colors flex items-center justify-center gap-1"
              >
                <PlusIcon class="w-4 h-4" /> Agregar meta
              </button>
            </div>
          </div>
        </div>

        <template v-if="bonosInactivos.length">
          <p class="text-xs font-semibold text-gray-400 uppercase pt-2">Desactivadas</p>
          <div v-for="b in bonosInactivos" :key="b.id" class="bg-gray-50 rounded-xl p-4 flex items-center justify-between gap-2">
            <p class="text-sm text-gray-500 truncate">{{ b.nombre }}</p>
            <button @click="reactivarBono(b)" class="text-xs font-semibold text-blue-600 hover:text-blue-700 shrink-0">Reactivar</button>
          </div>
        </template>
      </div>

      <!-- Nueva / editar bonificación -->
      <Teleport to="body">
        <Transition
          enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
          <div v-if="mostrarFormBono" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormBono = false">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
              <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-xl bg-purple-50 flex items-center justify-center shrink-0">
                    <TrophyIcon class="w-5 h-5 text-purple-600" />
                  </div>
                  <p class="font-semibold text-gray-800">{{ editandoBono ? 'Editar bonificación' : 'Nueva bonificación' }}</p>
                </div>
                <button @click="mostrarFormBono = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>
              <div class="p-5 space-y-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre *</label>
                  <input v-model="formBono.nombre" placeholder="Bonos del mínimo" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  <p class="text-[11px] text-gray-400 mt-1">Este nombre es el que se elige después en cada trabajador.</p>
                </div>

                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cada cuánto se mide el tope? *</label>
                  <select v-model="formBono.periodo" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                    <option v-for="op in PERIODOS_BONO" :key="op.value" :value="op.value">{{ op.label }}</option>
                  </select>
                  <p v-if="formBono.periodo === 'ciclo'" class="text-[11px] text-gray-400 mt-1">
                    El tope se cuenta sobre el ciclo de pago de cada quien: al quincenal se le mide por quincena
                    y al mensual por mes, así que el mismo tope les exige distinto.
                  </p>
                  <p v-else class="text-[11px] text-gray-400 mt-1">
                    El tope se cuenta sobre {{ formBono.periodo === 'mensual' ? 'el mes' : 'la ventana' }} completo,
                    igual para todos. Si alguien cobra más seguido, el bono se le paga una sola vez, en el pago
                    que cierra {{ formBono.periodo === 'mensual' ? 'el mes' : 'esa ventana' }}.
                  </p>
                </div>

                <div class="flex items-center justify-between gap-3 bg-gray-50 rounded-xl px-3.5 py-2.5">
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-700">Usar tope</p>
                    <p class="text-[11px] text-gray-400">Mínimo a producir para recibir bono.</p>
                  </div>
                  <button
                    type="button" @click="formBono.tope_activo = !formBono.tope_activo"
                    :class="['relative w-11 h-6 rounded-full transition-colors shrink-0', formBono.tope_activo ? 'bg-purple-600' : 'bg-gray-300']"
                  >
                    <span :class="['absolute top-0.5 w-5 h-5 bg-white rounded-full transition-all', formBono.tope_activo ? 'left-[22px]' : 'left-0.5']" />
                  </button>
                </div>

                <div v-if="formBono.tope_activo">
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Tope</label>
                  <InputPesos v-model="formBono.tope" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  <p class="text-[11px] text-gray-400 mt-1">
                    Quien no llegue a {{ formatoPesos(formBono.tope) }} en su ciclo no recibe bonificación.
                  </p>
                </div>

                <p class="text-[11px] text-gray-400">
                  Después de guardar, abre la bonificación en la lista para armarle las metas
                  (de tanto a tanto se paga tanto).
                </p>
              </div>
              <div class="flex gap-2.5 p-5 pt-2">
                <button @click="mostrarFormBono = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
                <button
                  @click="guardarBono" :disabled="guardandoBono"
                  class="flex-1 bg-purple-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-purple-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
                >
                  <span v-if="guardandoBono" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                  {{ guardandoBono ? 'Guardando...' : 'Guardar' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>

    <!-- ═══════════ NOVEDADES: producción, faltas y ajustes ═══════════ -->
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
              <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
                <button
                  v-if="empleadoNovedades?.nomina_bonificacion_id"
                  type="button" @click="novedadTab = 'produccion'"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', novedadTab === 'produccion' ? 'bg-white text-purple-600 shadow-sm' : 'text-gray-500']"
                >Producción</button>
                <button
                  type="button" @click="novedadTab = 'falta'"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', novedadTab === 'falta' ? 'bg-white text-amber-600 shadow-sm' : 'text-gray-500']"
                >Falta</button>
                <button
                  type="button" @click="novedadTab = 'ajuste'"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors', novedadTab === 'ajuste' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
                >Bono / préstamo</button>
              </div>
            </div>

            <!-- Registrar producción -->
            <div v-if="novedadTab === 'produccion'" class="p-5 space-y-4 border-b border-gray-100">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué hizo? *</label>
                <input v-model="formProduccion.concepto" placeholder="Mesa de comedor, silla blanca, base cama redonda..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
              </div>
              <div class="grid grid-cols-3 gap-2">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Fecha</label>
                  <input v-model="formProduccion.fecha" type="date" class="w-full rounded-xl border border-gray-200 px-2.5 py-2.5 text-xs text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">C/u</label>
                  <InputPesos v-model="formProduccion.valor_unitario" class="w-full rounded-xl border border-gray-200 px-2.5 py-2.5 text-xs text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Cantidad</label>
                  <input
                    v-model.number="formProduccion.cantidad" type="number" step="1" min="1"
                    class="w-full rounded-xl border border-gray-200 px-2.5 py-2.5 text-xs text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                  />
                </div>
              </div>
              <p class="text-xs text-gray-500 -mt-2">
                Suma <span class="font-bold text-purple-600">{{ formatoPesos(totalProduccionForm) }}</span>
                a la producción del ciclo.
              </p>
              <button
                @click="guardarProduccion" :disabled="guardandoNovedad"
                class="w-full bg-purple-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-purple-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <span v-if="guardandoNovedad" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                <WrenchScrewdriverIcon v-else class="w-4 h-4" />
                {{ guardandoNovedad ? 'Registrando...' : 'Registrar producción' }}
              </button>
            </div>

            <!-- Registrar falta -->
            <div v-else-if="novedadTab === 'falta'" class="p-5 space-y-4 border-b border-gray-100">
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
                <div class="flex flex-wrap gap-1.5 mt-1.5">
                  <button
                    v-for="c in (formAjuste.signo === 1 ? CONCEPTOS_BONO : CONCEPTOS_DESCUENTO)" :key="c"
                    type="button" @click="formAjuste.nombre = c"
                    class="text-[11px] font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-full px-2.5 py-1 transition-colors"
                  >{{ c }}</button>
                </div>
              </div>
              <p class="text-[11px] text-gray-400">
                Entra en el ciclo que contenga esa fecha, igual que una falta.
                Un préstamo se registra como descuento y se resta del pago de ese ciclo.
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
                <p v-if="!historialFaltas.length && !historialAjustes.length && !historialProd.length" class="text-center py-6 text-gray-400 text-sm">
                  Sin producción, faltas ni ajustes registrados todavía.
                </p>

                <div v-for="p in historialProd" :key="'p' + p.id" class="bg-purple-50/60 rounded-xl px-3.5 py-3">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-gray-800 truncate">
                        {{ p.concepto }}<span v-if="p.cantidad !== 1" class="text-gray-500"> × {{ p.cantidad }}</span>
                      </p>
                      <p class="text-xs text-gray-500 mt-0.5">
                        {{ formatoFecha(p.fecha) }} · {{ formatoPesos(p.valor_unitario) }} c/u
                      </p>
                      <p class="text-[11px] text-gray-400 mt-1">Registrada el {{ formatoFechaHora(p.registrada_en) }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                      <p class="text-sm font-semibold text-purple-600">{{ formatoPesos(p.total) }}</p>
                      <button v-if="!p.pagada" @click="quitarProduccion(p.id)" class="text-gray-300 hover:text-red-600 transition-colors">
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                  <p class="text-[11px] font-semibold mt-1.5" :class="p.pagada ? 'text-green-600' : 'text-purple-500'">
                    {{ p.pagada ? `Ya pagada · ${p.ciclo}` : `Suma en: ${p.ciclo}` }}
                  </p>
                </div>

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
                      <span v-if="p.bonificacion">· +{{ formatoPesos(p.bonificacion) }} bono</span>
                    </p>
                    <p v-if="p.bonificacion_nombre" class="text-[11px] text-purple-500 mt-0.5">{{ p.bonificacion_nombre }}</p>
                    <p v-if="p.bonificacion_detalle" class="text-[10px] text-gray-400 mt-0.5">{{ p.bonificacion_detalle }}</p>
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

    <!-- Préstamos del trabajador -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="modalPrestamo" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
          @click.self="modalPrestamo = false">
          <div class="absolute inset-0 bg-black/40" />
          <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto">
            <div class="sticky top-0 bg-white/95 backdrop-blur-sm px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
              <div>
                <h3 class="text-lg font-bold text-gray-800">Préstamos</h3>
                <p class="text-xs text-gray-500 mt-0.5">{{ empPrestamo?.nombre }}</p>
              </div>
              <button @click="modalPrestamo = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100 shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-4">
              <!-- Lo que ya debe -->
              <div v-if="cargandoPrest" class="flex justify-center py-4">
                <div class="w-5 h-5 border-2 border-purple-500 border-t-transparent rounded-full animate-spin" />
              </div>
              <div v-else-if="prestamosDe.length" class="space-y-2">
                <p class="text-[11px] font-semibold text-gray-400 uppercase">Lo que se le está descontando</p>
                <div v-for="pr in prestamosDe" :key="pr.id"
                  :class="['rounded-xl border p-3', pr.saldado ? 'bg-gray-50 border-gray-200' : 'bg-purple-50 border-purple-200']">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-gray-800 truncate">
                        {{ pr.motivo || 'Préstamo' }} · {{ formatoPesos(pr.monto) }}
                      </p>
                      <p class="text-[11px] text-gray-500 mt-0.5">
                        {{ formatoPesos(pr.valor_cuota) }} por pago · {{ pr.cuotas_pagadas }} de {{ pr.cuotas }} cuotas
                      </p>
                    </div>
                    <div class="text-right shrink-0">
                      <p v-if="pr.saldado" class="text-xs font-bold text-green-600">Saldado</p>
                      <template v-else>
                        <p class="text-[11px] text-gray-400">Queda</p>
                        <p class="text-sm font-bold text-purple-700">{{ formatoPesos(pr.saldo) }}</p>
                      </template>
                    </div>
                  </div>
                  <div v-if="!pr.saldado" class="flex gap-2 mt-2 pt-2 border-t border-purple-100">
                    <button @click="pausarPrestamo(pr)" class="text-[11px] font-semibold text-gray-600 hover:text-gray-800">
                      {{ pr.activo ? 'Pausar el descuento' : 'Reanudar' }}
                    </button>
                    <button v-if="!pr.cuotas_pagadas" @click="quitarPrestamo(pr)"
                      class="text-[11px] font-semibold text-red-500 hover:text-red-700 ml-auto">
                      Borrar
                    </button>
                  </div>
                  <p v-else-if="!pr.activo" class="text-[11px] text-gray-400 mt-1">Pausado</p>
                </div>
              </div>
              <p v-else class="text-xs text-gray-400 text-center py-2">No tiene préstamos.</p>

              <!-- Uno nuevo -->
              <div class="border-t border-gray-100 pt-4 space-y-3">
                <p class="text-[11px] font-semibold text-gray-400 uppercase">Prestarle</p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Cuánto</label>
                    <input v-model="formPrestamo.monto" type="number" min="0" inputmode="numeric" placeholder="200000"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
                  </div>
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">En cuántos pagos</label>
                    <input v-model="formPrestamo.cuotas" type="number" min="1" inputmode="numeric" placeholder="4"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
                  </div>
                </div>
                <input v-model="formPrestamo.motivo" type="text" maxlength="160" placeholder="Motivo (opcional)"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />

                <p v-if="cuotaCalculada" class="text-xs text-purple-800 bg-purple-50 border border-purple-200 rounded-lg px-3 py-2">
                  Se le descontarán <strong>{{ formatoPesos(cuotaCalculada) }}</strong> en cada pago,
                  hasta completar {{ formatoPesos(Number(formPrestamo.monto) || 0) }}.
                  La última cuota se ajusta para no cobrar de más.
                </p>

                <button @click="guardarPrestamo" :disabled="guardandoPrest"
                  class="w-full bg-purple-600 text-white text-sm font-semibold rounded-xl py-2.5 hover:bg-purple-700 disabled:opacity-50 transition-colors">
                  {{ guardandoPrest ? 'Guardando...' : 'Registrar préstamo' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- A quién le vamos a pagar -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="modalAgregar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
          @click.self="modalAgregar = false">
          <div class="absolute inset-0 bg-black/40" />
          <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto">
            <div class="sticky top-0 bg-white/95 backdrop-blur-sm px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
              <div>
                <h3 class="text-lg font-bold text-gray-800">Agregar a nómina</h3>
                <p class="text-xs text-gray-500 mt-0.5">Elige a varios y ponles el mismo sueldo de una vez</p>
              </div>
              <button @click="modalAgregar = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100 shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-4">
              <div v-if="cargandoAgregar" class="flex justify-center py-6">
                <div class="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
              </div>

              <template v-else-if="porAgregar.length">
                <div class="relative">
                  <input v-model="busquedaAgregar" type="text" placeholder="Buscar trabajador..."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>

                <div class="flex items-center justify-between">
                  <button @click="seleccionarTodos" class="text-xs font-semibold text-blue-600 hover:text-blue-700">
                    {{ seleccionados.length === porAgregarFiltrados.length ? 'Quitar todos' : 'Elegir todos' }}
                  </button>
                  <span class="text-xs text-gray-400">{{ seleccionados.length }} elegido(s)</span>
                </div>

                <div class="max-h-56 overflow-y-auto border border-gray-100 rounded-xl divide-y divide-gray-100">
                  <button v-for="u in porAgregarFiltrados" :key="u.id" type="button" @click="alternarSeleccion(u.id)"
                    class="w-full flex items-center gap-2.5 px-3 py-2.5 hover:bg-blue-50 text-left transition-colors">
                    <input type="checkbox" :checked="seleccionados.includes(u.id)" tabindex="-1"
                      class="rounded border-gray-300 text-blue-600 pointer-events-none" />
                    <div class="min-w-0">
                      <p class="text-sm text-gray-800 truncate">{{ u.nombre }}</p>
                      <p class="text-[11px] text-gray-400">{{ u.rol_nombre ?? u.rol }}</p>
                    </div>
                  </button>
                  <p v-if="!porAgregarFiltrados.length" class="text-xs text-gray-400 text-center py-4">
                    Nadie con ese nombre.
                  </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Sueldo</label>
                    <select v-model="loteSueldo"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                      <option value="">Elige...</option>
                      <option v-for="sd in sueldosActivos" :key="sd.id" :value="sd.id">{{ sd.nombre }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1">Cada cuánto cobra</label>
                    <select v-model="lotePeriodo"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                      <option value="diario">Diario</option>
                      <option value="semanal">Semanal</option>
                      <option value="quincenal">Quincenal</option>
                      <option value="20_dias">Cada 20 días</option>
                      <option value="mensual">Mensual</option>
                    </select>
                  </div>
                </div>

                <p v-if="!sueldosActivos.length" class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5">
                  No hay sueldos cargados todavía. Créalos primero en la pestaña de Sueldos.
                </p>

                <button @click="guardarLote" :disabled="guardandoLote || !seleccionados.length || !loteSueldo"
                  class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 hover:bg-blue-700 disabled:opacity-50 transition-colors">
                  {{ guardandoLote ? 'Guardando...' : `Agregar ${seleccionados.length || ''} a nómina` }}
                </button>
              </template>

              <p v-else class="text-sm text-gray-500 text-center py-6">
                Ya están todos en nómina.
              </p>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
