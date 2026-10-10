<script setup>
// ¿Cuánto deja cada tienda? La CONTRIBUCIÓN (lo suyo: ventas menos sus
// materiales, su gente de ventas, sus comisiones y sus gastos) es la cifra
// honesta para decidir si una tienda se sostiene. La UTILIDAD le resta además
// su parte de lo general (taller, administración), que siempre se discute.
import { ref, computed, watch } from 'vue'
import { getPorTienda, getPorCanal } from '@/api/finanzas'
import { pesos, pesosCorto, pct, nombreMes } from '@/utils/finanzas'
import GraficaFinanzas from './GraficaFinanzas.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({ mes: String, version: Number })
const emit = defineEmits(['ajustes'])
const toast = useToast()

const cargando = ref(true)
const datos = ref(null)
const abierta = ref(null)

const canales = ref(null)

async function cargar() {
  cargando.value = true
  try {
    const [t, c] = await Promise.all([getPorTienda(props.mes), getPorCanal(props.mes)])
    datos.value = t.data
    canales.value = c.data
  } catch {
    toast.error('No se pudo cargar la rentabilidad por tienda')
  } finally {
    cargando.value = false
  }
}
watch(() => [props.mes, props.version], cargar, { immediate: true })

const tiendas = computed(() => datos.value?.tiendas ?? [])
const corto = (n) => n.replace('Decasa ', '')

const REPARTO = { ventas: 'según lo que vendió cada una', partes_iguales: 'en partes iguales', ninguno: 'sin repartir' }

function armar(c) {
  if (!tiendas.value.length) return null
  return {
    type: 'bar',
    data: {
      labels: tiendas.value.map(t => corto(t.nombre)),
      datasets: [
        { label: 'Contribución', data: tiendas.value.map(t => t.contribucion), backgroundColor: c.ingresos, borderRadius: 3 },
        { label: 'Después de lo general', data: tiendas.value.map(t => t.utilidad),
          backgroundColor: tiendas.value.map(t => (t.utilidad >= 0 ? c.utilidad : c.perdida)), borderRadius: 3 },
      ],
    },
    options: {
      indexAxis: 'y',
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: (x) => ` ${x.dataset.label}: ${pesos(x.raw)}` } },
      },
      scales: {
        x: { ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla } },
        y: { grid: { display: false }, ticks: { font: { size: 11 } } },
      },
    },
  }
}

// Canales: lo vendido contra la publicidad de cada uno.
const filasCanal = computed(() => (canales.value?.canales ?? []).filter(c => c.vendido > 0 || c.publicidad > 0))
function armarCanales(c) {
  if (!filasCanal.value.length) return null
  return {
    type: 'bar',
    data: {
      labels: filasCanal.value.map(x => x.nombre),
      datasets: [
        { label: 'Vendido', data: filasCanal.value.map(x => x.vendido), backgroundColor: c.ingresos, borderRadius: 3 },
        { label: 'Publicidad', data: filasCanal.value.map(x => x.publicidad), backgroundColor: c.egresos, borderRadius: 3 },
      ],
    },
    options: {
      indexAxis: 'y',
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: (x) => ` ${x.dataset.label}: ${pesos(x.raw)}` } },
      },
      scales: {
        x: { ticks: { callback: pesosCorto, font: { size: 10 } }, grid: { color: c.rejilla } },
        y: { grid: { display: false }, ticks: { font: { size: 11 } } },
      },
    },
  }
}

const filasDesglose = (t) => [
  ['Ventas sin IVA', t.netas, false],
  ['Materiales (est.)', -t.materiales, true],
  ['Nómina de ventas', -t.nomina, true],
  ['Comisiones', -t.comisiones, true],
  ['Gastos de la tienda', -t.gastos, true],
]
</script>

<template>
  <div v-if="cargando && !datos" class="flex justify-center py-16"><AppSpinner /></div>

  <div v-else-if="datos" class="space-y-4" :class="cargando && 'opacity-60'">
    <div v-if="!tiendas.length" class="bg-white rounded-xl shadow-sm p-6 text-center text-sm text-gray-400">
      Sin ventas en {{ nombreMes(mes) }}.
    </div>

    <template v-else>
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-800">Cuánto deja cada tienda</p>
        <p class="text-xs text-gray-400 mb-2">
          Azul: lo que aporta con lo suyo. Verde/rojo: después de su parte de lo general
          ({{ pesosCorto(datos.general) }} repartidos {{ REPARTO[datos.reparto] }}).
        </p>
        <GraficaFinanzas :armar="armar" :datos="tiendas" :alto="tiendas.length > 4 ? 'h-80' : 'h-60'" descripcion="Contribución y utilidad por tienda" />
        <button @click="emit('ajustes')" class="mt-2 text-xs font-semibold text-blue-600">Cambiar cómo se reparte lo general</button>
      </div>

      <div v-for="t in tiendas" :key="t.tienda_id" class="bg-white rounded-xl shadow-sm">
        <button @click="abierta = abierta === t.tienda_id ? null : t.tienda_id" class="w-full p-4 flex items-center justify-between gap-2 text-left">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-800 truncate">{{ t.nombre }}</p>
            <p class="text-[11px] text-gray-400">{{ t.ordenes }} órdenes · vendió {{ pesosCorto(t.ventas) }}</p>
          </div>
          <div class="text-right shrink-0">
            <p :class="['text-sm font-bold', t.contribucion >= 0 ? 'text-green-600' : 'text-red-600']">{{ pesosCorto(t.contribucion) }}</p>
            <p class="text-[11px] text-gray-400">aporta {{ pct(t.margen_contribucion, 0) }}</p>
          </div>
        </button>
        <div v-if="abierta === t.tienda_id" class="px-4 pb-4 border-t border-gray-50 pt-3 space-y-1.5">
          <div v-for="[nombre, valor, resta] in filasDesglose(t)" :key="nombre" class="flex justify-between text-xs">
            <span class="text-gray-500">{{ resta ? '− ' : '' }}{{ nombre }}</span>
            <span class="text-gray-700">{{ pesos(Math.abs(valor)) }}</span>
          </div>
          <div class="flex justify-between text-xs font-semibold border-t border-gray-100 pt-1.5">
            <span class="text-gray-800">Contribución</span><span :class="t.contribucion >= 0 ? 'text-green-600' : 'text-red-600'">{{ pesos(t.contribucion) }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-gray-500">− Parte de lo general</span><span class="text-gray-700">{{ pesos(t.generales) }}</span>
          </div>
          <div class="flex justify-between text-xs font-semibold">
            <span class="text-gray-800">Utilidad</span>
            <span :class="t.utilidad >= 0 ? 'text-green-600' : 'text-red-600'">{{ pesos(t.utilidad) }} <span class="font-normal text-gray-400">({{ pct(t.margen, 0) }})</span></span>
          </div>
        </div>
      </div>

      <!-- Canales -->
      <div v-if="filasCanal.length" class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold text-gray-800">Por dónde se vende</p>
        <p class="text-xs text-gray-400 mb-2">Lo vendido por cada canal contra lo que se gastó en su publicidad (marca el canal al registrar un gasto de publicidad).</p>
        <GraficaFinanzas :armar="armarCanales" :datos="filasCanal" :alto="filasCanal.length > 4 ? 'h-72' : 'h-52'" descripcion="Ventas y publicidad por canal" />
        <ul class="divide-y divide-gray-100 mt-2">
          <li v-for="c in filasCanal" :key="c.canal" class="py-1.5 flex items-center justify-between text-xs gap-2">
            <span class="text-gray-700">{{ c.nombre }} <span class="text-gray-400">· {{ c.ordenes }} órdenes</span></span>
            <span class="shrink-0 text-gray-700">
              {{ pesosCorto(c.vendido) }}
              <template v-if="c.retorno"> · <b class="text-green-600">${{ c.retorno.toLocaleString('es-CO') }}</b> por cada $1 de publicidad</template>
            </span>
          </li>
        </ul>
      </div>

      <p class="text-[11px] text-gray-400 px-1">
        La nómina de ventas es la de quienes tienen esa tienda en su ficha; el taller, la administración y los gastos sin tienda son "lo general".
        Registra los gastos con su tienda (arriendo, servicios) para que esta cuenta sea más precisa.
      </p>
    </template>
  </div>
</template>
