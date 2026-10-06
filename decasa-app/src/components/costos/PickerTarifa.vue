<script setup>
/**
 * Trabajos de Tarifas agrupados por oficio, para agregar mano de obra a una ficha.
 * El ítem que se agrega queda vinculado al trabajo: si cambia el incentivo por
 * hora del oficio, la ficha se recalcula sola.
 */
import { onMounted } from 'vue'
import AppSpinner from '@/components/common/AppSpinner.vue'
import PanelCostos from './PanelCostos.vue'
import { useCostos } from './useCostos'

defineProps({ subtitulo: { type: String, default: '' } })
const emit = defineEmits(['elegir', 'cerrar'])

const { procesosPorCargo, cargandoTarifas, cargarTarifas, tarifaHoraDe, costoProceso, nombreCargo, nombreProceso, formatPeso } = useCostos()

onMounted(() => cargarTarifas())
</script>

<template>
  <PanelCostos titulo="Agregar mano de obra" :subtitulo="subtitulo" encima ancho="sm:max-w-lg" @cerrar="emit('cerrar')">
    <AppSpinner v-if="cargandoTarifas && !procesosPorCargo.length" />
    <p v-else-if="!procesosPorCargo.length" class="text-center py-12 text-sm text-gray-400">
      Todavía no hay trabajos. Créalos en la pestaña Tarifas.
    </p>
    <div v-else class="p-4 space-y-4">
      <section v-for="[cargo, items] in procesosPorCargo" :key="cargo">
        <div class="flex items-baseline justify-between mb-1.5">
          <h3 class="text-sm font-semibold text-gray-700">{{ nombreCargo(cargo) }}</h3>
          <span class="text-xs text-gray-400">{{ formatPeso(tarifaHoraDe(cargo)) }} por hora</span>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
          <button v-for="p in items" :key="p.id" @click="emit('elegir', p)"
            class="w-full flex items-center justify-between gap-3 px-3 py-2.5 hover:bg-gray-50 text-left">
            <span class="text-sm text-gray-800 first-letter:uppercase">{{ nombreProceso(p) }}</span>
            <span class="text-xs text-gray-500 flex-shrink-0">{{ p._horas }} h · <strong class="text-gray-700">{{ formatPeso(costoProceso(p)) }}</strong></span>
          </button>
        </div>
      </section>
    </div>
  </PanelCostos>
</template>
