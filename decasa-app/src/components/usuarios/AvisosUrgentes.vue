<script setup>
/**
 * Qué avisos le llegan a una persona como URGENTES: rojos y fijados arriba de
 * la campana hasta que los ve o los borra, y en el celular se quedan en la
 * pantalla.
 *
 * Pedido del usuario (2026-11-17): que cuando lo arroban en el chat de una
 * orden sea urgente, y que cada uno desde su perfil —o un supervisor desde
 * Trabajadores— escoja qué otros quiere así. La lista sale del servidor y trae
 * solo lo que la persona tiene prendido y le puede llegar por su rol
 * (NotificacionesUsuarioController). Los que el sistema ya manda urgentes
 * (cambios de plata…) siguen urgentes: esto solo agrega.
 *
 * Sin `usuarioId` es lo de uno mismo (Perfil). Cada interruptor guarda solo.
 */
import { ref, computed, onMounted } from 'vue'
import { getMisUrgentes, setMisUrgentes, getNotificacionesUsuario, setUrgentesUsuario } from '@/api/usuarios'
import { useToast } from '@/composables/useToast'
import { ExclamationTriangleIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  usuarioId: { type: Number, default: null },   // null = yo
  // Dentro de la ficha de Trabajadores va sin tarjeta propia ni encabezado plegable
  incrustado: { type: Boolean, default: false },
})
const toast = useToast()

const lista     = ref([])
const cargando  = ref(true)
const error     = ref('')
const guardando = ref(null)
const abierto   = ref(false)

const esMio    = computed(() => props.usuarioId == null)
const marcados = computed(() => lista.value.filter(g => g.urgente || g.siempre))

async function recargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = esMio.value ? await getMisUrgentes() : await getNotificacionesUsuario(props.usuarioId)
    lista.value = data.urgentes ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudieron cargar los avisos urgentes.'
  } finally {
    cargando.value = false
  }
}

async function cambiar(g) {
  if (guardando.value) return
  const urgente = !g.urgente
  guardando.value = g.clave
  const antes = lista.value.map(x => ({ ...x }))
  g.urgente = urgente      // se ve ya; si el servidor dice que no, se devuelve
  try {
    const cambio = { [g.clave]: urgente }
    const { data } = esMio.value ? await setMisUrgentes(cambio) : await setUrgentesUsuario(props.usuarioId, cambio)
    lista.value = data.urgentes ?? lista.value
    toast.success(urgente ? `${g.nombre}: llega como urgente.` : `${g.nombre}: llega normal.`)
  } catch (e) {
    lista.value = antes
    toast.error(e.response?.data?.message ?? 'No se pudo guardar. Intenta otra vez.')
  } finally {
    guardando.value = null
  }
}

onMounted(recargar)
defineExpose({ recargar })
</script>

<template>
  <div :class="incrustado ? '' : 'bg-white rounded-xl shadow-sm'">
    <button
      v-if="!incrustado"
      type="button" @click="abierto = !abierto"
      class="w-full flex items-center gap-3 p-4 text-left"
    >
      <ExclamationTriangleIcon class="w-5 h-5 text-red-500 flex-shrink-0" />
      <div class="flex-1 min-w-0">
        <p class="text-xs font-semibold text-gray-500 uppercase">Avisos urgentes</p>
        <p v-if="cargando" class="text-sm text-gray-400">Cargando…</p>
        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p v-else class="text-sm font-medium text-gray-800">
          <template v-if="!marcados.length">Ninguno marcado</template>
          <template v-else>{{ marcados.length }} {{ marcados.length === 1 ? 'tipo' : 'tipos' }} de aviso</template>
        </p>
        <p v-if="!cargando && !error && marcados.length && !abierto" class="text-xs text-gray-500 truncate">
          {{ marcados.map(g => g.nombre).join(', ') }}
        </p>
      </div>
      <ChevronDownIcon :class="['w-4 h-4 text-gray-400 transition-transform', abierto ? 'rotate-180' : '']" />
    </button>

    <div v-if="(incrustado || abierto) && !cargando && !error" :class="incrustado ? 'space-y-3' : 'px-4 pb-4 space-y-3'">
      <p v-if="incrustado" class="text-xs font-semibold text-gray-500 uppercase">Le llegan como urgentes</p>
      <p class="text-xs text-gray-500">
        Lo urgente sale en rojo y se queda fijado arriba de la campana hasta que
        {{ esMio ? 'lo abras o lo borres' : 'lo abra o lo borre' }}; en el celular se queda en la pantalla.
        Solo salen los avisos que {{ esMio ? 'te llegan' : 'le llegan' }}.
      </p>

      <p v-if="!lista.length" class="text-sm text-gray-400">No hay avisos para escoger.</p>

      <div class="divide-y divide-gray-100">
        <label
          v-for="g in lista" :key="g.clave"
          :class="['flex items-start gap-2.5 py-2.5 select-none', g.siempre ? '' : 'cursor-pointer']"
        >
          <!-- Los que el sistema ya manda urgentes (plata): prendido y fijo, sin
               interruptor que engañe (apagado no cambiaba nada). -->
          <button
            type="button" role="switch"
            :aria-checked="!!(g.urgente || g.siempre)"
            :aria-label="g.nombre"
            :disabled="!!guardando || !!g.siempre"
            @click.prevent="cambiar(g)"
            :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0 mt-0.5',
              g.siempre ? 'opacity-50 cursor-not-allowed' : 'disabled:opacity-50',
              g.urgente || g.siempre ? 'bg-red-600' : 'bg-gray-300']"
          >
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
              g.urgente || g.siempre ? 'translate-x-5' : 'translate-x-0.5']" />
          </button>
          <span class="text-sm flex-1 min-w-0 text-gray-800">
            {{ g.nombre }}
            <span class="block text-xs text-gray-400 mt-0.5">{{ g.descripcion }}</span>
            <span v-if="g.siempre" class="block text-xs text-gray-500 mt-0.5">{{ g.siempre }}</span>
          </span>
        </label>
      </div>
    </div>
  </div>
</template>
