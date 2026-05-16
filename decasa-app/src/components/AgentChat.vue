<script setup>
import { ref, nextTick, computed } from 'vue'
import { chatWithAgent } from '@/api/agent.js'
import {
  SparklesIcon,
  XMarkIcon,
  PaperAirplaneIcon,
  ArrowPathIcon,
} from '@heroicons/vue/24/outline'
import { SparklesIcon as SparklesSolid } from '@heroicons/vue/24/solid'

const abierto   = ref(false)
const cargando  = ref(false)
const inputText = ref('')
const mensajes  = ref([
  {
    role: 'assistant',
    content: '¡Hola! Soy el asistente de Decasa. Puedo ayudarte con costos de fabricación, inventario, ventas, producción y órdenes.\n\n¿Qué necesitas consultar?',
  },
])
const inputRef  = ref(null)
const listRef   = ref(null)

function toggle() {
  abierto.value = !abierto.value
  if (abierto.value) {
    nextTick(() => inputRef.value?.focus())
  }
}

async function enviar() {
  const texto = inputText.value.trim()
  if (!texto || cargando.value) return

  mensajes.value.push({ role: 'user', content: texto })
  inputText.value = ''
  cargando.value  = true
  await scrollAbajo()

  try {
    // Enviar solo los últimos 10 turnos (sin el mensaje inicial del asistente)
    const historial = mensajes.value
      .slice(1)             // quitar saludo inicial
      .slice(-10)           // máx. 10 mensajes
      .map(m => ({ role: m.role, content: m.content }))

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
}

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
  '¿Cuál es el producto más vendido este mes?',
  '¿Cuánto cuesta fabricar un comedor de 6 puestos?',
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
                'max-w-[80%] px-3 py-2 rounded-2xl text-sm leading-relaxed',
                msg.role === 'user'
                  ? 'bg-blue-600 text-white rounded-tr-sm'
                  : 'bg-gray-100 text-gray-800 rounded-tl-sm',
              ]"
              v-html="formatearTexto(msg.content)"
            />
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
          <div class="flex items-end gap-2 bg-gray-50 rounded-xl border border-gray-200 px-3 py-2">
            <textarea
              ref="inputRef"
              v-model="inputText"
              @keydown="onKeydown"
              :disabled="cargando"
              placeholder="Escribe tu pregunta..."
              rows="1"
              class="flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 resize-none outline-none leading-relaxed max-h-28 min-h-[1.5rem]"
              style="field-sizing: content;"
            />
            <button
              @click="enviar"
              :disabled="!inputText.trim() || cargando"
              :class="[
                'flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center transition-all',
                inputText.trim() && !cargando
                  ? 'bg-blue-600 hover:bg-blue-700 text-white'
                  : 'bg-gray-200 text-gray-400 cursor-not-allowed',
              ]"
            >
              <PaperAirplaneIcon class="w-4 h-4" />
            </button>
          </div>
          <p class="text-[10px] text-gray-400 text-center mt-1.5">Enter para enviar · Shift+Enter nueva línea</p>
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
</style>
