<script setup>
// Lo que la empresa paga por detrás de un ciclo: aportes del empleador y
// prestaciones. No se le paga al trabajador —su total no cambia—, por eso va
// aparte, con borde punteado y plegado. Lo lee Finanzas para el costo real.
// Los conceptos vienen del servidor: si la ley agrega uno, sale aquí solo.
import { ref, computed } from 'vue'
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  costo: { type: Object, default: null },
})

const abierto = ref(false)

function pesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}
function pct(n) {
  return `${Number(n).toLocaleString('es-CO', { maximumFractionDigits: 3 })} %`
}

const NOMBRES = {
  aportes: 'Aportes (planilla)',
  prestaciones: 'Prestaciones (provisión)',
  otros: 'Otros',
}

const grupos = computed(() => Object.entries(NOMBRES)
  .map(([clave, nombre]) => ({
    clave, nombre,
    total: props.costo?.[clave] ?? 0,
    lineas: (props.costo?.lineas ?? []).filter(l => l.grupo === clave),
  }))
  .filter(g => g.lineas.length))
</script>

<template>
  <div v-if="props.costo && props.costo.total > 0" class="border border-dashed border-gray-200 rounded-lg">
    <button type="button" @click.stop="abierto = !abierto"
      class="w-full flex items-center justify-between gap-2 px-2.5 py-2 text-xs">
      <span class="text-gray-500 text-left">
        Lo que pone la empresa por detrás
        <span class="block text-[10px] text-gray-400">No se le paga a la persona · lo usa Finanzas</span>
      </span>
      <span class="flex items-center gap-1 shrink-0">
        <span class="font-semibold text-gray-700">{{ pesos(props.costo.total) }}</span>
        <component :is="abierto ? ChevronUpIcon : ChevronDownIcon" class="w-3.5 h-3.5 text-gray-300" />
      </span>
    </button>

    <div v-if="abierto" class="px-2.5 pb-2.5 space-y-2">
      <div v-for="g in grupos" :key="g.clave">
        <div class="flex justify-between text-[11px] font-semibold text-gray-400 uppercase mb-0.5">
          <span>{{ g.nombre }}</span>
          <span>{{ pesos(g.total) }}</span>
        </div>
        <div v-for="l in g.lineas" :key="l.clave" class="flex justify-between gap-2 text-xs py-0.5">
          <span class="text-gray-500 min-w-0 truncate">
            {{ l.nombre }}
            <span v-if="l.porcentaje != null" class="text-gray-400">· {{ pct(l.porcentaje) }}</span>
            <span v-if="l.personalizado" class="text-blue-600">· propio</span>
          </span>
          <span class="text-gray-700 shrink-0">{{ pesos(l.monto) }}</span>
        </div>
      </div>
      <p class="text-[10px] text-gray-400">
        Sobre {{ pesos(props.costo.base_salarial) }} de sueldo devengado
        ({{ pesos(props.costo.base_prestaciones) }} con auxilio)<template v-if="props.costo.fecha_tarifas">,
        con los porcentajes vigentes al {{ props.costo.fecha_tarifas }}</template>.
        <template v-if="props.costo.calculado_despues">Pago anterior a esta cuenta: calculado después.</template>
      </p>
    </div>
  </div>
</template>
