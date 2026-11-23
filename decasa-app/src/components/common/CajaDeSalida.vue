<script setup>
// De qué caja sale el efectivo que se le devuelve al cliente.
//
// Antes la devolución bajaba siempre la caja de la tienda de la orden y quedaba
// a nombre del vendedor de la orden: si un supervisor devolvía en El Edén la
// plata de una orden de Norte, la caja de Norte bajaba sin que nadie supiera
// por qué (usuario, 2026-11-23). Ahora se escoge; viene puesta la de la orden.
// Si quien devuelve vende por su cuenta (independiente), sale de su caja propia
// y esto no se muestra.
import { ref, onMounted, watch } from 'vue'
import { getTiendas } from '@/api/ordenes'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  modelValue:    { type: [Number, String, null], default: null },
  tiendaOrdenId: { type: [Number, String, null], default: null },
})
const emit = defineEmits(['update:modelValue'])

const auth    = useAuthStore()
const tiendas = ref([])

onMounted(async () => {
  if (!props.modelValue && props.tiendaOrdenId) emit('update:modelValue', Number(props.tiendaOrdenId))
  if (auth.usuario?.independiente) return
  try {
    const { data } = await getTiendas()
    tiendas.value = (Array.isArray(data) ? data : data?.data ?? [])
      .filter(t => !t.es_independientes && t.activa !== false)
  } catch { /* sin la lista, queda la de la orden */ }
})

watch(() => props.tiendaOrdenId, (v) => {
  if (!props.modelValue && v) emit('update:modelValue', Number(v))
})
</script>

<template>
  <div v-if="!auth.usuario?.independiente && tiendas.length">
    <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿De qué caja sale el efectivo?</label>
    <select
      :value="modelValue ?? ''"
      @change="emit('update:modelValue', $event.target.value ? Number($event.target.value) : null)"
      class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
    >
      <option v-for="t in tiendas" :key="t.id" :value="t.id">
        {{ t.nombre }}{{ Number(t.id) === Number(tiendaOrdenId) ? ' (la de la orden)' : '' }}
      </option>
    </select>
    <p class="text-[11px] text-gray-400 mt-1">Queda en esa caja como "Devolución", a tu nombre.</p>
  </div>
</template>
