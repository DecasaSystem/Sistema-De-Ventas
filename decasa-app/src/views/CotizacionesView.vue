<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { getCotizaciones } from '@/api/cotizaciones'
import {
  DocumentTextIcon, PlusIcon, ClockIcon, CheckCircleIcon,
  XCircleIcon, PaperAirplaneIcon,
} from '@heroicons/vue/24/outline'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()

const cotizaciones = ref([])
const loading      = ref(true)
const tab          = ref('activas')   // activas | convertidas | perdidas
const search       = ref('')

const TABS = [
  { value: 'activas',      label: 'Activas'    },
  { value: 'convertidas',  label: 'Convertidas' },
  { value: 'perdidas',     label: 'Perdidas'   },
]

const filtradas = computed(() => {
  const term = search.value.trim().toLowerCase()

  return cotizaciones.value.filter((c) => {
    const coincideTab =
      tab.value === 'activas'     ? ['abierta', 'enviada'].includes(c.cotizacion_estado)
      : tab.value === 'convertidas' ? c.cotizacion_estado === 'convertida'
      : c.cotizacion_estado === 'perdida'

    if (!coincideTab) return false
    if (!term) return true

    return (c.contacto_display ?? '').toLowerCase().includes(term)
      || (c.cotizacion_ref ?? '').toLowerCase().includes(term)
  })
})

const activasCount = computed(() =>
  cotizaciones.value.filter(c => ['abierta', 'enviada'].includes(c.cotizacion_estado)).length
)

async function cargar() {
  loading.value = true
  try {
    const { data } = await getCotizaciones()
    cotizaciones.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : [])
  } catch {
    cotizaciones.value = []
  } finally {
    loading.value = false
  }
}

function formatMoney(val) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', maximumFractionDigits: 0,
  }).format(val ?? 0)
}

function formatFecha(str) {
  if (!str) return '—'
  return new Date(str).toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })
}

function badgeEstado(c) {
  if (c.cotizacion_estado === 'convertida') return { label: 'Convertida', cls: 'bg-emerald-100 text-emerald-700', icon: CheckCircleIcon }
  if (c.cotizacion_estado === 'perdida')    return { label: 'Perdida',    cls: 'bg-gray-100 text-gray-600',       icon: XCircleIcon }
  if (c.esta_vencida)                        return { label: 'Vencida',    cls: 'bg-red-100 text-red-700',         icon: ClockIcon }
  if (c.cotizacion_estado === 'enviada')     return { label: 'Enviada',    cls: 'bg-blue-100 text-blue-700',       icon: PaperAirplaneIcon }
  return { label: 'Abierta', cls: 'bg-violet-100 text-violet-700', icon: DocumentTextIcon }
}

onMounted(cargar)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">

    <!-- Header -->
    <div class="flex items-center gap-2">
      <DocumentTextIcon class="w-6 h-6 text-violet-600" />
      <h2 class="text-lg font-bold text-gray-800 flex-1">Cotizaciones</h2>
      <span v-if="activasCount" class="text-xs bg-violet-100 text-violet-700 font-bold px-2 py-0.5 rounded-full">
        {{ activasCount }}
      </span>
    </div>

    <p class="text-xs text-gray-500">
      Propuestas de precio para clientes que todavía no compran. No reservan inventario.
    </p>

    <!-- Nueva -->
    <button
      @click="router.push({ name: 'nueva-orden', query: { modo: 'cotizacion' } })"
      class="w-full py-2.5 bg-violet-600 hover:bg-violet-700 text-white rounded-xl font-semibold text-sm flex items-center justify-center gap-1.5 transition-colors"
    >
      <PlusIcon class="w-4 h-4" />
      Nueva cotización
    </button>

    <!-- Buscador -->
    <input
      v-model="search"
      placeholder="Buscar por cliente o COT-..."
      class="input"
    />

    <!-- Tabs -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        v-for="t in TABS"
        :key="t.value"
        @click="tab = t.value"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors',
          tab === t.value ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >{{ t.label }}</button>
    </div>

    <!-- Lista -->
    <div v-if="loading" class="space-y-2">
      <div v-for="n in 3" :key="n" class="bg-white rounded-xl p-4 animate-pulse">
        <div class="h-3 bg-gray-100 rounded w-32 mb-2" />
        <div class="h-2.5 bg-gray-100 rounded w-24" />
      </div>
    </div>

    <EmptyState
      v-else-if="!filtradas.length"
      :message="tab === 'activas'
        ? 'Sin cotizaciones activas. Cuando un cliente pregunte cuánto le sale algo, crea una y envíale el PDF.'
        : 'No hay cotizaciones en esta pestaña.'"
    />

    <ul v-else class="space-y-2">
      <li
        v-for="c in filtradas"
        :key="c.id"
        @click="router.push({ name: 'cotizacion-detalle', params: { id: c.id } })"
        class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:shadow transition-shadow"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-800 text-sm truncate">
              {{ c.contacto_display }}
            </p>
            <p class="text-xs text-gray-400 mt-0.5">
              {{ c.cotizacion_ref }} · {{ c.tienda?.nombre }} · {{ formatFecha(c.created_at) }}
            </p>
          </div>
          <span
            :class="['text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap', badgeEstado(c).cls]"
          >{{ badgeEstado(c).label }}</span>
        </div>

        <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-100">
          <span class="text-xs text-gray-500">{{ c.items_count ?? 0 }} ítem(s)</span>
          <span class="font-bold text-violet-700 text-sm">{{ formatMoney(c.valor_total) }}</span>
        </div>

        <p
          v-if="c.esta_vencida && !['convertida', 'perdida'].includes(c.cotizacion_estado)"
          class="text-xs text-red-600 mt-1.5"
        >
          Venció el {{ formatFecha(c.cotizacion_valida_hasta) }} — revisa precios antes de convertirla.
        </p>
      </li>
    </ul>
  </div>
</template>
