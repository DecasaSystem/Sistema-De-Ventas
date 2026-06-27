<script setup>
import { ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'

const props = defineProps({
  orden: { type: Object, required: true },
  seleccionado: { type: Boolean, default: false },
  posicion: { type: Number, default: null },
  conductor: { type: String, default: null },
})

const emit = defineEmits(['toggle', 'ver-detalle'])

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div
    class="w-full text-left bg-white rounded-xl border-2 transition-all duration-150 shadow-sm hover:shadow-md"
    :class="seleccionado ? 'border-blue-500 shadow-md' : 'border-transparent'"
  >
    <div class="flex items-start gap-3 p-4" @click="emit('toggle', orden.id)">
      <!-- Círculo numerado -->
      <div
        v-if="seleccionado"
        class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-bold"
      >
        {{ posicion }}
      </div>

      <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between gap-2">
          <span class="text-xs text-gray-400">#{{ orden.numero_orden ?? orden.id }}</span>
          <BadgeEstado :estado="orden.estado" />
        </div>
        <p class="font-semibold text-gray-900 mt-1 truncate">{{ orden.cliente?.nombre }}</p>
        <p v-if="orden.cliente?.direccion" class="text-xs text-gray-500 mt-0.5 truncate">
          {{ orden.cliente.direccion }}
        </p>
        <div class="flex items-center justify-between mt-2 text-sm">
          <span class="text-gray-600">
            <MoneyDisplay :amount="orden.valor_total" />
          </span>
          <span v-if="orden.saldo_pendiente > 0" class="text-orange-600 text-xs">
            Saldo: <MoneyDisplay :amount="orden.saldo_pendiente" />
          </span>
        </div>
        <div class="flex items-center justify-between mt-1.5 text-xs text-gray-400">
          <span>Listo: {{ formatFecha(orden.listo_entrega_at) }}</span>
          <span v-if="conductor" class="text-blue-600 font-medium">{{ conductor }}</span>
        </div>
      </div>
    </div>

    <!-- Botón ver detalle de orden -->
    <button
      @click.stop="emit('ver-detalle', orden.id)"
      class="w-full flex items-center justify-end gap-1 px-4 py-2 border-t border-gray-100 text-xs text-blue-600 hover:bg-blue-50 transition-colors"
    >
      Ver orden
      <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
    </button>
  </div>
</template>
