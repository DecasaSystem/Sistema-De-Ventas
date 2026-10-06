<script setup>
/**
 * Panel de Costos: pantalla completa en el celular, ventana centrada en la
 * computadora. Lo usan el detalle de una ficha, la ficha nueva y los buscadores,
 * para que todos se abran y se cierren igual.
 */
import { onMounted, onBeforeUnmount } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

defineProps({
  titulo:    { type: String, default: '' },
  subtitulo: { type: String, default: '' },
  // Los buscadores se abren encima del detalle
  encima:    { type: Boolean, default: false },
  ancho:     { type: String, default: 'sm:max-w-2xl' },
})
const emit = defineEmits(['cerrar'])

// Escape cierra solo el panel de arriba (un buscador abierto sobre el detalle)
const yo = Symbol()
function onKey(e) { if (e.key === 'Escape' && pila.at(-1) === yo) emit('cerrar') }
onMounted(() => { pila.push(yo); window.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { pila.splice(pila.indexOf(yo), 1); window.removeEventListener('keydown', onKey) })
</script>

<script>
const pila = []
</script>

<template>
  <Teleport to="body">
    <div :class="['fixed inset-0 flex sm:items-center sm:justify-center sm:bg-black/40 sm:p-6', encima ? 'z-[60]' : 'z-50']"
      @click.self="emit('cerrar')">
      <div role="dialog" aria-modal="true" :aria-label="titulo"
        :class="['bg-white w-full h-full sm:h-auto sm:max-h-[90vh] sm:rounded-2xl sm:shadow-xl flex flex-col overflow-hidden', ancho]">
        <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 shrink-0">
          <button @click="emit('cerrar')" class="p-1 -ml-1 text-gray-500 hover:text-gray-700" aria-label="Cerrar">
            <XMarkIcon class="w-5 h-5" />
          </button>
          <div class="flex-1 min-w-0">
            <slot name="titulo">
              <p class="text-sm font-semibold text-gray-800 truncate">{{ titulo }}</p>
              <p v-if="subtitulo" class="text-xs text-gray-400 truncate">{{ subtitulo }}</p>
            </slot>
          </div>
          <slot name="acciones" />
        </div>

        <div class="flex-1 overflow-y-auto">
          <slot />
        </div>

        <div v-if="$slots.pie" class="shrink-0 border-t border-gray-200 px-4 py-3 bg-white">
          <slot name="pie" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
