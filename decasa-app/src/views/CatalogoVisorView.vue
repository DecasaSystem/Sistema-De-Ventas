<script setup>
/**
 * El visor tipo revista de un catálogo visual. Público, sin sesión.
 *
 * Una página a la vez sobre fondo oscuro, con volteo de hoja en 3D: se arrastra
 * con el dedo, se pasa con las flechas o el teclado, o tocando los bordes.
 * Abajo hay una tira de miniaturas para saltar a cualquier página.
 *
 * Hecho para que ande liso en cualquier celular, también en uno malito:
 *
 *  - La animación mueve la hoja escribiendo su estilo directo, cuadro a
 *    cuadro. Antes el progreso era reactivo y cada cuadro volvía a pintar la
 *    pantalla entera —con la tira de miniaturas— 60 veces por segundo.
 *  - Las páginas vecinas ya están montadas (y bajadas) antes de voltear, cada
 *    una en su propio elemento: al terminar el volteo no se cambia ninguna
 *    imagen, solo cuál se ve.
 *  - Pasar rápido nunca traba: si llega otro toque con una hoja girando, esa
 *    hoja termina de una y arranca la siguiente. Antes se cancelaba a medias
 *    y la página no cambiaba ("solo pasa la animación").
 *  - Saltar lejos desde las miniaturas muestra al instante la miniatura (ya
 *    bajada) mientras llega la imagen grande.
 *  - La imagen grande se pide del tamaño de la pantalla, no siempre a 1600.
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

// Sin botones de WhatsApp en los catálogos públicos, a pedido: el link lo
// manda un asesor que ya está hablando con el cliente.

const nombre    = ref('')
const descrip   = ref('')
const paginas   = ref([])
const cargando  = ref(true)
const noExiste  = ref(false)

const i          = ref(0)              // página actual (0-based)
const dir        = ref(null)           // 'next' | 'prev' mientras se voltea (cambia 2 veces por volteo, no por cuadro)
const stageRef   = ref(null)
const esPantallaCompleta = ref(false)
const miniRef    = ref(null)

const total = computed(() => paginas.value.length)

// Del tamaño de la pantalla (hasta 2x de densidad): un iPhone no necesita
// 1600 px para una hoja que ocupa 390 de ancho. Se fija una vez para que un
// giro de pantalla no vuelva a bajar todo.
const ANCHO_IMG = (() => {
  if (typeof window === 'undefined') return 1200
  const dpr  = Math.min(window.devicePixelRatio || 1, 2)
  const base = Math.min(window.innerWidth, window.innerHeight * 0.75)
  return Math.min(1600, Math.max(600, Math.ceil((base * dpr) / 200) * 200))
})()
const grande = (url) => cloudinaryOpt(url, ANCHO_IMG)
const mini   = (url) => cloudinaryOpt(url, 120)

const reducirMovimiento = typeof window !== 'undefined'
  && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

// ── Tamaño de la hoja ────────────────────────────────────────────────────────
// Todas las páginas se apilan en la misma caja, del tamaño que cabe en el
// escenario con la proporción de la primera página.
const proporcion = ref(0.707)          // ancho / alto (A4 vertical hasta saber)
const caja = ref({ w: 0, h: 0 })

function medir() {
  const s = stageRef.value
  if (!s) return
  const cs = getComputedStyle(s)
  const W = s.clientWidth  - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight)
  const H = s.clientHeight - parseFloat(cs.paddingTop)  - parseFloat(cs.paddingBottom)
  const w = Math.max(0, Math.min(W, H * proporcion.value))
  caja.value = { w: Math.floor(w), h: Math.floor(w / proporcion.value) }
}

let ro = null
watch(stageRef, (el) => {
  ro?.disconnect()
  if (!el) return
  medir()
  if (typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(() => medir())
    ro.observe(el)
  }
})

// ── Carga ────────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const { data } = await catalogoPublico(route.params.slug)
    nombre.value  = data.nombre
    descrip.value = data.descripcion || ''
    paginas.value = data.paginas ?? []
    document.title = `${data.nombre} — Decasa Muebles`

    const primera = paginas.value[0]
    if (primera) {
      const img = new Image()
      img.onload = () => {
        if (img.naturalWidth && img.naturalHeight) {
          proporcion.value = img.naturalWidth / img.naturalHeight
          medir()
        }
      }
      img.src = grande(primera.imagen_url)
    }
  } catch {
    noExiste.value = true
  } finally {
    cargando.value = false
  }
  window.addEventListener('keydown', onTecla)
  window.addEventListener('resize', medir)
  document.addEventListener('fullscreenchange', onFsChange)
})
onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  cancelAnimationFrame(rafToque)
  ro?.disconnect()
  window.removeEventListener('keydown', onTecla)
  window.removeEventListener('resize', medir)
  document.removeEventListener('fullscreenchange', onFsChange)
})

// Se bajan las que siguen, para que al llegar ya estén. Una sola vez cada una.
const yaPedidas = new Set()
watch([i, total], () => {
  for (const idx of [i.value + 1, i.value - 1, i.value + 2, i.value + 3]) {
    if (idx < 0 || idx >= total.value || yaPedidas.has(idx)) continue
    yaPedidas.add(idx)
    const img = new Image()
    img.decoding = 'async'
    img.src = grande(paginas.value[idx].imagen_url)
  }
}, { immediate: true })

watch(i, () => scrollMiniActiva())

// ── Las páginas montadas ─────────────────────────────────────────────────────
// Solo alrededor de la actual: la anterior (para voltear atrás), la actual y
// dos adelante. Cada una conserva su elemento mientras esté en la ventana.
const ventana = computed(() => {
  const out = []
  for (let k = Math.max(0, i.value - 1); k <= Math.min(total.value - 1, i.value + 2); k++) out.push(k)
  return out
})

function rol(idx) {
  if (dir.value === 'next') return idx === i.value ? 'hoja' : idx === i.value + 1 ? 'fondo' : 'oculta'
  if (dir.value === 'prev') return idx === i.value - 1 ? 'hoja' : idx === i.value ? 'fondo' : 'oculta'
  return idx === i.value ? 'actual' : 'oculta'
}
const Z = { hoja: 3, actual: 2, fondo: 1, oculta: 0 }
function estiloPagina(idx) {
  const r = rol(idx)
  return { zIndex: Z[r], opacity: r === 'oculta' ? 0 : 1 }
}

const elPagina = new Map()
function registrar(idx, el) {
  if (el) elPagina.set(idx, el)
  else if (elPagina.get(idx) && !elPagina.get(idx).isConnected) elPagina.delete(idx)
}

// ── El volteo ────────────────────────────────────────────────────────────────
// La hoja que gira pivota sobre el borde izquierdo (el lomo). Todo lo que
// cambia cuadro a cuadro se escribe directo en el DOM.
let hoja = null       // { el, sombra, reverso, lomo }
let p = 0             // progreso [0..1]
let raf = null
let objetivo = null   // a dónde va la animación en curso (1 = pasa, 0 = vuelve)

function pintar() {
  if (!hoja) return
  const rot = dir.value === 'next' ? -180 * p : -180 * (1 - p)
  hoja.el.style.transform = `rotateY(${rot}deg)`
  // Se oscurece al ponerse de canto, como una página real
  hoja.sombra.style.opacity = String(Math.sin(p * Math.PI) * 0.22)
  // Pasados los ~90° se ve el reverso: se tapa con tono papel
  hoja.reverso.style.opacity = String(Math.min(Math.max((Math.abs(rot) - 78) / 55, 0), 0.96))
}

function empezar(d) {
  const el = elPagina.get(d === 'next' ? i.value : i.value - 1)
  dir.value = d
  p = 0
  if (!el) { hoja = null; return }
  hoja = {
    el,
    sombra:  el.querySelector('[data-sombra]'),
    reverso: el.querySelector('[data-reverso]'),
    lomo:    el.querySelector('[data-lomo]'),
  }
  el.style.willChange = 'transform'
  hoja.lomo.style.opacity = '1'
  pintar()   // antes de que se pinte el cuadro: la anterior arranca ya volteada
}

function terminar(pasa) {
  cancelAnimationFrame(raf); raf = null
  objetivo = null
  if (hoja) {
    hoja.el.style.transform = ''
    hoja.el.style.willChange = ''
    hoja.sombra.style.opacity = '0'
    hoja.reverso.style.opacity = '0'
    hoja.lomo.style.opacity = '0'
    hoja = null
  }
  if (pasa && dir.value) i.value += dir.value === 'next' ? 1 : -1
  dir.value = null
  p = 0
}

/** Si hay una hoja girando, que llegue ya a donde iba. */
function adelantar() {
  if (!dir.value) return
  terminar(objetivo === null ? false : objetivo === 1)
}

function animarHacia(obj) {
  cancelAnimationFrame(raf)
  objetivo = obj
  const desde = p
  // Lo que falta, no un tiempo fijo: soltar casi al final no espera medio segundo
  const dur = reducirMovimiento ? 0 : Math.max(140, 460 * Math.abs(obj - desde))
  const t0 = performance.now()
  const ease = (x) => 1 - Math.pow(1 - x, 3)   // easeOutCubic
  const paso = (ahora) => {
    const k = dur ? Math.min((ahora - t0) / dur, 1) : 1
    p = desde + (obj - desde) * ease(k)
    pintar()
    if (k < 1) raf = requestAnimationFrame(paso)
    else terminar(obj === 1)
  }
  raf = requestAnimationFrame(paso)
}

// ── Navegación ───────────────────────────────────────────────────────────────
function puede(d) {
  return d === 'next' ? i.value < total.value - 1 : i.value > 0
}

async function voltear(d) {
  adelantar()
  if (!puede(d)) return
  // Tras adelantar, la ventana de páginas se corre: esperar a que esté montada
  await nextTick()
  if (dir.value || !puede(d)) return
  empezar(d)
  animarHacia(1)
}

function irA(idx) {
  adelantar()
  if (idx === i.value || idx < 0 || idx >= total.value) return
  i.value = idx
}

function onTecla(e) {
  if (e.key === 'ArrowRight' || e.key === 'PageDown') voltear('next')
  else if (e.key === 'ArrowLeft' || e.key === 'PageUp') voltear('prev')
  else if (e.key === 'Escape' && esPantallaCompleta.value) salirPantallaCompleta()
}

// ── Arrastre con el dedo ─────────────────────────────────────────────────────
let x0 = 0, y0 = 0, t0 = 0, ancho = 1, arrastrando = false, movido = false
let horizontal = null      // se decide en los primeros píxeles
let rafToque = null

function onTouchStart(e) {
  adelantar()
  const t = e.touches[0]
  x0 = t.clientX; y0 = t.clientY
  t0 = Date.now()
  ancho = stageRef.value?.clientWidth || window.innerWidth
  arrastrando = true
  movido = false
  horizontal = null
}
function onTouchMove(e) {
  if (!arrastrando) return
  const t  = e.touches[0]
  const dx = t.clientX - x0
  const dy = t.clientY - y0
  if (horizontal === null) {
    if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return
    horizontal = Math.abs(dx) > Math.abs(dy)
  }
  if (!horizontal) return
  movido = true

  const d = dx < 0 ? 'next' : 'prev'
  if (dir.value && dir.value !== d) terminar(false)   // cambió de sentido a mitad
  if (!dir.value) {
    if (!puede(d)) return
    empezar(d)
  }
  p = Math.min(Math.abs(dx) / ancho, 1)
  // Un pintado por cuadro, aunque el dedo mande más eventos
  if (!rafToque) rafToque = requestAnimationFrame(() => { rafToque = null; pintar() })
}
function onTouchEnd() {
  if (!arrastrando) return
  arrastrando = false
  cancelAnimationFrame(rafToque); rafToque = null
  if (!dir.value) return
  const rapido = (Date.now() - t0) < 260 && p > 0.05
  animarHacia((p > 0.35 || rapido) ? 1 : 0)
}

// Toque o clic en los lados. Se ignora si venía de un arrastre.
function onClickStage(e) {
  if (movido) { movido = false; return }
  const r = stageRef.value.getBoundingClientRect()
  const rel = (e.clientX - r.left) / r.width
  if (rel > 0.6) voltear('next')
  else if (rel < 0.4) voltear('prev')
}

// ── Pantalla completa ────────────────────────────────────────────────────────
function togglePantallaCompleta() {
  if (document.fullscreenElement) salirPantallaCompleta()
  else stageRef.value?.parentElement?.requestFullscreen?.().catch(() => {})
}
function salirPantallaCompleta() { document.exitFullscreen?.().catch(() => {}) }
function onFsChange() { esPantallaCompleta.value = !!document.fullscreenElement }

// ── Compartir ────────────────────────────────────────────────────────────────
// En el celular, el menú de compartir del sistema (WhatsApp directo). En el
// computador se copia el link: el menú de Windows se abre y se cierra solo,
// y para entonces el navegador ya no deja copiar, así que no pasaba nada.
const linkCopiado = ref(false)
const esCelular = typeof navigator !== 'undefined' && /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)

async function compartir() {
  const url = window.location.href
  if (esCelular && navigator.share) {
    try { await navigator.share({ title: nombre.value, url }) } catch { /* cancelado */ }
    return
  }
  let ok = false
  try {
    await navigator.clipboard.writeText(url)
    ok = true
  } catch {
    const area = document.createElement('textarea')
    area.value = url
    area.style.position = 'fixed'
    area.style.opacity = '0'
    document.body.appendChild(area)
    area.select()
    try { ok = document.execCommand('copy') } catch { ok = false }
    document.body.removeChild(area)
  }
  if (ok) {
    linkCopiado.value = true
    setTimeout(() => { linkCopiado.value = false }, 2000)
  } else {
    window.prompt('Copia el link del catálogo:', url)
  }
}

async function scrollMiniActiva() {
  await nextTick()
  const cont = miniRef.value
  const el   = cont?.children?.[i.value]
  // Sin 'smooth': al saltar de la primera a la última, la tira animando un
  // recorrido largo competía con la página que se está mostrando.
  if (cont && el) cont.scrollLeft = el.offsetLeft - cont.clientWidth / 2 + el.clientWidth / 2
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
      </div>
    </div>

    <template v-else>
      <!-- Barra superior. Sin desenfoque de fondo: encima de una hoja que gira,
           el celular tenía que recalcularlo en cada cuadro. -->
      <header class="absolute top-0 inset-x-0 z-20 flex items-center gap-2 px-3 py-2.5 bg-gradient-to-b from-black/60 to-transparent">
        <button
          @click="router.push({ name: 'catalogos-portada' })"
          class="w-9 h-9 rounded-full bg-black/50 flex items-center justify-center text-white hover:bg-black/70"
          aria-label="Cerrar"
        >
          <XMarkIcon class="w-5 h-5" />
        </button>
        <div class="flex-1 min-w-0">
          <p class="text-white font-semibold text-sm leading-tight truncate">{{ nombre }}</p>
          <p v-if="descrip" class="text-white/50 text-[11px] truncate">{{ descrip }}</p>
        </div>
        <button
          @click="compartir"
          class="relative w-9 h-9 rounded-full bg-black/50 flex items-center justify-center text-white hover:bg-black/70"
          :title="esCelular ? 'Compartir' : 'Copiar link'"
          :aria-label="esCelular ? 'Compartir' : 'Copiar link'"
        >
          <ShareIcon class="w-5 h-5" />
          <span v-if="linkCopiado"
                class="absolute top-full mt-1 right-0 whitespace-nowrap text-[11px] bg-black/70 text-white rounded px-2 py-0.5">
            ¡Link copiado!
          </span>
        </button>
        <button
          @click="togglePantallaCompleta"
          class="w-9 h-9 rounded-full bg-black/50 items-center justify-center text-white hover:bg-black/70 hidden sm:flex"
          title="Pantalla completa"
          aria-label="Pantalla completa"
        >
          <component :is="esPantallaCompleta ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="w-5 h-5" />
        </button>
      </header>

      <!-- Escenario -->
      <div class="flex-1 relative overflow-hidden">
        <div
          ref="stageRef"
          class="absolute inset-0 flex items-center justify-center px-2 sm:px-16 py-14"
          style="touch-action: pan-y;"
          @click="onClickStage"
          @touchstart.passive="onTouchStart"
          @touchmove.passive="onTouchMove"
          @touchend="onTouchEnd"
          @touchcancel="onTouchEnd"
        >
          <div
            class="relative pointer-events-none"
            :style="{ width: caja.w + 'px', height: caja.h + 'px', perspective: '2200px' }"
          >
            <div
              v-for="idx in ventana"
              :key="idx"
              :ref="(el) => registrar(idx, el)"
              class="absolute inset-0"
              :style="[estiloPagina(idx), { transformOrigin: 'left center' }]"
            >
              <!-- La miniatura (ya bajada) mientras llega la grande -->
              <div
                class="absolute inset-0 bg-center bg-no-repeat bg-contain"
                :style="{ backgroundImage: `url(${mini(paginas[idx].imagen_url)})` }"
              />
              <img
                :src="grande(paginas[idx].imagen_url)"
                alt=""
                class="absolute inset-0 w-full h-full object-contain"
                decoding="async"
                draggable="false"
              />
              <div data-sombra class="absolute inset-0 bg-black" style="opacity: 0" />
              <div data-reverso class="absolute inset-0" style="background: #e9e7e1; opacity: 0" />
              <div
                data-lomo
                class="absolute inset-y-0 left-0 w-16"
                style="background: linear-gradient(to right, rgba(0,0,0,0.28), transparent); opacity: 0"
              />
            </div>
          </div>
        </div>

        <!-- Flechas (desktop) -->
        <button
          v-if="i > 0"
          @click="voltear('prev')"
          class="absolute left-2 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-black/50 items-center justify-center text-white hover:bg-black/70 hidden sm:flex"
          aria-label="Página anterior"
        >
          <ChevronLeftIcon class="w-6 h-6" />
        </button>
        <button
          v-if="i < total - 1"
          @click="voltear('next')"
          class="absolute right-2 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-black/50 items-center justify-center text-white hover:bg-black/70 hidden sm:flex"
          aria-label="Página siguiente"
        >
          <ChevronRightIcon class="w-6 h-6" />
        </button>

        <!-- Nota de la página -->
        <div
          v-if="notaActual"
          class="absolute left-1/2 -translate-x-1/2 bottom-2 z-10 max-w-[90%] text-center pointer-events-none"
        >
          <span class="inline-block text-xs text-white/90 bg-black/60 rounded-full px-3 py-1">
            {{ notaActual }}
          </span>
        </div>
      </div>

      <!-- Pie: contador + miniaturas -->
      <footer class="relative z-20 bg-black/40 border-t border-white/10">
        <!-- El "Me interesa →" a WhatsApp se quitó a pedido: el catálogo lo
             manda un asesor, que ya está hablando con el cliente. -->
        <div class="flex items-center justify-center px-3 pt-1.5">
          <span class="text-[11px] text-white/60 font-medium tabular-nums">{{ i + 1 }} / {{ total }}</span>
        </div>
        <div
          v-if="total > 1"
          ref="miniRef"
          class="flex gap-1.5 overflow-x-auto px-3 py-2 no-scrollbar"
        >
          <button
            v-for="(pg, idx) in paginas" :key="idx"
            @click="irA(idx)"
            class="shrink-0 rounded overflow-hidden ring-2"
            :class="idx === i ? 'ring-emerald-400' : 'ring-transparent opacity-50'"
            :aria-label="`Ir a la página ${idx + 1}`"
          >
            <img
              :src="mini(pg.imagen_url)"
              alt=""
              class="h-12 w-9 object-cover bg-[#222]"
              loading="lazy"
              decoding="async"
              width="36"
              height="48"
              draggable="false"
            />
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
