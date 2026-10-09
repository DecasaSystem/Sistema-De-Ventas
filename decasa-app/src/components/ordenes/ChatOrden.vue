<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue'
import api from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import IconoS from '@/components/common/IconoS.vue'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { conReintento } from '@/utils/colaPeticiones'
import { cloudinaryOpt } from '@/utils/cloudinary'
import {
  ChatBubbleLeftRightIcon, PaperAirplaneIcon, AtSymbolIcon, LockClosedIcon,
  PhotoIcon, XMarkIcon,
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
const permitido     = ref(true)    // el servidor dijo que esta persona participa

// ── Fotos adjuntas ──────────────────────────────────────────────────────────
// Varias por mensaje (la tela, la veta y el golpe). Cada una se sube apenas se
// elige, por su cuenta: si una falla, las demás siguen y esa se puede
// reintentar o quitar. Antes había un solo cupo y fallaba seguido:
//   - elegir la misma foto otra vez no hacía nada (el input no se limpiaba
//     cuando algo salía mal);
//   - si se elegía otra mientras subía la primera, la que fallara borraba a
//     la que sí subió;
//   - el envío iba con `fetch` a mano, sin reintento ante un corte de señal.
const MAX_FOTOS  = 6
const MAX_BYTES  = 10 * 1024 * 1024   // el mismo tope del servidor
const adjuntos   = ref([])            // [{ id, file, preview, url, estado: subiendo|lista|error, error }]
let _seqAdjunto  = 0

const subiendo   = computed(() => adjuntos.value.some(a => a.estado === 'subiendo'))
const conError   = computed(() => adjuntos.value.some(a => a.estado === 'error'))
const fotosListas = computed(() => adjuntos.value.filter(a => a.estado === 'lista').map(a => a.url))

const puedeEscribir = computed(() => abierto.value && !enviando.value)
const hayAlgoQueEnviar = computed(() => !!texto.value.trim() || fotosListas.value.length > 0)

function onFoto(e) {
  // Se limpia ya: así se puede volver a elegir la misma foto aunque algo falle.
  const archivos = Array.from(e.target.files || [])
  e.target.value = ''
  agregarArchivos(archivos)
}

/** Pegar una imagen (pantallazo) en el cuadro de texto, desde el computador. */
function onPegar(e) {
  const imagenes = Array.from(e.clipboardData?.items || [])
    .filter(it => it.kind === 'file' && it.type.startsWith('image/'))
    .map(it => it.getAsFile()).filter(Boolean)
  if (imagenes.length) {
    e.preventDefault()
    agregarArchivos(imagenes)
  }
}

function agregarArchivos(archivos) {
  const imagenes = archivos.filter(f => !f.type || f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name || ''))
  if (imagenes.length < archivos.length) toast.error('Solo se pueden mandar fotos.')
  const cupo = MAX_FOTOS - adjuntos.value.length
  if (imagenes.length > cupo) toast.error(`Máximo ${MAX_FOTOS} fotos por mensaje.`)
  for (const original of imagenes.slice(0, Math.max(0, cupo))) {
    const a = { id: ++_seqAdjunto, file: original, preview: '', url: '', estado: 'subiendo', error: '' }
    adjuntos.value.push(a)
    subirAdjunto(a.id)
  }
}

function buscar(id) {
  return adjuntos.value.find(a => a.id === id)
}

async function subirAdjunto(id) {
  let a = buscar(id)
  if (!a) return
  a.estado = 'subiendo'
  a.error  = ''
  try {
    // Achicada antes de mostrarla y subirla: la original de la cámara llena
    // la memoria del teléfono y tarda en subir con poca señal.
    const file = await comprimirImagen(a.file)
    a = buscar(id)
    if (!a) return                       // la quitaron mientras se achicaba
    if (!a.preview) a.preview = URL.createObjectURL(file)
    if (file.size > MAX_BYTES) throw new Error('La foto pesa más de 10 MB.')

    const fd = new FormData()
    // El nombre según lo que de verdad es: si no se pudo achicar, sigue
    // siendo la original (PNG, HEIC...).
    const ext = file.type === 'image/jpeg' ? 'jpg' : ((a.file.name || '').split('.').pop() || 'jpg')
    fd.append('foto', file, `chat.${ext}`)
    fd.append('folder', 'chat-ordenes')
    const { data } = await conReintento(() => api.post('/upload/foto', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }, silencioso: true, timeout: 60000,
    }))
    if (!data?.url) throw new Error('El servidor no devolvió la foto.')
    a = buscar(id)
    if (!a) return
    a.url = data.url
    a.estado = 'lista'
  } catch (err) {
    a = buscar(id)
    if (!a) return
    a.estado = 'error'
    a.error  = err?.response?.data?.message
      || (err?.response ? `El servidor respondió ${err.response.status}` : (err?.message || 'Sin conexión'))
  }
}

function quitarFoto(id) {
  const i = adjuntos.value.findIndex(a => a.id === id)
  if (i === -1) return
  if (adjuntos.value[i].preview) URL.revokeObjectURL(adjuntos.value[i].preview)
  adjuntos.value.splice(i, 1)
}

function quitarTodas() {
  adjuntos.value.forEach(a => a.preview && URL.revokeObjectURL(a.preview))
  adjuntos.value = []
}

/** Las fotos de un mensaje; los viejos traen una sola en `imagen_url`. */
function fotosDe(m) {
  if (Array.isArray(m.imagenes) && m.imagenes.length) return m.imagenes
  return m.imagen_url ? [m.imagen_url] : []
}

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
  } catch (e) {
    // Quién participa lo decide el servidor (OrdenMensajeController): si dice
    // que no, el chat no se pinta. Si es la señal, se queda con lo que había.
    if (e.response?.status === 403) permitido.value = false
    else if (!mensajes.value.length) mensajes.value = []
  } finally {
    cargando.value = false
  }
}

async function enviar() {
  const t = texto.value.trim()
  if (subiendo.value) { toast.info('Espera a que terminen de subir las fotos.'); return }
  if (conError.value) { toast.error('Una foto no subió: reintenta o quítala antes de enviar.'); return }
  if ((!t && !fotosListas.value.length) || enviando.value) return
  enviando.value = true
  try {
    const { data } = await api.post(`/ordenes/${props.ordenId}/mensajes`, {
      mensaje: t || undefined,
      imagenes: fotosListas.value.length ? fotosListas.value : undefined,
      mencionados: mencionados.value.length ? mencionados.value : undefined,
    })
    // Se agrega de una: el eco del WebSocket puede tardar y se siente lento
    if (!mensajes.value.some(m => m.id === data.id)) mensajes.value.push(data)
    texto.value = ''
    quitarTodas()
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
onBeforeUnmount(() => { desconectar(); quitarTodas() })
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
  <div v-if="permitido" class="bg-white rounded-xl shadow-sm overflow-hidden">
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

            <!-- Una foto se ve grande; varias, en cuadrícula. Cada una abre la original. -->
            <div v-if="fotosDe(m).length" :class="['mb-1', fotosDe(m).length > 1 ? 'grid grid-cols-2 gap-1' : '']">
              <a v-for="(url, k) in fotosDe(m)" :key="k" :href="url" target="_blank" rel="noopener" class="block">
                <img
                  :src="cloudinaryOpt(url, fotosDe(m).length > 1 ? 300 : 500)"
                  loading="lazy"
                  :class="['rounded-lg object-cover border', fotosDe(m).length > 1 ? 'w-full h-24' : 'max-h-52 w-auto',
                    esMio(m) ? 'border-blue-400' : 'border-gray-200']"
                />
              </a>
            </div>

            <p v-if="m.mensaje" class="whitespace-pre-line">{{ m.mensaje }}</p>
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
                : d.es_de_la_orden
                  ? 'bg-white text-blue-700 border-blue-300 hover:border-amber-400 font-medium'
                  : 'bg-white text-gray-600 border-gray-300 hover:border-amber-400']"
          >
            {{ d.nombre }}
            <span v-if="d.es_de_la_orden" class="opacity-70">· hizo la venta</span>
          </button>
        </div>

        <p v-if="mencionados.length" class="text-[11px] text-amber-700">
          Le llega la notificación a
          <strong>{{ destinatarios.filter(d => mencionados.includes(d.id)).map(d => d.nombre).join(' y ') }}</strong>.
          Los demás lo ven igual y pueden responder.
        </p>

        <!-- Fotos adjuntas antes de mandarlas: cada una con su estado -->
        <div v-if="adjuntos.length" class="flex flex-wrap gap-2">
          <div v-for="a in adjuntos" :key="a.id" class="relative">
            <img v-if="a.preview" :src="a.preview" alt=""
              :class="['h-20 w-20 rounded-lg border object-cover', a.estado === 'error' ? 'border-red-400 opacity-60' : 'border-gray-200']" />
            <div v-else class="h-20 w-20 rounded-lg border border-gray-200 bg-gray-100" />
            <button
              type="button"
              @click="quitarFoto(a.id)"
              class="absolute -top-1.5 -right-1.5 bg-white rounded-full shadow p-0.5 text-red-500 hover:text-red-700"
              aria-label="Quitar foto"
            >
              <XMarkIcon class="w-3.5 h-3.5" />
            </button>
            <div v-if="a.estado === 'subiendo'" class="absolute inset-0 bg-white/70 rounded-lg flex items-center justify-center">
              <IconoS class="w-5 h-5 text-blue-500" />
            </div>
            <button v-else-if="a.estado === 'error'" type="button" @click="subirAdjunto(a.id)" :title="a.error"
              class="absolute inset-x-1 bottom-1 bg-red-600 text-white text-[10px] font-semibold rounded py-0.5">
              Reintentar
            </button>
          </div>
        </div>
        <p v-if="conError" class="text-[11px] text-red-600">
          {{ adjuntos.find(a => a.estado === 'error')?.error }} — toca "Reintentar" o quita la foto.
        </p>

        <div class="flex items-end gap-2">
          <label
            class="h-9 w-9 rounded-lg border border-gray-300 bg-white text-gray-500 flex items-center justify-center flex-shrink-0 cursor-pointer hover:border-blue-400 hover:text-blue-500 transition-colors"
            title="Mandar fotos (puedes elegir varias)"
          >
            <PhotoIcon class="w-4 h-4" />
            <input type="file" accept="image/*" multiple class="hidden" @change="onFoto" />
          </label>

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
            @paste="onPegar"
            class="flex-1 resize-none rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />

          <button
            type="button"
            @click="enviar"
            :disabled="!hayAlgoQueEnviar || !puedeEscribir || subiendo || conError"
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
