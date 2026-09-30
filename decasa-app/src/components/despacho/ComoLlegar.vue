<script setup>
/**
 * Cómo llegar a la casa del cliente: Google Maps, Waze y llamar.
 *
 * Lo usa el conductor en la lista de su ruta y en el detalle de la entrega.
 * Botones grandes y de colores distintos a propósito: se tocan con una mano,
 * a veces al sol, y cada uno tiene que reconocerse sin leerlo.
 */
import { computed } from 'vue'
import { MapIcon, PhoneIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/solid'
import { destinoDe, linkWaze, linkGoogleMaps, linkTel } from '@/utils/navegacion'

const props = defineProps({
  orden:    { type: Object, default: null },
  // 'lista': fila compacta en la tarjeta de la ruta. 'detalle': con título.
  variante: { type: String, default: 'lista' },
})

const destino = computed(() => destinoDe(props.orden))
const waze    = computed(() => linkWaze(destino.value))
const maps    = computed(() => linkGoogleMaps(destino.value))
const tel     = computed(() => linkTel(props.orden?.cliente?.telefono))
const grande  = computed(() => props.variante === 'detalle')
</script>

<template>
  <div :class="grande ? 'space-y-2' : 'space-y-1.5'">
    <p v-if="grande" class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Cómo llegar</p>

    <div v-if="waze || tel" class="flex gap-2">
      <!-- Google Maps primero: siempre funciona. Con la app la abre, y sin
           ella muestra la ruta en el mismo navegador. -->
      <a v-if="maps" :href="maps" target="_blank" rel="noopener"
        :class="['flex-1 flex items-center justify-center gap-1.5 rounded-xl font-bold shadow-sm transition-transform active:scale-95',
          'bg-blue-600 text-white',
          grande ? 'min-h-12 text-base' : 'min-h-11 text-sm']">
        <MapIcon class="w-5 h-5" />
        Maps
      </a>
      <!-- Waze solo navega con su app: sin ella manda a descargarla. Se deja
           para quien ya la tiene, y lo dice para que nadie se quede atascado.
           Su celeste con letra oscura, que se lee al sol. -->
      <a v-if="waze" :href="waze" target="_blank" rel="noopener"
        :class="['flex-1 flex flex-col items-center justify-center rounded-xl shadow-sm transition-transform active:scale-95',
          'bg-[#33ccff] text-slate-900',
          grande ? 'min-h-12' : 'min-h-11']">
        <span :class="['flex items-center gap-1.5 font-bold leading-tight', grande ? 'text-base' : 'text-sm']">
          <!-- Flecha de navegación: "llévame", no el logo de nadie. -->
          <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor" aria-hidden="true">
            <path d="M20.6 3.4a1 1 0 0 0-1.1-.2l-16 7a1 1 0 0 0 .1 1.9l6.6 1.7 1.7 6.6a1 1 0 0 0 1.9.1l7-16a1 1 0 0 0-.2-1.1Z"/>
          </svg>
          Waze
        </span>
        <span class="text-[10px] font-semibold leading-tight text-slate-800">requiere la app</span>
      </a>
      <a v-if="tel" :href="tel"
        :class="['flex items-center justify-center gap-1.5 rounded-xl font-bold border-2 border-green-600 text-green-700 bg-white transition-transform active:scale-95',
          destino ? (grande ? 'px-4' : 'px-3') : 'flex-1',
          grande ? 'min-h-12 text-base' : 'min-h-11 text-sm']"
        :aria-label="`Llamar a ${orden?.cliente?.nombre ?? 'el cliente'}`">
        <PhoneIcon class="w-5 h-5" />
        Llamar
      </a>
    </div>

    <p v-if="!destino" class="flex items-start gap-1.5 rounded-lg bg-amber-50 border border-amber-200 px-2.5 py-2 text-xs font-medium text-amber-800">
      <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-px" />
      Esta orden no tiene dirección. Llama al cliente para que te la dé.
    </p>
  </div>
</template>
