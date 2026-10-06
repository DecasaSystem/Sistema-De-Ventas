<script setup>
/**
 * Buscador de productos del catálogo de venta, para vincular una ficha y ver su
 * margen. Cada resultado ya muestra el margen que daría con el costo de la ficha.
 */
import { ref, onMounted } from 'vue'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { buscarProductos } from '@/api/fichas'
import { cloudinaryOpt } from '@/utils/cloudinary'
import AppSpinner from '@/components/common/AppSpinner.vue'
import PanelCostos from './PanelCostos.vue'
import { useCostos } from './useCostos'

const props = defineProps({
  ficha:   { type: Object, required: true },
  ocupado: { type: Boolean, default: false },
})
const emit = defineEmits(['elegir', 'cerrar'])

const { formatPeso, formatFactor, margenDe, COLOR_MARGEN } = useCostos()

// Se arranca buscando por la primera palabra útil del nombre ("SOFA MODERNO…" → "sofa")
const busqueda   = ref((props.ficha.nombre.split(/\s+/).find(w => w.length >= 4) || '').toLowerCase())
const resultados = ref([])
const cargando   = ref(false)
let debounce     = null

async function buscar() {
  cargando.value = true
  try {
    const { data } = await buscarProductos(busqueda.value)
    resultados.value = data
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
  <PanelCostos titulo="Vincular producto" :subtitulo="`Ficha: ${ficha.nombre}`" encima ancho="sm:max-w-lg" @cerrar="emit('cerrar')">
    <div class="sticky top-0 bg-white px-4 pt-3 pb-2 border-b border-gray-100">
      <div class="relative">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input v-model="busqueda" @input="onInput" type="search" placeholder="Buscar en el catálogo de venta…" autofocus
          class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <p class="text-xs text-gray-400 mt-1.5">El margen de cada producto se calcula con el costo de esta ficha.</p>
    </div>

    <AppSpinner v-if="cargando && !resultados.length" />
    <p v-else-if="!resultados.length" class="text-center py-12 text-sm text-gray-400">Ningún producto activo se llama así.</p>
    <ul v-else class="divide-y divide-gray-100">
      <li v-for="p in resultados" :key="p.id">
        <button @click="emit('elegir', p)" :disabled="ocupado"
          class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 text-left disabled:opacity-50">
          <img v-if="p.foto_url" :src="cloudinaryOpt(p.foto_url, 96)" alt=""
            class="w-10 h-10 rounded-lg object-cover flex-shrink-0 border border-gray-100" />
          <div v-else class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0" />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-800 truncate">{{ p.nombre }}</p>
            <p v-if="p.categoria" class="text-xs text-gray-400 truncate capitalize">{{ p.categoria }}</p>
          </div>
          <div class="text-right flex-shrink-0">
            <p class="text-sm font-semibold text-gray-800">{{ formatPeso(p.precio_base) }}</p>
            <span v-if="margenDe(ficha.costo_total, p.precio_base)"
              :class="['inline-block mt-0.5 text-[11px] font-semibold rounded-full px-2', COLOR_MARGEN[margenDe(ficha.costo_total, p.precio_base).nivel]]">
              {{ formatFactor(margenDe(ficha.costo_total, p.precio_base).factor) }}
            </span>
          </div>
        </button>
      </li>
    </ul>
  </PanelCostos>
</template>
