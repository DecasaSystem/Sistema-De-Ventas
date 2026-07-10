<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import {
  ReceiptPercentIcon,
  CheckCircleIcon,
  ClockIcon,
  ExclamationCircleIcon,
  XCircleIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  Cog6ToothIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'

const auth  = useAuthStore()
const toast = useToast()

// ── Estado ─────────────────────────────────────────────────────────────────────
const tab          = ref('lista')   // pendiente | lista | pagada
const vistaTab     = ref('estado')  // estado | vendedor
const cargando     = ref(true)
const comisiones   = ref([])
const vendedores   = ref([])
const tiendas      = ref([])
const metas        = ref([])
const vendedorSel  = ref(null)
const mesActual    = ref(new Date().toISOString().slice(0, 7))
const expandidos   = ref(new Set())
const pagando      = ref(null)
const mostrarMetas = ref(false)
const recalculando = ref(false)
const guardandoMeta = ref(null)
const metaEdits    = ref({}) // tienda_id → valor string en edición
const divisorEdits = ref({}) // tienda_id → divisor int

// ── Carga ──────────────────────────────────────────────────────────────────────
async function cargar() {
  cargando.value = true
  try {
    const params = {}
    if (vendedorSel.value)             params.vendedor_id = vendedorSel.value
    if (vistaTab.value === 'vendedor') params.vendedor_id = vendedorSel.value

    const [cRes, vRes, tRes] = await Promise.all([
      api.get('/comisiones', { params }),
      api.get('/comisiones/vendedores'),
      api.get('/tiendas'),
    ])
    comisiones.value = cRes.data
    vendedores.value = vRes.data
    tiendas.value    = tRes.data
    await cargarMetas()
  } catch {
    toast.error('Error cargando comisiones')
  } finally {
    cargando.value = false
  }
}

async function cargarMetas() {
  const { data } = await api.get('/comisiones/metas', { params: { mes: mesActual.value } })
  metas.value = data
  metaEdits.value    = Object.fromEntries(data.map(m => [m.tienda_id, m.meta != null ? String(m.meta) : '']))
  divisorEdits.value = Object.fromEntries(data.map(m => [m.tienda_id, m.divisor_asesores ?? 1]))
}

async function recalcular() {
  recalculando.value = true
  try {
    const { data } = await api.post('/comisiones/recalcular')
    toast.success(`Recalculado — ${data.actualizadas} actualizadas, ${data.notificadas} notificadas`)
    await cargar()
  } catch {
    toast.error('Error al recalcular')
  } finally {
    recalculando.value = false
  }
}

async function pagar(id) {
  if (!confirm('¿Marcar esta comisión como pagada?')) return
  pagando.value = id
  try {
    const { data } = await api.post(`/comisiones/${id}/pagar`)
    const idx = comisiones.value.findIndex(c => c.id === id)
    if (idx !== -1) comisiones.value[idx] = { ...comisiones.value[idx], ...data, estado: 'pagada', estado_calculado: 'pagada' }
    toast.success('Comisión marcada como pagada')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Error al pagar')
  } finally {
    pagando.value = null
  }
}

async function guardarMeta(tiendaId) {
  const val = parseFloat(metaEdits.value[tiendaId])
  if (isNaN(val) || val < 0) { toast.error('Valor inválido'); return }
  guardandoMeta.value = tiendaId
  try {
    await api.post('/comisiones/metas', {
      tienda_id:        tiendaId,
      mes:              mesActual.value,
      meta:             val,
      divisor_asesores: parseInt(divisorEdits.value[tiendaId] ?? 1),
    })
    toast.success('Meta guardada')
    await cargarMetas()
  } catch {
    toast.error('Error guardando meta')
  } finally {
    guardandoMeta.value = null
  }
}

// ── Computed ───────────────────────────────────────────────────────────────────
const filtradas = computed(() => {
  let list = comisiones.value
  if (vistaTab.value === 'vendedor' && vendedorSel.value) {
    list = list.filter(c => c.vendedor_id === vendedorSel.value)
  }
  return list.filter(c => (c.estado_calculado ?? c.estado) === tab.value)
})

const badges = computed(() => ({
  pendiente: comisiones.value.filter(c => (c.estado_calculado ?? c.estado) === 'pendiente').length,
  lista:     comisiones.value.filter(c => (c.estado_calculado ?? c.estado) === 'lista').length,
  pagada:    comisiones.value.filter(c => (c.estado_calculado ?? c.estado) === 'pagada').length,
}))

const vendedorActual = computed(() => vendedores.value.find(v => v.id === vendedorSel.value))

// ── Helpers ────────────────────────────────────────────────────────────────────
function cop(v) {
  if (!v && v !== 0) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(v)
}

function fmtFecha(f) {
  if (!f) return '—'
  return new Date(f + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
}

function diasLabel(c) {
  if (c.estado_calculado === 'pagada') {
    return { text: 'Pagada', color: 'text-green-600', bg: 'bg-green-50' }
  }
  const d = c.dias_restantes
  if (d > 0) {
    return { text: `${d}d restantes`, color: 'text-blue-600', bg: 'bg-blue-50' }
  }
  if (d === 0) {
    return { text: 'Disponible hoy', color: 'text-orange-600', bg: 'bg-orange-50' }
  }
  return { text: `${Math.abs(d)}d atrasada`, color: 'text-red-600', bg: 'bg-red-50' }
}

function toggleExpand(id) {
  if (expandidos.value.has(id)) expandidos.value.delete(id)
  else expandidos.value.add(id)
}

onMounted(cargar)
</script>

<template>
  <div class="max-w-lg mx-auto px-4 pt-4 pb-28">

    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-xl font-bold text-gray-800 flex items-center gap-2">
        <ReceiptPercentIcon class="w-6 h-6 text-green-600" />
        Comisiones
      </h1>
      <div class="flex gap-2">
        <button
          @click="mostrarMetas = !mostrarMetas"
          class="flex items-center gap-1 text-xs font-semibold text-gray-600 border border-gray-200 bg-white rounded-lg px-2.5 py-1.5 hover:bg-gray-50"
        >
          <Cog6ToothIcon class="w-3.5 h-3.5" />
          Metas
        </button>
        <button
          @click="recalcular"
          :disabled="recalculando"
          class="text-xs font-semibold text-blue-600 border border-blue-200 bg-blue-50 rounded-lg px-2.5 py-1.5 hover:bg-blue-100 disabled:opacity-50"
        >
          {{ recalculando ? 'Calculando…' : 'Recalcular' }}
        </button>
      </div>
    </div>

    <!-- Panel metas -->
    <div v-if="mostrarMetas" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
      <div class="flex items-center justify-between mb-3">
        <p class="text-sm font-semibold text-gray-700">Metas por tienda</p>
        <input
          type="month"
          v-model="mesActual"
          @change="cargarMetas"
          class="text-xs border border-gray-200 rounded-lg px-2 py-1 focus:ring-2 focus:ring-green-500 focus:border-transparent"
        />
      </div>
      <div class="space-y-3">
        <div v-for="m in metas" :key="m.tienda_id" class="border border-gray-100 rounded-xl p-3">
          <p class="text-xs font-semibold text-gray-700 mb-2 truncate">{{ m.nombre }}</p>
          <div class="flex items-center gap-2">
            <div class="flex-1">
              <p class="text-[10px] text-gray-400 mb-0.5">Meta mensual</p>
              <input
                type="number"
                v-model="metaEdits[m.tienda_id]"
                placeholder="0"
                class="w-full text-xs text-right border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-green-500 focus:border-transparent"
              />
            </div>
            <div class="w-20">
              <p class="text-[10px] text-gray-400 mb-0.5">÷ Asesores</p>
              <input
                type="number"
                v-model="divisorEdits[m.tienda_id]"
                min="1"
                max="20"
                class="w-full text-xs text-center border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-green-500 focus:border-transparent"
              />
            </div>
            <div class="pt-4">
              <button
                @click="guardarMeta(m.tienda_id)"
                :disabled="guardandoMeta === m.tienda_id"
                class="text-xs font-semibold text-green-700 bg-green-50 border border-green-200 rounded-lg px-2.5 py-1.5 hover:bg-green-100 disabled:opacity-50"
              >{{ guardandoMeta === m.tienda_id ? '…' : 'OK' }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Resumen vendedores (vista por vendedor) -->
    <div class="flex gap-2 mb-3">
      <button
        @click="vistaTab = 'estado'; vendedorSel = null; cargar()"
        :class="vistaTab === 'estado' ? 'bg-green-600 text-white' : 'bg-white text-gray-500 border border-gray-200'"
        class="flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors"
      >Por estado</button>
      <button
        @click="vistaTab = 'vendedor'"
        :class="vistaTab === 'vendedor' ? 'bg-green-600 text-white' : 'bg-white text-gray-500 border border-gray-200'"
        class="flex-1 text-xs font-semibold rounded-lg py-1.5 transition-colors"
      >Por vendedor</button>
    </div>

    <!-- Selector vendedor -->
    <div v-if="vistaTab === 'vendedor'" class="mb-3">
      <select
        v-model="vendedorSel"
        @change="cargar"
        class="w-full text-sm border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-green-500 focus:border-transparent"
      >
        <option :value="null">Todos los vendedores</option>
        <option v-for="v in vendedores" :key="v.id" :value="v.id">
          {{ v.nombre }} ({{ v.lista }} listas · {{ v.pendiente }} pend.)
        </option>
      </select>
      <!-- Resumen del vendedor seleccionado -->
      <div v-if="vendedorActual" class="mt-2 grid grid-cols-3 gap-2">
        <div class="bg-orange-50 rounded-xl p-2.5 text-center">
          <p class="text-lg font-bold text-orange-600">{{ vendedorActual.pendiente }}</p>
          <p class="text-[10px] text-orange-500 font-medium">Pendientes</p>
        </div>
        <div class="bg-green-50 rounded-xl p-2.5 text-center">
          <p class="text-lg font-bold text-green-700">{{ vendedorActual.lista }}</p>
          <p class="text-[10px] text-green-600 font-medium">Listas</p>
        </div>
        <div class="bg-gray-100 rounded-xl p-2.5 text-center">
          <p class="text-lg font-bold text-gray-700">{{ vendedorActual.pagada }}</p>
          <p class="text-[10px] text-gray-500 font-medium">Pagadas</p>
        </div>
      </div>
    </div>

    <!-- Tabs estado -->
    <div class="flex rounded-xl bg-gray-100 p-1 mb-4 gap-1">
      <button
        v-for="t in [
          { key: 'pendiente', label: 'Pendientes' },
          { key: 'lista',     label: 'Listas' },
          { key: 'pagada',    label: 'Pagadas' },
        ]"
        :key="t.key"
        @click="tab = t.key"
        :class="[
          'flex-1 py-1.5 rounded-lg text-xs font-semibold transition-colors relative',
          tab === t.key ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'
        ]"
      >
        {{ t.label }}
        <span
          v-if="badges[t.key] > 0"
          :class="['absolute -top-1 -right-1 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5',
            t.key === 'lista' ? 'bg-green-500' : t.key === 'pendiente' ? 'bg-orange-400' : 'bg-gray-400']"
        >{{ badges[t.key] > 9 ? '9+' : badges[t.key] }}</span>
      </button>
    </div>

    <!-- Cargando -->
    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-green-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <!-- Vacío -->
    <div v-else-if="filtradas.length === 0" class="text-center py-14">
      <ReceiptPercentIcon class="w-12 h-12 text-gray-300 mx-auto mb-2" />
      <p class="text-gray-400 text-sm">
        {{ tab === 'pendiente' ? 'No hay comisiones pendientes' : tab === 'lista' ? 'No hay comisiones listas' : 'No hay comisiones pagadas' }}
      </p>
    </div>

    <!-- Lista comisiones -->
    <div v-else class="space-y-3">
      <div
        v-for="c in filtradas"
        :key="c.id"
        class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden"
        :class="c.atrasada ? 'border-l-4 border-l-red-400' : c.estado_calculado === 'lista' ? 'border-l-4 border-l-green-400' : ''"
      >
        <!-- Card header -->
        <div class="p-4">
          <div class="flex items-start justify-between gap-2 mb-2">
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-800 text-sm truncate">{{ c.vendedor_nombre }}</p>
              <p class="text-xs text-gray-400">{{ c.tienda_nombre }} · Orden #{{ c.orden_numero }}</p>
            </div>
            <!-- Comisión calculada -->
            <div class="text-right shrink-0">
              <p class="text-lg font-bold text-green-700">{{ cop(c.monto_comision) }}</p>
              <p class="text-[10px] text-gray-400">de {{ cop(c.valor_orden) }}</p>
            </div>
          </div>

          <!-- Tiempo restante / estado -->
          <div class="flex items-center justify-between gap-2">
            <span :class="['inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full', diasLabel(c).bg, diasLabel(c).color]">
              <ExclamationCircleIcon v-if="c.atrasada" class="w-3 h-3" />
              <ClockIcon v-else-if="c.estado_calculado !== 'pagada'" class="w-3 h-3" />
              <CheckCircleIcon v-else class="w-3 h-3" />
              {{ diasLabel(c).text }}
            </span>
            <div class="flex items-center gap-2">
              <!-- Botón pagar (solo en lista) -->
              <button
                v-if="c.estado_calculado === 'lista'"
                @click="pagar(c.id)"
                :disabled="pagando === c.id"
                class="flex items-center gap-1 text-xs font-bold text-white bg-green-600 hover:bg-green-700 rounded-lg px-3 py-1.5 transition-colors disabled:opacity-50"
              >
                <CheckCircleIcon class="w-3.5 h-3.5" />
                {{ pagando === c.id ? '…' : 'Marcar pagada' }}
              </button>
              <!-- Pagada: mostrar fecha -->
              <span v-if="c.estado_calculado === 'pagada'" class="text-xs text-gray-400">
                Pagada {{ fmtFecha(c.fecha_pago) }}
                <span v-if="c.pagada_por">por {{ c.pagada_por?.nombre }}</span>
              </span>
              <!-- Expandir -->
              <button @click="toggleExpand(c.id)" class="text-gray-300 hover:text-gray-500 transition-colors">
                <ChevronUpIcon v-if="expandidos.has(c.id)" class="w-4 h-4" />
                <ChevronDownIcon v-else class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <!-- Detalle expandido -->
        <div v-if="expandidos.has(c.id)" class="border-t border-gray-50 px-4 py-3 bg-gray-50 space-y-2">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Requisitos</p>

          <!-- Req 50% pagado -->
          <div class="flex items-center justify-between text-xs">
            <div class="flex items-center gap-1.5">
              <CheckCircleIcon v-if="c.req_50_pct" class="w-4 h-4 text-green-500" />
              <XCircleIcon v-else class="w-4 h-4 text-red-400" />
              <span :class="c.req_50_pct ? 'text-green-700' : 'text-gray-500'">50% de la orden pagado</span>
            </div>
            <span class="text-gray-400 font-medium">{{ c.pct_pagado }}%</span>
          </div>

          <!-- Req meta cumplida -->
          <div class="flex items-center justify-between text-xs">
            <div class="flex items-center gap-1.5">
              <CheckCircleIcon v-if="c.meta_cumplida" class="w-4 h-4 text-green-500" />
              <XCircleIcon v-else class="w-4 h-4 text-red-400" />
              <span :class="c.meta_cumplida ? 'text-green-700' : 'text-gray-500'">Meta tienda alcanzada</span>
            </div>
            <span class="text-gray-400 font-medium">
              {{ cop(c.total_tienda_mes) }} / {{ c.meta_tienda > 0 ? cop(c.meta_tienda) : 'Sin meta' }}
            </span>
          </div>

          <!-- Req mes vencido -->
          <div class="flex items-center justify-between text-xs">
            <div class="flex items-center gap-1.5">
              <CheckCircleIcon v-if="c.req_mes_vencido" class="w-4 h-4 text-green-500" />
              <XCircleIcon v-else class="w-4 h-4 text-red-400" />
              <span :class="c.req_mes_vencido ? 'text-green-700' : 'text-gray-500'">1 mes desde la venta</span>
            </div>
            <span class="text-gray-400 font-medium">Disponible {{ fmtFecha(c.fecha_disponible) }}</span>
          </div>

          <!-- Cálculo -->
          <div v-if="c.meta_tienda > 0" class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500 space-y-0.5">
            <p class="font-semibold text-gray-600 mb-1">Cálculo</p>
            <p>Ventas tienda mes: {{ cop(c.total_tienda_mes) }}</p>
            <p>Meta tienda: − {{ cop(c.meta_tienda) }}</p>
            <p>Base bruta: {{ cop(Math.max(0, c.total_tienda_mes - c.meta_tienda)) }}</p>
            <p>Sin IVA (÷1.19): {{ cop(Math.max(0, (c.total_tienda_mes - c.meta_tienda) / 1.19)) }}</p>
            <p>Pool 5%: {{ cop(c.comision_pool) }}</p>
            <p v-if="c.divisor_asesores > 1">÷ {{ c.divisor_asesores }} asesores: {{ cop(c.comision_asesor) }}</p>
            <p class="font-semibold text-green-700 border-t border-gray-100 pt-0.5 mt-0.5">Comisión asesor: {{ cop(c.comision_asesor) }}</p>
            <p>Ventas propias mes: {{ cop(c.total_vendedor_mes) }} ({{ c.total_vendedor_mes > 0 ? Math.round(c.valor_orden / c.total_vendedor_mes * 100) : 0 }}% esta orden)</p>
            <p class="font-bold text-green-700">Comisión esta orden: {{ cop(c.monto_comision) }}</p>
          </div>

          <div class="pt-1 text-xs text-gray-400">
            Venta: {{ fmtFecha(c.fecha_venta) }} · Mes: {{ c.mes_venta }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
