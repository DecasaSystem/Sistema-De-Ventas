<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useDespachoStore } from '@/stores/despacho'
import { useDespachoSocket } from '@/composables/useDespachoSocket'
import { asignar, asignados, historialDespacho, detalleDespacho, camiones as getCamiones, crearCamion, actualizarCamion } from '@/api/despacho'
import { useToast } from '@/composables/useToast'
import { ChevronDownIcon, XMarkIcon, ArrowTopRightOnSquareIcon, TruckIcon, PencilSquareIcon, CheckIcon } from '@heroicons/vue/24/outline'
import DespachoCard from '@/components/despacho/DespachoCard.vue'
import ColaCamionesModal from '@/components/despacho/ColaCamionesModal.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const router   = useRouter()
const despacho = useDespachoStore()
const socket   = useDespachoSocket()
const toast    = useToast()

const tab = ref('pendientes')

// Selección de órdenes
const seleccionadas = ref(new Map()) // ordenId → posicion (1-indexed)

const ordenesSeleccionadas = computed(() =>
  despacho.cola.filter(o => seleccionadas.value.has(o.id))
)

const haySeleccionadas = computed(() => seleccionadas.value.size > 0)

const totalSaldoSeleccionado = computed(() =>
  ordenesSeleccionadas.value.reduce((sum, o) => sum + (parseFloat(o.saldo_pendiente) || 0), 0)
)

const mostrarModalCamion = ref(false)
const asignando = ref(false)

function toggleSeleccion(ordenId) {
  if (seleccionadas.value.has(ordenId)) {
    // Quitar
    const pos = seleccionadas.value.get(ordenId)
    seleccionadas.value.delete(ordenId)
    // Re-indexar posiciones después de la removida
    for (const [id, p] of seleccionadas.value) {
      if (p > pos) {
        seleccionadas.value.set(id, p - 1)
      }
    }
  } else {
    seleccionadas.value.set(ordenId, seleccionadas.value.size + 1)
  }
  // Crear un nuevo Map para reactividad
  seleccionadas.value = new Map(seleccionadas.value)
}

function abrirAsignar() {
  if (!haySeleccionadas.value) return
  mostrarModalCamion.value = true
}

async function confirmarAsignacion({ camion, fecha, nombre_ruta, instrucciones }) {
  asignando.value = true
  mostrarModalCamion.value = false
  try {
    const ordenes = ordenesSeleccionadas.value.map(o => ({
      orden_id: o.id,
      posicion: seleccionadas.value.get(o.id),
    }))
    await asignar({ camion_id: camion.id, fecha_despacho: fecha, nombre_ruta, instrucciones, ordenes })
    const nombreCamion = camion.nombre ?? `Camión ${camion.id}`
    toast.success(`Despacho asignado a ${nombreCamion} — ${camion.conductor?.nombre}`)
    seleccionadas.value = new Map()
    await despacho.refrescar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Error al asignar')
  } finally {
    asignando.value = false
  }
}

function verDetalle(ordenId) {
  router.push({ name: 'orden-detalle', params: { id: ordenId } })
}

// ── Camiones ─────────────────────────────────────────────────────────────────
const camionesList    = ref([])
const editandoCamion  = ref(null)  // { id, nombre, placa, conductor_id }
const guardandoCamion = ref(false)
const mostrarFormNuevo = ref(false)
const formNuevo        = ref({ nombre: '', placa: '', conductor_id: '' })
const creandoCamion    = ref(false)

async function cargarCamiones() {
  try {
    const { data } = await getCamiones()
    camionesList.value = data
  } catch {}
}

function abrirEditCamion(c) {
  editandoCamion.value = { id: c.id, nombre: c.nombre ?? '', placa: c.placa ?? '', conductor_id: c.conductor_id ?? '' }
}

async function crearNuevoCamion() {
  creandoCamion.value = true
  try {
    await crearCamion({
      nombre:       formNuevo.value.nombre       || null,
      placa:        formNuevo.value.placa         || null,
      conductor_id: formNuevo.value.conductor_id || null,
    })
    formNuevo.value      = { nombre: '', placa: '', conductor_id: '' }
    mostrarFormNuevo.value = false
    await cargarCamiones()
    toast.success('Camión creado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Error al crear camión')
  } finally {
    creandoCamion.value = false
  }
}

async function guardarCamion() {
  if (!editandoCamion.value) return
  guardandoCamion.value = true
  try {
    await actualizarCamion(editandoCamion.value.id, {
      nombre:       editandoCamion.value.nombre       || null,
      placa:        editandoCamion.value.placa         || null,
      conductor_id: editandoCamion.value.conductor_id || null,
    })
    editandoCamion.value = null
    await cargarCamiones()
    toast.success('Camión actualizado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Error al guardar')
  } finally {
    guardandoCamion.value = false
  }
}

// conductores disponibles para asignar al camión
const conductoresDisponibles = ref([])
async function cargarConductores() {
  try {
    const { conductores } = await import('@/api/despacho')
    const { data } = await conductores()
    conductoresDisponibles.value = data
  } catch {}
}

// ── Asignados con filtros ────────────────────────────────────────────────────
const asignadosFiltrados    = ref([])
const cargandoAsignados     = ref(false)
const filtrosAsignados      = ref({ camion_id: '', desde: '', hasta: '' })

async function cargarAsignadosFiltrados() {
  cargandoAsignados.value = true
  try {
    const params = {}
    if (filtrosAsignados.value.camion_id) params.camion_id = filtrosAsignados.value.camion_id
    if (filtrosAsignados.value.desde) params.desde = filtrosAsignados.value.desde
    if (filtrosAsignados.value.hasta) params.hasta = filtrosAsignados.value.hasta
    const { data } = await asignados(params)
    asignadosFiltrados.value = data
  } catch {} finally {
    cargandoAsignados.value = false
  }
}

function limpiarFiltrosAsignados() {
  filtrosAsignados.value = { camion_id: '', desde: '', hasta: '' }
  cargarAsignadosFiltrados()
}

const hayFiltrosAsignados = computed(() =>
  filtrosAsignados.value.camion_id || filtrosAsignados.value.desde || filtrosAsignados.value.hasta
)

// ── Historial ────────────────────────────────────────────────────────────────
const historial = ref([])
const cargandoHistorial = ref(false)
const filtrosHistorial = ref({ camion_id: '', desde: '', hasta: '' })
const historialPaginacion = ref(null)
const detalleExpandido = ref(null)
const detalleExpandidoAsignado = ref(null)
const cargandoDetalle = ref(false)
const cargandoDetalleAsignado = ref(false)

async function cargarHistorial() {
  cargandoHistorial.value = true
  try {
    const params = {}
    if (filtrosHistorial.value.camion_id) params.camion_id = filtrosHistorial.value.camion_id
    if (filtrosHistorial.value.desde) params.desde = filtrosHistorial.value.desde
    if (filtrosHistorial.value.hasta) params.hasta = filtrosHistorial.value.hasta
    const { data } = await historialDespacho(params)
    historial.value = data.data
    historialPaginacion.value = {
      current: data.current_page,
      last: data.last_page,
      total: data.total,
      next: data.next_page_url,
      prev: data.prev_page_url,
    }
  } catch {} finally {
    cargandoHistorial.value = false
  }
}

const verFactura = ref(false)

async function toggleDetalle(despachoId) {
  if (detalleExpandido.value?.id === despachoId) {
    detalleExpandido.value = null
    return
  }
  cargandoDetalle.value = true
  try {
    const { data } = await detalleDespacho(despachoId)
    detalleExpandido.value = data
  } catch {} finally {
    cargandoDetalle.value = false
  }
}

async function toggleDetalleAsignado(grupo) {
  const despachoId = grupo[0]?.despacho_id
  if (!despachoId) return
  if (detalleExpandidoAsignado.value?.id === despachoId) {
    detalleExpandidoAsignado.value = null
    return
  }
  cargandoDetalleAsignado.value = true
  try {
    const { data } = await detalleDespacho(despachoId)
    detalleExpandidoAsignado.value = data
  } catch {} finally {
    cargandoDetalleAsignado.value = false
  }
}

async function cargarPagina(page) {
  if (!page || page < 1 || page > (historialPaginacion.value?.last ?? 1)) return
  cargandoHistorial.value = true
  try {
    const params = { page }
    if (filtrosHistorial.value.camion_id) params.camion_id = filtrosHistorial.value.camion_id
    if (filtrosHistorial.value.desde) params.desde = filtrosHistorial.value.desde
    if (filtrosHistorial.value.hasta) params.hasta = filtrosHistorial.value.hasta
    const { data } = await historialDespacho(params)
    historial.value = data.data
    historialPaginacion.value = {
      current: data.current_page,
      last: data.last_page,
      total: data.total,
      next: data.next_page_url,
      prev: data.prev_page_url,
    }
  } catch {} finally {
    cargandoHistorial.value = false
  }
}

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function fotoUrl(url) {
  if (!url) return ''
  if (url.startsWith('http')) return url
  return url
}

onMounted(async () => {
  await despacho.refrescar()
  socket.conectar()
  await Promise.all([cargarCamiones(), cargarConductores(), cargarAsignadosFiltrados()])
})

watch(tab, (t) => {
  if (t === 'asignados') cargarAsignadosFiltrados()
  if (t === 'historial') cargarHistorial()
  if (t === 'camiones')  cargarCamiones()
})

onBeforeUnmount(() => {
  socket.desconectar()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-gray-900">Despacho</h1>
      <span v-if="despacho.ordenesPendientes > 0" class="text-sm text-gray-500">
        {{ despacho.ordenesPendientes }} pendiente(s)
      </span>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'pendientes'"
        class="flex-1 py-2 text-sm font-medium rounded-lg transition-colors"
        :class="tab === 'pendientes' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500'"
      >
        Cola ({{ despacho.cola.length }})
      </button>
      <button
        @click="tab = 'asignados'"
        class="flex-1 py-2 text-sm font-medium rounded-lg transition-colors"
        :class="tab === 'asignados' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500'"
      >
        Activos
      </button>
      <button
        @click="tab = 'camiones'"
        class="flex-1 py-2 text-sm font-medium rounded-lg transition-colors"
        :class="tab === 'camiones' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500'"
      >
        Camiones
      </button>
      <button
        @click="tab = 'historial'"
        class="flex-1 py-2 text-sm font-medium rounded-lg transition-colors"
        :class="tab === 'historial' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500'"
      >
        Historial
      </button>
    </div>

    <!-- Tab: Cola pendiente -->
    <div v-if="tab === 'pendientes'" class="space-y-3">
      <div v-if="asignando" class="text-center py-4 text-sm text-gray-400">Asignando...</div>

      <template v-if="despacho.cola.length === 0">
        <EmptyState message="No hay órdenes en la cola de despacho" />
      </template>

      <template v-else>
        <p class="text-xs text-gray-400">
          Toca las órdenes en el orden en que deben entregarse
        </p>

        <DespachoCard
          v-for="o in despacho.cola"
          :key="o.id"
          :orden="o"
          :seleccionado="seleccionadas.has(o.id)"
          :posicion="seleccionadas.get(o.id)"
          @toggle="toggleSeleccion"
          @ver-detalle="verDetalle"
        />

        <!-- Botón Asignar flotante -->
        <Transition name="slide-up">
          <div
            v-if="haySeleccionadas"
            class="sticky bottom-20 bg-blue-600 text-white rounded-xl p-3 shadow-lg flex items-center justify-between"
          >
            <span class="text-sm font-semibold">
              {{ seleccionadas.size }} orden(es) seleccionada(s)
            </span>
            <button
              @click="abrirAsignar"
              class="bg-white text-blue-600 px-4 py-1.5 rounded-lg text-sm font-bold hover:bg-blue-50 transition-colors"
            >
              Asignar
            </button>
          </div>
        </Transition>
      </template>
    </div>

    <!-- Tab: Camiones -->
    <div v-if="tab === 'camiones'" class="space-y-3">
      <div class="flex items-center justify-between">
        <p class="text-xs text-gray-400">Toca el lápiz para editar nombre, placa o conductor.</p>
        <button
          @click="mostrarFormNuevo = !mostrarFormNuevo; editandoCamion = null"
          class="flex items-center gap-1.5 text-xs bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 transition-colors"
        >
          + Nuevo camión
        </button>
      </div>

      <!-- Formulario nuevo camión -->
      <div v-if="mostrarFormNuevo" class="bg-blue-50 border border-blue-200 rounded-xl p-4 space-y-3">
        <p class="text-xs font-semibold text-blue-700 uppercase">Nuevo camión</p>
        <input
          v-model="formNuevo.nombre"
          type="text"
          placeholder="Nombre (ej: Camión 3)"
          class="w-full rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
        />
        <input
          v-model="formNuevo.placa"
          type="text"
          placeholder="Placa (opcional)"
          class="w-full rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
        />
        <select
          v-model="formNuevo.conductor_id"
          class="w-full rounded-lg border border-blue-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
        >
          <option value="">Sin conductor (asignar después)</option>
          <option v-for="cond in conductoresDisponibles" :key="cond.id" :value="cond.id">
            {{ cond.nombre }}
          </option>
        </select>
        <div class="flex gap-2">
          <button
            @click="mostrarFormNuevo = false"
            class="flex-1 border border-blue-200 text-blue-600 rounded-lg py-2 text-sm font-medium hover:bg-blue-100"
          >
            Cancelar
          </button>
          <button
            @click="crearNuevoCamion"
            :disabled="creandoCamion"
            class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
          >
            {{ creandoCamion ? 'Creando...' : 'Crear camión' }}
          </button>
        </div>
      </div>

      <div
        v-for="c in camionesList"
        :key="c.id"
        class="bg-white rounded-xl shadow-sm p-4 space-y-3"
      >
        <!-- Vista -->
        <template v-if="editandoCamion?.id !== c.id">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center flex-shrink-0">
              <TruckIcon class="w-6 h-6 text-blue-600" />
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-800">{{ c.nombre ?? `Camión ${c.id}` }}</p>
              <p class="text-xs text-gray-400">
                {{ c.placa ? `Placa: ${c.placa}` : 'Sin placa' }} ·
                {{ c.conductor?.nombre ?? 'Sin conductor' }}
              </p>
            </div>
            <button @click="abrirEditCamion(c)" class="p-2 text-gray-400 hover:text-blue-600 transition-colors">
              <PencilSquareIcon class="w-4 h-4" />
            </button>
          </div>
        </template>

        <!-- Edición -->
        <template v-else>
          <p class="text-xs font-semibold text-gray-500 uppercase">Editar camión</p>
          <div class="space-y-2">
            <input
              v-model="editandoCamion.nombre"
              type="text"
              placeholder="Nombre (ej: Camión 1)"
              class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
            />
            <input
              v-model="editandoCamion.placa"
              type="text"
              placeholder="Placa (opcional)"
              class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
            />
            <select
              v-model="editandoCamion.conductor_id"
              class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
            >
              <option value="">Sin conductor asignado</option>
              <option v-for="cond in conductoresDisponibles" :key="cond.id" :value="cond.id">
                {{ cond.nombre }}
              </option>
            </select>
          </div>
          <div class="flex gap-2">
            <button
              @click="editandoCamion = null"
              class="flex-1 border border-gray-200 text-gray-600 rounded-lg py-2 text-sm font-medium hover:bg-gray-50"
            >
              Cancelar
            </button>
            <button
              @click="guardarCamion"
              :disabled="guardandoCamion"
              class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 flex items-center justify-center gap-1.5"
            >
              <CheckIcon class="w-4 h-4" />
              {{ guardandoCamion ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Tab: Asignados -->
    <div v-if="tab === 'asignados'" class="space-y-3">

      <!-- Filtros -->
      <div class="bg-white rounded-xl shadow-sm p-3 space-y-2">
        <select
          v-model="filtrosAsignados.camion_id"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">Todos los camiones</option>
          <option v-for="c in camionesList" :key="c.id" :value="c.id">{{ c.nombre ?? `Camión ${c.id}` }}</option>
        </select>
        <div class="flex gap-2">
          <input
            v-model="filtrosAsignados.desde"
            type="date"
            class="flex-1 rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <input
            v-model="filtrosAsignados.hasta"
            type="date"
            class="flex-1 rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <button
            @click="cargarAsignadosFiltrados"
            class="bg-blue-600 text-white px-3 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700"
          >
            Filtrar
          </button>
        </div>
        <button
          v-if="hayFiltrosAsignados"
          @click="limpiarFiltrosAsignados"
          class="text-xs text-gray-400 hover:text-gray-600 underline"
        >
          Limpiar filtros
        </button>
      </div>

      <div v-if="cargandoAsignados" class="flex justify-center py-8">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <template v-else-if="asignadosFiltrados.length === 0">
        <EmptyState message="No hay despachos activos" />
      </template>

      <template v-else>
        <div
          v-for="grupo in asignadosFiltrados"
          :key="grupo[0]?.despacho_id"
          class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 space-y-2"
        >
          <div class="space-y-2">
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-center gap-2 flex-1 min-w-0">
                <TruckIcon class="w-4 h-4 text-blue-500 flex-shrink-0" />
                <div class="min-w-0">
                  <p class="font-semibold text-gray-800 truncate">
                    {{ grupo[0]?.despacho?.camion?.nombre ?? 'Camión' }}
                    <span v-if="grupo[0]?.despacho?.camion?.placa" class="font-normal text-gray-400 text-xs">· {{ grupo[0]?.despacho?.camion?.placa }}</span>
                  </p>
                  <p class="text-xs text-gray-500">
                    {{ grupo[0]?.despacho?.conductor?.nombre }}
                    <span v-if="grupo[0]?.despacho?.fecha_despacho" class="ml-1 text-gray-400">
                      · {{ new Date(grupo[0].despacho.fecha_despacho + 'T12:00:00').toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric', month: 'short' }) }}
                    </span>
                  </p>
                </div>
              </div>
              <BadgeEstado :estado="grupo[0]?.despacho?.estado" />
            </div>
            <!-- Nombre de ruta + total a cobrar -->
            <div class="flex items-center justify-between gap-2 flex-wrap">
              <span
                v-if="grupo[0]?.despacho?.nombre_ruta"
                class="text-xs font-semibold bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full"
              >
                {{ grupo[0].despacho.nombre_ruta }}
              </span>
              <span class="flex items-center gap-1 text-xs font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded-full ml-auto">
                💵 ${{ grupo.reduce((s, i) => s + (parseFloat(i.orden?.saldo_pendiente) || 0), 0).toLocaleString('es-CO') }}
              </span>
            </div>
          </div>

          <div class="space-y-1">
            <div
              v-for="item in grupo"
              :key="item.id"
              class="flex items-center gap-2 text-sm py-1.5 cursor-pointer hover:bg-gray-50 -mx-4 px-4 rounded-lg transition-colors"
              @click="verDetalle(item.orden_id)"
            >
              <span class="text-gray-400 text-xs w-5 text-right flex-shrink-0">#{{ item.posicion }}</span>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-800 truncate">{{ item.orden?.cliente?.nombre }}</p>
                <p v-if="item.orden?.cliente?.direccion" class="text-xs text-gray-400 truncate">{{ item.orden.cliente.direccion }}</p>
              </div>
              <BadgeEstado :estado="item.estado" />
              <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
            </div>
          </div>

          <div
            @click="toggleDetalleAsignado(grupo)"
            class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 transition-colors -mx-4 px-4 pb-2 pt-2 border-t border-gray-100 mt-2"
          >
            <span class="text-xs text-blue-600 font-medium flex-1">Ver detalle del despacho</span>
            <ChevronDownIcon
              class="w-4 h-4 text-gray-400 transition-transform"
              :class="detalleExpandidoAsignado?.id === grupo[0]?.despacho_id ? 'rotate-180' : ''"
            />
          </div>

          <!-- Expanded detail -->
          <div v-if="detalleExpandidoAsignado?.id === grupo[0]?.despacho_id" class="-mx-4 px-4 pb-4">
            <div v-if="cargandoDetalleAsignado" class="py-4 text-center text-sm text-gray-400">Cargando...</div>
            <template v-else>
              <div class="divide-y divide-gray-50 border-t border-gray-100">
                <div
                  v-for="item in detalleExpandidoAsignado?.items"
                  :key="item.id"
                  class="py-3 cursor-pointer hover:bg-gray-50 -mx-4 px-4 transition-colors"
                  @click="verDetalle(item.orden_id)"
                >
                  <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs text-gray-400 w-5 text-right flex-shrink-0">#{{ item.posicion }}</span>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-gray-800 truncate">{{ item.orden?.cliente?.nombre }}</p>
                      <p v-if="item.orden?.cliente?.direccion" class="text-xs text-gray-400 truncate">{{ item.orden.cliente.direccion }}</p>
                    </div>
                    <BadgeEstado :estado="item.estado" />
                    <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
                  </div>
                  <div v-if="item.orden?.total_pagado > 0" class="text-xs text-gray-500 ml-7">
                    Pagado: <MoneyDisplay :amount="item.orden?.total_pagado" />
                    <span v-if="item.orden?.saldo_pendiente > 0" class="text-orange-600 ml-2">
                      Saldo: <MoneyDisplay :amount="item.orden?.saldo_pendiente" />
                    </span>
                  </div>
                  <div v-if="item.foto_producto || item.foto_pago" class="flex gap-2 mt-2 ml-7" @click.stop>
                    <img
                      v-if="item.foto_producto"
                      :src="fotoUrl(item.foto_producto)"
                      class="w-16 h-16 object-cover rounded-lg border border-gray-200 cursor-pointer"
                      @click="verFactura = item.foto_producto"
                    />
                    <img
                      v-if="item.foto_pago"
                      :src="fotoUrl(item.foto_pago)"
                      class="w-16 h-16 object-cover rounded-lg border border-gray-200 cursor-pointer"
                      @click="verFactura = item.foto_pago"
                    />
                  </div>
                </div>
              </div>
            </template>
          </div>
        </div>
      </template>
    </div>

    <!-- Tab: Historial -->
    <div v-if="tab === 'historial'" class="space-y-3">
      <!-- Filtros -->
      <div class="bg-white rounded-xl shadow-sm p-3 space-y-2">
        <select
          v-model="filtrosHistorial.camion_id"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">Todos los camiones</option>
          <option v-for="c in camionesList" :key="c.id" :value="c.id">{{ c.nombre ?? `Camión ${c.id}` }}</option>
        </select>
        <div class="flex gap-2">
          <input
            v-model="filtrosHistorial.desde"
            type="date"
            class="flex-1 rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <input
            v-model="filtrosHistorial.hasta"
            type="date"
            class="flex-1 rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <button
            @click="cargarHistorial"
            class="bg-blue-600 text-white px-3 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700"
          >
            Filtrar
          </button>
        </div>
      </div>

      <div v-if="cargandoHistorial" class="text-center py-8 text-sm text-gray-400">Cargando...</div>

      <template v-else-if="historial.length === 0">
        <EmptyState message="No hay despachos completados" />
      </template>

      <template v-else>
        <div class="space-y-2">
          <div
            v-for="d in historial"
            :key="d.id"
            class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden"
          >
            <!-- Card header -->
            <div
              @click="toggleDetalle(d.id)"
              class="p-4 flex items-center gap-3 cursor-pointer hover:bg-gray-50 transition-colors"
            >
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <TruckIcon class="w-4 h-4 text-blue-400 flex-shrink-0" />
                  <p class="font-semibold text-gray-800 text-sm">{{ d.camion?.nombre ?? 'Camión' }}</p>
                  <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Completado</span>
                </div>
                <p class="text-xs text-gray-400 mt-0.5">
                  {{ d.conductor?.nombre }}
                  <span v-if="d.fecha_despacho"> · {{ new Date(d.fecha_despacho + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) }}</span>
                  · {{ d.items?.length ?? 0 }} orden(es)
                </p>
              </div>
              <ChevronDownIcon
                class="w-5 h-5 text-gray-400 transition-transform"
                :class="detalleExpandido?.id === d.id ? 'rotate-180' : ''"
              />
            </div>

            <!-- Expanded detail -->
            <div v-if="detalleExpandido?.id === d.id" class="border-t border-gray-100">
              <div v-if="cargandoDetalle" class="p-4 text-center text-sm text-gray-400">Cargando detalle...</div>
              <template v-else>
                <!-- Items -->
                <div class="divide-y divide-gray-50">
                  <div
                    v-for="item in detalleExpandido?.items"
                    :key="item.id"
                    class="px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors"
                    @click="verDetalle(item.orden_id)"
                  >
                    <div class="flex items-center gap-2 mb-1">
                      <span class="text-xs text-gray-400 w-5 text-right flex-shrink-0">#{{ item.posicion }}</span>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-800 truncate">{{ item.orden?.cliente?.nombre }}</p>
                        <p v-if="item.orden?.cliente?.direccion" class="text-xs text-gray-400 truncate">{{ item.orden.cliente.direccion }}</p>
                      </div>
                      <BadgeEstado :estado="item.estado" />
                      <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
                    </div>
                    <div v-if="item.orden?.total_pagado > 0" class="text-xs text-gray-500 ml-7">
                      Pagado: <MoneyDisplay :amount="item.orden?.total_pagado" />
                      <span v-if="item.orden?.saldo_pendiente > 0" class="text-orange-600 ml-2">
                        Saldo: <MoneyDisplay :amount="item.orden?.saldo_pendiente" />
                      </span>
                    </div>
                    <!-- Fotos -->
                    <div v-if="item.foto_producto || item.foto_pago" class="flex gap-2 mt-2 ml-7" @click.stop>
                      <img
                        v-if="item.foto_producto"
                        :src="fotoUrl(item.foto_producto)"
                        class="w-16 h-16 object-cover rounded-lg border border-gray-200 cursor-pointer"
                        @click="verFactura = item.foto_producto"
                      />
                      <img
                        v-if="item.foto_pago"
                        :src="fotoUrl(item.foto_pago)"
                        class="w-16 h-16 object-cover rounded-lg border border-gray-200 cursor-pointer"
                        @click="verFactura = item.foto_pago"
                      />
                    </div>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </div>

        <!-- Paginación -->
        <div v-if="historialPaginacion && historialPaginacion.last > 1" class="flex justify-center gap-2 pt-2">
          <button
            :disabled="!historialPaginacion.prev"
            @click="cargarPagina(historialPaginacion.current - 1)"
            class="px-3 py-1.5 rounded-lg text-sm font-medium disabled:opacity-30 bg-white border border-gray-300 text-gray-700"
          >
            Anterior
          </button>
          <span class="px-3 py-1.5 text-sm text-gray-500">
            {{ historialPaginacion.current }} / {{ historialPaginacion.last }}
          </span>
          <button
            :disabled="!historialPaginacion.next"
            @click="cargarPagina(historialPaginacion.current + 1)"
            class="px-3 py-1.5 rounded-lg text-sm font-medium disabled:opacity-30 bg-white border border-gray-300 text-gray-700"
          >
            Siguiente
          </button>
        </div>
      </template>
    </div>

    <!-- Modal de camiones -->
    <ColaCamionesModal
      v-if="mostrarModalCamion"
      :cantidad-ordenes="seleccionadas.size"
      :total-saldo="totalSaldoSeleccionado"
      @confirmar="confirmarAsignacion"
      @cerrar="mostrarModalCamion = false"
    />

    <!-- Lightbox -->
    <div
      v-if="verFactura"
      class="fixed inset-0 z-[60] flex items-center justify-center p-6"
      @click.self="verFactura = false"
    >
      <div class="absolute inset-0 bg-black/85" @click="verFactura = false" />
      <div class="relative w-full max-w-lg">
        <button
          @click="verFactura = false"
          class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg"
        >
          <XMarkIcon class="w-5 h-5 text-gray-700" />
        </button>
        <div class="bg-white rounded-2xl overflow-hidden shadow-2xl">
          <img
            :src="verFactura"
            alt="Foto"
            class="w-full object-contain max-h-96"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.2s ease, opacity 0.2s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(20px);
  opacity: 0;
}
</style>
