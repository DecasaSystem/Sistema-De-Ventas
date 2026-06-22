<script setup>
import { ref, computed, onMounted } from 'vue'
import { MagnifyingGlassIcon, PlusIcon, MinusIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import api from '@/api'

const auth  = useAuthStore()
const toast = useToast()

const telas          = ref([])
const proveedores    = ref([])
const busqueda       = ref('')
const proveedorFiltro = ref('')
const cargando       = ref(true)
const showModal      = ref(false)
const modalTipo      = ref('recargar')
const telaActiva     = ref(null)
const metros         = ref('')
const nota           = ref('')
const guardando      = ref(false)
const modalError     = ref('')

const puedeRecargar  = computed(() => auth.puedeRecargarTelas)
const puedeDescontar = computed(() => auth.isCosturero || auth.isSupervisor)

const telasFiltradas = computed(() => {
  let lista = telas.value
  if (proveedorFiltro.value) {
    lista = lista.filter(t => t.marca === proveedorFiltro.value)
  }
  if (busqueda.value.trim()) {
    const q = busqueda.value.toLowerCase()
    lista = lista.filter(t =>
      t.tipo?.toLowerCase().includes(q) ||
      t.color?.toLowerCase().includes(q) ||
      t.marca?.toLowerCase().includes(q)
    )
  }
  return lista
})

async function cargar() {
  cargando.value = true
  try {
    const [{ data: telasData }, { data: provData }] = await Promise.all([
      api.get('/inventario-telas'),
      api.get('/inventario-telas/proveedores'),
    ])
    telas.value     = telasData
    proveedores.value = provData
  } catch {
    toast.error('Error al cargar el inventario de telas.')
  } finally {
    cargando.value = false
  }
}

function abrirRecargar(tela) {
  telaActiva.value = tela
  modalTipo.value  = 'recargar'
  metros.value     = ''
  nota.value       = ''
  modalError.value = ''
  showModal.value  = true
}

function abrirDescontar(tela) {
  telaActiva.value = tela
  modalTipo.value  = 'descontar'
  metros.value     = ''
  nota.value       = ''
  modalError.value = ''
  showModal.value  = true
}

async function confirmar() {
  modalError.value = ''
  const m = parseFloat(metros.value)
  if (!m || m <= 0) { modalError.value = 'Ingresa una cantidad válida.'; return }

  guardando.value = true
  try {
    const endpoint = modalTipo.value === 'recargar' ? '/inventario-telas/recargar' : '/inventario-telas/descontar'
    const { data } = await api.post(endpoint, {
      id:     telaActiva.value.id,
      metros: m,
      nota:   nota.value || undefined,
    })

    const idx = telas.value.findIndex(t => t.id === data.id)
    if (idx !== -1) {
      telas.value[idx] = data
    }
    showModal.value = false
    const nombreTela = `${data.tipo} (${data.color})`
    toast.success(
      modalTipo.value === 'recargar'
        ? `+${m} m agregados a ${nombreTela}`
        : `-${m} m descontados de ${nombreTela}`
    )
  } catch (e) {
    modalError.value = e.response?.data?.message ?? 'Error al actualizar.'
  } finally {
    guardando.value = false
  }
}

function colorBadge(metros) {
  if (metros <= 0) return 'bg-red-100 text-red-700'
  if (metros <= 3)  return 'bg-amber-100 text-amber-700'
  return 'bg-green-100 text-green-700'
}

onMounted(cargar)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-bold text-gray-800">Inventario de telas</h2>
      <span class="text-xs text-gray-400">{{ telasFiltradas.length }} / {{ telas.length }}</span>
    </div>

    <!-- Search -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
      <input
        v-model="busqueda"
        type="search"
        placeholder="Buscar por tipo, color, marca..."
        class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtro por marca/proveedor -->
    <div v-if="proveedores.length" class="flex flex-wrap gap-2">
      <button
        @click="proveedorFiltro = ''"
        :class="[
          'px-3 py-1 rounded-full text-xs font-semibold transition-colors',
          proveedorFiltro === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        ]"
      >
        Todas
      </button>
      <button
        v-for="prov in proveedores"
        :key="prov"
        @click="proveedorFiltro = prov"
        :class="[
          'px-3 py-1 rounded-full text-xs font-semibold transition-colors',
          proveedorFiltro === prov ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        ]"
      >
        {{ prov }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="cargando" class="flex justify-center py-12">
      <AppSpinner />
    </div>

    <!-- Empty -->
    <div v-else-if="!telasFiltradas.length" class="text-center py-12 text-sm text-gray-400">
      {{ busqueda || proveedorFiltro ? 'Sin resultados para el filtro actual.' : 'No hay telas en el inventario.' }}
    </div>

    <!-- List -->
    <div v-else class="space-y-2">
      <div
        v-for="tela in telasFiltradas"
        :key="tela.fuente + '-' + tela.id"
        class="bg-white rounded-xl shadow-sm p-4"
      >
        <!-- Title row -->
        <div class="flex items-start justify-between gap-2">
          <div class="flex-1 min-w-0">
            <!-- Catálogo: mostrar tipo como título, marca+color como sub -->
            <template v-if="tela.fuente === 'catalogo'">
              <p class="font-semibold text-sm text-gray-800 truncate">{{ tela.tipo }}</p>
              <p class="text-xs text-gray-500 mt-0.5">
                {{ tela.color }}<span class="text-gray-400"> · {{ tela.marca }}</span>
              </p>
            </template>
            <!-- Inventario (Excel): mostrar referencia como título -->
            <template v-else>
              <p class="font-semibold text-sm text-gray-800 truncate">{{ tela.referencia }}</p>
              <p class="text-xs text-gray-500 mt-0.5">
                <span v-if="tela.color">{{ tela.color }}</span>
                <span v-if="tela.color && tela.textura"> · </span>
                <span v-if="tela.textura">{{ tela.textura }}</span>
                <span v-if="tela.proveedor" class="text-gray-400"> · {{ tela.proveedor }}</span>
              </p>
            </template>
          </div>
          <span :class="['text-xs font-bold px-2.5 py-1 rounded-full whitespace-nowrap', colorBadge(tela.metros_libres)]">
            {{ tela.metros_libres }} m
          </span>
        </div>

        <!-- Actions -->
        <div v-if="puedeRecargar || puedeDescontar" class="flex gap-2 mt-3">
          <button
            v-if="puedeRecargar"
            @click="abrirRecargar(tela)"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-green-50 text-green-700 text-xs font-semibold hover:bg-green-100 transition-colors"
          >
            <PlusIcon class="w-3.5 h-3.5" />
            Recargar
          </button>
          <button
            v-if="puedeDescontar"
            @click="abrirDescontar(tela)"
            :disabled="tela.metros_libres <= 0"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 text-red-700 text-xs font-semibold hover:bg-red-100 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <MinusIcon class="w-3.5 h-3.5" />
            Descontar
          </button>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <Transition name="fade">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">
              {{ modalTipo === 'recargar' ? 'Agregar metros' : 'Descontar metros' }}
            </h3>
            <button @click="showModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <div class="bg-gray-50 rounded-lg px-3 py-2">
            <p class="text-sm font-semibold text-gray-800">
              {{ telaActiva?.tipo }} <span class="text-gray-500 font-normal">({{ telaActiva?.color }})</span>
            </p>
            <p class="text-xs text-gray-500 mt-0.5">
              Disponible: <strong>{{ telaActiva?.metros_libres }} m</strong>
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Metros a {{ modalTipo === 'recargar' ? 'agregar' : 'descontar' }}
            </label>
            <input
              v-model="metros"
              type="number"
              min="0.1"
              step="0.5"
              :max="modalTipo === 'descontar' ? telaActiva?.metros_libres : undefined"
              placeholder="0.0"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nota (opcional)</label>
            <input
              v-model="nota"
              type="text"
              placeholder="Motivo o referencia de compra..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <p v-if="modalError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ modalError }}</p>

          <div class="flex gap-3">
            <button @click="showModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button
              @click="confirmar"
              :disabled="guardando"
              :class="[
                'flex-1 rounded-lg py-2.5 text-sm font-semibold text-white disabled:opacity-50',
                modalTipo === 'recargar' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'
              ]"
            >
              {{ guardando ? 'Guardando...' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
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
