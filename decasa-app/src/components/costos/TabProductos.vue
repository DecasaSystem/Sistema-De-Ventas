<script setup>
/** Lista de fichas técnicas por categoría, con su costo, su margen y lo que hay que revisar. */
import { ref, computed, onMounted, onActivated } from 'vue'
import { MagnifyingGlassIcon, ChevronRightIcon, ExclamationTriangleIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { getFichas } from '@/api/fichas'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { useCostos } from './useCostos'

const emit = defineEmits(['abrir', 'categorias'])

const toast = useToast()
const { formatPeso, formatFactor, margenDe, COLOR_MARGEN, TEXTO_MARGEN, porRevisar } = useCostos()

const fichas     = ref([])
const categorias = ref([])
const cargando   = ref(false)
const busqueda   = ref('')
const categoria  = ref('')
const soloRevisar = ref(false)
let debounce = null

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getFichas({ search: busqueda.value, categoria: categoria.value })
    fichas.value     = data.fichas
    categorias.value = data.categorias
    emit('categorias', data.categorias)
  } catch {
    toast.error('No se pudieron cargar las fichas.')
  } finally {
    cargando.value = false
  }
}

function onBuscar() {
  clearTimeout(debounce)
  debounce = setTimeout(cargar, 350)
}

function elegirCategoria(c) {
  categoria.value = c
  cargar()
}

const paraRevisar = computed(() => fichas.value.filter(f => porRevisar(f) > 0).length)

const grupos = computed(() => {
  const lista = soloRevisar.value ? fichas.value.filter(f => porRevisar(f) > 0) : fichas.value
  const mapa = new Map()
  for (const f of lista) {
    if (!mapa.has(f.categoria)) mapa.set(f.categoria, [])
    mapa.get(f.categoria).push(f)
  }
  return [...mapa.entries()]
})

onMounted(cargar)
// Vuelve de otra pestaña (vive en KeepAlive): pudieron cambiar precios o tarifas
let primeraVez = true
onActivated(() => { if (primeraVez) { primeraVez = false; return } cargar() })
defineExpose({ cargar })
</script>

<template>
  <div class="space-y-3">
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
      <input v-model="busqueda" @input="onBuscar" type="search" placeholder="Buscar mueble o categoría…"
        class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
    </div>

    <div class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4 [scrollbar-width:none]">
      <button v-if="paraRevisar || soloRevisar" @click="soloRevisar = !soloRevisar"
        :class="['flex-shrink-0 flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-colors',
          soloRevisar ? 'bg-amber-500 text-white' : 'bg-amber-100 text-amber-800 hover:bg-amber-200']">
        <ExclamationTriangleIcon class="w-3.5 h-3.5" />Para revisar · {{ paraRevisar }}
      </button>
      <button @click="elegirCategoria('')"
        :class="['flex-shrink-0 px-3 py-1 rounded-full text-xs font-semibold transition-colors', categoria === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
        Todas
      </button>
      <button v-for="c in categorias" :key="c" @click="elegirCategoria(c)"
        :class="['flex-shrink-0 px-3 py-1 rounded-full text-xs font-semibold transition-colors capitalize', categoria === c ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
        {{ c.toLowerCase() }}
      </button>
    </div>

    <AppSpinner v-if="cargando && !fichas.length" />

    <div v-else-if="!grupos.length" class="text-center py-12 text-sm text-gray-400">
      <template v-if="soloRevisar">Nada para revisar con este filtro.</template>
      <template v-else-if="busqueda || categoria">Ninguna ficha coincide con la búsqueda.</template>
      <template v-else>Todavía no hay fichas técnicas. Crea la primera con «Nueva ficha».</template>
    </div>

    <template v-else>
    <section v-for="[cat, lista] in grupos" :key="cat">
      <h2 class="text-sm font-semibold text-gray-500 mb-1.5 px-1 capitalize">
        {{ cat.toLowerCase() }} <span class="font-normal text-gray-400">{{ lista.length }}</span>
      </h2>
      <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
        <button v-for="f in lista" :key="f.id" @click="emit('abrir', f.id)"
          class="w-full flex items-center gap-3 px-3 py-3 hover:bg-gray-50 text-left">
          <img v-if="f.foto_url" :src="cloudinaryOpt(f.foto_url, 96)" alt=""
            class="w-11 h-11 rounded-lg object-cover flex-shrink-0 border border-gray-100" />
          <div v-else class="w-11 h-11 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 text-gray-300">
            <PhotoIcon class="w-5 h-5" />
          </div>

          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-800 truncate">{{ f.nombre }}</p>
            <!-- Proporción materiales / mano de obra de un vistazo -->
            <div class="flex items-center gap-2 mt-1">
              <div class="h-1.5 w-16 rounded-full bg-gray-100 overflow-hidden flex flex-shrink-0" aria-hidden="true">
                <div class="h-full bg-blue-500" :style="{ width: `${(f.costo_materiales / (f.costo_total || 1)) * 100}%` }" />
                <div class="h-full bg-orange-400" :style="{ width: `${(f.costo_mano_obra / (f.costo_total || 1)) * 100}%` }" />
              </div>
              <span class="text-xs text-gray-400 truncate tabular-nums">
                {{ Math.round((f.costo_materiales / (f.costo_total || 1)) * 100) }}% material,
                {{ Math.round((f.costo_mano_obra / (f.costo_total || 1)) * 100) }}% mano de obra
              </span>
            </div>
          </div>

          <div class="text-right flex-shrink-0">
            <p class="text-sm font-bold text-gray-900 tabular-nums">{{ formatPeso(f.costo_total) }}</p>
            <div class="flex justify-end gap-1 mt-0.5">
              <span v-if="porRevisar(f)" class="inline-flex items-center gap-0.5 text-[11px] font-semibold rounded-full px-1.5 bg-amber-100 text-amber-800"
                :title="`${porRevisar(f)} ítem(s) para revisar`">
                <ExclamationTriangleIcon class="w-3 h-3" />{{ porRevisar(f) }}
              </span>
              <span v-if="margenDe(f.costo_total, f.producto_precio)"
                :class="['text-[11px] font-semibold rounded-full px-1.5 tabular-nums', COLOR_MARGEN[margenDe(f.costo_total, f.producto_precio).nivel]]"
                :title="`${TEXTO_MARGEN[margenDe(f.costo_total, f.producto_precio).nivel]} · se vende a ${formatPeso(f.producto_precio)}`">
                {{ formatFactor(margenDe(f.costo_total, f.producto_precio).factor) }}
              </span>
            </div>
          </div>
          <ChevronRightIcon class="w-4 h-4 text-gray-300 flex-shrink-0" />
        </button>
      </div>
    </section>
    </template>
  </div>
</template>
