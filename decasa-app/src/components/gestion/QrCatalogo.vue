<script setup>
/**
 * El QR de un enlace de catálogos, para imprimir.
 *
 * El de la portada (/c) es permanente: la portada se arma sola con los
 * catálogos activos, así que el mismo QR sirve aunque se agreguen, quiten o
 * cambien catálogos. El de un catálogo suelto deja de servir si se le cambia
 * el slug o se oculta.
 *
 * Se genera en el teléfono, sin pedirle nada al servidor. La descarga sale
 * en alta resolución con el nombre debajo, lista para mandar a imprimir.
 */
import { ref, watch, onMounted } from 'vue'
import QRCode from 'qrcode'
import { XMarkIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  url:    { type: String, required: true },
  titulo: { type: String, default: 'Catálogos Decasa' },
  nota:   { type: String, default: '' },
})
defineEmits(['cerrar'])

const vista = ref(null)
const error = ref('')

// Nivel M: aguanta que se manche o se doble un poco el impreso y sigue siendo
// fácil de leer con cualquier cámara.
const OPCIONES = { errorCorrectionLevel: 'M', margin: 2, color: { dark: '#111827', light: '#ffffff' } }

async function pintar() {
  if (!vista.value) return
  try {
    await QRCode.toCanvas(vista.value, props.url, { ...OPCIONES, width: 240 })
    error.value = ''
  } catch {
    error.value = 'No se pudo generar el QR.'
  }
}
onMounted(pintar)
watch(() => props.url, pintar)

/** PNG grande (1200 px de ancho) con el título y la dirección debajo. */
async function descargar() {
  const ancho = 1200
  const lado  = 1000
  const lienzo = document.createElement('canvas')
  lienzo.width  = ancho
  lienzo.height = 1400
  const ctx = lienzo.getContext('2d')
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, lienzo.width, lienzo.height)

  const qr = document.createElement('canvas')
  await QRCode.toCanvas(qr, props.url, { ...OPCIONES, width: lado })
  ctx.drawImage(qr, (ancho - lado) / 2, 60, lado, lado)

  ctx.fillStyle = '#111827'
  ctx.textAlign = 'center'
  ctx.font = 'bold 64px system-ui, -apple-system, Segoe UI, Arial, sans-serif'
  ctx.fillText(props.titulo, ancho / 2, 1150)
  ctx.fillStyle = '#4b5563'
  ctx.font = '40px system-ui, -apple-system, Segoe UI, Arial, sans-serif'
  ctx.fillText('Escanea para ver nuestros catálogos', ancho / 2, 1220)
  ctx.font = '30px system-ui, -apple-system, Segoe UI, Arial, sans-serif'
  ctx.fillText(props.url.replace(/^https?:\/\//, ''), ancho / 2, 1290)

  lienzo.toBlob((blob) => {
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `qr-${props.titulo.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-')}.png`
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }, 'image/png')
}
</script>

<template>
  <div class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="$emit('cerrar')">
    <div class="absolute inset-0 bg-black/50" @click="$emit('cerrar')" />
    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h3 class="text-base font-bold text-gray-800">QR — {{ titulo }}</h3>
          <p class="text-xs text-gray-500 break-all">{{ url }}</p>
        </div>
        <button type="button" @click="$emit('cerrar')" class="text-gray-400 hover:text-gray-600" aria-label="Cerrar">
          <XMarkIcon class="w-5 h-5" />
        </button>
      </div>

      <div class="flex justify-center">
        <canvas ref="vista" class="rounded-lg border border-gray-100" />
      </div>
      <p v-if="error" class="text-xs text-red-600 text-center">{{ error }}</p>
      <p v-if="nota" class="text-xs text-gray-500 text-center">{{ nota }}</p>

      <button
        type="button"
        @click="descargar"
        class="w-full bg-blue-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-blue-700 flex items-center justify-center gap-2"
      >
        <ArrowDownTrayIcon class="w-4 h-4" /> Descargar para imprimir (PNG)
      </button>
    </div>
  </div>
</template>
