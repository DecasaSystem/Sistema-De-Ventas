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
  // Redondeado y sin decimales: en pesos no hay centavos, y sin esto un valor
  // con decimales —un saldo, un cobro con `step="0.01"`— salía como
  // "$1.234.567,5". Es el componente que pinta casi todo el dinero de la app,
  // así que el redondeo tiene que estar acá y no repetido en cada pantalla.
  return Math.round(Number(n) || 0).toLocaleString('es-CO')
}
</script>

<template>
  <span :class="bold ? 'font-bold' : 'font-medium'">${{ format(amount) }}</span>
</template>
