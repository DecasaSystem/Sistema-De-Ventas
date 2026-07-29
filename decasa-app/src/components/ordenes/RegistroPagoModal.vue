<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import { registrarPago, getTiendas } from '@/api/ordenes'
import api from '@/api'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { PhotoIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show: { type: Boolean, required: true },
  ordenId: { type: [Number, String], required: true },
  valorTotal: { type: [Number, String], required: true },
  saldoPendiente: { type: [Number, String], required: true },
  // Tienda de la orden: es la que viene marcada por defecto
  tiendaOrdenId: { type: [Number, String], default: null },
  tiendaOrdenNombre: { type: String, default: '' },
})

const valorTotalN     = computed(() => Number(props.valorTotal))
const saldoPendienteN = computed(() => Number(props.saldoPendiente))

const emit = defineEmits(['close', 'pago-registrado'])

const metodosOpts = [
  { value: 'efectivo',      label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'tarjeta',       label: 'Tarjeta' },
  { value: 'otro',          label: 'Otro' },
]

const monto         = ref(0)
const metodo        = ref('efectivo')
const referencia    = ref('')
const notas         = ref('')

// ── Tienda donde se recibe el dinero ─────────────────────────────────────────
// El cliente puede abonar en una sede distinta a la que hizo la venta; el
// efectivo entra a la caja de donde abona, no a la de la orden.
const tiendas  = ref([])
const tiendaId = ref(null)

const esOtraTienda = computed(() =>
  tiendaId.value && props.tiendaOrdenId && Number(tiendaId.value) !== Number(props.tiendaOrdenId)
)

onMounted(async () => {
  try {
    const { data } = await getTiendas()
    tiendas.value = Array.isArray(data) ? data : []
  } catch {
    tiendas.value = []
  }
})
const loading       = ref(false)
const error         = ref('')

const comprobanteFile    = ref(null)
const comprobanteUrl     = ref('')
const comprobantePreview = ref('')

// ── Descuento condicionado al medio de pago ───────────────────────────────────
// Algunas órdenes tienen descuento por pagar en efectivo o transferencia. Si el
// cliente saca la tarjeta se pierde y el total sube: hay que avisarle antes de
// cobrar, no después.
const avisoDescuento = ref(null)
const verificando    = ref(false)

async function verificarMetodo() {
  avisoDescuento.value = null
  if (!props.ordenId) return

  verificando.value = true
  try {
    const { data } = await api.post(`/ordenes/${props.ordenId}/verificar-pago`, { metodo: metodo.value })
    if (data.pierde_descuento) {
      avisoDescuento.value = data
      // Si venía con el saldo viejo, subirlo al nuevo: cobrar de menos aquí
      // dejaría un saldo pendiente que nadie notaría.
      if (Math.abs(Number(monto.value) - saldoPendienteN.value) < 0.01) {
        monto.value = Number(data.saldo_sin_descuento)
      }
    }
  } catch {
    // Si la consulta falla no se bloquea el cobro: el backend vuelve a validar
    // y responde 409 si hace falta.
  } finally {
    verificando.value = false
  }
}

watch(metodo, verificarMetodo)

watch(() => props.show, (val) => {
  if (val) {
    monto.value            = saldoPendienteN.value
    metodo.value           = 'efectivo'
    referencia.value       = ''
    notas.value            = ''
    error.value            = ''
    avisoDescuento.value   = null
    tiendaId.value         = props.tiendaOrdenId
    comprobanteFile.value  = null
    comprobanteUrl.value   = ''
    if (comprobantePreview.value) URL.revokeObjectURL(comprobantePreview.value)
    comprobantePreview.value = ''
  }
})

function money(v) {
  return '$' + Number(v ?? 0).toLocaleString('es-CO')
}

function onComprobanteChange(e) {
  const file = e.target.files[0]
  if (!file) return
  if (comprobantePreview.value) URL.revokeObjectURL(comprobantePreview.value)
  comprobanteFile.value    = file
  comprobanteUrl.value     = ''
  comprobantePreview.value = URL.createObjectURL(file)
}

function quitarComprobante() {
  if (comprobantePreview.value) URL.revokeObjectURL(comprobantePreview.value)
  comprobanteFile.value    = null
  comprobanteUrl.value     = ''
  comprobantePreview.value = ''
}

function closeModal() {
  if (loading.value) return
  emit('close')
}

async function submit() {
  error.value = ''

  if (!monto.value || monto.value <= 0) {
    error.value = 'Ingresa un monto válido.'
    return
  }
  // Con el descuento perdido el saldo sube, así que el tope es el saldo nuevo.
  const topeSaldo = avisoDescuento.value
    ? Number(avisoDescuento.value.saldo_sin_descuento)
    : saldoPendienteN.value

  if (monto.value > topeSaldo + 0.01) {
    error.value = `El monto no puede superar el saldo pendiente ($${topeSaldo.toLocaleString('es-CO')}).`
    return
  }
  if (!comprobanteFile.value && !comprobanteUrl.value) {
    error.value = 'Adjunta la foto del comprobante de pago.'
    return
  }

  loading.value = true
  try {
    // Subir comprobante si es un archivo nuevo
    if (comprobanteFile.value && !comprobanteUrl.value) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(comprobanteFile.value), 'comprobante.jpg')
      fd.append('folder', 'comprobantes')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      comprobanteUrl.value = up.url
    }

    await registrarPago(props.ordenId, {
      monto:            monto.value,
      metodo:           metodo.value,
      referencia:       referencia.value || undefined,
      notas:            notas.value || undefined,
      comprobante_url:  comprobanteUrl.value,
      tienda_id:        tiendaId.value || undefined,
      // El vendedor ya vio el aviso y le informó al cliente
      aceptar_perdida_descuento: avisoDescuento.value ? true : undefined,
    })
    emit('pago-registrado')
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Error al registrar el pago.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Transition name="fade">
    <div v-if="show" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="closeModal">
      <div class="absolute inset-0 bg-black/40" />

      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4 max-h-[90vh] overflow-y-auto">

        <div class="flex items-center justify-between">
          <h3 class="text-lg font-bold text-gray-800">Registrar pago</h3>
          <button @click="closeModal" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <!-- Resumen -->
        <div class="bg-gray-50 rounded-xl p-3 space-y-1 text-sm">
          <div class="flex justify-between">
            <span class="text-gray-500">Valor total</span>
            <span class="font-medium text-gray-800">${{ valorTotalN.toLocaleString('es-CO') }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-500">Saldo pendiente</span>
            <span class="font-bold text-red-600">${{ saldoPendienteN.toLocaleString('es-CO') }}</span>
          </div>
        </div>

        <!-- Monto -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Monto a pagar</label>
          <input
            v-model.number="monto"
            type="number" min="1" :max="saldoPendienteN"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="0"
          />
        </div>

        <!-- Método -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Método de pago</label>
          <div class="flex gap-2 flex-wrap">
            <button
              v-for="m in metodosOpts" :key="m.value"
              @click="metodo = m.value"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                metodo === m.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300']"
            >{{ m.label }}</button>
          </div>
        </div>

        <!-- Tienda donde se recibe el dinero -->
        <div v-if="tiendas.length">
          <label class="block text-sm font-medium text-gray-700 mb-1">
            ¿En qué tienda se recibe el pago?
          </label>
          <select
            v-model="tiendaId"
            class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            :class="esOtraTienda ? 'border-amber-400 bg-amber-50' : 'border-gray-300'"
          >
            <option v-for="t in tiendas" :key="t.id" :value="t.id">
              {{ t.nombre }}{{ Number(t.id) === Number(tiendaOrdenId) ? ' (tienda de la orden)' : '' }}
            </option>
          </select>
          <p v-if="esOtraTienda" class="text-xs text-amber-700 mt-1">
            El dinero entra a la caja de esta tienda, no a la de
            {{ tiendaOrdenNombre || 'la orden' }}. La venta sigue siendo del mismo vendedor.
          </p>
          <p v-else class="text-xs text-gray-500 mt-1">
            Cámbiala si el cliente está abonando en otra sede.
          </p>
        </div>

        <!-- Aviso: este método hace perder el descuento -->
        <div v-if="verificando" class="text-xs text-gray-400">Revisando el descuento...</div>

        <div
          v-else-if="avisoDescuento"
          class="bg-amber-50 border border-amber-300 rounded-xl p-3 space-y-2"
        >
          <p class="text-sm font-semibold text-amber-900">
            Se pierde el descuento de {{ money(avisoDescuento.descuento) }}
          </p>
          <p class="text-xs text-amber-800">
            Esta orden tenía {{ avisoDescuento.pct }}% de descuento por pagar en efectivo o
            transferencia. Al cobrar con {{ metodo }}, el descuento se pierde completo.
          </p>

          <div class="bg-white rounded-lg p-2.5 space-y-1 text-xs">
            <div class="flex justify-between">
              <span class="text-gray-500">Total de la orden</span>
              <span class="text-gray-400 line-through">{{ money(avisoDescuento.valor_actual) }}</span>
            </div>
            <div class="flex justify-between font-semibold">
              <span class="text-gray-700">Total nuevo</span>
              <span class="text-amber-900">{{ money(avisoDescuento.valor_sin_descuento) }}</span>
            </div>
            <div class="flex justify-between border-t border-gray-100 pt-1 mt-1 font-bold">
              <span class="text-gray-700">Saldo a cobrar</span>
              <span class="text-amber-900">{{ money(avisoDescuento.saldo_sin_descuento) }}</span>
            </div>
          </div>

          <p class="text-xs text-amber-700">
            Avísale al cliente antes de continuar. Al registrar el pago queda constancia y se
            notifica a supervisión.
          </p>
        </div>

        <!-- Referencia -->
        <div v-if="metodo !== 'efectivo'">
          <label class="block text-sm font-medium text-gray-700 mb-1">Referencia</label>
          <input v-model="referencia" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Número de transacción" />
        </div>

        <!-- Comprobante -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Foto del comprobante <span class="text-red-500">*</span>
          </label>
          <div v-if="comprobanteFile" class="space-y-1.5">
            <div class="relative">
              <img :src="comprobantePreview" class="w-full rounded-xl border-2 border-gray-200 object-contain bg-gray-50 max-h-40" />
              <button @click="quitarComprobante" class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow">
                <XMarkIcon class="w-3.5 h-3.5" />
              </button>
            </div>
            <p class="text-xs text-gray-400 truncate">{{ comprobanteFile.name }}</p>
          </div>
          <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-amber-300 rounded-xl p-4 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
            <PhotoIcon class="w-7 h-7 text-amber-300" />
            <span class="text-sm text-gray-500">Toca para adjuntar comprobante</span>
            <input type="file" accept="image/*" @change="onComprobanteChange" class="hidden" />
          </label>
        </div>

        <!-- Notas -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Notas (opcional)</label>
          <textarea v-model="notas" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none" placeholder="Observaciones..." />
        </div>

        <p v-if="error" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ error }}</p>

        <div class="flex gap-3">
          <button @click="closeModal" :disabled="loading" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-200 transition-colors disabled:opacity-50">Cancelar</button>
          <button @click="submit" :disabled="loading || (!comprobanteFile && !comprobanteUrl)" class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors">
            {{ loading ? 'Guardando...' : 'Registrar pago' }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
