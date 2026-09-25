<script setup>
/**
 * Las cuentas de toda la tienda en el mes, no solo de esta persona.
 *
 * Cada tarjeta trae lo suyo y los del mismo equipo salen con cifras
 * distintas; para saber cuánto vendió la tienda, cuánto se llevó el datáfono
 * o por qué el pool dio lo que dio había que sumar las tarjetas a mano. El
 * servidor ya lo manda sumado sin repetir órdenes (ver resumirTienda).
 *
 * Va plegado: se repite en cada tarjeta del mismo equipo, y abierto en todas
 * la pantalla se vuelve larguísima.
 */
import { computed } from 'vue'

const props = defineProps({
  resumen: { type: Object, default: null },
  tienda:  { type: String, default: '' },
})

const cop = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-CO')

const TIPOS = [
  { clave: 'venta',        label: 'Normales' },
  { clave: 'restauracion', label: 'Restauraciones' },
  { clave: 'fv2',          label: 'FV2' },
]

const tipos = computed(() =>
  TIPOS
    .map(t => ({ ...t, ...(props.resumen?.por_tipo?.[t.clave] ?? { monto: 0, ordenes: 0 }) }))
    .filter(t => t.ordenes > 0)
)

const p = computed(() => props.resumen?.pool ?? null)
const esTrimestral = computed(() => p.value?.periodicidad === 'trimestral')
const excedente = computed(() => p.value ? Math.max(0, p.value.ventas_cuentan - p.value.meta) : 0)
const faltaParaMeta = computed(() => p.value ? Math.max(0, p.value.meta - p.value.ventas_cuentan) : 0)
</script>

<template>
  <details v-if="resumen" class="rounded-xl border border-indigo-100 bg-indigo-50/50 px-3 py-2 group">
    <summary class="flex items-center justify-between cursor-pointer list-none text-[11px]">
      <span class="font-semibold text-indigo-700 uppercase tracking-wide">Toda la tienda · {{ tienda }}</span>
      <span class="text-indigo-600">
        <span class="font-bold">{{ cop(resumen.comision) }}</span>
        <span class="ml-1 group-open:hidden">▾</span><span class="ml-1 hidden group-open:inline">▴</span>
      </span>
    </summary>

    <div class="mt-2 space-y-2.5 text-[11px]">
      <!-- Lo vendido y lo que de verdad entró -->
      <div class="space-y-0.5">
        <div class="flex justify-between">
          <span class="text-gray-600">Vendido ({{ resumen.ordenes }} {{ resumen.ordenes === 1 ? 'orden' : 'órdenes' }})</span>
          <span class="text-gray-700 tabular-nums">{{ cop(resumen.vendido) }}</span>
        </div>
        <div v-if="resumen.datafono > 0" class="flex justify-between">
          <span class="text-gray-400">− Datáfono (5,5% de {{ cop(resumen.tarjeta) }} con tarjeta)</span>
          <span class="text-gray-400 tabular-nums">− {{ cop(resumen.datafono) }}</span>
        </div>
        <div class="flex justify-between border-t border-indigo-100 pt-0.5">
          <span class="font-semibold text-gray-700">Valor real</span>
          <span class="font-semibold text-gray-800 tabular-nums">{{ cop(resumen.valor_real) }}</span>
        </div>
      </div>

      <!-- Por tipo de orden -->
      <div v-if="tipos.length" class="space-y-0.5">
        <p class="text-[10px] font-semibold text-gray-500 uppercase">Por tipo (valor real)</p>
        <div v-for="t in tipos" :key="t.clave" class="flex justify-between">
          <span class="text-gray-600">{{ t.label }} · {{ t.ordenes }} {{ t.ordenes === 1 ? 'orden' : 'órdenes' }}</span>
          <span class="text-gray-700 tabular-nums">{{ cop(t.monto) }}</span>
        </div>
      </div>

      <!-- La cuenta del pool -->
      <div v-if="p" class="space-y-0.5">
        <p class="text-[10px] font-semibold text-gray-500 uppercase">
          Cuenta del pool{{ esTrimestral ? ' (va por trimestre)' : '' }}
        </p>
        <template v-if="!esTrimestral">
          <div class="flex justify-between">
            <span class="text-gray-600">Ventas que cuentan (con el 50% pagado)</span>
            <span class="text-gray-700 tabular-nums">{{ cop(p.ventas_cuentan) }}</span>
          </div>
          <div v-if="p.sin_mitad > 0" class="flex justify-between">
            <span class="text-amber-700">Aún sin el 50% · {{ p.ordenes_sin_mitad }} {{ p.ordenes_sin_mitad === 1 ? 'orden' : 'órdenes' }} (no cuentan)</span>
            <span class="text-amber-700 tabular-nums">{{ cop(p.sin_mitad) }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">− Meta</span>
            <span class="text-gray-700 tabular-nums">− {{ cop(p.meta) }}</span>
          </div>
          <div class="flex justify-between border-t border-indigo-100 pt-0.5">
            <span class="text-gray-600">{{ p.meta_cumplida ? 'Pasó la meta por' : 'Le falta para la meta' }}</span>
            <span :class="['tabular-nums font-semibold', p.meta_cumplida ? 'text-green-700' : 'text-red-600']">
              {{ cop(p.meta_cumplida ? excedente : faltaParaMeta) }}
            </span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-400">÷ 1,19 × 5%</span>
            <span class="text-gray-700 tabular-nums font-semibold">{{ cop(p.pool) }}</span>
          </div>
        </template>
        <div v-else class="flex justify-between">
          <span class="text-gray-600">Pool del trimestre</span>
          <span class="text-gray-700 tabular-nums font-semibold">{{ cop(p.pool) }}</span>
        </div>
        <div v-if="p.integrantes > 0" class="flex justify-between">
          <span class="text-gray-400">÷ {{ p.integrantes }} integrantes (por días si hubo reemplazos)</span>
          <span class="text-gray-700 tabular-nums">{{ cop(p.pool / p.integrantes) }} c/u</span>
        </div>
      </div>

      <!-- Lo que se paga en total -->
      <div class="flex justify-between border-t border-indigo-100 pt-1.5">
        <span class="font-semibold text-gray-700">Comisión de toda la tienda</span>
        <span class="font-bold text-green-700 tabular-nums">{{ cop(resumen.comision) }}</span>
      </div>
      <div v-if="resumen.comision_lista > 0" class="flex justify-between -mt-2">
        <span class="text-gray-400">Lista para pagar</span>
        <span class="text-gray-500 tabular-nums">{{ cop(resumen.comision_lista) }}</span>
      </div>
      <p class="text-[10px] text-gray-400 leading-snug">
        Suma de todos los de la tienda: pool, restauraciones y lo que abonan los independientes.
        Cada orden se cuenta una sola vez.
      </p>
    </div>
  </details>
</template>
