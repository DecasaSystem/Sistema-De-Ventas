<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getConsultas, getConsultasMonitoreo } from '@/api/consultas'
import {
  ClipboardDocumentCheckIcon, ClockIcon, CheckCircleIcon,
  SparklesIcon, EyeIcon, ChatBubbleLeftEllipsisIcon,
} from '@heroicons/vue/24/outline'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()
const auth   = useAuthStore()

// ── Mis consultas ─────────────────────────────────────────────────────────────
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

// ── Monitoreo (solo supervisor) ───────────────────────────────────────────────
const monitoreo        = ref([])
const loadingMonitoreo = ref(false)
const tabPrincipal     = ref('mias')   // 'mias' | 'monitoreo'

const monitoreoFiltradas = computed(() => {
  if (tabMonitoreo.value === 'todas') return monitoreo.value
  return monitoreo.value.filter(c => c.estado === tabMonitoreo.value)
})
const tabMonitoreo = ref('pendiente')

async function cargarMonitoreo() {
  loadingMonitoreo.value = true
  try {
    const { data } = await getConsultasMonitoreo()
    monitoreo.value = Array.isArray(data) ? data : []
  } catch {
    monitoreo.value = []
  } finally {
    loadingMonitoreo.value = false
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

function formatMoney(val) {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(val ?? 0)
}

// Carga monitoreo solo la primera vez que el supervisor cambia a esa pestaña
watch(tabPrincipal, (val) => {
  if (val === 'monitoreo' && monitoreo.value.length === 0) cargarMonitoreo()
})

onMounted(cargar)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <ClipboardDocumentCheckIcon class="w-6 h-6 text-violet-600" />
      <h2 class="text-lg font-bold text-gray-800 flex-1">Consultas de costo</h2>
    </div>

    <!-- Tab principal (solo supervisor) -->
    <div v-if="auth.isSupervisor" class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        @click="tabPrincipal = 'mias'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tabPrincipal === 'mias' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Mis consultas
        <span v-if="pendientesCount" class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs bg-violet-100 text-violet-700 rounded-full font-bold">{{ pendientesCount }}</span>
      </button>
      <button
        @click="tabPrincipal = 'monitoreo'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors flex items-center justify-center gap-1.5', tabPrincipal === 'monitoreo' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        <EyeIcon class="w-3.5 h-3.5" />
        Monitoreo general
      </button>
    </div>

    <!-- ═══════════════════════ MIS CONSULTAS ═══════════════════════ -->
    <template v-if="tabPrincipal === 'mias'">
      <p class="text-xs text-gray-500">
        {{ auth.isEbanista || auth.isTapicero
          ? 'Cotizaciones asignadas a ti para calcular'
          : auth.isSupervisor ? 'Consultas en las que participas'
          : 'Cotizaciones que has solicitado para tus órdenes' }}
      </p>

      <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
        <button
          @click="tab = 'pendiente'"
          :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'pendiente' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >
          Pendientes
          <span v-if="pendientesCount" class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs bg-violet-100 text-violet-700 rounded-full font-bold">{{ pendientesCount }}</span>
        </button>
        <button
          @click="tab = 'respondida'"
          :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'respondida' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >Respondidas</button>
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
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="font-semibold text-sm text-gray-800">Orden #{{ c.orden_id }}</p>
              <p class="text-xs text-gray-400">{{ formatFecha(c.created_at) }}</p>
            </div>
            <span :class="['inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full flex-shrink-0', c.estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700']">
              <ClockIcon v-if="c.estado === 'pendiente'" class="w-3 h-3" />
              <CheckCircleIcon v-else class="w-3 h-3" />
              {{ c.estado === 'pendiente' ? 'Pendiente' : 'Respondida' }}
            </span>
          </div>
          <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-500">
            <div>
              <span class="text-gray-400">Cliente</span>
              <p class="font-medium text-gray-700">{{ c.orden?.cliente?.nombre ?? '—' }}</p>
            </div>
            <div>
              <span class="text-gray-400">{{ auth.isEbanista || auth.isTapicero ? 'Solicitado por' : 'Asignado a' }}</span>
              <p class="font-medium text-gray-700">
                {{ auth.isEbanista || auth.isTapicero ? (c.solicitado_por?.nombre ?? '—') : (c.asignado_a?.nombre ?? '—') }}
              </p>
            </div>
          </div>
          <div class="flex flex-wrap gap-1.5 pt-1">
            <span
              v-for="item in itemsPersonalizados(c)"
              :key="item.id"
              :class="['inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium', item.estado === 'calculado' ? 'bg-green-50 text-green-700' : 'bg-violet-50 text-violet-700']"
            >
              <SparklesIcon class="w-3 h-3" />
              {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem personalizado' }}
              <span v-if="item.estado === 'calculado'" class="text-green-500">✓</span>
            </span>
          </div>
        </li>
      </ul>
    </template>

    <!-- ═══════════════════════ MONITOREO GENERAL ═══════════════════════ -->
    <template v-else-if="tabPrincipal === 'monitoreo'">
      <p class="text-xs text-gray-500">Todas las consultas de costo del sistema — solo lectura.</p>

      <!-- Subtabs de monitoreo -->
      <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
        <button
          @click="tabMonitoreo = 'pendiente'"
          :class="['flex-1 py-1.5 text-xs font-medium rounded-lg transition-colors', tabMonitoreo === 'pendiente' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >
          Pendientes
          <span class="ml-1 text-amber-600 font-bold">{{ monitoreo.filter(c => c.estado === 'pendiente').length }}</span>
        </button>
        <button
          @click="tabMonitoreo = 'respondida'"
          :class="['flex-1 py-1.5 text-xs font-medium rounded-lg transition-colors', tabMonitoreo === 'respondida' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >
          Respondidas
          <span class="ml-1 text-green-600 font-bold">{{ monitoreo.filter(c => c.estado === 'respondida').length }}</span>
        </button>
        <button
          @click="tabMonitoreo = 'todas'"
          :class="['flex-1 py-1.5 text-xs font-medium rounded-lg transition-colors', tabMonitoreo === 'todas' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
        >Todas</button>
      </div>

      <AppSpinner v-if="loadingMonitoreo" />
      <EmptyState v-else-if="monitoreoFiltradas.length === 0" message="No hay consultas en este estado." />

      <ul v-else class="space-y-3">
        <li
          v-for="c in monitoreoFiltradas"
          :key="c.id"
          @click="router.push({ name: 'consulta-detalle', params: { id: c.id } })"
          class="bg-white rounded-xl shadow-sm p-4 space-y-2 cursor-pointer hover:shadow-md transition-shadow"
        >
          <!-- Estado + orden -->
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="font-semibold text-sm text-gray-800">Orden #{{ c.orden_id }}</p>
              <p class="text-xs text-gray-400">{{ formatFecha(c.created_at) }}</p>
            </div>
            <span :class="['inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full flex-shrink-0', c.estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700']">
              <ClockIcon v-if="c.estado === 'pendiente'" class="w-3 h-3" />
              <CheckCircleIcon v-else class="w-3 h-3" />
              {{ c.estado === 'pendiente' ? 'Pendiente' : 'Respondida' }}
            </span>
          </div>

          <!-- De / Para / Cliente -->
          <div class="grid grid-cols-3 gap-2 text-xs">
            <div>
              <p class="text-gray-400">Cliente</p>
              <p class="font-medium text-gray-700 truncate">{{ c.orden?.cliente?.nombre ?? '—' }}</p>
            </div>
            <div>
              <p class="text-gray-400">Solicitado por</p>
              <p class="font-medium text-gray-700 truncate">{{ c.solicitado_por?.nombre ?? '—' }}</p>
            </div>
            <div>
              <p class="text-gray-400">Asignado a</p>
              <p class="font-medium text-violet-700 truncate">{{ c.asignado_a?.nombre ?? '—' }}</p>
            </div>
          </div>

          <!-- Ítems con precio si ya respondida -->
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="item in itemsPersonalizados(c)"
              :key="item.id"
              :class="['inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium', item.estado === 'calculado' ? 'bg-green-50 text-green-700' : 'bg-violet-50 text-violet-700']"
            >
              <SparklesIcon class="w-3 h-3" />
              {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem' }}
              <span v-if="item.precio_final" class="font-bold">· {{ formatMoney(item.precio_final) }}</span>
            </span>
          </div>

          <!-- Notas adicionales -->
          <p v-if="c.notas_adicionales" class="text-xs text-gray-500 bg-gray-50 rounded-lg px-2.5 py-1.5 line-clamp-2">
            "{{ c.notas_adicionales }}"
          </p>

          <!-- Footer: fecha respuesta + indicador mensajes -->
          <div class="flex items-center justify-between text-xs text-gray-400 pt-1">
            <span v-if="c.respondido_at">
              Respondida {{ formatFecha(c.respondido_at) }}
            </span>
            <span v-else>Sin respuesta aún</span>
            <span class="flex items-center gap-1">
              <ChatBubbleLeftEllipsisIcon class="w-3.5 h-3.5" />
              Ver hilo
            </span>
          </div>
        </li>
      </ul>
    </template>

  </div>
</template>
