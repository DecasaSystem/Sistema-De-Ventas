<script setup>
/**
 * De dónde sale cada peso de lo que se le va a pagar a alguien.
 *
 * Antes era un número grande y una lista de renglones. Se lee bien cuando ya
 * sabes la fórmula, pero no responde de un vistazo la pregunta que siempre se
 * hace: "¿y por qué me dio esto?". Dos barras lo cuentan:
 *
 *  1. La comisión, partida por su origen. Los segmentos SUMAN el total: es una
 *     composición, y por eso va apilada.
 *  2. Cómo se cobró lo vendido, que es otra magnitud (pesos vendidos, no
 *     comisión) y por eso va en su propia barra y no mezclada con la primera.
 *     Es lo que explica por qué la base no cuadra con lo vendido: de lo que
 *     entra por datáfono, la franquicia se queda un 5,5% sobre el que nadie
 *     comisiona.
 *
 * Los colores salen de una paleta categórica validada para daltonismo, en
 * orden fijo (cada origen siempre el suyo, no por tamaño). Van con su cifra al
 * lado siempre: tres de estos tonos quedan por debajo de 3:1 contra el fondo
 * blanco, así que el color nunca es lo único que distingue una línea.
 *
 * Sin modo oscuro a propósito: la app es de fondo claro fijo, y meter los
 * tonos del modo oscuro sobre un fondo blanco los dejaría ilegibles.
 */
import { computed } from 'vue'

const props = defineProps({
  desglose: { type: Object, default: null },
  /** Lo que se le paga en total, ya redondeado por el servidor. */
  total:    { type: Number, default: 0 },
  /** Lo que vendió, para la barra de cómo se cobró. */
  ventas:   { type: Number, default: 0 },
})

/** Orden fijo: cada origen tiene su color, pase lo que pase. */
const ORIGENES = [
  { clave: 'pool',             label: 'Su parte del pool',     color: '#2a78d6' },
  { clave: 'parte_equipo',     label: 'Su parte del equipo',   color: '#eb6834' },
  // Sin el "(5%)": en una tienda ese 5% se parte entre los que estaban, así
  // que lo que le queda a cada uno no es el 5% del valor.
  { clave: 'restauraciones',   label: 'Restauraciones',        color: '#1baf7a' },
  { clave: 'de_independiente', label: 'De un independiente',   color: '#eda100' },
  { clave: 'individual',       label: 'Sus ventas al 5%',      color: '#e87ba4' },
]

const COLOR_TARJETA  = '#4a3aa7'
const COLOR_EFECTIVO = '#9a9a94'

const cop = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-CO')

/** Solo lo que trae plata: un renglón en cero es ruido. */
const partes = computed(() => {
  const d = props.desglose
  if (!d) return []

  const filas = ORIGENES
    .map(o => ({ ...o, monto: Number(d[o.clave]?.comision ?? 0), ordenes: d[o.clave]?.ordenes ?? 0, base: Number(d[o.clave]?.base ?? 0) }))
    .filter(f => f.monto > 0)

  const suma = filas.reduce((s, f) => s + f.monto, 0) || 1

  return filas.map(f => ({ ...f, pct: f.monto / suma * 100 }))
})

/** Cómo entró la plata de las ventas. Es otra escala: no suma la comisión. */
const cobro = computed(() => {
  const d = props.desglose
  if (!d || !(d.pagado_tarjeta > 0)) return null

  const tarjeta  = Number(d.pagado_tarjeta) || 0
  const efectivo = Number(d.sin_tarjeta) || 0
  const suma     = tarjeta + efectivo || 1

  return {
    tarjeta, efectivo,
    costo: Number(d.costo_datafono) || 0,
    pctTarjeta:  tarjeta / suma * 100,
    pctEfectivo: efectivo / suma * 100,
    // Con un solo modo de pago la barra sería un bloque entero: no compara
    // nada y las dos cifras lo dicen mejor.
    vaLaBarra: tarjeta > 0 && efectivo > 0,
  }
})
</script>

<template>
  <div v-if="partes.length" class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2.5">
    <!-- ── De dónde sale la comisión ───────────────────────────────────── -->
    <div class="flex items-baseline justify-between mb-1.5">
      <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">De dónde sale</p>
      <p class="text-xs font-bold text-gray-700">{{ cop(total) }}</p>
    </div>

    <!-- Barra apilada: los segmentos suman el total. El hueco de 2px es del
         color del fondo, no un borde: separa sin ensuciar el color.
         Con una sola fuente no se pinta: un bloque de un color entero no
         compara nada, y el renglón de abajo ya lo dice. -->
    <div v-if="partes.length > 1" class="flex h-2.5 rounded overflow-hidden" style="gap: 2px">
      <div
        v-for="p in partes"
        :key="p.clave"
        :style="{ flex: `${p.pct} 0 0`, background: p.color, minWidth: '3px' }"
        :title="`${p.label}: ${cop(p.monto)}`"
      />
    </div>

    <!-- La cifra al lado del color: el color solo nunca alcanza. -->
    <div class="mt-1.5 space-y-0.5">
      <div v-for="p in partes" :key="p.clave" class="flex items-center gap-1.5 text-[11px]">
        <span class="w-2 h-2 rounded-sm shrink-0" :style="{ background: p.color }" />
        <span class="text-gray-600 flex-1 min-w-0 truncate">
          {{ p.label }}
          <span v-if="p.base > 0" class="text-gray-400">
            · {{ p.ordenes }} {{ p.ordenes === 1 ? 'orden' : 'órdenes' }} sobre {{ cop(p.base) }}
          </span>
        </span>
        <!-- El porcentaje solo cuando hay con qué compararlo. -->
        <span v-if="partes.length > 1" class="text-gray-400 tabular-nums">{{ Math.round(p.pct) }}%</span>
        <span class="font-semibold text-gray-700 tabular-nums shrink-0 w-20 text-right">{{ cop(p.monto) }}</span>
      </div>
    </div>

    <!-- ── Cómo se cobró lo vendido ────────────────────────────────────── -->
    <div v-if="cobro" class="mt-2.5 pt-2.5 border-t border-gray-200">
      <div class="flex items-baseline justify-between mb-1.5">
        <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Cómo se cobró</p>
        <p class="text-[11px] text-gray-400">de {{ cop(ventas) }} vendidos</p>
      </div>

      <div v-if="cobro.vaLaBarra" class="flex h-2.5 rounded overflow-hidden" style="gap: 2px">
        <div :style="{ flex: `${cobro.pctEfectivo} 0 0`, background: COLOR_EFECTIVO, minWidth: '3px' }" title="Efectivo o transferencia" />
        <div :style="{ flex: `${cobro.pctTarjeta} 0 0`, background: COLOR_TARJETA, minWidth: '3px' }" title="Tarjeta" />
      </div>

      <div class="mt-1.5 space-y-0.5 text-[11px]">
        <div class="flex items-center gap-1.5">
          <span class="w-2 h-2 rounded-sm shrink-0" :style="{ background: COLOR_EFECTIVO }" />
          <span class="text-gray-600 flex-1">Efectivo o transferencia</span>
          <span class="text-gray-700 tabular-nums">{{ cop(cobro.efectivo) }}</span>
        </div>
        <div class="flex items-center gap-1.5">
          <span class="w-2 h-2 rounded-sm shrink-0" :style="{ background: COLOR_TARJETA }" />
          <span class="text-gray-600 flex-1">Con tarjeta</span>
          <span class="text-gray-700 tabular-nums">{{ cop(cobro.tarjeta) }}</span>
        </div>
        <div class="flex items-center gap-1.5 pt-0.5">
          <span class="w-2 h-2 shrink-0" />
          <span class="text-gray-400 flex-1">Se lo llevó el datáfono (5,5%)</span>
          <span class="text-gray-400 tabular-nums">− {{ cop(cobro.costo) }}</span>
        </div>
      </div>

      <p class="text-[10px] text-gray-400 leading-snug mt-1">
        Sobre lo que se lleva la franquicia no comisiona nadie: por eso la base
        de la comisión no es igual a lo vendido.
      </p>
    </div>
  </div>
</template>
