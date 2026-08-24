<script setup>
/**
 * La página que abre el cliente cuando le mandas el link.
 *
 * No pide contraseña ni usa nada de la sesión: la puede abrir cualquiera desde
 * WhatsApp. Muestra solo una sección y no tiene por dónde navegar al resto del
 * inventario, que es justamente lo que se quería al compartirla.
 */
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api'
import { cloudinaryOpt } from '@/utils/cloudinary'

const route = useRoute()

const seccion   = ref('')
const productos = ref([])
const cargando  = ref(true)
const noExiste  = ref(false)

// Se abre a pantalla completa al tocarla: en el celular la miniatura no deja
// ver el mueble, que es lo único que el cliente vino a hacer.
const fotoAbierta = ref(null)

const WHATSAPP = '573217770621'

onMounted(async () => {
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

function consultar(p) {
  const texto = `Hola, me interesa: ${p.nombre} (${precio(p.precio)})`
  window.open(`https://wa.me/${WHATSAPP}?text=${encodeURIComponent(texto)}`, '_blank')
}

const conFoto = computed(() => productos.value.filter(p => p.foto_url))
const sinFoto = computed(() => productos.value.filter(p => !p.foto_url))
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="sticky top-0 z-10 bg-white/95 backdrop-blur-sm border-b border-gray-200 px-4 py-3">
      <div class="max-w-4xl mx-auto flex items-center gap-3">
        <img src="/logo_192x192.png" alt="Decasa" class="w-9 h-9 rounded-lg" />
        <div class="min-w-0">
          <p class="font-bold text-gray-800 leading-tight truncate">{{ seccion || 'Catálogo' }}</p>
          <p class="text-xs text-gray-400">Decasa Muebles</p>
        </div>
      </div>
    </header>

    <div v-if="cargando" class="flex justify-center py-20">
      <div class="w-7 h-7 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="noExiste" class="max-w-md mx-auto text-center py-20 px-6">
      <p class="text-lg font-semibold text-gray-700">Esta sección no está disponible</p>
      <p class="text-sm text-gray-500 mt-1">Puede que el enlace esté mal o que ya no tengamos esos productos.</p>
      <a :href="`https://wa.me/${WHATSAPP}`" target="_blank"
        class="inline-block mt-5 bg-emerald-600 text-white text-sm font-semibold rounded-xl px-5 py-2.5">
        Escribirnos por WhatsApp
      </a>
    </div>

    <main v-else class="max-w-4xl mx-auto px-4 py-4 pb-10">
      <p class="text-xs text-gray-400 mb-3">
        {{ productos.length }} producto{{ productos.length === 1 ? '' : 's' }}
      </p>

      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <article
          v-for="p in [...conFoto, ...sinFoto]" :key="p.id"
          class="bg-white rounded-2xl overflow-hidden shadow-sm flex flex-col"
        >
          <button
            v-if="p.foto_url" type="button" @click="fotoAbierta = p.foto_url"
            class="aspect-square bg-gray-100 overflow-hidden"
          >
            <img :src="cloudinaryOpt(p.foto_url, 600)" :alt="p.nombre" loading="lazy"
              class="w-full h-full object-cover" />
          </button>
          <div v-else class="aspect-square bg-gray-100 flex items-center justify-center">
            <span class="text-xs text-gray-300">Sin foto</span>
          </div>

          <div class="p-3 flex flex-col flex-1 gap-1">
            <p class="text-sm font-semibold text-gray-800 leading-snug">{{ p.nombre }}</p>
            <p v-if="p.medidas" class="text-[11px] text-gray-400">{{ p.medidas }}</p>
            <p v-if="p.material" class="text-[11px] text-gray-400">{{ p.material }}</p>
            <p class="text-base font-bold text-gray-900 mt-1">{{ precio(p.precio) }}</p>
            <button
              @click="consultar(p)"
              class="mt-auto pt-2 text-xs font-semibold text-emerald-700 hover:text-emerald-800 text-left"
            >
              Preguntar por este →
            </button>
          </div>
        </article>
      </div>

      <div class="mt-8 text-center">
        <a :href="`https://wa.me/${WHATSAPP}`" target="_blank"
          class="inline-block bg-emerald-600 text-white text-sm font-semibold rounded-xl px-6 py-3 shadow-sm hover:bg-emerald-700 transition-colors">
          Escribirnos por WhatsApp
        </a>
        <p class="text-[11px] text-gray-400 mt-3">
          Los precios pueden cambiar sin previo aviso. Consulta disponibilidad antes de comprar.
        </p>
      </div>
    </main>

    <!-- Foto a pantalla completa -->
    <div v-if="fotoAbierta" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4"
      @click="fotoAbierta = null">
      <img :src="cloudinaryOpt(fotoAbierta, 1200)" class="max-w-full max-h-full object-contain rounded-lg" />
    </div>
  </div>
</template>
