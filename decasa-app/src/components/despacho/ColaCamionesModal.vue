<script setup>
import { ref, computed, onMounted } from 'vue'
import { camiones as getCamiones } from '@/api/despacho'
import { TruckIcon, UserIcon } from '@heroicons/vue/24/outline'

const props = defineProps({ cantidadOrdenes: { type: Number, default: 0 } })
const emit  = defineEmits(['confirmar', 'cerrar'])

const lista    = ref([])
const cargando = ref(true)
const seleccionado = ref(null)
const fecha    = ref(hoy())

function hoy() {
  return new Date().toISOString().slice(0, 10)
}

onMounted(async () => {
  try {
    const { data } = await getCamiones()
    lista.value = data
  } catch {} finally {
    cargando.value = false
  }
})

const puedeConfirmar = computed(() => seleccionado.value && fecha.value)

function confirmar() {
  if (!puedeConfirmar.value) return
  emit('confirmar', { camion: seleccionado.value, fecha: fecha.value })
}

function labelFecha(f) {
  if (!f) return ''
  const d = new Date(f + 'T12:00:00')
  return d.toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' })
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
    <div class="fixed inset-0 bg-black/40" @click="emit('cerrar')" />

    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[85vh] overflow-y-auto p-5 z-10">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-gray-900">Asignar al camión</h3>
        <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
      </div>

      <!-- Fecha de salida -->
      <div class="mb-4">
        <label class="text-xs font-semibold text-gray-500 uppercase mb-1.5 block">Fecha de salida</label>
        <input
          v-model="fecha"
          type="date"
          class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
        <p v-if="fecha" class="text-xs text-gray-400 mt-1 capitalize">{{ labelFecha(fecha) }}</p>
      </div>

      <!-- Camiones -->
      <label class="text-xs font-semibold text-gray-500 uppercase mb-2 block">Camión</label>

      <div v-if="cargando" class="text-center py-6 text-sm text-gray-400">Cargando camiones...</div>

      <div v-else-if="lista.length === 0" class="text-center py-6 text-sm text-gray-400">
        No hay camiones registrados.
      </div>

      <div v-else class="space-y-2 mb-5">
        <button
          v-for="c in lista.filter(c => c.activo)"
          :key="c.id"
          @click="seleccionado = c"
          class="w-full text-left px-4 py-3 rounded-xl border-2 transition-all"
          :class="seleccionado?.id === c.id ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300'"
        >
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center flex-shrink-0">
              <TruckIcon class="w-5 h-5 text-blue-600" />
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-900">{{ c.nombre ?? `Camión ${c.id}` }}</p>
              <p v-if="c.placa" class="text-xs text-gray-400">Placa: {{ c.placa }}</p>
              <div class="flex items-center gap-1 mt-0.5">
                <UserIcon class="w-3.5 h-3.5 text-gray-400" />
                <p class="text-xs" :class="c.conductor ? 'text-gray-500' : 'text-amber-600 font-medium'">
                  {{ c.conductor?.nombre ?? 'Sin conductor asignado' }}
                </p>
              </div>
            </div>
            <div
              v-if="seleccionado?.id === c.id"
              class="w-5 h-5 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0"
            >
              <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
            </div>
          </div>
        </button>
      </div>

      <!-- Resumen -->
      <div v-if="puedeConfirmar" class="bg-blue-50 rounded-xl px-4 py-3 mb-4 text-sm text-blue-700">
        <span class="font-semibold">{{ props.cantidadOrdenes }}</span> orden(es) →
        <span class="font-semibold">{{ seleccionado?.nombre ?? `Camión ${seleccionado?.id}` }}</span>
        · {{ seleccionado?.conductor?.nombre }}
        · {{ labelFecha(fecha) }}
      </div>

      <button
        @click="confirmar"
        :disabled="!puedeConfirmar"
        class="w-full py-3 rounded-xl font-semibold text-white transition-colors"
        :class="puedeConfirmar ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-300 cursor-not-allowed'"
      >
        {{ puedeConfirmar ? `Asignar a ${seleccionado?.nombre ?? 'Camión'}` : 'Selecciona camión y fecha' }}
      </button>
    </div>
  </div>
</template>
