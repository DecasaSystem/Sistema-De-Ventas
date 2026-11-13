<script setup>
/** Tarjeta de una sección de Ajustes: ícono, título, para qué sirve y su contenido. */
defineProps({
  titulo:  { type: String, required: true },
  detalle: { type: String, default: '' },
  icono:   { type: [Object, Function], default: null },
})
</script>

<template>
  <section class="bg-white rounded-xl shadow-sm">
    <header class="flex items-start gap-3 px-4 pt-4 pb-3 border-b border-gray-100">
      <span v-if="icono" class="w-8 h-8 rounded-lg bg-gray-100 text-gray-600 flex items-center justify-center shrink-0">
        <component :is="icono" class="w-4 h-4" />
      </span>
      <div class="flex-1 min-w-0">
        <h3 class="text-sm font-bold text-gray-800">{{ titulo }}</h3>
        <p v-if="detalle" class="text-xs text-gray-400 mt-0.5">{{ detalle }}</p>
      </div>
      <slot name="extra" />
    </header>
    <div class="p-4 space-y-4">
      <slot />
    </div>
  </section>
</template>
