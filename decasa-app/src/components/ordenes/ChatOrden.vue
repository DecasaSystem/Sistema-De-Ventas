<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue'
import api from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import IconoS from '@/components/common/IconoS.vue'
import {
  ChatBubbleLeftRightIcon, PaperAirplaneIcon, AtSymbolIcon, LockClosedIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  ordenId: { type: [Number, String], required: true },
  estado:  { type: String, default: '' },
})

const auth  = useAuthStore()
const toast = useToast()

const mensajes      = ref([])
const destinatarios = ref([])
const abierto       = ref(true)
const cargando      = ref(true)
const enviando      = ref(false)
const texto         = ref('')
const mencionados   = ref([])          // ids de a quién se le pregunta
const mostrarMenciones = ref(false)
const hilo          = ref(null)

const puedeEscribir = computed(() => abierto.value && !enviando.value)

/** Los mensajes en los que a mí me preguntaron: van resaltados. */
function meMencionaron(m) {
  return (m.mencionados ?? []).includes(auth.usuario?.id)
}

function esMio(m) {
  return m.usuario?.id === auth.usuario?.id
}

function nombresMencionados(m) {
  return (m.mencionados ?? [])
    .map(id => destinatarios.value.find(d => d.id === id)?.nombre
             ?? (id === auth.usuario?.id ? 'ti' : null))
    .filter(Boolean)
}

function toggleMencion(id) {
  const i = mencionados.value.indexOf(id)
  if (i >= 0) mencionados.value.splice(i, 1)
  else        mencionados.value.push(id)
}

async function alFinal() {
  await nextTick()
  if (hilo.value) hilo.value.scrollTop = hilo.value.scrollHeight
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get(`/ordenes/${props.ordenId}/mensajes`)
    mensajes.value      = data.mensajes ?? []
    destinatarios.value = data.destinatarios ?? []
    abierto.value       = !!data.abierto
    await alFinal()
  } catch {
    // Sin permiso o sin conexión: el chat simplemente no se muestra
    mensajes.value = []
  } finally {
    cargando.value = false
  }
}

async function enviar() {
  const t = texto.value.trim()
  if (!t || enviando.value) return
  enviando.value = true
  try {
    const { data } = await api.post(`/ordenes/${props.ordenId}/mensajes`, {
      mensaje: t,
      mencionados: mencionados.value.length ? mencionados.value : undefined,
    })
    // Se agrega de una: el eco del WebSocket puede tardar y se siente lento
    if (!mensajes.value.some(m => m.id === data.id)) mensajes.value.push(data)
    texto.value = ''
    mencionados.value = []
    mostrarMenciones.value = false
    await alFinal()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo enviar el mensaje.')
  } finally {
    enviando.value = false
  }
}

// ── Tiempo real ─────────────────────────────────────────────────────────────
let canal = null
function conectar() {
  if (!window.Echo || canal) return
  canal = `orden.${props.ordenId}`
  window.Echo.channel(canal)
    .stopListening('.orden.mensaje')
    .listen('.orden.mensaje', (m) => {
      // El propio ya se agregó al enviar; sin esto saldría dos veces
      if (mensajes.value.some(x => x.id === m.id)) return
      mensajes.value.push(m)
      alFinal()
    })
}
function desconectar() {
  if (window.Echo && canal) window.Echo.leave(canal)
  canal = null
}

onMounted(() => { cargar(); conectar() })
onBeforeUnmount(desconectar)
watch(() => props.estado, cargar)

function hora(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const hoy = new Date()
  const mismoDia = d.toDateString() === hoy.toDateString()
  return mismoDia
    ? d.toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' })
    : d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
      + ' ' + d.toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' })
}

function iniciales(nombre) {
  return (nombre ?? '?').trim().split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase()
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-2">
      <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
        <ChatBubbleLeftRightIcon class="w-4 h-4" />
        Dudas de esta orden
        <span v-if="mensajes.length" class="text-gray-400 font-normal normal-case">({{ mensajes.length }})</span>
      </p>
      <span
        v-if="!cargando && !abierto"
        class="text-[11px] text-gray-500 bg-gray-100 rounded-full px-2 py-0.5 flex items-center gap-1"
      >
        <LockClosedIcon class="w-3 h-3" /> Cerrado
      </span>
    </div>

    <!-- Hilo -->
    <div ref="hilo" class="max-h-80 overflow-y-auto px-4 py-3 space-y-3 bg-gray-50/60">
      <div v-if="cargando" class="flex justify-center py-6">
        <IconoS class="w-6 h-6 text-blue-500" />
      </div>

      <p v-else-if="!mensajes.length" class="text-xs text-gray-400 text-center py-6 leading-relaxed">
        Sin mensajes todavía.<br />
        Pregúntale a un supervisor sobre esta orden y queda guardado aquí.
      </p>

      <div
        v-for="m in mensajes"
        :key="m.id"
        :class="['flex gap-2', esMio(m) ? 'flex-row-reverse' : '']"
      >
        <!-- Avatar con iniciales -->
        <div
          :class="['w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold flex-shrink-0 mt-0.5',
            esMio(m) ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-700']"
          :title="m.usuario?.nombre"
        >{{ iniciales(m.usuario?.nombre) }}</div>

        <div :class="['max-w-[78%] min-w-0', esMio(m) ? 'items-end' : '']">
          <p :class="['text-[11px] text-gray-400 mb-0.5 flex items-center gap-1', esMio(m) ? 'justify-end' : '']">
            <span class="font-medium text-gray-500">{{ esMio(m) ? 'Tú' : m.usuario?.nombre }}</span>
            · {{ hora(m.created_at) }}
          </p>

          <div
            :class="['rounded-2xl px-3 py-2 text-sm leading-snug break-words',
              esMio(m)          ? 'bg-blue-600 text-white rounded-br-sm'
              : meMencionaron(m) ? 'bg-amber-50 border border-amber-300 text-gray-800 rounded-bl-sm'
                                 : 'bg-white border border-gray-200 text-gray-800 rounded-bl-sm']"
          >
            <p
              v-if="nombresMencionados(m).length"
              :class="['text-[11px] font-semibold mb-0.5 flex items-center gap-0.5',
                esMio(m) ? 'text-blue-100' : 'text-amber-700']"
            >
              <AtSymbolIcon class="w-3 h-3" />
              {{ nombresMencionados(m).join(', ') }}
            </p>
            {{ m.mensaje }}
          </div>
        </div>
      </div>
    </div>

    <!-- Escribir -->
    <div v-if="!cargando" class="border-t border-gray-100 p-3 space-y-2">
      <template v-if="abierto">
        <!-- A quién se le pregunta -->
        <div v-if="mostrarMenciones && destinatarios.length" class="flex flex-wrap gap-1.5 pb-1">
          <button
            v-for="d in destinatarios"
            :key="d.id"
            type="button"
            @click="toggleMencion(d.id)"
            :class="['text-xs px-2.5 py-1 rounded-full border transition-colors',
              mencionados.includes(d.id)
                ? 'bg-amber-500 text-white border-amber-500'
                : 'bg-white text-gray-600 border-gray-300 hover:border-amber-400']"
          >{{ d.nombre }}</button>
        </div>

        <p v-if="mencionados.length" class="text-[11px] text-amber-700">
          Le llega la notificación a
          <strong>{{ destinatarios.filter(d => mencionados.includes(d.id)).map(d => d.nombre).join(' y ') }}</strong>.
          Los demás lo ven igual y pueden responder.
        </p>

        <div class="flex items-end gap-2">
          <button
            type="button"
            @click="mostrarMenciones = !mostrarMenciones"
            :class="['h-9 w-9 rounded-lg border flex items-center justify-center flex-shrink-0 transition-colors',
              mencionados.length
                ? 'bg-amber-500 text-white border-amber-500'
                : 'bg-white text-gray-500 border-gray-300 hover:border-amber-400']"
            title="Elegir a quién preguntarle"
          >
            <AtSymbolIcon class="w-4 h-4" />
          </button>

          <textarea
            v-model="texto"
            rows="1"
            placeholder="Escribe tu duda..."
            @keydown.enter.exact.prevent="enviar"
            class="flex-1 resize-none rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />

          <button
            type="button"
            @click="enviar"
            :disabled="!texto.trim() || !puedeEscribir"
            class="h-9 w-9 rounded-lg bg-blue-600 text-white flex items-center justify-center flex-shrink-0 hover:bg-blue-700 disabled:opacity-40 transition-colors"
          >
            <IconoS v-if="enviando" class="w-4 h-4" />
            <PaperAirplaneIcon v-else class="w-4 h-4" />
          </button>
        </div>
      </template>

      <p v-else class="text-xs text-gray-400 text-center py-1">
        El chat se cerró cuando la orden quedó lista para entrega. La conversación queda de consulta.
      </p>
    </div>
  </div>
</template>
