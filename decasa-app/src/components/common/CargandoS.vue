<!--
  Indicador global: aparece en el centro cuando hay una petición en vuelo y la
  pantalla no está mostrando ya su propia S.

  Sirve para las acciones que no tienen carga propia — tocar un botón que
  guarda, borra o asigna — donde antes no pasaba nada visible y la gente volvía
  a tocar. No bloquea: no lleva fondo oscuro y deja pasar los clics.
-->
<script setup>
import IconoS from '@/components/common/IconoS.vue'
</script>

<template>
  <div class="fixed inset-0 z-[200] flex items-center justify-center pointer-events-none">
    <div class="bg-white/95 rounded-2xl shadow-lg ring-1 ring-black/5 p-3">
      <!-- registra-carga en false: si se registrara, se silenciaría a sí mismo -->
      <IconoS :registra-carga="false" class="w-9 h-9 text-blue-600" />
    </div>
  </div>
</template>
