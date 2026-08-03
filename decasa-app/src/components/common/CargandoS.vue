<!--
  Indicador de carga global: la "S" de SODEGE que se dibuja y se borra en bucle.

  Reemplaza a la barra azul de antes. Va fija arriba y al centro, sobre una
  pastilla blanca para que se distinga igual de bien sobre una cabecera de
  color que sobre el fondo gris de las listas.
-->
<template>
  <div class="fixed top-1.5 left-1/2 -translate-x-1/2 z-[200] pointer-events-none">
    <div class="bg-white/95 rounded-full shadow-md ring-1 ring-black/5 p-1.5">
      <svg class="w-5 h-5 text-blue-600" viewBox="0 0 24 24" fill="none" aria-label="Cargando" role="status">
        <!-- Rastro tenue: deja ver la forma completa de la S mientras se dibuja -->
        <path
          d="M17 6.2C17 3.6 7 3.6 7 8.1c0 4 10 3.6 10 7.8 0 4.5-10 4.5-10 1.9"
          stroke="currentColor"
          stroke-width="2.4"
          stroke-linecap="round"
          class="opacity-15"
        />
        <path
          d="M17 6.2C17 3.6 7 3.6 7 8.1c0 4 10 3.6 10 7.8 0 4.5-10 4.5-10 1.9"
          stroke="currentColor"
          stroke-width="2.4"
          stroke-linecap="round"
          class="trazo-s"
        />
      </svg>
    </div>
  </div>
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

/* Respeta a quien pidió menos animación: se queda la S completa, quieta. */
@media (prefers-reduced-motion: reduce) {
  .trazo-s {
    animation: none;
    stroke-dashoffset: 0;
  }
}
</style>
