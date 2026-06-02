<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { getOrden, updateEstado, descargarPdfOrden, reenviarCotizacion, asignarFechasEntrega, confirmarCotizacion } from '@/api/ordenes'
import { despachoPorOrden } from '@/api/despacho'
import { tomarFacturacion, marcarFacturada } from '@/api/pagos'
import { getReceptores, crearConsulta } from '@/api/consultas'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import RegistroPagoModal from '@/components/ordenes/RegistroPagoModal.vue'
import EditarOrdenModal from '@/components/ordenes/EditarOrdenModal.vue'
import { SparklesIcon, XMarkIcon } from '@heroicons/vue/24/solid'
import { DocumentIcon, EnvelopeIcon, ChatBubbleLeftEllipsisIcon, ArrowDownTrayIcon, CalendarIcon, BuildingOffice2Icon, TruckIcon, PencilSquareIcon, ClockIcon, CheckBadgeIcon, LockClosedIcon, WrenchScrewdriverIcon, CheckCircleIcon, UserGroupIcon, CurrencyDollarIcon, BanknotesIcon } from '@heroicons/vue/24/outline'
import FirmaCanvas from '@/components/FirmaCanvas.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const orden = ref(null)
const loading = ref(true)
const verFactura = ref(false)
const bocetoModal = ref('')
const error = ref('')
const showPagoModal   = ref(false)
const showEditarModal = ref(false)
const changingEstado  = ref(false)
const estadoError = ref('')
const enviandoEmail = ref(false)
const emailManual = ref('')
const mostrarEmailManual = ref(false)

const fechasEdicion = ref({})
const guardandoFechas = ref(false)

const despachoEntrega = ref(null)
const cargandoDespacho = ref(false)

const pruebaEntregaVisible = computed(() =>
  orden.value?.estado === 'entregado' && despachoEntrega.value
)

const puedeEditar = computed(() => {
  if (!orden.value) return false
  if (['entregado', 'cancelado', 'listo_entrega', 'en_camino'].includes(orden.value.estado)) return false
  if (auth.usuario?.rol === 'vendedor' && Number(orden.value.vendedor_id) !== Number(auth.usuario.id)) return false
  return true
})

const todasFechasAsignadas = computed(() =>
  (orden.value?.items?.length ?? 0) > 0 && (orden.value?.items?.every(i => i.fecha_entrega_prom) ?? false)
)

const transicionesValidas = {
  pendiente_cotizacion: ['cancelado'],
  pendiente_anticipo: ['en_produccion', 'listo_entrega', 'cancelado'],
  en_produccion: ['listo_entrega', 'cancelado'],
  listo_entrega: [],
  en_camino: [],
  entregado: [],
  cancelado: [],
}

const nuevoEstado = ref('')

const estadosLabel = {
  pendiente_cotizacion: 'Pendiente cotización',
  pendiente_anticipo: 'Pendiente anticipo',
  en_produccion: 'En producción',
  listo_entrega: 'Listo entrega',
  entregado: 'Entregado',
  cancelado: 'Cancelado',
}

const porcentajePagado = computed(() => {
  if (!orden.value || !orden.value.valor_total) return 0
  return Math.min(100, Math.round((orden.value.total_pagado / orden.value.valor_total) * 100))
})

const puedeCambiarEstado = computed(() => {
  if (!orden.value) return false
  if (!auth.isSupervisor) return false
  if (['entregado', 'cancelado', 'listo_entrega', 'en_camino'].includes(orden.value.estado)) return false
  if (tienePersonalizados.value) return false
  return true
})

const tienePersonalizados = computed(() =>
  orden.value?.items?.some(i => i.es_personalizado) ?? false
)

const puedeRegistrarPago = computed(() => {
  return orden.value && !['entregado', 'cancelado'].includes(orden.value.estado) && orden.value.saldo_pendiente > 0
})

const opcionesNuevoEstado = computed(() => {
  if (!orden.value) return []
  return (transicionesValidas[orden.value.estado] ?? [])
    .filter((e) => {
      if (e === 'en_produccion' && !tienePersonalizados.value) return false
      if (e === 'listo_entrega' && tienePersonalizados.value && orden.value.estado === 'pendiente_anticipo') return false
      return true
    })
    .map((e) => ({
      value: e,
      label: estadosLabel[e] ?? e,
    }))
})

async function cargarOrden() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getOrden(route.params.id)
    orden.value = data
    nuevoEstado.value = ''
    estadoError.value = ''
    const edicion = {}
    for (const item of data.items ?? []) {
      edicion[item.id] = item.fecha_entrega_prom
        ? String(item.fecha_entrega_prom).substring(0, 10)
        : ''
    }
    fechasEdicion.value = edicion

    // Cargar datos de despacho si está entregado
    if (data.estado === 'entregado') {
      cargarDespachoEntrega(data.id)
    }

    // Cargar consulta activa si hay ítems personalizados
    if (data.items?.some(i => i.es_personalizado)) {
      import('@/api/consultas').then(({ getConsultas }) =>
        getConsultas().then(r => {
          consultaActiva.value = (r.data ?? []).find(c => c.orden_id === data.id) ?? null
        }).catch(() => {})
      )
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar la orden.'
  } finally {
    loading.value = false
  }
}

async function cargarDespachoEntrega(ordenId) {
  try {
    cargandoDespacho.value = true
    const { data } = await despachoPorOrden(ordenId)
    despachoEntrega.value = data
  } catch {
    despachoEntrega.value = null
  } finally {
    cargandoDespacho.value = false
  }
}

async function cambiarEstado() {
  if (!nuevoEstado.value) return
  changingEstado.value = true
  try {
    await updateEstado(orden.value.id, nuevoEstado.value)
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al cambiar el estado.')
  } finally {
    changingEstado.value = false
  }
}

function onPagoRegistrado() {
  cargarOrden()
}

function onOrdenEditada(ordenActualizada) {
  orden.value = ordenActualizada
}

function formatCambioVal(val) {
  if (val === null || val === undefined || val === '') return '—'
  if (typeof val === 'object') {
    const parts = []
    if (val.marca)       parts.push(val.marca)
    if (val.tela)        parts.push(val.tela)
    if (val.color)       parts.push(val.color)
    if (val.medidas)     parts.push(val.medidas)
    if (val.acabado)     parts.push(val.acabado)
    if (val.descripcion) parts.push(val.descripcion)
    return parts.length ? parts.join(' · ') : JSON.stringify(val)
  }
  if (typeof val === 'number') return new Intl.NumberFormat('es-CO').format(val)
  return String(val)
}

async function descargarPdf() {
  try {
    const response = await descargarPdfOrden(orden.value.id)
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const url = window.URL.createObjectURL(blob)
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 5000)
  } catch (e) {
    error.value = 'Error al descargar el PDF.'
  }
}

function formatFecha(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(String(dateStr).substring(0, 10) + 'T00:00:00')
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatDateTime(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const tipoPagoLabel = {
  anticipo: 'Anticipo',
  abono: 'Abono',
  saldo_final: 'Saldo final',
}

// ── Compartir ─────────────────────────────────────────────────────────────────

function whatsappLink() {
  const telefono = orden.value?.cliente?.telefono ?? ''
  // Limpiar dígitos y formatear para Colombia (+57)
  const digits = telefono.replace(/\D/g, '')
  const numero = digits.startsWith('57') ? digits : `57${digits}`

  const o = orden.value
  const total     = new Intl.NumberFormat('es-CO').format(o.valor_total)
  const anticipo  = new Intl.NumberFormat('es-CO').format(o.total_pagado)
  const saldo     = new Intl.NumberFormat('es-CO').format(o.saldo_pendiente)

  const productos = (o.items ?? [])
    .map(i => `  • ${i.producto?.nombre ?? i.nombre_custom ?? 'Producto personalizado'} x${i.cantidad}`)
    .join('\n')

  const mensaje = [
    `Hola ${o.cliente?.nombre} 👋`,
    ``,
    `Aquí tienes el resumen de tu pedido en *Decasa* (Orden #${o.id}):`,
    ``,
    `🛋️ *Productos:*`,
    productos,
    ``,
    `💰 *Total:* $${total} COP`,
    `✅ *Anticipo pagado:* $${anticipo} COP`,
    o.saldo_pendiente > 0 ? `💳 *Saldo pendiente:* $${saldo} COP` : `🎉 *¡Pedido totalmente pagado!*`,
    ``,
    `Adjunto encontrarás la cotización en PDF con todos los detalles.`,
    `¡Gracias por tu compra! 🛋️`,
  ].filter(l => l !== null).join('\n')

  return `https://wa.me/${numero}?text=${encodeURIComponent(mensaje)}`
}

async function abrirWhatsApp() {
  // Primero descargar/abrir el PDF para que el vendedor lo tenga disponible
  descargarPdf()
  // Abrir WhatsApp con mensaje pre-llenado
  window.open(whatsappLink(), '_blank')
}

async function enviarEmail(emailDestino = null) {
  enviandoEmail.value = true
  try {
    const { data } = await reenviarCotizacion(orden.value.id, emailDestino)
    toast.success(data.message)
    mostrarEmailManual.value = false
    emailManual.value = ''
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al enviar el email.')
  } finally {
    enviandoEmail.value = false
  }
}

async function descargarBoceto(url) {
  try {
    const resp = await fetch(url)
    const blob = await resp.blob()
    const ext = blob.type.includes('png') ? 'png' : blob.type.includes('jpg') || blob.type.includes('jpeg') ? 'jpg' : 'png'
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `boceto.${ext}`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 5000)
  } catch {
    // noop
  }
}

async function guardarFechas() {
  guardandoFechas.value = true
  try {
    const items = Object.entries(fechasEdicion.value)
      .filter(([, fecha]) => fecha)
      .map(([id, fecha]) => ({ id: Number(id), fecha }))
    if (items.length === 0) {
      toast.error('Debes ingresar al menos una fecha.')
      guardandoFechas.value = false
      return
    }
    const { data } = await asignarFechasEntrega(orden.value.id, items)
    toast.success(data.message)
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al guardar las fechas.')
  } finally {
    guardandoFechas.value = false
  }
}

// ── Facturación por pago ──────────────────────────────────────────────────────
const facturacionLoading = ref({}) // { [pagoId]: true/false }

async function doTomarFacturacion(pagoId) {
  facturacionLoading.value[pagoId] = true
  try {
    const { data } = await tomarFacturacion(pagoId)
    if (!data.tomado) {
      toast.error('Ya fue tomado por ' + (data.pago?.facturacion_tomada_por?.nombre ?? 'otro facturador'))
    }
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al tomar la facturación')
  } finally {
    facturacionLoading.value[pagoId] = false
  }
}

async function doMarcarFacturada(pagoId) {
  facturacionLoading.value[pagoId] = true
  try {
    await marcarFacturada(pagoId)
    toast.success('Factura marcada como hecha')
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al marcar la factura')
  } finally {
    facturacionLoading.value[pagoId] = false
  }
}

// ── Trazabilidad de producción ────────────────────────────────────────────────

const itemsConProduccion = computed(() =>
  (orden.value?.items ?? []).filter(i => i.es_personalizado && i.produccion?.pasos?.length)
)

function labelProceso(tipo) {
  return { ebanisteria: 'Ebanistería', tapizado: 'Tapizado', laca: 'Laca' }[tipo] ?? tipo
}

function colorPaso(estado) {
  if (estado === 'completado') return 'bg-green-100 text-green-700'
  if (estado === 'en_proceso') return 'bg-blue-100 text-blue-700'
  return 'bg-gray-100 text-gray-400'
}

// ── Confirmar cotización (firma + anticipo cuando cliente acepta) ─────────────
const showModalConfirmar      = ref(false)
const firmaConfirmarBlob      = ref(null)
const firmaConfirmarUrl       = ref('')
const facturaConfirmarFile    = ref(null)
const facturaConfirmarUrl     = ref('')
const facturaConfirmarPreview = ref('')
const anticipoPctConfirmar    = ref(50)
const anticipoConfirmar       = ref(0)
const metodoPagoConfirmar     = ref('efectivo')
const refPagoConfirmar        = ref('')
const confirmando             = ref(false)

watch(firmaConfirmarBlob, () => { firmaConfirmarUrl.value = '' })

watch(facturaConfirmarFile, (file) => {
  if (facturaConfirmarPreview.value) URL.revokeObjectURL(facturaConfirmarPreview.value)
  facturaConfirmarPreview.value = file ? URL.createObjectURL(file) : ''
})

function onFacturaConfirmarChange(e) {
  const file = e.target.files[0]
  if (file) { facturaConfirmarFile.value = file; facturaConfirmarUrl.value = '' }
}

function quitarFacturaConfirmar() {
  facturaConfirmarFile.value    = null
  facturaConfirmarUrl.value     = ''
  facturaConfirmarPreview.value = ''
}

const totalAcordado = computed(() => Number(orden.value?.valor_total ?? 0))

const minimoAnticipoc = computed(() =>
  Math.ceil(totalAcordado.value * anticipoPctConfirmar.value / 100)
)

function seleccionarPctAnticipo(pct) {
  anticipoPctConfirmar.value = pct
  anticipoConfirmar.value    = minimoAnticipoc.value
}

const metodosOpts = [
  { value: 'efectivo',      label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'tarjeta',       label: 'Tarjeta' },
  { value: 'otro',          label: 'Otro' },
]

async function doConfirmarCotizacion() {
  if (!firmaConfirmarBlob.value && !firmaConfirmarUrl.value) return
  if (!facturaConfirmarFile.value && !facturaConfirmarUrl.value) {
    toast.error('Adjunta la foto del comprobante antes de confirmar.')
    return
  }
  confirmando.value = true
  try {
    const api = (await import('@/api')).default

    // Subir firma
    if (firmaConfirmarBlob.value && !firmaConfirmarUrl.value) {
      const fd = new FormData()
      fd.append('foto', firmaConfirmarBlob.value, 'firma.png')
      fd.append('folder', 'firmas')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      firmaConfirmarUrl.value = up.url
    }

    // Subir foto comprobante
    if (facturaConfirmarFile.value && !facturaConfirmarUrl.value) {
      const fd = new FormData()
      fd.append('foto', facturaConfirmarFile.value)
      fd.append('folder', 'facturas')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      facturaConfirmarUrl.value = up.url
    }

    await confirmarCotizacion(orden.value.id, {
      firma_url:         firmaConfirmarUrl.value,
      factura_foto_url:  facturaConfirmarUrl.value,
      anticipo_monto:    anticipoConfirmar.value,
      anticipo_metodo:   metodoPagoConfirmar.value,
      anticipo_referencia: refPagoConfirmar.value || undefined,
    })

    toast.success('Cotización confirmada. Orden en pendiente de anticipo.')
    showModalConfirmar.value = false
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al confirmar.')
  } finally {
    confirmando.value = false
  }
}

// ── Consulta de costo ────────────────────────────────────────────────────────

const consultaActiva     = ref(null)
const showModalCotizar   = ref(false)
const receptores         = ref([])
const cotizarReceptorId  = ref(null)
const cotizarNotas       = ref('')
const enviandoCotizacion = ref(false)

const puedesolicitarCotizacion = computed(() => {
  if (!orden.value) return false
  if (!tienePersonalizados.value) return false
  if (consultaActiva.value?.estado === 'pendiente') return false
  if (!['vendedor', 'supervisor'].includes(auth.usuario?.rol)) return false
  return true
})

async function abrirModalCotizar() {
  if (!receptores.value.length) {
    try {
      const { data } = await getReceptores()
      receptores.value = data
    } catch { receptores.value = [] }
  }
  cotizarReceptorId.value = null
  cotizarNotas.value      = ''
  showModalCotizar.value  = true
}

async function enviarSolicitudCotizacion() {
  if (!cotizarReceptorId.value) return
  enviandoCotizacion.value = true
  try {
    await crearConsulta({
      orden_id:          orden.value.id,
      asignado_a_id:     cotizarReceptorId.value,
      notas_adicionales: cotizarNotas.value.trim() || null,
    })
    toast.success('Consulta enviada.')
    showModalCotizar.value = false
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al enviar la consulta.')
  } finally {
    enviandoCotizacion.value = false
  }
}

onMounted(cargarOrden)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        Orden #{{ orden?.id ?? '...' }}
      </h2>
      <button
        v-if="orden && puedeEditar"
        @click="showEditarModal = true"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-amber-700 bg-amber-50 rounded-lg hover:bg-amber-100 transition-colors"
        title="Editar orden"
      >
        <PencilSquareIcon class="w-4 h-4" />
        Editar
      </button>
      <button
        v-if="orden"
        @click="descargarPdf"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors"
        title="Descargar PDF"
      >
        <DocumentIcon class="w-4 h-4" />
        PDF
      </button>
      <BadgeEstado v-if="orden" :estado="orden.estado" />
      <span
        v-if="orden?.atrasado"
        class="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700"
      >⚠ Atrasado</span>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">
      {{ error }}
    </div>

    <template v-else-if="orden">
      <!-- Info general -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-2 text-sm">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Información general</p>
        <div class="flex justify-between">
          <span class="text-gray-500">Cliente</span>
          <span class="font-medium text-gray-800">{{ orden.cliente?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Tienda</span>
          <span class="font-medium text-gray-800">{{ orden.tienda?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Vendedor</span>
          <span class="font-medium text-gray-800">{{ orden.vendedor?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Canal</span>
          <span class="font-medium text-gray-800 capitalize">{{ orden.canal }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Fecha</span>
          <span class="font-medium text-gray-800">{{ formatDateTime(orden.created_at) }}</span>
        </div>
        <div v-if="orden.notas" class="flex justify-between">
          <span class="text-gray-500">Notas</span>
          <span class="font-medium text-gray-800 text-right max-w-[60%]">{{ orden.notas }}</span>
        </div>
        <div v-if="orden.ciudad_envio || orden.departamento_envio" class="pt-1">
          <p class="text-xs font-semibold text-gray-500 uppercase mb-1.5">Dirección de envío</p>
          <div class="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
            <svg class="w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
            </svg>
            <div class="text-xs text-blue-700 space-y-0.5">
              <p class="font-medium">
                {{ orden.ciudad_envio }}{{ orden.departamento_envio ? ', ' + orden.departamento_envio : '' }}
              </p>
              <p v-if="orden.direccion_envio" class="text-blue-600">{{ orden.direccion_envio }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Consulta de costo -->
      <div v-if="tienePersonalizados" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
          <CurrencyDollarIcon class="w-3.5 h-3.5" />
          Cotización de costo
        </p>

        <!-- Estado de la consulta activa -->
        <div v-if="consultaActiva" class="flex items-start gap-3">
          <div
            :class="[
              'flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center',
              consultaActiva.estado === 'pendiente' ? 'bg-amber-100' : 'bg-green-100'
            ]"
          >
            <ClockIcon v-if="consultaActiva.estado === 'pendiente'" class="w-4 h-4 text-amber-600" />
            <CheckCircleIcon v-else class="w-4 h-4 text-green-600" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">
              {{ consultaActiva.estado === 'pendiente' ? 'Cotización pendiente' : 'Precio recibido' }}
            </p>
            <p class="text-xs text-gray-400">
              Asignada a {{ consultaActiva.asignado_a?.nombre ?? '—' }}
            </p>
            <div v-if="consultaActiva.estado === 'respondida'" class="space-y-2 mt-1.5">
              <div class="flex flex-wrap gap-1.5">
                <span
                  v-for="item in consultaActiva.items"
                  :key="item.id"
                  class="text-xs bg-green-50 text-green-700 px-2 py-0.5 rounded-full font-medium"
                >
                  {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem' }}:
                  {{ new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(item.precio_final) }}
                </span>
              </div>
              <button
                v-if="orden.estado === 'pendiente_cotizacion'"
                @click="anticipoPctConfirmar = 50; anticipoConfirmar = Math.ceil(totalAcordado * 0.5); showModalConfirmar = true"
                class="w-full bg-green-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-green-700 transition-colors flex items-center justify-center gap-2"
              >
                <CheckCircleIcon class="w-4 h-4" />
                El cliente aceptó el precio — confirmar
              </button>
            </div>
          </div>
          <button
            @click="router.push({ name: 'consulta-detalle', params: { id: consultaActiva.id } })"
            class="text-xs text-blue-600 font-medium hover:underline flex-shrink-0"
          >
            Ver →
          </button>
        </div>

        <!-- Sin consulta -->
        <div v-else>
          <p class="text-xs text-gray-400">No se solicitó cotización de costo para esta orden.</p>
        </div>
      </div>

      <!-- Foto de factura -->
      <div v-if="orden.factura_foto_url" class="bg-white rounded-xl shadow-sm p-4 space-y-2">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Factura</p>
        <img
          :src="orden.factura_foto_url"
          alt="Factura"
          class="w-full rounded-lg border border-gray-200 object-contain max-h-72 cursor-pointer"
          @click="verFactura = true"
        />
      </div>

      <!-- Progreso de pago -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Pago</p>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Total</span>
          <MoneyDisplay :amount="orden.valor_total" bold />
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Pagado</span>
          <span class="font-medium text-green-600"><MoneyDisplay :amount="orden.total_pagado" /></span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Saldo</span>
          <span class="font-bold text-red-600"><MoneyDisplay :amount="orden.saldo_pendiente" /></span>
        </div>
        <!-- Barra progreso -->
        <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
          <div
            class="h-full rounded-full transition-all duration-500"
            :class="porcentajePagado >= 100 ? 'bg-green-500' : 'bg-blue-500'"
            :style="{ width: porcentajePagado + '%' }"
          />
        </div>
        <p class="text-xs text-gray-400 text-right">{{ porcentajePagado }}% pagado</p>
      </div>

      <!-- Ítems -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Ítems ({{ orden.items?.length ?? 0 }})</p>
        <div
          v-for="(item, idx) in orden.items"
          :key="idx"
          class="border-b border-gray-100 last:border-0 pb-3 last:pb-0"
        >
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800">{{ item.producto?.nombre ?? item.nombre_custom ?? 'Producto personalizado' }}</p>
              <p class="text-xs text-gray-400">{{ item.producto?.categoria ?? item.categoria_custom ?? 'personalizado' }}</p>
              <p class="text-xs text-gray-500 mt-0.5">Cantidad: {{ item.cantidad }}</p>
              <p v-if="item.es_personalizado" class="text-xs text-purple-600 mt-1 flex items-center gap-1">
                <SparklesIcon class="w-3.5 h-3.5" /> Personalizado
              </p>
              <div v-if="item.es_personalizado && item.specs_personalizacion" class="mt-1 bg-purple-50 rounded-lg px-2 py-1.5 text-xs text-gray-600 space-y-0.5">
                <p v-if="item.specs_personalizacion.marca || item.specs_personalizacion.tela || item.specs_personalizacion.color">
                  <span v-if="item.specs_personalizacion.marca">{{ item.specs_personalizacion.marca }}</span><span v-if="item.specs_personalizacion.tela"> · {{ item.specs_personalizacion.tela }}</span><span v-if="item.specs_personalizacion.color"> · {{ item.specs_personalizacion.color }}</span>
                </p>
                <p v-if="item.specs_personalizacion.medidas || item.specs_personalizacion.acabado">
                  <span v-if="item.specs_personalizacion.medidas">{{ item.specs_personalizacion.medidas }}</span><span v-if="item.specs_personalizacion.acabado"> · {{ item.specs_personalizacion.acabado }}</span>
                </p>
                <p v-if="item.specs_personalizacion.descripcion" class="whitespace-pre-wrap">{{ item.specs_personalizacion.descripcion }}</p>
              </div>
              <div v-if="item.boceto_url" class="mt-2">
                <div class="flex items-center justify-between mb-1">
                  <p class="text-xs text-gray-400">Boceto</p>
                  <button
                    @click.stop="descargarBoceto(item.boceto_url)"
                    class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-700 transition-colors"
                    title="Descargar boceto"
                  >
                    <ArrowDownTrayIcon class="w-3.5 h-3.5" />
                    Descargar
                  </button>
                </div>
                <img
                  :src="item.boceto_url"
                  alt="Boceto"
                  class="rounded-lg border border-purple-200 object-contain bg-white w-full max-h-48 cursor-pointer"
                  @click="bocetoModal = item.boceto_url"
                />
              </div>
              <p v-if="item.fecha_entrega_prom" class="text-xs text-gray-500 mt-0.5">
                Entrega estimada: {{ formatFecha(item.fecha_entrega_prom) }}
              </p>
            </div>
            <div class="text-right ml-3">
              <p class="text-xs text-gray-500"><MoneyDisplay :amount="item.precio_unitario" /></p>
              <p class="text-sm font-semibold text-gray-700"><MoneyDisplay :amount="item.cantidad * item.precio_unitario" /></p>
            </div>
          </div>
        </div>
      </div>

      <!-- Trazabilidad de producción (solo supervisor) -->
      <div v-if="auth.isSupervisor && itemsConProduccion.length > 0" class="bg-white rounded-xl shadow-sm p-4 space-y-4">
        <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
          <WrenchScrewdriverIcon class="w-3.5 h-3.5" />
          Trazabilidad de producción
        </p>

        <div
          v-for="item in itemsConProduccion"
          :key="item.id"
          class="space-y-2"
          :class="itemsConProduccion.length > 1 ? 'border-b border-gray-100 last:border-0 pb-3 last:pb-0' : ''"
        >
          <p class="text-sm font-semibold text-gray-700">
            {{ item.producto?.nombre ?? item.nombre_custom ?? 'Ítem personalizado' }}
          </p>

          <!-- Pasos -->
          <div class="space-y-2 ml-1">
            <div
              v-for="paso in item.produccion.pasos"
              :key="paso.id"
              class="flex items-start gap-2.5"
            >
              <!-- Indicador de estado -->
              <div :class="['w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center', colorPaso(paso.estado)]">
                <CheckCircleIcon v-if="paso.estado === 'completado'" class="w-4 h-4" />
                <span v-else class="text-[10px] font-bold">{{ paso.orden }}</span>
              </div>

              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <p class="text-sm font-medium text-gray-700">{{ labelProceso(paso.tipo_proceso) }}</p>
                  <span v-if="paso.estado === 'en_proceso'" class="text-[10px] bg-blue-100 text-blue-700 font-semibold px-1.5 py-0.5 rounded-full">En proceso</span>
                  <span v-else-if="paso.estado === 'pendiente'" class="text-[10px] bg-gray-100 text-gray-500 font-semibold px-1.5 py-0.5 rounded-full">Pendiente</span>
                </div>

                <template v-if="paso.estado === 'completado'">
                  <p class="text-xs text-gray-400 mt-0.5">{{ formatDateTime(paso.completado_at) }}</p>
                  <div v-if="paso.completado_por" class="flex items-center gap-1 mt-1 text-xs text-gray-600">
                    <UserGroupIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
                    <span class="font-medium">{{ paso.completado_por.nombre }}</span>
                    <span v-if="paso.trabajadores?.length" class="text-gray-400">
                      · {{ paso.trabajadores.join(', ') }}
                    </span>
                  </div>
                  <div v-else-if="paso.trabajadores?.length" class="flex items-center gap-1 mt-1 text-xs text-gray-600">
                    <UserGroupIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
                    {{ paso.trabajadores.join(', ') }}
                  </div>
                </template>
              </div>
            </div>

            <!-- Despacho de producción -->
            <div v-if="item.produccion.despachador" class="flex items-start gap-2.5">
              <div class="w-6 h-6 rounded-full flex-shrink-0 bg-purple-100 text-purple-700 flex items-center justify-center">
                <TruckIcon class="w-3.5 h-3.5" />
              </div>
              <div>
                <p class="text-sm font-medium text-gray-700">Despacho de producción</p>
                <p class="text-xs text-gray-600 mt-0.5">
                  {{ item.produccion.despachador.nombre }}
                  <span v-if="item.produccion.fecha_real" class="text-gray-400 ml-1">· {{ formatFecha(item.produccion.fecha_real) }}</span>
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pruebas de Entrega (despacho) -->
      <div v-if="pruebaEntregaVisible" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Pruebas de Entrega</p>

        <div v-if="cargandoDespacho" class="text-sm text-gray-400">Cargando...</div>

        <template v-else-if="despachoEntrega">
          <div class="grid grid-cols-2 gap-3">
            <div v-if="despachoEntrega.foto_producto">
              <p class="text-xs text-gray-500 mb-1">Producto entregado</p>
              <img
                :src="despachoEntrega.foto_producto"
                class="w-full h-32 object-cover rounded-lg border border-gray-200 cursor-pointer"
                @click="verFactura = despachoEntrega.foto_producto"
              />
            </div>
            <div v-if="despachoEntrega.foto_pago">
              <p class="text-xs text-gray-500 mb-1">Comprobante de pago</p>
              <img
                :src="despachoEntrega.foto_pago"
                class="w-full h-32 object-cover rounded-lg border border-gray-200 cursor-pointer"
                @click="verFactura = despachoEntrega.foto_pago"
              />
            </div>
          </div>

          <div class="flex items-center justify-between text-sm pt-2 border-t border-gray-100">
            <span class="text-gray-500">Conductor</span>
            <span class="font-medium text-gray-800">{{ despachoEntrega.despacho?.conductor?.nombre }}</span>
          </div>
          <div v-if="despachoEntrega.entregado_at" class="flex items-center justify-between text-sm">
            <span class="text-gray-500">Entregado el</span>
            <span class="font-medium text-gray-800">{{ formatDateTime(despachoEntrega.entregado_at) }}</span>
          </div>
        </template>
      </div>

      <!-- Historial de pagos -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Historial de pagos ({{ orden.pagos?.length ?? 0 }})</p>
        <div v-if="orden.pagos?.length === 0" class="text-sm text-gray-400 text-center py-4">
          No hay pagos registrados.
        </div>
        <div
          v-for="pago in orden.pagos"
          :key="pago.id"
          class="border-b border-gray-100 last:border-0 pb-3 last:pb-0"
        >
          <div class="flex justify-between items-start">
            <div>
              <p class="text-sm font-medium text-gray-800">
                {{ tipoPagoLabel[pago.tipo] ?? pago.tipo }}
              </p>
              <p class="text-xs text-gray-400 capitalize">{{ pago.metodo }}
                <span v-if="pago.referencia">· {{ pago.referencia }}</span>
              </p>
              <p v-if="pago.notas" class="text-xs text-gray-400">{{ pago.notas }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm font-semibold text-green-600"><MoneyDisplay :amount="pago.monto" /></p>
              <p class="text-xs text-gray-400">{{ formatDateTime(pago.created_at) }}</p>
            </div>
          </div>

          <!-- Indicador de facturación: visible para todos -->
          <div v-if="pago.facturacion_hecha_at" class="mt-2 flex items-center gap-1.5 text-xs text-green-700 bg-green-50 rounded-lg px-2.5 py-1.5 w-fit">
            <CheckBadgeIcon class="w-3.5 h-3.5" />
            Facturado · {{ formatDateTime(pago.facturacion_hecha_at) }}
          </div>

          <!-- Acciones de facturación: solo para el facturador -->
          <div v-else-if="auth.isFacturador" class="mt-2">
            <!-- Tomado por mí → marcar hecha -->
            <button
              v-if="pago.facturacion_tomada_por?.id === auth.usuario?.id"
              @click="doMarcarFacturada(pago.id)"
              :disabled="facturacionLoading[pago.id]"
              class="flex items-center gap-1.5 text-xs font-semibold text-white bg-green-600 hover:bg-green-700 disabled:opacity-50 rounded-lg px-3 py-1.5 transition-colors"
            >
              <CheckBadgeIcon class="w-3.5 h-3.5" />
              {{ facturacionLoading[pago.id] ? 'Guardando...' : 'Marcar factura hecha' }}
            </button>
            <!-- Tomado por otro -->
            <div v-else-if="pago.facturacion_tomada_por" class="flex items-center gap-1.5 text-xs text-amber-700 bg-amber-50 rounded-lg px-2.5 py-1.5 w-fit">
              <LockClosedIcon class="w-3.5 h-3.5" />
              Tomado por {{ pago.facturacion_tomada_por.nombre }}
            </div>
            <!-- Sin tomar → tomar -->
            <button
              v-else
              @click="doTomarFacturacion(pago.id)"
              :disabled="facturacionLoading[pago.id]"
              class="flex items-center gap-1.5 text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 disabled:opacity-50 rounded-lg px-3 py-1.5 transition-colors"
            >
              {{ facturacionLoading[pago.id] ? 'Procesando...' : 'Tomar facturación' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Asignar fechas de entrega (solo supervisor, mientras falten fechas) -->
      <div v-if="auth.isSupervisor && !todasFechasAsignadas" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Asignar fechas de entrega</p>
        <div
          v-for="item in orden.items"
          :key="item.id"
          class="space-y-1"
        >
          <label class="text-xs font-medium text-gray-600">{{ item.producto?.nombre ?? item.nombre_custom ?? 'Producto personalizado' }}</label>
          <input
            v-model="fechasEdicion[item.id]"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <button
          @click="guardarFechas"
          :disabled="guardandoFechas"
          class="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          {{ guardandoFechas ? 'Guardando...' : 'Guardar fechas' }}
        </button>
      </div>

      <!-- Historial de ediciones -->
      <div v-if="orden.ediciones?.length" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
          <ClockIcon class="w-3.5 h-3.5" />
          Historial de ediciones ({{ orden.ediciones.length }})
        </p>
        <div
          v-for="edicion in orden.ediciones"
          :key="edicion.id"
          class="border-b border-gray-100 last:border-0 pb-3 last:pb-0"
        >
          <div class="flex justify-between items-center mb-1.5">
            <span class="text-xs font-semibold text-gray-700">{{ edicion.usuario?.nombre }}</span>
            <span class="text-[11px] text-gray-400">{{ formatDateTime(edicion.created_at) }}</span>
          </div>
          <ul class="space-y-1">
            <li v-for="cambio in edicion.cambios" :key="cambio.campo" class="text-xs text-gray-600 leading-snug">
              <span class="font-medium">{{ cambio.label }}:</span>
              <span class="text-red-500 line-through ml-1">{{ formatCambioVal(cambio.antes) }}</span>
              <span class="mx-1 text-gray-400">→</span>
              <span class="text-green-600">{{ formatCambioVal(cambio.despues) }}</span>
            </li>
          </ul>
        </div>
      </div>

      <!-- Compartir cotización -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Compartir cotización</p>

        <!-- Aviso: fechas pendientes -->
        <div
          v-if="!todasFechasAsignadas"
          class="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5"
        >
          <CalendarIcon class="w-4 h-4 mt-0.5 text-amber-500 flex-shrink-0" />
          <p class="text-xs text-amber-700 leading-snug">
            El supervisor debe asignar las fechas de entrega antes de compartir la cotización con el cliente.
          </p>
        </div>

        <template v-if="todasFechasAsignadas">
          <div class="flex gap-2">
            <!-- WhatsApp -->
            <button
              v-if="orden.cliente?.telefono"
              @click="abrirWhatsApp"
              class="flex-1 flex items-center justify-center gap-2 bg-green-500 hover:bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold transition-colors"
            >
              <ChatBubbleLeftEllipsisIcon class="w-4 h-4" />
              WhatsApp
            </button>

            <!-- Email -->
            <button
              v-if="orden.cliente?.email"
              @click="enviarEmail()"
              :disabled="enviandoEmail"
              class="flex-1 flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold transition-colors"
            >
              <EnvelopeIcon class="w-4 h-4" />
              {{ enviandoEmail ? 'Enviando...' : 'Enviar email' }}
            </button>

            <!-- Si no hay email registrado: opción de ingresar uno -->
            <button
              v-else
              @click="mostrarEmailManual = !mostrarEmailManual"
              class="flex-1 flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg py-2.5 text-sm font-semibold transition-colors"
            >
              <EnvelopeIcon class="w-4 h-4" />
              Email manual
            </button>
          </div>

          <!-- Email manual (si el cliente no tiene email guardado) -->
          <div v-if="mostrarEmailManual" class="flex gap-2">
            <input
              v-model="emailManual"
              type="email"
              placeholder="correo@ejemplo.com"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <button
              @click="enviarEmail(emailManual)"
              :disabled="enviandoEmail || !emailManual"
              class="bg-blue-600 text-white px-4 rounded-lg text-sm font-semibold disabled:opacity-50 hover:bg-blue-700 transition-colors"
            >
              {{ enviandoEmail ? '...' : 'Enviar' }}
            </button>
          </div>

          <!-- Sin teléfono ni email -->
          <p
            v-if="!orden.cliente?.telefono && !orden.cliente?.email"
            class="text-xs text-gray-400 text-center py-1"
          >
            El cliente no tiene teléfono ni email registrado.
          </p>
        </template>
      </div>

      <!-- Aviso: orden en despacho -->
      <div
        v-if="orden.estado === 'listo_entrega'"
        class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 flex items-start gap-3"
      >
        <TruckIcon class="w-5 h-5 mt-0.5 text-purple-600 flex-shrink-0" />
        <div>
          <p class="text-sm font-semibold text-purple-800">Orden en cola de despacho</p>
          <p class="text-xs text-purple-600 mt-0.5">Esta orden está lista para entregar. El supervisor debe asignarla a un conductor desde el módulo de Despacho.</p>
        </div>
      </div>

      <div
        v-if="orden.estado === 'en_camino'"
        class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 flex items-start gap-3"
      >
        <TruckIcon class="w-5 h-5 mt-0.5 text-purple-600 flex-shrink-0" />
        <div>
          <p class="text-sm font-semibold text-purple-800">Orden en ruta de despacho</p>
          <p class="text-xs text-purple-600 mt-0.5">Esta orden fue asignada a un conductor para entrega. El estado se actualizará cuando el conductor la marque como entregada.</p>
        </div>
      </div>

      <!-- Acciones -->
      <div v-if="puedeCambiarEstado || puedeRegistrarPago || (tienePersonalizados && !['entregado','cancelado'].includes(orden.estado))" class="space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Acciones</p>

        <!-- Registrar pago -->
        <button
          v-if="puedeRegistrarPago"
          @click="showPagoModal = true"
          class="w-full bg-blue-600 text-white rounded-xl py-3 text-sm font-semibold hover:bg-blue-700 transition-colors"
        >
          Registrar pago
        </button>

        <!-- Aviso: estado controlado por Producción -->
        <div
          v-if="tienePersonalizados && !['entregado','cancelado'].includes(orden.estado)"
          class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 flex items-start gap-3"
        >
          <BuildingOffice2Icon class="w-5 h-5 mt-0.5 text-purple-600 flex-shrink-0" />
          <div>
            <p class="text-sm font-semibold text-purple-800">Estado gestionado desde Producción</p>
            <p class="text-xs text-purple-600 mt-0.5">Esta orden tiene ítems personalizados. El estado se actualiza automáticamente al cambiar el avance en el módulo de Producción.</p>
          </div>
        </div>

        <!-- Cambiar estado (solo órdenes sin personalizados) -->
        <div v-if="puedeCambiarEstado" class="space-y-2">
          <div class="flex gap-2">
            <select
              v-model="nuevoEstado"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Cambiar estado...</option>
              <option v-for="opt in opcionesNuevoEstado" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
            <button
              @click="cambiarEstado"
              :disabled="!nuevoEstado || changingEstado"
              class="bg-gray-800 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-gray-900 disabled:opacity-50 transition-colors"
            >Aplicar</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Modal de pago -->
    <RegistroPagoModal
      v-if="orden"
      :show="showPagoModal"
      :orden-id="orden.id"
      :valor-total="orden.valor_total"
      :saldo-pendiente="orden.saldo_pendiente"
      @close="showPagoModal = false"
      @pago-registrado="onPagoRegistrado"
    />

    <!-- Modal de edición -->
    <EditarOrdenModal
      v-if="orden"
      :show="showEditarModal"
      :orden="orden"
      @close="showEditarModal = false"
      @guardado="onOrdenEditada"
    />

    <!-- Modal confirmar cotización (firma + anticipo) -->
    <Transition name="fade">
      <div v-if="showModalConfirmar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showModalConfirmar = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[90vh] overflow-y-auto">
          <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
            <CheckCircleIcon class="w-5 h-5 text-green-600" />
            Confirmar precio aceptado
          </h3>

          <!-- Foto comprobante -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">
              Foto del comprobante <span class="text-red-500">*</span>
            </label>
            <div v-if="facturaConfirmarFile" class="space-y-1.5">
              <div class="relative">
                <img
                  :src="facturaConfirmarPreview"
                  class="w-full rounded-xl border-2 border-gray-200 object-contain bg-gray-50 max-h-40"
                />
                <button
                  @click="quitarFacturaConfirmar"
                  class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow"
                >
                  <XMarkIcon class="w-3.5 h-3.5" />
                </button>
              </div>
              <p class="text-xs text-gray-400 truncate">{{ facturaConfirmarFile.name }}</p>
            </div>
            <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-gray-300 rounded-xl p-4 cursor-pointer hover:border-green-400 hover:bg-green-50 transition-colors">
              <DocumentIcon class="w-7 h-7 text-gray-300" />
              <span class="text-sm text-gray-500">Toca para adjuntar comprobante</span>
              <input type="file" accept="image/*" @change="onFacturaConfirmarChange" class="hidden" />
            </label>
          </div>

          <!-- Firma -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">
              Firma del cliente <span class="text-red-500">*</span>
            </label>
            <FirmaCanvas v-model="firmaConfirmarBlob" />
            <p v-if="!firmaConfirmarBlob" class="text-xs text-amber-600">Se requiere la firma para confirmar.</p>
          </div>

          <!-- Anticipo -->
          <div class="space-y-2">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Anticipo</label>

            <!-- Total acordado -->
            <div class="flex justify-between text-sm bg-gray-50 rounded-lg px-3 py-2">
              <span class="text-gray-500">Total acordado</span>
              <span class="font-bold text-gray-800">
                {{ new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(totalAcordado) }}
              </span>
            </div>

            <!-- Porcentaje -->
            <div>
              <label class="block text-xs text-gray-500 mb-1">Porcentaje mínimo anticipo</label>
              <div class="flex gap-2">
                <button
                  v-for="pct in [30, 50, 70, 100]"
                  :key="pct"
                  @click="seleccionarPctAnticipo(pct)"
                  :class="['flex-1 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                    anticipoPctConfirmar === pct ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300']"
                >{{ pct }}%</button>
              </div>
            </div>

            <!-- Monto -->
            <div>
              <label class="block text-xs text-gray-500 mb-1">
                Monto anticipo
                <span class="text-gray-400">(mínimo {{ new Intl.NumberFormat('es-CO').format(minimoAnticipoc) }})</span>
              </label>
              <input
                v-model.number="anticipoConfirmar"
                type="number"
                min="0"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
              />
            </div>

            <!-- Método -->
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="m in metodosOpts"
                :key="m.value"
                @click="metodoPagoConfirmar = m.value"
                :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                  metodoPagoConfirmar === m.value ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300']"
              >{{ m.label }}</button>
            </div>
            <input
              v-if="metodoPagoConfirmar !== 'efectivo'"
              v-model="refPagoConfirmar"
              type="text"
              placeholder="Referencia / número transacción"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
            />
          </div>

          <div class="flex gap-3">
            <button @click="showModalConfirmar = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="doConfirmarCotizacion"
              :disabled="!firmaConfirmarBlob || !facturaConfirmarFile || confirmando"
              class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-40 transition-colors"
            >
              {{ confirmando ? 'Confirmando...' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal solicitar cotización -->
    <Transition name="fade">
      <div v-if="showModalCotizar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showModalCotizar = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
            <CurrencyDollarIcon class="w-5 h-5 text-violet-600" />
            Solicitar cotización
          </h3>
          <p class="text-sm text-gray-600">
            Selecciona a quién le envías la consulta de costo para los ítems personalizados de esta orden.
          </p>

          <div class="space-y-2">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Enviar a</label>
            <div class="space-y-2">
              <label
                v-for="r in receptores"
                :key="r.id"
                :class="[
                  'flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-colors',
                  cotizarReceptorId === r.id ? 'border-violet-400 bg-violet-50' : 'border-gray-200 hover:border-gray-300'
                ]"
              >
                <input type="radio" :value="r.id" v-model="cotizarReceptorId" class="accent-violet-600" />
                <div>
                  <p class="text-sm font-semibold text-gray-800">{{ r.nombre }}</p>
                  <p class="text-xs text-gray-400 capitalize">{{ r.rol }}</p>
                </div>
              </label>
              <p v-if="!receptores.length" class="text-xs text-gray-400 text-center py-2">
                No hay supervisores ni ebanistas activos.
              </p>
            </div>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Notas adicionales (opcional)</label>
            <textarea
              v-model="cotizarNotas"
              rows="3"
              placeholder="Instrucciones especiales, materiales sugeridos, urgencia..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 resize-none"
            />
          </div>

          <div class="flex gap-3">
            <button @click="showModalCotizar = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="enviarSolicitudCotizacion"
              :disabled="!cotizarReceptorId || enviandoCotizacion"
              class="flex-1 bg-violet-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-violet-700 disabled:opacity-40 transition-colors"
            >
              {{ enviandoCotizacion ? 'Enviando...' : 'Enviar consulta' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Lightbox boceto -->
    <Transition name="fade">
      <div
        v-if="bocetoModal"
        class="fixed inset-0 z-[60] flex items-center justify-center p-6"
        @click.self="bocetoModal = ''"
      >
        <div class="absolute inset-0 bg-black/85" @click="bocetoModal = ''" />
        <div class="relative w-full max-w-lg">
          <button
            @click="bocetoModal = ''"
            class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg"
          >
            <XMarkIcon class="w-5 h-5 text-gray-700" />
          </button>
          <div class="bg-white rounded-2xl overflow-hidden shadow-2xl p-2">
            <img :src="bocetoModal" alt="Boceto" class="w-full object-contain max-h-[70vh] rounded-xl" />
            <button
              @click="descargarBoceto(bocetoModal)"
              class="mt-2 w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2 text-sm font-semibold transition-colors"
            >
              <ArrowDownTrayIcon class="w-4 h-4" />
              Descargar boceto
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Lightbox foto factura -->
    <Transition name="fade">
      <div
        v-if="verFactura"
        class="fixed inset-0 z-[60] flex items-center justify-center p-6"
        @click.self="verFactura = false"
      >
        <div class="absolute inset-0 bg-black/85" @click="verFactura = false" />
        <div class="relative w-full max-w-lg">
          <button
            @click="verFactura = false"
            class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg"
          >
            <XMarkIcon class="w-5 h-5 text-gray-700" />
          </button>
          <div class="bg-white rounded-2xl overflow-hidden shadow-2xl">
            <img
              :src="verFactura"
              alt="Foto"
              class="w-full object-contain max-h-96"
            />
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
