<script setup>
/**
 * Todo / Venta / Restauración / FV2, con cuántas hay de cada una.
 *
 * Lo usan Cartera y Producción de Reportes. Cuatro columnas fijas, nombre
 * arriba y cuántas abajo: en 390 px una fila de pastillas con
 * "Restauraciones 9" se salía de la pantalla.
 */
import { TIPOS_ORDEN } from '@/utils/tiposOrden'

defineProps({
  /** { venta, restauracion, fv2 } → cuántas hay de cada una */
  cuantas: { type: Object, default: () => ({}) },
  total:   { type: Number, default: 0 },
})
const modelo = defineModel({ type: String, default: '' })
</script>

<template>
  <div class="bg-gray-100 rounded-xl p-1 grid grid-cols-4 gap-1">
    <button
      type="button"
      @click="modelo = ''"
      :class="['rounded-lg px-1 py-1.5 text-[11px] font-semibold transition leading-tight',
        !modelo ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
    >
      Todo
      <span class="block text-gray-400 font-normal">{{ total }}</span>
    </button>
    <button
      v-for="t in TIPOS_ORDEN" :key="t.clave"
      type="button"
      @click="modelo = t.clave"
      :disabled="!cuantas[t.clave]"
      :class="['rounded-lg px-1 py-1.5 text-[11px] font-semibold transition leading-tight min-w-0 disabled:opacity-40',
        modelo === t.clave ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
    >
      <span class="block truncate">{{ t.corto }}</span>
      <span class="flex items-center justify-center gap-1 text-gray-400 font-normal">
        <span class="w-1.5 h-1.5 rounded-sm shrink-0" :style="{ background: t.color }" />
        {{ cuantas[t.clave] || 0 }}
      </span>
    </button>
  </div>
</template>
