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

  // Copiar firma existente del canvas pequeño al expandido
  if (hayFirma.value && canvasRef.value) {
    ctxExp.drawImage(canvasRef.value, 0, 0, w, h)
    hayFirmaExp.value = true
  }
}

function getPos(e, canvas) {
  const rect = canvas.getBoundingClientRect()
  const src  = e.touches ? e.touches[0] : e
  return { x: src.clientX - rect.left, y: src.clientY - rect.top }
}

// Canvas pequeño
function startDraw(e) {
  e.preventDefault()
  dibujando.value = true
  const { x, y } = getPos(e, canvasRef.value)
  ctx.beginPath()
  ctx.moveTo(x, y)
}
function draw(e) {
  e.preventDefault()
  if (!dibujando.value) return
  const { x, y } = getPos(e, canvasRef.value)
  ctx.lineTo(x, y)
  ctx.stroke()
  hayFirma.value = true
}
function endDraw() {
  if (!dibujando.value) return
  dibujando.value = false
  if (hayFirma.value) emitBlob(canvasRef.value)
}

// Canvas expandido
function startDrawExp(e) {
  e.preventDefault()
  dibujandoExp.value = true
  const { x, y } = getPos(e, canvasExpandido.value)
  ctxExp.beginPath()
  ctxExp.moveTo(x, y)
}
function drawExp(e) {
  e.preventDefault()
  if (!dibujandoExp.value) return
  const { x, y } = getPos(e, canvasExpandido.value)
  ctxExp.lineTo(x, y)
  ctxExp.stroke()
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
  // Copiar firma del canvas grande al pequeño
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, peqCanvas.offsetWidth, peqCanvas.offsetHeight)
  ctx.drawImage(expCanvas, 0, 0, peqCanvas.offsetWidth, peqCanvas.offsetHeight)
  hayFirma.value = hayFirmaExp.value
  if (hayFirmaExp.value) emitBlob(peqCanvas)
  modoExpandido.value = false
}

async function abrirExpandido() {
  modoExpandido.value = true
  await nextTick()
  initCanvasExpandido()
}

function emitBlob(canvas) {
  canvas.toBlob(blob => emit('update:modelValue', blob), 'image/png')
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
      <div v-else>
        <input
          ref="archivoRef"
          type="file"
          accept="image/png,image/jpeg,image/jpg"
          @change="onArchivoChange"
          class="block w-full text-sm text-gray-600 border border-gray-200 rounded-lg cursor-pointer file:border-0 file:bg-gray-50 file:px-3 file:py-2 file:text-sm file:text-gray-700 file:font-medium file:mr-3"
        />
        <p class="text-xs text-gray-400 mt-1">PNG o JPG, máx. 5 MB</p>
      </div>
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
