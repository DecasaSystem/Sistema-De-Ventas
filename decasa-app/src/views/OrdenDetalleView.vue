<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { getOrden, updateEstado, descargarPdfOrden, reenviarCotizacion, asignarFechasEntrega } from '@/api/ordenes'
import { despachoPorOrden } from '@/api/despacho'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import RegistroPagoModal from '@/components/ordenes/RegistroPagoModal.vue'
import EditarOrdenModal from '@/components/ordenes/EditarOrdenModal.vue'
import { SparklesIcon, XMarkIcon } from '@heroicons/vue/24/solid'
import { DocumentIcon, EnvelopeIcon, ChatBubbleLeftEllipsisIcon, ArrowDownTrayIcon, CalendarIcon, BuildingOffice2Icon, TruckIcon, PencilSquareIcon, ClockIcon } from '@heroicons/vue/24/outline'

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
  pendiente_anticipo: ['en_produccion', 'listo_entrega', 'cancelado'],
  en_produccion: ['listo_entrega', 'cancelado'],
  listo_entrega: [],
  en_camino: [],
  entregado: [],
  cancelado: [],
}

const nuevoEstado = ref('')

const estadosLabel = {
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
    .map(i => `  • ${i.producto?.nombre ?? '—'} x${i.cantidad}`)
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
              <p class="font-medium text-sm text-gray-800">{{ item.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.producto?.categoria }}</p>
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
          class="flex justify-between items-center border-b border-gray-100 last:border-0 pb-2 last:pb-0"
        >
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
      </div>

      <!-- Asignar fechas de entrega (solo supervisor, mientras falten fechas) -->
      <div v-if="auth.isSupervisor && !todasFechasAsignadas" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Asignar fechas de entrega</p>
        <div
          v-for="item in orden.items"
          :key="item.id"
          class="space-y-1"
        >
          <label class="text-xs font-medium text-gray-600">{{ item.producto?.nombre }}</label>
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
