<script setup>
// "Cifras de las 3:40 p. m. · actualizando…": lo que se ve es la última foto
// que guardó el servidor (getFoto) y lo nuevo todavía se está calculando.
import { computed } from 'vue'

const props = defineProps({ fecha: String })

const texto = computed(() => {
  if (!props.fecha) return ''
  const f = new Date(props.fecha)
  const zona = { timeZone: 'America/Bogota' }
  const dia = (d) => d.toLocaleDateString('es-CO', { ...zona, year: 'numeric', month: '2-digit', day: '2-digit' })
  const hora = f.toLocaleTimeString('es-CO', { ...zona, hour: 'numeric', minute: '2-digit' })
  return dia(f) === dia(new Date())
    ? `de las ${hora}`
    : `del ${f.toLocaleDateString('es-CO', { ...zona, day: 'numeric', month: 'short' })}, ${hora}`
})
</script>

<template>
  <p v-if="fecha" class="text-[11px] text-gray-400 text-center" role="status">
    Cifras {{ texto }} · actualizando…
  </p>
</template>
