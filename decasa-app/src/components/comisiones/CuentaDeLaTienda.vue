<script setup>
/**
 * La cuenta de una tienda en Listas: de dónde sale su pool.
 *
 * Trimestral: los tres meses con su meta, lo que cuenta y el saldo, más la
 * deuda que traía. Mensual: meta, lo que cuenta y cómo se reparte entre el
 * equipo (por días), con la persona de la tarjeta resaltada. Sale de
 * por-pagar?detalle=1 (respuesta.tiendas / tiendas_sin_pago): no calcula nada.
 */
import { computed } from 'vue'

const props = defineProps({
  tienda:     { type: Object, required: true },
  vendedorId: { type: Number, default: null },
})

const pool = computed(() => props.tienda.pool ?? {})
const q = computed(() => pool.value.periodicidad === 'trimestral' ? pool.value.trimestre : null)

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
const mesCorto = m => `${MESES[Number(String(m).slice(5, 7)) - 1]} ${String(m).slice(0, 4)}`
/** '2026-08-27' → '27/08/2026', como en el resto de Listas. */
const dia = f => { const [y, m, d] = String(f ?? '').slice(0, 10).split('-'); return d ? `${d}/${m}/${y}` : (f ?? '') }
const cop = v => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(v) || 0)
</script>

<template>
  <div class="rounded-lg border border-gray-100 overflow-hidden">
    <!-- Trimestral: los tres meses juntos -->
    <template v-if="q">
      <p class="text-[10px] font-semibold text-gray-500 px-2.5 pt-2">Trimestre {{ q.trimestre }} de {{ tienda.tienda_nombre }}</p>
      <table class="w-full text-[11px] mt-1">
        <thead>
          <tr class="text-gray-400 text-left">
            <th class="font-medium px-2.5 py-1">Mes</th>
            <th class="font-medium px-1 py-1 text-right">Meta</th>
            <th class="font-medium px-1 py-1 text-right">Cuenta</th>
            <th class="font-medium px-2.5 py-1 text-right">Saldo</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in q.meses" :key="m.mes" class="border-t border-gray-100">
            <td class="px-2.5 py-1 capitalize text-gray-700">{{ mesCorto(m.mes) }}</td>
            <td class="px-1 py-1 text-right text-gray-600">{{ cop(m.meta) }}</td>
            <td class="px-1 py-1 text-right text-gray-800">{{ cop(m.cuenta) }}</td>
            <td :class="['px-2.5 py-1 text-right font-semibold', Number(m.saldo) >= 0 ? 'text-green-700' : 'text-red-600']">{{ cop(m.saldo) }}</td>
          </tr>
        </tbody>
      </table>
      <div class="px-2.5 py-1.5 border-t border-gray-100 text-[10px] text-gray-500 space-y-0.5">
        <p>"Cuenta" es lo vendido con la mitad pagada; el saldo va sumando (cuenta − meta) mes a mes.</p>
        <p v-if="Number(q.deficit_inicial) > 0">Deuda que traía del trimestre anterior: {{ cop(q.deuda_en_ventas) }} en ventas.</p>
        <p class="font-semibold text-gray-700">Pool del trimestre: {{ cop(q.pool_pagado) }}</p>
      </div>
    </template>

    <!-- Mensual: la meta y el reparto por días -->
    <template v-else>
      <div class="grid grid-cols-3 gap-1 px-2.5 py-2 text-center">
        <div>
          <p class="text-[10px] text-gray-400">Meta</p>
          <p class="text-[11px] font-semibold text-gray-700">{{ cop(pool.meta) }}</p>
        </div>
        <div>
          <p class="text-[10px] text-gray-400">Cuenta</p>
          <p class="text-[11px] font-semibold text-gray-800">{{ cop(pool.ventas_cuentan) }}</p>
        </div>
        <div>
          <p class="text-[10px] text-gray-400">Pool</p>
          <p :class="['text-[11px] font-semibold', pool.meta_cumplida ? 'text-green-700' : 'text-red-600']">{{ cop(pool.pool) }}</p>
        </div>
      </div>
      <div v-if="pool.reparto?.length" class="border-t border-gray-100 px-2.5 py-1.5">
        <p class="text-[10px] text-gray-400 mb-0.5">
          {{ pool.reparto_modo === 'iguales' ? 'El pool se reparte en partes iguales' : `El pool se reparte por días (${pool.dias_total} días de todo el equipo)` }}
          · <span class="text-gray-500">solo el pool: lo que se paga es el total del mes</span>
        </p>
        <div
          v-for="x in pool.reparto"
          :key="x.vendedor_id"
          :class="['flex justify-between text-[11px] py-0.5', x.vendedor_id === vendedorId ? 'font-semibold text-gray-800' : 'text-gray-600']"
        >
          <span class="truncate">{{ x.nombre }} · {{ x.dias }} días</span>
          <span class="shrink-0 ml-2">{{ cop(x.parte) }}</span>
        </div>
        <p v-for="(x, i) in pool.reemplazos ?? []" :key="i" class="text-[10px] text-gray-500 mt-0.5">
          {{ x.reemplaza ? `${x.quien} cubrió a ${x.reemplaza} del ${dia(x.desde)}${x.hasta ? ' al ' + dia(x.hasta) : ''}` : `${x.quien} llegó el ${dia(x.desde)} (${x.tipo === 'traslado' ? 'traslado' : 'reemplazo'})` }}
        </p>
      </div>
    </template>
  </div>
</template>
