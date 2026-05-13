<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { CheckCircleIcon, ArchiveBoxArrowDownIcon, ClockIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline'
import { getPendientesDespacho, completarDespacho, getHistorialDespacho } from '@/api/produccion'
import { useToast } from '@/composables/useToast'
import { useRealtime } from '@/composables/useRealtime'
import { useDespachoProduccionStore } from '@/stores/despachoProduccion'
import EmptyState from '@/components/common/EmptyState.vue'

const router       = useRouter()
const toast        = useToast()
const despachoProd = useDespachoProduccionStore()

function verOrden(ordenId) {
  if (ordenId) router.push({ name: 'orden-detalle', params: { id: ordenId } })
}

const tab = ref('pendientes')

const items          = ref([])
const loading        = ref(true)
const completandoId  = ref(null)
const mostrarModal   = ref(false)
const itemConfirmar  = ref(null)

const historial        = ref([])
const loadingHistorial = ref(false)

async function cargar() {
  loading.value = true
  try {
    const { data } = await getPendientesDespacho()
    items.value = Array.isArray(data) ? data : []
    despachoProd.pendientes = items.value
  } catch {
    items.value = []
  } finally {
    loading.value = false
  }
}

async function cargarHistorial() {
  loadingHistorial.value = true
  try {
    const { data } = await getHistorialDespacho()
    historial.value = Array.isArray(data) ? data : []
  } catch {
    historial.value = []
  } finally {
    loadingHistorial.value = false
  }
}

function abrirConfirmar(item) {
  itemConfirmar.value = item
  mostrarModal.value  = true
}

async function confirmarDespacho() {
  const item = itemConfirmar.value
  if (!item) return
  completandoId.value = item.id
  mostrarModal.value  = false
  try {
    await completarDespacho(item.id)
    toast.success('¡Producto listo para entrega!')
    await cargar()
    await cargarHistorial()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al completar el despacho.')
  } finally {
    completandoId.value = null
  }
}

function formatFecha(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(String(dateStr).substring(0, 10) + 'T00:00:00')
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

const PROCESO_LABEL = { ebanisteria: 'Ebanistería', tapizado: 'Tapizado', laca: 'Laca' }

const { listen } = useRealtime()

onMounted(async () => {
  await Promise.all([cargar(), cargarHistorial()])
  listen('produccion', 'produccion.actualizada', cargar)
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <ArchiveBoxArrowDownIcon class="w-6 h-6 text-purple-600" />
      <h2 class="text-lg font-bold text-gray-800 flex-1">Despacho de producción</h2>
    </div>
    <p class="text-xs text-gray-500">
      Productos que completaron todos sus pasos de producción y están listos para enviarse a entrega.
    </p>

    <!-- Tabs -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'pendientes'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'pendientes' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Pendientes
        <span v-if="items.length" class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs bg-purple-100 text-purple-700 rounded-full font-bold">{{ items.length }}</span>
      </button>
      <button
        @click="tab = 'historial'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'historial' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Historial
      </button>
    </div>

    <!-- ── TAB PENDIENTES ─────────────────────────────────────────────────── -->
    <template v-if="tab === 'pendientes'">

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Empty -->
    <EmptyState
      v-else-if="items.length === 0"
      message="No hay productos pendientes de despacho."
    />

    <!-- Lista -->
    <template v-else>
      <ul class="space-y-3">
        <li
          v-for="item in items"
          :key="item.id"
          class="bg-white rounded-xl shadow-sm p-4 space-y-3"
        >
          <!-- Producto -->
          <div class="flex items-start justify-between gap-2">
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">{{ item.orden_item?.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.orden_item?.producto?.categoria }}</p>
            </div>
            <span class="bg-purple-100 text-purple-700 text-xs font-medium px-2.5 py-1 rounded-full flex-shrink-0">
              Listo producción
            </span>
          </div>

          <!-- Pasos completados -->
          <div v-if="item.pasos && item.pasos.length" class="flex items-center gap-2 flex-wrap">
            <span
              v-for="paso in item.pasos"
              :key="paso.id"
              class="inline-flex items-center gap-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium"
            >
              <CheckCircleIcon class="w-3.5 h-3.5" />
              {{ PROCESO_LABEL[paso.tipo_proceso] }}
            </span>
          </div>

          <!-- Info -->
          <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
            <div>
              <p class="text-gray-400">Cliente</p>
              <p class="font-medium text-gray-700">{{ item.orden_item?.orden?.cliente?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Teléfono</p>
              <p class="font-medium text-gray-700">{{ item.orden_item?.orden?.cliente?.telefono }}</p>
            </div>
            <div>
              <p class="text-gray-400">Vendedor</p>
              <p class="font-medium text-gray-700">{{ item.orden_item?.orden?.vendedor?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Tienda</p>
              <p class="font-medium text-gray-700">{{ item.orden_item?.orden?.tienda?.nombre }}</p>
            </div>
          </div>

          <!-- Specs -->
          <div
            v-if="item.orden_item?.specs_personalizacion"
            class="bg-gray-50 rounded-lg px-3 py-2 text-xs text-gray-600 space-y-0.5"
          >
            <p
              v-for="(val, key) in item.orden_item.specs_personalizacion"
              :key="key"
            >
              <span class="text-gray-400 capitalize">{{ key }}:</span> {{ val }}
            </p>
          </div>

          <!-- Botones -->
          <div class="flex gap-2">
            <button
              @click="verOrden(item.orden_item?.orden?.id)"
              class="flex-1 bg-gray-100 text-gray-700 rounded-xl py-2.5 text-sm font-semibold hover:bg-gray-200 transition-colors flex items-center justify-center gap-1.5"
            >
              <ArrowTopRightOnSquareIcon class="w-4 h-4" />
              Ver orden
            </button>
            <button
              @click="abrirConfirmar(item)"
              :disabled="completandoId === item.id"
              class="flex-[2] bg-purple-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-purple-700 disabled:opacity-50 transition-colors flex items-center justify-center gap-2"
            >
              <CheckCircleIcon class="w-5 h-5" />
              {{ completandoId === item.id ? 'Procesando...' : 'Listo — enviar a entrega' }}
            </button>
          </div>
        </li>
      </ul>
    </template>
    </template><!-- /tab pendientes -->

    <!-- ── TAB HISTORIAL ──────────────────────────────────────────────────── -->
    <template v-if="tab === 'historial'">
      <AppSpinner v-if="loadingHistorial" />

      <EmptyState
        v-else-if="historial.length === 0"
        message="Todavía no has despachado ningún producto."
      />

      <ul v-else class="space-y-3">
        <li
          v-for="prod in historial"
          :key="prod.id"
          class="bg-white rounded-xl shadow-sm p-4 space-y-3"
        >
          <!-- Foto + nombre -->
          <div class="flex items-start gap-3">
            <img
              v-if="prod.orden_item?.producto?.foto_url"
              :src="prod.orden_item.producto.foto_url"
              :alt="prod.orden_item.producto.nombre"
              class="w-16 h-16 rounded-xl object-cover flex-shrink-0 border border-gray-100"
            />
            <div v-else class="w-16 h-16 rounded-xl bg-gray-100 flex-shrink-0 flex items-center justify-center">
              <ArchiveBoxArrowDownIcon class="w-7 h-7 text-gray-300" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-1">
                <span :class="['inline-block text-xs font-bold px-2.5 py-1 rounded-full mb-1', prod.estado === 'entregado' ? 'bg-gray-100 text-gray-600' : 'bg-green-100 text-green-700']">
                  {{ prod.estado === 'entregado' ? 'Entregado' : 'Listo entrega' }}
                </span>
                <span class="text-xs text-gray-400 flex items-center gap-1 flex-shrink-0">
                  <ClockIcon class="w-3.5 h-3.5" />
                  {{ formatFecha(prod.fecha_real) }}
                </span>
              </div>
              <p class="font-semibold text-sm text-gray-800 truncate">{{ prod.orden_item?.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ prod.orden_item?.producto?.categoria }}</p>
            </div>
          </div>

          <!-- Pasos completados -->
          <div v-if="prod.pasos?.length" class="flex items-center gap-2 flex-wrap">
            <span
              v-for="paso in prod.pasos"
              :key="paso.id"
              class="inline-flex items-center gap-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium"
            >
              <CheckCircleIcon class="w-3.5 h-3.5" />
              {{ PROCESO_LABEL[paso.tipo_proceso] }}
            </span>
          </div>

          <!-- Info -->
          <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
            <div>
              <p class="text-gray-400">Cliente</p>
              <p class="font-medium text-gray-700">{{ prod.orden_item?.orden?.cliente?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Teléfono</p>
              <p class="font-medium text-gray-700">{{ prod.orden_item?.orden?.cliente?.telefono ?? '—' }}</p>
            </div>
            <div>
              <p class="text-gray-400">Vendedor</p>
              <p class="font-medium text-gray-700">{{ prod.orden_item?.orden?.vendedor?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Tienda</p>
              <p class="font-medium text-gray-700">{{ prod.orden_item?.orden?.tienda?.nombre }}</p>
            </div>
          </div>

          <!-- Ver orden -->
          <button
            @click="verOrden(prod.orden_item?.orden?.id)"
            class="w-full bg-gray-100 text-gray-700 rounded-xl py-2.5 text-sm font-semibold hover:bg-gray-200 transition-colors flex items-center justify-center gap-1.5"
          >
            <ArrowTopRightOnSquareIcon class="w-4 h-4" />
            Ver orden
          </button>
        </li>
      </ul>
    </template><!-- /tab historial -->

    <!-- Modal de confirmación -->
    <Transition name="fade">
      <div v-if="mostrarModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <h3 class="text-lg font-bold text-gray-800">¿Confirmar despacho?</h3>
          <p class="text-sm text-gray-600">
            Vas a marcar
            <strong>{{ itemConfirmar?.orden_item?.producto?.nombre }}</strong>
            como listo para entrega. La orden pasará al área de despacho.
          </p>
          <div class="flex gap-3">
            <button @click="mostrarModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="confirmarDespacho" class="flex-1 bg-purple-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-purple-700">
              Sí, listo
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
