<script setup>
defineProps({
  amount: {
    type: Number,
    required: true,
  },
  bold: {
    type: Boolean,
    default: false,
  },
})

function format(n) {
  return Number(n).toLocaleString('es-CO')
}
</script>

<template>
  <span :class="bold ? 'font-bold' : 'font-medium'">${{ format(amount) }}</span>
</template>
