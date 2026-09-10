<script setup>
/**
 * La portada de los catálogos visuales: todas las categorías en una grilla.
 *
 * Pública, sin sesión. Cada tarjeta lleva al visor tipo revista de esa
 * categoría (/c/:slug).
 */
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { portadaCatalogos } from '@/api/catalogos'
import { cloudinaryOpt } from '@/utils/cloudinary'

const WHATSAPP = '573217770621'

const catalogos = ref([])
const cargando   = ref(true)

onMounted(async () => {
  try {
    const { data } = await portadaCatalogos()
    catalogos.value = data.catalogos ?? []
    document.title = 'Catálogos — Decasa Muebles'
  } catch {
    catalogos.value = []
  } finally {
    cargando.value = false
  }
})

const portada = (url) => cloudinaryOpt(url, 700)
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="sticky top-0 z-10 bg-white/95 backdrop-blur-sm border-b border-gray-200 px-4 py-3">
      <div class="max-w-4xl mx-auto flex items-center gap-3">
        <img src="/logo_192x192.png" alt="Decasa" class="w-9 h-9 rounded-lg" />
        <div class="min-w-0">
          <p class="font-bold text-gray-800 leading-tight">Catálogos</p>
          <p class="text-xs text-gray-400">Decasa Muebles</p>
        </div>
      </div>
    </header>

    <div v-if="cargando" class="flex justify-center py-20">
      <div class="w-7 h-7 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="!catalogos.length" class="max-w-md mx-auto text-center py-20 px-6">
      <p class="text-lg font-semibold text-gray-700">Todavía no hay catálogos publicados</p>
      <p class="text-sm text-gray-500 mt-1">Vuelve pronto o escríbenos y te contamos qué tenemos.</p>
      <a :href="`https://wa.me/${WHATSAPP}`" target="_blank"
        class="inline-block mt-5 bg-emerald-600 text-white text-sm font-semibold rounded-xl px-5 py-2.5">
        Escribirnos por WhatsApp
      </a>
    </div>

    <main v-else class="max-w-4xl mx-auto px-4 py-5 pb-12">
      <p class="text-sm text-gray-500 mb-4">Elige una categoría para verla como una revista.</p>

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
        <RouterLink
          v-for="c in catalogos" :key="c.slug"
          :to="{ name: 'catalogo-visor', params: { slug: c.slug } }"
          class="group block bg-white rounded-2xl overflow-hidden shadow-sm ring-1 ring-black/5 transition-transform duration-200 active:scale-[0.98] hover:-translate-y-0.5 hover:shadow-md"
        >
          <div class="aspect-[3/4] bg-gray-100 overflow-hidden relative">
            <img
              v-if="c.portada_url"
              :src="portada(c.portada_url)" :alt="c.nombre" loading="lazy"
              class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
            />
            <div v-else class="w-full h-full flex items-center justify-center text-gray-300 text-xs">Sin portada</div>
            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-3">
              <p class="text-white font-semibold text-sm leading-tight drop-shadow">{{ c.nombre }}</p>
              <p class="text-white/70 text-[11px]">{{ c.paginas }} página{{ c.paginas === 1 ? '' : 's' }}</p>
            </div>
          </div>
          <p v-if="c.descripcion" class="text-[11px] text-gray-500 px-3 py-2 line-clamp-2">{{ c.descripcion }}</p>
        </RouterLink>
      </div>

      <div class="mt-10 text-center">
        <a :href="`https://wa.me/${WHATSAPP}`" target="_blank"
          class="inline-block bg-emerald-600 text-white text-sm font-semibold rounded-xl px-6 py-3 shadow-sm hover:bg-emerald-700 transition-colors">
          Escribirnos por WhatsApp
        </a>
      </div>
    </main>
  </div>
</template>
