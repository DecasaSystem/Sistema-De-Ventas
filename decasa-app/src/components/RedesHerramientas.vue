<script setup>
/**
 * Lo que el asesor tiene a mano mientras atiende: direcciones, horarios,
 * formas de pago, catálogos.
 *
 * Antes esto era una lista escrita aquí adentro —las cinco sedes, el envío
 * gratis en el Quindío— más los catálogos, que venían de otro lado y no se
 * podían editar desde ninguna pantalla. Ahora todo sale del mismo sitio y todo
 * se arma desde Gestión: esta pantalla sólo lo muestra.
 */
import { ref, computed, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import { useModulosStore } from '@/stores/modulos'
import { iconoPorNombre } from '@/constants/iconos'
import api from '@/api'
import {
  XMarkIcon, ClipboardDocumentIcon,
  ArrowTopRightOnSquareIcon, Squares2X2Icon,
} from '@heroicons/vue/24/outline'

defineEmits(['close'])
const toast   = useToast()
const modulos = useModulosStore()

const herramientas = ref([])
const cargando     = ref(true)

/** Agrupadas por sección, en el orden que les puso la empresa. */
const secciones = computed(() => {
  const mapa = new Map()
  for (const h of herramientas.value) {
    if (!mapa.has(h.seccion)) mapa.set(h.seccion, [])
    mapa.get(h.seccion).push(h)
  }
  return [...mapa.entries()].map(([nombre, items]) => ({ nombre, items }))
})

onMounted(async () => {
  try {
    const { data } = await api.get('/herramientas')
    herramientas.value = Array.isArray(data) ? data : []
  } catch {
    herramientas.value = []
  } finally {
    cargando.value = false
  }
})

async function copiar(texto, aviso = 'Copiado ✅') {
  try {
    await navigator.clipboard.writeText(texto)
    toast.success(aviso)
  } catch {
    toast.info?.('No se pudo copiar automáticamente')
  }
}

function mapsUrl(direccion) {
  return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(direccion)
}

/** A dónde lleva el botón de abrir, según lo que sea. */
function enlaceDe(h) {
  if (h.tipo === 'direccion') return mapsUrl(h.contenido)
  if (h.tipo === 'enlace')    return h.contenido
  return null
}

function iconoDe(h) {
  return iconoPorNombre(h.icono) ?? Squares2X2Icon
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="$emit('close')">
    <div class="bg-gray-50 w-full sm:max-w-lg sm:rounded-2xl rounded-t-2xl max-h-[90vh] flex flex-col shadow-xl">

      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-white sm:rounded-t-2xl">
        <h2 class="font-bold text-gray-800 flex items-center gap-2">
          🧰 {{ modulos.nombre('herramientas', 'Herramientas') }}
        </h2>
        <button @click="$emit('close')" class="p-1 text-gray-400 hover:text-gray-600">
          <XMarkIcon class="w-6 h-6" />
        </button>
      </div>

      <p class="text-xs text-gray-400 px-4 pt-2">Toca cualquier elemento para copiarlo y pégalo en el chat del cliente.</p>

      <div class="overflow-y-auto px-4 py-3 space-y-4">

        <div v-if="cargando" class="text-xs text-gray-400 py-3 text-center">Cargando…</div>

        <!-- Lo que armó la empresa -->
        <section v-for="s in secciones" :key="s.nombre">
          <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">{{ s.nombre }}</h3>
          <div class="space-y-2">
            <div v-for="h in s.items" :key="h.id" class="bg-white rounded-xl p-3 shadow-sm">
              <p class="text-sm font-semibold text-gray-800 flex items-center gap-1.5">
                <component :is="iconoDe(h)" class="w-4 h-4 text-gray-400 shrink-0" />
                {{ h.titulo }}
              </p>
              <p v-if="h.subtitulo" class="text-xs text-gray-400 mt-0.5">{{ h.subtitulo }}</p>
              <!-- Un enlace largo de Drive ocuparía tres líneas y no dice nada:
                   se recorta. Lo que se copia es la dirección entera igual. -->
              <p
                :class="['text-xs text-gray-500 mt-1 break-words',
                  h.tipo === 'enlace' ? 'truncate' : 'whitespace-pre-line']"
              >{{ h.contenido }}</p>

              <div class="flex gap-2 mt-2">
                <button @click="copiar(h.contenido, h.titulo + ' copiado ✅')"
                  class="flex-1 flex items-center justify-center gap-1 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg py-1.5">
                  <ClipboardDocumentIcon class="w-4 h-4" /> Copiar
                </button>
                <a v-if="enlaceDe(h)" :href="enlaceDe(h)" target="_blank" rel="noopener"
                  class="flex-1 flex items-center justify-center gap-1 text-xs font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg py-1.5">
                  <ArrowTopRightOnSquareIcon class="w-4 h-4" />
                  {{ h.tipo === 'direccion' ? 'Maps' : 'Abrir' }}
                </a>
              </div>
            </div>
          </div>
        </section>

        <p v-if="!cargando && !secciones.length" class="text-xs text-gray-400 text-center py-3">
          Todavía no hay nada aquí. Se arma desde Gestión → Herramientas.
        </p>

      </div>
    </div>
  </div>
</template>
