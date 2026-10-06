<script setup>
/**
 * Costos de producción: cuánto cuesta fabricar cada mueble (fichas técnicas),
 * a qué precio están los materiales, cuánto vale cada hora de cada oficio,
 * qué margen deja cada producto y qué tan bien estima el cotizador con IA.
 *
 * Esta vista solo arma las pestañas y abre los paneles; cada pestaña vive en
 * components/costos/. Lo compartido (tarifas, factor, formatos) está en
 * components/costos/useCostos.js.
 */
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PlusIcon } from '@heroicons/vue/24/outline'
import TabProductos from '@/components/costos/TabProductos.vue'
import TabMateriales from '@/components/costos/TabMateriales.vue'
import TabTarifas from '@/components/costos/TabTarifas.vue'
import TabMargenes from '@/components/costos/TabMargenes.vue'
import TabPrecision from '@/components/costos/TabPrecision.vue'
import FichaDetalle from '@/components/costos/FichaDetalle.vue'
import FichaNueva from '@/components/costos/FichaNueva.vue'

const route  = useRoute()
const router = useRouter()

const PESTANAS = [
  { id: 'productos',  label: 'Productos' },
  { id: 'materiales', label: 'Materiales' },
  { id: 'tarifas',    label: 'Tarifas' },
  { id: 'margenes',   label: 'Márgenes' },
  { id: 'precision',  label: 'Precisión IA' },
]

// La pestaña va en la URL (?tab=…) para que recargar o volver atrás no la pierda
const pestana = computed(() => PESTANAS.some(p => p.id === route.query.tab) ? route.query.tab : 'productos')

const refProductos = ref(null)
const refMargenes  = ref(null)
const refTarifas   = ref(null)

function irA(id) {
  if (id === pestana.value) return
  if (refTarifas.value?.hayCambios && !confirm('Hay cambios de tarifas sin guardar. ¿Salir sin guardarlos?')) return
  router.replace({ query: { ...route.query, tab: id } })
}

// ── Paneles ──────────────────────────────────────────────────────────────────
const categorias   = ref([])
const fichaAbierta = ref(null)
const creando      = ref(false)

// Las fichas cambiaron (se guardó una, cambió un precio o una tarifa): lo que
// está a la vista se recarga; lo que no, se recarga solo al volver a abrirlo.
function fichasCambiaron() {
  refProductos.value?.cargar()
  refMargenes.value?.cargar()
}

function onCreada(id) {
  creando.value      = false
  fichaAbierta.value = id
  fichasCambiaron()
}

function onEliminada() {
  fichaAbierta.value = null
  fichasCambiaron()
}

function onDuplicada(id) {
  fichaAbierta.value = id
  fichasCambiaron()
}

// Al cambiar de pestaña no se queda un panel abierto encima
watch(pestana, () => { fichaAbierta.value = null })
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <div class="flex items-center justify-between gap-3">
      <h2 class="text-lg font-bold text-gray-800">Costos de producción</h2>
      <button v-if="pestana === 'productos'" @click="creando = true"
        class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 transition-colors">
        <PlusIcon class="w-3.5 h-3.5" />Nueva ficha
      </button>
    </div>

    <nav class="flex gap-1 bg-gray-100 rounded-xl p-1 overflow-x-auto [scrollbar-width:none]" aria-label="Secciones de costos">
      <button v-for="p in PESTANAS" :key="p.id" @click="irA(p.id)"
        :aria-current="pestana === p.id ? 'page' : undefined"
        :class="['flex-1 min-w-max px-2.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition-colors',
          pestana === p.id ? 'bg-white text-gray-800 shadow-sm' : 'text-gray-500 hover:text-gray-700']">
        {{ p.label }}
      </button>
    </nav>

    <KeepAlive :include="['TabProductos']">
      <TabProductos v-if="pestana === 'productos'" ref="refProductos"
        @abrir="id => fichaAbierta = id" @categorias="c => categorias = c" />
    </KeepAlive>
    <TabMateriales v-if="pestana === 'materiales'" @fichas-cambiaron="fichasCambiaron" />
    <TabTarifas    v-if="pestana === 'tarifas'" ref="refTarifas" @fichas-cambiaron="fichasCambiaron" />
    <TabMargenes   v-if="pestana === 'margenes'" ref="refMargenes" @abrir="id => fichaAbierta = id" />
    <TabPrecision  v-if="pestana === 'precision'" />

    <FichaDetalle v-if="fichaAbierta" :key="fichaAbierta" :ficha-id="fichaAbierta" :categorias="categorias"
      @cerrar="fichaAbierta = null" @actualizada="fichasCambiaron" @eliminada="onEliminada" @abrir="onDuplicada" />

    <FichaNueva v-if="creando" :categorias="categorias" @cerrar="creando = false" @creada="onCreada" />
  </div>
</template>
