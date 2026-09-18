<script setup>
/**
 * Dibujar el icono de un módulo con el dedo o el mouse.
 *
 * No se guarda una imagen: se guardan los trazos como el `d` de un path de
 * SVG, en el mismo lienzo de 24×24 de heroicons. Por eso el resultado se ve
 * igual que los iconos de la lista —mismo grosor de línea, mismo color del
 * botón donde esté— y ocupa unas letras en vez de un archivo.
 *
 * Emite `elegir` con el texto listo para guardar (`dibujo:M2 2L22 22...`).
 */
import { ref, computed, watch } from 'vue'
import { PREFIJO_DIBUJO, esDibujo, iconoPorNombre } from '@/constants/iconos'
import { ArrowUturnLeftIcon, TrashIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  /** Un dibujo ya guardado, para seguir sobre él. */
  inicial: { type: String, default: '' },
})
const emit = defineEmits(['elegir'])

// Lo mismo que el lienzo de heroicons: los iconos de la lista van de 2 a 22.
const LIENZO  = 24
const MARGEN  = 2
// Un punto nuevo sólo se guarda si se alejó de éste: el dedo manda decenas de
// eventos por centímetro y el trazo sería enorme sin aportar nada.
const PASO_MINIMO = 0.35
// Hasta dónde puede crecer el texto guardado; es el tope del servidor con aire.
const LARGO_MAXIMO = 7500

const trazos    = ref(leer(props.inicial))   // [[ [x,y], [x,y], ... ], ...]
const dibujando = ref(false)
const lienzoRef = ref(null)

watch(() => props.inicial, v => { trazos.value = leer(v) })

// ── Del texto guardado a puntos, y de vuelta ─────────────────────────────────

function leer(texto) {
  if (!esDibujo(texto)) return []
  const d = texto.slice(PREFIJO_DIBUJO.length)
  return d.split(/(?=M)/).map(seg => {
    const nums = seg.match(/-?\d+(\.\d+)?/g)?.map(Number) ?? []
    const pts = []
    for (let i = 0; i + 1 < nums.length; i += 2) pts.push([nums[i], nums[i + 1]])
    return pts
  }).filter(pts => pts.length)
}

const trazoSvg = computed(() =>
  trazos.value.map(pts => {
    // Un toque sin arrastrar es un punto: con las puntas redondas se ve como
    // un puntico, pero sólo si el path tiene a dónde ir.
    const lista = pts.length === 1 ? [pts[0], pts[0]] : pts
    return lista.map(([x, y], i) => `${i ? 'L' : 'M'}${x} ${y}`).join('')
  }).join('')
)

const valor      = computed(() => PREFIJO_DIBUJO + trazoSvg.value)
const hayTrazos  = computed(() => trazos.value.length > 0)
const muyLargo   = computed(() => valor.value.length > LARGO_MAXIMO)
const vistaPrevia = computed(() => hayTrazos.value ? iconoPorNombre(valor.value) : null)

// ── Dibujar ──────────────────────────────────────────────────────────────────

function punto(e) {
  const r = lienzoRef.value.getBoundingClientRect()
  const x = ((e.clientX - r.left) / r.width)  * LIENZO
  const y = ((e.clientY - r.top)  / r.height) * LIENZO
  const acotar = v => Math.min(LIENZO, Math.max(0, Math.round(v * 10) / 10))
  return [acotar(x), acotar(y)]
}

function empezar(e) {
  if (e.button !== undefined && e.button !== 0) return
  e.preventDefault()
  lienzoRef.value.setPointerCapture?.(e.pointerId)
  dibujando.value = true
  trazos.value.push([punto(e)])
}

function mover(e) {
  if (!dibujando.value) return
  e.preventDefault()
  const actual = trazos.value[trazos.value.length - 1]
  const [x, y] = punto(e)
  const [ux, uy] = actual[actual.length - 1]
  if (Math.hypot(x - ux, y - uy) < PASO_MINIMO) return
  actual.push([x, y])
}

function terminar(e) {
  if (!dibujando.value) return
  dibujando.value = false
  lienzoRef.value.releasePointerCapture?.(e.pointerId)
}

function deshacer() {
  trazos.value.pop()
}

function borrarTodo() {
  trazos.value = []
}

function usar() {
  if (!hayTrazos.value || muyLargo.value) return
  emit('elegir', valor.value)
}
</script>

<template>
  <div class="space-y-3">
    <p class="text-xs text-gray-500">
      Dibuja con el dedo o el mouse. Líneas sencillas se ven mejor: el icono
      va a salir pequeño, en la barra de abajo y en el inicio.
    </p>

    <!-- El lienzo: un SVG sobre el que se dibuja directamente, para que lo que
         se ve sea exactamente lo que se guarda. -->
    <div class="mx-auto w-full max-w-[280px]">
      <svg
        ref="lienzoRef"
        :viewBox="`0 0 ${LIENZO} ${LIENZO}`"
        class="w-full aspect-square rounded-2xl border-2 border-gray-200 bg-white text-gray-800 cursor-crosshair select-none"
        style="touch-action: none"
        @pointerdown="empezar"
        @pointermove="mover"
        @pointerup="terminar"
        @pointercancel="terminar"
        @pointerleave="terminar"
      >
        <!-- Guía: el cuadro donde caben los iconos de la lista, para dibujar al mismo tamaño -->
        <rect
          :x="MARGEN" :y="MARGEN" :width="LIENZO - 2 * MARGEN" :height="LIENZO - 2 * MARGEN"
          fill="none" stroke="#e5e7eb" stroke-width="0.15" stroke-dasharray="0.6 0.4" rx="1"
        />
        <line :x1="LIENZO / 2" y1="0" :x2="LIENZO / 2" :y2="LIENZO" stroke="#f3f4f6" stroke-width="0.12" />
        <line x1="0" :y1="LIENZO / 2" :x2="LIENZO" :y2="LIENZO / 2" stroke="#f3f4f6" stroke-width="0.12" />

        <path
          :d="trazoSvg"
          fill="none" stroke="currentColor" stroke-width="1.5"
          stroke-linecap="round" stroke-linejoin="round"
        />
      </svg>
    </div>

    <!-- Cómo va a quedar, al tamaño real de cada lugar -->
    <div class="flex items-center justify-center gap-5 text-xs text-gray-500">
      <div class="flex flex-col items-center gap-1">
        <span class="w-11 h-11 rounded-xl border border-gray-200 flex items-center justify-center text-gray-600">
          <component v-if="vistaPrevia" :is="vistaPrevia" class="w-6 h-6" />
        </span>
        <span>Gestión</span>
      </div>
      <div class="flex flex-col items-center gap-1">
        <span class="w-11 h-11 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
          <component v-if="vistaPrevia" :is="vistaPrevia" class="w-8 h-8" />
        </span>
        <span>Inicio</span>
      </div>
      <div class="flex flex-col items-center gap-1">
        <span class="w-11 h-11 rounded-xl bg-blue-600 flex items-center justify-center text-white">
          <component v-if="vistaPrevia" :is="vistaPrevia" class="w-5 h-5" />
        </span>
        <span>Barra</span>
      </div>
    </div>

    <p v-if="muyLargo" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
      El dibujo tiene demasiados trazos para guardarse. Deshaz algunos o hazlo más sencillo.
    </p>

    <div class="flex gap-2">
      <button
        type="button" @click="deshacer" :disabled="!hayTrazos"
        class="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold disabled:opacity-40"
      >
        <ArrowUturnLeftIcon class="w-4 h-4" />
        Deshacer
      </button>
      <button
        type="button" @click="borrarTodo" :disabled="!hayTrazos"
        class="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold disabled:opacity-40"
      >
        <TrashIcon class="w-4 h-4" />
        Borrar
      </button>
      <button
        type="button" @click="usar" :disabled="!hayTrazos || muyLargo"
        class="flex-1 px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-bold hover:bg-blue-700 disabled:opacity-50"
      >
        Usar este icono
      </button>
    </div>
  </div>
</template>
