<!--
  La "S" de SODEGE que se dibuja y se borra en bucle. Es el único indicador de
  carga del sistema: reemplaza a los círculos que giraban.

  El tamaño y el color se dan por clases, como con un icono:
      <IconoS class="w-8 h-8 text-blue-500" />

  Mientras haya una S en pantalla, el indicador global se calla — así nunca se
  ven dos a la vez. El propio indicador global pasa `:registra-carga="false"`
  para no silenciarse a sí mismo.
-->
<script setup>
import { onMounted, onUnmounted } from 'vue'
import { registrarCargaLocal, quitarCargaLocal } from '@/composables/useCargaGlobal'

const props = defineProps({
  registraCarga: { type: Boolean, default: true },
})

if (props.registraCarga) {
  onMounted(registrarCargaLocal)
  onUnmounted(quitarCargaLocal)
}
</script>

<template>
  <svg viewBox="0 0 24 24" fill="none" role="status" aria-label="Cargando">
    <!-- Rastro tenue: deja ver la forma completa mientras el trazo se dibuja -->
    <path
      d="M17 6.2C17 3.6 7 3.6 7 8.1c0 4 10 3.6 10 7.8 0 4.5-10 4.5-10 1.9"
      stroke="currentColor"
      stroke-width="2.4"
      stroke-linecap="round"
      class="opacity-20"
    />
    <path
      d="M17 6.2C17 3.6 7 3.6 7 8.1c0 4 10 3.6 10 7.8 0 4.5-10 4.5-10 1.9"
      stroke="currentColor"
      stroke-width="2.4"
      stroke-linecap="round"
      class="trazo-s"
    />
  </svg>
</template>

<style scoped>
/* 38.5 es el largo real de la curva, medido sobre el path. Si se retoca la
   forma de la S hay que volver a medirlo, o el trazo se corta antes de tiempo.
   (Se evita `pathLength` porque no todos los renderizadores lo respetan.) */
.trazo-s {
  stroke-dasharray: 38.5;
  animation: dibujar-s 1.4s ease-in-out infinite;
}

@keyframes dibujar-s {
  0%   { stroke-dashoffset: 38.5; }
  50%  { stroke-dashoffset: 0; }
  100% { stroke-dashoffset: -38.5; }
}

/* Respeta a quien pidió menos animación: la S se queda completa y quieta. */
@media (prefers-reduced-motion: reduce) {
  .trazo-s {
    animation: none;
    stroke-dashoffset: 0;
  }
}
</style>
