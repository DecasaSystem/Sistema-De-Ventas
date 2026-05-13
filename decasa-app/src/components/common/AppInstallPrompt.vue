<script setup>
import { usePwaInstall } from '@/composables/usePwaInstall'
import { XMarkIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'

const { canInstall, instalar, descartar } = usePwaInstall()
</script>

<template>
  <Transition name="slide-up">
    <div
      v-if="canInstall"
      class="fixed bottom-24 inset-x-4 z-30 bg-blue-600 text-white rounded-xl shadow-xl px-4 py-3 flex items-center gap-3"
    >
      <ArrowDownTrayIcon class="w-6 h-6 flex-shrink-0" />
      <p class="flex-1 text-sm font-medium leading-snug">
        Instala Decasa en tu pantalla de inicio
      </p>
      <button
        @click="instalar"
        class="bg-white text-blue-600 text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-blue-50 transition-colors"
      >
        Instalar
      </button>
      <button @click="descartar" class="flex-shrink-0 opacity-70 hover:opacity-100 transition-opacity">
        <XMarkIcon class="w-5 h-5" />
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.25s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(16px);
  opacity: 0;
}
</style>
