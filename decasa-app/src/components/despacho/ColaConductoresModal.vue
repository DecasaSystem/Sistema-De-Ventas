<script setup>
import { ref, onMounted } from 'vue'
import { conductores } from '@/api/despacho'

const emit = defineEmits(['confirmar', 'cerrar'])

const lista = ref([])
const seleccionado = ref(null)
const cargando = ref(true)

onMounted(async () => {
  try {
    const { data } = await conductores()
    lista.value = data
  } catch {} finally {
    cargando.value = false
  }
})

function confirmar() {
  if (seleccionado.value) {
    emit('confirmar', seleccionado.value)
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
    <div class="fixed inset-0 bg-black/40" @click="emit('cerrar')" />

    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[80vh] overflow-y-auto p-5 z-10">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-gray-900">Seleccionar conductor</h3>
        <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
      </div>

      <div v-if="cargando" class="text-center py-6 text-sm text-gray-400">Cargando conductores...</div>

      <div v-else-if="lista.length === 0" class="text-center py-6 text-sm text-gray-400">
        No hay conductores activos disponibles.
      </div>

      <div v-else class="space-y-2">
        <button
          v-for="c in lista"
          :key="c.id"
          @click="seleccionado = c"
          class="w-full text-left px-4 py-3 rounded-xl border-2 transition-all"
          :class="seleccionado?.id === c.id ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300'"
        >
          <p class="font-semibold text-gray-900">{{ c.nombre }}</p>
          <p class="text-xs text-gray-500">{{ c.email }}</p>
        </button>
      </div>

      <button
        v-if="lista.length > 0"
        @click="confirmar"
        :disabled="!seleccionado"
        class="mt-5 w-full py-3 rounded-xl font-semibold text-white transition-colors"
        :class="seleccionado ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-300 cursor-not-allowed'"
      >
        Asignar a {{ seleccionado?.nombre || '...' }}
      </button>
    </div>
  </div>
</template>
