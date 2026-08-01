<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import { comprimirImagen } from '@/utils/comprimirImagen'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import {
  getCotizacion, cambiarEstadoCotizacion, eliminarCotizacion,
  verificarCotizacion, convertirCotizacion, descargarPdfCotizacion,
  enviarCotizacionEmail,
} from '@/api/cotizaciones'
import { urlWhatsapp, tieneWhatsapp } from '@/utils/whatsapp'
import { getReceptores, crearConsulta, getConsultas } from '@/api/consultas'
import {
  DocumentArrowDownIcon, PaperAirplaneIcon, CheckCircleIcon,
  XCircleIcon, TrashIcon, ExclamationTriangleIcon, ClockIcon,
  EnvelopeIcon, ChatBubbleLeftRightIcon, CurrencyDollarIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()

const cotizacion = ref(null)
const loading    = ref(true)
const guardando  = ref(false)

const esActiva = computed(() =>
  ['abierta', 'enviada'].includes(cotizacion.value?.cotizacion_estado)
)

// ── Consulta de costo ────────────────────────────────────────────────────────
// Lo que va sin precio se le pregunta a un supervisor o al ebanista. Cuando
// responde, ese precio entra directo en la cotización y en el PDF: aquí no hay
// nada que el cliente tenga que aceptar todavía.
const consulta = ref(null)

async function cargarConsulta() {
  try {
    const { data } = await getConsultas()
    consulta.value = (data ?? []).find(c => c.orden_id === Number(route.params.id)) ?? null
  } catch {
    consulta.value = null
  }
}

async function cargar() {
  loading.value = true
  try {
    const { data } = await getCotizacion(route.params.id)
    cotizacion.value = data
    await cargarConsulta()
  } catch {
    toast.error('No se pudo cargar la cotización.')
  } finally {
    loading.value = false
  }
}

/** Ítems que todavía no tienen precio: son los que hay que preguntar. */
const itemsSinPrecio = computed(() =>
  (cotizacion.value?.items ?? []).filter(i => i.es_personalizado && !Number(i.precio_unitario))
)

const receptores        = ref([])
const showPedirCosto    = ref(false)
const receptorId        = ref(null)
const notasCosto        = ref('')
const pidiendoCosto     = ref(false)

async function abrirPedirCosto() {
  if (!receptores.value.length) {
    try {
      const { data } = await getReceptores()
      receptores.value = data
    } catch { receptores.value = [] }
  }
  receptorId.value = null
  notasCosto.value = ''
  showPedirCosto.value = true
}

async function pedirCosto() {
  if (!receptorId.value) return
  pidiendoCosto.value = true
  try {
    await crearConsulta({
      orden_id:          cotizacion.value.id,
      asignado_a_id:     receptorId.value,
      notas_adicionales: notasCosto.value.trim() || null,
    })
    toast.success('Consulta de costo enviada.')
    showPedirCosto.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo enviar la consulta.')
  } finally {
    pidiendoCosto.value = false
  }
}

async function descargarPdf() {
  try {
    const response = await descargarPdfCotizacion(cotizacion.value.id)
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const url  = window.URL.createObjectURL(blob)
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 5000)
  } catch {
    toast.error('Error al descargar el PDF.')
  }
}

// ── Enviarla al cliente: por correo o por WhatsApp ──────────────────────────
// El correo es opcional en todo el módulo, así que casi siempre solo hay
// teléfono. Se ofrecen los dos caminos y se usa el que el cliente haya dejado.
const emailDestino  = computed(() =>
  cotizacion.value?.cliente?.email || cotizacion.value?.contacto_email || ''
)
const telefonoDestino = computed(() =>
  cotizacion.value?.cliente?.telefono || cotizacion.value?.contacto_telefono || ''
)
const puedeWhatsapp = computed(() => tieneWhatsapp(telefonoDestino.value))

const mostrarEmailManual = ref(false)
const emailManual        = ref('')
const enviandoEmail      = ref(false)

/** El mensaje que va escrito al abrir WhatsApp. */
const mensajeWhatsapp = computed(() => {
  const c = cotizacion.value
  if (!c) return ''

  const pesos = v => '$' + new Intl.NumberFormat('es-CO').format(Math.round(Number(v) || 0))
  const lineas = (c.items ?? []).map(i => {
    const nombre = i.producto?.nombre ?? i.nombre_custom ?? 'Producto'
    return `• ${nombre} x${i.cantidad} — ${pesos(i.cantidad * i.precio_unitario)}`
  })

  const partes = [
    `Hola! Te comparto la cotización ${c.cotizacion_ref ?? ''} de Decasa.`,
    '',
    ...lineas,
    '',
    `Total: ${pesos(c.valor_total)}`,
  ]

  if (c.cotizacion_valida_hasta) {
    const f = new Date(c.cotizacion_valida_hasta).toLocaleDateString('es-CO')
    partes.push(`Válida hasta el ${f}.`)
  }
  partes.push('', 'Cualquier duda me escribes.')

  return partes.join('\n')
})

function abrirWhatsapp() {
  const url = urlWhatsapp(telefonoDestino.value, mensajeWhatsapp.value)
  if (!url) {
    toast.error('El teléfono no sirve para WhatsApp.')
    return
  }
  window.open(url, '_blank', 'noopener')
  // Mandarla es lo que la vuelve "enviada": ya salió para el cliente
  if (cotizacion.value?.cotizacion_estado === 'abierta') marcar('enviada')
}

async function enviarPorEmail() {
  const destino = emailManual.value.trim() || emailDestino.value
  if (!destino) {
    mostrarEmailManual.value = true
    return
  }
  enviandoEmail.value = true
  try {
    const { data } = await enviarCotizacionEmail(cotizacion.value.id, emailManual.value.trim() || undefined)
    toast.success(data.message ?? 'Cotización enviada.')
    mostrarEmailManual.value = false
    emailManual.value = ''
    await cargar()
    if (cotizacion.value?.cotizacion_estado === 'abierta') marcar('enviada')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo enviar el correo.')
    if (e.response?.status === 422) mostrarEmailManual.value = true
  } finally {
    enviandoEmail.value = false
  }
}

async function marcar(estado, motivo = null) {
  guardando.value = true
  try {
    await cambiarEstadoCotizacion(cotizacion.value.id, {
      cotizacion_estado: estado,
      motivo_perdida:    motivo,
    })
    toast.success(estado === 'enviada' ? 'Marcada como enviada.' : 'Marcada como perdida.')
    showPerdida.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo actualizar.')
  } finally {
    guardando.value = false
  }
}

// ── Marcar perdida ────────────────────────────────────────────────────────────
const showPerdida  = ref(false)
const motivoPerdida = ref('')

// ── Convertir en orden ────────────────────────────────────────────────────────
const showConvertir = ref(false)
const verificacion  = ref(null)
const verificando   = ref(false)

async function abrirConvertir() {
  verificando.value = true
  showConvertir.value = true
  precargarContacto()
  try {
    const { data } = await verificarCotizacion(cotizacion.value.id)
    verificacion.value = data
  } catch {
    verificacion.value = null
  } finally {
    verificando.value = false
  }
}

// Datos que exige una orden y una cotización no tiene
const firmaBlob   = ref(null)
const anexoFile   = ref(null)
const convirtiendo = ref(false)
const form = ref({
  cliente_nombre:   '',
  cliente_telefono: '',
  cliente_cedula:   '',
  cliente_email:    '',
  anticipo_monto:   0,
  anticipo_metodo:  'efectivo',
  anticipo_referencia: '',
  es_fv2:           false,
  motivo_serie:     '',
})

const necesitaCliente = computed(() => !cotizacion.value?.cliente_id)
const esPresencial    = computed(() => cotizacion.value?.canal === 'fisica')

function precargarContacto() {
  form.value.cliente_nombre   = cotizacion.value?.contacto_nombre   ?? ''
  form.value.cliente_telefono = cotizacion.value?.contacto_telefono ?? ''
  form.value.cliente_email    = cotizacion.value?.contacto_email    ?? ''
}

async function subirArchivo(file, folder, nombre) {
  const fd = new FormData()
  fd.append('foto', file, nombre)
  fd.append('folder', folder)
  const { data } = await api.post('/upload/foto', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.url
}

async function hacerConversion() {
  if (!firmaBlob.value) {
    toast.error('Falta la firma del cliente.')
    return
  }
  if (necesitaCliente.value && (!form.value.cliente_nombre.trim() || !form.value.cliente_telefono.trim())) {
    toast.error('Para cerrar la venta necesitas al menos nombre y teléfono del cliente.')
    return
  }
  if (esPresencial.value && !anexoFile.value) {
    toast.error('En venta presencial se requiere la foto del anexo firmado.')
    return
  }

  convirtiendo.value = true
  try {
    const firmaUrl = await subirArchivo(firmaBlob.value, 'firmas', 'firma.png')
    const anexoUrl = anexoFile.value
      ? await subirArchivo(await comprimirImagen(anexoFile.value), 'facturas', 'anexo.jpg')
      : undefined

    const payload = {
      firma_url:      firmaUrl,
      anexo_foto_url: anexoUrl,
      anticipo_monto: Number(form.value.anticipo_monto) || 0,
      anticipo_metodo: form.value.anticipo_metodo,
      anticipo_referencia: form.value.anticipo_referencia || undefined,
      aceptar_cambios_precio: true,   // ya se mostraron las diferencias arriba
      es_fv2:         form.value.es_fv2 || undefined,
      motivo_serie:   form.value.es_fv2 ? (form.value.motivo_serie.trim() || undefined) : undefined,
      ...(necesitaCliente.value
        ? { cliente_nuevo: {
            nombre:   form.value.cliente_nombre.trim(),
            telefono: form.value.cliente_telefono.trim(),
            cedula:   form.value.cliente_cedula.trim() || undefined,
            email:    form.value.cliente_email.trim()  || undefined,
          } }
        : { cliente_id: cotizacion.value.cliente_id }),
    }

    const { data } = await convertirCotizacion(cotizacion.value.id, payload)
    toast.success(`Orden #${data.numero_orden} creada.`)
    router.push({ name: 'orden-detalle', params: { id: data.id } })
  } catch (e) {
    const errores = e.response?.data?.errors
    const detalle = errores ? ' · ' + Object.entries(errores).map(([k, v]) => `${k}: ${v[0]}`).join(', ') : ''
    toast.error((e.response?.data?.message ?? 'No se pudo convertir la cotización') + detalle)
  } finally {
    convirtiendo.value = false
  }
}

async function borrar() {
  if (!confirm('¿Eliminar esta cotización? No afecta inventario ni ventas.')) return
  try {
    await eliminarCotizacion(cotizacion.value.id)
    toast.success('Cotización eliminada.')
    router.push({ name: 'cotizaciones' })
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo eliminar.')
  }
}

function formatMoney(val) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', maximumFractionDigits: 0,
  }).format(val ?? 0)
}

function formatFecha(str) {
  if (!str) return '—'
  return new Date(str).toLocaleDateString('es-CO', { day: '2-digit', month: 'long', year: 'numeric' })
}

onMounted(cargar)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">

    <!-- Header -->
    <div class="flex items-center gap-3">
      <button @click="router.back()" class="text-violet-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        {{ cotizacion?.cotizacion_ref ?? 'Cotización' }}
      </h2>
    </div>

    <div v-if="loading" class="space-y-2">
      <div v-for="n in 3" :key="n" class="bg-white rounded-xl p-4 animate-pulse">
        <div class="h-3 bg-gray-100 rounded w-32 mb-2" />
        <div class="h-2.5 bg-gray-100 rounded w-24" />
      </div>
    </div>

    <template v-else-if="cotizacion">

      <!-- Estado -->
      <div
        v-if="cotizacion.cotizacion_estado === 'convertida'"
        class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 flex items-start gap-2"
      >
        <CheckCircleIcon class="w-5 h-5 text-emerald-600 flex-shrink-0" />
        <div>
          <p class="text-sm font-semibold text-emerald-800">Convertida en orden</p>
          <p class="text-xs text-emerald-600">
            Es la orden #{{ cotizacion.numero_orden }}.
            <button
              @click="router.push({ name: 'orden-detalle', params: { id: cotizacion.id } })"
              class="underline font-medium"
            >Ver orden</button>
          </p>
        </div>
      </div>

      <div
        v-else-if="cotizacion.cotizacion_estado === 'perdida'"
        class="bg-gray-100 border border-gray-200 rounded-xl p-3"
      >
        <p class="text-sm font-semibold text-gray-700">Marcada como perdida</p>
        <p v-if="cotizacion.motivo_perdida" class="text-xs text-gray-500 mt-0.5">
          {{ cotizacion.motivo_perdida }}
        </p>
      </div>

      <div
        v-else-if="cotizacion.esta_vencida"
        class="bg-red-50 border border-red-200 rounded-xl p-3 flex items-start gap-2"
      >
        <ClockIcon class="w-5 h-5 text-red-600 flex-shrink-0" />
        <div>
          <p class="text-sm font-semibold text-red-800">Vencida</p>
          <p class="text-xs text-red-600">
            Venció el {{ formatFecha(cotizacion.cotizacion_valida_hasta) }}.
            Puedes convertirla igual, pero revisa que los precios sigan vigentes.
          </p>
        </div>
      </div>

      <div v-else class="bg-violet-50 border border-violet-200 rounded-xl p-3">
        <p class="text-sm font-semibold text-violet-800">
          {{ cotizacion.cotizacion_estado === 'enviada' ? 'Enviada al cliente' : 'Abierta' }}
        </p>
        <p class="text-xs text-violet-600 mt-0.5">
          Válida hasta el {{ formatFecha(cotizacion.cotizacion_valida_hasta) }}. No reserva inventario.
        </p>
      </div>

      <!-- Datos -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-1.5">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-1">Datos</p>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Cliente</span>
          <span class="font-medium text-gray-800">{{ cotizacion.contacto_display }}</span>
        </div>
        <div v-if="cotizacion.contacto_telefono || cotizacion.cliente?.telefono" class="flex justify-between text-sm">
          <span class="text-gray-500">Teléfono</span>
          <span class="text-gray-800">{{ cotizacion.cliente?.telefono ?? cotizacion.contacto_telefono }}</span>
        </div>
        <div v-if="cotizacion.contacto_email || cotizacion.cliente?.email" class="flex justify-between text-sm">
          <span class="text-gray-500">Correo</span>
          <span class="text-gray-800 truncate ml-2">{{ cotizacion.cliente?.email ?? cotizacion.contacto_email }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Tienda</span>
          <span class="text-gray-800">{{ cotizacion.tienda?.nombre }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Asesor</span>
          <span class="text-gray-800">{{ cotizacion.vendedor?.nombre }}</span>
        </div>
      </div>

      <!-- Ítems -->
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">
          Ítems ({{ cotizacion.items?.length ?? 0 }})
        </p>
        <ul class="divide-y divide-gray-100">
          <li v-for="item in cotizacion.items" :key="item.id" class="py-2 flex justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm text-gray-800 truncate">
                {{ item.producto?.nombre ?? item.nombre_custom ?? 'Producto personalizado' }}
              </p>
              <p class="text-xs text-gray-400">
                {{ item.cantidad }} × {{ formatMoney(item.precio_unitario) }}
              </p>
            </div>
            <span class="text-sm font-semibold text-gray-800 whitespace-nowrap">
              {{ formatMoney(item.cantidad * item.precio_unitario) }}
            </span>
          </li>
        </ul>
        <div v-if="cotizacion.descuento_total > 0" class="flex justify-between text-sm pt-2 border-t border-gray-100 mt-1">
          <span class="text-emerald-600">Descuento</span>
          <span class="text-emerald-600">− {{ formatMoney(cotizacion.descuento_total) }}</span>
        </div>
        <div class="flex justify-between font-bold pt-2 border-t border-gray-100 mt-1">
          <span>Total</span>
          <span class="text-violet-700">{{ formatMoney(cotizacion.valor_total) }}</span>
        </div>
      </div>

      <!-- Consulta de costo: lo que va sin precio -->
      <div
        v-if="itemsSinPrecio.length || consulta"
        class="bg-violet-50 border border-violet-200 rounded-2xl p-3 space-y-2"
      >
        <div class="flex items-center gap-2">
          <CurrencyDollarIcon class="w-4 h-4 text-violet-600" />
          <p class="text-sm font-semibold text-violet-800">Costo de lo personalizado</p>
        </div>

        <!-- Ya se preguntó y falta la respuesta -->
        <template v-if="consulta?.estado === 'pendiente'">
          <p class="text-xs text-violet-700">
            Se le preguntó a <strong>{{ consulta.asignado_a?.nombre ?? 'el cotizador' }}</strong>.
            Cuando responda, el precio queda aquí y en el PDF.
          </p>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="i in itemsSinPrecio" :key="i.id"
              class="text-xs bg-white text-violet-700 px-2 py-0.5 rounded-full border border-violet-200 font-medium"
            >{{ i.producto?.nombre ?? i.nombre_custom }}</span>
          </div>
        </template>

        <!-- Ya respondió -->
        <template v-else-if="consulta?.estado === 'respondida' && !itemsSinPrecio.length">
          <p class="text-xs text-green-700">
            <strong>{{ consulta.asignado_a?.nombre ?? 'El cotizador' }}</strong> ya respondió: los precios
            están puestos en la cotización y salen en el PDF.
          </p>
        </template>

        <!-- Falta preguntar -->
        <template v-else-if="itemsSinPrecio.length">
          <p class="text-xs text-violet-700">
            {{ itemsSinPrecio.length }} ítem(s) van sin precio. Pregúntale el costo a quien lo sepa
            y el valor entra solo en la cotización.
          </p>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="i in itemsSinPrecio" :key="i.id"
              class="text-xs bg-white text-violet-700 px-2 py-0.5 rounded-full border border-violet-200 font-medium"
            >{{ i.producto?.nombre ?? i.nombre_custom }}</span>
          </div>
          <button
            @click="abrirPedirCosto"
            class="w-full py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-xl font-semibold text-sm transition-colors"
          >
            Preguntar el costo
          </button>
        </template>
      </div>

      <!-- Enviársela al cliente — por donde la haya dejado -->
      <div class="bg-white rounded-2xl border border-gray-200 p-3 space-y-2">
        <p class="text-xs font-semibold text-gray-500 uppercase">Enviar al cliente</p>

        <div class="grid grid-cols-2 gap-2">
          <button
            @click="abrirWhatsapp"
            :disabled="!puedeWhatsapp"
            :title="puedeWhatsapp ? 'Abre WhatsApp con el mensaje escrito' : 'No hay un teléfono al que escribirle'"
            class="py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-1.5 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            <ChatBubbleLeftRightIcon class="w-4 h-4" />
            WhatsApp
          </button>
          <button
            @click="enviarPorEmail"
            :disabled="enviandoEmail"
            class="py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-1.5 disabled:opacity-50 transition-colors"
          >
            <EnvelopeIcon class="w-4 h-4" />
            {{ enviandoEmail ? 'Enviando...' : 'Correo' }}
          </button>
        </div>

        <p v-if="puedeWhatsapp || emailDestino" class="text-[11px] text-gray-500">
          <template v-if="puedeWhatsapp">WhatsApp a {{ telefonoDestino }}</template>
          <template v-if="puedeWhatsapp && emailDestino"> · </template>
          <template v-if="emailDestino">Correo a {{ emailDestino }}</template>
        </p>
        <p v-else class="text-[11px] text-amber-600">
          El cliente no dejó teléfono ni correo. Escribe uno abajo o descarga el PDF y mándalo tú.
        </p>

        <!-- Correo a mano: cuando no hay uno guardado o el vendedor quiere otro -->
        <div v-if="mostrarEmailManual || !emailDestino" class="flex gap-2">
          <input
            v-model="emailManual"
            type="email"
            placeholder="correo@ejemplo.com"
            class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            @keyup.enter="enviarPorEmail"
          />
          <button
            @click="enviarPorEmail"
            :disabled="enviandoEmail || !emailManual.trim()"
            class="flex-shrink-0 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold disabled:opacity-40"
          >
            Enviar
          </button>
        </div>
        <button
          v-else-if="emailDestino"
          @click="mostrarEmailManual = true"
          class="text-[11px] text-blue-600 font-medium"
        >
          Enviar a otro correo
        </button>
      </div>

      <!-- Acciones -->
      <div class="space-y-2">
        <button
          @click="descargarPdf"
          class="w-full py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-1.5 transition-colors"
        >
          <DocumentArrowDownIcon class="w-4 h-4" />
          Descargar PDF para el cliente
        </button>

        <template v-if="esActiva">
          <button
            v-if="cotizacion.cotizacion_estado === 'abierta'"
            @click="marcar('enviada')"
            :disabled="guardando"
            class="w-full py-2.5 bg-white border border-blue-300 text-blue-700 rounded-xl font-semibold text-sm flex items-center justify-center gap-1.5 disabled:opacity-50"
          >
            <PaperAirplaneIcon class="w-4 h-4" />
            Marcar como enviada
          </button>

          <button
            @click="abrirConvertir"
            class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-1.5 transition-colors"
          >
            <CheckCircleIcon class="w-4 h-4" />
            El cliente aceptó — convertir en orden
          </button>

          <button
            @click="showPerdida = true"
            class="w-full py-2 text-gray-500 text-sm font-medium flex items-center justify-center gap-1.5"
          >
            <XCircleIcon class="w-4 h-4" />
            Marcar como perdida
          </button>
        </template>

        <button
          v-if="cotizacion.cotizacion_estado !== 'convertida'"
          @click="borrar"
          class="w-full py-2 text-red-500 text-sm font-medium flex items-center justify-center gap-1.5"
        >
          <TrashIcon class="w-4 h-4" />
          Eliminar
        </button>
      </div>
    </template>

    <!-- Modal marcar perdida -->
    <Transition name="fade">
      <div v-if="showPerdida" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showPerdida = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-3">
          <h3 class="text-lg font-bold text-gray-800">¿Por qué se perdió?</h3>
          <p class="text-sm text-gray-500">
            Opcional, pero sirve para saber qué mejorar en las próximas cotizaciones.
          </p>
          <textarea
            v-model="motivoPerdida"
            rows="3"
            placeholder="Ej. precio muy alto, compró en otro lado, ya no lo necesita..."
            class="input"
          />
          <div class="flex gap-2">
            <button @click="showPerdida = false" class="btn-secondary flex-1">Cancelar</button>
            <button
              @click="marcar('perdida', motivoPerdida.trim() || null)"
              :disabled="guardando"
              class="flex-1 py-2 bg-gray-700 text-white rounded-lg text-sm font-semibold disabled:opacity-50"
            >Marcar perdida</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal convertir -->
    <Transition name="fade">
      <div v-if="showConvertir" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showConvertir = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-3">
          <h3 class="text-lg font-bold text-gray-800">Convertir en orden</h3>

          <div v-if="verificando" class="text-sm text-gray-500 py-3">Revisando precios y stock...</div>

          <template v-else>
            <!-- Precios cambiados -->
            <div
              v-if="verificacion?.precios_cambiados?.length"
              class="bg-amber-50 border border-amber-200 rounded-lg p-3 space-y-1"
            >
              <p class="text-xs font-semibold text-amber-800 flex items-center gap-1">
                <ExclamationTriangleIcon class="w-4 h-4" />
                Precios que cambiaron desde que cotizaste
              </p>
              <p v-for="p in verificacion.precios_cambiados" :key="p.item_id" class="text-xs text-amber-700">
                {{ p.nombre }}: cotizado {{ formatMoney(p.precio_cotizado) }} → hoy {{ formatMoney(p.precio_actual) }}
              </p>
              <p class="text-xs text-amber-600 mt-1">
                Se respeta el precio que le prometiste al cliente.
              </p>
            </div>

            <!-- Stock -->
            <div
              v-if="verificacion?.faltantes_stock?.length"
              class="bg-red-50 border border-red-200 rounded-lg p-3 space-y-1"
            >
              <p class="text-xs font-semibold text-red-800 flex items-center gap-1">
                <ExclamationTriangleIcon class="w-4 h-4" />
                Sin stock suficiente
              </p>
              <p v-for="f in verificacion.faltantes_stock" :key="f.item_id" class="text-xs text-red-700">
                {{ f.nombre }}: necesitas {{ f.necesario }}, libre {{ f.libre }}
              </p>
              <p class="text-xs text-red-600 mt-1">
                Habrá que reponer o cambiar el ítem antes de convertir.
              </p>
            </div>

            <div
              v-if="!verificacion?.precios_cambiados?.length && !verificacion?.faltantes_stock?.length"
              class="bg-emerald-50 border border-emerald-200 rounded-lg p-3"
            >
              <p class="text-xs text-emerald-700">
                Precios y stock siguen igual. Puedes continuar.
              </p>
            </div>

            <!-- Cliente formal: una orden sí lo exige -->
            <div v-if="necesitaCliente" class="space-y-2 pt-1">
              <p class="text-xs font-semibold text-gray-700">Datos del cliente</p>
              <input v-model="form.cliente_nombre"   placeholder="Nombre completo *" class="input text-sm" />
              <input v-model="form.cliente_telefono" placeholder="Teléfono *"        class="input text-sm" />
              <div class="grid grid-cols-2 gap-2">
                <input v-model="form.cliente_cedula" placeholder="Cédula / NIT" class="input text-sm" />
                <input v-model="form.cliente_email"  placeholder="Correo" type="email" class="input text-sm" />
              </div>
            </div>

            <!-- Descuento especial -->
            <div :class="['rounded-lg border p-2.5', form.es_fv2 ? 'bg-amber-50 border-amber-300' : 'border-gray-200']">
              <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" v-model="form.es_fv2" class="mt-0.5 w-4 h-4 accent-amber-600" />
                <span class="min-w-0">
                  <span class="text-xs font-semibold text-gray-800">Orden con descuento especial (FV2)</span>
                  <span class="block text-xs text-gray-500">
                    Llevará numeración FV2-N en vez de número de orden.
                  </span>
                </span>
              </label>
              <input
                v-if="form.es_fv2"
                v-model="form.motivo_serie"
                placeholder="Motivo (opcional)"
                class="input text-sm mt-2"
              />
            </div>

            <!-- Anticipo -->
            <div class="space-y-2 pt-1">
              <p class="text-xs font-semibold text-gray-700">Anticipo</p>
              <input v-model.number="form.anticipo_monto" type="number" min="0" placeholder="Monto" class="input text-sm" />
              <select v-model="form.anticipo_metodo" class="input text-sm">
                <option value="efectivo">Efectivo</option>
                <option value="transferencia">Transferencia</option>
                <option value="tarjeta">Tarjeta</option>
                <option value="otro">Otro</option>
              </select>
              <input v-model="form.anticipo_referencia" placeholder="Referencia (opcional)" class="input text-sm" />
            </div>

            <!-- Anexo en venta presencial -->
            <div v-if="esPresencial" class="space-y-1 pt-1">
              <p class="text-xs font-semibold text-gray-700">Foto del anexo firmado *</p>
              <input type="file" accept="image/*" capture="environment" class="text-xs"
                     @change="anexoFile = $event.target.files[0]" />
            </div>

            <!-- Firma -->
            <div class="space-y-1 pt-1">
              <p class="text-xs font-semibold text-gray-700">Firma del cliente *</p>
              <FirmaCanvas v-model="firmaBlob" />
            </div>

            <div class="flex gap-2 pt-1">
              <button @click="showConvertir = false" class="btn-secondary flex-1">Cancelar</button>
              <button
                @click="hacerConversion"
                :disabled="convirtiendo"
                class="flex-1 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50"
              >{{ convirtiendo ? 'Convirtiendo...' : 'Crear orden' }}</button>
            </div>
          </template>
        </div>
      </div>
    </Transition>

    <!-- ¿A quién le preguntamos el costo? -->
    <Transition name="fade">
      <div v-if="showPedirCosto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showPedirCosto = false">
        <div class="absolute inset-0 bg-black/40" @click="showPedirCosto = false" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-3 max-h-[85vh] overflow-y-auto">
          <p class="text-base font-bold text-gray-800">¿A quién le preguntas el costo?</p>
          <p class="text-xs text-gray-500">
            Le llega la lista de lo personalizado con sus especificaciones. Cuando responda, el precio
            queda en la cotización y sale en el PDF.
          </p>

          <div class="space-y-2">
            <label
              v-for="r in receptores"
              :key="r.id"
              :class="[
                'flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-colors',
                receptorId === r.id ? 'border-violet-500 bg-violet-50' : 'border-gray-200 hover:border-violet-300'
              ]"
            >
              <input type="radio" :value="r.id" v-model="receptorId" class="accent-violet-600" />
              <div>
                <p class="text-sm font-semibold text-gray-800">{{ r.nombre }}</p>
                <p class="text-xs text-gray-400 capitalize">{{ r.rol }}</p>
              </div>
            </label>
            <p v-if="!receptores.length" class="text-xs text-gray-400">No hay supervisores ni ebanistas activos.</p>
          </div>

          <textarea
            v-model="notasCosto"
            rows="2"
            placeholder="Notas para el cotizador (opcional)"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-violet-400"
          />

          <div class="flex gap-3">
            <button @click="showPedirCosto = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="pedirCosto"
              :disabled="!receptorId || pidiendoCosto"
              class="flex-1 bg-violet-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-violet-700 disabled:opacity-40 transition-colors"
            >
              {{ pidiendoCosto ? 'Enviando...' : 'Preguntar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
