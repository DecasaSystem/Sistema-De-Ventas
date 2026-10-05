<script setup>
/**
 * La página que abre el cliente con el enlace que le llega por WhatsApp o
 * correo: ve el resumen de su pedido, lee el anexo de garantías, responde
 * el check list y firma con el dedo. Sin sesión: la llave es el token.
 *
 * Al firmar, al vendedor le aparece al instante en la orden que está
 * armando (AnexoFirmado) y le llega una notificación.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import AnexoFirma from '@/components/anexo/AnexoFirma.vue'
import { getAnexoPublico, firmarAnexo } from '@/api/anexos'
import { CheckCircleIcon } from '@heroicons/vue/24/solid'

const route = useRoute()
const token = route.params.token

const cargando = ref(true)
const error    = ref('')
const datos    = ref(null)
const enviando = ref(false)
const errorFirma = ref('')
const listo    = ref(false)

const resumen = computed(() => datos.value?.resumen ?? null)

function pesos(v) {
  return '$' + new Intl.NumberFormat('es-CO').format(Math.round(Number(v) || 0))
}
function fecha(f) {
  if (!f) return ''
  const [y, m, d] = String(f).slice(0, 10).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' })
}

onMounted(async () => {
  try {
    const { data } = await getAnexoPublico(token)
    datos.value = data
    if (data.estado === 'firmado') listo.value = true
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo abrir el enlace. Revisa tu conexión e inténtalo de nuevo.'
  } finally {
    cargando.value = false
  }
})

async function firmar(payload) {
  enviando.value = true
  errorFirma.value = ''
  try {
    await firmarAnexo(token, {
      secciones: payload.secciones,
      checklist: payload.checklist,
      nombre:    payload.nombre,
      documento: payload.documento,
      firma:     payload.firma,
    })
    listo.value = true
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    errorFirma.value = e.response?.data?.message ?? 'No se pudo guardar. Revisa tu conexión y vuelve a darle.'
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-gray-900 text-white px-4 py-4">
      <div class="max-w-lg mx-auto">
        <p class="text-lg font-bold">Decasa Muebles &amp; Decoración</p>
        <p class="text-xs text-gray-300">Revisa tu pedido y firma el documento de garantías</p>
      </div>
    </header>

    <main class="max-w-lg mx-auto px-4 py-5 space-y-4">
      <div v-if="cargando" class="text-center text-sm text-gray-500 py-16">Abriendo…</div>

      <div v-else-if="error" class="bg-white border border-gray-200 rounded-xl p-6 text-center space-y-2">
        <p class="text-base font-semibold text-gray-800">No se puede abrir</p>
        <p class="text-sm text-gray-600">{{ error }}</p>
      </div>

      <div v-else-if="listo" class="bg-white border border-green-200 rounded-xl p-6 text-center space-y-3">
        <CheckCircleIcon class="w-14 h-14 text-green-600 mx-auto" />
        <p class="text-lg font-bold text-gray-800">¡Listo, quedó firmado!</p>
        <p class="text-sm text-gray-600">
          {{ datos?.vendedor ? `${datos.vendedor} ya` : 'Tu asesor ya' }} recibió tu firma. Ya puedes cerrar esta página.
        </p>
      </div>

      <template v-else-if="datos">
        <p class="text-sm text-gray-700">
          Hola{{ datos.cliente?.nombre ? `, ${datos.cliente.nombre}` : '' }}.
          {{ resumen ? 'Revisa que tu pedido esté bien, lee' : 'Lee' }} el documento de garantías y firma al final.
        </p>

        <!-- El pedido, para confirmar antes de firmar -->
        <section v-if="resumen" class="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100">
            <p class="text-sm font-bold text-gray-800">Tu pedido</p>
            <p v-if="resumen.tienda || datos.vendedor" class="text-xs text-gray-500">
              {{ [resumen.tienda, datos.vendedor ? `Asesor: ${datos.vendedor}` : null].filter(Boolean).join(' · ') }}
            </p>
          </div>
          <ul class="divide-y divide-gray-100">
            <li v-for="(it, i) in resumen.items" :key="i" class="px-4 py-2.5 flex items-start gap-3">
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800">{{ it.nombre }}</p>
                <p v-if="it.detalle" class="text-xs text-gray-500">{{ it.detalle }}</p>
                <p class="text-xs text-gray-500">{{ it.cantidad }} × {{ it.precio ? pesos(it.precio) : 'precio por confirmar' }}</p>
              </div>
              <p class="text-sm font-semibold text-gray-800 whitespace-nowrap">{{ it.precio ? pesos(it.precio * it.cantidad) : '—' }}</p>
            </li>
          </ul>
          <div class="px-4 py-3 bg-gray-50 space-y-1 text-sm">
            <div v-if="resumen.descuentos > 0" class="flex justify-between text-gray-600">
              <span>Descuentos</span><span>−{{ pesos(resumen.descuentos) }}</span>
            </div>
            <div class="flex justify-between font-bold text-gray-900 text-base">
              <span>Total</span><span>{{ pesos(resumen.total) }}</span>
            </div>
            <div v-if="resumen.anticipo > 0" class="flex justify-between text-gray-600">
              <span>Anticipo</span><span>{{ pesos(resumen.anticipo) }}</span>
            </div>
            <div v-if="resumen.fecha_entrega" class="flex justify-between text-gray-600">
              <span>Entrega acordada</span><span>{{ fecha(resumen.fecha_entrega) }}</span>
            </div>
          </div>
          <p class="px-4 py-2 text-[11px] text-gray-500 border-t border-gray-100">
            Si algo no está bien, no firmes y escríbele a tu asesor.
          </p>
        </section>

        <AnexoFirma
          :contenido="datos.contenido"
          :nombre="datos.cliente?.nombre ?? ''"
          :documento="datos.cliente?.cedula ?? ''"
          :enviando="enviando"
          :texto-boton="resumen ? 'Confirmo mi pedido y firmo' : 'Acepto y firmo'"
          @firmar="firmar"
        />
        <p v-if="errorFirma" class="text-sm text-red-600 text-center">{{ errorFirma }}</p>
      </template>
    </main>
  </div>
</template>
