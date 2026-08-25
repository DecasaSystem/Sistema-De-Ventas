<script setup>
import { cloudinaryOpt } from '@/utils/cloudinary'
import { ref, computed, watch, onUnmounted } from 'vue'
import { detalleEntrega, registrarPagoEntrega, marcarEntregado } from '@/api/despacho'
import { useToast } from '@/composables/useToast'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import { CheckCircleIcon, MapPinIcon, ClockIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

function compressImage(file, maxWidth = 1280, quality = 0.75) {
  return new Promise((resolve) => {
    const img = new Image()
    const url = URL.createObjectURL(file)
    img.onload = () => {
      URL.revokeObjectURL(url)
      let { width, height } = img
      if (width > maxWidth) {
        height = Math.round((height * maxWidth) / width)
        width = maxWidth
      }
      const canvas = document.createElement('canvas')
      canvas.width = width
      canvas.height = height
      canvas.getContext('2d').drawImage(img, 0, 0, width, height)
      canvas.toBlob(resolve, 'image/jpeg', quality)
    }
    img.src = url
  })
}

const props = defineProps({
  despachoItemId: { type: Number, required: true },
})
const emit = defineEmits(['cerrar', 'entregado'])

const toast = useToast()

const item      = ref(null)
const cargando  = ref(true)
const registrando = ref(false)

const esEntregado = computed(() => item.value?.estado === 'entregado')
const tieneSaldo  = computed(() => (item.value?.orden?.saldo_pendiente ?? 0) > 0.01)

// Formulario de pago
const monto      = ref(0)
const metodo     = ref('efectivo')
const referencia = ref('')
const fotoProducto        = ref(null)
const fotoPago            = ref(null)
const fotoAnexo           = ref(null)
const fotoProductoPreview = ref(null)
const fotoPagoPreview     = ref(null)
const fotoAnexoPreview    = ref(null)

// ── Acta de satisfacción ─────────────────────────────────────────────────────
// Quien recibe firma que el producto llegó y en qué estado. No siempre es el
// cliente: puede ser un familiar, la empleada o el portero, por eso se pide
// nombre y cédula de quien efectivamente está firmando.
const firmaBlob      = ref(null)
const recibidoNombre = ref('')
const recibidoCedula = ref('')
const conforme       = ref(true)
const observaciones  = ref('')
const fotoNovedad        = ref(null)
const fotoNovedadPreview = ref(null)
const noHayQuienFirme = ref(false)
const motivoSinFirma  = ref('')

const actaCompleta = computed(() => {
  if (noHayQuienFirme.value) return motivoSinFirma.value.trim().length > 0
  if (!firmaBlob.value) return false
  if (!recibidoNombre.value.trim()) return false
  // "Con novedad" sin decir cuál no sirve de nada cuando llegue el reclamo
  if (!conforme.value && !observaciones.value.trim()) return false
  return true
})

// ── Lo que se devuelve ───────────────────────────────────────────────────────
// "Con novedad" es que llegó rayado y el cliente se lo quedó igual. Esto es
// otra cosa: el producto se regresa en el camión. Va por pieza porque puede
// llevar la cama y dos mesas y volver solo la cama.
const hayDevolucion   = ref(false)
const motivoDevolucion = ref('')
const fotoDevolucion        = ref(null)
const fotoDevolucionPreview = ref(null)
// { [orden_item_id]: cantidad que vuelve }
const devueltos = ref({})

const itemsOrden = computed(() => item.value?.orden?.items ?? [])

function alternarDevuelto(oi) {
  if (devueltos.value[oi.id]) {
    delete devueltos.value[oi.id]
  } else {
    // Casi siempre vuelve todo lo de esa línea; si vuelve solo una de dos, se
    // baja a mano.
    devueltos.value[oi.id] = oi.cantidad
  }
}

function nombreItem(oi) {
  return oi.nombre_custom || oi.producto?.nombre || 'Producto'
}

const piezasDevueltas = computed(() =>
  Object.values(devueltos.value).reduce((s, n) => s + (Number(n) || 0), 0)
)

// Si vuelve absolutamente todo, no hay nada que cobrarle al cliente.
const devuelveTodo = computed(() => {
  if (!hayDevolucion.value || !itemsOrden.value.length) return false
  return itemsOrden.value.every(oi => (Number(devueltos.value[oi.id]) || 0) >= oi.cantidad)
})

const devolucionCompleta = computed(() => {
  if (!hayDevolucion.value) return true
  if (!piezasDevueltas.value) return false
  return motivoDevolucion.value.trim().length >= 3
})

function onFotoDevolucion(e) {
  const file = e.target.files?.[0]
  if (!file) return
  compressImage(file).then(blob => {
    fotoDevolucion.value = blob
    fotoDevolucionPreview.value = URL.createObjectURL(blob)
  })
}

// ── Descuento que se pierde al pagar con tarjeta ──────────────────────────────
// Viene del backend cuando la orden tiene descuento por pago en efectivo o
// transferencia. Si el conductor elige tarjeta, el cliente pierde el descuento
// y el monto a cobrar sube: se fija y no se puede bajar.
const descuentoCond = computed(() => item.value?.descuento_condicionado ?? null)

const pierdeDescuento = computed(() => {
  if (!descuentoCond.value) return false
  return !descuentoCond.value.metodos_que_lo_conservan.includes(metodo.value)
})

const montoACobrar = computed(() =>
  pierdeDescuento.value
    ? Number(descuentoCond.value.saldo_sin_descuento)
    : Number(item.value?.orden?.saldo_pendiente ?? 0)
)

// Al cambiar el método el monto se recalcula solo: con tarjeta queda bloqueado
// en el total sin descuento para que no se cobre de menos.
watch([metodo, descuentoCond], () => {
  if (esEntregado.value) return
  monto.value = montoACobrar.value
})

const puedeEntregar = computed(() => {
  if (!fotoProductoPreview.value) return false
  if (!actaCompleta.value) return false
  if (!devolucionCompleta.value) return false
  // Si vuelve todo, no se le cobra nada: no se le puede pedir al conductor un
  // comprobante de un pago que no existe.
  if (devuelveTodo.value) return true
  if (tieneSaldo.value) return !!fotoPagoPreview.value && monto.value > 0
  return true  // sin saldo: foto del producto y acta firmada
})

const mensajeBoton = computed(() => {
  if (!fotoProductoPreview.value) return 'Sube la foto del producto para continuar'
  if (hayDevolucion.value) {
    if (!piezasDevueltas.value)              return 'Marca qué se devuelve'
    if (motivoDevolucion.value.trim().length < 3) return 'Escribe por qué se devuelve'
  }
  if (tieneSaldo.value && !devuelveTodo.value) {
    if (!fotoPagoPreview.value) return 'Sube la foto del comprobante de pago'
    if (!(monto.value > 0))     return 'Ingresa el monto cobrado'
  }
  if (noHayQuienFirme.value && !motivoSinFirma.value.trim()) return 'Explica por qué nadie pudo firmar'
  if (!noHayQuienFirme.value) {
    if (!recibidoNombre.value.trim()) return 'Escribe el nombre de quien recibe'
    if (!firmaBlob.value)             return 'Falta la firma de quien recibe'
    if (!conforme.value && !observaciones.value.trim()) return 'Describe la novedad del producto'
  }
  return null
})

const METODO_LABEL = {
  efectivo: 'Efectivo', transferencia: 'Transferencia',
  tarjeta: 'Tarjeta', otro: 'Otro',
}

function fmtFecha(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('es-CO', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

watch(() => props.despachoItemId, async (id) => {
  if (!id) return
  await cargar(id)
}, { immediate: true })

async function cargar(id) {
  cargando.value = true
  try {
    const { data } = await detalleEntrega(id)
    item.value = data
    if (!esEntregado.value) {
      monto.value = data.orden?.saldo_pendiente || 0
      // Se precarga el cliente: en la mayoría de entregas recibe él mismo, y si
      // no, el conductor lo cambia por quien esté firmando.
      recibidoNombre.value = data.orden?.cliente?.nombre ?? ''
      recibidoCedula.value = data.orden?.cliente?.cedula ?? ''
    }
  } catch {} finally {
    cargando.value = false
  }
}

const _blobUrls = []
function _createPreviewUrl(blob) {
  const url = URL.createObjectURL(blob)
  _blobUrls.push(url)
  return url
}
onUnmounted(() => _blobUrls.forEach(u => URL.revokeObjectURL(u)))

async function onFotoProducto(e) {
  const file = e.target.files[0]
  if (!file) return
  const blob = await compressImage(file)
  fotoProducto.value = blob
  fotoProductoPreview.value = _createPreviewUrl(blob)
}

async function onFotoPago(e) {
  const file = e.target.files[0]
  if (!file) return
  const blob = await compressImage(file)
  fotoPago.value = blob
  fotoPagoPreview.value = _createPreviewUrl(blob)
}

async function onFotoAnexo(e) {
  const file = e.target.files[0]
  if (!file) return
  const blob = await compressImage(file)
  fotoAnexo.value = blob
  fotoAnexoPreview.value = _createPreviewUrl(blob)
}

async function onFotoNovedad(e) {
  const file = e.target.files[0]
  if (!file) return
  const blob = await compressImage(file)
  fotoNovedad.value = blob
  fotoNovedadPreview.value = _createPreviewUrl(blob)
}

async function guardarPagoYEntregar() {
  if (!puedeEntregar.value) return
  registrando.value = true
  try {
    const fd = new FormData()
    fd.append('foto_producto', fotoProducto.value, 'foto_producto.jpg')

    // ── Lo que se regresa en el camión ──────────────────────────────────────
    // Va antes del pago porque lo cambia: si vuelve todo, no se cobra nada.
    if (hayDevolucion.value && piezasDevueltas.value) {
      fd.append('devoluciones', JSON.stringify(
        Object.entries(devueltos.value)
          .filter(([, cant]) => Number(cant) > 0)
          .map(([ordenItemId, cant]) => ({
            orden_item_id: Number(ordenItemId),
            cantidad: Number(cant),
            motivo: motivoDevolucion.value.trim(),
          }))
      ))
      if (fotoDevolucion.value) fd.append('foto_devolucion', fotoDevolucion.value, 'foto_devolucion.jpg')
    }

    if (tieneSaldo.value && !devuelveTodo.value) {
      fd.append('monto', monto.value)
      fd.append('metodo', metodo.value)
      if (referencia.value) fd.append('referencia', referencia.value)
      fd.append('foto_pago', fotoPago.value, 'foto_pago.jpg')
    } else {
      fd.append('monto', '0')
    }
    if (fotoAnexo.value) fd.append('foto_anexo', fotoAnexo.value, 'foto_anexo.jpg')

    // ── Acta de satisfacción ────────────────────────────────────────────────
    if (noHayQuienFirme.value) {
      fd.append('firma_omitida_motivo', motivoSinFirma.value.trim())
    } else {
      fd.append('firma_recibido', firmaBlob.value, 'firma_recibido.png')
      fd.append('recibido_por_nombre', recibidoNombre.value.trim())
      if (recibidoCedula.value.trim()) fd.append('recibido_por_cedula', recibidoCedula.value.trim())
      fd.append('conforme', conforme.value ? '1' : '0')
      if (!conforme.value) {
        fd.append('observaciones_entrega', observaciones.value.trim())
        if (fotoNovedad.value) fd.append('foto_novedad', fotoNovedad.value, 'foto_novedad.jpg')
      }
    }

    await registrarPagoEntrega(props.despachoItemId, fd)
    await marcarEntregado(props.despachoItemId)
    toast.success('Entrega completada exitosamente')
    emit('entregado')
    emit('cerrar')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Error al procesar la entrega')
  } finally {
    registrando.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
    <div class="fixed inset-0 bg-black/40" @click="emit('cerrar')" />

    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg max-h-[90vh] overflow-y-auto z-10">
      <!-- Header -->
      <div class="sticky top-0 bg-white border-b border-gray-100 px-5 py-3 flex items-center justify-between rounded-t-2xl">
        <div class="flex items-center gap-2">
          <CheckCircleIcon v-if="esEntregado" class="w-5 h-5 text-green-500" />
          <h3 class="text-lg font-bold text-gray-900">
            {{ esEntregado ? 'Detalle de entrega' : 'Registrar entrega' }}
          </h3>
        </div>
        <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
      </div>

      <div v-if="cargando" class="p-8 text-center text-sm text-gray-400">Cargando...</div>

      <template v-else-if="item">
        <div class="p-5 space-y-5">

          <!-- Info del cliente -->
          <div class="bg-gray-50 rounded-xl p-4 space-y-1.5">
            <p class="font-bold text-gray-900">{{ item.orden?.cliente?.nombre }}</p>
            <p class="text-sm text-gray-500">{{ item.orden?.cliente?.telefono }}</p>

            <div v-if="item.orden?.direccion_envio" class="flex items-start gap-1.5 text-sm text-gray-600 mt-1">
              <MapPinIcon class="w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5" />
              <span>
                {{ item.orden.direccion_envio }}
                <span v-if="item.orden.ciudad_envio">, {{ item.orden.ciudad_envio }}</span>
              </span>
            </div>
            <p v-else class="text-sm text-gray-500 flex items-center gap-1">
              <MapPinIcon class="w-4 h-4 text-gray-400" />
              {{ item.orden?.cliente?.direccion }}
            </p>

            <div class="flex items-center gap-4 mt-2 text-sm">
              <span class="text-gray-600">
                Total: <MoneyDisplay :amount="item.orden?.valor_total" :bold="true" />
              </span>
              <span v-if="!esEntregado && tieneSaldo" class="text-orange-600 font-semibold">
                Cobra: <MoneyDisplay :amount="montoACobrar" />
              </span>
              <span v-else-if="!esEntregado" class="text-green-600 text-xs font-semibold">✓ Ya pagado</span>
            </div>

            <div v-if="esEntregado && item.entregado_at" class="flex items-center gap-1.5 text-sm text-green-600 pt-1 border-t border-gray-200 mt-1">
              <ClockIcon class="w-4 h-4" />
              Entregado el {{ fmtFecha(item.entregado_at) }}
            </div>
          </div>

          <!-- Notas del supervisor -->
          <div v-if="item.despacho?.notas" class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3">
            <p class="text-xs font-semibold text-amber-700 mb-1">Notas del despacho</p>
            <p class="text-sm text-amber-800">{{ item.despacho.notas }}</p>
          </div>

          <!-- Productos -->
          <div v-if="item.orden?.items?.length">
            <h4 class="text-sm font-semibold text-gray-700 mb-2">Productos</h4>
            <div class="space-y-2">
              <div v-for="p in item.orden.items" :key="p.id" class="flex items-center gap-3">
                <img
                  v-if="p.producto?.foto_url"
                  :src="cloudinaryOpt(p.producto.foto_url, 96)"
                  :alt="p.producto.nombre"
                  class="w-12 h-12 rounded-lg object-cover border border-gray-100 flex-shrink-0"
                />
                <div v-else class="w-12 h-12 rounded-lg bg-gray-100 flex-shrink-0" />
                <div>
                  <p class="text-sm font-medium text-gray-800">{{ p.producto?.nombre }}</p>
                  <p class="text-xs text-gray-400">x{{ p.cantidad }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- ── MODO LECTURA (entregado) ─────────────────────────────────── -->
          <template v-if="esEntregado">
            <div v-if="item.orden?.pagos?.length" class="space-y-2">
              <h4 class="text-sm font-semibold text-gray-700">Pago registrado</h4>
              <div
                v-for="pago in item.orden.pagos"
                :key="pago.id"
                class="bg-green-50 border border-green-100 rounded-xl px-4 py-3 flex items-center justify-between"
              >
                <div>
                  <p class="text-sm font-semibold text-green-800"><MoneyDisplay :amount="pago.monto" /></p>
                  <p class="text-xs text-green-600">
                    {{ METODO_LABEL[pago.metodo] ?? pago.metodo }}
                    <span v-if="pago.referencia"> · {{ pago.referencia }}</span>
                  </p>
                </div>
                <p class="text-xs text-gray-400">{{ fmtFecha(pago.created_at) }}</p>
              </div>
            </div>

            <div v-if="item.foto_producto || item.foto_pago">
              <h4 class="text-sm font-semibold text-gray-700 mb-2">Fotos de evidencia</h4>
              <div class="grid grid-cols-2 gap-3">
                <div v-if="item.foto_producto">
                  <p class="text-xs text-gray-500 mb-1">Producto</p>
                  <a :href="item.foto_producto" target="_blank">
                    <img :src="cloudinaryOpt(item.foto_producto, 600)" class="w-full h-28 object-cover rounded-xl border border-gray-100" />
                  </a>
                </div>
                <div v-if="item.foto_pago">
                  <p class="text-xs text-gray-500 mb-1">Comprobante</p>
                  <a :href="item.foto_pago" target="_blank">
                    <img :src="cloudinaryOpt(item.foto_pago, 600)" class="w-full h-28 object-cover rounded-xl border border-gray-100" />
                  </a>
                </div>
              </div>
            </div>

            <button @click="emit('cerrar')" class="w-full py-3 rounded-xl font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors">
              Cerrar
            </button>
          </template>

          <!-- ── MODO ACTIVO (pendiente) ──────────────────────────────────── -->
          <template v-else>

            <!-- Foto del producto — siempre obligatoria -->
            <div>
              <h4 class="text-sm font-semibold text-gray-700 mb-2">
                Foto del producto <span class="text-red-500">*</span>
                <span class="text-xs font-normal text-gray-400 ml-1">Evidencia de que llegó el mueble</span>
              </h4>
              <label class="block border-2 border-dashed rounded-xl p-3 text-center cursor-pointer transition-colors"
                :class="fotoProductoPreview ? 'border-green-400' : 'border-gray-300 hover:border-blue-400'"
              >
                <input type="file" accept="image/*" class="hidden" @change="onFotoProducto" />
                <img v-if="fotoProductoPreview" :src="fotoProductoPreview" class="w-full h-32 object-cover rounded-lg" />
                <span v-else class="text-sm text-gray-400">📷 Foto del producto entregado</span>
              </label>
            </div>

            <!-- Sección de pago — solo cuando hay saldo pendiente -->
            <template v-if="tieneSaldo">
              <div class="border-t border-gray-100 pt-4">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">
                  Registrar cobro <span class="text-red-500">*</span>
                </h4>
                <div class="space-y-3">
                  <div>
                    <label class="text-xs text-gray-500">Método de pago</label>
                    <select v-model="metodo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                      <option value="efectivo">Efectivo</option>
                      <option value="transferencia">Transferencia</option>
                      <option value="tarjeta">Tarjeta</option>
                      <option value="otro">Otro</option>
                    </select>
                  </div>

                  <!-- El descuento no aplica con este medio de pago -->
                  <div v-if="pierdeDescuento" class="bg-amber-50 border-2 border-amber-300 rounded-xl p-3 space-y-2">
                    <p class="text-sm font-bold text-amber-900">
                      Este pedido sube a <MoneyDisplay :amount="descuentoCond.valor_sin_descuento" />
                    </p>
                    <p class="text-sm text-amber-900 leading-snug">
                      {{ descuentoCond.explicacion }}
                    </p>
                    <div class="bg-white rounded-lg px-3 py-2">
                      <p class="text-xs text-gray-500">Debes cobrar</p>
                      <p class="text-lg font-bold text-amber-900">
                        <MoneyDisplay :amount="descuentoCond.saldo_sin_descuento" />
                      </p>
                    </div>
                    <p class="text-xs text-amber-700">
                      Muéstrale esta nota al cliente si pregunta por qué cambió el valor.
                      Si prefiere pagar en efectivo o transferencia, conserva el descuento.
                    </p>
                  </div>

                  <div>
                    <label class="text-xs text-gray-500">Monto cobrado</label>
                    <input
                      v-model.number="monto"
                      type="number"
                      step="0.01"
                      min="1"
                      :readonly="pierdeDescuento"
                      :class="['w-full border rounded-lg px-3 py-2 text-sm outline-none',
                        pierdeDescuento
                          ? 'border-amber-300 bg-amber-50 font-bold text-amber-900 cursor-not-allowed'
                          : 'border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500']"
                    />
                    <p v-if="pierdeDescuento" class="text-xs text-amber-700 mt-1">
                      No se puede cobrar menos: el descuento no aplica con este medio de pago.
                    </p>
                  </div>
                  <div>
                    <label class="text-xs text-gray-500">Referencia (opcional)</label>
                    <input v-model="referencia" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" />
                  </div>

                  <!-- Foto del comprobante — solo cuando hay saldo -->
                  <div>
                    <label class="text-xs text-gray-500 block mb-1">
                      Foto del comprobante de pago <span class="text-red-500">*</span>
                    </label>
                    <label class="block border-2 border-dashed rounded-xl p-3 text-center cursor-pointer transition-colors"
                      :class="fotoPagoPreview ? 'border-green-400' : 'border-gray-300 hover:border-blue-400'"
                    >
                      <input type="file" accept="image/*" class="hidden" @change="onFotoPago" />
                      <img v-if="fotoPagoPreview" :src="fotoPagoPreview" class="w-full h-28 object-cover rounded-lg" />
                      <span v-else class="text-sm text-gray-400">📷 Foto o pantallazo del comprobante</span>
                    </label>
                  </div>
                </div>
              </div>
            </template>

            <!-- Sin saldo: mensaje informativo -->
            <div v-else class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 text-sm text-green-700 font-medium text-center">
              ✓ Esta orden ya está completamente pagada — solo sube la foto del producto
            </div>

            <!-- Foto del anexo firmado — si aún no se ha subido -->
            <div v-if="!item.orden?.anexo_foto_url" class="border-t border-gray-100 pt-4">
              <h4 class="text-sm font-semibold text-gray-700 mb-1">
                Foto del anexo firmado
                <span class="text-xs font-normal text-gray-400 ml-1">(opcional)</span>
              </h4>
              <p class="text-xs text-gray-400 mb-2">Si el cliente firma el documento en la entrega, súbelo aquí.</p>
              <label class="block border-2 border-dashed rounded-xl p-3 text-center cursor-pointer transition-colors"
                :class="fotoAnexoPreview ? 'border-blue-400' : 'border-gray-300 hover:border-blue-400'"
              >
                <input type="file" accept="image/*" class="hidden" @change="onFotoAnexo" />
                <img v-if="fotoAnexoPreview" :src="fotoAnexoPreview" class="w-full h-28 object-cover rounded-lg" />
                <span v-else class="text-sm text-gray-400">📋 Foto del anexo firmado</span>
              </label>
            </div>

            <!-- Anexo ya subido -->
            <div v-else class="border-t border-gray-100 pt-4">
              <h4 class="text-sm font-semibold text-gray-700 mb-2">Anexo firmado</h4>
              <a :href="item.orden.anexo_foto_url" target="_blank">
                <img :src="cloudinaryOpt(item.orden.anexo_foto_url, 600)" class="w-full h-28 object-cover rounded-xl border border-gray-100" />
              </a>
            </div>

            <!-- ═══════════ ACTA DE SATISFACCIÓN ═══════════ -->
            <div class="border-t-2 border-emerald-100 pt-4 space-y-3">
              <div>
                <h4 class="text-sm font-bold text-gray-800">
                  Acta de satisfacción <span class="text-red-500">*</span>
                </h4>
                <p class="text-xs text-gray-500">
                  El cliente firma que recibió el producto y en qué estado llegó.
                </p>
              </div>

              <template v-if="!noHayQuienFirme">
                <!-- Quién recibe -->
                <div class="space-y-2">
                  <div>
                    <label class="text-xs text-gray-500">Nombre de quien recibe <span class="text-red-500">*</span></label>
                    <input
                      v-model="recibidoNombre"
                      placeholder="Nombre completo"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                    />
                    <p class="text-[11px] text-gray-400 mt-0.5">
                      Si no recibe el cliente sino otra persona, escribe su nombre.
                    </p>
                  </div>
                  <div>
                    <label class="text-xs text-gray-500">Cédula</label>
                    <input
                      v-model="recibidoCedula"
                      inputmode="numeric"
                      placeholder="Número de cédula"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                    />
                  </div>
                </div>

                <!-- ¿Cómo llegó? -->
                <div>
                  <label class="text-xs text-gray-500 block mb-1">¿Cómo llegó el producto?</label>
                  <div class="grid grid-cols-2 gap-2">
                    <button
                      type="button" @click="conforme = true"
                      :class="['py-2.5 rounded-xl text-sm font-semibold border-2 transition-colors',
                        conforme ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-600 border-gray-300']"
                    >Llegó bien</button>
                    <button
                      type="button" @click="conforme = false"
                      :class="['py-2.5 rounded-xl text-sm font-semibold border-2 transition-colors',
                        !conforme ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-gray-600 border-gray-300']"
                    >Con novedad</button>
                  </div>
                </div>

                <!-- Novedad -->
                <div v-if="!conforme" class="bg-amber-50 border border-amber-300 rounded-xl p-3 space-y-2">
                  <p class="text-xs font-semibold text-amber-900 flex items-center gap-1">
                    <ExclamationTriangleIcon class="w-4 h-4" />
                    ¿Qué pasó con el producto? <span class="text-red-500">*</span>
                  </p>
                  <textarea
                    v-model="observaciones"
                    rows="2"
                    placeholder="Ej. la mesa llegó rayada en una esquina"
                    class="w-full border border-amber-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-amber-500 outline-none"
                  />
                  <label class="block border-2 border-dashed border-amber-300 rounded-xl p-2 text-center cursor-pointer">
                    <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoNovedad" />
                    <img v-if="fotoNovedadPreview" :src="fotoNovedadPreview" class="w-full h-24 object-cover rounded-lg" />
                    <span v-else class="text-xs text-amber-700">📷 Foto de la novedad (opcional)</span>
                  </label>
                  <p class="text-[11px] text-amber-700">
                    Se avisa a supervisión apenas registres la entrega.
                  </p>
                </div>

                <!-- Firma -->
                <div>
                  <label class="text-xs text-gray-500 block mb-1">
                    Firma de quien recibe <span class="text-red-500">*</span>
                  </label>
                  <FirmaCanvas v-model="firmaBlob" />
                </div>
              </template>

              <!-- Nadie pudo firmar -->
              <div v-else class="bg-gray-50 border border-gray-300 rounded-xl p-3 space-y-2">
                <p class="text-xs font-semibold text-gray-700">¿Por qué no se pudo firmar?</p>
                <textarea
                  v-model="motivoSinFirma"
                  rows="2"
                  placeholder="Ej. se dejó con el vigilante del edificio, el cliente no estaba"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-gray-400 outline-none"
                />
                <p class="text-[11px] text-gray-500">
                  Queda registrado en la orden. Úsalo solo si de verdad no hay quien firme.
                </p>
              </div>

              <button
                type="button"
                @click="noHayQuienFirme = !noHayQuienFirme"
                class="text-xs text-gray-500 underline"
              >
                {{ noHayQuienFirme ? '← Volver a la firma' : 'No hay quien firme' }}
              </button>
            </div>

            <!-- ── Se devuelve en el camión ────────────────────────────────
                 Distinto de "con novedad": ahí el producto se queda en la casa
                 golpeado. Acá el cliente no se lo recibe y vuelve. -->
            <div class="border-t border-gray-200 pt-4 space-y-3">
              <label class="flex items-start gap-3 cursor-pointer">
                <input
                  type="checkbox"
                  v-model="hayDevolucion"
                  class="mt-0.5 rounded border-gray-300 text-orange-600 focus:ring-orange-500"
                />
                <span>
                  <span class="text-sm font-semibold text-gray-800">El cliente devuelve algo</span>
                  <span class="block text-[11px] text-gray-500">
                    El producto se regresa en el camión. Distinto de recibirlo con novedad y quedárselo.
                  </span>
                </span>
              </label>

              <div v-if="hayDevolucion" class="bg-orange-50 border border-orange-300 rounded-xl p-3 space-y-3">
                <p class="text-xs font-semibold text-orange-900">¿Qué se devuelve?</p>

                <div class="space-y-1.5">
                  <div
                    v-for="oi in itemsOrden" :key="oi.id"
                    :class="['flex items-center gap-2 rounded-lg px-2 py-1.5 border transition-colors',
                      devueltos[oi.id] ? 'bg-white border-orange-400' : 'bg-white/60 border-transparent']"
                  >
                    <input
                      type="checkbox"
                      :checked="!!devueltos[oi.id]"
                      @change="alternarDevuelto(oi)"
                      class="rounded border-gray-300 text-orange-600 focus:ring-orange-500"
                    />
                    <span class="flex-1 min-w-0 text-xs text-gray-800 truncate">
                      {{ nombreItem(oi) }}
                      <span class="text-gray-400">({{ oi.cantidad }})</span>
                    </span>
                    <!-- De dos mesas puede volver una sola -->
                    <input
                      v-if="devueltos[oi.id] && oi.cantidad > 1"
                      v-model.number="devueltos[oi.id]"
                      type="number" min="1" :max="oi.cantidad"
                      class="w-14 border border-orange-300 rounded-lg px-1.5 py-1 text-xs text-center focus:ring-2 focus:ring-orange-500 outline-none"
                    />
                  </div>
                </div>

                <div>
                  <p class="text-xs font-semibold text-orange-900 mb-1">
                    ¿Por qué se devuelve? <span class="text-red-500">*</span>
                  </p>
                  <textarea
                    v-model="motivoDevolucion"
                    rows="2"
                    placeholder="Ej. la cama llegó con la madera partida en el espaldar"
                    class="w-full border border-orange-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 outline-none"
                  />
                </div>

                <label class="block border-2 border-dashed border-orange-300 rounded-xl p-2 text-center cursor-pointer">
                  <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoDevolucion" />
                  <img v-if="fotoDevolucionPreview" :src="fotoDevolucionPreview" class="w-full h-24 object-cover rounded-lg" />
                  <span v-else class="text-xs text-orange-700">📷 Foto del daño (recomendada)</span>
                </label>

                <p v-if="devuelveTodo" class="text-[11px] text-orange-900 bg-orange-100 rounded-lg px-2 py-1.5">
                  Vuelve todo, así que no se le cobra nada al cliente. La orden queda esperando que
                  producción decida si se arregla o se cancela.
                </p>
                <p v-else class="text-[11px] text-orange-800">
                  Se cobra solo lo que el cliente se queda. Producción decide después qué se hace con
                  lo que vuelve.
                </p>
              </div>
            </div>

            <button
              @click="guardarPagoYEntregar"
              :disabled="!puedeEntregar || registrando"
              class="w-full py-3.5 rounded-xl font-bold text-white transition-all"
              :class="puedeEntregar && !registrando ? 'bg-emerald-600 hover:bg-emerald-700 shadow-md' : 'bg-gray-300 cursor-not-allowed'"
            >
              <template v-if="registrando">Procesando...</template>
              <template v-else-if="mensajeBoton">{{ mensajeBoton }}</template>
              <template v-else>✓ Marcar como entregado</template>
            </button>
          </template>

        </div>
      </template>
    </div>
  </div>
</template>
