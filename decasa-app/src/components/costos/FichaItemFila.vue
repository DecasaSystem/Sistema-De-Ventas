<script setup>
/**
 * Un ítem de la ficha en dos líneas: qué es y cuánto cuesta arriba, el cálculo
 * (cantidad × valor) abajo. Antes era una tabla de cinco columnas que en el
 * celular había que arrastrar de lado.
 *
 * La mano de obra que sale de Tarifas no deja editar el valor por hora: lo manda
 * el incentivo del oficio, y al guardar Tarifas se pisaría.
 */
import { computed } from 'vue'
import { CubeIcon, WrenchScrewdriverIcon, ArrowsRightLeftIcon, TrashIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import InputPesos from '@/components/common/InputPesos.vue'
import { useCostos } from './useCostos'

const props = defineProps({
  item:     { type: Object, required: true },
  editando: { type: Boolean, default: false },
})
const emit = defineEmits(['cambio', 'quitar', 'cambiar-material'])

const { formatPeso, formatCantidad } = useCostos()

const sinPrecio     = computed(() => !(Number(props.item.precio_unitario) > 0) || !String(props.item.descripcion ?? '').trim())
const fueraCatalogo = computed(() => !props.item.es_mano_obra && !props.item.material_id && String(props.item.descripcion ?? '').trim() !== '')
const sigueTarifa   = computed(() => props.item.es_mano_obra && props.item.tarifa_proceso_id)
// "1 horas" se lee mal: la mano de obra se muestra en h
const unidad        = computed(() => {
  const u = props.item.unidad
  if (!u || /^horas?$/i.test(u)) return props.item.es_mano_obra ? 'h' : (u || 'und')
  return u
})
</script>

<template>
  <div class="flex gap-3 px-3 py-2.5">
    <div :class="['mt-0.5 w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0', item.es_mano_obra ? 'bg-orange-100 text-orange-600' : 'bg-blue-100 text-blue-600']"
      :title="item.es_mano_obra ? 'Mano de obra' : 'Material'">
      <WrenchScrewdriverIcon v-if="item.es_mano_obra" class="w-4 h-4" />
      <CubeIcon v-else class="w-4 h-4" />
    </div>

    <div class="flex-1 min-w-0">
      <div class="flex items-start gap-2">
        <p class="flex-1 min-w-0 text-sm text-gray-800 break-words first-letter:uppercase">
          {{ item.descripcion || 'Sin nombre' }}
          <button v-if="editando && !item.es_mano_obra" @click="emit('cambiar-material')"
            class="inline-flex align-middle ml-1 p-0.5 rounded text-blue-500 hover:bg-gray-100" title="Cambiar material" aria-label="Cambiar material">
            <ArrowsRightLeftIcon class="w-3.5 h-3.5" />
          </button>
        </p>
        <span class="text-sm font-semibold text-gray-800 whitespace-nowrap">{{ formatPeso(item.subtotal) }}</span>
        <button v-if="editando" @click="emit('quitar')" class="-mr-1 p-1 rounded text-gray-400 hover:text-red-600 hover:bg-gray-100"
          title="Quitar ítem" aria-label="Quitar ítem">
          <TrashIcon class="w-4 h-4" />
        </button>
      </div>

      <!-- Cálculo -->
      <div v-if="!editando" class="text-xs text-gray-500 mt-0.5">
        {{ formatCantidad(item.cantidad) }} {{ unidad }} × {{ formatPeso(item.precio_unitario) }}
      </div>
      <div v-else class="flex flex-wrap items-center gap-1.5 mt-1.5 text-xs text-gray-500">
        <input :value="item.cantidad" @input="e => emit('cambio', { cantidad: e.target.value })"
          type="number" step="any" min="0" inputmode="decimal" :aria-label="`Cantidad (${unidad})`"
          class="w-20 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <span>{{ unidad }} ×</span>
        <InputPesos v-if="!sigueTarifa" :model-value="item.precio_unitario" @update:modelValue="v => emit('cambio', { precio_unitario: v })"
          aria-label="Valor unitario"
          class="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <span v-else class="px-2 py-1.5 rounded-lg bg-gray-100 text-gray-600" title="Lo fija el incentivo por hora del oficio en Tarifas">
          {{ formatPeso(item.precio_unitario) }} <span class="text-gray-400">· de Tarifas</span>
        </span>
      </div>

      <!-- Para revisar -->
      <p v-if="sinPrecio || fueraCatalogo" class="flex items-center gap-1 text-xs text-amber-700 mt-1">
        <ExclamationTriangleIcon class="w-3.5 h-3.5 flex-shrink-0" />
        <template v-if="sinPrecio">Sin precio: no suma al costo</template>
        <template v-else>No está en el catálogo: no se actualiza si cambia el precio del material</template>
      </p>
    </div>
  </div>
</template>
