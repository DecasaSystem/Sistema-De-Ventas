<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useDespachoSocket } from '@/composables/useDespachoSocket'
import { misEntregas, historialMisEntregas } from '@/api/despacho'
import EntregaDetalleModal from '@/components/despacho/EntregaDetalleModal.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { TruckIcon, CheckCircleIcon, ClockIcon, MapPinIcon } from '@heroicons/vue/24/outline'

const socket = useDespachoSocket()

const tab          = ref('activas')
const entregas     = ref([])
const cargando     = ref(true)
const error        = ref('')
const itemActivo   = ref(null)

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
  } catch (e) {
    error.value = e.response?.data?.message || 'Error al cargar las entregas'
  } finally {
    cargando.value = false
  }
}

async function cargarHistorial(page = 1, append = false) {
  loadingHistorial.value = true
  try {
    const { data } = await historialMisEntregas({ page })
    const items = data.data ?? []
    historial.value = append ? [...historial.value, ...items] : items
    historialPage.value  = data.current_page
    historialHasMore.value = data.current_page < data.last_page
  } catch {} finally {
    loadingHistorial.value = false
  }
}

async function switchTab(t) {
  tab.value = t
  if (t === 'historial' && historial.value.length === 0) {
    await cargarHistorial(1, false)
  }
}

async function cargarMas() {
  if (!historialHasMore.value || loadingHistorial.value) return
  await cargarHistorial(historialPage.value + 1, true)
}

function abrirDetalle(item) {
  itemActivo.value = item
}

function cerrarDetalle() {
  itemActivo.value = null
}

async function trasEntregar() {
  await cargar()
  historial.value = []
  historialPage.value = 1
  historialHasMore.value = true
}

function fmtFecha(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('es-CO', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <TruckIcon class="w-6 h-6 text-blue-600" />
      <h1 class="text-xl font-bold text-gray-900 flex-1">Mis Entregas</h1>
      <span v-if="tab === 'activas' && entregas.length > 0" class="text-sm text-gray-500">
        {{ entregas.length }} pendiente(s)
      </span>
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

      <div v-else class="space-y-3">
        <template v-for="(item, idx) in entregas" :key="item.id">
          <!-- Banner de ruta al inicio de cada despacho -->
          <div
            v-if="idx === 0 || item.despacho_id !== entregas[idx - 1].despacho_id"
            class="bg-blue-600 rounded-xl p-4 text-white space-y-2"
          >
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2">
                <TruckIcon class="w-5 h-5 flex-shrink-0" />
                <p class="font-bold text-base">
                  {{ item.despacho?.nombre_ruta || 'Ruta de entregas' }}
                </p>
              </div>
              <span v-if="item.despacho?.fecha_despacho" class="text-xs text-blue-200 font-medium">
                {{ new Date(item.despacho.fecha_despacho + 'T12:00:00').toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric', month: 'short' }) }}
              </span>
            </div>

            <!-- Total a cobrar en esta ruta -->
            <div class="bg-white/15 rounded-lg px-3 py-2 flex items-center justify-between">
              <span class="text-xs text-blue-100">Total a cobrar en esta ruta</span>
              <span class="font-bold text-white">
                ${{ entregas
                  .filter(e => e.despacho_id === item.despacho_id)
                  .reduce((s, e) => s + (parseFloat(e.orden?.saldo_pendiente) || 0), 0)
                  .toLocaleString('es-CO') }}
              </span>
            </div>

            <!-- Instrucciones del supervisor -->
            <div v-if="item.despacho?.instrucciones" class="bg-white/15 rounded-lg px-3 py-2">
              <p class="text-xs text-blue-100 font-semibold mb-0.5">Instrucciones</p>
              <p class="text-sm text-white leading-snug">{{ item.despacho.instrucciones }}</p>
            </div>
          </div>

          <div
            @click="abrirDetalle(item)"
            class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 active:scale-[0.98] transition-transform cursor-pointer"
          >
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-sm font-bold">
              {{ idx + 1 }}
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

              <!-- Productos breve -->
              <p v-if="item.orden?.items?.length" class="text-xs text-gray-400 mt-1 truncate">
                {{ item.orden.items.map(i => i.producto?.nombre).filter(Boolean).join(', ') }}
              </p>

              <div class="flex items-center gap-3 mt-2 text-sm">
                <span class="text-gray-600">
                  <MoneyDisplay :amount="item.orden?.valor_total" />
                </span>
                <span v-if="item.orden?.saldo_pendiente > 0" class="text-orange-600 text-xs">
                  Saldo: <MoneyDisplay :amount="item.orden?.saldo_pendiente" />
                </span>
              </div>
            </div>
          </div>
          </div>
        </template>
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

              <!-- Productos breve -->
              <p v-if="item.orden?.items?.length" class="text-xs text-gray-400 mt-1 truncate">
                {{ item.orden.items.map(i => i.producto?.nombre).filter(Boolean).join(', ') }}
              </p>

              <div class="flex items-center justify-between mt-2">
                <span class="text-sm text-gray-600">
                  <MoneyDisplay :amount="item.orden?.valor_total" />
                </span>
                <span class="text-xs text-gray-400 flex items-center gap-1">
                  <ClockIcon class="w-3.5 h-3.5" />
                  {{ fmtFecha(item.entregado_at) }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Cargar más -->
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
