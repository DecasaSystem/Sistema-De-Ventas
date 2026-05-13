<script setup>
import { ref, computed, watch } from 'vue'
import { detalleEntrega, registrarPagoEntrega, marcarEntregado } from '@/api/despacho'
import { useToast } from '@/composables/useToast'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'

const props = defineProps({
  despachoItemId: { type: Number, required: true },
})
const emit = defineEmits(['cerrar', 'entregado'])

const toast = useToast()

const item = ref(null)
const cargando = ref(true)
const registrando = ref(false)

// Formulario de pago
const monto = ref(0)
const metodo = ref('efectivo')
const referencia = ref('')
const fotoProducto = ref(null)
const fotoPago = ref(null)
const fotoProductoPreview = ref(null)
const fotoPagoPreview = ref(null)

const puedeEntregar = computed(() =>
  fotoProductoPreview.value &&
  fotoPagoPreview.value &&
  monto.value > 0
)

watch(() => props.despachoItemId, async (id) => {
  if (!id) return
  await cargar(id)
}, { immediate: true })

async function cargar(id) {
  cargando.value = true
  try {
    const { data } = await detalleEntrega(id)
    item.value = data
    monto.value = data.orden?.saldo_pendiente || 0
  } catch {} finally {
    cargando.value = false
  }
}

function onFotoProducto(e) {
  const file = e.target.files[0]
  if (!file) return
  fotoProducto.value = file
  const reader = new FileReader()
  reader.onload = (ev) => { fotoProductoPreview.value = ev.target.result }
  reader.readAsDataURL(file)
}

function onFotoPago(e) {
  const file = e.target.files[0]
  if (!file) return
  fotoPago.value = file
  const reader = new FileReader()
  reader.onload = (ev) => { fotoPagoPreview.value = ev.target.result }
  reader.readAsDataURL(file)
}

async function guardarPagoYEntregar() {
  if (!puedeEntregar.value) return
  registrando.value = true
  try {
    const fd = new FormData()
    fd.append('monto', monto.value)
    fd.append('metodo', metodo.value)
    if (referencia.value) fd.append('referencia', referencia.value)
    fd.append('foto_producto', fotoProducto.value)
    fd.append('foto_pago', fotoPago.value)

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
        <h3 class="text-lg font-bold text-gray-900">Registrar entrega</h3>
        <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
      </div>

      <div v-if="cargando" class="p-8 text-center text-sm text-gray-400">Cargando...</div>

      <template v-else-if="item">
        <div class="p-5 space-y-5">
          <!-- Info del cliente y orden -->
          <div class="bg-gray-50 rounded-xl p-4 space-y-1">
            <p class="font-bold text-gray-900">{{ item.orden?.cliente?.nombre }}</p>
            <p class="text-sm text-gray-500">{{ item.orden?.cliente?.telefono }}</p>
            <p class="text-sm text-gray-500">{{ item.orden?.cliente?.direccion }}</p>
            <div class="flex items-center gap-4 mt-2 text-sm">
              <span class="text-gray-600">
                Total: <MoneyDisplay :amount="item.orden?.valor_total" :bold="true" />
              </span>
              <span v-if="item.orden?.saldo_pendiente > 0" class="text-orange-600">
                Saldo: <MoneyDisplay :amount="item.orden?.saldo_pendiente" />
              </span>
            </div>
          </div>

          <!-- Productos -->
          <div v-if="item.orden?.items?.length">
            <h4 class="text-sm font-semibold text-gray-700 mb-2">Productos</h4>
            <div class="space-y-1">
              <div v-for="p in item.orden.items" :key="p.id" class="text-sm text-gray-600 flex items-center gap-2">
                <span>{{ p.producto?.nombre }}</span>
                <span class="text-gray-400">x{{ p.cantidad }}</span>
              </div>
            </div>
          </div>

          <!-- Formulario de pago -->
          <div>
            <h4 class="text-sm font-semibold text-gray-700 mb-3">Registrar Pago</h4>
            <div class="space-y-3">
              <div>
                <label class="text-xs text-gray-500">Monto a cobrar</label>
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
                <select
                  v-model="metodo"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                >
                  <option value="efectivo">Efectivo</option>
                  <option value="transferencia">Transferencia</option>
                  <option value="tarjeta">Tarjeta</option>
                  <option value="otro">Otro</option>
                </select>
              </div>

              <div>
                <label class="text-xs text-gray-500">Referencia (opcional)</label>
                <input
                  v-model="referencia"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                />
              </div>

              <!-- Uploads de fotos -->
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="text-xs text-gray-500 block mb-1">Foto del producto</label>
                  <label class="block border-2 border-dashed border-gray-300 rounded-xl p-3 text-center cursor-pointer hover:border-blue-400 transition-colors">
                    <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoProducto" />
                    <template v-if="fotoProductoPreview">
                      <img :src="fotoProductoPreview" class="w-full h-24 object-cover rounded-lg" />
                    </template>
                    <template v-else>
                      <span class="text-xs text-gray-400">Tomar foto</span>
                    </template>
                  </label>
                </div>

                <div>
                  <label class="text-xs text-gray-500 block mb-1">Foto del comprobante</label>
                  <label class="block border-2 border-dashed border-gray-300 rounded-xl p-3 text-center cursor-pointer hover:border-blue-400 transition-colors">
                    <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoPago" />
                    <template v-if="fotoPagoPreview">
                      <img :src="fotoPagoPreview" class="w-full h-24 object-cover rounded-lg" />
                    </template>
                    <template v-else>
                      <span class="text-xs text-gray-400">Tomar foto</span>
                    </template>
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Botón entregar -->
          <button
            @click="guardarPagoYEntregar"
            :disabled="!puedeEntregar || registrando"
            class="w-full py-3.5 rounded-xl font-bold text-white transition-all"
            :class="puedeEntregar && !registrando ? 'bg-emerald-600 hover:bg-emerald-700 shadow-md' : 'bg-gray-300 cursor-not-allowed'"
          >
            <template v-if="registrando">Procesando...</template>
            <template v-else-if="!puedeEntregar">
              {{ !fotoProductoPreview || !fotoPagoPreview ? 'Sube ambas fotos para continuar' : 'Ingresa el monto' }}
            </template>
            <template v-else>Entregado</template>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
