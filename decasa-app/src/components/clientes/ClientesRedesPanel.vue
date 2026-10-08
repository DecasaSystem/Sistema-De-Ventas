<script setup>
// Clientes → Redes: las personas que escribieron por WhatsApp o Instagram, con el nombre
// y el celular que le dieron a Elena antes de pasar con un asesor (dueño, 2026-10-08).
// Las fichas las arma el webhook de Redes; aquí se buscan, se filtran por estado y se
// abren para llamar, anotar o pasarlas a clientes.
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { MagnifyingGlassIcon, PhoneIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import { getClientesRedes } from '@/api/clientes'
import EmptyState from '@/components/common/EmptyState.vue'
import ClienteRedModal from './ClienteRedModal.vue'
import { ESTADOS_RED, canalBadge, haceCuanto, interesCorto } from './clientesRedes'

const lista = ref([])
const conteos = ref({})
const loading = ref(true)
const loadingMore = ref(false)
const hasMore = ref(false)
const pagina = ref(1)
const busqueda = ref('')
const estado = ref('nuevo')
const canal = ref('')
const abiertoId = ref(null)

const sentinel = ref(null)
let observer = null
let pedido = 0 // descarta respuestas viejas si se cambia de filtro rápido

async function cargar(page = 1) {
  const miPedido = ++pedido
  page === 1 ? (loading.value = true) : (loadingMore.value = true)
  try {
    const params = { page }
    if (busqueda.value.trim()) params.search = busqueda.value.trim()
    if (estado.value) params.estado = estado.value
    if (canal.value) params.canal = canal.value
    const { data } = await getClientesRedes(params)
    if (miPedido !== pedido) return
    lista.value = page === 1 ? (data.data ?? []) : [...lista.value, ...(data.data ?? [])]
    conteos.value = data.conteos ?? {}
    pagina.value = data.current_page
    hasMore.value = data.current_page < data.last_page
  } catch {
    if (page === 1 && miPedido === pedido) lista.value = []
  } finally {
    if (miPedido === pedido) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function filtrar(nuevoEstado) {
  if (nuevoEstado !== undefined) estado.value = nuevoEstado
  cargar(1)
}

function cambiarCanal(c) {
  canal.value = canal.value === c ? '' : c
  cargar(1)
}

function total() {
  return Object.values(conteos.value).reduce((s, n) => s + n, 0)
}

// La ficha cambió en el modal (estado, notas, convertida): se refleja sin recargar todo.
function alActualizar(ficha) {
  const i = lista.value.findIndex(f => f.id === ficha.id)
  if (i === -1) return
  const antes = lista.value[i].estado
  if (estado.value && ficha.estado !== estado.value) {
    lista.value.splice(i, 1)
  } else {
    lista.value[i] = { ...lista.value[i], ...ficha }
  }
  if (antes !== ficha.estado) {
    conteos.value = {
      ...conteos.value,
      [antes]: Math.max(0, (conteos.value[antes] ?? 1) - 1),
      [ficha.estado]: (conteos.value[ficha.estado] ?? 0) + 1,
    }
  }
}

onMounted(async () => {
  await cargar(1)
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && hasMore.value && !loadingMore.value && !loading.value) cargar(pagina.value + 1)
  }, { rootMargin: '200px' })
  nextTick(() => { if (sentinel.value) observer.observe(sentinel.value) })
})

onUnmounted(() => { if (observer) observer.disconnect() })
</script>

<template>
  <div class="space-y-3">
    <p class="text-xs text-gray-500">
      Quienes escribieron por WhatsApp o Instagram. Elena les pide nombre y celular antes de pasarlos con un asesor.
    </p>

    <!-- Estados -->
    <div class="flex gap-1.5 overflow-x-auto -mx-4 px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <button
        @click="filtrar('')"
        :class="['flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          estado === '' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']"
      >Todos <span class="opacity-70">{{ total() }}</span></button>
      <button
        v-for="e in ESTADOS_RED"
        :key="e.value"
        @click="filtrar(e.value)"
        :class="['flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          estado === e.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']"
      >{{ e.plural }} <span class="opacity-70">{{ conteos[e.value] ?? 0 }}</span></button>
    </div>

    <!-- Buscador + canal -->
    <div class="flex gap-2">
      <div class="relative flex-1">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
        <input
          v-model="busqueda"
          @keyup.enter="filtrar()"
          type="search"
          placeholder="Nombre, celular o producto..."
          class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>
      <div class="flex bg-gray-100 rounded-xl p-1 flex-shrink-0">
        <button
          v-for="c in ['whatsapp', 'instagram']"
          :key="c"
          @click="cambiarCanal(c)"
          :aria-pressed="canal === c"
          :class="['px-2.5 rounded-lg text-xs font-semibold transition-colors',
            canal === c ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >{{ canalBadge(c).label }}</button>
      </div>
    </div>

    <AppSpinner v-if="loading" />

    <EmptyState
      v-else-if="!lista.length"
      :message="busqueda ? 'No hay clientes de redes con esa búsqueda.' : 'Todavía no hay clientes de redes en este estado.'"
    />

    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="f in lista"
          :key="f.id"
          @click="abiertoId = f.id"
          class="bg-white rounded-xl shadow-sm p-4 flex items-start gap-3 cursor-pointer hover:bg-blue-50 transition-colors active:bg-blue-100"
        >
          <div class="flex-1 min-w-0 space-y-1">
            <div class="flex items-center gap-1.5 flex-wrap">
              <p class="font-medium text-gray-800 truncate max-w-[60%]">{{ f.nombre || 'Sin nombre' }}</p>
              <span :class="['px-1.5 py-0.5 rounded-full text-[10px] font-semibold', canalBadge(f.canal).class]">{{ canalBadge(f.canal).label }}</span>
              <span
                :class="['px-2 py-0.5 rounded-full text-[10px] font-semibold', ESTADOS_RED.find(e => e.value === f.estado)?.chip]"
              >{{ ESTADOS_RED.find(e => e.value === f.estado)?.label }}</span>
            </div>
            <p class="flex items-center gap-1 text-xs text-gray-500">
              <PhoneIcon class="w-3.5 h-3.5 flex-shrink-0" />
              <span v-if="f.telefono">{{ f.telefono }}</span>
              <span v-else class="italic text-gray-400">{{ f.no_quiso_dar_datos ? 'No quiso dejar celular' : 'Sin celular' }}</span>
              <span v-if="f.usuario_red" class="text-purple-500 truncate">· {{ f.usuario_red }}</span>
            </p>
            <p v-if="interesCorto(f)" class="text-xs text-gray-600 line-clamp-2">{{ interesCorto(f) }}</p>
            <p class="text-[11px] text-gray-400">
              {{ haceCuanto(f.ultimo_contacto_at) }}
              <span v-if="f.total_conversaciones > 1">· {{ f.total_conversaciones }} contactos</span>
              <span v-if="f.cliente" class="text-blue-500">· ya es cliente</span>
            </p>
          </div>
          <ChevronRightIcon class="w-5 h-5 text-gray-300 flex-shrink-0 mt-1" />
        </li>
      </ul>
    </template>

    <div ref="sentinel" class="py-4 text-center">
      <div v-if="loadingMore" class="text-sm text-gray-400">Cargando más...</div>
    </div>

    <ClienteRedModal
      v-if="abiertoId"
      :id="abiertoId"
      @cerrar="abiertoId = null"
      @actualizado="alActualizar"
    />
  </div>
</template>
