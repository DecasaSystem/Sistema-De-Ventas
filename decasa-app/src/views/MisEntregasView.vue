<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useDespachoSocket } from '@/composables/useDespachoSocket'
import { misEntregas, historialMisEntregas } from '@/api/despacho'
import EntregaDetalleModal from '@/components/despacho/EntregaDetalleModal.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { TruckIcon, CheckCircleIcon, ClockIcon, MapPinIcon, ChevronRightIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline'

const socket = useDespachoSocket()

const tab        = ref('activas')
const entregas   = ref([])
const cargando   = ref(true)
const error      = ref('')
const itemActivo = ref(null)

// Ruta seleccionada para ver sus entregas (null = vista de tarjetas)
const rutaSeleccionada = ref(null)

const historial        = ref([])
const loadingHistorial = ref(false)
const historialPage    = ref(1)
const historialHasMore = ref(true)

onMounted(async () => {
  await cargar()
  socket.conectar()
})

onBeforeUnmount(() => {
  socket.desconectar()
})

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await misEntregas()
    entregas.value = data
    // Si solo hay una ruta, entrar directo
    if (rutasAgrupadas.value.length === 1) {
      rutaSeleccionada.value = rutasAgrupadas.value[0].despacho_id
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Error al cargar las entregas'
  } finally {
    cargando.value = false
  }
}

// Agrupar entregas por despacho y ordenar por fecha
const rutasAgrupadas = computed(() => {
  const grupos = new Map()
  for (const item of entregas.value) {
    const key = item.despacho_id
    if (!grupos.has(key)) {
      grupos.set(key, { despacho_id: key, despacho: item.despacho, items: [] })
    }
    grupos.get(key).items.push(item)
  }
  return [...grupos.values()].sort((a, b) => {
    const fa = a.despacho?.fecha_despacho ?? ''
    const fb = b.despacho?.fecha_despacho ?? ''
    return fa.localeCompare(fb)
  })
})

const itemsRutaActiva = computed(() =>
  rutaSeleccionada.value
    ? (rutasAgrupadas.value.find(g => g.despacho_id === rutaSeleccionada.value)?.items ?? [])
    : []
)

const infoRutaActiva = computed(() =>
  rutasAgrupadas.value.find(g => g.despacho_id === rutaSeleccionada.value)
)

function entrarRuta(grupo) {
  rutaSeleccionada.value = grupo.despacho_id
}

function volverARutas() {
  rutaSeleccionada.value = null
}

async function cargarHistorial(page = 1, append = false) {
  loadingHistorial.value = true
  try {
    const { data } = await historialMisEntregas({ page })
    const items = data.data ?? []
    historial.value = append ? [...historial.value, ...items] : items
    historialPage.value     = data.current_page
    historialHasMore.value  = data.current_page < data.last_page
  } catch {} finally {
    loadingHistorial.value = false
  }
}

async function switchTab(t) {
  tab.value = t
  if (t === 'historial' && historial.value.length === 0) {
    await cargarHistorial(1, false)
  }
  if (t === 'activas') rutaSeleccionada.value = null
}

async function cargarMas() {
  if (!historialHasMore.value || loadingHistorial.value) return
  await cargarHistorial(historialPage.value + 1, true)
}

function abrirDetalle(item) { itemActivo.value = item }
function cerrarDetalle()    { itemActivo.value = null  }

async function trasEntregar() {
  await cargar()
  historial.value = []
  historialPage.value    = 1
  historialHasMore.value = true
}

function fmtFecha(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('es-CO', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function fmtFechaRuta(f) {
  if (!f) return ''
  return new Date(f + 'T12:00:00').toLocaleDateString('es-CO', {
    weekday: 'long', day: 'numeric', month: 'long',
  })
}

function totalRuta(items) {
  return items.reduce((s, i) => s + (parseFloat(i.orden?.saldo_pendiente) || 0), 0)
}

function pendientesRuta(items) {
  return items.filter(i => i.estado !== 'entregado').length
}
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">

    <!-- Header -->
    <div class="flex items-center gap-2">
      <!-- Botón atrás cuando estamos dentro de una ruta (y hay más de 1) -->
      <button
        v-if="rutaSeleccionada && rutasAgrupadas.length > 1"
        @click="volverARutas"
        class="p-1.5 -ml-1 text-blue-600"
      >
        <ArrowLeftIcon class="w-5 h-5" />
      </button>
      <TruckIcon v-else class="w-6 h-6 text-blue-600" />
      <h1 class="text-xl font-bold text-gray-900 flex-1">
        {{ rutaSeleccionada ? (infoRutaActiva?.despacho?.nombre_ruta || 'Mis Entregas') : 'Mis Entregas' }}
      </h1>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        @click="switchTab('activas')"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors',
          tab === 'activas' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Activas
        <span v-if="entregas.length" class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs bg-blue-100 text-blue-700 rounded-full font-bold">{{ entregas.length }}</span>
      </button>
      <button
        @click="switchTab('historial')"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors',
          tab === 'historial' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Historial
      </button>
    </div>

    <!-- ── TAB ACTIVAS ──────────────────────────────────────────────────────── -->
    <template v-if="tab === 'activas'">
      <div v-if="cargando" class="text-center py-8 text-sm text-gray-400">Cargando entregas...</div>
      <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">{{ error }}</div>
      <EmptyState v-else-if="entregas.length === 0" message="No tienes entregas asignadas en este momento." />

      <!-- Vista de tarjetas de rutas -->
      <div v-else-if="!rutaSeleccionada" class="space-y-3">
        <p class="text-xs text-gray-400">Toca una ruta para ver sus entregas.</p>
        <button
          v-for="grupo in rutasAgrupadas"
          :key="grupo.despacho_id"
          @click="entrarRuta(grupo)"
          class="w-full text-left bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden active:scale-[0.98] transition-transform"
        >
          <!-- Cabecera azul -->
          <div class="bg-gradient-to-r from-blue-600 to-blue-500 px-4 py-3 text-white">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 flex-1 min-w-0">
                <TruckIcon class="w-5 h-5 flex-shrink-0" />
                <p class="font-bold text-base truncate">
                  {{ grupo.despacho?.nombre_ruta || 'Ruta de entregas' }}
                </p>
              </div>
              <ChevronRightIcon class="w-5 h-5 text-blue-200 flex-shrink-0" />
            </div>
            <p class="text-xs text-blue-200 mt-0.5 capitalize">{{ fmtFechaRuta(grupo.despacho?.fecha_despacho) }}</p>
          </div>

          <!-- Resumen -->
          <div class="px-4 py-3 flex items-center justify-between gap-4">
            <div class="space-y-0.5">
              <p class="text-sm font-semibold text-gray-800">
                {{ pendientesRuta(grupo.items) }} entrega(s) pendiente(s)
              </p>
              <p v-if="grupo.despacho?.instrucciones" class="text-xs text-gray-400 truncate max-w-[200px]">
                📋 {{ grupo.despacho.instrucciones }}
              </p>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-xs text-gray-400">A cobrar</p>
              <p class="text-base font-bold text-green-600">
                ${{ totalRuta(grupo.items).toLocaleString('es-CO') }}
              </p>
            </div>
          </div>
        </button>
      </div>

      <!-- Vista de entregas de la ruta seleccionada -->
      <div v-else class="space-y-3">

        <!-- Banner de la ruta -->
        <div class="bg-blue-600 rounded-xl p-4 text-white space-y-2">
          <div class="flex items-center justify-between gap-2">
            <p class="font-bold text-base">{{ infoRutaActiva?.despacho?.nombre_ruta || 'Ruta de entregas' }}</p>
            <span class="text-xs text-blue-200 font-medium capitalize">{{ fmtFechaRuta(infoRutaActiva?.despacho?.fecha_despacho) }}</span>
          </div>
          <div class="bg-white/15 rounded-lg px-3 py-2 flex items-center justify-between">
            <span class="text-xs text-blue-100">Total a cobrar</span>
            <span class="font-bold text-white">
              ${{ totalRuta(itemsRutaActiva).toLocaleString('es-CO') }}
            </span>
          </div>
          <div v-if="infoRutaActiva?.despacho?.instrucciones" class="bg-white/15 rounded-lg px-3 py-2">
            <p class="text-xs text-blue-100 font-semibold mb-0.5">Instrucciones</p>
            <p class="text-sm text-white leading-snug">{{ infoRutaActiva.despacho.instrucciones }}</p>
          </div>
        </div>

        <!-- Tarjetas de entrega -->
        <div
          v-for="(item, idx) in itemsRutaActiva"
          :key="item.id"
          @click="abrirDetalle(item)"
          class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 active:scale-[0.98] transition-transform cursor-pointer"
        >
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-sm font-bold">
              {{ item.posicion ?? idx + 1 }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-2 min-w-0">
                <p class="font-semibold text-gray-900 truncate flex-1 min-w-0">{{ item.orden?.cliente?.nombre }}</p>
                <BadgeEstado :estado="item.estado" class="flex-shrink-0" />
              </div>
              <p class="text-xs text-gray-500 mt-0.5">{{ item.orden?.cliente?.telefono }}</p>
              <div class="flex items-center gap-1 text-xs text-gray-500 mt-0.5 min-w-0">
                <MapPinIcon class="w-3.5 h-3.5 flex-shrink-0 text-gray-400" />
                <span class="truncate">{{ item.orden?.direccion_envio || item.orden?.cliente?.direccion }}</span>
              </div>
              <p v-if="item.orden?.items?.length" class="text-xs text-gray-400 mt-1 truncate">
                {{ item.orden.items.map(i => i.producto?.nombre).filter(Boolean).join(', ') }}
              </p>
              <div class="flex items-center gap-3 mt-2 text-sm">
                <span class="text-gray-600"><MoneyDisplay :amount="item.orden?.valor_total" /></span>
                <span v-if="item.orden?.saldo_pendiente > 0" class="text-orange-600 text-xs font-medium">
                  Cobra: <MoneyDisplay :amount="item.orden?.saldo_pendiente" />
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ── TAB HISTORIAL ───────────────────────────────────────────────────── -->
    <template v-if="tab === 'historial'">
      <AppSpinner v-if="loadingHistorial && historial.length === 0" />
      <EmptyState v-else-if="!loadingHistorial && historial.length === 0" message="Todavía no tienes entregas completadas." />

      <div v-else class="space-y-3">
        <div
          v-for="item in historial"
          :key="item.id"
          @click="abrirDetalle(item)"
          class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 active:scale-[0.98] transition-transform cursor-pointer"
        >
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-9 h-9 rounded-full bg-green-100 text-green-700 flex items-center justify-center">
              <CheckCircleIcon class="w-5 h-5" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-2">
                <p class="font-semibold text-gray-900 truncate">{{ item.orden?.cliente?.nombre }}</p>
                <span class="text-xs font-semibold text-green-600 flex-shrink-0">Entregado</span>
              </div>
              <p class="text-xs text-gray-500 mt-0.5">{{ item.orden?.cliente?.telefono }}</p>
              <p class="text-xs text-gray-500 flex items-center gap-1 truncate">
                <MapPinIcon class="w-3.5 h-3.5 flex-shrink-0" />
                {{ item.orden?.cliente?.direccion }}
              </p>
              <p v-if="item.orden?.items?.length" class="text-xs text-gray-400 mt-1 truncate">
                {{ item.orden.items.map(i => i.producto?.nombre).filter(Boolean).join(', ') }}
              </p>
              <div class="flex items-center justify-between mt-2">
                <span class="text-sm text-gray-600"><MoneyDisplay :amount="item.orden?.valor_total" /></span>
                <span class="text-xs text-gray-400 flex items-center gap-1">
                  <ClockIcon class="w-3.5 h-3.5" />
                  {{ fmtFecha(item.entregado_at) }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <div class="text-center py-2">
          <button
            v-if="historialHasMore"
            @click="cargarMas"
            :disabled="loadingHistorial"
            class="text-sm text-blue-600 font-medium px-4 py-2 rounded-lg border border-blue-200 hover:bg-blue-50 disabled:opacity-50 transition-colors"
          >
            {{ loadingHistorial ? 'Cargando...' : 'Cargar más' }}
          </button>
          <p v-else class="text-xs text-gray-400">No hay más entregas.</p>
        </div>
      </div>
    </template>

    <!-- Modal detalle -->
    <EntregaDetalleModal
      v-if="itemActivo"
      :despacho-item-id="itemActivo.id"
      @cerrar="cerrarDetalle"
      @entregado="trasEntregar"
    />
  </div>
</template>
