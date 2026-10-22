<script setup>
/**
 * Qué avisos le llegan a esta persona (en la ficha de Trabajadores).
 *
 * Pedido del dueño: hay supervisores que no necesitan los cambios de plata,
 * los atrasados ni los próximos a entregar, pero sí las ventas nuevas y los
 * chats. Se prende y apaga por grupo; los grupos y lo que trae cada uno salen
 * del servidor (App\Support\TiposNotificacion). Lo apagado no se crea ni le
 * llega al celular; lo que ya tiene en la campana no se toca.
 *
 * Cada interruptor guarda solo al tocarlo, como en Gestión → Ajustes.
 */
import { ref, computed, onMounted } from 'vue'
import { getNotificacionesUsuario, setNotificacionesUsuario } from '@/api/usuarios'
import { useToast } from '@/composables/useToast'
import { BellIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({ usuarioId: { type: Number, required: true } })
const toast = useToast()

const grupos   = ref([])
const cargando = ref(true)
const error    = ref('')
const guardando = ref(null)   // clave del grupo que se está guardando ('todas' = prender todo)
const abierto  = ref(false)

const prendidos = computed(() => grupos.value.filter(g => g.prendido).length)
const apagados  = computed(() => grupos.value.filter(g => !g.prendido))

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await getNotificacionesUsuario(props.usuarioId)
    grupos.value = data.grupos
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudieron cargar las notificaciones.'
  } finally {
    cargando.value = false
  }
}

async function guardar(cambios, clave) {
  guardando.value = clave
  const antes = grupos.value.map(g => ({ ...g }))
  // Se ve el cambio ya; si el servidor dice que no, se devuelve.
  grupos.value = grupos.value.map(g => (g.clave in cambios ? { ...g, prendido: cambios[g.clave] } : g))
  try {
    const { data } = await setNotificacionesUsuario(props.usuarioId, cambios)
    grupos.value = data.grupos
    return true
  } catch (e) {
    grupos.value = antes
    toast.error(e.response?.data?.message ?? 'No se pudo guardar. Intenta otra vez.')
    return false
  } finally {
    guardando.value = null
  }
}

function cambiar(g) {
  if (guardando.value) return
  const prender = !g.prendido
  guardar({ [g.clave]: prender }, g.clave).then((ok) => {
    if (ok) toast.success(prender ? `Le van a llegar: ${g.nombre}.` : `Ya no le llegan: ${g.nombre}.`)
  })
}

function prenderTodas() {
  if (guardando.value) return
  guardar(Object.fromEntries(apagados.value.map(g => [g.clave, true])), 'todas')
}

onMounted(cargar)
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm">
    <button type="button" @click="abierto = !abierto" class="w-full flex items-center gap-3 p-4 text-left">
      <BellIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
      <div class="flex-1 min-w-0">
        <p class="text-xs font-semibold text-gray-500 uppercase">Notificaciones</p>
        <p v-if="cargando" class="text-sm text-gray-400">Cargando…</p>
        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
        <p v-else class="text-sm font-medium text-gray-800">
          <template v-if="!apagados.length">Le llegan todas</template>
          <template v-else>Le llegan {{ prendidos }} de {{ grupos.length }} tipos de aviso</template>
        </p>
        <p v-if="!cargando && !error && apagados.length && !abierto" class="text-xs text-gray-500 truncate">
          Apagadas: {{ apagados.map(g => g.nombre).join(', ') }}
        </p>
      </div>
      <ChevronDownIcon :class="['w-4 h-4 text-gray-400 transition-transform', abierto ? 'rotate-180' : '']" />
    </button>

    <div v-if="abierto && !cargando && !error" class="px-4 pb-4 space-y-3">
      <p class="text-xs text-gray-500">
        Lo apagado no le sale en la campana ni en el celular. Lo que ya tiene no se borra.
      </p>
      <button
        v-if="apagados.length"
        type="button" @click="prenderTodas" :disabled="!!guardando"
        class="text-xs font-semibold text-blue-600 disabled:opacity-50"
      >Prender todas</button>

      <div class="divide-y divide-gray-100">
        <label
          v-for="g in grupos" :key="g.clave"
          class="flex items-start gap-2.5 py-2.5 cursor-pointer select-none"
        >
          <button
            type="button" role="switch"
            :aria-checked="g.prendido"
            :aria-label="g.nombre"
            :disabled="!!guardando"
            @click.prevent="cambiar(g)"
            :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0 mt-0.5 disabled:opacity-50',
              g.prendido ? 'bg-blue-600' : 'bg-gray-300']"
          >
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
              g.prendido ? 'translate-x-5' : 'translate-x-0.5']" />
          </button>
          <span class="text-sm flex-1 min-w-0" :class="g.prendido ? 'text-gray-800' : 'text-gray-400'">
            {{ g.nombre }}
            <span class="block text-xs text-gray-400 mt-0.5">{{ g.descripcion }}</span>
            <span v-if="g.aviso && !g.prendido" class="block text-xs text-amber-700 mt-0.5">{{ g.aviso }}</span>
          </span>
        </label>
      </div>
    </div>
  </div>
</template>
