<script setup>
/**
 * Qué significa cada color de los materiales ("Falta actualizar el precio", "Por revisar"…).
 * Cambiar un significado no cambia el color de ningún material: solo lo que dice.
 */
import { ref } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { useToast } from '@/composables/useToast'
import IconoS from '@/components/common/IconoS.vue'
import { useColoresMaterial } from './coloresMaterial'

const emit = defineEmits(['cerrar'])

const toast = useToast()
const { COLORES, leyenda, guardarLeyenda } = useColoresMaterial()

const textos    = ref(Object.fromEntries(COLORES.map(c => [c.id, leyenda.value[c.id] ?? ''])))
const guardando = ref(false)

async function guardar() {
  guardando.value = true
  try {
    await guardarLeyenda(Object.fromEntries(COLORES.map(c => [c.id, (textos.value[c.id] || '').trim()])))
    toast.success('Colores guardados')
    emit('cerrar')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudieron guardar los colores.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="emit('cerrar')">
      <div role="dialog" aria-modal="true" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 shrink-0">
          <div>
            <h3 class="text-base font-bold text-gray-800">Colores de los materiales</h3>
            <p class="text-xs text-gray-400">Escribe qué quiere decir cada color.</p>
          </div>
          <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 p-1" aria-label="Cerrar"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="overflow-y-auto flex-1 px-5 py-4 space-y-2.5">
          <div v-for="c in COLORES" :key="c.id" class="flex items-center gap-3">
            <span :class="['w-4 h-4 rounded-full flex-shrink-0', c.punto]" aria-hidden="true" />
            <input v-model="textos[c.id]" type="text" maxlength="80" :placeholder="`${c.nombre}: ¿qué significa?`" :aria-label="`Significado del color ${c.nombre}`"
              class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <p class="text-xs text-gray-400 pt-1">Cambiar lo que significa un color no le quita el color a los materiales que ya lo tienen.</p>
        </div>

        <div class="flex gap-2 px-5 py-4 border-t border-gray-200 shrink-0">
          <button @click="emit('cerrar')" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
          <button @click="guardar" :disabled="guardando"
            class="flex-1 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 flex items-center justify-center gap-1.5">
            <IconoS v-if="guardando" class="w-4 h-4" />{{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
