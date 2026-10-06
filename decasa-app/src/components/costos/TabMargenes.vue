<script setup>
/**
 * Márgenes: cada ficha vinculada a un producto, del peor margen al mejor.
 * Factor = precio ÷ costo; se compara contra el factor sugerido de Tarifas.
 */
import { ref, computed, onMounted } from 'vue'
import { ChevronRightIcon } from '@heroicons/vue/24/outline'
import { getFichas } from '@/api/fichas'
import { useToast } from '@/composables/useToast'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { formatPct } from '@/utils/descuentos'
import { useCostos } from './useCostos'

const emit = defineEmits(['abrir'])

const toast = useToast()
const { factorVenta, cargarTarifas, margenDe, COLOR_MARGEN, formatPeso, formatFactor } = useCostos()

const fichas   = ref([])
const cargando = ref(false)
const filtro   = ref('todas')   // 'todas' | 'perdida' | 'bajo'

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getFichas({})
    fichas.value = data.fichas
  } catch {
    toast.error('No se pudieron cargar los márgenes.')
  } finally {
    cargando.value = false
  }
}

onMounted(() => { cargarTarifas(); cargar() })
defineExpose({ cargar })

const vinculadas = computed(() =>
  fichas.value
    .filter(f => f.producto_id)
    .map(f => ({ ...f, margen: margenDe(f.costo_total, f.producto_precio) }))
    .sort((a, b) => (a.margen?.factor ?? Infinity) - (b.margen?.factor ?? Infinity))
)
const cuenta = computed(() => ({
  perdida:     vinculadas.value.filter(f => f.margen?.nivel === 'perdida').length,
  bajo:        vinculadas.value.filter(f => f.margen?.nivel === 'bajo').length,
  ok:          vinculadas.value.filter(f => f.margen?.nivel === 'ok').length,
  sinVincular: fichas.value.filter(f => !f.producto_id).length,
}))
const lista = computed(() => filtro.value === 'todas' ? vinculadas.value : vinculadas.value.filter(f => f.margen?.nivel === filtro.value))
</script>

<template>
  <AppSpinner v-if="cargando && !fichas.length" />

  <div v-else class="space-y-4">
    <!-- Resumen: tocar un número filtra la lista -->
    <div class="grid grid-cols-3 gap-2">
      <button @click="filtro = filtro === 'perdida' ? 'todas' : 'perdida'"
        :class="['bg-white rounded-xl shadow-sm p-3 text-left border-2', filtro === 'perdida' ? 'border-red-500' : 'border-transparent']">
        <span class="block text-2xl font-bold tabular-nums" :class="cuenta.perdida ? 'text-red-600' : 'text-gray-300'">{{ cuenta.perdida }}</span>
        <span class="block text-xs text-gray-500">a pérdida</span>
      </button>
      <button @click="filtro = filtro === 'bajo' ? 'todas' : 'bajo'"
        :class="['bg-white rounded-xl shadow-sm p-3 text-left border-2', filtro === 'bajo' ? 'border-amber-500' : 'border-transparent']">
        <span class="block text-2xl font-bold tabular-nums" :class="cuenta.bajo ? 'text-amber-600' : 'text-gray-300'">{{ cuenta.bajo }}</span>
        <span class="block text-xs text-gray-500">bajo {{ formatFactor(factorVenta) }}</span>
      </button>
      <div class="bg-white rounded-xl shadow-sm p-3 border-2 border-transparent">
        <span class="block text-2xl font-bold tabular-nums text-green-600">{{ cuenta.ok }}</span>
        <span class="block text-xs text-gray-500">sanos</span>
      </div>
    </div>

    <p class="text-xs text-gray-500 px-1">
      Se compara el costo de cada ficha con el precio base del producto que tiene vinculado (sin el adicional de medidas o variantes).
      <template v-if="cuenta.sinVincular">
        {{ cuenta.sinVincular }} {{ cuenta.sinVincular === 1 ? 'ficha no tiene' : 'fichas no tienen' }} producto: ábrelas en Productos y vincúlalas.
      </template>
    </p>

    <div v-if="!vinculadas.length" class="bg-white rounded-xl shadow-sm p-6 text-center">
      <p class="text-sm text-gray-700 font-medium">Aún no hay márgenes que mostrar</p>
      <p class="text-xs text-gray-500 mt-1">Abre una ficha en Productos y toca «Vincular con un producto».</p>
    </div>

    <p v-else-if="!lista.length" class="text-center py-8 text-sm text-gray-400">Ninguna ficha en este grupo.</p>

    <div v-else class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
      <button v-for="f in lista" :key="f.id" @click="emit('abrir', f.id)"
        class="w-full flex items-center gap-3 px-3 py-3 hover:bg-gray-50 text-left">
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-gray-800 truncate">{{ f.producto_nombre }}</p>
          <p class="text-xs text-gray-400 truncate">Ficha: {{ f.nombre }}</p>
          <p class="text-xs text-gray-500 mt-0.5 tabular-nums">
            Cuesta {{ formatPeso(f.costo_total) }}, se vende a {{ formatPeso(f.producto_precio) }}
          </p>
        </div>
        <div v-if="f.margen" class="text-right flex-shrink-0">
          <span :class="['inline-block text-sm font-bold rounded-lg px-2 py-0.5 tabular-nums', COLOR_MARGEN[f.margen.nivel]]">
            {{ formatFactor(f.margen.factor, 2) }}
          </span>
          <p class="text-xs text-gray-400 mt-0.5 tabular-nums">{{ formatPct(f.margen.pct) }}% margen</p>
        </div>
        <span v-else class="text-xs text-gray-400 flex-shrink-0">Sin precio</span>
        <ChevronRightIcon class="w-4 h-4 text-gray-300 flex-shrink-0" />
      </button>
    </div>
  </div>
</template>
