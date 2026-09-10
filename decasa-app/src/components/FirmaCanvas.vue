<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { PencilIcon, PaperClipIcon, ArrowsPointingOutIcon, CheckIcon } from '@heroicons/vue/24/outline'

defineProps({ modelValue: { default: null } })
const emit = defineEmits(['update:modelValue'])

const canvasRef       = ref(null)
const canvasExpandido = ref(null)
const dibujando       = ref(false)
const dibujandoExp    = ref(false)
const hayFirma        = ref(false)
const hayFirmaExp     = ref(false)
const modoUpload      = ref(false)
const modoExpandido   = ref(false)
const archivoRef      = ref(null)
const previewUrl      = ref('')

let ctx    = null
let ctxExp = null
let ratio  = 1
let lastX = 0, lastY = 0
let lastXExp = 0, lastYExp = 0

onMounted(initCanvas)

function initCanvas() {
  const canvas = canvasRef.value
  if (!canvas) return
  ratio = window.devicePixelRatio || 1
  const w = canvas.offsetWidth
  const h = canvas.offsetHeight
  canvas.width  = w * ratio
  canvas.height = h * ratio
  ctx = canvas.getContext('2d')
  ctx.scale(ratio, ratio)
  ctx.fillStyle   = '#ffffff'
  ctx.fillRect(0, 0, w, h)
  ctx.strokeStyle = '#1e293b'
  ctx.lineWidth   = 2.5
  ctx.lineCap     = 'round'
  ctx.lineJoin    = 'round'
}

function initCanvasExpandido() {
  const canvas = canvasExpandido.value
  if (!canvas) return
  const w = canvas.offsetWidth
  const h = canvas.offsetHeight
  canvas.width  = w * ratio
  canvas.height = h * ratio
  ctxExp = canvas.getContext('2d')
  ctxExp.scale(ratio, ratio)
  ctxExp.fillStyle   = '#ffffff'
  ctxExp.fillRect(0, 0, w, h)
  ctxExp.strokeStyle = '#1e293b'
  ctxExp.lineWidth   = 3
  ctxExp.lineCap     = 'round'
  ctxExp.lineJoin    = 'round'

  // Copiar firma existente del canvas pequeño al expandido, conservando su
  // proporción (sin estirarla al nuevo tamaño de pantalla)
  if (hayFirma.value && canvasRef.value) {
    dibujarProporcional(ctxExp, canvasRef.value, w, h)
    hayFirmaExp.value = true
  }
}

function getPos(e, canvas) {
  const rect = canvas.getBoundingClientRect()
  const src  = e.touches ? e.touches[0] : e
  return { x: src.clientX - rect.left, y: src.clientY - rect.top }
}

// Canvas pequeño — segmentos independientes para que levantar el dedo no rompa el trazo
function startDraw(e) {
  e.preventDefault()
  dibujando.value = true
  const { x, y } = getPos(e, canvasRef.value)
  lastX = x; lastY = y
  // Punto para taps sin movimiento
  ctx.beginPath()
  ctx.arc(x, y, ctx.lineWidth / 2, 0, Math.PI * 2)
  ctx.fillStyle = '#1e293b'
  ctx.fill()
  hayFirma.value = true
}
function draw(e) {
  e.preventDefault()
  if (!dibujando.value) return
  const { x, y } = getPos(e, canvasRef.value)
  ctx.beginPath()
  ctx.moveTo(lastX, lastY)
  ctx.lineTo(x, y)
  ctx.stroke()
  lastX = x; lastY = y
  hayFirma.value = true
}
function endDraw() {
  if (!dibujando.value) return
  dibujando.value = false
  emitBlob(canvasRef.value)
}

// Canvas expandido
function startDrawExp(e) {
  e.preventDefault()
  dibujandoExp.value = true
  const { x, y } = getPos(e, canvasExpandido.value)
  lastXExp = x; lastYExp = y
  ctxExp.beginPath()
  ctxExp.arc(x, y, ctxExp.lineWidth / 2, 0, Math.PI * 2)
  ctxExp.fillStyle = '#1e293b'
  ctxExp.fill()
  hayFirmaExp.value = true
}
function drawExp(e) {
  e.preventDefault()
  if (!dibujandoExp.value) return
  const { x, y } = getPos(e, canvasExpandido.value)
  ctxExp.beginPath()
  ctxExp.moveTo(lastXExp, lastYExp)
  ctxExp.lineTo(x, y)
  ctxExp.stroke()
  lastXExp = x; lastYExp = y
  hayFirmaExp.value = true
}
function endDrawExp() {
  dibujandoExp.value = false
}

function limpiar() {
  const canvas = canvasRef.value
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, canvas.offsetWidth, canvas.offsetHeight)
  ctx.strokeStyle = '#1e293b'
  hayFirma.value = false
  emit('update:modelValue', null)
}

function limpiarExpandido() {
  const canvas = canvasExpandido.value
  ctxExp.fillStyle = '#ffffff'
  ctxExp.fillRect(0, 0, canvas.offsetWidth, canvas.offsetHeight)
  ctxExp.strokeStyle = '#1e293b'
  hayFirmaExp.value = false
}

function confirmarExpandido() {
  const expCanvas = canvasExpandido.value
  const peqCanvas = canvasRef.value
  // Vista previa en el canvas pequeño: se dibuja conservando la proporción del
  // canvas grande (letterbox), solo para que se vea en el formulario.
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, peqCanvas.offsetWidth, peqCanvas.offsetHeight)
  dibujarProporcional(ctx, expCanvas, peqCanvas.offsetWidth, peqCanvas.offsetHeight)
  hayFirma.value = hayFirmaExp.value
  // La firma que se guarda sale del canvas GRANDE a resolución completa (no del
  // preview pequeño), así no se comprime ni se deforma en el PDF.
  if (hayFirmaExp.value) emitBlob(expCanvas)
  modoExpandido.value = false
}

async function abrirExpandido() {
  modoExpandido.value = true
  await nextTick()
  initCanvasExpandido()
}

// Dibuja `src` dentro del contexto destino conservando su proporción (sin
// deformar), centrado en un área de destW×destH px CSS.
function dibujarProporcional(destCtx, src, destW, destH) {
  const escala = Math.min(destW / src.width, destH / src.height)
  const dw = src.width  * escala
  const dh = src.height * escala
  destCtx.drawImage(
    src, 0, 0, src.width, src.height,
    (destW - dw) / 2, (destH - dh) / 2, dw, dh,
  )
}

// Recorta el canvas a la caja que realmente contiene la firma, conservando su
// proporción real. Así el PDF la escala sin aplastarla ni dejar franjas blancas.
function recortarFirma(canvas) {
  const { width, height } = canvas
  let data
  try {
    data = canvas.getContext('2d').getImageData(0, 0, width, height).data
  } catch {
    return null
  }
  let minX = width, minY = height, maxX = -1, maxY = -1
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = (y * width + x) * 4
      // Fondo blanco: cuenta como tinta cualquier pixel que no sea casi-blanco.
      if (data[i] < 245 || data[i + 1] < 245 || data[i + 2] < 245) {
        if (x < minX) minX = x
        if (x > maxX) maxX = x
        if (y < minY) minY = y
        if (y > maxY) maxY = y
      }
    }
  }
  if (maxX < 0) return null // canvas vacío

  const pad = Math.round(12 * ratio)
  minX = Math.max(0, minX - pad)
  minY = Math.max(0, minY - pad)
  maxX = Math.min(width  - 1, maxX + pad)
  maxY = Math.min(height - 1, maxY + pad)
  const w = maxX - minX + 1
  const h = maxY - minY + 1

  const out  = document.createElement('canvas')
  out.width  = w
  out.height = h
  const octx = out.getContext('2d')
  octx.fillStyle = '#ffffff'
  octx.fillRect(0, 0, w, h)
  octx.drawImage(canvas, minX, minY, w, h, 0, 0, w, h)
  return out
}

function emitBlob(canvas) {
  const salida = recortarFirma(canvas) || canvas
  salida.toBlob(blob => emit('update:modelValue', blob), 'image/png')
}

function onArchivoChange(e) {
  const file = e.target.files[0]
  if (!file) return
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = URL.createObjectURL(file)
  emit('update:modelValue', file)
}

function quitarArchivo() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  if (archivoRef.value) archivoRef.value.value = ''
  emit('update:modelValue', null)
}

function cambiarModo(modo) {
  modoUpload.value = modo === 'upload'
  emit('update:modelValue', null)
}
</script>

<template>
  <div class="space-y-2">
    <!-- Pestañas -->
    <div class="flex rounded-lg overflow-hidden border border-gray-200">
      <button
        type="button"
        @click="cambiarModo('canvas')"
        :class="[
          'flex-1 py-2 text-sm font-medium transition-colors',
          !modoUpload ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50',
        ]"
      ><PencilIcon class="w-4 h-4 inline-block mr-1" />Firmar aquí</button>
      <button
        type="button"
        @click="cambiarModo('upload')"
        :class="[
          'flex-1 py-2 text-sm font-medium transition-colors border-l border-gray-200',
          modoUpload ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50',
        ]"
      ><PaperClipIcon class="w-4 h-4 inline-block mr-1" />Subir imagen</button>
    </div>

    <!-- Modo canvas -->
    <div v-show="!modoUpload" class="relative">
      <canvas
        ref="canvasRef"
        class="w-full rounded-lg border-2 border-dashed border-gray-300 cursor-crosshair touch-none bg-white"
        style="height: 140px;"
        @mousedown="startDraw"
        @mousemove="draw"
        @mouseup="endDraw"
        @mouseleave="endDraw"
        @touchstart.prevent="startDraw"
        @touchmove.prevent="draw"
        @touchend.prevent="endDraw"
        @touchcancel.prevent="endDraw"
      />
      <!-- Placeholder -->
      <p
        v-if="!hayFirma"
        class="absolute inset-0 flex items-center justify-center text-sm text-gray-300 pointer-events-none select-none"
      >
        Dibuje la firma del cliente aquí
      </p>
      <!-- Botón ampliar -->
      <button
        type="button"
        @click="abrirExpandido"
        class="absolute top-2 right-2 flex items-center gap-1 text-xs text-gray-500 bg-white border border-gray-200 rounded-md px-2 py-1 hover:bg-gray-50 shadow-sm"
        title="Ampliar para firmar"
      >
        <ArrowsPointingOutIcon class="w-3.5 h-3.5" />
        Ampliar
      </button>
      <!-- Botón limpiar -->
      <button
        v-if="hayFirma"
        type="button"
        @click="limpiar"
        class="absolute bottom-2 right-2 text-xs text-gray-500 bg-white border border-gray-200 rounded-md px-2 py-1 hover:bg-gray-50 shadow-sm"
      >
        Limpiar
      </button>
    </div>

    <!-- Modo archivo -->
    <div v-show="modoUpload" class="space-y-2">
      <div v-if="previewUrl" class="flex items-start gap-3">
        <img
          :src="previewUrl"
          alt="Firma"
          class="h-24 max-w-[240px] rounded-lg border border-gray-200 object-contain bg-white"
        />
        <button
          type="button"
          @click="quitarArchivo"
          class="text-xs text-red-500 border border-red-200 rounded-md px-2 py-1 hover:bg-red-50"
        >
          Quitar
        </button>
      </div>
      <label v-else class="block border-2 border-dashed border-gray-300 rounded-xl p-4 text-center cursor-pointer hover:border-blue-400 transition-colors">
        <input
          ref="archivoRef"
          type="file"
          accept="image/png,image/jpeg,image/jpg"
          @change="onArchivoChange"
          class="hidden"
        />
        <p class="text-sm text-gray-400">📎 Tomar foto o elegir de galería</p>
        <p class="text-xs text-gray-300 mt-1">PNG o JPG</p>
      </label>
    </div>
  </div>

  <!-- Overlay pantalla completa para firmar -->
  <Teleport to="body">
    <div
      v-if="modoExpandido"
      class="fixed inset-0 z-50 bg-white flex flex-col"
    >
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-gray-50">
        <span class="text-sm font-semibold text-gray-700">Firma del cliente</span>
        <div class="flex gap-2">
          <button
            type="button"
            @click="limpiarExpandido"
            class="text-sm text-gray-500 border border-gray-300 rounded-lg px-4 py-2 hover:bg-gray-100 active:bg-gray-200"
          >
            Limpiar
          </button>
          <button
            type="button"
            @click="confirmarExpandido"
            class="flex items-center gap-1.5 text-sm font-semibold text-white bg-blue-600 rounded-lg px-4 py-2 hover:bg-blue-700 active:bg-blue-800"
          >
            <CheckIcon class="w-4 h-4" />
            Listo
          </button>
        </div>
      </div>

      <!-- Canvas grande -->
      <div class="relative flex-1">
        <canvas
          ref="canvasExpandido"
          class="w-full h-full touch-none cursor-crosshair bg-white"
          @mousedown="startDrawExp"
          @mousemove="drawExp"
          @mouseup="endDrawExp"
          @mouseleave="endDrawExp"
          @touchstart.prevent="startDrawExp"
          @touchmove.prevent="drawExp"
          @touchend.prevent="endDrawExp"
          @touchcancel.prevent="endDrawExp"
        />
        <p
          v-if="!hayFirmaExp"
          class="absolute inset-0 flex items-center justify-center text-gray-300 pointer-events-none select-none text-base"
        >
          Dibuje la firma aquí
        </p>
      </div>
    </div>
  </Teleport>
</template>
