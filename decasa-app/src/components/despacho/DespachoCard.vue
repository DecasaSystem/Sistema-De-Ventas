<script setup>
import { ref, computed } from 'vue'
import { ArrowTopRightOnSquareIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import ProductosParaRuta from '@/components/despacho/ProductosParaRuta.vue'

const props = defineProps({
  orden: { type: Object, required: true },
  seleccionado: { type: Boolean, default: false },
  posicion: { type: Number, default: null },
  conductor: { type: String, default: null },
  /** Qué productos van: { [orden_item_id]: cantidad }. Si viene, se pintan las casillas. */
  lineas: { type: Object, default: null },
  /** ¿Se ofrece devolver una pieza al taller? Solo en la cola, antes del camión. */
  permiteRetorno: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle', 'ver-detalle', 'update:lineas', 'devolver-al-taller'])

/**
 * Lo que se puede mandar de vuelta al taller: lo que el taller ya dio por
 * listo y sigue aquí. Lo de catálogo no tiene pieza que devolver, y lo que
 * todavía está en producción no ha salido de allá.
 */
const devolvibles = computed(() =>
  (props.orden.items ?? []).filter(i =>
    i.produccion_id && i.entregable && (i.pendiente_entregar ?? 0) > 0
  )
)

const mostrandoRetorno = ref(false)

function nombreItem(i) {
  return i.producto?.nombre || i.nombre_custom || 'Producto'
}

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
          <span class="text-xs text-gray-400">{{ orden.referencia ?? `#${orden.numero_orden ?? orden.id}` }}</span>
          <span class="flex items-center gap-1"><BadgeEstado :estado="orden.estado" :entrega="orden.entrega" /></span>
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
          <span v-if="orden.listo_entrega_at">Listo: {{ formatFecha(orden.listo_entrega_at) }}</span>
          <span v-else class="text-purple-600">Parte sigue en el taller</span>
          <span v-if="conductor" class="text-blue-600 font-medium">{{ conductor }}</span>
        </div>

        <!-- Qué va en el camión: solo cuando la orden está marcada. -->
        <div v-if="lineas && seleccionado && orden.items?.length" class="mt-2">
          <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Va en el camión</p>
          <ProductosParaRuta :items="orden.items" :lineas="lineas" @update:lineas="v => emit('update:lineas', v)" />
        </div>
        <p v-else-if="orden.items?.length && orden.items.some(i => !i.entregable && (i.pendiente_entregar ?? 0) > 0)" class="mt-1.5 text-[11px] text-purple-700">
          Solo va lo que está listo; lo del taller queda para otra ruta.
        </p>
      </div>
    </div>

    <!--
      Devolver algo al taller antes de que suba al camión: se olvidó una pieza,
      llegó mal, o se dañó moviéndola. Se elige el producto, no la orden: de
      una orden con tres muebles vuelve el que tiene el problema.
    -->
    <div v-if="permiteRetorno && devolvibles.length" class="border-t border-gray-100">
      <button
        @click.stop="mostrandoRetorno = !mostrandoRetorno"
        class="w-full flex items-center justify-center gap-1.5 px-4 py-2 text-xs font-medium text-amber-700 hover:bg-amber-50 transition-colors"
      >
        <ArrowUturnLeftIcon class="w-3.5 h-3.5" />
        {{ mostrandoRetorno ? 'Cancelar' : 'Devolver algo al taller' }}
      </button>
      <div v-if="mostrandoRetorno" class="px-4 pb-3 space-y-1" @click.stop>
        <p class="text-[10px] text-gray-400 uppercase tracking-wide">¿Cuál vuelve?</p>
        <button
          v-for="i in devolvibles"
          :key="i.id"
          @click.stop="mostrandoRetorno = false; emit('devolver-al-taller', i)"
          class="w-full text-left text-xs bg-amber-50 border border-amber-200 text-amber-900 rounded-lg px-2.5 py-2 hover:bg-amber-100 transition-colors truncate"
        >
          {{ nombreItem(i) }}
          <span class="text-amber-600">· x{{ i.pendiente_entregar }}</span>
        </button>
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
