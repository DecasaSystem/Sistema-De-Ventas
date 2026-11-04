<script setup>
/**
 * "Hay una versión nueva": avisa a quien tiene la app abierta que se subió
 * algo y tiene que recargar para verlo.
 *
 * El 2026-10-29 una persona bajó el Excel de Listas y le salió el de octubre:
 * tenía la app abierta desde antes de subirlo (en el celular queda abierta
 * días) y su versión todavía no conocía ese libro. Nada le decía que recargara.
 *
 * Cada compilación trae su versión (__VERSION_APP__, vite.config.js) y publica
 * /version.json. Se pregunta al volver a la app y cada 5 minutos; si la de
 * afuera es otra, sale el aviso. No se recarga solo: podría estar a mitad de
 * una orden o de un pago. "Después" lo esconde hasta que salga otra versión.
 */
import { ref, onMounted, onUnmounted } from 'vue'
import { ArrowPathIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const CADA_MS = 5 * 60 * 1000
const MINIMO_ENTRE_PREGUNTAS_MS = 60 * 1000

const nueva = ref(null)       // versión publicada distinta a la que corre
const descartada = ref(null)  // la que se dejó para después
let ultimaPregunta = 0
let reloj = null

async function revisar() {
  if (import.meta.env.DEV) return
  if (Date.now() - ultimaPregunta < MINIMO_ENTRE_PREGUNTAS_MS) return
  ultimaPregunta = Date.now()
  try {
    const res = await fetch(`/version.json?t=${Date.now()}`, { cache: 'no-store' })
    if (!res.ok) return
    const { version } = await res.json()
    if (version && version !== __VERSION_APP__) nueva.value = version
  } catch {
    // Sin conexión: se vuelve a preguntar después.
  }
}

function alVolver() {
  if (document.visibilityState === 'visible') revisar()
}

function recargar() {
  window.location.reload()
}

onMounted(() => {
  reloj = setInterval(revisar, CADA_MS)
  document.addEventListener('visibilitychange', alVolver)
  window.addEventListener('focus', alVolver)
})

onUnmounted(() => {
  clearInterval(reloj)
  document.removeEventListener('visibilitychange', alVolver)
  window.removeEventListener('focus', alVolver)
})
</script>

<template>
  <Transition name="slide-up">
    <div
      v-if="nueva && nueva !== descartada"
      class="fixed top-16 inset-x-4 z-40 sm:max-w-md sm:mx-auto bg-blue-600 text-white rounded-xl shadow-xl px-4 py-3 flex items-center gap-3"
      role="status"
    >
      <ArrowPathIcon class="w-6 h-6 flex-shrink-0" />
      <p class="flex-1 text-sm font-medium leading-snug">
        Hay una versión nueva del sistema. Recarga para verla.
      </p>
      <button
        @click="recargar"
        class="bg-white text-blue-600 text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-blue-50 transition-colors"
      >
        Recargar
      </button>
      <button
        @click="descartada = nueva"
        class="flex-shrink-0 opacity-70 hover:opacity-100 transition-opacity"
        title="Después"
        aria-label="Después"
      >
        <XMarkIcon class="w-5 h-5" />
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active { transition: transform 0.25s ease, opacity 0.25s ease; }
.slide-up-enter-from,
.slide-up-leave-to { transform: translateY(-12px); opacity: 0; }
</style>
