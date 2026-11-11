<script setup>
/**
 * Agregar mano de obra a una ficha, de dos formas:
 *
 * - Por trabajo (Esqueletería, Tapicería…): se escoge el tipo y se escribe lo que cuesta en
 *   este mueble (usuario, 2026-11-11: "no tienen un costo fijo ni calculable"). Emite
 *   `elegir-tipo` con { tipo, costo }. Tarifas nunca cambia ese costo.
 * - De Tarifas (oficio × horas): el ítem queda vinculado al trabajo; si cambia el incentivo
 *   por hora del oficio, la ficha se recalcula sola. Emite `elegir`.
 */
import { ref, nextTick, onMounted } from 'vue'
import { ChevronRightIcon } from '@heroicons/vue/24/outline'
import AppSpinner from '@/components/common/AppSpinner.vue'
import InputPesos from '@/components/common/InputPesos.vue'
import PanelCostos from './PanelCostos.vue'
import { useCostos } from './useCostos'

defineProps({ subtitulo: { type: String, default: '' } })
const emit = defineEmits(['elegir', 'elegir-tipo', 'cerrar'])

const {
  procesosPorCargo, cargandoTarifas, cargarTarifas, tarifaHoraDe, costoProceso, nombreCargo, nombreProceso, formatPeso,
  tiposActivos, cargandoTipos, cargarTiposManoObra,
} = useCostos()

onMounted(() => { cargarTarifas(); cargarTiposManoObra() })

// El tipo escogido espera su costo antes de agregarse
const tipo  = ref(null)
const costo = ref(null)
const cajaCosto = ref(null)

async function escoger(t) {
  tipo.value  = tipo.value?.id === t.id ? null : t
  costo.value = null
  await nextTick()
  // Dentro de un v-for el ref es una lista; solo hay una caja abierta a la vez
  const caja = Array.isArray(cajaCosto.value) ? cajaCosto.value[0] : cajaCosto.value
  caja?.$el?.focus?.()
}

function agregarTipo() {
  if (!(Number(costo.value) > 0)) return
  emit('elegir-tipo', { tipo: tipo.value, costo: Number(costo.value) })
}
</script>

<template>
  <PanelCostos titulo="Agregar mano de obra" :subtitulo="subtitulo" encima ancho="sm:max-w-lg" @cerrar="emit('cerrar')">
    <div class="p-4 space-y-4">
      <!-- Por trabajo: se escribe el costo -->
      <section>
        <div class="mb-1.5">
          <h3 class="text-sm font-semibold text-gray-700">Por trabajo</h3>
          <p class="text-xs text-gray-400">Escoge el trabajo y escribe lo que cuesta en este mueble.</p>
        </div>
        <AppSpinner v-if="cargandoTipos && !tiposActivos.length" />
        <p v-else-if="!tiposActivos.length" class="text-xs text-gray-400">Créalos en la pestaña Tarifas, en «Mano de obra por trabajo».</p>
        <div v-else class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
          <div v-for="t in tiposActivos" :key="t.id">
            <button @click="escoger(t)" :aria-expanded="tipo?.id === t.id"
              :class="['w-full flex items-center justify-between gap-3 px-3 py-2.5 text-left', tipo?.id === t.id ? 'bg-gray-50' : 'hover:bg-gray-50']">
              <span class="text-sm text-gray-800">{{ t.nombre }}</span>
              <ChevronRightIcon :class="['w-4 h-4 text-gray-300 flex-shrink-0 transition-transform', tipo?.id === t.id ? 'rotate-90' : '']" />
            </button>
            <form v-if="tipo?.id === t.id" @submit.prevent="agregarTipo" class="flex items-center gap-2 px-3 pb-3 bg-gray-50">
              <InputPesos ref="cajaCosto" v-model="costo" permite-vacio placeholder="Costo" :aria-label="`Costo de ${t.nombre}`"
                class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
              <button type="submit" :disabled="!(Number(costo) > 0)"
                class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-40">
                Agregar
              </button>
            </form>
          </div>
        </div>
      </section>

      <!-- De Tarifas: incentivo por hora × horas -->
      <section>
        <div class="mb-1.5">
          <h3 class="text-sm font-semibold text-gray-700">De Tarifas</h3>
          <p class="text-xs text-gray-400">Costo por horas del oficio: se recalcula solo si cambia el incentivo.</p>
        </div>
        <AppSpinner v-if="cargandoTarifas && !procesosPorCargo.length" />
        <p v-else-if="!procesosPorCargo.length" class="text-xs text-gray-400">Todavía no hay trabajos. Créalos en la pestaña Tarifas.</p>
        <div v-else class="space-y-3">
          <div v-for="[cargo, items] in procesosPorCargo" :key="cargo">
            <div class="flex items-baseline justify-between mb-1">
              <h4 class="text-xs font-semibold text-gray-600">{{ nombreCargo(cargo) }}</h4>
              <span class="text-xs text-gray-400">{{ formatPeso(tarifaHoraDe(cargo)) }} por hora</span>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
              <button v-for="p in items" :key="p.id" @click="emit('elegir', p)"
                class="w-full flex items-center justify-between gap-3 px-3 py-2.5 hover:bg-gray-50 text-left">
                <span class="text-sm text-gray-800 first-letter:uppercase">{{ nombreProceso(p) }}</span>
                <span class="text-xs text-gray-500 flex-shrink-0">{{ p._horas }} h · <strong class="text-gray-700">{{ formatPeso(costoProceso(p)) }}</strong></span>
              </button>
            </div>
          </div>
        </div>
      </section>
    </div>
  </PanelCostos>
</template>
