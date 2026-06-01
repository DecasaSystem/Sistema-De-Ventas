<script setup>
defineProps({
  amount: {
    type: [Number, String],
    default: 0,
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
