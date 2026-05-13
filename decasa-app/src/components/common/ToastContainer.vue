<script setup>
import { useToast } from '@/composables/useToast'
import { CheckCircleIcon, XCircleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/vue/24/solid'

const { items, dismiss } = useToast()

const config = {
  success: { cls: 'bg-green-500', Icon: CheckCircleIcon },
  error:   { cls: 'bg-red-500',   Icon: XCircleIcon },
  info:    { cls: 'bg-blue-500',  Icon: InformationCircleIcon },
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed bottom-20 left-4 right-4 sm:left-auto sm:right-4 sm:w-80 z-[200] flex flex-col gap-2 pointer-events-none">
      <TransitionGroup name="toast">
        <div
          v-for="t in items"
          :key="t.id"
          :class="['flex items-start gap-3 px-4 py-3 rounded-xl shadow-xl text-white pointer-events-auto', config[t.type]?.cls ?? 'bg-gray-800']"
        >
          <component :is="config[t.type]?.Icon" class="w-5 h-5 flex-shrink-0 mt-0.5" />
          <span class="flex-1 text-sm font-medium leading-snug">{{ t.msg }}</span>
          <button @click="dismiss(t.id)" class="flex-shrink-0 opacity-70 hover:opacity-100 transition-opacity -mr-1">
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-move,
.toast-enter-active {
  transition: all 0.25s ease;
}
.toast-leave-active {
  transition: all 0.2s ease;
  position: absolute;
  width: 100%;
}
.toast-enter-from {
  opacity: 0;
  transform: translateY(12px);
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(100%);
}
</style>
