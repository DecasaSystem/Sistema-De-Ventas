<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { ChevronUpIcon } from '@heroicons/vue/24/outline'

const visible = ref(false)

function onScroll() {
  visible.value = window.scrollY > 300
}

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }))
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))
</script>

<template>
  <Transition name="fade">
    <button
      v-if="visible"
      @click="scrollToTop"
      class="fixed bottom-20 right-4 z-20 w-10 h-10 bg-white/90 border border-gray-200 rounded-full shadow-sm flex items-center justify-center text-gray-500 hover:text-blue-600 hover:border-blue-300 transition-colors"
      title="Volver arriba"
    >
      <ChevronUpIcon class="w-5 h-5" />
    </button>
  </Transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
