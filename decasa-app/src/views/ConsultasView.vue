<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getConsultas } from '@/api/consultas'
import { ClipboardDocumentCheckIcon, ClockIcon, CheckCircleIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()
const auth   = useAuthStore()

const consultas       = ref([])
const loading         = ref(true)
const tab             = ref('pendiente')

const filtradas = computed(() =>
  consultas.value.filter(c => c.estado === tab.value)
)

const pendientesCount = computed(() =>
  consultas.value.filter(c => c.estado === 'pendiente').length
)

async function cargar() {
  loading.value = true
  try {
    const { data } = await getConsultas()
    consultas.value = Array.isArray(data) ? data : []
  } catch {
    consultas.value = []
  } finally {
    loading.value = false
  }
}

function formatFecha(str) {
  if (!str) return '—'
  const d = new Date(str)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function itemsPersonalizados(consulta) {
  return consulta.items ?? []
}

onMounted(cargar)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <ClipboardDocumentCheckIcon class="w-6 h-6 text-violet-600" />
      <h2 class="text-lg font-bold text-gray-800 flex-1">Consultas de costo</h2>
    </div>
    <p class="text-xs text-gray-500">
      {{ auth.isEbanista || auth.isTapicero
        ? 'Cotizaciones asignadas a ti para calcular'
        : 'Cotizaciones que has solicitado para tus órdenes' }}
    </p>

    <!-- Tabs -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'pendiente'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'pendiente' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Pendientes
        <span v-if="pendientesCount" class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs bg-violet-100 text-violet-700 rounded-full font-bold">
          {{ pendientesCount }}
        </span>
      </button>
      <button
        @click="tab = 'respondida'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'respondida' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Respondidas
      </button>
    </div>

    <AppSpinner v-if="loading" />

    <EmptyState
      v-else-if="filtradas.length === 0"
      :message="tab === 'pendiente' ? 'No hay consultas pendientes.' : 'No hay consultas respondidas.'"
    />

    <ul v-else class="space-y-3">
      <li
        v-for="c in filtradas"
        :key="c.id"
        @click="router.push({ name: 'consulta-detalle', params: { id: c.id } })"
        class="bg-white rounded-xl shadow-sm p-4 space-y-2 cursor-pointer hover:shadow-md transition-shadow"
      >
        <!-- Header de la card -->
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="font-semibold text-sm text-gray-800">
              Orden #{{ c.orden_id }}
            </p>
            <p class="text-xs text-gray-400">{{ formatFecha(c.created_at) }}</p>
          </div>
          <span
            :class="[
              'inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full flex-shrink-0',
              c.estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'
            ]"
          >
            <ClockIcon v-if="c.estado === 'pendiente'" class="w-3 h-3" />
            <CheckCircleIcon v-else class="w-3 h-3" />
            {{ c.estado === 'pendiente' ? 'Pendiente' : 'Respondida' }}
          </span>
        </div>

        <!-- Info -->
        <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-500">
          <div>
            <span class="text-gray-400">Cliente</span>
            <p class="font-medium text-gray-700">{{ c.orden?.cliente?.nombre ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-400">{{ auth.isEbanista || auth.isTapicero ? 'Solicitado por' : 'Asignado a' }}</span>
            <p class="font-medium text-gray-700">
              {{ auth.isEbanista || auth.isTapicero
                ? (c.solicitado_por?.nombre ?? '—')
                : (c.asignado_a?.nombre ?? '—') }}
            </p>
          </div>
        </div>

        <!-- Items personalizados -->
        <div class="flex flex-wrap gap-1.5 pt-1">
          <span
            v-for="item in itemsPersonalizados(c)"
            :key="item.id"
            :class="[
              'inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium',
              item.estado === 'calculado' ? 'bg-green-50 text-green-700' : 'bg-violet-50 text-violet-700'
            ]"
          >
            <SparklesIcon class="w-3 h-3" />
            {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem personalizado' }}
            <span v-if="item.estado === 'calculado'" class="text-green-500">✓</span>
          </span>
        </div>
      </li>
    </ul>
  </div>
</template>
