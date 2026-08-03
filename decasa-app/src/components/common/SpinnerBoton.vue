<!--
  Spinner en línea para dentro de un botón mientras se espera al backend.

  AppSpinner es un bloque con py-12 pensado para pantallas completas; esto es
  solo el aro, hereda el color del texto del botón y el tamaño se ajusta con
  clases (`class="w-4 h-4"`).
-->
<template>
  <svg class="animate-spin flex-shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
  </svg>
</template>
