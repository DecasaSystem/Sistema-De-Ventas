<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { MagnifyingGlassIcon, Cog6ToothIcon } from '@heroicons/vue/24/outline'
import { XMarkIcon } from '@heroicons/vue/24/solid'
import { getOrdenes, getTiendas } from '@/api/ordenes'
import { useRealtime } from '@/composables/useRealtime'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()

const ordenes = ref([])
const loading = ref(true)
const loadingMore = ref(false)
const hasMore = ref(true)
const currentPage = ref(1)

const showFilters = ref(false)
const tiendas = ref([])
const busqueda = ref('')

const filtros = ref({
  estado: '',
  tienda_id: '',
  desde: '',
  hasta: '',
})

const estadosOpts = [
  { value: '', label: 'Todos' },
  { value: 'borrador', label: 'Borrador' },
  { value: 'pendiente_cotizacion', label: 'Pendiente cotización' },
  { value: 'pendiente_anticipo', label: 'Por iniciar producción' },
  { value: 'en_produccion', label: 'En producción' },
  { value: 'listo_entrega', label: 'Listo entrega' },
  { value: 'en_camino', label: 'En camino' },
  { value: 'entregado', label: 'Entregado' },
  { value: 'cancelado', label: 'Cancelado' },
]

const sentinel = ref(null)
let observer = null

async function loadTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
}

async function fetchOrdenes(page = 1, append = false) {
  if (page === 1) {
    loading.value = true
  } else {
    loadingMore.value = true
  }

  try {
    const params = { page }
    if (filtros.value.estado) params.estado = filtros.value.estado
    if (filtros.value.tienda_id) params.tienda_id = filtros.value.tienda_id
    if (filtros.value.desde) params.desde = filtros.value.desde
    if (filtros.value.hasta) params.hasta = filtros.value.hasta
    if (busqueda.value) params.search = busqueda.value

    const { data } = await getOrdenes(params)

    const list = data.data ?? []
    if (append) {
      ordenes.value = [...ordenes.value, ...list]
    } else {
      ordenes.value = list
    }

    hasMore.value = data.current_page < data.last_page
    currentPage.value = data.current_page
  } catch (e) {
    if (page === 1) ordenes.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function applyFilters() {
  showFilters.value = false
  currentPage.value = 1
  fetchOrdenes(1, false)
}

function clearFilters() {
  filtros.value = { estado: '', tienda_id: '', desde: '', hasta: '' }
  busqueda.value = ''
  showFilters.value = false
  currentPage.value = 1
  fetchOrdenes(1, false)
  setupObserver()
}

function seleccionarTienda(id) {
  filtros.value.tienda_id = id
  currentPage.value = 1
  fetchOrdenes(1, false)
  setupObserver()
}

function buscar() {
  currentPage.value = 1
  fetchOrdenes(1, false)
  setupObserver()
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  await fetchOrdenes(currentPage.value + 1, true)
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

function goToDetalle(id) {
  router.push({ name: 'orden-detalle', params: { id } })
}

function formatFecha(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })
}

const PASO_LABEL = {
  ebanisteria:          { text: 'Ebanistería',       cls: 'bg-orange-100 text-orange-700' },
  tapizado:             { text: 'Tapizado',           cls: 'bg-teal-100 text-teal-700'    },
  laca:                 { text: 'Laca',               cls: 'bg-indigo-100 text-indigo-700' },
  esqueleteria:         { text: 'Esqueletería',       cls: 'bg-yellow-100 text-yellow-700' },
  pintura:              { text: 'Pintura',            cls: 'bg-purple-100 text-purple-700' },
  costura:              { text: 'Costura',            cls: 'bg-pink-100 text-pink-700'    },
  pendiente_despachador:{ text: 'Lista p/ despacho',  cls: 'bg-purple-100 text-purple-700' },
}

function pasoInfo(paso) {
  return PASO_LABEL[paso] ?? null
}

const { listen } = useRealtime()

onMounted(async () => {
  await loadTiendas()
  await fetchOrdenes(1, false)
  setupObserver()

  listen('ordenes', 'orden.actualizada', () => {
    fetchOrdenes(1, false)
    setupObserver()
  })
})

onUnmounted(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Órdenes</h2>
      <button
        @click="showFilters = !showFilters"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors"
      >
        <XMarkIcon v-if="showFilters" class="w-4 h-4 inline-block mr-1" />
        <Cog6ToothIcon v-else class="w-4 h-4 inline-block mr-1" />
        {{ showFilters ? 'Cerrar' : 'Filtros' }}
      </button>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="buscar"
        placeholder="Buscar por cliente..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtro rápido por tienda -->
    <div v-if="tiendas.length" class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
      <button
        @click="seleccionarTienda('')"
        :class="[
          'shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          filtros.tienda_id === ''
            ? 'bg-blue-600 text-white border-blue-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'
        ]"
      >
        Todas
      </button>
      <button
        v-for="t in tiendas"
        :key="t.id"
        @click="seleccionarTienda(t.id)"
        :class="[
          'shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          filtros.tienda_id === t.id
            ? 'bg-blue-600 text-white border-blue-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'
        ]"
      >
        {{ t.nombre }}
      </button>
    </div>

    <!-- Panel de filtros -->
    <div v-if="showFilters" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <!-- Estado -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
        <select
          v-model="filtros.estado"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option v-for="e in estadosOpts" :key="e.value" :value="e.value">{{ e.label }}</option>
        </select>
      </div>

      <!-- Tienda -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
        <select
          v-model="filtros.tienda_id"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
      </div>

      <!-- Fechas -->
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Desde</label>
          <input
            v-model="filtros.desde"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Hasta</label>
          <input
            v-model="filtros.hasta"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
      </div>

      <!-- Botones -->
      <div class="flex gap-2">
        <button
          @click="clearFilters"
          class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2 text-sm font-semibold hover:bg-gray-200 transition-colors"
        >Limpiar</button>
        <button
          @click="applyFilters"
          class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 transition-colors"
        >Aplicar</button>
      </div>
    </div>

    <!-- Loading inicial -->
    <AppSpinner v-if="loading" />

    <!-- Empty state -->
    <EmptyState
      v-else-if="ordenes.length === 0"
      :message="busqueda ? 'No se encontraron órdenes.' : 'No hay órdenes registradas.'"
    />

    <!-- Lista de órdenes -->
    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="o in ordenes"
          :key="o.id"
          @click="goToDetalle(o.id)"
          class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:bg-blue-50 transition-colors active:bg-blue-100"
        >
          <div class="flex justify-between items-start gap-2">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1 flex-wrap">
                <span class="font-semibold text-sm text-gray-800">#{{ o.numero_orden ?? o.id }}</span>
                <BadgeEstado :estado="o.estado" />
                <span
                  v-if="o.atrasado"
                  class="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700"
                >⚠ Atrasado</span>
                <span
                  v-if="o.tipo === 'restauracion'"
                  class="text-xs font-semibold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700"
                >Restauración</span>
              </div>
              <p class="text-sm text-gray-600 truncate">{{ o.cliente?.nombre }}</p>
              <p class="text-xs text-gray-400 mt-0.5">{{ o.tienda?.nombre }} · {{ formatFecha(o.created_at) }}</p>
              <span
                v-if="o.paso_produccion_actual && pasoInfo(o.paso_produccion_actual)"
                :class="['inline-block mt-1.5 text-xs font-semibold px-2 py-0.5 rounded-full', pasoInfo(o.paso_produccion_actual).cls]"
              >
                En producción: {{ pasoInfo(o.paso_produccion_actual).text }}
              </span>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-sm font-semibold text-gray-700"><MoneyDisplay :amount="o.valor_total" /></p>
              <p
                v-if="o.saldo_pendiente > 0"
                class="text-xs font-medium text-red-500 mt-0.5"
              >
                Resta <MoneyDisplay :amount="o.saldo_pendiente" />
              </p>
              <p v-else class="text-xs font-medium text-green-500 mt-0.5">Pagada</p>
            </div>
          </div>
        </li>
      </ul>

      <!-- Sentinel para scroll infinito -->
      <div ref="sentinel" class="py-4 text-center">
        <div v-if="loadingMore" class="text-sm text-gray-400">Cargando más...</div>
        <div v-else-if="!hasMore" class="text-xs text-gray-300">No hay más órdenes.</div>
      </div>
    </template>
  </div>
</template>
