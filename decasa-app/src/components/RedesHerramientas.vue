<script setup>
import { ref, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import {
  XMarkIcon, MapPinIcon, ClockIcon, DocumentTextIcon,
  CreditCardIcon, TruckIcon, ClipboardDocumentIcon, ArrowTopRightOnSquareIcon,
} from '@heroicons/vue/24/outline'

defineEmits(['close'])
const toast = useToast()

// ── Datos fijos del negocio (coinciden con lo que maneja el bot) ──────────────
const sedes = [
  { nombre: 'Av. Bolívar', direccion: 'Avenida Bolívar # 16 N 26, Armenia, Quindío' },
  { nombre: 'Vía El Edén', direccion: 'Km 2 vía El Edén, Armenia, Quindío' },
  { nombre: 'Vía Jardines', direccion: 'Km 1 vía Jardines, Armenia, Quindío' },
  { nombre: 'Unicentro Pereira', direccion: 'C.C. Unicentro, Pereira, Risaralda' },
  { nombre: 'Cra. 14 Pereira', direccion: 'Cra. 14 #11-93, Pereira, Risaralda' },
]

const horario = 'Nuestro horario de atención es:\nLunes a Viernes: 8:00 am – 5:00 pm\nSábados: 8:00 am – 12:00 pm 😊'

const pagos = 'Formas de pago: efectivo, transferencia bancaria, tarjeta de crédito/débito y ADDI (crédito) 💳\nLos descuentos aplican solo con pago en efectivo o transferencia.'

const envios = 'Envío GRATIS en todo el Quindío y en Pereira (Risaralda) 🚚\nPara destinos fuera de esas zonas hay un costo adicional de transportadora — con gusto te lo cotizamos.'

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

onMounted(async () => {
  try {
    const { data } = await api.get('/redes/catalogos')
    catalogos.value = Object.entries(data)
      // La promoción del 20% venció: no mostramos catálogos de descuento aunque
      // quedara alguno en la configuración.
      .filter(([key]) => !key.startsWith('descuento'))
      .map(([key, url]) => ({ key, url, nombre: NOMBRES_CAT[key] || key }))
      .sort((a, b) => a.nombre.localeCompare(b.nombre))
  } catch {
    toast.error?.('No se pudieron cargar los catálogos')
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
  return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent('DeCasa ' + direccion)
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="$emit('close')">
    <div class="bg-gray-50 w-full sm:max-w-lg sm:rounded-2xl rounded-t-2xl max-h-[90vh] flex flex-col shadow-xl">

      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-white sm:rounded-t-2xl">
        <h2 class="font-bold text-gray-800 flex items-center gap-2">
          🧰 Herramientas del asesor
        </h2>
        <button @click="$emit('close')" class="p-1 text-gray-400 hover:text-gray-600">
          <XMarkIcon class="w-6 h-6" />
        </button>
      </div>

      <p class="text-xs text-gray-400 px-4 pt-2">Toca cualquier elemento para copiarlo y pégalo en el chat del cliente.</p>

      <div class="overflow-y-auto px-4 py-3 space-y-4">

        <!-- Sedes -->
        <section>
          <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 flex items-center gap-1.5">
            <MapPinIcon class="w-4 h-4" /> Sedes
          </h3>
          <div class="space-y-2">
            <div v-for="s in sedes" :key="s.nombre" class="bg-white rounded-xl p-3 shadow-sm">
              <p class="text-sm font-semibold text-gray-800">{{ s.nombre }}</p>
              <p class="text-xs text-gray-500 mb-2">{{ s.direccion }}</p>
              <div class="flex gap-2">
                <button @click="copiar(s.direccion, 'Dirección copiada ✅')"
                  class="flex-1 flex items-center justify-center gap-1 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg py-1.5">
                  <ClipboardDocumentIcon class="w-4 h-4" /> Copiar
                </button>
                <a :href="mapsUrl(s.direccion)" target="_blank" rel="noopener"
                  class="flex-1 flex items-center justify-center gap-1 text-xs font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg py-1.5">
                  <ArrowTopRightOnSquareIcon class="w-4 h-4" /> Maps
                </a>
              </div>
            </div>
          </div>
        </section>

        <!-- Textos rápidos -->
        <section>
          <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 flex items-center gap-1.5">
            <ClockIcon class="w-4 h-4" /> Textos rápidos
          </h3>
          <div class="space-y-2">
            <button @click="copiar(horario, 'Horario copiado ✅')"
              class="w-full text-left bg-white rounded-xl p-3 shadow-sm hover:bg-blue-50 transition-colors">
              <p class="text-sm font-semibold text-gray-800 flex items-center gap-1.5"><ClockIcon class="w-4 h-4 text-gray-400" /> Horario de atención</p>
              <p class="text-xs text-gray-500 mt-1 whitespace-pre-line">{{ horario }}</p>
            </button>
            <button @click="copiar(pagos, 'Formas de pago copiadas ✅')"
              class="w-full text-left bg-white rounded-xl p-3 shadow-sm hover:bg-blue-50 transition-colors">
              <p class="text-sm font-semibold text-gray-800 flex items-center gap-1.5"><CreditCardIcon class="w-4 h-4 text-gray-400" /> Formas de pago</p>
              <p class="text-xs text-gray-500 mt-1 whitespace-pre-line">{{ pagos }}</p>
            </button>
            <button @click="copiar(envios, 'Info de envío copiada ✅')"
              class="w-full text-left bg-white rounded-xl p-3 shadow-sm hover:bg-blue-50 transition-colors">
              <p class="text-sm font-semibold text-gray-800 flex items-center gap-1.5"><TruckIcon class="w-4 h-4 text-gray-400" /> Envíos</p>
              <p class="text-xs text-gray-500 mt-1 whitespace-pre-line">{{ envios }}</p>
            </button>
          </div>
        </section>

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
