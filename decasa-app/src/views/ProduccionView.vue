<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { useTiposProceso } from '@/composables/useTiposProceso'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import { MapPinIcon, Cog6ToothIcon, UserGroupIcon } from '@heroicons/vue/24/outline'
import { MapPinIcon as MapPinSolid } from '@heroicons/vue/24/solid'
import {
  MagnifyingGlassIcon,
  FunnelIcon,
  CheckCircleIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
  NoSymbolIcon,
  CalendarDaysIcon,
  TableCellsIcon,
} from '@heroicons/vue/24/outline'
import { getProduccion, updateProduccion } from '@/api/produccion'
import { formatoDuracion } from '@/utils/duracion'
import { exportarExcel } from '@/utils/exportarExcel'
import { useToast } from '@/composables/useToast'
import { getTiendas, fijarOrden, quitarFijada } from '@/api/ordenes'
import { useRealtime } from '@/composables/useRealtime'
import EmptyState from '@/components/common/EmptyState.vue'
import ProcesosModal from '@/components/produccion/ProcesosModal.vue'
import DevolucionesPendientes from '@/components/produccion/DevolucionesPendientes.vue'
import { SPECS_TEMPLATES, resolverCategoria } from '@/constants/specsConfig'

const auth   = useAuthStore()
const router = useRouter()
const toast  = useToast()

const producciones = ref([])
const tiendas = ref([])
const loading = ref(true)
const loadingMore = ref(false)
const hasMore = ref(true)
const currentPage = ref(1)
const showFilters = ref(false)

// Ordenar por lo que se entrega antes, de lo más próximo a lo más lejano, sin
// agrupar por estado: agrupando, lo de mañana que ya está en proceso queda
// debajo de lo de dentro de un mes que sigue pendiente.
const porEntregar = ref(false)

function alternarPorEntregar() {
  porEntregar.value = !porEntregar.value
  fetchProduccion(1)
}
const busqueda = ref('')

const sentinel = ref(null)
let observer = null

const filtros = ref({
  estado: '',
  tienda_id: '',
})

const mostrarModal = ref(false)
const produccionSeleccionada = ref(null)
const nuevoEstado = ref('')
const motivoRetraso = ref('')
const modalLoading = ref(false)

// Pasos de producción (para cuando se cambia a en_proceso)
// Los procesos los mantiene el taller desde el boton "Procesos"
const { tipos: tiposProceso, cargar: cargarTipos, nombre: nombreProceso, clases: clasesProceso } = useTiposProceso()
const PROCESOS_DISPONIBLES = computed(() =>
  [...tiposProceso.value]
    .filter(t => t.activo)
    .sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0))
    .map(t => ({ tipo: t.clave, label: t.nombre, desc: t.descripcion ?? '' }))
)
const showProcesos = ref(false)
const pasosSeleccionados = ref([]) // [{tipo_proceso, orden}] en orden de selección
const pasoSelectorRef   = ref(null)

watch(nuevoEstado, (val) => {
  if (val === 'en_proceso') {
    nextTick(() => pasoSelectorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))
  }
})

function togglePaso(tipo) {
  const idx = pasosSeleccionados.value.findIndex(p => p.tipo_proceso === tipo)
  if (idx !== -1) {
    pasosSeleccionados.value.splice(idx, 1)
    // Recalcular órdenes
    pasosSeleccionados.value = pasosSeleccionados.value.map((p, i) => ({ ...p, orden: i + 1 }))
  } else {
    pasosSeleccionados.value.push({ tipo_proceso: tipo, orden: pasosSeleccionados.value.length + 1 })
  }
}

function ordenDePaso(tipo) {
  return pasosSeleccionados.value.find(p => p.tipo_proceso === tipo)?.orden ?? null
}

const estadosOpts = [
  { value: '',                     label: 'Todos' },
  { value: 'pendiente',            label: 'Pendiente' },
  { value: 'en_proceso',           label: 'En proceso' },
  { value: 'pendiente_despachador',label: 'En despacho prod.' },
  { value: 'listo',                label: 'Listo' },
  { value: 'retrasado',            label: 'Retrasado' },
  { value: 'entregado',            label: 'Entregado' },
  { value: 'cancelado',            label: 'Cancelado' },
]

function specsResumen(item) {
  const specs = item?.specs_personalizacion
  if (!specs) return ''
  const cat      = item.producto?.categoria || item.categoria_custom
  const template = SPECS_TEMPLATES[resolverCategoria(cat)] ?? SPECS_TEMPLATES['generico']
  const partes   = []
  for (const campo of template.campos) {
    const val = specs[campo.key]
    if (val === null || val === undefined || val === '') continue
    // Sin unidad: el valor guardado no siempre está en la unidad del template
    // (a veces se digita en metros aunque el campo se llame "_cm").
    partes.push(`${campo.label}: ${val}`)
  }
  if (specs.notas) partes.push(`Notas: ${specs.notas}`)
  return partes.join(' · ')
}

function pasoActualLabel(p) {
  if (!p.pasos || p.pasos.length === 0) return null
  const activo = p.pasos.find(x => x.estado === 'en_proceso')
  if (activo) return { label: labelProceso(activo.tipo_proceso), cls: 'bg-blue-100 text-blue-700' }
  const pendiente = p.pasos.find(x => x.estado === 'pendiente')
  if (pendiente) return { label: `Próx: ${labelProceso(pendiente.tipo_proceso)}`, cls: 'bg-gray-100 text-gray-500' }
  const todos = p.pasos.every(x => x.estado === 'completado')
  if (todos && p.estado !== 'listo') return { label: 'Todos los pasos listos', cls: 'bg-green-100 text-green-700' }
  return null
}

/** El paso que se está haciendo ahora, si hay alguno. */
function pasoEnCurso(p) {
  return (p.pasos ?? []).find(x => x.estado === 'en_proceso') ?? null
}

/**
 * Los nombres de quienes están (o estuvieron) en un paso.
 *
 * Los pasos viejos sólo tienen la lista de nombres escritos a mano; se
 * respetan para que una orden anterior al cambio no se quede sin nadie.
 */
function nombresDePaso(paso) {
  const reales = (paso?.participantes ?? []).map(x => x.usuario?.nombre ?? x.nombre).filter(Boolean)
  return reales.length ? reales : (paso?.trabajadores ?? [])
}

function horasDePaso(paso) {
  const con = (paso?.participantes ?? []).filter(x => x.horas != null)
  if (!con.length) return null
  return con.reduce((s, x) => s + Number(x.horas), 0)
}

function labelProceso(tipo) {
  const m = new Proxy({}, { get: (_, k) => nombreProceso(String(k)) })
  return m[tipo] ?? tipo
}

function badgeInfo(p) {
  if (p.estado === 'cancelado') {
    return { label: 'Cancelado', cls: 'bg-gray-100 text-gray-500' }
  }
  if (p.estado === 'pendiente') {
    return { label: 'Pendiente', cls: 'bg-yellow-100 text-yellow-700' }
  }
  if (p.estado === 'pendiente_despachador') {
    return { label: 'En despacho prod.', cls: 'bg-purple-100 text-purple-700' }
  }
  if (p.estado === 'retrasado' || (p.estado === 'en_proceso' && p.dias_restantes !== null && p.dias_restantes < 0)) {
    return { label: 'Retrasado', cls: 'bg-red-100 text-red-700' }
  }
  const labels = {
    en_proceso: { label: 'En proceso',       cls: 'bg-green-100 text-green-700' },
    listo:      { label: 'Listo p/ entrega', cls: 'bg-blue-100 text-blue-700' },
    entregado:  { label: 'Entregado',        cls: 'bg-gray-100 text-gray-500' },
  }
  return labels[p.estado] || { label: p.estado, cls: 'bg-gray-100 text-gray-500' }
}

function estadoIcon(p) {
  if (p.estado === 'cancelado') return NoSymbolIcon
  if (p.estado === 'entregado' || p.estado === 'listo') return CheckCircleIcon
  if (p.estado === 'retrasado' || (p.estado === 'en_proceso' && p.dias_restantes !== null && p.dias_restantes < 0)) return ExclamationTriangleIcon
  return ClockIcon
}

async function loadTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
}

/**
 * Fijar la ORDEN de este paso, no el paso: en el taller uno dice "esta orden
 * primero" y asi suben juntas todas sus piezas. Se vuelve a pedir la lista
 * porque viene paginada y la fijada puede estar en otra pagina.
 */
async function toggleFijada(p) {
  const ordenId = p.orden_item?.orden?.id
  if (!ordenId) return
  const antes = !!p.fijada
  p.fijada = !antes
  try {
    if (antes) await quitarFijada(ordenId)
    else       await fijarOrden(ordenId)
    await fetchProduccion(1, false)
  } catch {
    p.fijada = antes
    toast.error('No se pudo cambiar la fijacion.')
  }
}

async function fetchProduccion(page = 1, append = false) {
  if (page === 1) {
    loading.value = true
  } else {
    loadingMore.value = true
  }

  try {
    const params = { page }
    if (filtros.value.estado) params.estado = filtros.value.estado
    if (filtros.value.tienda_id) params.tienda_id = filtros.value.tienda_id
    if (busqueda.value) params.search = busqueda.value
    // Por lo que se entrega primero, sin agrupar por estado: la pregunta es
    // qué sale esta semana. El backend deja fuera lo ya entregado.
    if (porEntregar.value) params.orden = 'entrega'

    const { data } = await getProduccion(params)

    const list = data.data ?? []
    if (append) {
      producciones.value = [...producciones.value, ...list]
    } else {
      producciones.value = list
    }

    hasMore.value = data.current_page < data.last_page
    currentPage.value = data.current_page
  } catch {
    if (page === 1) producciones.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function setupObserver() {
  if (observer) observer.disconnect()

  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && hasMore.value && !loadingMore.value) {
      loadMore()
    }
  }, { rootMargin: '200px' })

  nextTick(() => {
    if (sentinel.value) observer.observe(sentinel.value)
  })
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  await fetchProduccion(currentPage.value + 1, true)
}

function openModal(p) {
  produccionSeleccionada.value = p
  nuevoEstado.value = p.estado
  motivoRetraso.value = ''
  pasosSeleccionados.value = []
  // Si ya tiene pasos, pre-cargarlos
  if (p.pasos && p.pasos.length > 0) {
    pasosSeleccionados.value = p.pasos
      .filter(x => x.estado !== 'completado')
      .map(x => ({ tipo_proceso: x.tipo_proceso, orden: x.orden }))
      .sort((a, b) => a.orden - b.orden)
  }
  mostrarModal.value = true
}

async function guardarEstado() {
  if (!nuevoEstado.value) return
  if (nuevoEstado.value === 'retrasado' && !motivoRetraso.value.trim()) {
    toast.error('Debes indicar el motivo del retraso.')
    return
  }
  if (nuevoEstado.value === 'en_proceso' && pasosSeleccionados.value.length === 0) {
    toast.error('Debes seleccionar al menos un proceso de producción.')
    return
  }

  modalLoading.value = true
  try {
    const data = { estado: nuevoEstado.value }
    if (motivoRetraso.value.trim()) {
      data.motivo_retraso = motivoRetraso.value.trim()
    }
    if (nuevoEstado.value === 'en_proceso') {
      data.pasos = pasosSeleccionados.value
    }
    await updateProduccion(produccionSeleccionada.value.id, data)
    mostrarModal.value = false
    await fetchProduccion(1, false)
    setupObserver()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al actualizar el estado.')
  } finally {
    modalLoading.value = false
  }
}

function diasInfo(p) {
  const d = p.dias_restantes
  if (d === null || d === undefined) return null

  const inicio = new Date(String(p.fecha_inicio).substring(0, 10) + 'T00:00:00')
  const fin    = new Date(String(p.fecha_compromiso).substring(0, 10) + 'T00:00:00')
  const total  = Math.max(1, Math.round((fin - inicio) / 86400000))
  const mitad  = total / 2

  let cls, texto
  if (d <= 0) {
    cls   = 'bg-red-100 text-red-700'
    texto = d === 0 ? 'Vence hoy' : `${Math.abs(d)} día${Math.abs(d) !== 1 ? 's' : ''} de retraso`
  } else if (d <= 5) {
    cls   = 'bg-red-100 text-red-700'
    texto = `${d} día${d !== 1 ? 's' : ''} restante${d !== 1 ? 's' : ''}`
  } else if (d < mitad) {
    cls   = 'bg-yellow-100 text-yellow-700'
    texto = `${d} días restantes`
  } else {
    cls   = 'bg-green-100 text-green-700'
    texto = `${d} días restantes`
  }

  return { cls, texto }
}

function formatFecha(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(String(dateStr).substring(0, 10) + 'T00:00:00')
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function applyFilters() {
  showFilters.value = false
  currentPage.value = 1
  fetchProduccion(1, false)
  setupObserver()
}

function clearFilters() {
  filtros.value = { estado: '', tienda_id: '' }
  busqueda.value = ''
  showFilters.value = false
  currentPage.value = 1
  fetchProduccion(1, false)
  setupObserver()
}

function buscar() {
  currentPage.value = 1
  fetchProduccion(1, false)
  setupObserver()
}

// ── Hoja para la fábrica ──────────────────────────────────────────────────────
//
// El taller trabaja en papel: alguien imprime lo que hay que sacar y lo pega en
// la pared. Por eso se puede escoger a mano cuáles van —"estas cinco son las de
// esta semana"— o sacar de un golpe todo lo que está en el taller ahora.

const exportando  = ref(false)
const modoElegir  = ref(false)
/** Lo marcado, por id. Se guarda la pieza entera: la lista se recarga sola
 *  (realtime, scroll) y perder lo elegido a mitad de camino enfurece. */
const elegidas    = ref(new Map())
const mostrarExportar = ref(false)

function toggleElegida(p) {
  if (elegidas.value.has(p.id)) elegidas.value.delete(p.id)
  else elegidas.value.set(p.id, p)
}

function elegirTodasVisibles() {
  for (const p of producciones.value) elegidas.value.set(p.id, p)
}

function limpiarEleccion() {
  elegidas.value.clear()
}

function empezarAElegir() {
  mostrarExportar.value = false
  modoElegir.value = true
}

function salirDeElegir() {
  modoElegir.value = false
  limpiarEleccion()
}

/** En modo elegir la tarjeta marca; si no, abre la orden como siempre. */
function clickTarjeta(p) {
  if (modoElegir.value) return toggleElegida(p)
  const id = p.orden_item?.orden?.id
  if (id) router.push({ name: 'orden-detalle', params: { id } })
}

/** En qué va la pieza, dicho para alguien que la va a leer en un papel. */
function pasoTextoExcel(p) {
  const pasos = p.pasos ?? []
  if (!pasos.length) return 'Sin pasos definidos'

  const enCurso = pasos.find(x => x.estado === 'en_proceso')
  if (enCurso) return labelProceso(enCurso.tipo_proceso)

  const pendiente = pasos.find(x => x.estado === 'pendiente')
  if (pendiente) return `Por empezar: ${labelProceso(pendiente.tipo_proceso)}`

  return pasos.every(x => x.estado === 'completado') ? 'Todos los pasos listos' : '—'
}

function avanceTexto(p) {
  const pasos = p.pasos ?? []
  if (!pasos.length) return ''
  const hechos = pasos.filter(x => x.estado === 'completado').length
  return `${hechos} de ${pasos.length}`
}

function fechaCorta(dateStr) {
  if (!dateStr) return ''
  return new Date(String(dateStr).substring(0, 10) + 'T00:00:00').toLocaleDateString('es-CO')
}

function filaExcel(p) {
  return {
    'Orden':            p.orden_item?.orden?.referencia ?? ('#' + (p.orden_item?.orden?.numero_orden ?? p.orden_item?.orden?.id ?? '')),
    'Cliente':          p.orden_item?.orden?.cliente?.nombre ?? '',
    'Producto':         p.orden_item?.producto?.nombre || p.orden_item?.nombre_custom || '',
    // La medida o la tela que se vendió: "CAMA MIAMI" sola no se puede fabricar.
    'Variante':         p.orden_item?.variante_texto ?? '',
    'Cantidad':         Number(p.orden_item?.cantidad) || 1,
    'Paso actual':      pasoTextoExcel(p),
    'Avance':           avanceTexto(p),
    'Fecha de entrega': fechaCorta(p.fecha_compromiso),
    'Estado':           badgeInfo(p).label,
    'Tienda':           p.orden_item?.orden?.tienda?.nombre ?? '',
    // Medidas y acabados: sin esto el papel no sirve para fabricar.
    'Detalle':          specsResumen(p.orden_item),
  }
}

/** Lo que sale primero va arriba: es el orden en que se trabaja. */
function porFechaDeEntrega(a, b) {
  const fa = a.fecha_compromiso ?? '9999-12-31'
  const fb = b.fecha_compromiso ?? '9999-12-31'
  return String(fa).localeCompare(String(fb))
}

function bajarExcel(lista) {
  if (!lista.length) {
    toast.error('No hay piezas para poner en la hoja.')
    return false
  }
  exportarExcel([...lista].sort(porFechaDeEntrega).map(filaExcel), {
    nombreArchivo: 'produccion_fabrica',
    hoja: 'Producción',
  })
  return true
}

/** Todas las páginas de un filtro, no sólo lo que alcanzó a cargar la pantalla. */
async function traerTodo(params) {
  const todas = []
  let page = 1
  let lastPage = 1
  do {
    const { data } = await getProduccion({ ...params, page })
    todas.push(...(data.data ?? []))
    lastPage = data.last_page ?? 1
    page++
  } while (page <= lastPage)
  return todas
}

/**
 * Lo que está en el taller ahora: arrancado y todavía adentro. Se piden los dos
 * estados por separado —y no todo sin filtro— porque "todo" arrastra el
 * histórico entero de piezas ya entregadas.
 */
async function excelDelTaller() {
  if (exportando.value) return
  exportando.value = true
  try {
    const base = {}
    if (filtros.value.tienda_id) base.tienda_id = filtros.value.tienda_id
    if (busqueda.value)          base.search    = busqueda.value

    const [enProceso, retrasadas] = await Promise.all([
      traerTodo({ ...base, estado: 'en_proceso' }),
      traerTodo({ ...base, estado: 'retrasado' }),
    ])

    if (bajarExcel([...enProceso, ...retrasadas])) mostrarExportar.value = false
  } catch {
    toast.error('No se pudo generar el Excel. Intenta de nuevo.')
  } finally {
    exportando.value = false
  }
}

/** Lo mismo que se está viendo en pantalla, con sus filtros y todas sus páginas. */
async function excelConFiltros() {
  if (exportando.value) return
  exportando.value = true
  try {
    const params = {}
    if (filtros.value.estado)    params.estado    = filtros.value.estado
    if (filtros.value.tienda_id) params.tienda_id = filtros.value.tienda_id
    if (busqueda.value)          params.search    = busqueda.value
    if (porEntregar.value)       params.orden     = 'entrega'

    if (bajarExcel(await traerTodo(params))) mostrarExportar.value = false
  } catch {
    toast.error('No se pudo generar el Excel. Intenta de nuevo.')
  } finally {
    exportando.value = false
  }
}

/** Sólo las marcadas a mano, con lo último que se sepa de cada una. */
function excelDeLoElegido() {
  const lista = [...elegidas.value.entries()].map(
    ([id, guardada]) => producciones.value.find(x => x.id === id) ?? guardada
  )
  if (bajarExcel(lista)) salirDeElegir()
}

const { listen } = useRealtime()

onMounted(async () => {
  cargarTipos()   // nombres y colores de los procesos
  await loadTiendas()
  await fetchProduccion(1, false)
  setupObserver()

  listen('produccion', 'produccion.actualizada', () => {
    fetchProduccion(1, false)
    setupObserver()
  })
})

onUnmounted(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header. Envuelve porque en un teléfono no caben los cuatro botones. -->
    <div class="flex items-center flex-wrap gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Producción</h2>
      <button
        @click="alternarPorEntregar"
        :title="porEntregar ? 'Volver al orden por estado' : 'Ver primero lo que se entrega antes'"
        :class="['text-sm font-medium px-3 py-1.5 rounded-lg border transition-colors flex items-center gap-1',
          porEntregar
            ? 'bg-amber-500 text-white border-amber-500 hover:bg-amber-600'
            : 'text-amber-700 border-amber-200 hover:bg-amber-50']"
      >
        <CalendarDaysIcon class="w-4 h-4" />
        Por entregar
      </button>
      <button
        @click="mostrarExportar = true"
        class="text-sm text-green-700 font-medium px-3 py-1.5 rounded-lg border border-green-200 hover:bg-green-50 transition-colors flex items-center gap-1"
        title="Sacar la hoja de trabajo para la fábrica"
      >
        <TableCellsIcon class="w-4 h-4" />
        Excel
      </button>
      <button
        @click="showFilters = !showFilters"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors flex items-center gap-1"
      >
        <FunnelIcon class="w-4 h-4" />
        {{ showFilters ? 'Cerrar' : 'Filtros' }}
      </button>
      <button
        v-if="auth.gestionaProduccion"
        @click="showProcesos = true"
        class="text-sm text-gray-600 font-medium px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors flex items-center gap-1"
        title="Crear o cambiar los procesos del taller"
      >
        <Cog6ToothIcon class="w-4 h-4" />
        Procesos
      </button>
    </div>

    <!-- Lo que volvió del camión y espera decisión. Va arriba de todo: mientras
         nadie resuelva, hay un mueble parado y un cliente esperando. Al
         resolverla, la pieza que vuelve al taller aparece en la lista de abajo,
         así que se recarga. -->
    <DevolucionesPendientes @resuelta="fetchProduccion(1)" />

    <p v-if="!auth.gestionaProduccion" class="text-[11px] text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-1.5">
      Estás viendo en qué va el taller. Para arrancar procesos o cambiarle el estado a
      una pieza hace falta el permiso de gestionar producción.
    </p>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="buscar"
        placeholder="Buscar por producto o cliente..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtros -->
    <div v-if="showFilters" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
        <select v-model="filtros.estado" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option v-for="e in estadosOpts" :key="e.value" :value="e.value">{{ e.label }}</option>
        </select>
      </div>
      <div v-if="auth.isSupervisor">
        <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
        <select v-model="filtros.tienda_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
      </div>
      <div class="flex gap-2">
        <button @click="clearFilters" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2 text-sm font-semibold hover:bg-gray-200">Limpiar</button>
        <button @click="applyFilters" class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700">Aplicar</button>
      </div>
    </div>

    <!-- Elegir a mano cuáles van en la hoja. Va pegado arriba: se marca
         bajando por la lista y el botón de generar tiene que seguir a la vista. -->
    <div v-if="modoElegir" class="sticky top-0 z-20 bg-green-700 text-white rounded-xl px-3 py-2.5 shadow-md space-y-2">
      <div class="flex items-center gap-2">
        <p class="text-sm font-semibold flex-1">
          {{ elegidas.size }} pieza{{ elegidas.size === 1 ? '' : 's' }} para la hoja
        </p>
        <button @click="elegirTodasVisibles" class="text-xs bg-white/15 hover:bg-white/25 rounded-lg px-2 py-1">Toda la lista</button>
        <button @click="limpiarEleccion" class="text-xs bg-white/15 hover:bg-white/25 rounded-lg px-2 py-1">Ninguna</button>
      </div>
      <div class="flex gap-2">
        <button @click="salirDeElegir" class="flex-1 text-sm bg-white/15 hover:bg-white/25 rounded-lg py-1.5 font-medium">
          Cancelar
        </button>
        <button
          @click="excelDeLoElegido"
          :disabled="!elegidas.size"
          class="flex-[2] text-sm bg-white text-green-700 rounded-lg py-1.5 font-bold disabled:opacity-50"
        >
          Generar Excel
        </button>
      </div>
      <p class="text-[11px] text-white/70">Toca las piezas de la lista para marcarlas.</p>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Empty -->
    <EmptyState
      v-else-if="producciones.length === 0"
      :message="busqueda ? 'No se encontraron pedidos.' : 'No hay pedidos en producción.'"
    />

    <!-- Lista -->
    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="p in producciones"
          :key="p.id"
          :class="['rounded-xl shadow-sm p-4 space-y-2 cursor-pointer active:scale-[0.99] transition-transform',
            modoElegir && elegidas.has(p.id) ? 'bg-green-50 ring-2 ring-green-500'
              : p.fijada ? 'bg-amber-50 border-l-4 border-amber-400' : 'bg-white']"
          @click="clickTarjeta(p)"
        >
          <!-- Producto + badge de estado -->
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800 truncate flex items-center gap-1.5">
                <span
                  v-if="modoElegir"
                  :class="['w-5 h-5 rounded-md border-2 flex-shrink-0 flex items-center justify-center',
                    elegidas.has(p.id) ? 'bg-green-600 border-green-600 text-white' : 'border-gray-300']"
                >
                  <CheckCircleIcon v-if="elegidas.has(p.id)" class="w-4 h-4" />
                </span>
                <button
                  v-else
                  type="button"
                  @click.stop="toggleFijada(p)"
                  :class="['flex-shrink-0 transition-colors', p.fijada ? 'text-amber-500' : 'text-gray-300 hover:text-amber-400']"
                  :title="p.fijada ? 'Quitar de arriba' : 'Fijar arriba'"
                >
                  <MapPinSolid v-if="p.fijada" class="w-4 h-4" />
                  <MapPinIcon v-else class="w-4 h-4" />
                </button>
                {{ p.orden_item?.producto?.nombre || p.orden_item?.nombre_custom }}
              </p>
              <p class="text-xs text-gray-400 flex items-center gap-1.5 flex-wrap">
                {{ p.orden_item?.producto?.categoria || p.orden_item?.categoria_custom }}
                <!-- Restaurar el mueble del cliente no es hacer uno nuevo, y
                     desde que cada línea puede tener su encargado hay que
                     distinguirlas de un vistazo en el tablero. -->
                <span v-if="p.orden_item?.es_restauracion"
                  class="inline-block text-[11px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                  🛠️ Restauración
                </span>
              </p>
              <p v-if="specsResumen(p.orden_item)" class="text-xs text-indigo-600 mt-0.5 truncate">{{ specsResumen(p.orden_item) }}</p>
            </div>
            <span
              :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 ml-2', badgeInfo(p).cls]"
            >
              <component :is="estadoIcon(p)" class="w-3.5 h-3.5" />
              {{ badgeInfo(p).label }}
            </span>
          </div>

          <!-- Paso actual de producción -->
          <div v-if="pasoActualLabel(p)" class="flex items-center gap-1.5">
            <span class="text-xs text-gray-400">Paso:</span>
            <span :class="['text-xs font-medium px-2 py-0.5 rounded-full', pasoActualLabel(p).cls]">
              {{ pasoActualLabel(p).label }}
            </span>
            <!-- Mini progreso de pasos -->
            <div v-if="p.pasos && p.pasos.length > 0" class="flex gap-1 ml-auto">
              <span
                v-for="paso in p.pasos"
                :key="paso.id"
                :class="[
                  'inline-block w-5 h-1.5 rounded-full',
                  paso.estado === 'completado' ? 'bg-green-400' :
                  paso.estado === 'en_proceso'  ? 'bg-blue-400' :
                  paso.estado === 'cancelado'   ? 'bg-red-200' :
                  'bg-gray-200'
                ]"
                :title="labelProceso(paso.tipo_proceso)
                  + (paso.estado === 'cancelado' ? ' (cancelado)' : '')
                  + (paso.trabajadores?.length ? ': ' + paso.trabajadores.join(', ') : '')"
              />
            </div>
          </div>

          <!-- Quién está haciendo el paso ahora mismo -->
          <div
            v-if="pasoEnCurso(p) && nombresDePaso(pasoEnCurso(p)).length"
            class="flex items-center gap-1.5 text-xs bg-blue-50 border border-blue-100 rounded-lg px-2 py-1"
          >
            <UserGroupIcon class="w-3.5 h-3.5 text-blue-500 shrink-0" />
            <span class="text-blue-500">Trabajando ahora:</span>
            <span class="text-blue-800 font-semibold truncate">{{ nombresDePaso(pasoEnCurso(p)).join(', ') }}</span>
          </div>

          <!-- Responsables de pasos completados -->
          <div
            v-if="p.pasos?.some(paso => paso.estado === 'completado' && nombresDePaso(paso).length)"
            class="space-y-0.5"
          >
            <template v-for="paso in p.pasos.filter(x => x.estado === 'completado' && nombresDePaso(x).length)" :key="paso.id">
              <div class="flex items-center gap-1.5 text-xs">
                <span class="text-gray-400">{{ labelProceso(paso.tipo_proceso) }}:</span>
                <span class="text-gray-600 font-medium">{{ nombresDePaso(paso).join(', ') }}</span>
                <span v-if="horasDePaso(paso) != null" class="text-gray-400">· {{ formatoDuracion(horasDePaso(paso)) }}</span>
              </div>
            </template>
          </div>

          <!-- Info -->
          <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
            <div>
              <p class="text-gray-400">Cliente</p>
              <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.cliente?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Teléfono</p>
              <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.cliente?.telefono }}</p>
            </div>
            <div>
              <p class="text-gray-400">Vendedor</p>
              <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.vendedor?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Tienda</p>
              <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.tienda?.nombre }}</p>
            </div>
          </div>

          <!-- Fechas + días restantes -->
          <div class="flex justify-between items-center text-xs pt-1 border-t border-gray-100">
            <span class="text-gray-400">Compromiso: <span class="font-medium text-gray-600">{{ formatFecha(p.fecha_compromiso) }}</span></span>
            <span
              v-if="p.estado !== 'entregado' && diasInfo(p)"
              :class="['inline-block px-2 py-0.5 rounded-full font-semibold', diasInfo(p).cls]"
            >{{ diasInfo(p).texto }}</span>
            <span v-else-if="p.estado === 'entregado'" class="text-gray-400 italic">Entregado</span>
          </div>
          <button
            v-if="auth.gestionaProduccion && !modoElegir && !['entregado', 'cancelado'].includes(p.estado)"
            @click.stop="openModal(p)"
            class="w-full mt-2 text-blue-600 text-xs font-medium text-center py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors"
          >
            Cambiar estado
          </button>
        </li>
      </ul>

      <!-- Sentinel para scroll infinito -->
      <div ref="sentinel" class="py-4 text-center">
        <div v-if="loadingMore" class="text-sm text-gray-400">Cargando más...</div>
        <div v-else-if="!hasMore && producciones.length > 0" class="text-xs text-gray-300">No hay más pedidos.</div>
      </div>
    </template>

    <!-- Modal: la hoja que se lleva a la fábrica -->
    <Transition name="fade">
      <div v-if="mostrarExportar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarExportar = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Hoja para la fábrica</h3>
            <button @click="mostrarExportar = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <p class="text-xs text-gray-500">
            Sale un Excel con la orden, el cliente, el producto, la cantidad, en qué paso
            va, la fecha de entrega y el detalle de cada pieza.
          </p>

          <div class="space-y-2">
            <button
              @click="excelDelTaller"
              :disabled="exportando"
              class="w-full text-left rounded-xl border-2 border-green-200 hover:border-green-400 px-3 py-3 transition-colors disabled:opacity-50"
            >
              <p class="text-sm font-bold text-gray-800">Lo que está en el taller ahora</p>
              <p class="text-xs text-gray-500">Todo lo que ya arrancó y sigue adentro, incluido lo retrasado.</p>
            </button>

            <button
              @click="excelConFiltros"
              :disabled="exportando"
              class="w-full text-left rounded-xl border-2 border-gray-200 hover:border-gray-300 px-3 py-3 transition-colors disabled:opacity-50"
            >
              <p class="text-sm font-bold text-gray-800">Lo que se está viendo</p>
              <p class="text-xs text-gray-500">Con los filtros y la búsqueda de la pantalla, todas las páginas.</p>
            </button>

            <button
              @click="empezarAElegir"
              :disabled="exportando"
              class="w-full text-left rounded-xl border-2 border-gray-200 hover:border-gray-300 px-3 py-3 transition-colors disabled:opacity-50"
            >
              <p class="text-sm font-bold text-gray-800">Elegir a mano cuáles</p>
              <p class="text-xs text-gray-500">Se marcan una por una en la lista y sólo esas van a la hoja.</p>
            </button>
          </div>

          <p v-if="exportando" class="text-xs text-gray-500 text-center">Armando la hoja...</p>
        </div>
      </div>
    </Transition>

    <!-- Modal cambiar estado -->
    <Transition name="fade">
      <div v-if="mostrarModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4 max-h-[92vh] overflow-y-auto pb-8">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Cambiar estado</h3>
            <button @click="mostrarModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <p class="text-sm text-gray-600">{{ produccionSeleccionada?.orden_item?.producto?.nombre }}</p>
          <p class="text-xs text-gray-400">Estado actual: <span class="font-medium text-gray-600">{{ produccionSeleccionada?.estado }}</span></p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nuevo estado</label>
            <select v-model="nuevoEstado" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option value="pendiente">Pendiente (no iniciado)</option>
              <option value="en_proceso">En proceso</option>
              <option value="listo">Listo para entrega</option>
              <option value="retrasado">Retrasado</option>
              <option value="entregado">Entregado</option>
              <option value="cancelado">Cancelado</option>
            </select>
          </div>

          <!-- Selector de pasos (solo cuando se elige "en_proceso") -->
          <div v-if="nuevoEstado === 'en_proceso'" ref="pasoSelectorRef" class="space-y-3">
            <div>
              <p class="text-sm font-semibold text-gray-800 mb-1">
                Selecciona los pasos de producción
                <span class="text-red-500">*</span>
              </p>
              <p class="text-xs text-gray-400 mb-3">Toca los procesos en el orden en que se deben realizar. El número indica la secuencia.</p>
              <div class="space-y-2">
                <button
                  v-for="proc in PROCESOS_DISPONIBLES"
                  :key="proc.tipo"
                  type="button"
                  @click="togglePaso(proc.tipo)"
                  :class="[
                    'w-full flex items-center gap-3 px-3 py-3 rounded-xl border-2 transition-all text-left',
                    ordenDePaso(proc.tipo)
                      ? 'border-blue-500 bg-blue-50'
                      : 'border-gray-200 bg-white hover:border-gray-300'
                  ]"
                >
                  <span
                    :class="[
                      'w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0',
                      ordenDePaso(proc.tipo) ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-400'
                    ]"
                  >
                    {{ ordenDePaso(proc.tipo) ?? '+' }}
                  </span>
                  <div>
                    <p class="text-sm font-semibold text-gray-800">{{ proc.label }}</p>
                    <p class="text-xs text-gray-500">{{ proc.desc }}</p>
                  </div>
                </button>
              </div>
              <p v-if="pasosSeleccionados.length > 0" class="text-xs text-blue-600 mt-2">
                Orden seleccionado: {{ pasosSeleccionados.map(p => labelProceso(p.tipo_proceso)).join(' → ') }}
              </p>
            </div>
          </div>

          <div v-if="nuevoEstado === 'retrasado'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo del retraso *</label>
            <textarea
              v-model="motivoRetraso"
              rows="3"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
              placeholder="Explica por qué se retrasó este pedido..."
            />
          </div>

          <div class="flex gap-3">
            <button @click="mostrarModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button
              @click="guardarEstado"
              :disabled="modalLoading"
              class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
            >
              {{ modalLoading ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>

  <ProcesosModal
    :show="showProcesos"
    @close="showProcesos = false"
    @cambiado="cargarTipos(true)"
  />
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
