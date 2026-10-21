<script setup>
/**
 * Despacho → Por recoger.
 *
 * Lo que un cliente devolvió en un cambio de producto y sigue en su casa. La
 * orden ya cambió (lo devuelto dejó de cobrarse); falta traer el mueble, y
 * solo cuando llega entra al inventario. Lo programado va por fecha (lo
 * atrasado y lo de hoy primero) y después lo que se recoge con la entrega del
 * nuevo. Con dirección y teléfono: es para ir.
 *
 * Si no hay nada, no ocupa espacio.
 */
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getPorRecoger } from '@/api/devoluciones'
import RecogidaAcciones from './RecogidaAcciones.vue'
import { ArrowUturnLeftIcon, MapPinIcon, PhoneIcon } from '@heroicons/vue/24/outline'

const router = useRouter()
const lista  = ref([])
const abierta = ref(true)

async function cargar() {
  try { lista.value = (await getPorRecoger()).data } catch { lista.value = [] }
}
onMounted(cargar)
defineExpose({ cargar })
</script>

<template>
  <div v-if="lista.length" class="bg-white rounded-xl shadow-sm p-4 space-y-3 border border-amber-200">
    <button type="button" @click="abierta = !abierta" class="w-full flex items-center justify-between">
      <span class="text-xs font-semibold text-amber-800 uppercase flex items-center gap-1.5">
        <ArrowUturnLeftIcon class="w-4 h-4" /> Por recoger ({{ lista.length }})
      </span>
      <span class="text-[11px] text-gray-400">{{ abierta ? 'Ocultar' : 'Ver' }}</span>
    </button>

    <template v-if="abierta">
      <p class="text-[11px] text-gray-500 -mt-1">
        Productos que un cliente cambió y siguen en su casa. Al recibirlos entran al inventario.
      </p>
      <div v-for="d in lista" :key="d.id" class="border-t border-gray-100 pt-3 space-y-1.5">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-800 truncate">
              <span v-if="d.cantidad > 1">{{ d.cantidad }}× </span>{{ d.producto }}
            </p>
            <p class="text-[11px] text-gray-500 truncate">{{ d.cliente }} · {{ d.motivo }}</p>
          </div>
          <button type="button" @click="router.push(`/ordenes/${d.orden_id}`)"
            class="shrink-0 text-[11px] font-semibold text-blue-600">{{ d.orden_referencia }}</button>
        </div>
        <p v-if="d.direccion" class="flex items-start gap-1 text-[11px] text-gray-600">
          <MapPinIcon class="w-3.5 h-3.5 shrink-0 mt-px" /> {{ d.direccion }}
        </p>
        <a v-if="d.telefono" :href="`tel:${d.telefono}`" class="flex items-center gap-1 text-[11px] text-blue-600">
          <PhoneIcon class="w-3.5 h-3.5" /> {{ d.telefono }}
        </a>
        <RecogidaAcciones :devolucion="d" @cambio="cargar" />
      </div>
    </template>
  </div>
</template>
