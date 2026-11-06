<script setup>
/**
 * Buscador de productos del catálogo de venta, para vincular una ficha y ver su
 * margen. Cada resultado ya muestra el margen que daría con el costo de la ficha.
 *
 * Al vincular, la ficha toma el nombre y la foto del producto (su nombre actual queda
 * como descripción). Si el producto tiene versiones con precio propio (la cama de 1.40,
 * la de 1.60), se pregunta de cuál es la receta: emite { producto, version }.
 */
import { ref, onMounted } from 'vue'
import { MagnifyingGlassIcon, ChevronLeftIcon } from '@heroicons/vue/24/outline'
import api from '@/api'
import { buscarProductos } from '@/api/fichas'
import IconoS from '@/components/common/IconoS.vue'
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

// ── Versión ──────────────────────────────────────────────────────────────────
const elegido      = ref(null)   // producto escogido que tiene versiones con precio propio
const versiones    = ref([])
const buscandoVers = ref(null)   // id del producto cuyas versiones se están trayendo

async function escoger(p) {
  buscandoVers.value = p.id
  try {
    const { data } = await api.get(`/productos/${p.id}/variante-configs`)
    // Solo las opciones que cambian el precio; `precio_adicional` es su precio completo (0 = el del producto)
    const vers = (data ?? []).filter(t => t.tipo?.afecta_precio).flatMap(t => t.items.map(i => ({
      config_id: i.id, opcion: i.opcion_nombre, tipo: t.tipo.nombre,
      precio: Number(i.precio_adicional) > 0 ? Number(i.precio_adicional) : Number(p.precio_base),
    })))
    if (!vers.length) { emit('elegir', { producto: p, version: null }); return }
    elegido.value   = p
    versiones.value = vers
  } catch {
    // Sin poder saber sus versiones, se vincula al producto (como antes)
    emit('elegir', { producto: p, version: null })
  } finally {
    buscandoVers.value = null
  }
}
</script>

<template>
  <PanelCostos titulo="Vincular producto" :subtitulo="`Ficha: ${ficha.nombre}`" encima ancho="sm:max-w-lg" @cerrar="emit('cerrar')">
    <!-- Paso 2: de cuál versión es la receta -->
    <template v-if="elegido">
      <div class="px-4 pt-3 pb-2 border-b border-gray-100">
        <button @click="elegido = null" class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700 mb-2">
          <ChevronLeftIcon class="w-3.5 h-3.5" />Otro producto
        </button>
        <p class="text-sm font-semibold text-gray-800">{{ elegido.nombre }}</p>
        <p class="text-xs text-gray-500">¿De qué medida es esta receta? Cada una lleva la suya: no todas cuestan lo mismo.</p>
      </div>
      <ul class="divide-y divide-gray-100">
        <li v-for="v in versiones" :key="v.config_id">
          <button @click="emit('elegir', { producto: elegido, version: v })" :disabled="ocupado"
            class="w-full flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50 text-left disabled:opacity-50">
            <span class="text-sm font-medium text-gray-800">{{ v.opcion }} <span class="text-xs font-normal text-gray-400">{{ v.tipo.toLowerCase() }}</span></span>
            <span class="text-right flex-shrink-0">
              <span class="block text-xs text-gray-500">venta {{ formatPeso(v.precio) }}</span>
              <span v-if="margenDe(ficha.costo_total, v.precio)"
                :class="['inline-block mt-0.5 text-[11px] font-semibold rounded-full px-2', COLOR_MARGEN[margenDe(ficha.costo_total, v.precio).nivel]]">
                {{ formatFactor(margenDe(ficha.costo_total, v.precio).factor) }}
              </span>
            </span>
          </button>
        </li>
        <!-- La general no es el costo de ninguna medida: plantilla y referencia del cotizador -->
        <li>
          <button @click="emit('elegir', { producto: elegido, version: null })" :disabled="ocupado"
            class="w-full text-left px-4 py-3 hover:bg-gray-50 disabled:opacity-50">
            <span class="block text-sm font-medium text-gray-600">Receta general</span>
            <span class="block text-xs text-gray-400">De ninguna medida: para copiarla a las medidas y de referencia al cotizar</span>
          </button>
        </li>
      </ul>
    </template>

    <template v-else>
    <div class="sticky top-0 bg-white px-4 pt-3 pb-2 border-b border-gray-100">
      <div class="relative">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input v-model="busqueda" @input="onInput" type="search" placeholder="Buscar en el catálogo de venta…" autofocus
          class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <p class="text-xs text-gray-400 mt-1.5">
        Al vincular, la ficha toma el nombre y la foto del producto; su nombre de ahora queda como descripción.
        El margen se calcula con el costo de esta ficha.
      </p>
    </div>

    <AppSpinner v-if="cargando && !resultados.length" />
    <p v-else-if="!resultados.length" class="text-center py-12 text-sm text-gray-400">Ningún producto activo se llama así.</p>
    <ul v-else class="divide-y divide-gray-100">
      <li v-for="p in resultados" :key="p.id">
        <button @click="escoger(p)" :disabled="ocupado || !!buscandoVers"
          class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 text-left disabled:opacity-50">
          <img v-if="p.foto_url" :src="cloudinaryOpt(p.foto_url, 96)" alt=""
            class="w-10 h-10 rounded-lg object-cover flex-shrink-0 border border-gray-100" />
          <div v-else class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0" />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-800 truncate">{{ p.nombre }}</p>
            <p v-if="p.categoria" class="text-xs text-gray-400 truncate capitalize">{{ p.categoria }}</p>
          </div>
          <IconoS v-if="buscandoVers === p.id" class="w-4 h-4 text-gray-400 flex-shrink-0" />
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
    </template>
  </PanelCostos>
</template>
