<script setup>
import { ref, onMounted } from 'vue'
import { PencilIcon, PaperClipIcon } from '@heroicons/vue/24/outline'

const emit = defineEmits(['update:modelValue'])

const canvasRef  = ref(null)
const dibujando  = ref(false)
const hayBoceto  = ref(false)
const modoUpload = ref(false)
const archivoRef = ref(null)
const previewUrl = ref('')

let ctx   = null
let ratio = 1

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
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, w, h)
  ctx.strokeStyle = '#1e293b'
  ctx.lineWidth   = 2
  ctx.lineCap     = 'round'
  ctx.lineJoin    = 'round'
}

function getPos(e) {
  const rect = canvasRef.value.getBoundingClientRect()
  const src  = e.touches ? e.touches[0] : e
  return { x: src.clientX - rect.left, y: src.clientY - rect.top }
}

function startDraw(e) {
  e.preventDefault()
  dibujando.value = true
  const { x, y } = getPos(e)
  ctx.beginPath()
  ctx.moveTo(x, y)
}

function draw(e) {
  e.preventDefault()
  if (!dibujando.value) return
  const { x, y } = getPos(e)
  ctx.lineTo(x, y)
  ctx.stroke()
  hayBoceto.value = true
}

function endDraw() {
  if (!dibujando.value) return
  dibujando.value = false
  if (hayBoceto.value) {
    canvasRef.value.toBlob(blob => emit('update:modelValue', blob), 'image/png')
  }
}

function limpiar() {
  const canvas = canvasRef.value
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, canvas.offsetWidth, canvas.offsetHeight)
  hayBoceto.value = false
  emit('update:modelValue', null)
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
    <div class="flex rounded-lg overflow-hidden border border-purple-200">
      <button
        type="button"
        @click="cambiarModo('canvas')"
        :class="[
          'flex-1 py-2 text-sm font-medium transition-colors',
          !modoUpload ? 'bg-purple-600 text-white' : 'bg-white text-gray-600 hover:bg-purple-50',
        ]"
      ><PencilIcon class="w-4 h-4 inline-block mr-1" />Dibujar boceto</button>
      <button
        type="button"
        @click="cambiarModo('upload')"
        :class="[
          'flex-1 py-2 text-sm font-medium transition-colors border-l border-purple-200',
          modoUpload ? 'bg-purple-600 text-white' : 'bg-white text-gray-600 hover:bg-purple-50',
        ]"
      ><PaperClipIcon class="w-4 h-4 inline-block mr-1" />Subir imagen</button>
    </div>

    <!-- Canvas -->
    <div v-show="!modoUpload" class="relative">
      <canvas
        ref="canvasRef"
        class="w-full rounded-lg border-2 border-dashed border-purple-300 cursor-crosshair touch-none bg-white"
        style="height: 200px;"
        @mousedown="startDraw"
        @mousemove="draw"
        @mouseup="endDraw"
        @mouseleave="endDraw"
        @touchstart.prevent="startDraw"
        @touchmove.prevent="draw"
        @touchend.prevent="endDraw"
        @touchcancel.prevent="endDraw"
      />
      <p
        v-if="!hayBoceto"
        class="absolute inset-0 flex items-center justify-center text-sm text-purple-200 pointer-events-none select-none"
      >
        Dibuje el boceto del producto aquí
      </p>
      <button
        v-if="hayBoceto"
        type="button"
        @click="limpiar"
        class="absolute bottom-2 right-2 text-xs text-gray-500 bg-white border border-gray-200 rounded-md px-2 py-1 hover:bg-gray-50 shadow-sm"
      >
        Limpiar
      </button>
    </div>

    <!-- Subir archivo -->
    <div v-show="modoUpload" class="space-y-2">
      <div v-if="previewUrl" class="flex items-start gap-3">
        <img
          :src="previewUrl"
          alt="Boceto"
          class="max-h-40 max-w-full rounded-lg border border-gray-200 object-contain bg-white"
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
          class="block w-full text-sm text-gray-600 border border-gray-200 rounded-lg cursor-pointer file:border-0 file:bg-purple-50 file:px-3 file:py-2 file:text-sm file:text-purple-700 file:font-medium file:mr-3"
        />
        <p class="text-xs text-gray-400 mt-1">PNG o JPG — foto del boceto en papel</p>
      </div>
    </div>
  </div>
</template>
