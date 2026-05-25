<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { PencilIcon, PaperClipIcon, ArrowsPointingOutIcon, XMarkIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline'

const emit = defineEmits(['update:modelValue'])

// ── Estado general ────────────────────────────────────────────────────────────
const modoUpload   = ref(false)
const archivoRef   = ref(null)
const previewUrl   = ref('')
const hayBoceto    = ref(false)

// ── Canvas inline (miniatura) ─────────────────────────────────────────────────
const canvasRef   = ref(null)
let   ctxInline   = null
let   ratioInline = 1

// ── Canvas expandido (modal) ──────────────────────────────────────────────────
const expandido     = ref(false)
const canvasExpandRef = ref(null)
let   ctxExpand    = null
let   ratioExpand  = 1

// ── Herramientas ──────────────────────────────────────────────────────────────
const grosor       = ref(3)    // px de línea
const modoLapiz    = ref(false) // toggle: dibujar sin mantener presionado
const dibujando    = ref(false)

// Historial para deshacer (snapshots del canvas expandido)
const historial    = ref([])

// ── Inicializar canvas inline ─────────────────────────────────────────────────
onMounted(initInline)

function initInline() {
  const c = canvasRef.value
  if (!c) return
  ratioInline = window.devicePixelRatio || 1
  c.width  = c.offsetWidth  * ratioInline
  c.height = c.offsetHeight * ratioInline
  ctxInline = c.getContext('2d')
  ctxInline.scale(ratioInline, ratioInline)
  _llenarBlanco(ctxInline, c.offsetWidth, c.offsetHeight)
  _estiloCtx(ctxInline)
}

// ── Inicializar canvas expandido ──────────────────────────────────────────────
async function abrirExpandido() {
  expandido.value = true
  await nextTick()
  const c = canvasExpandRef.value
  if (!c) return
  ratioExpand = window.devicePixelRatio || 1
  c.width  = c.offsetWidth  * ratioExpand
  c.height = c.offsetHeight * ratioExpand
  ctxExpand = c.getContext('2d')
  ctxExpand.scale(ratioExpand, ratioExpand)
  _llenarBlanco(ctxExpand, c.offsetWidth, c.offsetHeight)
  _estiloCtx(ctxExpand)
  historial.value = []
  modoLapiz.value = false
  dibujando.value = false

  // Copiar boceto inline al expandido si ya había uno
  if (hayBoceto.value) {
    const img = new Image()
    img.onload = () => {
      ctxExpand.drawImage(img, 0, 0, c.offsetWidth, c.offsetHeight)
      guardarHistorial()
    }
    img.src = canvasRef.value.toDataURL()
  } else {
    guardarHistorial()
  }
}

function cerrarExpandido() {
  // Copiar el dibujo expandido de vuelta al canvas inline
  if (ctxExpand && canvasExpandRef.value) {
    const imgData = canvasExpandRef.value.toDataURL()
    const img = new Image()
    img.onload = () => {
      const c = canvasRef.value
      _llenarBlanco(ctxInline, c.offsetWidth, c.offsetHeight)
      ctxInline.drawImage(img, 0, 0, c.offsetWidth, c.offsetHeight)
    }
    img.src = imgData
    // Emitir blob final
    canvasExpandRef.value.toBlob(blob => emit('update:modelValue', blob), 'image/png')
  }
  expandido.value = false
  dibujando.value = false
  modoLapiz.value = false
}

// ── Dibujar en canvas expandido ───────────────────────────────────────────────
function posExpand(e) {
  const rect = canvasExpandRef.value.getBoundingClientRect()
  const src  = e.touches ? e.touches[0] : e
  return { x: src.clientX - rect.left, y: src.clientY - rect.top }
}

function startExpand(e) {
  e.preventDefault()
  _estiloCtx(ctxExpand)
  const { x, y } = posExpand(e)
  if (modoLapiz.value) {
    // Toggle: si ya estaba dibujando, esta pulsación termina el trazo
    if (dibujando.value) {
      dibujando.value = false
      ctxExpand.lineTo(x, y)
      ctxExpand.stroke()
      hayBoceto.value = true
      guardarHistorial()
    } else {
      dibujando.value = true
      ctxExpand.beginPath()
      ctxExpand.moveTo(x, y)
    }
  } else {
    dibujando.value = true
    ctxExpand.beginPath()
    ctxExpand.moveTo(x, y)
  }
}

function moveExpand(e) {
  e.preventDefault()
  if (!dibujando.value) return
  const { x, y } = posExpand(e)
  ctxExpand.lineTo(x, y)
  ctxExpand.stroke()
  ctxExpand.beginPath()
  ctxExpand.moveTo(x, y)
  hayBoceto.value = true
}

function endExpand(e) {
  if (modoLapiz.value) return // en modo lápiz el trazo sigue hasta próximo click
  if (!dibujando.value) return
  dibujando.value = false
  guardarHistorial()
}

function guardarHistorial() {
  historial.value.push(canvasExpandRef.value.toDataURL())
  if (historial.value.length > 20) historial.value.shift()
}

function deshacer() {
  if (historial.value.length <= 1) return
  historial.value.pop()
  const prev = historial.value[historial.value.length - 1]
  const c = canvasExpandRef.value
  const img = new Image()
  img.onload = () => {
    _llenarBlanco(ctxExpand, c.offsetWidth, c.offsetHeight)
    ctxExpand.drawImage(img, 0, 0, c.offsetWidth, c.offsetHeight)
  }
  img.src = prev
}

function limpiarExpand() {
  const c = canvasExpandRef.value
  _llenarBlanco(ctxExpand, c.offsetWidth, c.offsetHeight)
  hayBoceto.value = false
  historial.value = []
  guardarHistorial()
}

// ── Dibujar en canvas inline (pequeño) ───────────────────────────────────────
function posInline(e) {
  const rect = canvasRef.value.getBoundingClientRect()
  const src  = e.touches ? e.touches[0] : e
  return { x: src.clientX - rect.left, y: src.clientY - rect.top }
}

function startInline(e) {
  e.preventDefault()
  _estiloCtx(ctxInline)
  dibujando.value = true
  const { x, y } = posInline(e)
  ctxInline.beginPath()
  ctxInline.moveTo(x, y)
}

function moveInline(e) {
  e.preventDefault()
  if (!dibujando.value) return
  const { x, y } = posInline(e)
  ctxInline.lineTo(x, y)
  ctxInline.stroke()
  ctxInline.beginPath()
  ctxInline.moveTo(x, y)
  hayBoceto.value = true
}

function endInline() {
  if (!dibujando.value) return
  dibujando.value = false
  if (hayBoceto.value) {
    canvasRef.value.toBlob(blob => emit('update:modelValue', blob), 'image/png')
  }
}

function limpiarInline() {
  const c = canvasRef.value
  _llenarBlanco(ctxInline, c.offsetWidth, c.offsetHeight)
  hayBoceto.value = false
  emit('update:modelValue', null)
}

// ── Upload ────────────────────────────────────────────────────────────────────
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

// ── Helpers ───────────────────────────────────────────────────────────────────
function _llenarBlanco(ctx, w, h) {
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, w, h)
}

function _estiloCtx(ctx) {
  ctx.strokeStyle = '#1e293b'
  ctx.lineWidth   = grosor.value
  ctx.lineCap     = 'round'
  ctx.lineJoin    = 'round'
}

// Cerrar modal con Escape
function onKeydown(e) {
  if (e.key === 'Escape' && expandido.value) cerrarExpandido()
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="space-y-2">
    <!-- Pestañas -->
    <div class="flex rounded-lg overflow-hidden border border-purple-200">
      <button type="button" @click="cambiarModo('canvas')"
        :class="['flex-1 py-2 text-sm font-medium transition-colors',
          !modoUpload ? 'bg-purple-600 text-white' : 'bg-white text-gray-600 hover:bg-purple-50']">
        <PencilIcon class="w-4 h-4 inline-block mr-1" />Dibujar boceto
      </button>
      <button type="button" @click="cambiarModo('upload')"
        :class="['flex-1 py-2 text-sm font-medium transition-colors border-l border-purple-200',
          modoUpload ? 'bg-purple-600 text-white' : 'bg-white text-gray-600 hover:bg-purple-50']">
        <PaperClipIcon class="w-4 h-4 inline-block mr-1" />Subir imagen
      </button>
    </div>

    <!-- Canvas inline (miniatura) -->
    <div v-show="!modoUpload" class="relative">
      <canvas
        ref="canvasRef"
        class="w-full rounded-lg border-2 border-dashed border-purple-300 cursor-crosshair touch-none bg-white"
        style="height: 160px;"
        @mousedown="startInline"
        @mousemove="moveInline"
        @mouseup="endInline"
        @mouseleave="endInline"
        @touchstart.prevent="startInline"
        @touchmove.prevent="moveInline"
        @touchend.prevent="endInline"
        @touchcancel.prevent="endInline"
      />
      <p v-if="!hayBoceto"
        class="absolute inset-0 flex items-center justify-center text-sm text-purple-200 pointer-events-none select-none">
        Dibuje aquí o use el modo expandido
      </p>
      <!-- Botones sobre el canvas inline -->
      <div class="absolute top-2 right-2 flex gap-1">
        <button type="button" @click="abrirExpandido"
          class="flex items-center gap-1 text-xs text-purple-700 bg-white border border-purple-200 rounded-md px-2 py-1 hover:bg-purple-50 shadow-sm font-medium">
          <ArrowsPointingOutIcon class="w-3.5 h-3.5" /> Expandir
        </button>
      </div>
      <button v-if="hayBoceto" type="button" @click="limpiarInline"
        class="absolute bottom-2 right-2 text-xs text-gray-500 bg-white border border-gray-200 rounded-md px-2 py-1 hover:bg-gray-50 shadow-sm">
        Limpiar
      </button>
    </div>

    <!-- Modal expandido -->
    <Teleport to="body">
      <div v-if="expandido"
        class="fixed inset-0 z-50 flex flex-col bg-white"
        style="touch-action: none;">

        <!-- Barra de herramientas -->
        <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 border-b border-gray-200 flex-shrink-0">
          <span class="text-sm font-semibold text-gray-700 mr-1">Boceto</span>

          <!-- Grosor -->
          <div class="flex items-center gap-1">
            <span class="text-xs text-gray-500">Grosor:</span>
            <button v-for="g in [2, 4, 7]" :key="g" type="button"
              @click="grosor = g"
              :class="['rounded-full border transition-colors flex items-center justify-center',
                grosor === g ? 'border-purple-500 bg-purple-100' : 'border-gray-300 bg-white',
                g === 2 ? 'w-6 h-6' : g === 4 ? 'w-7 h-7' : 'w-8 h-8']">
              <span :style="`width:${g*2}px; height:${g*2}px; border-radius:50%; background:#1e293b; display:block`"></span>
            </button>
          </div>

          <!-- Modo lápiz -->
          <button type="button" @click="modoLapiz = !modoLapiz; dibujando = false"
            :class="['flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors',
              modoLapiz ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50']">
            <PencilIcon class="w-3.5 h-3.5" />
            {{ modoLapiz ? 'Lápiz ON' : 'Lápiz OFF' }}
          </button>

          <div class="flex-1" />

          <!-- Deshacer -->
          <button type="button" @click="deshacer" :disabled="historial.length <= 1"
            class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-40 transition-colors">
            <ArrowUturnLeftIcon class="w-3.5 h-3.5" /> Deshacer
          </button>

          <!-- Limpiar -->
          <button type="button" @click="limpiarExpand"
            class="px-2.5 py-1 rounded-lg text-xs font-medium border border-gray-300 bg-white hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-colors">
            Limpiar
          </button>

          <!-- Guardar y cerrar -->
          <button type="button" @click="cerrarExpandido"
            class="flex items-center gap-1 px-3 py-1 rounded-lg text-xs font-semibold bg-purple-600 text-white hover:bg-purple-700 transition-colors">
            Guardar
          </button>

          <button type="button" @click="cerrarExpandido" class="p-1 text-gray-400 hover:text-gray-600 ml-1">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <!-- Indicador modo lápiz -->
        <div v-if="modoLapiz"
          class="px-3 py-1.5 bg-purple-50 border-b border-purple-100 text-xs text-purple-700 flex-shrink-0">
          <PencilIcon class="w-3.5 h-3.5 inline-block mr-1" />
          Modo lápiz activo — toca para {{ dibujando ? 'terminar trazo' : 'empezar a dibujar' }}
        </div>

        <!-- Canvas grande -->
        <div class="flex-1 relative overflow-hidden">
          <canvas
            ref="canvasExpandRef"
            class="w-full h-full touch-none"
            :class="dibujando && modoLapiz ? 'cursor-crosshair' : modoLapiz ? 'cursor-cell' : 'cursor-crosshair'"
            @mousedown="startExpand"
            @mousemove="moveExpand"
            @mouseup="endExpand"
            @mouseleave="endExpand"
            @touchstart.prevent="startExpand"
            @touchmove.prevent="moveExpand"
            @touchend.prevent="endExpand"
            @touchcancel.prevent="endExpand"
          />
          <p v-if="!hayBoceto"
            class="absolute inset-0 flex items-center justify-center text-lg text-gray-200 pointer-events-none select-none">
            {{ modoLapiz ? 'Toca para empezar a dibujar' : 'Mantén presionado para dibujar' }}
          </p>
        </div>
      </div>
    </Teleport>

    <!-- Subir archivo -->
    <div v-show="modoUpload" class="space-y-2">
      <div v-if="previewUrl" class="flex items-start gap-3">
        <img :src="previewUrl" alt="Boceto"
          class="max-h-40 max-w-full rounded-lg border border-gray-200 object-contain bg-white" />
        <button type="button" @click="quitarArchivo"
          class="text-xs text-red-500 border border-red-200 rounded-md px-2 py-1 hover:bg-red-50">
          Quitar
        </button>
      </div>
      <div v-else>
        <input ref="archivoRef" type="file" accept="image/png,image/jpeg,image/jpg"
          @change="onArchivoChange"
          class="block w-full text-sm text-gray-600 border border-gray-200 rounded-lg cursor-pointer file:border-0 file:bg-purple-50 file:px-3 file:py-2 file:text-sm file:text-purple-700 file:font-medium file:mr-3" />
        <p class="text-xs text-gray-400 mt-1">PNG o JPG — foto del boceto en papel</p>
      </div>
    </div>
  </div>
</template>
