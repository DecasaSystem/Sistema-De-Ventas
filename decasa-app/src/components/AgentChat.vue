<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import DOMPurify from 'dompurify'
import { useVisorFoto } from '@/composables/useVisorFoto'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { chatWithAgent } from '@/api/agent.js'
import {
  SparklesIcon,
  XMarkIcon,
  PaperAirplaneIcon,
  ArrowPathIcon,
  PhotoIcon,
  MicrophoneIcon,
  WrenchScrewdriverIcon,
  ChartBarIcon,
  ClipboardDocumentListIcon,
  BookOpenIcon,
  TagIcon,
  ShieldCheckIcon,
  CubeIcon,
  ReceiptPercentIcon,
  BanknotesIcon,
  ClipboardDocumentIcon,
  ShareIcon,
  CheckIcon,
  ArrowsPointingOutIcon,
  ArrowsPointingInIcon,
  ArrowUturnLeftIcon,
} from '@heroicons/vue/24/outline'
import { SparklesIcon as SparklesSolid } from '@heroicons/vue/24/solid'

const auth  = useAuthStore()
const toast = useToast()

const abierto   = ref(false)
const cargando  = ref(false)
const ampliado  = ref(false)   // panel más grande en computador
const noLeido   = ref(false)   // llegó respuesta con el panel cerrado

// ── Botón arrastrable ─────────────────────────────────────────────────────────
const BTN_SIZE = 52
const MARGEN   = 8

/**
 * La posición guardada, siempre dentro de la ventana de AHORA.
 *
 * Se guarda en píxeles desde la esquina de arriba a la izquierda. Si se movió
 * con la ventana grande (maximizada, un monitor grande, otro zoom) y después
 * la ventana es más chica, quedaba dibujada más allá del borde: la burbuja
 * "desaparecía" y nadie sabía por qué. Aquí se mete de nuevo en la pantalla
 * sin tocar lo guardado, así que al volver a la ventana grande vuelve a su sitio.
 */
function dentroDeLaVentana(pos) {
  return {
    x: Math.min(Math.max(MARGEN, pos.x), window.innerWidth  - BTN_SIZE - MARGEN),
    y: Math.min(Math.max(MARGEN, pos.y), window.innerHeight - BTN_SIZE - MARGEN),
  }
}
function posGuardada() {
  try {
    const saved = JSON.parse(localStorage.getItem('agent-btn-pos'))
    if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) return saved
  } catch {}
  return { x: window.innerWidth - BTN_SIZE - 16, y: window.innerHeight - BTN_SIZE - 160 }
}
const btnPos     = ref(dentroDeLaVentana(posGuardada()))
function reacomodar() {
  if (!arrastrando.value) btnPos.value = dentroDeLaVentana(posGuardada())
}
const arrastrando = ref(false)
let _dragClientStart = { x: 0, y: 0 }
let _dragPosStart    = { x: 0, y: 0 }
let _seArrastro      = false

function onBtnPointerDown(e) {
  e.currentTarget.setPointerCapture(e.pointerId)
  arrastrando.value   = true
  _seArrastro         = false
  _dragClientStart    = { x: e.clientX, y: e.clientY }
  _dragPosStart       = { ...btnPos.value }
}
function onBtnPointerMove(e) {
  if (!arrastrando.value) return
  const dx = e.clientX - _dragClientStart.x
  const dy = e.clientY - _dragClientStart.y
  if (Math.abs(dx) > 4 || Math.abs(dy) > 4) _seArrastro = true
  btnPos.value = dentroDeLaVentana({ x: _dragPosStart.x + dx, y: _dragPosStart.y + dy })
}
function onBtnPointerUp() {
  arrastrando.value = false
  if (!_seArrastro) {
    toggle()
  } else {
    try { localStorage.setItem('agent-btn-pos', JSON.stringify(btnPos.value)) } catch {}
  }
}

// ── Conversación ──────────────────────────────────────────────────────────────
const inputText = ref('')
const { abrirFoto, cerrarFoto } = useVisorFoto()   // imagen en grande (visor único)

const SALUDO = {
  role: 'assistant',
  content: 'Puedo ayudarte con ventas y qué conviene fabricar, inventario, órdenes, producción, garantías, promociones, catálogos y costos de fabricación.',
}

/**
 * La conversación sobrevive a recargar la página, pero solo en esta pestaña
 * (sessionStorage) y por persona: con perfiles alternos, la de una cuenta no
 * le sale a la otra. Las fotos no se guardan (pesan); queda su nombre.
 */
const claveGuardado = computed(() => `agent-chat:${auth.usuario?.id ?? 'anon'}`)
function cargarGuardado() {
  try {
    const guardado = JSON.parse(sessionStorage.getItem(claveGuardado.value))
    if (Array.isArray(guardado) && guardado.length) return [SALUDO, ...guardado]
  } catch {}
  return [SALUDO]
}
const mensajes = ref(cargarGuardado())
watch(claveGuardado, () => { mensajes.value = cargarGuardado() })
watch(mensajes, (lista) => {
  try {
    const sinFotos = lista.slice(1).slice(-30).map(({ image, ...resto }) => resto)
    sessionStorage.setItem(claveGuardado.value, JSON.stringify(sinFotos))
  } catch {}
}, { deep: true })

const primerNombre = computed(() => (auth.usuario?.nombre || '').trim().split(/\s+/)[0] || '')
const enInicio     = computed(() => mensajes.value.length === 1)

const inputRef    = ref(null)
const listRef     = ref(null)
const fileInputRef = ref(null)

// imagen adjunta pendiente de enviar
const imagenPendiente = ref(null)   // { base64: string, nombre: string }

// ── Temas del inicio: solo lo que esta persona puede preguntar ────────────────
const temas = computed(() => [
  { icono: WrenchScrewdriverIcon, titulo: 'Qué fabricar',   texto: 'Lo que se agota y cuánto hacer',  pregunta: '¿Qué producto deberíamos fabricar más y cuántas unidades?' },
  { icono: ChartBarIcon,          titulo: 'Ventas',          texto: 'Cómo vamos y qué se vende más',    pregunta: '¿Cómo van las ventas este mes comparado con el anterior y qué se vende más?' },
  { icono: ClipboardDocumentListIcon, titulo: 'Órdenes',     texto: 'Listas, en camino, con saldo',     pregunta: '¿Qué órdenes están listas para entregar?' },
  { icono: CubeIcon,              titulo: 'Inventario',      texto: 'Stock libre por tienda',            pregunta: '¿Qué camas tenemos libres en todas las tiendas?' },
  { icono: BookOpenIcon,          titulo: 'Catálogos',       texto: 'Enlaces para el cliente',           pregunta: 'Dame los enlaces de los catálogos' },
  { icono: TagIcon,               titulo: 'Promociones',     texto: 'Qué está rebajado y dónde',         pregunta: '¿Qué promociones hay vigentes?' },
  { icono: ShieldCheckIcon,       titulo: 'Garantías',       texto: 'Abiertas y por responder',          pregunta: '¿Qué garantías hay abiertas?' },
  auth.tieneAccesoComisiones && { icono: ReceiptPercentIcon, titulo: 'Comisiones', texto: 'Cuánto llevas y por qué', pregunta: '¿Cuánto llevo de comisión este mes y cuándo la cobro?' },
  auth.puedeFinanzas && { icono: BanknotesIcon, titulo: 'Finanzas', texto: 'Utilidad y próximos pagos', pregunta: '¿Cómo vamos este mes en plata y qué hay que pagar pronto?' },
].filter(Boolean))

// ── Reconocimiento de voz (Web Speech API) ────────────────────────────────
const escuchando = ref(false)
let reconocimiento = null

function toggleVoz() {
  if (escuchando.value) {
    reconocimiento?.stop()
    return
  }

  const SR = window.SpeechRecognition || window.webkitSpeechRecognition
  if (!SR) {
    toast.error('Tu navegador no reconoce la voz. Usa Chrome o Edge.')
    return
  }

  reconocimiento          = new SR()
  reconocimiento.lang     = 'es-CO'
  reconocimiento.continuous      = false
  reconocimiento.interimResults  = true

  const textoBase = inputText.value.trim()

  reconocimiento.onstart = () => { escuchando.value = true }

  reconocimiento.onresult = (e) => {
    const transcripcion = Array.from(e.results).map(r => r[0].transcript).join('')
    inputText.value = textoBase ? textoBase + ' ' + transcripcion : transcripcion
  }

  reconocimiento.onend = () => {
    escuchando.value = false
    reconocimiento   = null
    nextTick(() => inputRef.value?.focus())
  }

  reconocimiento.onerror = () => {
    escuchando.value = false
    reconocimiento   = null
  }

  reconocimiento.start()
}

function abrirSelectorImagen() {
  fileInputRef.value?.click()
}

async function onImagenSeleccionada(e) {
  const archivo = e.target.files?.[0]
  if (!archivo) return
  e.target.value = ''   // reset para poder seleccionar la misma imagen de nuevo

  const base64 = await redimensionarImagen(archivo, 900, 0.82)
  imagenPendiente.value = { base64, nombre: archivo.name }
}

function quitarImagen() {
  imagenPendiente.value = null
}

// Redimensiona la imagen en canvas y devuelve un data URL JPEG reducido
function redimensionarImagen(archivo, maxPx, calidad) {
  return new Promise((resolve) => {
    const reader = new FileReader()
    reader.onload = (ev) => {
      const img = new Image()
      img.onload = () => {
        const ratio = Math.min(maxPx / img.width, maxPx / img.height, 1)
        const w = Math.round(img.width  * ratio)
        const h = Math.round(img.height * ratio)
        const canvas = document.createElement('canvas')
        canvas.width  = w
        canvas.height = h
        canvas.getContext('2d').drawImage(img, 0, 0, w, h)
        resolve(canvas.toDataURL('image/jpeg', calidad))
      }
      img.src = ev.target.result
    }
    reader.readAsDataURL(archivo)
  })
}

function toggle() {
  abierto.value = !abierto.value
  if (abierto.value) {
    noLeido.value = false
    nextTick(() => inputRef.value?.focus())
    // En el inicio se queda arriba (el saludo y los temas); con conversación,
    // al último mensaje, después de la transición de apertura.
    if (!enInicio.value) setTimeout(scrollAbajo, 240)
  }
}

// ── Mientras piensa: qué está haciendo, para que no parezca colgado ───────────
const FRASES_CARGA = ['Leyendo tu pregunta…', 'Buscando en el sistema…', 'Revisando los datos…', 'Haciendo las cuentas…', 'Armando la respuesta…']
const fraseCarga = ref(FRASES_CARGA[0])
let _timerCarga = null
watch(cargando, (si) => {
  clearInterval(_timerCarga)
  if (!si) return
  let i = 0
  fraseCarga.value = FRASES_CARGA[0]
  _timerCarga = setInterval(() => {
    i = Math.min(i + 1, FRASES_CARGA.length - 1)
    fraseCarga.value = FRASES_CARGA[i]
  }, 2200)
})

function horaAhora() {
  return new Date().toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' })
}

/**
 * La IA termina cada respuesta con una línea ">> pregunta | pregunta" (ver el
 * prompt en AgentService): se quita del texto y se muestra como botones.
 */
function separarSugerencias(texto) {
  const lineas = (texto || '').trimEnd().split('\n')
  const ultima = lineas[lineas.length - 1]?.trim() ?? ''
  if (!ultima.startsWith('>>')) return { contenido: texto || '', sugerencias: [] }
  const sugerencias = ultima.slice(2).split('|').map(s => s.trim()).filter(s => s && s.length <= 80).slice(0, 3)
  return { contenido: lineas.slice(0, -1).join('\n').trimEnd(), sugerencias }
}

async function enviar(textoDirecto = null) {
  const texto  = (typeof textoDirecto === 'string' ? textoDirecto : inputText.value).trim()
  const imagen = typeof textoDirecto === 'string' ? null : imagenPendiente.value

  if ((!texto && !imagen) || cargando.value) return

  const textoFinal = texto || '¿Cuánto cuesta fabricar este mueble?'

  mensajes.value.push({
    role    : 'user',
    content : textoFinal,
    image   : imagen?.base64 ?? null,
    imgNombre: imagen?.nombre ?? null,
    hora    : horaAhora(),
  })

  if (typeof textoDirecto !== 'string') {
    inputText.value       = ''
    imagenPendiente.value = null
  }
  await pedirRespuesta()
}

async function pedirRespuesta() {
  cargando.value = true
  await scrollAbajo()

  try {
    // Solo los últimos 10 turnos, sin el saludo ni los avisos de error.
    const historial = mensajes.value
      .slice(1)
      .filter(m => !m.error)
      .slice(-10)
      .map(m => {
        const entry = { role: m.role, content: m.content }
        if (m.image) entry.image = m.image
        return entry
      })

    const { data } = await chatWithAgent(historial)
    const { contenido, sugerencias } = separarSugerencias(data.respuesta)
    mensajes.value.push({ role: 'assistant', content: contenido, sugerencias, hora: horaAhora() })
    if (!abierto.value) noLeido.value = true
  } catch (e) {
    mensajes.value.push({
      role: 'assistant',
      content: e?.response?.status === 429
        ? 'Hiciste muchas preguntas seguidas. Espera un minuto y vuelve a intentar.'
        : 'No pude consultar en este momento.',
      error: true,
      hora: horaAhora(),
    })
  } finally {
    cargando.value = false
    await scrollAbajo()
  }
}

// Reintentar: se quita el aviso de error y se vuelve a preguntar lo mismo.
function reintentar(i) {
  if (cargando.value) return
  mensajes.value.splice(i, 1)
  pedirRespuesta()
}

function onKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    enviar()
  }
  if (e.key === 'Escape') {
    cerrarFoto()
  }
}

function onEscGlobal(e) {
  if (e.key === 'Escape') cerrarFoto()
}
onMounted(() => {
  window.addEventListener('keydown', onEscGlobal)
  // Ventana que se achica (restaurarla, abrir la consola, cambiar el zoom,
  // girar el celular): la burbuja vuelve a quedar dentro.
  window.addEventListener('resize', reacomodar)
  reacomodar()
})
onUnmounted(() => {
  window.removeEventListener('keydown', onEscGlobal)
  window.removeEventListener('resize', reacomodar)
  clearInterval(_timerCarga)
  reconocimiento?.stop()
})


function limpiar() {
  if (cargando.value) return
  mensajes.value = [SALUDO]
}

async function scrollAbajo() {
  await nextTick()
  if (listRef.value) {
    listRef.value.scrollTop = listRef.value.scrollHeight
  }
}

// ── Copiar y compartir una respuesta ──────────────────────────────────────────
// Texto plano para pegar en WhatsApp: sin asteriscos y con el enlace a la vista.
function textoPlano(md) {
  return md
    .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, '$1: $2')
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/`(.+?)`/g, '$1')
}
const copiado = ref(null)
async function copiar(msg, i) {
  try {
    await navigator.clipboard.writeText(textoPlano(msg.content))
    copiado.value = i
    setTimeout(() => { if (copiado.value === i) copiado.value = null }, 1500)
  } catch {
    toast.error('No se pudo copiar. Mantén presionado el texto para copiarlo.')
  }
}
const puedeCompartir = typeof navigator !== 'undefined' && !!navigator.share
async function compartir(msg) {
  try { await navigator.share({ text: textoPlano(msg.content) }) } catch {}
}

// ── Formato de las respuestas ─────────────────────────────────────────────────
// Escapa todo, luego da formato: negritas, cursivas, código, enlaces y listas.
// Los enlaces ([Camas](https://…) o una dirección suelta) se apartan antes de las
// negritas para que un * o un _ de la dirección no la rompa, y se devuelven al
// final. Solo http(s): nada de javascript: ni otros esquemas.
function formatearTexto(texto) {
  const enlaces = []
  const apartar = (url, etiqueta) => {
    enlaces.push({ url, etiqueta })
    return `\u0000${enlaces.length - 1}\u0000`
  }
  const enLinea = (linea) => linea
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/`(.+?)`/g, '<code class="bg-gray-200 px-1 rounded text-xs">$1</code>')

  const escapado = texto
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, etiqueta, url) => apartar(url, etiqueta))
    .replace(/https?:\/\/[^\s<\u0000]*[^\s<\u0000.,;:!?)\]'"]/g, (url) => apartar(url, url))

  // Cada línea es un bloque: las viñetas (-, ·, •) y los números (1.) se
  // alinean con su sangría; un título de markdown (### x) sale en negrita.
  const html = escapado.split('\n').map((linea) => {
    const t = linea.trim()
    if (!t) return '<div class="h-2"></div>'
    let m
    if ((m = t.match(/^[-·•*]\s+(.*)$/))) {
      return `<div class="flex gap-2 pl-0.5"><span class="text-blue-500 flex-shrink-0">•</span><span class="min-w-0">${enLinea(m[1])}</span></div>`
    }
    if ((m = t.match(/^(\d+)[.)]\s+(.*)$/))) {
      return `<div class="flex gap-2"><span class="text-blue-600 font-semibold flex-shrink-0 tabular-nums">${m[1]}.</span><span class="min-w-0">${enLinea(m[2])}</span></div>`
    }
    if ((m = t.match(/^#{1,4}\s+(.*)$/))) {
      return `<div class="font-semibold text-gray-900 mt-1">${enLinea(m[1])}</div>`
    }
    return `<div>${enLinea(t)}</div>`
  }).join('')
    .replace(/\u0000(\d+)\u0000/g, (_, i) => {
      const { url, etiqueta } = enlaces[Number(i)]
      return `<a href="${url.replace(/"/g, '%22')}" class="text-blue-600 font-medium underline underline-offset-2 break-words">${etiqueta}</a>`
    })

  // DOMPurify quita `target` aunque se permita: se le pone después, con valores
  // fijos, para que el enlace abra aparte y no saque a la persona del chat.
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ['strong', 'em', 'code', 'br', 'a', 'div', 'span'],
    ALLOWED_ATTR: ['class', 'href'],
    ALLOWED_URI_REGEXP: /^https?:\/\//i,
  }).replace(/<a /g, '<a target="_blank" rel="noopener noreferrer" ')
}
</script>

<template>
  <Teleport to="body">
    <!-- Botón flotante (arrastrable) -->
    <button
      v-if="!abierto"
      :style="{ left: btnPos.x + 'px', top: btnPos.y + 'px', touchAction: 'none' }"
      :class="['fixed z-50 w-13 h-13 bg-blue-600 text-white rounded-full shadow-lg flex items-center justify-center select-none',
               arrastrando ? 'cursor-grabbing scale-110 shadow-xl' : 'cursor-grab hover:bg-blue-700 hover:scale-105 active:scale-95 transition-all']"
      @pointerdown="onBtnPointerDown"
      @pointermove="onBtnPointerMove"
      @pointerup="onBtnPointerUp"
      title="Asistente Decasa — arrastra para mover"
    >
      <SparklesSolid class="w-6 h-6" :class="cargando ? 'animate-pulse' : ''" />
      <!-- Llegó la respuesta con el chat cerrado -->
      <span v-if="noLeido" class="absolute top-0.5 right-0.5 w-3 h-3 bg-red-500 rounded-full ring-2 ring-white" />
    </button>

    <!-- Panel de chat -->
    <Transition name="chat-slide">
      <div
        v-if="abierto"
        :class="['fixed bottom-0 right-0 z-50 w-full flex flex-col bg-white sm:rounded-2xl shadow-2xl border border-gray-200 overflow-hidden sm:bottom-4 sm:right-4 transition-[width,height] duration-200',
                 ampliado ? 'sm:w-[40rem]' : 'sm:w-[26rem]']"
        :style="ampliado ? 'max-height: min(92dvh, 860px); height: 92dvh;' : 'max-height: min(85dvh, 640px); height: 85dvh;'"
      >
        <!-- Header -->
        <div class="flex items-center gap-2.5 px-4 py-3 bg-blue-600 text-white flex-shrink-0">
          <div class="relative w-9 h-9 rounded-full bg-white/15 flex items-center justify-center flex-shrink-0">
            <SparklesSolid class="w-5 h-5" />
            <span class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-400 rounded-full ring-2 ring-blue-600" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-sm leading-tight">Asistente Decasa</p>
            <p class="text-blue-100 text-xs leading-tight truncate">
              {{ cargando ? fraseCarga : 'Con los datos del sistema, al día' }}
            </p>
          </div>
          <button
            v-if="!enInicio"
            @click="limpiar"
            :disabled="cargando"
            class="p-1.5 rounded-lg hover:bg-white/15 transition-colors disabled:opacity-50"
            title="Nueva conversación"
          >
            <ArrowPathIcon class="w-4 h-4" />
          </button>
          <button
            @click="ampliado = !ampliado"
            class="hidden sm:block p-1.5 rounded-lg hover:bg-white/15 transition-colors"
            :title="ampliado ? 'Achicar' : 'Agrandar'"
          >
            <component :is="ampliado ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="w-4 h-4" />
          </button>
          <button
            @click="toggle"
            class="p-1.5 rounded-lg hover:bg-white/15 transition-colors"
            title="Cerrar"
          >
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <!-- Mensajes -->
        <div
          ref="listRef"
          class="flex-1 overflow-y-auto px-3 py-4 space-y-4 scroll-smooth bg-gray-50"
        >
          <!-- Inicio: saludo + temas -->
          <div v-if="enInicio" class="space-y-4">
            <div class="text-center px-2 pt-2">
              <div class="mx-auto w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center shadow-sm mb-3">
                <SparklesSolid class="w-6 h-6" />
              </div>
              <p class="text-lg font-bold text-gray-900">Hola{{ primerNombre ? `, ${primerNombre}` : '' }} 👋</p>
              <p class="text-sm text-gray-500 mt-1 leading-snug">{{ SALUDO.content }}</p>
            </div>

            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="t in temas"
                :key="t.titulo"
                @click="enviar(t.pregunta)"
                class="group text-left bg-white border border-gray-200 rounded-xl p-3 shadow-sm hover:border-blue-400 hover:shadow transition-all active:scale-[0.98]"
              >
                <div class="w-8 h-8 rounded-lg bg-blue-600/10 text-blue-600 flex items-center justify-center mb-2 group-hover:bg-blue-600 group-hover:text-white transition-colors">
                  <component :is="t.icono" class="w-4.5 h-4.5" />
                </div>
                <p class="text-sm font-semibold text-gray-800 leading-tight">{{ t.titulo }}</p>
                <p class="text-xs text-gray-500 leading-snug mt-0.5">{{ t.texto }}</p>
              </button>
            </div>

            <button
              @click="abrirSelectorImagen"
              class="w-full flex items-center gap-3 bg-white border border-dashed border-gray-300 rounded-xl p-3 text-left hover:border-blue-400 transition-colors"
            >
              <div class="w-8 h-8 rounded-lg bg-blue-600/10 text-blue-600 flex items-center justify-center flex-shrink-0">
                <PhotoIcon class="w-4.5 h-4.5" />
              </div>
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800 leading-tight">Cotizar un mueble a la medida</p>
                <p class="text-xs text-gray-500 leading-snug">Sube una foto o boceto y te doy el costo de fabricarlo</p>
              </div>
            </button>
          </div>

          <!-- Conversación -->
          <template v-for="(msg, i) in mensajes" :key="i">
            <div
              v-if="i > 0"
              :class="['flex gap-2 msg-entra', msg.role === 'user' ? 'justify-end' : 'justify-start']"
            >
              <!-- Avatar agente -->
              <div
                v-if="msg.role === 'assistant'"
                class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0 mt-0.5"
              >
                <SparklesSolid class="w-4 h-4 text-white" />
              </div>

              <div :class="['min-w-0 flex flex-col', msg.role === 'user' ? 'items-end max-w-[82%]' : 'items-start max-w-[88%]']">
                <!-- Burbuja -->
                <div
                  :class="[
                    'rounded-2xl text-sm leading-relaxed overflow-hidden',
                    msg.role === 'user'
                      ? 'bg-blue-600 text-white rounded-tr-sm'
                      : msg.error
                        ? 'bg-white text-gray-800 rounded-tl-sm border border-red-200 shadow-sm'
                        : 'bg-white text-gray-800 rounded-tl-sm border border-gray-100 shadow-sm',
                  ]"
                >
                  <!-- Foto adjunta -->
                  <img
                    v-if="msg.image"
                    :src="msg.image"
                    class="w-full max-h-48 object-cover cursor-zoom-in"
                    @click="abrirFoto(msg.image)"
                  />
                  <p v-else-if="msg.imgNombre" class="px-3 pt-2 text-xs opacity-80 flex items-center gap-1">
                    <PhotoIcon class="w-3.5 h-3.5" /> {{ msg.imgNombre }}
                  </p>
                  <div class="px-3.5 py-2.5 space-y-0.5 break-words" v-html="formatearTexto(msg.content)" />

                  <!-- Reintentar -->
                  <div v-if="msg.error" class="px-3.5 pb-2.5">
                    <button
                      @click="reintentar(i)"
                      :disabled="cargando"
                      class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 disabled:opacity-50"
                    >
                      <ArrowUturnLeftIcon class="w-3.5 h-3.5" /> Intentar de nuevo
                    </button>
                  </div>
                </div>

                <!-- Hora y acciones -->
                <div class="flex items-center gap-1 mt-1 px-1 text-[11px] text-gray-400">
                  <span v-if="msg.hora">{{ msg.hora }}</span>
                  <template v-if="msg.role === 'assistant' && !msg.error">
                    <button
                      @click="copiar(msg, i)"
                      class="ml-1 inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md hover:bg-gray-200 hover:text-gray-600 transition-colors"
                      title="Copiar"
                    >
                      <component :is="copiado === i ? CheckIcon : ClipboardDocumentIcon" class="w-3.5 h-3.5" />
                      {{ copiado === i ? 'Copiado' : 'Copiar' }}
                    </button>
                    <button
                      v-if="puedeCompartir"
                      @click="compartir(msg)"
                      class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-md hover:bg-gray-200 hover:text-gray-600 transition-colors"
                      title="Compartir"
                    >
                      <ShareIcon class="w-3.5 h-3.5" /> Compartir
                    </button>
                  </template>
                </div>

                <!-- Preguntas de seguimiento (solo en la última respuesta) -->
                <div
                  v-if="msg.sugerencias?.length && i === mensajes.length - 1 && !cargando"
                  class="flex flex-wrap gap-1.5 mt-2"
                >
                  <button
                    v-for="s in msg.sugerencias"
                    :key="s"
                    @click="enviar(s)"
                    class="text-xs font-semibold text-blue-600 bg-white border border-blue-600/30 hover:border-blue-600 px-3 py-1.5 rounded-full transition-colors active:scale-95"
                  >
                    {{ s }}
                  </button>
                </div>
              </div>
            </div>
          </template>

          <!-- Pensando -->
          <div v-if="cargando" class="flex gap-2 justify-start msg-entra">
            <div class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0">
              <SparklesSolid class="w-4 h-4 text-white animate-pulse" />
            </div>
            <div class="bg-white border border-gray-100 shadow-sm px-3.5 py-2.5 rounded-2xl rounded-tl-sm flex items-center gap-2.5">
              <div class="flex gap-1 items-center h-4">
                <span class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0ms" />
                <span class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 150ms" />
                <span class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 300ms" />
              </div>
              <span class="text-xs text-gray-500">{{ fraseCarga }}</span>
            </div>
          </div>
        </div>

        <!-- Input -->
        <div class="px-3 pt-2.5 pb-3 border-t border-gray-200 bg-white flex-shrink-0">

          <!-- Foto adjunta, lista para enviar -->
          <div v-if="imagenPendiente" class="flex items-center gap-2 mb-2 bg-white border border-gray-200 rounded-xl px-2.5 py-2 shadow-sm">
            <img :src="imagenPendiente.base64" class="w-11 h-11 rounded-lg object-cover flex-shrink-0 cursor-zoom-in" @click="abrirFoto(imagenPendiente.base64, { nombre: imagenPendiente.nombre })" />
            <div class="flex-1 min-w-0">
              <p class="text-xs font-semibold text-gray-700 truncate">{{ imagenPendiente.nombre }}</p>
              <p class="text-[11px] text-gray-400">Agrega medidas o detalles si quieres</p>
            </div>
            <button @click="quitarImagen" class="p-1 rounded-md text-gray-400 hover:text-red-500 hover:bg-gray-100 transition-colors flex-shrink-0" title="Quitar foto">
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>

          <div
            class="flex items-end gap-1.5 bg-gray-50 rounded-2xl border px-2 py-1.5 transition-colors focus-within:border-blue-400"
            :class="escuchando ? 'border-red-300' : 'border-gray-200'"
          >
            <!-- Adjuntar foto -->
            <button
              @click="abrirSelectorImagen"
              :disabled="cargando || escuchando"
              class="flex-shrink-0 w-8 h-8 rounded-xl flex items-center justify-center text-gray-400 hover:text-blue-600 hover:bg-gray-200 transition-all disabled:opacity-40"
              title="Adjuntar foto o boceto"
            >
              <PhotoIcon class="w-5 h-5" />
            </button>

            <textarea
              ref="inputRef"
              v-model="inputText"
              @keydown="onKeydown"
              :disabled="cargando"
              :placeholder="escuchando ? 'Escuchando…' : imagenPendiente ? 'Medidas o detalles (opcional)…' : 'Pregúntame lo que quieras…'"
              rows="1"
              class="flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 resize-none outline-none leading-relaxed max-h-28 min-h-[1.5rem] py-1.5"
              style="field-sizing: content; background-color: transparent !important; border: 0 !important;"
            />

            <!-- Micrófono (sin texto) o Enviar (con texto o foto) -->
            <button
              v-if="!inputText.trim() && !imagenPendiente"
              @click="toggleVoz"
              :disabled="cargando"
              :class="[
                'flex-shrink-0 w-8 h-8 rounded-xl flex items-center justify-center transition-all disabled:opacity-40',
                escuchando ? 'bg-red-500 text-white' : 'text-gray-400 hover:text-blue-600 hover:bg-gray-200',
              ]"
              :title="escuchando ? 'Toca para detener' : 'Habla tu pregunta'"
            >
              <MicrophoneIcon class="w-5 h-5" :class="escuchando ? 'animate-pulse' : ''" />
            </button>
            <button
              v-else
              @click="enviar()"
              :disabled="cargando"
              class="flex-shrink-0 w-8 h-8 rounded-xl flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white transition-all active:scale-90 disabled:opacity-50"
              title="Enviar"
            >
              <PaperAirplaneIcon class="w-4 h-4" />
            </button>
          </div>
          <p class="text-[10px] text-gray-400 text-center mt-1.5">
            <span v-if="escuchando" class="text-red-500 font-medium">● Escuchando — toca el micrófono para terminar</span>
            <span v-else class="hidden sm:inline">Enter para enviar · Shift+Enter nueva línea</span>
            <span v-if="!escuchando" class="sm:hidden">Las respuestas salen de los datos del sistema</span>
          </p>

          <!-- Input de archivo oculto -->
          <input
            ref="fileInputRef"
            type="file"
            accept="image/*"
            class="hidden"
            @change="onImagenSeleccionada"
          />
        </div>
      </div>
    </Transition>

    <!-- Backdrop en móvil -->
    <Transition name="fade">
      <div
        v-if="abierto"
        class="fixed inset-0 z-40 bg-black/30 sm:hidden"
        @click="toggle"
      />
    </Transition>

  </Teleport>
</template>

<style scoped>
.chat-slide-enter-active,
.chat-slide-leave-active {
  transition: transform 0.22s ease, opacity 0.22s ease;
}
.chat-slide-enter-from,
.chat-slide-leave-to {
  transform: translateY(16px);
  opacity: 0;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Cada mensaje entra deslizándose un poco, como en una app de chat. */
.msg-entra {
  animation: msg-entra 0.22s ease-out;
}
@keyframes msg-entra {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
  .msg-entra { animation: none; }
}
</style>
