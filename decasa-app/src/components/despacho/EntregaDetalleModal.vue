<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { detalleEntrega, registrarPagoEntrega, marcarEntregado } from '@/api/despacho'
import { useToast } from '@/composables/useToast'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import { CheckCircleIcon, MapPinIcon, ClockIcon } from '@heroicons/vue/24/outline'

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

const puedeEntregar = computed(() => {
  if (!fotoProductoPreview.value) return false
  if (tieneSaldo.value) return !!fotoPagoPreview.value && monto.value > 0
  return true  // sin saldo: solo se necesita foto del producto
})

const mensajeBoton = computed(() => {
  if (!fotoProductoPreview.value) return 'Sube la foto del producto para continuar'
  if (tieneSaldo.value) {
    if (!fotoPagoPreview.value) return 'Sube la foto del comprobante de pago'
    if (!(monto.value > 0))     return 'Ingresa el monto cobrado'
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

async function guardarPagoYEntregar() {
  if (!puedeEntregar.value) return
  registrando.value = true
  try {
    const fd = new FormData()
    fd.append('foto_producto', fotoProducto.value, 'foto_producto.jpg')

    if (tieneSaldo.value) {
      fd.append('monto', monto.value)
      fd.append('metodo', metodo.value)
      if (referencia.value) fd.append('referencia', referencia.value)
      fd.append('foto_pago', fotoPago.value, 'foto_pago.jpg')
    } else {
      fd.append('monto', '0')
    }
    if (fotoAnexo.value) fd.append('foto_anexo', fotoAnexo.value, 'foto_anexo.jpg')

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
                Cobra: <MoneyDisplay :amount="item.orden?.saldo_pendiente" />
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
                  :src="p.producto.foto_url"
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
                    <img :src="item.foto_producto" class="w-full h-28 object-cover rounded-xl border border-gray-100" />
                  </a>
                </div>
                <div v-if="item.foto_pago">
                  <p class="text-xs text-gray-500 mb-1">Comprobante</p>
                  <a :href="item.foto_pago" target="_blank">
                    <img :src="item.foto_pago" class="w-full h-28 object-cover rounded-xl border border-gray-100" />
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
                    <label class="text-xs text-gray-500">Monto cobrado</label>
                    <input
                      v-model.number="monto"
                      type="number"
                      step="0.01"
                      min="1"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                    />
                  </div>
                  <div>
                    <label class="text-xs text-gray-500">Método de pago</label>
                    <select v-model="metodo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                      <option value="efectivo">Efectivo</option>
                      <option value="transferencia">Transferencia</option>
                      <option value="tarjeta">Tarjeta</option>
                      <option value="otro">Otro</option>
                    </select>
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
                <img :src="item.orden.anexo_foto_url" class="w-full h-28 object-cover rounded-xl border border-gray-100" />
              </a>
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
