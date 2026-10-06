<script setup>
/**
 * De qué está hecho el precio: materiales + mano de obra + lo que queda.
 *
 * Sin producto vinculado, la barra es solo el costo partido en materiales y
 * mano de obra. Con producto, la barra completa es el precio de venta y el
 * tramo final es el margen. Si se vende a pérdida, la barra es el costo y una
 * raya roja marca hasta dónde llega el precio.
 */
import { computed } from 'vue'
import { useCostos } from './useCostos'

const props = defineProps({
  materiales: { type: [Number, String], default: 0 },
  manoObra:   { type: [Number, String], default: 0 },
  precio:     { type: [Number, String], default: null },
})

const { margenDe, formatPeso } = useCostos()

const mat    = computed(() => Number(props.materiales) || 0)
const mo     = computed(() => Number(props.manoObra) || 0)
const costo  = computed(() => mat.value + mo.value)
const precio = computed(() => Number(props.precio) || 0)
const margen = computed(() => margenDe(costo.value, precio.value))
const base   = computed(() => Math.max(costo.value, precio.value) || 1)

const pct = v => `${Math.max(0, (v / base.value) * 100)}%`

const colorMargen = computed(() => margen.value?.nivel === 'bajo' ? 'bg-amber-400' : 'bg-green-500')
</script>

<template>
  <div>
    <!-- gap: separa los tramos, que naranja (mano de obra) y ámbar (margen bajo) no se fundan -->
    <div class="relative h-3 rounded-full bg-gray-100 overflow-hidden flex gap-0.5" role="img"
      :aria-label="`Materiales ${formatPeso(mat)}, mano de obra ${formatPeso(mo)}${margen ? `, precio ${formatPeso(precio)}` : ''}`">
      <div class="h-full bg-blue-500" :style="{ width: pct(mat) }" />
      <div class="h-full bg-orange-400" :style="{ width: pct(mo) }" />
      <div v-if="margen && margen.nivel !== 'perdida'" :class="['h-full', colorMargen]" :style="{ width: pct(precio - costo) }" />
      <!-- Pérdida: hasta dónde alcanza el precio -->
      <div v-if="margen?.nivel === 'perdida'" class="absolute inset-y-0 w-0.5 bg-red-600" :style="{ left: pct(precio) }" />
    </div>

    <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs">
      <span class="flex items-center gap-1.5 text-gray-600">
        <span class="w-2 h-2 rounded-full bg-blue-500" />Materiales <strong class="font-semibold text-gray-800">{{ formatPeso(mat) }}</strong>
      </span>
      <span class="flex items-center gap-1.5 text-gray-600">
        <span class="w-2 h-2 rounded-full bg-orange-400" />Mano de obra <strong class="font-semibold text-gray-800">{{ formatPeso(mo) }}</strong>
      </span>
      <span v-if="margen && margen.nivel !== 'perdida'" class="flex items-center gap-1.5 text-gray-600">
        <span :class="['w-2 h-2 rounded-full', colorMargen]" />Margen <strong class="font-semibold text-gray-800">{{ formatPeso(margen.ganancia) }}</strong>
      </span>
      <span v-else-if="margen" class="flex items-center gap-1.5 text-red-600 font-medium">
        <span class="w-2 h-0.5 bg-red-600" />Pierde {{ formatPeso(-margen.ganancia) }} por unidad
      </span>
    </div>
  </div>
</template>
