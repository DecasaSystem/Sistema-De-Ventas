<script setup>
import { ref, onMounted, nextTick } from 'vue'
import {
  MagnifyingGlassIcon, PlusIcon, ArchiveBoxIcon,
  PhotoIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'
import { getReservaInventario, addReservaStock, removeReservaStock, getReservaMovimientos, getReservaInfo } from '@/api/reserva'
import { getVariantes, addStockVariante } from '@/api/inventario'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { useToast } from '@/composables/useToast'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const toast = useToast()

const fabricaId      = ref(null)
const inventario     = ref([])
const busqueda       = ref('')
const loading        = ref(false)
const currentPage    = ref(1)
const tieneMas       = ref(false)
const loadingMore    = ref(false)
const sentinel       = ref(null)
let observer         = null

// Modal agregar stock
const mostrarEntrada  = ref(false)
const itemEntrada     = ref(null)
const cantEntrada     = ref(1)
const motivoEntrada   = ref('')
const loadEntrada     = ref(false)
const errEntrada      = ref('')

// Modal quitar stock
const mostrarSalida   = ref(false)
const itemSalida      = ref(null)
const cantSalida      = ref(1)
const motivoSalida    = ref('')
const loadSalida      = ref(false)
const errSalida       = ref('')

// Modal historial
const mostrarHistorial  = ref(false)
const itemHistorial     = ref(null)
const movimientos       = ref([])
const loadMovimientos   = ref(false)

// Foto modal
const fotoModal    = ref(false)
const fotoProducto = ref(null)

// Variantes tapizado
const variantesReserva    = ref({})   // { producto_id: variante[] }
const varianteCargando    = ref({})

// Modal stock variante
const mostrarStockVariante   = ref(false)
const varianteStockItem      = ref(null)  // { variante, productoId }
const varianteStockCant      = ref(1)
const varianteStockMotivo    = ref('')
const varianteStockLoading   = ref(false)
const varianteStockError     = ref('')

async function cargarVariantes(item) {
  const pid = item.producto_id
  if (variantesReserva.value[pid] !== undefined) return
  varianteCargando.value[pid] = true
  try {
    const { data } = await getVariantes(pid, fabricaId.value)
    variantesReserva.value[pid] = data
  } finally {
    varianteCargando.value[pid] = false
  }
}

async function cargarInventario(reset = false) {
  if (reset) loading.value = true
  try {
    const page = reset ? 1 : currentPage.value + 1
    const { data } = await getReservaInventario(busqueda.value.trim(), page)
    if (reset) {
      inventario.value = data.data
      variantesReserva.value = {}
    } else {
      inventario.value.push(...data.data)
    }
    currentPage.value = data.current_page
    tieneMas.value = data.current_page < data.last_page
    // Cargar variantes para tapizados
    nextTick(() => inventario.value.forEach(i => { if (i.producto?.es_tapizado) cargarVariantes(i) }))
  } catch {
    if (reset) inventario.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
  if (tieneMas.value) nextTick(setupObserver)
}

function abrirStockVariante(variante, productoId) {
  varianteStockItem.value = { variante, productoId }
  varianteStockCant.value = 1
  varianteStockMotivo.value = ''
  varianteStockError.value = ''
  mostrarStockVariante.value = true
}

async function guardarStockVariante() {
  varianteStockError.value = ''
  if (varianteStockCant.value < 1) { varianteStockError.value = 'Cantidad inválida.'; return }
  varianteStockLoading.value = true
  try {
    await addStockVariante({
      variante_id: varianteStockItem.value.variante.id,
      tienda_id: fabricaId.value,
      cantidad: varianteStockCant.value,
      motivo: varianteStockMotivo.value || undefined,
    })
    toast.success('Stock de variante agregado.')
    mostrarStockVariante.value = false
    const pid = varianteStockItem.value.productoId
    const { data } = await getVariantes(pid, fabricaId.value)
    variantesReserva.value[pid] = data
  } catch (e) {
    varianteStockError.value = e.response?.data?.message ?? 'Error al agregar stock.'
  } finally {
    varianteStockLoading.value = false
  }
}

function loadMore() {
  if (loadingMore.value || !tieneMas.value) return
  loadingMore.value = true
  cargarInventario(false)
}

function setupObserver() {
  if (observer) observer.disconnect()
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && tieneMas.value && !loadingMore.value) loadMore()
  }, { rootMargin: '200px' })
  nextTick(() => { if (sentinel.value) observer.observe(sentinel.value) })
}

function openEntrada(item) {
  itemEntrada.value = item
  cantEntrada.value = 1
  motivoEntrada.value = ''
  errEntrada.value = ''
  mostrarEntrada.value = true
}

async function guardarEntrada() {
  errEntrada.value = ''
  if (!cantEntrada.value || cantEntrada.value < 1) { errEntrada.value = 'Cantidad inválida.'; return }
  loadEntrada.value = true
  try {
    await addReservaStock({
      producto_id: itemEntrada.value.producto_id,
      cantidad: cantEntrada.value,
      motivo: motivoEntrada.value || undefined,
    })
    toast.success('Stock agregado a fábrica.')
    mostrarEntrada.value = false
    await cargarInventario(true)
  } catch (e) {
    errEntrada.value = e.response?.data?.message ?? 'Error al agregar stock.'
  } finally {
    loadEntrada.value = false
  }
}

function openSalida(item) {
  itemSalida.value = item
  cantSalida.value = 1
  motivoSalida.value = ''
  errSalida.value = ''
  mostrarSalida.value = true
}

async function guardarSalida() {
  errSalida.value = ''
  if (!cantSalida.value || cantSalida.value < 1) { errSalida.value = 'Cantidad inválida.'; return }
  loadSalida.value = true
  try {
    await removeReservaStock({
      producto_id: itemSalida.value.producto_id,
      cantidad: cantSalida.value,
      motivo: motivoSalida.value || undefined,
    })
    toast.success('Stock removido de fábrica.')
    mostrarSalida.value = false
    await cargarInventario(true)
  } catch (e) {
    errSalida.value = e.response?.data?.message ?? 'Error al quitar stock.'
  } finally {
    loadSalida.value = false
  }
}

async function openHistorial(item) {
  itemHistorial.value = item
  movimientos.value = []
  loadMovimientos.value = true
  mostrarHistorial.value = true
  try {
    const { data } = await getReservaMovimientos(item.producto_id)
    movimientos.value = data
  } finally {
    loadMovimientos.value = false
  }
}

onMounted(async () => {
  try { const { data } = await getReservaInfo(); fabricaId.value = data.id } catch {}
  cargarInventario(true)
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Reserva de Fábrica</h2>
    </div>

    <div class="flex items-center gap-2 bg-purple-50 rounded-lg px-3 py-2">
      <ArchiveBoxIcon class="w-4 h-4 text-purple-500 flex-shrink-0" />
      <p class="text-xs text-purple-700 font-medium">Productos terminados en fábrica — disponibles para venta directa al cliente</p>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="cargarInventario(true)"
        placeholder="Buscar por nombre o categoría..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
      />
    </div>

    <AppSpinner v-if="loading" />

    <EmptyState v-else-if="inventario.length === 0" message="No hay productos en reserva de fábrica." />

    <template v-else>
      <ul class="space-y-2">
        <li v-for="item in inventario" :key="item.producto_id" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
          <div class="flex items-start gap-3">
            <!-- Foto -->
            <button
              @click="item.producto?.foto_url && (fotoProducto = item.producto, fotoModal = true)"
              :class="['flex-shrink-0 w-12 h-12 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center',
                item.producto?.foto_url ? 'cursor-pointer hover:opacity-75' : 'cursor-default']"
            >
              <img v-if="item.producto?.foto_url" :src="cloudinaryOpt(item.producto.foto_url, 160)" :alt="item.producto.nombre" class="w-full h-full object-cover" />
              <PhotoIcon v-else class="w-6 h-6 text-gray-300" />
            </button>

            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800 truncate">{{ item.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.producto?.categoria }}</p>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
              <button @click="openHistorial(item)" class="text-gray-500 text-xs font-medium flex items-center gap-1">
                <ArchiveBoxIcon class="w-4 h-4" /> Historial
              </button>
            </div>
          </div>

          <!-- Stock -->
          <div class="grid grid-cols-3 gap-2 text-center">
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-800">{{ item.cantidad_disponible }}</p>
              <p class="text-xs text-gray-400">Disponible</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-amber-600">{{ item.cantidad_reservada }}</p>
              <p class="text-xs text-gray-400">Reservado</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold" :class="item.stock_libre > 0 ? 'text-green-600' : 'text-red-500'">{{ item.stock_libre }}</p>
              <p class="text-xs text-gray-400">Libre</p>
            </div>
          </div>

          <!-- Acciones -->
          <div class="flex gap-2">
            <button @click="openEntrada(item)" class="flex-1 flex items-center justify-center gap-1.5 bg-green-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-green-700">
              <PlusIcon class="w-4 h-4" /> Agregar
            </button>
            <button @click="openSalida(item)" class="flex-1 flex items-center justify-center gap-1.5 bg-red-50 text-red-600 rounded-lg py-2 text-sm font-semibold hover:bg-red-100">
              <XMarkIcon class="w-4 h-4" /> Quitar
            </button>
          </div>

          <!-- Variantes tapizado -->
          <div v-if="item.producto?.es_tapizado" class="border-t border-gray-100 pt-2">
            <p class="text-xs font-medium text-purple-700 mb-2">Variantes de tela/color en fábrica</p>
            <div v-if="varianteCargando[item.producto_id]" class="text-xs text-gray-400">Cargando...</div>
            <template v-else-if="variantesReserva[item.producto_id]">
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="v in variantesReserva[item.producto_id]"
                  :key="v.id"
                  @click="abrirStockVariante(v, item.producto_id)"
                  :class="['px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
                    v.stock_libre > 0 ? 'bg-green-50 border-green-300 text-green-800' : 'bg-gray-50 border-gray-200 text-gray-400']"
                  :title="[v.marca, v.marca_tela, v.nombre_color].filter(Boolean).join(' · ') + ' — clic para agregar stock'"
                >
                  {{ v.marca_tela }} · {{ v.nombre_color }}
                  <span class="ml-1 font-bold">{{ v.stock_libre ?? 0 }}</span>
                </button>
                <span v-if="!variantesReserva[item.producto_id]?.length" class="text-xs text-gray-400 italic">Sin variantes — agrega stock base primero, luego asigna variantes</span>
              </div>
            </template>
          </div>
        </li>
      </ul>

      <div ref="sentinel" class="py-4 text-center">
        <div v-if="loadingMore" class="text-sm text-gray-400">Cargando más...</div>
        <div v-else-if="!tieneMas && inventario.length > 0" class="text-xs text-gray-300">{{ inventario.length }} productos</div>
      </div>
    </template>

    <!-- Lightbox foto -->
    <Transition name="fade">
      <div v-if="fotoModal" class="fixed inset-0 z-[60] flex items-center justify-center p-6" @click="fotoModal = false">
        <div class="absolute inset-0 bg-black/85" />
        <div class="relative w-full max-w-sm">
          <button @click="fotoModal = false" class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg">
            <XMarkIcon class="w-5 h-5 text-gray-700" />
          </button>
          <div class="bg-white rounded-2xl overflow-hidden shadow-2xl">
            <img :src="cloudinaryOpt(fotoProducto?.foto_url, 800)" :alt="fotoProducto?.nombre" class="w-full object-contain max-h-72" />
            <div class="px-4 py-3 border-t border-gray-100">
              <p class="text-sm font-semibold text-gray-800 text-center">{{ fotoProducto?.nombre }}</p>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal Agregar stock -->
    <Transition name="fade">
      <div v-if="mostrarEntrada" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarEntrada = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">Agregar a fábrica</h3>
              <p class="text-xs text-gray-500 mt-0.5 truncate">{{ itemEntrada?.producto?.nombre }}</p>
            </div>
            <button @click="mostrarEntrada = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
            <input v-model.number="cantEntrada" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="motivoEntrada" type="text" placeholder="Ej: Producción completada..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <p v-if="errEntrada" class="text-xs text-red-600">{{ errEntrada }}</p>
          <div class="flex gap-3">
            <button @click="mostrarEntrada = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarEntrada" :disabled="loadEntrada" class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-50">
              {{ loadEntrada ? 'Guardando...' : 'Agregar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal Quitar stock -->
    <Transition name="fade">
      <div v-if="mostrarSalida" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarSalida = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">Quitar de fábrica</h3>
              <p class="text-xs text-gray-500 mt-0.5 truncate">{{ itemSalida?.producto?.nombre }} — libre: {{ itemSalida?.stock_libre }}</p>
            </div>
            <button @click="mostrarSalida = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
            <input v-model.number="cantSalida" type="number" min="1" :max="itemSalida?.stock_libre" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="motivoSalida" type="text" placeholder="Ej: Pérdida, ajuste..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" />
          </div>
          <p v-if="errSalida" class="text-xs text-red-600">{{ errSalida }}</p>
          <div class="flex gap-3">
            <button @click="mostrarSalida = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarSalida" :disabled="loadSalida" class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-red-700 disabled:opacity-50">
              {{ loadSalida ? 'Guardando...' : 'Quitar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal stock variante tapizado -->
    <Transition name="fade">
      <div v-if="mostrarStockVariante" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarStockVariante = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">Agregar stock de variante</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                {{ [varianteStockItem?.variante?.marca, varianteStockItem?.variante?.marca_tela, varianteStockItem?.variante?.nombre_color].filter(Boolean).join(' · ') }}
              </p>
            </div>
            <button @click="mostrarStockVariante = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <p class="text-xs text-blue-600 bg-blue-50 rounded-lg px-3 py-2">Asegúrate de haber agregado el stock base del producto primero.</p>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
            <input v-model.number="varianteStockCant" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="varianteStockMotivo" type="text" placeholder="Producción completada..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <p v-if="varianteStockError" class="text-xs text-red-600">{{ varianteStockError }}</p>
          <div class="flex gap-3">
            <button @click="mostrarStockVariante = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarStockVariante" :disabled="varianteStockLoading" class="flex-1 bg-purple-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-purple-700 disabled:opacity-50">
              {{ varianteStockLoading ? 'Guardando...' : 'Agregar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal Historial -->
    <Transition name="fade">
      <div v-if="mostrarHistorial" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarHistorial = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[80vh] flex flex-col">
          <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <div>
              <h3 class="text-lg font-bold text-gray-800">Historial fábrica</h3>
              <p class="text-xs text-gray-500 mt-0.5">{{ itemHistorial?.producto?.nombre }}</p>
            </div>
            <button @click="mostrarHistorial = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-2">
            <div v-if="loadMovimientos" class="text-sm text-gray-400 text-center py-8">Cargando...</div>
            <div v-else-if="movimientos.length === 0" class="text-sm text-gray-400 text-center py-8">Sin movimientos</div>
            <div v-else v-for="m in movimientos" :key="m.id" class="flex items-start gap-3 py-2 border-b border-gray-50 last:border-0">
              <span class="mt-0.5 text-xs font-bold px-2 py-0.5 rounded-full shrink-0"
                :class="m.tipo === 'entrada' ? 'bg-green-100 text-green-700' : m.tipo === 'salida' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">
                {{ m.tipo === 'entrada' ? 'Entrada' : m.tipo === 'salida' ? 'Salida' : 'Reserva' }}
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800">{{ m.cantidad }} unidad(es)</p>
                <p class="text-xs text-gray-500 truncate">{{ m.motivo ?? '—' }}</p>
                <p class="text-xs text-gray-400">{{ new Date(m.created_at).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
