<script setup>
/** Buscador del catálogo de materiales, para agregar o cambiar el material de un ítem. */
import { ref, onMounted } from 'vue'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { getMateriales } from '@/api/materiales'
import AppSpinner from '@/components/common/AppSpinner.vue'
import PanelCostos from './PanelCostos.vue'
import { useCostos } from './useCostos'

const props = defineProps({
  titulo:    { type: String, default: 'Elegir material' },
  subtitulo: { type: String, default: '' },
  inicial:   { type: String, default: '' },
  excluir:   { type: Number, default: null },   // al reemplazar, no ofrecer el mismo
  // Deja usar lo escrito aunque no esté en el catálogo (queda marcado para revisar)
  permiteLibre: { type: Boolean, default: false },
})
const emit = defineEmits(['elegir', 'cerrar'])

const { formatPeso } = useCostos()

const busqueda   = ref(props.inicial)
const resultados = ref([])
const cargando   = ref(false)
let debounce     = null

async function buscar() {
  cargando.value = true
  try {
    const { data } = await getMateriales(busqueda.value)
    resultados.value = data.filter(m => m.id !== props.excluir)
  } finally {
    cargando.value = false
  }
}

function onInput() {
  clearTimeout(debounce)
  debounce = setTimeout(buscar, 300)
}

onMounted(buscar)
</script>

<template>
  <PanelCostos :titulo="titulo" :subtitulo="subtitulo" encima ancho="sm:max-w-lg" @cerrar="emit('cerrar')">
    <div class="sticky top-0 bg-white px-4 pt-3 pb-2 border-b border-gray-100">
      <div class="relative">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input v-model="busqueda" @input="onInput" type="search" placeholder="Buscar material…" autofocus
          class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
    </div>

    <button v-if="permiteLibre && busqueda.trim() && !resultados.some(m => m.nombre.toLowerCase() === busqueda.trim().toLowerCase())"
      @click="emit('elegir', { id: null, nombre: busqueda.trim().toUpperCase(), unidad: null, precio_unitario: 0 })"
      class="w-full text-left px-4 py-3 border-b border-gray-100 hover:bg-gray-50">
      <span class="block text-sm text-gray-800">Usar «{{ busqueda.trim() }}» sin catálogo</span>
      <span class="block text-xs text-gray-400">Tendrás que poner el precio a mano y no se actualizará solo</span>
    </button>

    <AppSpinner v-if="cargando && !resultados.length" />
    <p v-else-if="!resultados.length" class="text-center py-12 text-sm text-gray-400">
      Ningún material se llama así. Créalo en la pestaña Materiales para que su precio se mantenga al día.
    </p>
    <ul v-else class="divide-y divide-gray-100">
      <li v-for="m in resultados" :key="m.id">
        <button @click="emit('elegir', m)" class="w-full flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50 text-left">
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-800 truncate">{{ m.nombre }}</p>
            <p class="text-xs text-gray-400 truncate">
              {{ m.unidad || 'Sin unidad' }}<template v-if="m.descripcion"> · {{ m.descripcion }}</template>
            </p>
          </div>
          <span class="text-sm font-semibold text-gray-800 flex-shrink-0">{{ formatPeso(m.precio_unitario) }}</span>
        </button>
      </li>
    </ul>
  </PanelCostos>
</template>
