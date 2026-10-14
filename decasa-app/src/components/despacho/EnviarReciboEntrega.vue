<script setup>
/**
 * Mandarle al cliente el recibo de entrega y pago.
 *
 * Es el acta que firmó al recibir, con lo que pagó en la puerta: para el
 * efectivo es el único soporte que le queda. Por WhatsApp se abre el chat
 * del teléfono de quien entrega con el mensaje ya escrito (el sistema no
 * manda WhatsApp solo); por correo sale con el PDF adjunto.
 */
import { ref, onMounted } from 'vue'
import { reciboEntrega, descargarReciboEntrega, enviarReciboEmail, marcarReciboWhatsApp } from '@/api/despacho'
import { useToast } from '@/composables/useToast'
import { DocumentTextIcon, EnvelopeIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  despachoItemId: { type: Number, required: true },
})

const toast = useToast()

const info      = ref(null)
const cargando  = ref(true)
const error     = ref('')
const telefono  = ref('')
const email     = ref('')
const mandando  = ref(false)
const abriendo  = ref(false)
const enviadoA  = ref('')

onMounted(async () => {
  try {
    const { data } = await reciboEntrega(props.despachoItemId)
    info.value     = data
    telefono.value = data.telefono ?? ''
    email.value    = data.email ?? ''
    enviadoA.value = data.enviado_a ?? ''
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar el recibo.'
  } finally {
    cargando.value = false
  }
})

/** 3001234567 → 573001234567: wa.me necesita el indicativo. */
function numeroWhatsApp() {
  const tel = String(telefono.value ?? '').replace(/\D/g, '')
  return tel.length === 10 ? `57${tel}` : tel
}

function porWhatsApp() {
  if (!info.value) return
  const destino = numeroWhatsApp()
  window.open(`https://wa.me/${destino}?text=${encodeURIComponent(info.value.mensaje)}`, '_blank')
  enviadoA.value = `WhatsApp ${telefono.value}`.trim()
  marcarReciboWhatsApp(props.despachoItemId, telefono.value || undefined).catch(() => {})
}

async function porCorreo() {
  if (mandando.value) return
  if (!email.value.trim()) { toast.error('Escribe el correo del cliente.'); return }
  mandando.value = true
  try {
    const { data } = await enviarReciboEmail(props.despachoItemId, email.value.trim())
    enviadoA.value = email.value.trim()
    toast.success(data.message)
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo enviar el correo.')
  } finally {
    mandando.value = false
  }
}

async function verPdf() {
  if (abriendo.value) return
  abriendo.value = true
  try {
    const response = await descargarReciboEntrega(props.despachoItemId)
    const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }))
    if (!window.open(url, '_blank')) toast.error('El navegador bloqueó la ventana del PDF. Permite las ventanas emergentes.')
    setTimeout(() => window.URL.revokeObjectURL(url), 10000)
  } catch {
    toast.error('No se pudo abrir el recibo.')
  } finally {
    abriendo.value = false
  }
}
</script>

<template>
  <div class="bg-white border border-gray-200 rounded-xl p-3 space-y-3">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <p class="text-sm font-bold text-gray-800">
          {{ info?.referencia ? `Recibo ${info.referencia}` : 'Acta de entrega' }}
        </p>
        <p class="text-xs text-gray-500 leading-snug">
          {{ info?.referencia ? 'Lo que recibió y lo que pagó, con su firma. Es el soporte del pago en efectivo.' : 'Lo que recibió y en qué estado, con su firma.' }}
        </p>
      </div>
      <button
        v-if="info" type="button" @click="verPdf" :disabled="abriendo"
        class="shrink-0 min-h-11 px-3 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
      >
        <DocumentTextIcon class="w-4 h-4" aria-hidden="true" /> {{ abriendo ? 'Abriendo…' : 'Ver' }}
      </button>
    </div>

    <p v-if="cargando" class="text-xs text-gray-400">Cargando…</p>
    <p v-else-if="error" class="text-xs text-gray-500">{{ error }}</p>

    <template v-else-if="info">
      <p v-if="enviadoA" class="flex items-center gap-1 text-xs font-semibold text-emerald-700">
        <CheckCircleIcon class="w-4 h-4" aria-hidden="true" /> Enviado: {{ enviadoA }}
      </p>

      <!-- WhatsApp: se abre en el teléfono de quien entrega -->
      <div class="space-y-1.5">
        <label :for="`rec-tel-${despachoItemId}`" class="block text-xs font-medium text-gray-700">WhatsApp del cliente</label>
        <div class="flex gap-2">
          <input
            :id="`rec-tel-${despachoItemId}`" v-model="telefono" inputmode="tel" autocomplete="off"
            placeholder="Celular"
            class="flex-1 min-w-0 border border-gray-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-emerald-500 outline-none"
          />
          <button
            type="button" @click="porWhatsApp" :disabled="!numeroWhatsApp()"
            class="shrink-0 min-h-11 px-4 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 disabled:opacity-50"
          >WhatsApp</button>
        </div>
      </div>

      <!-- Correo: sale con el PDF adjunto -->
      <div class="space-y-1.5">
        <label :for="`rec-mail-${despachoItemId}`" class="block text-xs font-medium text-gray-700">Correo del cliente</label>
        <div class="flex gap-2">
          <input
            :id="`rec-mail-${despachoItemId}`" v-model="email" type="email" inputmode="email" autocomplete="off"
            placeholder="correo@ejemplo.com"
            class="flex-1 min-w-0 border border-gray-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-blue-500 outline-none"
          />
          <button
            type="button" @click="porCorreo" :disabled="mandando || !email.trim()"
            class="shrink-0 min-h-11 px-4 inline-flex items-center gap-1 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50"
          >
            <EnvelopeIcon class="w-4 h-4" aria-hidden="true" /> {{ mandando ? 'Enviando…' : 'Enviar' }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
