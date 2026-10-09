<script setup>
/**
 * Mandarle al cliente a firmar una orden que ya existe y quedó sin firma.
 *
 * Pasa con la venta virtual que se confirmó sin el cliente al lado y con la
 * que se creó esperando el precio del taller. Es el mismo enlace de Nueva
 * orden: el cliente ve TODA la orden (sus datos, productos con tela,
 * especificaciones y bocetos, valor, entrega), lee el anexo y firma una vez.
 * Al firmar, la firma queda en la orden y aquí aparece sola.
 *
 * Si la orden se edita después de mandar el enlace, el cliente ya no puede
 * firmar esa versión (lo frena el servidor): se manda uno nuevo.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { enviarOrdenAFirmar, firmaRemotaDeOrden, enviarAnexoEmail, anularAnexo } from '@/api/anexos'
import { specsParaCliente, soloHttps } from '@/utils/resumenParaFirma'
import { PencilSquareIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  orden:        { type: Object, required: true },
  puedeEditar:  { type: Boolean, default: true },
})
const emit = defineEmits(['firmada'])

const auth  = useAuthStore()
const toast = useToast()

const firma     = ref(null)   // el último enlace: { id, estado, url, nombre_firmante, ... }
const enviando  = ref(false)
const email     = ref('')
const mandandoCorreo = ref(false)

const vivos = computed(() => (props.orden.items ?? []).filter(i => !i.devuelto_en))

// Con productos sin precio no se manda: el cliente firmaría un valor que no es.
const faltaPrecio = computed(() => vivos.value.some(i =>
  !i.es_regalo && Number(i.precio_unitario) === 0 && (i.es_personalizado || i.retapizar)))

async function cargar() {
  try {
    firma.value = (await firmaRemotaDeOrden(props.orden.id)).data || null
    if (firma.value?.estado === 'firmado') emit('firmada')
  } catch { /* sin enlace todavía */ }
}
onMounted(() => {
  cargar()
  email.value = props.orden.cliente?.email ?? ''
})

/** Lo que ve el cliente: la orden completa, como si la tuviera en la mano. */
function resumen() {
  const o = props.orden
  const items = vivos.value.map(i => {
    const nombre = i.producto?.nombre ?? i.nombre_custom ?? 'Producto'
    const categoria = i.producto?.categoria ?? i.categoria_custom
    return {
      nombre,
      detalle:  [i.variante_texto, i.es_regalo ? 'Obsequio' : null].filter(Boolean).join(' · ') || null,
      cantidad: Number(i.cantidad),
      precio:   Number(i.precio_unitario),
      specs:    (i.es_personalizado || i.retapizar) ? specsParaCliente(i.specs_personalizacion, nombre, categoria) : [],
      bocetos:  soloHttps(i.bocetos_list ?? (i.boceto_url ? [i.boceto_url] : [])),
    }
  })
  const subtotal = items.reduce((s, it) => s + it.cantidad * it.precio, 0)
  const fechaItem = vivos.value.map(i => i.fecha_entrega_prom).filter(Boolean).sort().pop()
  return {
    items,
    subtotal:           Math.round(subtotal),
    descuentos:         Math.round(Number(o.descuento_total || 0) + Number(o.descuento_condicionado || 0)),
    descuento_efectivo: Math.round(Number(o.descuento_condicionado || 0)),
    total:              Math.round(Number(o.valor_total || 0)),
    pagado:             Math.round(Number(o.total_pagado || 0)),
    saldo:              Math.round(Number(o.saldo_pendiente ?? (o.valor_total - (o.total_pagado || 0)))),
    fecha_entrega:      (fechaItem || o.fecha_sugerida_vendedor || '').slice(0, 10) || null,
    tienda:             o.tienda?.nombre ?? null,
    envio: {
      departamento: o.departamento_envio || null,
      ciudad:       o.ciudad_envio || null,
      direccion:    o.direccion_envio || null,
    },
  }
}

async function enviar() {
  if (enviando.value) return
  enviando.value = true
  try {
    firma.value = (await enviarOrdenAFirmar(props.orden.id, resumen())).data
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo preparar el enlace.')
  } finally {
    enviando.value = false
  }
}

function mensajeWhatsApp() {
  const nombre = props.orden.cliente?.nombre?.split(' ')[0] ?? ''
  return `Hola${nombre ? ` ${nombre}` : ''}, soy ${auth.usuario?.nombre ?? 'tu asesor'} de Decasa. ` +
    `Aquí puedes revisar tu orden ${props.orden.referencia ?? ''} completa y firmarla junto con el documento de garantías: ${firma.value?.url}`
}
function abrirWhatsApp() {
  const tel = String(props.orden.cliente?.telefono ?? '').replace(/\D/g, '')
  const destino = tel ? (tel.length === 10 ? `57${tel}` : tel) : ''
  window.open(`https://wa.me/${destino}?text=${encodeURIComponent(mensajeWhatsApp())}`, '_blank')
}
async function copiar() {
  try {
    await navigator.clipboard.writeText(firma.value.url)
    toast.success('Enlace copiado')
  } catch {
    toast.info(firma.value.url)
  }
}
async function porCorreo() {
  if (!email.value.trim() || mandandoCorreo.value) return
  mandandoCorreo.value = true
  try {
    const { data } = await enviarAnexoEmail(firma.value.id, email.value.trim())
    toast.success(data.message)
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo enviar el correo.')
  } finally {
    mandandoCorreo.value = false
  }
}
async function cancelar() {
  if (!firma.value?.id) return
  try { await anularAnexo(firma.value.id) } catch {}
  firma.value = null
}

// Esperar la firma: al instante por el canal del anexo y, por si no hay
// tiempo real, revisando cada 15 s mientras la pantalla esté a la vista.
let canal = null
let sondeo = null
function dejarDeEsperar() {
  if (canal && window.Echo) window.Echo.leave(canal)
  canal = null
  clearInterval(sondeo)
  sondeo = null
}
watch(() => [firma.value?.id, firma.value?.estado], ([id, estado]) => {
  dejarDeEsperar()
  if (!id || estado !== 'pendiente') return
  if (window.Echo) {
    canal = `anexo.${id}`
    window.Echo.channel(canal).listen('.anexo.firmado', cargar)
  }
  sondeo = setInterval(() => { if (document.visibilityState === 'visible') cargar() }, 15000)
})
onBeforeUnmount(dejarDeEsperar)
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
    <div class="flex items-center justify-between gap-2">
      <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
        <PencilSquareIcon class="w-4 h-4" /> Firma del cliente
      </p>
      <span v-if="firma?.estado === 'pendiente'" class="text-[11px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5">Esperando firma</span>
      <span v-else class="text-[11px] font-semibold text-red-700 bg-red-50 border border-red-200 rounded-full px-2 py-0.5">Sin firma</span>
    </div>

    <!-- Esperando que firme -->
    <template v-if="firma?.estado === 'pendiente'">
      <p class="text-xs text-gray-600">
        Se le mandó a {{ orden.cliente?.nombre?.split(' ')[0] ?? 'el cliente' }} para que revise la orden completa y la firme.
        Cuando firme, aparece aquí sola.
      </p>
      <template v-if="puedeEditar">
        <div class="grid grid-cols-2 gap-2">
          <button type="button" @click="abrirWhatsApp" class="text-xs font-semibold rounded-lg py-2 bg-green-600 text-white hover:bg-green-700">WhatsApp</button>
          <button type="button" @click="copiar" class="text-xs font-semibold rounded-lg py-2 border border-gray-300 hover:bg-gray-50">Copiar enlace</button>
        </div>
        <div class="flex gap-2">
          <input v-model="email" type="email" placeholder="Correo del cliente" class="input text-sm flex-1" />
          <button type="button" @click="porCorreo" :disabled="mandandoCorreo || !email.trim()"
            class="text-xs font-semibold rounded-lg px-3 border border-gray-300 hover:bg-gray-50 disabled:opacity-40">
            {{ mandandoCorreo ? '…' : 'Enviar' }}
          </button>
        </div>
        <div class="flex items-center justify-between">
          <button type="button" @click="cargar" class="text-xs text-blue-600 font-medium">Ya firmó, revisar</button>
          <div class="flex gap-3">
            <button type="button" @click="enviar" :disabled="enviando" class="text-xs text-gray-500 hover:text-blue-600">Mandar uno nuevo</button>
            <button type="button" @click="cancelar" class="text-xs text-gray-500 hover:text-red-600">Cancelar envío</button>
          </div>
        </div>
        <p class="text-[11px] text-gray-400">Si cambias la orden, manda uno nuevo: el enlace viejo ya no deja firmar.</p>
      </template>
    </template>

    <!-- Sin enlace, o venció -->
    <template v-else>
      <p class="text-xs text-gray-600">
        <template v-if="firma?.estado === 'vencido'">El enlace venció sin que el cliente firmara. </template>
        Esta orden no tiene la firma del cliente. Mándasela: desde su teléfono ve toda la orden
        (sus datos, productos, valor y entrega), lee el anexo de garantías y firma una sola vez.
      </p>
      <p v-if="faltaPrecio" class="text-xs text-amber-700">
        Todavía hay productos sin precio. Cuando el taller los cotice, se le manda con el precio final.
      </p>
      <button v-if="puedeEditar" type="button" @click="enviar" :disabled="enviando || faltaPrecio"
        class="w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2.5 hover:bg-blue-700 disabled:opacity-50">
        {{ enviando ? 'Preparando…' : 'Enviar al cliente para revisar y firmar' }}
      </button>
    </template>
  </div>
</template>
