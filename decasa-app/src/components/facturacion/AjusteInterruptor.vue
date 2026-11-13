<script setup>
/**
 * Un renglón de Ajustes con interruptor, igual al de Gestión → Ajustes
 * (AjustesPanel): título, una ayuda corta y, si hace falta, una advertencia.
 */
defineProps({
  titulo:  { type: String, required: true },
  ayuda:   { type: String, default: '' },
  aviso:   { type: String, default: '' },
})
const valor = defineModel({ type: Boolean, default: false })
</script>

<template>
  <div class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
    <div class="flex-1 min-w-0 cursor-pointer select-none" @click="valor = !valor">
      <p class="text-sm font-medium text-gray-800">{{ titulo }}</p>
      <p v-if="ayuda" class="text-xs text-gray-400 mt-0.5">{{ ayuda }}</p>
      <p v-if="aviso" class="text-xs text-amber-700 mt-1">{{ aviso }}</p>
    </div>
    <button type="button" role="switch" :aria-checked="valor" :aria-label="titulo" @click="valor = !valor"
      :class="['w-10 h-6 rounded-full transition-colors relative shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2',
        valor ? 'bg-blue-600' : 'bg-gray-300']">
      <span :class="['absolute top-0.5 left-0 w-5 h-5 bg-white rounded-full shadow transition-transform',
        valor ? 'translate-x-[18px]' : 'translate-x-0.5']" />
    </button>
  </div>
</template>
