<script setup>
import { ref, nextTick, onMounted, onUnmounted } from 'vue'
import { chatWithAgent } from '@/api/agent.js'
import {
  SparklesIcon,
  XMarkIcon,
  PaperAirplaneIcon,
  ArrowPathIcon,
  PhotoIcon,
} from '@heroicons/vue/24/outline'
import { SparklesIcon as SparklesSolid } from '@heroicons/vue/24/solid'

const abierto   = ref(false)
const cargando  = ref(false)
const inputText = ref('')
const imagenAmpliada = ref(null)   // src de la imagen en lightbox
const mensajes  = ref([
  {
    role: 'assistant',
    content: '¡Hola! Soy el asistente de Decasa. Puedo ayudarte con costos de fabricación, inventario, ventas, producción y órdenes.\n\nTambién puedes **adjuntar una foto o boceto** de un mueble y te estimo el costo de fabricación. ¿Qué necesitas consultar?',
  },
])
const inputRef    = ref(null)
const listRef     = ref(null)
const fileInputRef = ref(null)

// imagen adjunta pendiente de enviar
const imagenPendiente = ref(null)   // { base64: string, nombre: string }

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
    nextTick(() => inputRef.value?.focus())
    setTimeout(scrollAbajo, 240) // esperar que termine la transición de apertura
  }
}

async function enviar() {
  const texto  = inputText.value.trim()
  const imagen = imagenPendiente.value

  if ((!texto && !imagen) || cargando.value) return

  const textoFinal = texto || '¿Cuánto cuesta fabricar este mueble?'

  // Guardar en historial local (con preview de imagen si hay)
  mensajes.value.push({
    role    : 'user',
    content : textoFinal,
    image   : imagen?.base64 ?? null,
    imgNombre: imagen?.nombre ?? null,
  })

  inputText.value       = ''
  imagenPendiente.value = null
  cargando.value        = true
  await scrollAbajo()

  try {
    // Enviar solo los últimos 10 turnos (sin el mensaje inicial del asistente)
    const historial = mensajes.value
      .slice(1)
      .slice(-10)
      .map(m => {
        const entry = { role: m.role, content: m.content }
        if (m.image) entry.image = m.image
        return entry
      })

    const { data } = await chatWithAgent(historial)
    mensajes.value.push({ role: 'assistant', content: data.respuesta })
  } catch (e) {
    mensajes.value.push({
      role: 'assistant',
      content: 'Ocurrió un error al consultar. Por favor intenta de nuevo.',
    })
  } finally {
    cargando.value = false
    await scrollAbajo()
  }
}

function onKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    enviar()
  }
  if (e.key === 'Escape') {
    imagenAmpliada.value = null
  }
}

function onEscGlobal(e) {
  if (e.key === 'Escape') imagenAmpliada.value = null
}
onMounted(() => window.addEventListener('keydown', onEscGlobal))
onUnmounted(() => window.removeEventListener('keydown', onEscGlobal))


function limpiar() {
  mensajes.value = [mensajes.value[0]] // conservar saludo inicial
}

async function scrollAbajo() {
  await nextTick()
  if (listRef.value) {
    listRef.value.scrollTop = listRef.value.scrollHeight
  }
}

// Ejemplos de preguntas rápidas
const ejemplos = [
  '¿Cuánto cuesta fabricar un comedor de 6 puestos?',
  '¿Cuál es el producto más vendido este mes?',
  '¿Qué hay en inventario bajo de stock?',
  '¿Cuántos items hay en producción?',
]

function usarEjemplo(texto) {
  inputText.value = texto
  nextTick(() => inputRef.value?.focus())
}

// Renderizar texto con saltos de línea y negritas básicas
function formatearTexto(texto) {
  return texto
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/`(.+?)`/g, '<code class="bg-gray-100 px-1 rounded text-xs">$1</code>')
    .replace(/\n/g, '<br>')
}
</script>

<template>
  <Teleport to="body">
    <!-- Botón flotante -->
    <button
      v-if="!abierto"
      @click="toggle"
      class="fixed bottom-40 right-4 z-50 w-13 h-13 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-105 active:scale-95"
      title="Asistente Decasa"
    >
      <SparklesSolid class="w-6 h-6" />
    </button>

    <!-- Panel de chat -->
    <Transition name="chat-slide">
      <div
        v-if="abierto"
        class="fixed bottom-0 right-0 z-50 w-full sm:w-96 sm:bottom-4 sm:right-4 flex flex-col bg-white sm:rounded-2xl shadow-2xl border border-gray-200 overflow-hidden"
        style="max-height: min(85dvh, 600px); height: 85dvh;"
      >
        <!-- Header -->
        <div class="flex items-center gap-2.5 px-4 py-3 bg-blue-600 text-white flex-shrink-0">
          <SparklesSolid class="w-5 h-5 flex-shrink-0" />
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-sm leading-tight">Asistente Decasa</p>
            <p class="text-blue-200 text-xs leading-tight">Consultas inteligentes</p>
          </div>
          <button
            @click="limpiar"
            class="p-1.5 rounded-lg hover:bg-blue-500 transition-colors"
            title="Nueva conversación"
          >
            <ArrowPathIcon class="w-4 h-4" />
          </button>
          <button
            @click="toggle"
            class="p-1.5 rounded-lg hover:bg-blue-500 transition-colors"
            title="Cerrar"
          >
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <!-- Mensajes -->
        <div
          ref="listRef"
          class="flex-1 overflow-y-auto px-3 py-3 space-y-3 scroll-smooth"
        >
          <div
            v-for="(msg, i) in mensajes"
            :key="i"
            :class="['flex gap-2', msg.role === 'user' ? 'justify-end' : 'justify-start']"
          >
            <!-- Avatar agente -->
            <div
              v-if="msg.role === 'assistant'"
              class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0 mt-0.5"
            >
              <SparklesIcon class="w-4 h-4 text-blue-600" />
            </div>

            <!-- Burbuja -->
            <div
              :class="[
                'max-w-[80%] rounded-2xl text-sm leading-relaxed overflow-hidden',
                msg.role === 'user'
                  ? 'bg-blue-600 text-white rounded-tr-sm'
                  : 'bg-gray-100 text-gray-800 rounded-tl-sm',
              ]"
            >
              <!-- Thumbnail de imagen adjunta -->
              <img
                v-if="msg.image"
                :src="msg.image"
                class="w-full max-h-40 object-cover cursor-zoom-in"
                @click="imagenAmpliada = msg.image"
              />
              <div class="px-3 py-2" v-html="formatearTexto(msg.content)" />
            </div>
          </div>

          <!-- Indicador de escritura -->
          <div v-if="cargando" class="flex gap-2 justify-start">
            <div class="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
              <SparklesIcon class="w-4 h-4 text-blue-600" />
            </div>
            <div class="bg-gray-100 px-4 py-3 rounded-2xl rounded-tl-sm">
              <div class="flex gap-1 items-center h-4">
                <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms" />
                <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms" />
                <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms" />
              </div>
            </div>
          </div>

          <!-- Sugerencias (solo al inicio) -->
          <div v-if="mensajes.length === 1 && !cargando" class="pt-1">
            <p class="text-xs text-gray-400 mb-2 px-1">Preguntas frecuentes:</p>
            <div class="flex flex-col gap-1.5">
              <button
                v-for="ej in ejemplos"
                :key="ej"
                @click="usarEjemplo(ej)"
                class="text-left text-xs text-blue-600 bg-blue-50 hover:bg-blue-100 px-3 py-2 rounded-xl transition-colors border border-blue-100"
              >
                {{ ej }}
              </button>
            </div>
          </div>
        </div>

        <!-- Input -->
        <div class="px-3 py-3 border-t border-gray-100 flex-shrink-0">

          <!-- Preview imagen adjunta -->
          <div v-if="imagenPendiente" class="flex items-center gap-2 mb-2 bg-blue-50 border border-blue-100 rounded-xl px-3 py-2">
            <img :src="imagenPendiente.base64" class="w-10 h-10 rounded-lg object-cover flex-shrink-0 cursor-zoom-in" @click="imagenAmpliada = imagenPendiente.base64" />
            <span class="flex-1 text-xs text-gray-600 truncate">{{ imagenPendiente.nombre }}</span>
            <button @click="quitarImagen" class="text-gray-400 hover:text-red-500 transition-colors flex-shrink-0">
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>

          <div class="flex items-end gap-2 bg-gray-50 rounded-xl border border-gray-200 px-3 py-2">
            <!-- Botón adjuntar imagen -->
            <button
              @click="abrirSelectorImagen"
              :disabled="cargando"
              class="flex-shrink-0 w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-all mb-0.5"
              title="Adjuntar foto o boceto"
            >
              <PhotoIcon class="w-4 h-4" />
            </button>

            <textarea
              ref="inputRef"
              v-model="inputText"
              @keydown="onKeydown"
              :disabled="cargando"
              :placeholder="imagenPendiente ? 'Añade una descripción o medidas (opcional)...' : 'Escribe tu pregunta...'"
              rows="1"
              class="flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 resize-none outline-none leading-relaxed max-h-28 min-h-[1.5rem]"
              style="field-sizing: content;"
            />
            <button
              @click="enviar"
              :disabled="(!inputText.trim() && !imagenPendiente) || cargando"
              :class="[
                'flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center transition-all',
                (inputText.trim() || imagenPendiente) && !cargando
                  ? 'bg-blue-600 hover:bg-blue-700 text-white'
                  : 'bg-gray-200 text-gray-400 cursor-not-allowed',
              ]"
            >
              <PaperAirplaneIcon class="w-4 h-4" />
            </button>
          </div>
          <p class="text-[10px] text-gray-400 text-center mt-1.5">Enter para enviar · Shift+Enter nueva línea</p>

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

    <!-- Lightbox -->
    <Transition name="fade">
      <div
        v-if="imagenAmpliada"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4"
        @click="imagenAmpliada = null"
      >
        <img
          :src="imagenAmpliada"
          class="max-w-full max-h-full rounded-xl shadow-2xl object-contain"
          @click.stop
        />
        <button
          class="absolute top-4 right-4 w-9 h-9 rounded-full bg-white/20 hover:bg-white/40 text-white flex items-center justify-center transition-colors"
          @click="imagenAmpliada = null"
        >
          <XMarkIcon class="w-5 h-5" />
        </button>
      </div>
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
</style>
