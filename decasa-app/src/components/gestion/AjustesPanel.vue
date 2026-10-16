<script setup>
/**
 * Gestión → Ajustes: interruptores que cambian cómo trabaja el sistema.
 *
 * El primero lo pidió el dueño (2026-10-16): poder dejar de calificar a los
 * trabajadores del taller cada vez que una pieza pasa de un paso a otro. El
 * servidor lo respeta aunque una pantalla vieja mande las estrellas.
 */
import { ref, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import { getAjustesSistema, guardarAjustesSistema } from '@/api/ajustes'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'

const toast = useToast()

const ajustes   = ref(null)
const cargando  = ref(true)
const guardando = ref('')

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getAjustesSistema()
    ajustes.value = data
  } catch {
    toast.error('No se pudieron cargar los ajustes.')
  } finally {
    cargando.value = false
  }
}

async function cambiar(clave) {
  if (guardando.value) return
  const nuevo = !ajustes.value[clave]
  guardando.value = clave
  try {
    const { data } = await guardarAjustesSistema({ [clave]: nuevo })
    ajustes.value = data
    toast.success(nuevo ? 'Encendido.' : 'Apagado.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
  } finally {
    guardando.value = ''
  }
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-3">
    <div v-if="cargando" class="flex justify-center py-10"><AppSpinner /></div>

    <template v-else-if="ajustes">
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <h3 class="text-sm font-bold text-gray-800 flex items-center gap-2">
          <WrenchScrewdriverIcon class="w-4 h-4 text-blue-600" />
          Producción
        </h3>

        <label class="flex items-start gap-2.5 cursor-pointer select-none">
          <button
            type="button" role="switch"
            :aria-checked="ajustes.calificar_trabajadores_taller"
            :disabled="guardando === 'calificar_trabajadores_taller'"
            @click="cambiar('calificar_trabajadores_taller')"
            :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0 mt-0.5 disabled:opacity-50',
              ajustes.calificar_trabajadores_taller ? 'bg-blue-600' : 'bg-gray-300']"
          >
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
              ajustes.calificar_trabajadores_taller ? 'translate-x-5' : 'translate-x-0.5']" />
          </button>
          <span class="text-sm text-gray-700">
            Calificar a los trabajadores al terminar un paso
            <span class="block text-xs text-gray-400 mt-0.5">
              Encendido: al pasar una pieza al siguiente paso se pide cuánto se demoró cada
              trabajador, sus estrellas y una opinión. Apagado: solo se dice quién lo hizo.
            </span>
            <span class="block text-xs text-gray-400 mt-1">
              No cambia ningún pago: la nómina y las bonificaciones no usan las estrellas.
              Las calificaciones ya guardadas se conservan.
            </span>
          </span>
        </label>
      </div>
    </template>
  </div>
</template>
