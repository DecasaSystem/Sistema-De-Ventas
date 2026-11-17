<script setup>
/**
 * Órdenes con plata del cliente por devolver (usuario, 2026-11-17).
 *
 * Salen de cancelar una orden con anticipo, quitar un producto que ya estaba
 * pago o cambiar por uno más barato. La plata se devuelve desde la orden
 * ("Devolver plata", supervisor). Sin nada, no se pinta.
 */
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getSaldosAFavor } from '@/api/ordenes'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'

const props  = defineProps({ tiendaId: { type: [Number, String], default: null } })
const router = useRouter()
const filas  = ref([])

async function cargar() {
  try {
    const { data } = await getSaldosAFavor(props.tiendaId ? { tienda_id: props.tiendaId } : {})
    filas.value = data
  } catch {
    filas.value = []
  }
}
onMounted(cargar)
watch(() => props.tiendaId, cargar)

const total = () => filas.value.reduce((s, f) => s + Number(f.a_favor || 0), 0)
</script>

<template>
  <div v-if="filas.length" class="space-y-2 mb-4">
    <div class="bg-white rounded-xl shadow-sm p-4 flex items-center justify-between gap-3">
      <div>
        <p class="text-sm font-bold text-gray-800">Saldos a favor de clientes</p>
        <p class="text-[11px] text-gray-400">Plata pagada de más por devolver o abonar. Se devuelve desde la orden.</p>
      </div>
      <span class="text-sm font-bold text-blue-600 shrink-0"><MoneyDisplay :amount="total()" /></span>
    </div>
    <div
      v-for="f in filas" :key="f.id"
      @click="router.push({ name: 'orden-detalle', params: { id: f.id } })"
      class="bg-white rounded-xl shadow-sm px-4 py-3 cursor-pointer hover:shadow-md transition-shadow flex items-center justify-between gap-3"
    >
      <div class="min-w-0">
        <p class="text-sm font-semibold text-gray-800 truncate">{{ f.referencia }} · {{ f.cliente || 'Sin cliente' }}</p>
        <p class="text-[11px] text-gray-400 truncate">
          {{ f.tienda }}<template v-if="f.vendedor"> · {{ f.vendedor }}</template>
          <template v-if="f.estado === 'cancelado'"> · cancelada</template>
        </p>
      </div>
      <span class="text-sm font-bold text-blue-600 shrink-0"><MoneyDisplay :amount="f.a_favor" /></span>
    </div>
  </div>
</template>
