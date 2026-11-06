<script setup>
/**
 * Escoger una ficha suelta (sin producto, casi siempre del Excel) para usarla como la
 * receta de un producto. Las que se parecen al producto van primero.
 */
import { ref, computed } from 'vue'
import { MagnifyingGlassIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import PanelCostos from './PanelCostos.vue'
import { useCostos } from './useCostos'

const props = defineProps({
  fichas:    { type: Array, default: () => [] },
  primero:   { type: Array, default: () => [] },   // ids sugeridos
  subtitulo: { type: String, default: '' },
  ocupado:   { type: Boolean, default: false },
})
const emit = defineEmits(['elegir', 'cerrar'])

const { formatPeso } = useCostos()
const busqueda = ref('')

const lista = computed(() => {
  const q = busqueda.value.trim().toLowerCase()
  const filtradas = q
    ? props.fichas.filter(f => f.nombre.toLowerCase().includes(q) || (f.categoria ?? '').toLowerCase().includes(q))
    : props.fichas
  const sug = new Set(props.primero)
  return [...filtradas].sort((a, b) => (sug.has(b.id) ? 1 : 0) - (sug.has(a.id) ? 1 : 0))
})
const esSugerida = id => props.primero.includes(id)
</script>

<template>
  <PanelCostos titulo="Usar una ficha que ya existe" :subtitulo="subtitulo" encima ancho="sm:max-w-lg" @cerrar="emit('cerrar')">
    <div class="sticky top-0 bg-white px-4 pt-3 pb-2 border-b border-gray-100">
      <div class="relative">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input v-model="busqueda" type="search" placeholder="Buscar ficha…" autofocus
          class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
    </div>
    <p v-if="!lista.length" class="text-center py-12 text-sm text-gray-400">Ninguna ficha sin producto se llama así.</p>
    <ul v-else class="divide-y divide-gray-100">
      <li v-for="f in lista" :key="f.id">
        <button @click="emit('elegir', f)" :disabled="ocupado"
          class="w-full flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50 text-left disabled:opacity-50">
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-800 truncate">{{ f.nombre }}</p>
            <p class="text-xs text-gray-400 truncate">
              <span v-if="esSugerida(f.id)" class="inline-flex items-center gap-0.5 text-blue-600 font-semibold">
                <SparklesIcon class="w-3 h-3" />Se parece ·
              </span>
              {{ f.categoria?.toLowerCase() }}<template v-if="f.producto_apagado"> · su producto está apagado</template>
            </p>
          </div>
          <span class="text-sm font-semibold text-gray-800 flex-shrink-0 tabular-nums">{{ formatPeso(f.costo_total) }}</span>
        </button>
      </li>
    </ul>
  </PanelCostos>
</template>
