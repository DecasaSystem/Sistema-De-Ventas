<script setup>
import { ref, watch, computed } from 'vue'
import { registrarPago } from '@/api/ordenes'

const props = defineProps({
  show: { type: Boolean, required: true },
  ordenId: { type: [Number, String], required: true },
  valorTotal: { type: [Number, String], required: true },
  saldoPendiente: { type: [Number, String], required: true },
})

const valorTotalN    = computed(() => Number(props.valorTotal))
const saldoPendienteN = computed(() => Number(props.saldoPendiente))

const emit = defineEmits(['close', 'pago-registrado'])

const metodosOpts = [
  { value: 'efectivo', label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'tarjeta', label: 'Tarjeta' },
  { value: 'otro', label: 'Otro' },
]

const monto = ref(0)
const metodo = ref('efectivo')
const referencia = ref('')
const notas = ref('')
const loading = ref(false)
const error = ref('')

watch(() => props.show, (val) => {
  if (val) {
    monto.value = saldoPendienteN.value
    metodo.value = 'efectivo'
    referencia.value = ''
    notas.value = ''
    error.value = ''
  }
})

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
  if (monto.value > saldoPendienteN.value + 0.01) {
    error.value = `El monto no puede superar el saldo pendiente ($${saldoPendienteN.value.toLocaleString('es-CO')}).`
    return
  }

  loading.value = true
  try {
    await registrarPago(props.ordenId, {
      monto: monto.value,
      metodo: metodo.value,
      referencia: referencia.value || undefined,
      notas: notas.value || undefined,
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
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/40" />

      <!-- Modal -->
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4 max-h-[90vh] overflow-y-auto">

        <!-- Header -->
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-bold text-gray-800">Registrar pago</h3>
          <button @click="closeModal" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <!-- Info resumen -->
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
            type="number"
            min="1"
            :max="saldoPendienteN"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="0"
          />
        </div>

        <!-- Método -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Método de pago</label>
          <div class="flex gap-2 flex-wrap">
            <button
              v-for="m in metodosOpts"
              :key="m.value"
              @click="metodo = m.value"
              :class="[
                'px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                metodo === m.value
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white text-gray-700 border-gray-300'
              ]"
            >{{ m.label }}</button>
          </div>
        </div>

        <!-- Referencia -->
        <div v-if="metodo !== 'efectivo'">
          <label class="block text-sm font-medium text-gray-700 mb-1">Referencia</label>
          <input
            v-model="referencia"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="Número de transacción"
          />
        </div>

        <!-- Notas -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Notas (opcional)</label>
          <textarea
            v-model="notas"
            rows="2"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
            placeholder="Observaciones..."
          />
        </div>

        <!-- Error -->
        <p v-if="error" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ error }}</p>

        <!-- Actions -->
        <div class="flex gap-3">
          <button
            @click="closeModal"
            :disabled="loading"
            class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-200 transition-colors disabled:opacity-50"
          >Cancelar</button>
          <button
            @click="submit"
            :disabled="loading"
            class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >{{ loading ? 'Guardando...' : 'Registrar pago' }}</button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
