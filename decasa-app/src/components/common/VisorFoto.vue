<script setup>
/**
 * Visor único de fotos de toda la app: foto en grande + Descargar (JPG) + Compartir.
 * Se abre con useVisorFoto().abrirFoto(...) o tocando un enlace a una foto (App.vue).
 * Colores fijos sobre fondo negro (no grises/blancos) para que el modo oscuro no lo cambie.
 */
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import { XMarkIcon, ArrowDownTrayIcon, ShareIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import { useVisorFoto } from '@/composables/useVisorFoto'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { urlOriginal, prepararFoto, descargarFoto, compartirFoto } from '@/utils/fotos'

const { visor, cerrarFoto } = useVisorFoto()
const toast = useToast()

const descargando = ref(false)
const compartiendo = ref(false)

const actual = computed(() => visor.value?.fotos[visor.value.indice] ?? null)
const total = computed(() => visor.value?.fotos.length ?? 0)
// Nombre del archivo: "cama-bali-2.jpg" cuando hay varias.
const nombreArchivo = computed(() => {
  const n = visor.value?.nombre || ''
  return total.value > 1 ? `${n || 'foto'} ${visor.value.indice + 1}` : n
})
// Las fotos de Cloudinary se ven a 1600 px; las demás (base64, otros sitios) tal cual.
const src = computed(() => {
  const url = actual.value
  if (!url || url.startsWith('data:') || url.startsWith('blob:')) return url
  return cloudinaryOpt(urlOriginal(url), 1600)
})

// Se baja de una vez: así "Compartir" abre la hoja del celular en el mismo toque.
watch(actual, (url) => { if (url) prepararFoto(url).catch(() => {}) })

function mover(paso) {
  if (total.value < 2) return
  visor.value.indice = (visor.value.indice + paso + total.value) % total.value
}

async function descargar() {
  if (descargando.value) return
  descargando.value = true
  try {
    await descargarFoto(actual.value, nombreArchivo.value)
  } finally {
    descargando.value = false
  }
}

async function compartir() {
  if (compartiendo.value) return
  compartiendo.value = true
  try {
    const r = await compartirFoto(actual.value, nombreArchivo.value)
    if (r === 'copiada') toast.info('Link de la foto copiado')
  } catch {
    toast.error('No se pudo compartir la foto')
  } finally {
    compartiendo.value = false
  }
}

// Deslizar a los lados para pasar de foto en el celular.
let inicioX = null
function alTocar(e) { inicioX = e.touches[0].clientX }
function alSoltar(e) {
  if (inicioX === null) return
  const dx = e.changedTouches[0].clientX - inicioX
  inicioX = null
  if (Math.abs(dx) > 50) mover(dx < 0 ? 1 : -1)
}

function alTeclado(e) {
  if (!visor.value) return
  if (e.key === 'Escape') { e.stopPropagation(); cerrarFoto() }
  else if (e.key === 'ArrowRight') mover(1)
  else if (e.key === 'ArrowLeft') mover(-1)
}
onMounted(() => window.addEventListener('keydown', alTeclado, true))
onUnmounted(() => window.removeEventListener('keydown', alTeclado, true))
</script>

<template>
  <Teleport to="body">
    <Transition name="visor-foto">
      <div
        v-if="visor"
        class="fixed inset-0 z-[150] bg-black/95 flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-label="Foto"
        @touchstart.passive="alTocar"
        @touchend="alSoltar"
      >
        <!-- Barra de arriba -->
        <div class="flex items-center gap-3 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-2 text-white">
          <div class="flex-1 min-w-0">
            <p v-if="visor.nombre" class="text-sm font-semibold truncate">{{ visor.nombre }}</p>
            <p v-if="visor.detalle" class="text-xs text-white/60 truncate">{{ visor.detalle }}</p>
            <p v-if="total > 1" class="text-xs text-white/60">{{ visor.indice + 1 }} de {{ total }}</p>
          </div>
          <button
            type="button"
            class="w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center shrink-0"
            aria-label="Cerrar foto"
            @click="cerrarFoto"
          >
            <XMarkIcon class="w-6 h-6" />
          </button>
        </div>

        <!-- Foto (tocar afuera cierra) -->
        <div class="relative flex-1 min-h-0 flex items-center justify-center px-3" @click.self="cerrarFoto">
          <img
            :key="src"
            :src="src"
            :alt="visor.nombre || 'Foto'"
            class="max-w-full max-h-full object-contain rounded-lg select-none"
          />
          <template v-if="total > 1">
            <button
              type="button"
              class="absolute left-2 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/50 hover:bg-black/70 text-white flex items-center justify-center"
              aria-label="Foto anterior"
              @click="mover(-1)"
            >
              <ChevronLeftIcon class="w-6 h-6" />
            </button>
            <button
              type="button"
              class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/50 hover:bg-black/70 text-white flex items-center justify-center"
              aria-label="Foto siguiente"
              @click="mover(1)"
            >
              <ChevronRightIcon class="w-6 h-6" />
            </button>
          </template>
        </div>

        <!-- Descargar / Compartir -->
        <div class="flex justify-center gap-3 px-4 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <button
            type="button"
            class="flex-1 max-w-[11rem] flex items-center justify-center gap-2 rounded-xl bg-white/15 hover:bg-white/25 text-white text-sm font-semibold py-3 disabled:opacity-60"
            :disabled="descargando"
            @click="descargar"
          >
            <ArrowDownTrayIcon class="w-5 h-5" />
            {{ descargando ? 'Bajando…' : 'Descargar' }}
          </button>
          <button
            type="button"
            class="flex-1 max-w-[11rem] flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold py-3 disabled:opacity-60"
            :disabled="compartiendo"
            @click="compartir"
          >
            <ShareIcon class="w-5 h-5" />
            Compartir
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style>
.visor-foto-enter-active,
.visor-foto-leave-active { transition: opacity 0.15s ease; }
.visor-foto-enter-from,
.visor-foto-leave-to { opacity: 0; }
</style>
