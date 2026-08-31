<script setup>
/**
 * Lo que el asesor tiene a mano mientras atiende: direcciones, horarios,
 * formas de pago, enlaces.
 *
 * Antes esto era una lista escrita aquí adentro —las cinco sedes, el envío
 * gratis en el Quindío—, así que sólo servía para una empresa. Ahora cada
 * negocio arma la suya desde Gestión y esta pantalla sólo la muestra.
 *
 * Los catálogos siguen viniendo del módulo de Redes: los mantiene el bot, no
 * tiene sentido copiarlos a mano en dos sitios.
 */
import { ref, computed, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import { useModulosStore } from '@/stores/modulos'
import { iconoPorNombre } from '@/constants/iconos'
import api from '@/api'
import {
  XMarkIcon, DocumentTextIcon, ClipboardDocumentIcon,
  ArrowTopRightOnSquareIcon, Squares2X2Icon,
} from '@heroicons/vue/24/outline'

defineEmits(['close'])
const toast   = useToast()
const modulos = useModulosStore()

const herramientas = ref([])
const cargando     = ref(true)

// Nombres legibles de cada catálogo
const NOMBRES_CAT = {
  bases_comedores: 'Bases de comedor', sillas_comedor: 'Sillas de comedor',
  sillas_auxiliares: 'Sillas auxiliares', sillas_barra: 'Sillas de barra',
  mesas_centro: 'Mesas de centro', mesas_auxiliares: 'Mesas auxiliares',
  mesas_noche: 'Mesas de noche', mesas_tv: 'Mesas de TV',
  sofas: 'Sofás', sofas_modulares: 'Sofás modulares', sofas_camas: 'Sofá camas',
  camas: 'Camas', colchones: 'Colchones', cajoneros_bifes: 'Cajoneros / Bifés',
  escritorios: 'Escritorios',
}

const catalogos = ref([])
const cargandoCat = ref(true)

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
  api.get('/herramientas')
    .then(({ data }) => { herramientas.value = Array.isArray(data) ? data : [] })
    .catch(() => { herramientas.value = [] })
    .finally(() => { cargando.value = false })

  try {
    const { data } = await api.get('/redes/catalogos')
    catalogos.value = Object.entries(data)
      // La promoción del 20% venció: no mostramos catálogos de descuento aunque
      // quedara alguno en la configuración.
      .filter(([key]) => !key.startsWith('descuento'))
      .map(([key, url]) => ({ key, url, nombre: NOMBRES_CAT[key] || key }))
      .sort((a, b) => a.nombre.localeCompare(b.nombre))
  } catch {
    catalogos.value = []
  } finally {
    cargandoCat.value = false
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
              <p class="text-xs text-gray-500 mt-1 whitespace-pre-line break-words">{{ h.contenido }}</p>

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

        <!-- Catálogos -->
        <section>
          <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 flex items-center gap-1.5">
            <DocumentTextIcon class="w-4 h-4" /> Catálogos (PDF)
          </h3>
          <div v-if="cargandoCat" class="text-xs text-gray-400 py-3 text-center">Cargando catálogos…</div>
          <div v-else-if="!catalogos.length" class="text-xs text-gray-400 py-3 text-center">No hay catálogos disponibles.</div>
          <div v-else class="grid grid-cols-2 gap-2">
            <div v-for="c in catalogos" :key="c.key" class="bg-white rounded-xl p-2.5 shadow-sm">
              <p class="text-xs font-semibold text-gray-700 mb-1.5 truncate">{{ c.nombre }}</p>
              <div class="flex gap-1.5">
                <button @click="copiar(c.url, 'Enlace copiado ✅')"
                  class="flex-1 flex items-center justify-center gap-1 text-[11px] font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg py-1">
                  <ClipboardDocumentIcon class="w-3.5 h-3.5" /> Enlace
                </button>
                <a :href="c.url" target="_blank" rel="noopener"
                  class="flex items-center justify-center text-gray-500 bg-gray-100 hover:bg-gray-200 rounded-lg px-2">
                  <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
                </a>
              </div>
            </div>
          </div>
        </section>

      </div>
    </div>
  </div>
</template>
