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
import { computed, ref } from 'vue'

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

// ── Trimestre (Pereira y Circunvalar) ───────────────────────────────────────
const tri = computed(() => p.value?.trimestre ?? null)
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const nombreMes = (ym) => {
  const [, m] = String(ym).split('-')
  const n = MESES[Number(m) - 1] ?? ym
  return n.charAt(0).toUpperCase() + n.slice(1)
}
const nombreTrimestre = (t) => {
  const [anio, q] = String(t).split('-Q')
  const inicio = (Number(q) - 1) * 3
  return `Trimestre ${nombreMes(`${anio}-${inicio + 1}`)}–${MESES[inicio + 2]} ${anio}`
}

// ── Las órdenes una por una ─────────────────────────────────────────────────
// Para cuadrar contra el módulo de Órdenes: el número por tipo no dice cuáles
// son, y la que entra por un camino raro solo se encuentra viéndola.
const verOrdenes = ref(false)
const filtroTipo = ref('')   // '' todas · venta · restauracion · fv2

const POR_QUE = {
  venta_tienda:     null,
  otra_tienda:      { text: 'orden de otra tienda', cls: 'bg-sky-100 text-sky-700' },
  de_independiente: { text: 'de un independiente',  cls: 'bg-amber-100 text-amber-700' },
  restauracion:     { text: 'restauración repartida', cls: 'bg-orange-100 text-orange-700' },
}

const ordenesFiltradas = computed(() =>
  (props.resumen?.detalle ?? []).filter(o => !filtroTipo.value || o.tipo === filtroTipo.value)
)
const totalFiltrado = computed(() => ordenesFiltradas.value.reduce((s, o) => s + o.valor_real, 0))
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
          <span class="text-gray-400">− Datáfono / Addi (5,5% de {{ cop(resumen.tarjeta) }})</span>
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
        <!-- Trimestral: la cuenta entera, mes a mes -->
        <template v-else-if="tri">
          <p class="text-[10px] text-gray-500">
            {{ nombreTrimestre(tri.trimestre) }}
            <span v-if="tri.cerrado" class="ml-1 px-1.5 rounded bg-gray-200 text-gray-700 font-semibold">congelado: ya se empezó a pagar</span>
            <span v-else-if="tri.meses_restantes" class="ml-1 px-1.5 rounded bg-blue-100 text-blue-700 font-semibold">en curso · faltan {{ tri.meses_restantes }} {{ tri.meses_restantes === 1 ? 'mes' : 'meses' }}</span>
          </p>

          <!-- Mes a mes -->
          <div class="bg-white rounded-lg border border-indigo-100 overflow-hidden">
            <div class="grid grid-cols-4 gap-1 px-2 py-1 bg-indigo-50 text-[10px] font-semibold text-gray-500">
              <span>Mes</span><span class="text-right">Cuenta</span><span class="text-right">Meta</span><span class="text-right">Diferencia</span>
            </div>
            <div v-for="m in tri.meses" :key="m.mes" class="px-2 py-1 border-t border-indigo-50">
              <div class="grid grid-cols-4 gap-1 tabular-nums">
                <span class="text-gray-700">{{ nombreMes(m.mes) }}</span>
                <span class="text-right text-gray-700">{{ m.futuro ? '—' : cop(m.cuenta) }}</span>
                <span class="text-right text-gray-500">{{ cop(m.meta) }}</span>
                <span :class="['text-right font-semibold', m.diferencia >= 0 ? 'text-green-700' : 'text-red-600']">
                  {{ m.diferencia >= 0 ? '+' : '−' }}{{ cop(Math.abs(m.diferencia)) }}
                </span>
              </div>
              <p v-if="m.futuro" class="text-[10px] text-gray-400">No ha llegado: cuenta como cero contra su meta.</p>
              <p v-else-if="m.sin_mitad > 0" class="text-[10px] text-amber-700">+ {{ cop(m.sin_mitad) }} vendido sin el 50% pagado (todavía no cuenta)</p>
            </div>
          </div>

          <!-- La cuenta del pool -->
          <div class="space-y-0.5">
            <div class="flex justify-between">
              <span class="text-gray-600">Diferencia del trimestre (suma de los 3 meses)</span>
              <span :class="['tabular-nums font-semibold', tri.diferencial >= 0 ? 'text-green-700' : 'text-red-600']">
                {{ tri.diferencial >= 0 ? '+' : '−' }}{{ cop(Math.abs(tri.diferencial)) }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">÷ 1,19 × 5%</span>
              <span :class="['tabular-nums', tri.pool_bruto >= 0 ? 'text-gray-700' : 'text-red-600']">
                {{ tri.pool_bruto >= 0 ? '' : '−' }}{{ cop(Math.abs(tri.pool_bruto)) }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">− Deuda que arrastra del trimestre anterior</span>
              <span :class="['tabular-nums', tri.deficit_inicial > 0 ? 'text-red-600' : 'text-gray-400']">
                − {{ cop(tri.deficit_inicial) }}
              </span>
            </div>
            <p v-if="tri.deficit_inicial > 0" class="text-[10px] text-gray-400 -mt-0.5">
              Equivale a {{ cop(tri.deuda_en_ventas) }} en ventas por encima de la meta.
            </p>
            <div class="flex justify-between border-t border-indigo-100 pt-0.5">
              <span class="font-semibold text-gray-700">Pool que se paga</span>
              <span class="font-semibold text-gray-800 tabular-nums">{{ cop(tri.pool_pagado) }}</span>
            </div>
            <div v-if="tri.deficit_final > 0" class="flex justify-between">
              <span class="text-red-700">Deuda que le pasa al siguiente trimestre</span>
              <span class="text-red-700 tabular-nums font-semibold">{{ cop(tri.deficit_final) }}</span>
            </div>
          </div>

          <!-- Lo que falta -->
          <p v-if="!tri.cerrado && tri.meses_restantes && tri.falta_para_cobrar > 0"
             class="text-[11px] text-blue-800 bg-blue-50 border border-blue-100 rounded-lg px-2 py-1.5">
            Para que el trimestre dé comisión, en lo que queda hay que vender (con el 50% pagado)
            <strong>{{ cop(tri.falta_para_cobrar) }}</strong>: las metas que faltan,
            lo que va por debajo<template v-if="tri.deficit_inicial > 0"> y la deuda que arrastra</template>.
          </p>
          <p v-else-if="!tri.cerrado && tri.meses_restantes && tri.pool_pagado > 0"
             class="text-[11px] text-green-800 bg-green-50 border border-green-100 rounded-lg px-2 py-1.5">
            Va en positivo: lo que se venda de aquí en adelante agranda el pool.
          </p>
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

      <!-- Las órdenes una por una, filtrables por tipo -->
      <div v-if="resumen.detalle?.length">
        <button
          type="button"
          @click="verOrdenes = !verOrdenes"
          class="w-full text-left text-[11px] font-semibold text-indigo-700 hover:underline"
        >
          {{ verOrdenes ? '▴ Ocultar las órdenes' : `▾ Ver las ${resumen.detalle.length} órdenes` }}
        </button>

        <div v-if="verOrdenes" class="mt-1.5 space-y-1.5">
          <div class="flex flex-wrap gap-1">
            <button
              v-for="f in [{ clave: '', label: 'Todas' }, ...TIPOS]"
              :key="f.clave"
              type="button"
              @click="filtroTipo = f.clave"
              :class="['px-2 py-0.5 rounded-full border text-[10px] font-semibold',
                filtroTipo === f.clave ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-indigo-700 border-indigo-200']"
            >{{ f.label }}</button>
          </div>

          <p class="text-[10px] text-gray-500">
            {{ ordenesFiltradas.length }} {{ ordenesFiltradas.length === 1 ? 'orden' : 'órdenes' }} ·
            valor real {{ cop(totalFiltrado) }}
          </p>

          <div class="bg-white rounded-lg border border-indigo-100 divide-y divide-indigo-50">
            <RouterLink
              v-for="o in ordenesFiltradas"
              :key="o.orden_id"
              :to="{ name: 'orden-detalle', params: { id: o.orden_id } }"
              class="block px-2 py-1.5 hover:bg-indigo-50/60"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="font-semibold text-gray-800">{{ o.referencia ?? ('#' + o.orden_id) }}</span>
                <span class="tabular-nums text-gray-700">{{ cop(o.valor_real) }}</span>
              </div>
              <div class="flex items-center justify-between gap-2 text-[10px] text-gray-500">
                <span class="truncate">{{ o.cliente ?? 'Sin cliente' }} · {{ o.vendedores.join(', ') }}</span>
                <span
                  :class="['shrink-0 font-semibold', o.cuenta ? 'text-green-700' : 'text-amber-700']"
                  :title="o.cuenta ? 'Ya tiene el 50% pagado' : 'Todavía no tiene el 50% pagado'"
                >{{ o.pct_pagado }}% pagado{{ o.cuenta ? '' : ' · no cuenta' }}</span>
              </div>
              <div v-if="POR_QUE[o.por_que] || o.valor_real !== o.valor_orden" class="flex flex-wrap gap-1 mt-0.5">
                <span v-if="POR_QUE[o.por_que]" :class="['px-1.5 rounded text-[10px]', POR_QUE[o.por_que].cls]">
                  {{ POR_QUE[o.por_que].text }}
                </span>
                <span v-if="o.valor_real !== o.valor_orden" class="px-1.5 rounded text-[10px] bg-gray-100 text-gray-600">
                  orden de {{ cop(o.valor_orden) }}
                </span>
              </div>
            </RouterLink>
          </div>
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
