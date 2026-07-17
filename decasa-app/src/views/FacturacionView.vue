<script setup>
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api'
import {
  MagnifyingGlassIcon,
  BanknotesIcon,
  CheckCircleIcon,
  ClockIcon,
  ChevronRightIcon,
  FunnelIcon,
  ExclamationTriangleIcon,
  ArrowPathIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()

const tab      = ref('todas')
const search   = ref('')
const desde    = ref('')
const hasta    = ref('')
const ordenes  = ref([])
const meta     = ref(null)
const cargando = ref(true)
const error    = ref('')
const pagina   = ref(1)
const mostrarFiltros = ref(false)

const tabs = [
  { key: 'todas',     label: 'Todas' },
  { key: 'con_abono', label: 'Con saldo' },
  { key: 'pagadas',   label: 'Pagadas' },
]

async function cargar(resetPagina = true) {
  if (resetPagina) pagina.value = 1
  cargando.value = true
  error.value    = ''
  try {
    const { data } = await api.get('/facturacion/ordenes', {
      params: {
        estado: tab.value,
        search: search.value || undefined,
        desde:  desde.value  || undefined,
        hasta:  hasta.value  || undefined,
        page:   pagina.value,
      },
    })
    ordenes.value = resetPagina ? (data.data ?? []) : [...ordenes.value, ...(data.data ?? [])]
    meta.value    = data
  } catch (e) {
    const msg = e.response?.data?.message ?? e.message ?? 'Error desconocido'
    const status = e.response?.status
    if (status === 403) {
      error.value = 'Tu cuenta no tiene habilitada la función de facturación. Pídele al supervisor que la active.'
    } else {
      error.value = `Error ${status ?? ''}: ${msg}`
    }
  } finally {
    cargando.value = false
  }
}

function cargarMas() {
  if (meta.value?.next_page_url) {
    pagina.value++
    cargar(false)
  }
}

function irOrden(id) {
  router.push({ name: 'orden-detalle', params: { id } })
}

function fmtMoney(v) {
  return '$ ' + Number(v ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 0 })
}

function fmtFecha(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
}

const estadoLabel = {
  pendiente_anticipo: 'En espera',
  en_produccion:      'En producción',
  listo_entrega:      'Listo para entrega',
  entregado:          'Entregado',
}
const estadoColor = {
  pendiente_anticipo: 'bg-yellow-100 text-yellow-700',
  en_produccion:      'bg-blue-100 text-blue-700',
  listo_entrega:      'bg-purple-100 text-purple-700',
  entregado:          'bg-green-100 text-green-700',
}

let debounceTimer = null
watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => cargar(), 350)
})
watch(tab, () => cargar())

onMounted(() => cargar())
</script>

<template>
  <div class="p-4 space-y-4 max-w-2xl mx-auto">

    <!-- Header -->
    <div class="flex items-center justify-between">
      <h1 class="text-lg font-bold text-gray-800">Facturación</h1>
      <div class="flex items-center gap-2">
        <button
          @click="cargar()"
          :disabled="cargando"
          class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-40 transition-colors"
        >
          <ArrowPathIcon class="w-5 h-5" :class="{ 'animate-spin': cargando }" />
        </button>
        <button
          @click="mostrarFiltros = !mostrarFiltros"
          :class="['p-2 rounded-lg transition-colors', mostrarFiltros ? 'bg-blue-100 text-blue-600' : 'text-gray-500 hover:bg-gray-100']"
        >
          <FunnelIcon class="w-5 h-5" />
        </button>
      </div>
    </div>

    <!-- Error -->
    <div v-if="error" class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
      <ExclamationTriangleIcon class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
      <div class="flex-1">
        <p class="text-sm font-medium text-red-700">No se pudo cargar la información</p>
        <p class="text-xs text-red-600 mt-1">{{ error }}</p>
        <button @click="cargar()" class="mt-2 text-xs text-red-600 underline">Reintentar</button>
      </div>
    </div>

    <!-- Búsqueda -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
      <input
        v-model="search"
        type="text"
        placeholder="Buscar por cliente o # orden..."
        class="w-full pl-9 pr-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtros de fecha -->
    <Transition name="fade">
      <div v-if="mostrarFiltros" class="bg-white rounded-xl border border-gray-200 p-3 grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-gray-500 mb-1 block">Desde</label>
          <input v-model="desde" @change="cargar()" type="date" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-400" />
        </div>
        <div>
          <label class="text-xs text-gray-500 mb-1 block">Hasta</label>
          <input v-model="hasta" @change="cargar()" type="date" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-400" />
        </div>
      </div>
    </Transition>

    <!-- Tabs -->
    <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
      <button
        v-for="t in tabs"
        :key="t.key"
        @click="tab = t.key"
        :class="[
          'flex-1 py-2 text-sm font-medium rounded-lg transition-colors',
          tab === t.key ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500',
        ]"
      >
        {{ t.label }}
      </button>
    </div>

    <!-- Cargando -->
    <div v-if="cargando && ordenes.length === 0" class="py-16 text-center text-gray-400 text-sm">
      Cargando...
    </div>

    <!-- Vacío -->
    <div v-else-if="!error && ordenes.length === 0" class="py-16 text-center text-gray-400">
      <BanknotesIcon class="w-10 h-10 mx-auto mb-3 text-gray-300" />
      <p class="text-sm">No hay órdenes con pagos registrados</p>
    </div>

    <!-- Lista -->
    <div v-else class="space-y-3">
      <button
        v-for="o in ordenes"
        :key="o.id"
        @click="irOrden(o.id)"
        class="w-full bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-left hover:border-blue-200 hover:shadow-md transition-all"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-semibold text-gray-800 truncate">{{ o.cliente_nombre }}</span>
              <span class="text-xs text-gray-400 font-mono shrink-0">#{{ o.numero_orden ?? o.id }}</span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">{{ o.vendedor_nombre }} · {{ o.tienda_nombre }}</p>
            <span
              v-if="estadoLabel[o.estado]"
              :class="['mt-1.5 inline-block text-xs px-2 py-0.5 rounded-full font-medium', estadoColor[o.estado] ?? 'bg-gray-100 text-gray-600']"
            >
              {{ estadoLabel[o.estado] }}
            </span>
          </div>
          <div class="flex flex-col items-end shrink-0 gap-1">
            <component
              :is="Number(o.saldo_pendiente) <= 0.01 ? CheckCircleIcon : ClockIcon"
              :class="['w-5 h-5', Number(o.saldo_pendiente) <= 0.01 ? 'text-green-500' : 'text-orange-400']"
            />
            <ChevronRightIcon class="w-4 h-4 text-gray-300" />
          </div>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
          <div class="bg-gray-50 rounded-lg p-2">
            <p class="text-[10px] text-gray-400 mb-0.5">Total</p>
            <p class="text-xs font-semibold text-gray-700">{{ fmtMoney(o.valor_total) }}</p>
          </div>
          <div class="bg-green-50 rounded-lg p-2">
            <p class="text-[10px] text-gray-400 mb-0.5">Pagado</p>
            <p class="text-xs font-semibold text-green-700">{{ fmtMoney(o.total_pagado) }}</p>
          </div>
          <div :class="['rounded-lg p-2', Number(o.saldo_pendiente) <= 0.01 ? 'bg-gray-50' : 'bg-orange-50']">
            <p class="text-[10px] text-gray-400 mb-0.5">Saldo</p>
            <p :class="['text-xs font-semibold', Number(o.saldo_pendiente) <= 0.01 ? 'text-gray-500' : 'text-orange-600']">
              {{ fmtMoney(Math.max(0, Number(o.saldo_pendiente))) }}
            </p>
          </div>
        </div>

        <div class="mt-2 flex items-center justify-between text-[11px] text-gray-400">
          <span>{{ o.num_pagos }} pago{{ o.num_pagos !== 1 ? 's' : '' }}</span>
          <span>Último: {{ fmtFecha(o.ultimo_pago) }}</span>
        </div>
      </button>
    </div>

    <!-- Cargar más -->
    <div v-if="meta?.next_page_url" class="pt-1">
      <button
        @click="cargarMas"
        :disabled="cargando"
        class="w-full py-3 text-sm text-blue-600 font-medium bg-white rounded-xl border border-gray-200 hover:bg-blue-50 disabled:opacity-50 transition-colors"
      >
        {{ cargando ? 'Cargando...' : 'Cargar más' }}
      </button>
    </div>

  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s, transform 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(-4px); }
</style>
