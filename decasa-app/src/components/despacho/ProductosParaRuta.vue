<script setup>
/**
 * Qué productos de una orden van en el camión.
 *
 * Se entregan productos, no órdenes: de una orden con un reloj listo y un
 * comedor en el taller, al camión sube el reloj. Cada producto entregable
 * tiene su casilla (marcada por defecto) y su cantidad; lo que no se puede
 * entregar se ve, en gris, con el porqué.
 *
 * El padre guarda la selección como { [orden_item_id]: cantidad } y la manda
 * al agregar la orden a la ruta o al asignar el camión.
 */
import { computed } from 'vue'

const props = defineProps({
  items:  { type: Array, required: true },
  /** { [orden_item_id]: cantidad } */
  lineas: { type: Object, required: true },
  compacto: { type: Boolean, default: false },
})
const emit = defineEmits(['update:lineas'])

const entregables   = computed(() => props.items.filter(i => i.entregable && (i.pendiente_entregar ?? 0) > 0))
const noEntregables = computed(() => props.items.filter(i => !(i.entregable && (i.pendiente_entregar ?? 0) > 0) && !i.devuelto_en))

function nombre(i) { return i.producto?.nombre || i.nombre_custom || 'Producto' }

function alternar(i) {
  const n = { ...props.lineas }
  if (n[i.id]) delete n[i.id]
  else n[i.id] = i.pendiente_entregar
  emit('update:lineas', n)
}

function cantidad(i, v) {
  const n = { ...props.lineas }
  const c = Math.max(1, Math.min(i.pendiente_entregar, Number(v) || 1))
  n[i.id] = c
  emit('update:lineas', n)
}
</script>

<template>
  <div class="space-y-1" @click.stop>
    <label
      v-for="i in entregables" :key="i.id"
      :class="['flex items-center gap-2 rounded-lg px-2 py-1 border cursor-pointer transition-colors',
        lineas[i.id] ? 'bg-emerald-50 border-emerald-300' : 'bg-white border-gray-200']"
    >
      <input type="checkbox" :checked="!!lineas[i.id]" @change="alternar(i)" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
      <span class="flex-1 min-w-0 text-xs text-gray-800 truncate">
        {{ nombre(i) }}
        <span class="text-gray-400">
          {{ i.pendiente_entregar < i.cantidad ? `· faltan ${i.pendiente_entregar} de ${i.cantidad}` : `· x${i.cantidad}` }}
        </span>
      </span>
      <input
        v-if="lineas[i.id] && i.pendiente_entregar > 1"
        :value="lineas[i.id]"
        @input="cantidad(i, $event.target.value)"
        type="number" min="1" :max="i.pendiente_entregar"
        class="w-12 border border-emerald-300 rounded px-1 py-0.5 text-xs text-center focus:ring-1 focus:ring-emerald-500 outline-none"
      />
    </label>

    <div
      v-for="i in noEntregables" :key="'no-' + i.id"
      class="flex items-center gap-2 rounded-lg px-2 py-1 border border-dashed border-gray-200 bg-gray-50 opacity-75"
    >
      <span class="w-4 h-4 shrink-0" />
      <span class="flex-1 min-w-0 text-xs text-gray-500 truncate">{{ nombre(i) }}</span>
      <span class="text-[10px] font-medium shrink-0" :class="(i.pendiente_entregar ?? 0) <= 0 ? 'text-emerald-700' : 'text-purple-700'">
        {{ (i.pendiente_entregar ?? 0) <= 0 ? '✓ entregado' : 'en el taller' }}
      </span>
    </div>
  </div>
</template>
