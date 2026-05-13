<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import {
  MagnifyingGlassIcon,
  PlusIcon,
  ChevronRightIcon,
  MapPinIcon,
} from '@heroicons/vue/24/outline'
import { getUsuarios } from '@/api/usuarios'
import { getTiendas } from '@/api/ordenes'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()

const usuarios = ref([])
const tiendas = ref([])
const loading = ref(true)
const showFilters = ref(false)
const cargandoMas = ref(false)
const tieneMas = ref(false)
const sentinel = ref(null)
let observer = null

const paginaActual = ref(1)
const busqueda = ref('')
const filtros = ref({
  rol: '',
  tienda_id: '',
  estado: '',
})

async function cargarTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
}

async function cargarUsuarios(reset = true) {
  if (reset) {
    loading.value = true
    usuarios.value = []
    paginaActual.value = 1
  }
  try {
    const params = { page: paginaActual.value }
    if (!reset) params.page = paginaActual.value + 1
    if (busqueda.value.trim()) params.search = busqueda.value.trim()
    if (filtros.value.rol) params.rol = filtros.value.rol
    if (filtros.value.tienda_id) params.tienda_id = filtros.value.tienda_id
    if (filtros.value.estado) params.estado = filtros.value.estado

    const { data } = await getUsuarios(params)
    if (reset) {
      usuarios.value = data.data
    } else {
      usuarios.value.push(...data.data)
    }
    paginaActual.value = data.current_page
    tieneMas.value = data.current_page < data.last_page
  } catch {
    if (reset) usuarios.value = []
  } finally {
    loading.value = false
    cargandoMas.value = false
    if (tieneMas.value) nextTick(setupObserver)
  }
}

function loadMore() {
  if (cargandoMas.value || !tieneMas.value) return
  cargandoMas.value = true
  cargarUsuarios(false)
}

function setupObserver() {
  if (observer) observer.disconnect()
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && tieneMas.value && !cargandoMas.value) {
      loadMore()
    }
  }, { rootMargin: '200px' })
  nextTick(() => {
    if (sentinel.value) observer.observe(sentinel.value)
  })
}

function applyFilters() {
  showFilters.value = false
  cargarUsuarios()
}

function clearFilters() {
  filtros.value = { rol: '', tienda_id: '', estado: '' }
  showFilters.value = false
  cargarUsuarios()
}

function goToUsuario(id) {
  router.push({ name: 'usuario-detalle', params: { id } })
}

function goToCrear() {
  router.push({ name: 'usuario-crear' })
}

const rolBadgeCls = (rol) => {
  const m = {
    supervisor:  'bg-blue-100 text-blue-700',
    conductor:   'bg-amber-100 text-amber-700',
    ebanista:    'bg-orange-100 text-orange-700',
    despachador: 'bg-purple-100 text-purple-700',
  }
  return m[rol] ?? 'bg-gray-100 text-gray-600'
}

const rolLabel = (u) => {
  const m = { supervisor: 'Supervisor', conductor: 'Conductor', ebanista: 'Ebanista', despachador: 'Despachador', vendedor: 'Vendedor' }
  let label = m[u.rol] ?? u.rol
  if (u.rol === 'supervisor' && u.es_tapicero) label += ' · Tapicero'
  return label
}

const estadoBadgeCls = (activo) =>
  activo
    ? 'bg-green-100 text-green-700'
    : 'bg-red-100 text-red-700'

const estadoLabel = (activo) => activo ? 'Activo' : 'Inactivo'

onMounted(async () => {
  await cargarTiendas()
  await cargarUsuarios()
})

onBeforeUnmount(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Trabajadores</h2>
      <button
        @click="showFilters = !showFilters"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors"
      >
        {{ showFilters ? 'Cerrar' : 'Filtros' }}
      </button>
      <button
        @click="goToCrear"
        class="bg-blue-600 text-white rounded-lg p-2 hover:bg-blue-700 transition-colors"
      >
        <PlusIcon class="w-5 h-5" />
      </button>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="cargarUsuarios"
        placeholder="Buscar por nombre o email..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtros -->
    <div v-if="showFilters" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Rol</label>
        <select v-model="filtros.rol" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todos</option>
          <option value="vendedor">Vendedores</option>
          <option value="supervisor">Supervisores</option>
          <option value="conductor">Conductores</option>
          <option value="ebanista">Ebanistas</option>
          <option value="despachador">Despachadores</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
        <select v-model="filtros.tienda_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
        <select v-model="filtros.estado" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todos</option>
          <option value="1">Activos</option>
          <option value="0">Inactivos</option>
        </select>
      </div>
      <div class="flex gap-2">
        <button @click="clearFilters" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2 text-sm font-semibold hover:bg-gray-200">Limpiar</button>
        <button @click="applyFilters" class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700">Aplicar</button>
      </div>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Empty -->
    <EmptyState
      v-else-if="usuarios.length === 0"
      :message="busqueda ? 'No se encontraron usuarios.' : 'No hay usuarios registrados.'"
    />

    <!-- Lista -->
    <ul v-else class="space-y-2">
      <li
        v-for="u in usuarios"
        :key="u.id"
        @click="goToUsuario(u.id)"
        class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3 cursor-pointer hover:bg-blue-50 transition-colors active:bg-blue-100"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <p class="font-medium text-gray-800 truncate">{{ u.nombre }}</p>
            <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', rolBadgeCls(u.rol)]">
              {{ rolLabel(u) }}
            </span>
            <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', estadoBadgeCls(u.activo)]">
              {{ estadoLabel(u.activo) }}
            </span>
          </div>
          <p class="text-xs text-gray-400 truncate">{{ u.email }}</p>
          <p v-if="u.tienda_default && !['conductor','ebanista','despachador'].includes(u.rol)" class="text-xs text-gray-400 flex items-center gap-1 mt-0.5">
            <MapPinIcon class="w-3.5 h-3.5" />
            {{ u.tienda_default.nombre }}
          </p>
        </div>
        <ChevronRightIcon class="w-5 h-5 text-gray-300 flex-shrink-0" />
      </li>
    </ul>

    <!-- Sentinel infinite scroll -->
    <div ref="sentinel" class="py-4 text-center">
      <div v-if="cargandoMas" class="text-sm text-gray-400">Cargando más...</div>
      <div v-else-if="!tieneMas && usuarios.length > 0" class="text-xs text-gray-300">
        Mostrando {{ usuarios.length }} trabajadores
      </div>
    </div>
  </div>
</template>
