<script setup>
/**
 * El visor tipo revista de un catálogo visual. Público, sin sesión.
 *
 * Una página a la vez sobre fondo oscuro, con volteo de hoja en 3D: se arrastra
 * con el dedo, se pasa con las flechas o el teclado, o tocando los bordes.
 * Abajo hay una tira de miniaturas para saltar a cualquier página.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { catalogoPublico } from '@/api/catalogos'
import { cloudinaryOpt } from '@/utils/cloudinary'
import {
  ChevronLeftIcon, ChevronRightIcon, XMarkIcon,
  ArrowsPointingOutIcon, ArrowsPointingInIcon, ShareIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()

const WHATSAPP = '573217770621'

const nombre    = ref('')
const descrip   = ref('')
const paginas   = ref([])
const cargando  = ref(true)
const noExiste  = ref(false)

const i          = ref(0)              // página actual (0-based)
const dir        = ref(null)           // 'next' | 'prev' mientras se voltea
const p          = ref(0)              // progreso del volteo [0..1]
const stageRef   = ref(null)
const esPantallaCompleta = ref(false)
const miniRef    = ref(null)

const total = computed(() => paginas.value.length)
const grande = (url) => cloudinaryOpt(url, 1600)
const mini   = (url) => cloudinaryOpt(url, 200)

// ── Carga ────────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const { data } = await catalogoPublico(route.params.slug)
    nombre.value  = data.nombre
    descrip.value = data.descripcion || ''
    paginas.value = data.paginas ?? []
    document.title = `${data.nombre} — Decasa Muebles`
  } catch {
    noExiste.value = true
  } finally {
    cargando.value = false
  }
  window.addEventListener('keydown', onTecla)
  document.addEventListener('fullscreenchange', onFsChange)
})
onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  window.removeEventListener('keydown', onTecla)
  document.removeEventListener('fullscreenchange', onFsChange)
})

// Precarga de las vecinas para que el volteo no muestre una imagen a medio bajar.
watch(i, (n) => {
  for (const idx of [n - 1, n + 1, n + 2]) {
    if (idx >= 0 && idx < total.value) {
      const img = new Image()
      img.src = grande(paginas.value[idx].imagen_url)
    }
  }
}, { immediate: true })

watch(i, () => scrollMiniActiva())

// ── Navegación ───────────────────────────────────────────────────────────────
function puede(d) {
  return d === 'next' ? i.value < total.value - 1 : i.value > 0
}

// El volteo se anima con requestAnimationFrame —no con transición CSS— para que
// la rotación, la sombra y el reverso de papel vayan sincronizados en el mismo
// progreso `p`.
let raf = null
function animarHacia(objetivo, alTerminar) {
  cancelAnimationFrame(raf)
  const desde = p.value
  const dur   = 520
  const t0    = performance.now()
  const ease  = (x) => 1 - Math.pow(1 - x, 3)   // easeOutCubic
  const paso  = (ahora) => {
    const k = Math.min((ahora - t0) / dur, 1)
    p.value = desde + (objetivo - desde) * ease(k)
    if (k < 1) raf = requestAnimationFrame(paso)
    else { p.value = objetivo; alTerminar?.() }
  }
  raf = requestAnimationFrame(paso)
}

function cerrarVolteo() {
  if (dir.value && p.value >= 0.999) {
    i.value += dir.value === 'next' ? 1 : -1
  }
  dir.value = null
  p.value = 0
}

function voltear(d) {
  if (dir.value || !puede(d)) return
  dir.value = d
  p.value = 0            // 'next': hoja en rot 0 ; 'prev': hoja en rot -180
  animarHacia(1, cerrarVolteo)
}

function irA(idx) {
  if (idx === i.value || dir.value || idx < 0 || idx >= total.value) return
  i.value = idx
}

function onTecla(e) {
  if (e.key === 'ArrowRight' || e.key === 'PageDown') voltear('next')
  else if (e.key === 'ArrowLeft' || e.key === 'PageUp') voltear('prev')
  else if (e.key === 'Escape' && esPantallaCompleta.value) salirPantallaCompleta()
}

// ── Arrastre con el dedo ─────────────────────────────────────────────────────
let x0 = 0, t0 = 0, ancho = 1, arrastrando = false, movido = false

function onTouchStart(e) {
  cancelAnimationFrame(raf); raf = null
  const t = e.touches[0]
  x0 = t.clientX
  t0 = Date.now()
  ancho = stageRef.value?.clientWidth || window.innerWidth
  arrastrando = true
  movido = false
}
function onTouchMove(e) {
  if (!arrastrando) return
  const dx = e.touches[0].clientX - x0
  if (Math.abs(dx) > 8) movido = true
  const d  = dx < 0 ? 'next' : 'prev'
  if (!puede(d)) { dir.value = null; p.value = 0; return }
  dir.value = d
  p.value = Math.min(Math.abs(dx) / ancho, 1)
}
function onTouchEnd() {
  if (!arrastrando) return
  arrastrando = false
  if (!dir.value) return
  const rapido = (Date.now() - t0) < 260 && p.value > 0.08
  animarHacia((p.value > 0.4 || rapido) ? 1 : 0, cerrarVolteo)
}

// Clic en los tercios laterales (mouse). Se ignora si venía de un arrastre.
function onClickStage(e) {
  if (movido) { movido = false; return }
  if (dir.value) return
  const r = stageRef.value.getBoundingClientRect()
  const rel = (e.clientX - r.left) / r.width
  if (rel > 0.6) voltear('next')
  else if (rel < 0.4) voltear('prev')
}

// ── Transformaciones de las hojas ────────────────────────────────────────────
// La hoja que gira siempre pivota sobre el borde izquierdo (el lomo).
const leafActual = computed(() => {
  // 'next': gira la página actual hacia afuera (0 -> -180)
  if (dir.value === 'next') return { idx: i.value, rot: -180 * p.value }
  // 'prev': entra la anterior (-180 -> 0)
  if (dir.value === 'prev') return { idx: i.value - 1, rot: -180 * (1 - p.value) }
  return { idx: i.value, rot: 0 }
})
// Lo que se ve por debajo de la hoja que gira.
const paginaFondo = computed(() => {
  if (dir.value === 'next') return i.value + 1
  if (dir.value === 'prev') return i.value
  return null
})

const sombra = computed(() => {
  // Oscurece la hoja mientras está de canto, como una página real.
  const x = dir.value ? Math.sin(p.value * Math.PI) : 0
  return x * 0.22
})

// Cuando la hoja pasa de los ~90° se ve su reverso: se tapa con un tono papel
// para no mostrar la imagen al revés.
const reverso = computed(() => {
  const rot = Math.abs(leafActual.value.rot)
  return Math.min(Math.max((rot - 78) / 55, 0), 0.96)
})

// ── Pantalla completa ────────────────────────────────────────────────────────
function togglePantallaCompleta() {
  if (document.fullscreenElement) salirPantallaCompleta()
  else stageRef.value?.parentElement?.requestFullscreen?.().catch(() => {})
}
function salirPantallaCompleta() { document.exitFullscreen?.().catch(() => {}) }
function onFsChange() { esPantallaCompleta.value = !!document.fullscreenElement }

// ── Compartir ────────────────────────────────────────────────────────────────
async function compartir() {
  const url = window.location.href
  if (navigator.share) {
    try { await navigator.share({ title: nombre.value, url }) } catch { /* cancelado */ }
  } else {
    try { await navigator.clipboard.writeText(url) } catch { /* nada */ }
  }
}

async function scrollMiniActiva() {
  await nextTick()
  const cont = miniRef.value
  const el   = cont?.children?.[i.value]
  if (cont && el) cont.scrollTo({ left: el.offsetLeft - cont.clientWidth / 2 + el.clientWidth / 2, behavior: 'smooth' })
}

const notaActual = computed(() => paginas.value[i.value]?.nota || '')
</script>

<template>
  <div class="fixed inset-0 bg-[#1b1b1d] flex flex-col select-none">

    <!-- Cargando -->
    <div v-if="cargando" class="flex-1 flex items-center justify-center">
      <div class="w-8 h-8 border-2 border-white/40 border-t-transparent rounded-full animate-spin" />
    </div>

    <!-- No existe -->
    <div v-else-if="noExiste" class="flex-1 flex flex-col items-center justify-center text-center px-6">
      <p class="text-lg font-semibold text-white">Este catálogo no está disponible</p>
      <p class="text-sm text-white/50 mt-1">Puede que el enlace esté mal o que ya no esté publicado.</p>
      <div class="flex gap-2 mt-6">
        <RouterLink :to="{ name: 'catalogos-portada' }" class="bg-white/10 text-white text-sm font-semibold rounded-xl px-4 py-2.5">
          Ver otros catálogos
        </RouterLink>
        <a :href="`https://wa.me/${WHATSAPP}`" target="_blank" class="bg-emerald-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5">
          WhatsApp
        </a>
      </div>
    </div>

    <template v-else>
      <!-- Barra superior -->
      <header class="absolute top-0 inset-x-0 z-20 flex items-center gap-2 px-3 py-2.5 bg-gradient-to-b from-black/60 to-transparent">
        <button
          @click="router.push({ name: 'catalogos-portada' })"
          class="w-9 h-9 rounded-full bg-black/30 backdrop-blur flex items-center justify-center text-white hover:bg-black/50"
        >
          <XMarkIcon class="w-5 h-5" />
        </button>
        <div class="flex-1 min-w-0">
          <p class="text-white font-semibold text-sm leading-tight truncate">{{ nombre }}</p>
          <p v-if="descrip" class="text-white/50 text-[11px] truncate">{{ descrip }}</p>
        </div>
        <button
          @click="compartir"
          class="w-9 h-9 rounded-full bg-black/30 backdrop-blur flex items-center justify-center text-white hover:bg-black/50"
          title="Compartir"
        >
          <ShareIcon class="w-5 h-5" />
        </button>
        <button
          @click="togglePantallaCompleta"
          class="w-9 h-9 rounded-full bg-black/30 backdrop-blur flex items-center justify-center text-white hover:bg-black/50 hidden sm:flex"
          title="Pantalla completa"
        >
          <component :is="esPantallaCompleta ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="w-5 h-5" />
        </button>
      </header>

      <!-- Escenario -->
      <div class="flex-1 relative overflow-hidden" style="perspective: 2200px;">
        <div
          ref="stageRef"
          class="absolute inset-0 flex items-center justify-center px-2 sm:px-16 py-14"
          @click="onClickStage"
          @touchstart.passive="onTouchStart"
          @touchmove.passive="onTouchMove"
          @touchend="onTouchEnd"
        >
          <!-- Página de fondo (se ve al voltear) -->
          <img
            v-if="paginaFondo !== null && paginas[paginaFondo]"
            :key="'fondo-' + paginaFondo"
            :src="grande(paginas[paginaFondo].imagen_url)"
            alt=""
            class="max-h-[80vh] object-contain shadow-2xl rounded-sm pointer-events-none"
            style="width: min(100%, 62vh);"
            draggable="false"
          />

          <!-- Hoja que gira -->
          <div
            class="absolute pointer-events-none"
            :style="{
              transformOrigin: 'left center',
              transform: `rotateY(${leafActual.rot}deg)`,
              width: 'min(100%, 62vh)',
              willChange: 'transform',
            }"
          >
            <div class="relative">
              <img
                v-if="paginas[leafActual.idx]"
                :src="grande(paginas[leafActual.idx].imagen_url)"
                alt=""
                class="w-full max-h-[80vh] object-contain shadow-2xl rounded-sm bg-[#111]"
                draggable="false"
              />
              <!-- Sombra de la hoja al ponerse de canto -->
              <div
                class="absolute inset-0 rounded-sm pointer-events-none"
                :style="{ background: '#000', opacity: sombra }"
              />
              <!-- Reverso: al pasar los 90° se tapa la imagen con tono papel -->
              <div
                class="absolute inset-0 rounded-sm pointer-events-none"
                :style="{ background: '#e9e7e1', opacity: reverso }"
              />
              <!-- Sombra del lomo -->
              <div
                class="absolute inset-y-0 left-0 w-16 pointer-events-none rounded-l-sm"
                :style="{ background: 'linear-gradient(to right, rgba(0,0,0,0.28), transparent)', opacity: dir ? 1 : 0 }"
              />
            </div>
          </div>
        </div>

        <!-- Flechas (desktop) -->
        <button
          v-if="i > 0"
          @click="voltear('prev')"
          class="absolute left-2 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-black/30 backdrop-blur items-center justify-center text-white hover:bg-black/50 hidden sm:flex"
        >
          <ChevronLeftIcon class="w-6 h-6" />
        </button>
        <button
          v-if="i < total - 1"
          @click="voltear('next')"
          class="absolute right-2 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-black/30 backdrop-blur items-center justify-center text-white hover:bg-black/50 hidden sm:flex"
        >
          <ChevronRightIcon class="w-6 h-6" />
        </button>

        <!-- Nota de la página -->
        <div
          v-if="notaActual"
          class="absolute left-1/2 -translate-x-1/2 bottom-2 z-10 max-w-[90%] text-center"
        >
          <span class="inline-block text-xs text-white/90 bg-black/45 backdrop-blur rounded-full px-3 py-1">
            {{ notaActual }}
          </span>
        </div>
      </div>

      <!-- Pie: contador + miniaturas -->
      <footer class="relative z-20 bg-black/40 backdrop-blur border-t border-white/10">
        <div class="flex items-center justify-between px-3 pt-1.5">
          <span class="text-[11px] text-white/60 font-medium tabular-nums">{{ i + 1 }} / {{ total }}</span>
          <a :href="`https://wa.me/${WHATSAPP}?text=${encodeURIComponent('Hola, vi el catálogo de ' + nombre)}`"
            target="_blank"
            class="text-[11px] font-semibold text-emerald-400">
            Me interesa →
          </a>
        </div>
        <div
          v-if="total > 1"
          ref="miniRef"
          class="flex gap-1.5 overflow-x-auto px-3 py-2 no-scrollbar"
        >
          <button
            v-for="(pg, idx) in paginas" :key="idx"
            @click="irA(idx)"
            class="shrink-0 rounded overflow-hidden ring-2 transition-all"
            :class="idx === i ? 'ring-emerald-400' : 'ring-transparent opacity-50 hover:opacity-90'"
          >
            <img :src="mini(pg.imagen_url)" alt="" class="h-12 w-9 object-cover bg-[#222]" draggable="false" />
          </button>
        </div>
      </footer>
    </template>
  </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
