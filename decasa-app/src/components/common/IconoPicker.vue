<script setup>
/**
 * Elegir el dibujo de un módulo o de una herramienta.
 *
 * Se muestran agrupados por para qué sirven y con un buscador, porque nadie
 * reconoce un icono por su nombre en inglés: se reconoce viéndolo.
 */
import { ref, computed } from 'vue'
import { ICONOS, GRUPOS_ICONOS, iconoPorNombre } from '@/constants/iconos'
import { XMarkIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  abierto:  { type: Boolean, default: false },
  elegido:  { type: String,  default: '' },
})
const emit = defineEmits(['cerrar', 'elegir'])

const busqueda = ref('')

const grupos = computed(() => {
  const term = busqueda.value.trim().toLowerCase()
  if (!term) return GRUPOS_ICONOS

  // Buscar por el nombre del icono sin el "Icon" del final, que no aporta.
  const coinciden = Object.keys(ICONOS).filter(n =>
    n.replace(/Icon$/, '').toLowerCase().includes(term)
  )
  return coinciden.length ? [{ nombre: 'Resultados', iconos: coinciden }] : []
})

function elegir(nombre) {
  emit('elegir', nombre)
  emit('cerrar')
}
</script>

<template>
  <Transition name="fade">
    <div v-if="abierto" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="absolute inset-0 bg-black/40" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[85vh] flex flex-col">

        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <h3 class="text-base font-bold text-gray-800">Elegir icono</h3>
          <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="px-5 pt-3">
          <div class="relative">
            <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              v-model="busqueda" type="text" placeholder="Buscar (truck, box, chart...)"
              class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>

        <div class="overflow-y-auto px-5 py-3 space-y-4">
          <div v-for="g in grupos" :key="g.nombre">
            <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-2">{{ g.nombre }}</p>
            <div class="grid grid-cols-6 gap-2">
              <button
                v-for="n in g.iconos" :key="n"
                type="button"
                @click="elegir(n)"
                :title="n.replace(/Icon$/, '')"
                :class="['aspect-square rounded-xl border flex items-center justify-center transition-colors',
                  n === elegido
                    ? 'border-blue-500 bg-blue-50 text-blue-600'
                    : 'border-gray-200 text-gray-600 hover:bg-gray-50']"
              >
                <component :is="iconoPorNombre(n)" class="w-6 h-6" />
              </button>
            </div>
          </div>
          <p v-if="!grupos.length" class="text-xs text-gray-400 text-center py-6">
            Ningún icono con ese nombre.
          </p>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
