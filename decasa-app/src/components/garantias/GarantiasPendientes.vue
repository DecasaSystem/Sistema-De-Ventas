<script setup>
/**
 * Las garantías que esperan a alguien: dictamen, recoger el mueble o la
 * visita a domicilio.
 *
 * Va arriba del tablero del taller, junto a las devoluciones, porque es donde
 * entra todos los días quien decide y porque hay un plazo legal corriendo
 * (15 días hábiles para responder). Las que ya están en el taller o en cambio
 * no salen aquí: esas avanzan solas por el tablero y la entrega.
 *
 * Si no hay ninguna, no se pinta nada.
 */
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { getGarantias } from '@/api/garantias'
import GarantiaTarjeta from './GarantiaTarjeta.vue'
import { ShieldCheckIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'

// En "Mis pasos" solo interesan las visitas a domicilio que le tocan a uno.
const props = defineProps({ soloVisitasMias: { type: Boolean, default: false } })
const emit = defineEmits(['resuelta'])
const auth = useAuthStore()

const lista   = ref([])
const abierto = ref(true)

const QUE_ESPERAN = ['pendiente', 'por_recoger', 'a_domicilio', 'por_devolver']
const pendientes  = computed(() => lista.value.filter(g => props.soloVisitasMias
  ? g.estado === 'a_domicilio' && Number(g.visita_por_id) === Number(auth.usuario?.id)
  : QUE_ESPERAN.includes(g.estado)))
const sinDictamen = computed(() => pendientes.value.filter(g => g.estado === 'pendiente').length)

async function cargar() {
  try {
    lista.value = (await getGarantias({ estado: 'abiertas' }, { silencioso: true })).data
  } catch {}
}
onMounted(cargar)
defineExpose({ cargar })

function alCambiar() {
  cargar()
  emit('resuelta')
}
</script>

<template>
  <div v-if="pendientes.length" class="mb-4">
    <div class="bg-white border border-blue-200 rounded-xl overflow-hidden">
      <button @click="abierto = !abierto" class="w-full flex items-center gap-2.5 px-4 py-3 text-left">
        <div class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center shrink-0">
          <ShieldCheckIcon class="w-5 h-5 text-blue-700" />
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-bold text-gray-800">
            {{ pendientes.length }} {{ pendientes.length === 1 ? 'garantía por atender' : 'garantías por atender' }}
          </p>
          <p class="text-[11px] text-gray-500">
            <template v-if="sinDictamen">{{ sinDictamen }} sin dictamen · </template>
            {{ auth.isSupervisor || auth.gestionaProduccion ? 'Arreglar, cambiar o cerrar.' : 'Esperando que se decidan.' }}
          </p>
        </div>
        <component :is="abierto ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-500 shrink-0" />
      </button>

      <div v-if="abierto" class="px-3 pb-3 space-y-2">
        <GarantiaTarjeta v-for="g in pendientes" :key="g.id" :garantia="g" mostrar-orden @cambio="alCambiar" />
      </div>
    </div>
  </div>
</template>
