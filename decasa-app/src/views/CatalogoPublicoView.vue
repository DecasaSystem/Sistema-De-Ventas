<script setup>
/**
 * La página que abre el cliente cuando le mandas el link.
 *
 * No pide contraseña ni usa nada de la sesión: la puede abrir cualquiera desde
 * WhatsApp. Muestra solo una sección y no tiene por dónde navegar al resto del
 * inventario, que es justamente lo que se quería al compartirla.
 *
 * Es lo único del sistema que ve un cliente, así que tiene su propio aire —de
 * showroom, no de programa—: fondo marfil, letra serif en los títulos y el
 * mueble como protagonista. Al tocar un producto se abre su ficha con las dos
 * fotos, medidas, material y descripción, que antes no se veían.
 */
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api'
import { cloudinaryOpt } from '@/utils/cloudinary'

const route  = useRoute()
const router = useRouter()

const seccion   = ref('')
const productos = ref([])
const cargando  = ref(true)
const noExiste  = ref(false)

// Los botones de WhatsApp ("Preguntar por este" y "Escribirnos por WhatsApp")
// se quitaron por ahora, a pedido: el link lo manda un asesor, que ya está
// hablando con el cliente. Estaban en el commit 05cd107 si se quieren volver.

// Las fuentes del catálogo solo se cargan aquí: el resto del sistema no las usa.
function cargarFuentes() {
  if (document.getElementById('fuentes-catalogo')) return
  const link = document.createElement('link')
  link.id   = 'fuentes-catalogo'
  link.rel  = 'stylesheet'
  link.href = 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Manrope:wght@400;500;600;700&display=swap'
  document.head.appendChild(link)
}

onMounted(async () => {
  cargarFuentes()
  try {
    const { data } = await api.get(`/catalogo/${encodeURIComponent(route.params.seccion)}`)
    seccion.value   = data.seccion
    productos.value = data.productos ?? []
    document.title  = `${data.seccion} — Decasa`
  } catch {
    noExiste.value = true
  } finally {
    cargando.value = false
  }
})

const precio = (v) => '$' + Number(v || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 })

// Lo que tiene foto va primero: una tarjeta sin foto no vende nada. El primero
// con foto va destacado, a todo lo ancho.
const ordenados  = computed(() => [
  ...productos.value.filter(p => p.foto_url),
  ...productos.value.filter(p => !p.foto_url),
])
const destacado  = computed(() => (ordenados.value[0]?.foto_url ? ordenados.value[0] : null))
const resto      = computed(() => (destacado.value ? ordenados.value.slice(1) : ordenados.value))
const fotosDe    = (p) => [p?.foto_url, p?.foto_url_2].filter(Boolean)
const detalleDe  = (p) => [p.medidas, p.material].filter(Boolean).join(' · ')

// ── Ficha del producto ───────────────────────────────────────────────────────
// Vive en la dirección (?p=<id>): el botón "atrás" del celular la cierra en
// vez de sacar al cliente de la página, y el asesor puede mandar el enlace de
// un producto suelto.
const abierto = computed(() => {
  const id = Number(route.query.p)
  return id ? (productos.value.find(p => p.id === id) ?? null) : null
})
const fotoIdx = ref(0)
// Si llegó a la ficha desde la lista, "volver" es ir atrás; si abrió directo
// el enlace de un producto, atrás sería salir de la página.
let vinoDeLaLista = false

function abrir(p) {
  vinoDeLaLista = true
  fotoIdx.value = 0
  router.push({ query: { ...route.query, p: p.id } })
  window.scrollTo({ top: 0 })
}
function cerrar() {
  if (vinoDeLaLista) router.back()
  else router.replace({ query: { ...route.query, p: undefined } })
  vinoDeLaLista = false
}
// Al cerrar la ficha (con el botón o con el "atrás" del celular) no puede
// quedar una foto ampliada encima de la lista.
watch(() => route.query.p, () => { fotoAmpliada.value = null; fotoIdx.value = 0 })

// Foto a pantalla completa: en el celular la miniatura no deja ver el mueble.
const fotoAmpliada = ref(null)
</script>

<template>
  <div class="catalogo min-h-screen bg-[#F6F1E9] text-[#231C17]">

    <!-- ── Encabezado ─────────────────────────────────────────────────── -->
    <header class="sticky top-0 z-20 bg-[#F6F1E9]/95 backdrop-blur-sm border-b border-[#E4DACB]">
      <div class="max-w-6xl mx-auto flex items-center justify-between gap-3 px-5 sm:px-10 min-h-16">
        <button v-if="abierto" type="button" @click="cerrar"
          class="-ml-3 flex items-center gap-1.5 min-h-11 px-3 text-[15px] font-semibold">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
          {{ seccion }}
        </button>
        <div v-else class="flex flex-col sm:flex-row sm:items-baseline sm:gap-3 leading-none">
          <span class="titulo text-[22px] sm:text-[28px] font-semibold">Decasa</span>
          <span class="text-[10px] sm:text-[11px] tracking-[0.24em] uppercase text-[#6E6158] mt-0.5 sm:mt-0">Muebles</span>
        </div>
        <span v-if="abierto" class="titulo text-xl font-semibold">Decasa</span>
        <span v-else class="text-xs font-semibold text-[#6E6158] border border-[#D9CCBA] rounded-full px-3 py-1.5">Catálogo</span>
      </div>
    </header>

    <!-- ── Cargando ───────────────────────────────────────────────────── -->
    <div v-if="cargando" class="flex justify-center py-24">
      <div class="w-7 h-7 border-2 border-[#9A5A30] border-t-transparent rounded-full animate-spin" />
    </div>

    <!-- ── Enlace que ya no sirve ─────────────────────────────────────── -->
    <div v-else-if="noExiste" class="max-w-sm mx-auto flex flex-col items-center text-center gap-4 px-9 py-24">
      <div class="w-22 h-22 rounded-full bg-[#EDE4D6] flex items-center justify-center text-[#9A5A30]">
        <svg class="w-10 h-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 17H7A5 5 0 0 1 7 7h2M15 7h2a5 5 0 0 1 4 8M8 12h4M3 3l18 18" /></svg>
      </div>
      <h1 class="titulo text-[28px] font-medium leading-tight">Esta sección no está disponible</h1>
      <p class="text-[15px] leading-relaxed text-[#6E6158]">
        Puede que el enlace esté incompleto o que ya no tengamos esos productos. Pídele a tu asesor un enlace nuevo.
      </p>
    </div>

    <!-- ── Ficha de un producto ───────────────────────────────────────── -->
    <main v-else-if="abierto" class="max-w-5xl mx-auto sm:px-10 sm:py-10 pb-10">
      <div class="sm:grid sm:grid-cols-2 sm:gap-12">
        <div>
          <button v-if="fotosDe(abierto).length" type="button"
            @click="fotoAmpliada = fotosDe(abierto)[fotoIdx]"
            class="relative block w-full aspect-[4/5] bg-[#E7DDCF] sm:rounded-[20px] overflow-hidden"
            :aria-label="`Ampliar la foto de ${abierto.nombre}`">
            <img :src="cloudinaryOpt(fotosDe(abierto)[fotoIdx], 900)" :alt="abierto.nombre" class="w-full h-full object-cover" />
            <span class="absolute bottom-3.5 left-3.5 flex items-center gap-1.5 text-xs font-semibold bg-[#F6F1E9] rounded-full px-3 py-1.5">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" /></svg>
              Ampliar
            </span>
            <span v-if="fotosDe(abierto).length > 1"
              class="absolute bottom-3.5 right-3.5 text-xs font-semibold text-white bg-[#231C17] rounded-full px-3 py-1.5">
              {{ fotoIdx + 1 }} / {{ fotosDe(abierto).length }}
            </span>
          </button>
          <div v-else class="w-full aspect-[4/5] bg-[#E7DDCF] sm:rounded-[20px] flex flex-col items-center justify-center gap-2 text-[#A8977F]">
            <svg class="w-16 h-16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 11V8a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v3" /><path d="M3 13a2 2 0 0 1 4 0v2h10v-2a2 2 0 0 1 4 0v4H3z" /><path d="M5 17v2M19 17v2" /></svg>
            <span class="text-[11px] tracking-[0.18em] uppercase text-[#8C7B69]">Sin foto por ahora</span>
          </div>

          <div v-if="fotosDe(abierto).length > 1" class="flex gap-2.5 px-5 sm:px-0 pt-3">
            <button v-for="(f, i) in fotosDe(abierto)" :key="f" type="button" @click="fotoIdx = i"
              :aria-label="`Ver foto ${i + 1}`"
              :class="['w-16 h-20 rounded-xl overflow-hidden bg-[#E7DDCF]',
                i === fotoIdx ? 'ring-2 ring-[#231C17] ring-offset-2 ring-offset-[#F6F1E9]' : 'opacity-70']">
              <img :src="cloudinaryOpt(f, 160)" alt="" class="w-full h-full object-cover" />
            </button>
          </div>
        </div>

        <div class="px-5 sm:px-0 pt-6 sm:pt-2 flex flex-col">
          <span class="text-[11px] font-bold tracking-[0.22em] uppercase text-[#9A5A30]">{{ seccion }}</span>
          <h1 class="titulo mt-2 text-[32px] sm:text-[40px] font-medium leading-[1.1]">{{ abierto.nombre }}</h1>
          <p class="mt-3 text-2xl font-bold">{{ precio(abierto.precio) }}</p>

          <dl v-if="abierto.medidas || abierto.material" class="mt-6">
            <div v-if="abierto.medidas" class="flex justify-between gap-4 py-3.5 border-t border-[#E4DACB]">
              <dt class="text-[13px] text-[#6E6158]">Medidas</dt>
              <dd class="text-sm font-semibold text-right">{{ abierto.medidas }}</dd>
            </div>
            <div v-if="abierto.material" class="flex justify-between gap-4 py-3.5 border-t border-[#E4DACB]">
              <dt class="text-[13px] text-[#6E6158]">Material</dt>
              <dd class="text-sm font-semibold text-right">{{ abierto.material }}</dd>
            </div>
            <div class="border-t border-[#E4DACB]" />
          </dl>

          <section v-if="abierto.descripcion" class="mt-6 flex flex-col gap-2">
            <span class="text-[11px] font-bold tracking-[0.22em] uppercase text-[#6E6158]">Descripción</span>
            <p class="text-[15px] leading-relaxed whitespace-pre-line">{{ abierto.descripcion }}</p>
          </section>

          <div class="mt-7 p-4 rounded-2xl bg-[#EDE4D6] flex gap-3 items-start">
            <svg class="w-[22px] h-[22px] flex-shrink-0 mt-px text-[#9A5A30]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z" /></svg>
            <p class="text-sm leading-relaxed">¿Te gustó? Cuéntaselo a tu asesor en el mismo chat donde te envió este enlace.</p>
          </div>

          <p class="mt-8 text-xs leading-relaxed text-[#6E6158] text-center sm:text-left">
            Los precios pueden cambiar sin previo aviso. Consulta la disponibilidad con tu asesor antes de comprar.
          </p>
        </div>
      </div>
    </main>

    <!-- ── La sección ─────────────────────────────────────────────────── -->
    <main v-else class="max-w-6xl mx-auto pb-12">
      <section class="px-5 sm:px-10 pt-8 sm:pt-16 pb-6 sm:pb-10 sm:flex sm:justify-between sm:items-end sm:gap-10">
        <div class="flex flex-col gap-2.5 sm:gap-3.5">
          <span class="text-[11px] sm:text-xs font-bold tracking-[0.22em] uppercase text-[#9A5A30]">Colección</span>
          <h1 class="titulo text-5xl sm:text-[88px] font-medium leading-none tracking-tight">{{ seccion }}</h1>
        </div>
        <p class="mt-2.5 sm:mt-0 text-sm sm:text-base leading-relaxed text-[#6E6158] sm:text-right sm:max-w-xs">
          {{ productos.length }} pieza{{ productos.length === 1 ? '' : 's' }} · Toca una para ver sus fotos, medidas y material.
        </p>
      </section>

      <div class="hidden sm:block mx-10 mb-10 h-px bg-[#E4DACB]" />

      <!-- Destacado a todo lo ancho, solo en el celular: en pantalla grande
           va en la cuadrícula con su etiqueta. -->
      <button v-if="destacado" type="button" @click="abrir(destacado)"
        class="sm:hidden mx-5 w-[calc(100%-2.5rem)] flex flex-col gap-3.5 text-left">
        <div class="relative w-full aspect-[4/5] rounded-[20px] overflow-hidden bg-[#E7DDCF]">
          <img :src="cloudinaryOpt(destacado.foto_url, 800)" :alt="destacado.nombre" class="w-full h-full object-cover" />
          <span class="absolute top-3.5 left-3.5 text-[11px] font-bold tracking-[0.08em] uppercase text-white bg-[#9A5A30] rounded-full px-3 py-1.5">Destacado</span>
          <span v-if="destacado.foto_url_2" class="absolute top-3.5 right-3.5 text-[11px] font-semibold bg-[#F6F1E9] rounded-full px-2.5 py-1.5">2 fotos</span>
        </div>
        <div class="w-full flex justify-between items-end gap-3">
          <div class="min-w-0 flex flex-col gap-1">
            <span class="titulo text-[22px] font-medium leading-tight">{{ destacado.nombre }}</span>
            <span v-if="detalleDe(destacado)" class="text-[13px] text-[#6E6158]">{{ detalleDe(destacado) }}</span>
          </div>
          <span class="text-[17px] font-bold whitespace-nowrap">{{ precio(destacado.precio) }}</span>
        </div>
      </button>

      <div v-if="destacado && resto.length" class="sm:hidden mx-5 mt-10 mb-4.5 flex items-center gap-3">
        <span class="text-[11px] font-bold tracking-[0.22em] uppercase text-[#6E6158] whitespace-nowrap">Toda la colección</span>
        <div class="flex-1 h-px bg-[#E4DACB]" />
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-3 gap-y-6 sm:gap-x-7 sm:gap-y-12 px-5 sm:px-10">
        <button v-for="(p, i) in [...(destacado ? [destacado] : []), ...resto]" :key="p.id" type="button" @click="abrir(p)"
          :class="['flex-col gap-2.5 sm:gap-4 text-left', destacado && i === 0 ? 'hidden sm:flex' : 'flex']">
          <div class="relative w-full aspect-[4/5] rounded-2xl sm:rounded-[20px] overflow-hidden bg-[#E7DDCF]">
            <img v-if="p.foto_url" :src="cloudinaryOpt(p.foto_url, 600)" :alt="p.nombre" loading="lazy" class="w-full h-full object-cover" />
            <div v-else class="w-full h-full flex flex-col items-center justify-center gap-1.5 text-[#A8977F]">
              <svg class="w-10 h-10 sm:w-14 sm:h-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 11V8a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v3" /><path d="M3 13a2 2 0 0 1 4 0v2h10v-2a2 2 0 0 1 4 0v4H3z" /><path d="M5 17v2M19 17v2" /></svg>
              <span class="text-[10px] tracking-[0.16em] uppercase text-[#8C7B69]">Sin foto</span>
            </div>
            <span v-if="destacado && i === 0"
              class="absolute top-4 left-4 text-[11px] font-bold tracking-[0.08em] uppercase text-white bg-[#9A5A30] rounded-full px-3 py-1.5">Destacado</span>
          </div>
          <div class="w-full flex flex-col gap-0.5 sm:flex-row sm:justify-between sm:items-start sm:gap-4">
            <div class="min-w-0 flex flex-col gap-0.5 sm:gap-1">
              <span class="text-[15px] font-semibold leading-snug sm:titulo sm:text-[22px] sm:font-medium sm:leading-tight">{{ p.nombre }}</span>
              <span v-if="detalleDe(p)" class="text-xs sm:text-sm text-[#6E6158]">{{ detalleDe(p) }}</span>
            </div>
            <span class="text-[15px] sm:text-lg font-bold whitespace-nowrap mt-0.5 sm:mt-0">{{ precio(p.precio) }}</span>
          </div>
        </button>
      </div>

      <footer class="mt-12 mx-5 sm:mx-10 pt-9 border-t border-[#E4DACB] flex flex-col sm:flex-row items-center sm:justify-between gap-3.5 text-center sm:text-right">
        <span class="titulo text-lg sm:text-xl font-semibold">Decasa · Muebles</span>
        <p class="text-xs sm:text-[13px] leading-relaxed text-[#6E6158] max-w-xs sm:max-w-md">
          Los precios pueden cambiar sin previo aviso. Consulta la disponibilidad con tu asesor antes de comprar.
        </p>
      </footer>
    </main>

    <!-- ── Foto a pantalla completa ───────────────────────────────────── -->
    <div v-if="fotoAmpliada" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4"
      @click="fotoAmpliada = null">
      <button type="button" @click.stop="fotoAmpliada = null" aria-label="Cerrar foto"
        class="absolute top-4 right-4 w-11 h-11 rounded-full bg-white/15 text-white flex items-center justify-center">
        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>
      <img :src="cloudinaryOpt(fotoAmpliada, 1400)" class="max-w-full max-h-full object-contain rounded-lg" alt="" />
    </div>
  </div>
</template>

<style scoped>
.catalogo { font-family: Manrope, system-ui, sans-serif; }
.titulo { font-family: Fraunces, Georgia, serif; }
@media (min-width: 640px) {
  .sm\:titulo { font-family: Fraunces, Georgia, serif; }
}
</style>
