<script setup>
// Una gráfica de Chart.js que se rehace sola cuando cambian los datos o el
// modo oscuro (la clase `dark` de <html>): con colores fijos, los textos se
// perdían sobre el fondo oscuro. `armar` devuelve la configuración completa y
// recibe los colores del tema del momento.
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { Chart } from 'chart.js/auto'
import { coloresGrafica } from '@/utils/finanzas'

const props = defineProps({
  armar: { type: Function, required: true },   // (colores) => config de Chart.js
  datos: { default: null },                     // lo que se vigila para rehacerla
  alto: { type: String, default: 'h-56' },
  descripcion: { type: String, default: '' },  // texto para lectores de pantalla
})

const canvas = ref(null)
let grafica = null
let observador = null

function dibujar() {
  if (grafica) { grafica.destroy(); grafica = null }
  if (!canvas.value) return
  const c = coloresGrafica()
  const config = props.armar(c)
  if (!config) return
  Chart.defaults.color = c.texto
  Chart.defaults.font.family = 'inherit'
  config.options = {
    responsive: true,
    maintainAspectRatio: false,
    ...(config.options ?? {}),
  }
  grafica = new Chart(canvas.value, config)
}

onMounted(() => {
  dibujar()
  observador = new MutationObserver(dibujar)
  observador.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
})
onBeforeUnmount(() => {
  observador?.disconnect()
  grafica?.destroy()
})
watch(() => props.datos, dibujar, { deep: true })
</script>

<template>
  <div :class="['relative w-full', alto]">
    <canvas ref="canvas" role="img" :aria-label="descripcion" />
  </div>
</template>
