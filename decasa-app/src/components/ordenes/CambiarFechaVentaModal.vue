<script setup>
/**
 * Cambiar la fecha de una venta (solo super admin, usuario 2026-11-20).
 *
 * El caso: se vendió el 31 y el vendedor la subió el 1 del mes siguiente. La
 * venta pasa al día que de verdad fue y con ella cambian el mes de reportes,
 * estadísticas, metas, Finanzas y bonificaciones. El número y los pagos no
 * cambian. Ver App\Services\CambiarFechaDeVenta.
 */
import { ref, computed, watch } from 'vue'
import { cambiarFechaVenta } from '@/api/ordenes'
import { fechaBogota, hoyBogota } from '@/utils/fechas'
import { useToast } from '@/composables/useToast'
import { CalendarIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show:  { type: Boolean, default: false },
  orden: { type: Object, default: null },
})
const emit = defineEmits(['close', 'guardada'])
const toast = useToast()

const fecha    = ref('')
const motivo   = ref('')
const guardando = ref(false)

const actual = computed(() => props.orden?.created_at ? fechaBogota(new Date(props.orden.created_at)) : '')
const hoy    = hoyBogota()
const cambiaDeMes = computed(() => fecha.value && actual.value && fecha.value.slice(0, 7) !== actual.value.slice(0, 7))

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const nombreMes = (f) => f ? `${MESES[Number(f.slice(5, 7)) - 1]} ${f.slice(0, 4)}` : ''
const dia = (f) => f ? `${f.slice(8, 10)}/${f.slice(5, 7)}/${f.slice(0, 4)}` : ''

watch(() => props.show, (v) => {
  if (!v) return
  fecha.value  = actual.value
  motivo.value = ''
})

async function guardar() {
  if (!fecha.value || fecha.value === actual.value) {
    toast.error('Escoge una fecha distinta a la que tiene.')
    return
  }
  guardando.value = true
  try {
    const { data } = await cambiarFechaVenta(props.orden.id, {
      fecha: fecha.value,
      motivo: motivo.value.trim() || null,
    })
    toast.success(`La venta quedó del ${dia(fecha.value)}.`)
    for (const a of data?.avisos ?? []) toast.error(a, 10000)
    emit('guardada')
    emit('close')
  } catch (e) {
    toast.error(e.response?.data?.message
      || Object.values(e.response?.data?.errors ?? {}).flat()[0]
      || 'No se pudo cambiar la fecha.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show" class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center" @click.self="emit('close')">
        <div class="absolute inset-0 bg-black/50" @click="emit('close')" />

        <div class="relative w-full sm:max-w-md max-h-[92dvh] bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col">
          <div class="flex items-start gap-3 px-5 pt-5 pb-4 border-b border-gray-100 flex-shrink-0">
            <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center flex-shrink-0">
              <CalendarIcon class="w-5 h-5" />
            </div>
            <div class="min-w-0 flex-1">
              <h3 class="text-base font-bold text-gray-800">Fecha de la venta</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                Para cuando se vendió un día y se subió otro (vendida el 31, subida el 1).
              </p>
            </div>
            <button @click="emit('close')" aria-label="Cerrar" class="text-gray-400 text-2xl leading-none w-9 h-9 -mr-2 -mt-1">&times;</button>
          </div>

          <div class="overflow-y-auto px-5 py-4 space-y-4 flex-1 min-h-0">
            <div class="flex justify-between text-sm">
              <span class="text-gray-500">Ahora</span>
              <span class="font-semibold text-gray-800">{{ dia(actual) }}</span>
            </div>

            <div>
              <label for="fecha-venta" class="block text-xs font-medium text-gray-600 mb-1">Fecha en que se vendió</label>
              <input id="fecha-venta" v-model="fecha" type="date" :max="hoy"
                     class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>

            <div>
              <label for="motivo-fecha" class="block text-xs font-medium text-gray-600 mb-1">Motivo (opcional)</label>
              <input id="motivo-fecha" v-model="motivo" type="text" maxlength="200" placeholder="Se vendió el 31 y la subieron el 1"
                     class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>

            <p v-if="cambiaDeMes" class="text-xs text-amber-800 border border-amber-200 rounded-lg px-3 py-2">
              La venta pasa de <b>{{ nombreMes(actual) }}</b> a <b>{{ nombreMes(fecha) }}</b>: cambian los reportes,
              las estadísticas, la meta y las bonificaciones de los dos meses.
            </p>

            <ul class="text-[11px] text-gray-500 space-y-1 list-disc pl-4">
              <li>El número de la orden y los pagos no cambian.</li>
              <li>Si la bonificación de esta venta ya se pagó, no se deja mover.</li>
              <li>Si el mes al que llega ya se pagó, lo que falte sale en el próximo pago (Bonificaciones → Listas). Si el mes de donde sale ya se pagó, lo cobrado de más se descuenta del próximo pago.</li>
              <li>Queda en el historial de la orden y se le avisa a quien la vendió.</li>
            </ul>
          </div>

          <div class="px-5 py-4 border-t border-gray-100 flex gap-2 flex-shrink-0">
            <button @click="emit('close')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-lg px-4 py-2.5">Cancelar</button>
            <button @click="guardar" :disabled="guardando || !fecha || fecha === actual"
                    class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-lg px-4 py-2.5 disabled:opacity-50">
              {{ guardando ? 'Guardando…' : 'Cambiar fecha' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
