<script setup>
/**
 * "Pedir cambio de dinero": lo único que le queda al vendedor cuando la orden
 * pasó los días de la garantía y ya no se puede modificar.
 *
 * Solo lo de plata que tiene sentido corregir en un mueble que ya se está
 * haciendo: el precio de cada producto, el descuento y un abono (monto o
 * medio). Al continuar, el servidor dice qué cambia y se pasa al paso de
 * siempre —motivo y soporte— para que un supervisor lo apruebe.
 */
import { ref, computed, watch } from 'vue'
import { revisarCambiosDePlata } from '@/api/ordenes'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import SolicitarCambioModal from '@/components/ordenes/SolicitarCambioModal.vue'
import { LockClosedIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show:  { type: Boolean, default: false },
  orden: { type: Object, required: true },
})
const emit = defineEmits(['close', 'enviada'])
const toast = useToast()

const precios   = ref({})    // item.id → precio
const descuento = ref(0)
const pagoId    = ref('')    // el abono que se corrige ('' = ninguno)
const pagoMonto = ref(null)
const pagoMedio = ref('efectivo')
const revisando = ref(false)
const solicitud = ref(null)  // { cambios, pedido } → paso de motivo y soporte

const items = computed(() => (props.orden?.items ?? []).filter(i => !i.devuelto_en && !i.es_regalo))
const pagos = computed(() => props.orden?.pagos ?? [])
const pagoElegido = computed(() => pagos.value.find(p => String(p.id) === String(pagoId.value)) ?? null)

watch(() => props.show, (v) => {
  if (!v) return
  precios.value   = Object.fromEntries(items.value.map(i => [i.id, Number(i.precio_unitario)]))
  descuento.value = Number(props.orden?.descuento_total ?? 0)
  pagoId.value    = ''
})
watch(pagoElegido, (p) => {
  if (!p) return
  pagoMonto.value = Number(p.monto)
  pagoMedio.value = p.metodo ?? 'efectivo'
})

const nombre = (i) => i.producto?.nombre ?? i.nombre_custom ?? 'Producto'
const pesos  = (n) => '$' + Math.round(Number(n || 0)).toLocaleString('es-CO')
const fecha  = (iso) => iso ? new Date(iso).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) : ''

async function continuar() {
  const pedido = {
    cambios_orden: {
      descuento_total: Number(descuento.value) || 0,
      items: items.value.map(i => ({ id: i.id, precio_unitario: Number(precios.value[i.id]) || 0 })),
    },
  }
  if (pagoElegido.value) {
    pedido.pago = {
      id: pagoElegido.value.id, monto: Number(pagoMonto.value) || 0,
      metodo: pagoMedio.value, referencia: pagoElegido.value.referencia ?? null,
    }
  }
  revisando.value = true
  try {
    const { data } = await revisarCambiosDePlata(props.orden.id, pedido)
    if (!data.cambios?.length) {
      toast.error('No cambiaste nada de dinero.')
      return
    }
    solicitud.value = { cambios: data.cambios, pedido }
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo revisar el cambio.')
  } finally {
    revisando.value = false
  }
}

function enviada() {
  solicitud.value = null
  emit('enviada')
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show && !solicitud" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="emit('close')">
        <div class="absolute inset-0 bg-black/50" @click="emit('close')" />

        <div class="relative w-full sm:max-w-md max-h-[92dvh] bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col">
          <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-4 border-b border-gray-100 flex-shrink-0">
            <div>
              <h3 class="text-base font-bold text-gray-800">Pedir cambio de dinero</h3>
              <p class="text-xs text-gray-500 mt-0.5">Orden {{ orden.referencia }}</p>
            </div>
            <button @click="emit('close')" aria-label="Cerrar" class="text-gray-400 text-2xl leading-none w-9 h-9 -mr-2 -mt-1">&times;</button>
          </div>

          <div class="overflow-y-auto px-5 py-4 space-y-5 flex-1 min-h-0">
            <div class="flex items-start gap-2 rounded-xl bg-gray-50 border border-gray-200 px-3 py-2.5">
              <LockClosedIcon class="w-4 h-4 text-gray-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-gray-700 leading-snug">
                Pasaron {{ orden.dias_para_editar ?? 5 }} días desde que se hizo la orden: ya no se modifica (telas, notas, productos…), como
                dice la garantía. Aquí solo puedes pedir un cambio de dinero, que aprueba un supervisor.
              </p>
            </div>

            <!-- Precios -->
            <section v-if="items.length" class="space-y-2">
              <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Precio de cada producto</p>
              <div v-for="i in items" :key="i.id" class="flex items-center gap-3">
                <label :for="`precio-${i.id}`" class="flex-1 min-w-0">
                  <span class="block text-sm font-medium text-gray-800 truncate">{{ nombre(i) }}</span>
                  <span class="block text-xs text-gray-400">× {{ i.cantidad }} · hoy {{ pesos(i.precio_unitario) }}</span>
                </label>
                <InputPesos :id="`precio-${i.id}`" v-model="precios[i.id]"
                  class="w-36 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-amber-400" />
              </div>
            </section>

            <!-- Descuento -->
            <section class="flex items-center gap-3">
              <label for="desc-total" class="flex-1">
                <span class="block text-sm font-medium text-gray-800">Descuento</span>
                <span class="block text-xs text-gray-400">hoy {{ pesos(orden.descuento_total) }}</span>
              </label>
              <InputPesos id="desc-total" v-model="descuento"
                class="w-36 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-amber-400" />
            </section>

            <!-- Un abono -->
            <section v-if="pagos.length" class="space-y-2">
              <label for="pago-corregir" class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Corregir un abono (opcional)</label>
              <select id="pago-corregir" v-model="pagoId"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400">
                <option value="">Ninguno</option>
                <option v-for="p in pagos" :key="p.id" :value="String(p.id)">
                  {{ p.tipo === 'anticipo' ? 'Anticipo' : 'Abono' }} del {{ fecha(p.created_at) }} · {{ pesos(p.monto) }} · {{ p.metodo }}
                </option>
              </select>
              <div v-if="pagoElegido" class="grid grid-cols-2 gap-2">
                <div>
                  <label for="pago-monto" class="block text-xs text-gray-600 mb-1">Monto</label>
                  <InputPesos id="pago-monto" v-model="pagoMonto"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
                </div>
                <div>
                  <label for="pago-medio" class="block text-xs text-gray-600 mb-1">Medio</label>
                  <select id="pago-medio" v-model="pagoMedio"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400">
                    <option value="efectivo">Efectivo</option>
                    <option value="transferencia">Transferencia</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="addi">Addi</option>
                    <option value="otro">Otro</option>
                  </select>
                </div>
              </div>
            </section>
          </div>

          <div class="px-5 pt-3 pb-5 border-t border-gray-100 flex gap-3 flex-shrink-0">
            <button @click="emit('close')" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="continuar" :disabled="revisando"
              class="flex-1 bg-amber-700 text-white rounded-lg py-2.5 text-sm font-bold hover:bg-amber-800 disabled:opacity-50">
              {{ revisando ? 'Revisando…' : 'Continuar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Paso 2: motivo y soporte (el de siempre). -->
    <SolicitarCambioModal
      :show="!!solicitud"
      :orden-id="orden.id"
      :cambios="solicitud?.cambios ?? []"
      :pedido="solicitud?.pedido ?? {}"
      @close="solicitud = null"
      @enviada="enviada"
    />
  </Teleport>
</template>
