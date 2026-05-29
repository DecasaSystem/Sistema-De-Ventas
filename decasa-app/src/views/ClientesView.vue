<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import {
  MagnifyingGlassIcon,
  ChevronRightIcon,
  PhoneIcon,
  IdentificationIcon,
  PlusIcon,
  UserGroupIcon,
  ArrowDownTrayIcon,
} from '@heroicons/vue/24/outline'
import { getClientes, createCliente, updateCliente, exportarClientes, CATEGORIAS_DISPONIBLES } from '@/api/clientes'
import EmptyState from '@/components/common/EmptyState.vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/api'

const auth = useAuthStore()
const tiendas = ref([])

const router = useRouter()

const clientes = ref([])
const loading  = ref(true)
const loadingMore = ref(false)
const hasMore = ref(true)
const currentPage = ref(1)
const busqueda = ref('')
const filtroTipo = ref('') // '', 'oficial', 'interesado'

const sentinel = ref(null)
let observer = null

const mostrarCrear = ref(false)
const formError = ref('')
const creando = ref(false)
const exportando = ref(false)

async function exportar() {
  exportando.value = true
  try {
    await exportarClientes({ tipo: filtroTipo.value, search: busqueda.value })
  } catch (e) {
    console.error('Error al exportar:', e)
  } finally {
    exportando.value = false
  }
}
const nuevo = ref({
  nombre: '',
  cedula: '',
  telefono: '',
  email: '',
  direccion: '',
  canal_pref: '',
  tipo: 'oficial',
  tienda_id: '',
  categorias_interes: [],
  notas_interes: '',
})

const canalesOpts = [
  { value: '', label: 'Sin definir' },
  { value: 'fisica', label: 'Física' },
  { value: 'whatsapp', label: 'WhatsApp' },
  { value: 'red_social', label: 'Red social' },
  { value: 'otro', label: 'Otro' },
]

const tiposFiltro = [
  { value: '', label: 'Todos' },
  { value: 'oficial', label: 'Oficiales' },
  { value: 'interesado', label: 'Interesados' },
]

async function fetchClientes(page = 1, append = false) {
  if (page === 1) {
    loading.value = true
  } else {
    loadingMore.value = true
  }

  try {
    const params = { page }
    if (busqueda.value) params.search = busqueda.value
    if (filtroTipo.value) params.tipo = filtroTipo.value

    const { data } = await getClientes(params)

    const list = data.data ?? []
    if (append) {
      clientes.value = [...clientes.value, ...list]
    } else {
      clientes.value = list
    }

    hasMore.value = data.current_page < data.last_page
    currentPage.value = data.current_page
  } catch (e) {
    if (page === 1) clientes.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function buscar(reset = true) {
  if (reset) {
    currentPage.value = 1
    setupObserver()      // desconecta observer viejo antes del nuevo fetch
    fetchClientes(1, false)
  }
}

function cambiarFiltro(tipo) {
  filtroTipo.value = tipo
  currentPage.value = 1
  setupObserver()
  fetchClientes(1, false)
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
  await fetchClientes(currentPage.value + 1, true)
}

function goToCliente(id) {
  router.push({ name: 'cliente-detalle', params: { id } })
}

function abrirCrear() {
  formError.value = ''
  nuevo.value = {
    nombre: '',
    cedula: '',
    telefono: '',
    email: '',
    direccion: '',
    canal_pref: '',
    tipo: 'oficial',
    tienda_id: auth.usuario?.tienda_default_id ?? '',
    categorias_interes: [],
    notas_interes: '',
  }
  mostrarCrear.value = true
}

async function guardar() {
  formError.value = ''
  if (!nuevo.value.nombre.trim()) {
    formError.value = 'El nombre es obligatorio.'
    return
  }
  creando.value = true
  try {
    const payload = { ...nuevo.value }
    if (!payload.tienda_id) delete payload.tienda_id
    await createCliente(payload)
    mostrarCrear.value = false
    await fetchClientes(1, false)
    setupObserver()
  } catch (e) {
    const msgs = e.response?.data?.message
    formError.value = typeof msgs === 'string' ? msgs : Object.values(msgs).flat().join(' ')
  } finally {
    creando.value = false
  }
}

onMounted(async () => {
  fetchClientes(1, false)
  setupObserver()
  if (auth.isSupervisor) {
    const { data } = await api.get('/tiendas')
    tiendas.value = data
  }
})

onUnmounted(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Clientes</h2>
      <button
        @click="exportar"
        :disabled="exportando || loading"
        class="text-sm text-green-600 font-medium px-3 py-1.5 rounded-lg border border-green-200 hover:bg-green-50 transition-colors flex items-center gap-1 disabled:opacity-50"
        :title="`Exportar ${filtroTipo === 'oficial' ? 'oficiales' : filtroTipo === 'interesado' ? 'interesados' : 'todos'} a Excel`"
      >
        <ArrowDownTrayIcon class="w-4 h-4" />
        {{ exportando ? 'Exportando...' : 'Excel' }}
      </button>
      <button
        @click="abrirCrear"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors flex items-center gap-1"
      >
        <PlusIcon class="w-4 h-4" />
        Crear
      </button>
    </div>

    <!-- Filtros de tipo -->
    <div class="flex gap-2 flex-wrap">
      <button
        v-for="t in tiposFiltro"
        :key="t.value"
        @click="cambiarFiltro(t.value)"
        :class="[
          'px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
          filtroTipo === t.value
            ? 'bg-blue-600 text-white border-blue-600'
            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
        ]"
      >{{ t.label }}</button>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="buscar(true)"
        placeholder="Buscar por nombre, cédula o teléfono..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Empty -->
    <EmptyState
      v-else-if="!loading && clientes.length === 0"
      :message="busqueda ? 'No se encontraron clientes.' : 'No hay clientes registrados.'"
    />

    <!-- Lista -->
    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="c in clientes"
          :key="c.id"
          @click="goToCliente(c.id)"
          class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3 cursor-pointer hover:bg-blue-50 transition-colors active:bg-blue-100"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <p class="font-medium text-gray-800 truncate">{{ c.nombre }}</p>
              <span
                v-if="c.tipo === 'interesado'"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"
              >
                <UserGroupIcon class="w-3 h-3" />
                Interesado
              </span>
            </div>
            <div class="flex items-center gap-3 mt-1">
              <span v-if="c.cedula" class="flex items-center gap-1 text-xs text-gray-400">
                <IdentificationIcon class="w-3.5 h-3.5" />
                {{ c.cedula }}
              </span>
              <span v-if="c.telefono" class="flex items-center gap-1 text-xs text-gray-400">
                <PhoneIcon class="w-3.5 h-3.5" />
                {{ c.telefono }}
              </span>
            </div>
          </div>
          <ChevronRightIcon class="w-5 h-5 text-gray-300 flex-shrink-0" />
        </li>
      </ul>

      <!-- Sentinel para scroll infinito -->
      <div ref="sentinel" class="py-4 text-center">
        <div v-if="loadingMore" class="text-sm text-gray-400">Cargando más...</div>
        <div v-else-if="!hasMore && clientes.length > 0" class="text-xs text-gray-300">No hay más clientes.</div>
      </div>
    </template>

    <!-- Modal crear cliente -->
    <Transition name="fade">
      <div v-if="mostrarCrear" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarCrear = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-3 max-h-[90vh] overflow-y-auto">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Nuevo cliente</h3>
            <button @click="mostrarCrear = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <!-- Tipo de cliente -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Tipo de cliente</label>
            <div class="flex gap-2">
              <button
                type="button"
                @click="nuevo.tipo = 'oficial'"
                :class="[
                  'flex-1 py-2 rounded-lg text-sm font-medium border transition-colors',
                  nuevo.tipo === 'oficial'
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'bg-white text-gray-700 border-gray-300'
                ]"
              >Oficial</button>
              <button
                type="button"
                @click="nuevo.tipo = 'interesado'"
                :class="[
                  'flex-1 py-2 rounded-lg text-sm font-medium border transition-colors',
                  nuevo.tipo === 'interesado'
                    ? 'bg-amber-500 text-white border-amber-500'
                    : 'bg-white text-gray-700 border-gray-300'
                ]"
              >Interesado</button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Nombre *</label>
            <input v-model="nuevo.nombre" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Nombre completo" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Cédula</label>
            <input v-model="nuevo.cedula" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Número de cédula" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Teléfono</label>
            <input v-model="nuevo.telefono" type="tel" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Teléfono de contacto" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
            <input v-model="nuevo.email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="correo@ejemplo.com" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Dirección</label>
            <input v-model="nuevo.direccion" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Dirección" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Canal preferido</label>
            <select v-model="nuevo.canal_pref" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option v-for="c in canalesOpts" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
          </div>

          <!-- Campos para cliente interesado -->
          <template v-if="nuevo.tipo === 'interesado'">

            <!-- Tienda (solo supervisor) -->
            <div v-if="auth.isSupervisor">
              <label class="block text-xs font-medium text-gray-500 mb-1">Tienda <span class="text-gray-400 font-normal">(¿en cuál tienda preguntó?)</span></label>
              <select
                v-model="nuevo.tienda_id"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400"
              >
                <option value="">Sin tienda asignada</option>
                <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">
                Categorías de interés
                <span class="text-gray-400 font-normal ml-1">(mantén presionado para elegir varias)</span>
              </label>
              <select
                v-model="nuevo.categorias_interes"
                multiple
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                size="5"
              >
                <option v-for="cat in CATEGORIAS_DISPONIBLES" :key="cat" :value="cat">{{ cat }}</option>
              </select>
              <div v-if="nuevo.categorias_interes.length > 0" class="flex flex-wrap gap-1.5 mt-2">
                <span
                  v-for="cat in nuevo.categorias_interes"
                  :key="cat"
                  class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"
                >{{ cat }}</span>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Notas de interés</label>
              <textarea v-model="nuevo.notas_interes" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none" placeholder="¿En qué está interesado? Presupuesto, medidas, referencia..."></textarea>
            </div>
          </template>

          <p v-if="formError" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ formError }}</p>

          <div class="flex gap-3 pt-1">
            <button @click="mostrarCrear = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-200">Cancelar</button>
            <button
              @click="guardar"
              :disabled="creando"
              class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
            >
              {{ creando ? 'Guardando...' : 'Guardar' }}
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
