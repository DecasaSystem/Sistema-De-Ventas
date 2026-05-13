<script setup>
import { InboxIcon } from '@heroicons/vue/24/outline'

defineProps({
  message: {
    type: String,
    default: 'No hay datos disponibles.',
  },
})
</script>

<template>
  <div class="flex flex-col items-center justify-center py-12 text-gray-400">
    <InboxIcon class="w-16 h-16 mb-3" />
    <p class="text-sm">{{ message }}</p>
  </div>
</template>
