<script setup>
/**
 * De qué tipo de orden viene la plata: venta, restauración o FV2.
 *
 * Los tres pedazos suman el total, así que la barra es una composición y por
 * eso va apilada. Sirve igual para el resumen, para una tienda y para un
 * vendedor, porque los tres parten la plata con la misma regla (ver
 * Orden::sqlTipo en el backend).
 *
 * Colores de una paleta validada para daltonismo y contraste, en orden fijo:
 * el tipo manda sobre el tamaño, para que "azul" signifique siempre lo mismo
 * aunque cambie el mes. Y cada uno lleva su cifra al lado: el color solo
 * nunca alcanza.
 */
import { computed } from 'vue'

const props = defineProps({
  /**
   * { venta, restauracion, fv2 } — cada uno un número, o { monto, ordenes }
   * cuando además se sabe cuántas órdenes son.
   */
  datos:   { type: Object, default: null },
  /** Para titular el bloque: "lo vendido" o "lo cobrado". */
  titulo:  { type: String, default: 'De qué es' },
  compacto: { type: Boolean, default: false },
  /**
   * Solo la barra, sin títulos ni cifras: para una celda de tabla, donde la
   * fila ya trae el total y no cabe una leyenda por vendedor. La leyenda va
   * una sola vez encima de la tabla, y cada barra lleva su detalle en el
   * tooltip.
   */
  soloBarra: { type: Boolean, default: false },
})

const TIPOS = [
  { clave: 'venta',        label: 'Ventas',         color: '#2a78d6' },
  { clave: 'restauracion', label: 'Restauraciones', color: '#eb6834' },
  { clave: 'fv2',          label: 'FV2 (descuento)', color: '#1baf7a' },
]

const cop = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-CO')

const partes = computed(() => {
  const d = props.datos
  if (!d) return []

  const filas = TIPOS.map(t => {
    const v = d[t.clave]
    const monto = typeof v === 'object' && v !== null ? Number(v.monto ?? 0) : Number(v ?? 0)
    const ordenes = typeof v === 'object' && v !== null ? Number(v.ordenes ?? 0) : null
    return { ...t, monto, ordenes }
  }).filter(f => f.monto > 0)

  const suma = filas.reduce((s, f) => s + f.monto, 0) || 1

  return filas.map(f => ({ ...f, pct: f.monto / suma * 100 }))
})

const total = computed(() => partes.value.reduce((s, f) => s + f.monto, 0))
</script>

<template>
  <!-- Celda de tabla: la barra sola, con el detalle en el tooltip. -->
  <div
    v-if="soloBarra && partes.length > 1"
    class="flex h-1.5 rounded overflow-hidden mt-1"
    style="gap: 2px"
    :title="partes.map(p => `${p.label}: ${cop(p.monto)}`).join(' · ')"
  >
    <div
      v-for="p in partes"
      :key="p.clave"
      :style="{ flex: `${p.pct} 0 0`, background: p.color, minWidth: '3px' }"
    />
  </div>

  <div v-else-if="!soloBarra && partes.length" :class="compacto ? '' : 'rounded-xl bg-gray-50 border border-gray-100 px-3 py-2.5'">
    <div v-if="!compacto" class="flex items-baseline justify-between mb-1.5">
      <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">{{ titulo }}</p>
      <p class="text-xs font-bold text-gray-700">{{ cop(total) }}</p>
    </div>

    <!-- Con un solo tipo no se pinta: un bloque de un color no compara nada. -->
    <div v-if="partes.length > 1" class="flex h-2 rounded overflow-hidden" style="gap: 2px">
      <div
        v-for="p in partes"
        :key="p.clave"
        :style="{ flex: `${p.pct} 0 0`, background: p.color, minWidth: '3px' }"
        :title="`${p.label}: ${cop(p.monto)}`"
      />
    </div>

    <div class="mt-1.5 space-y-0.5">
      <div v-for="p in partes" :key="p.clave" class="flex items-center gap-1.5 text-[11px]">
        <span class="w-2 h-2 rounded-sm shrink-0" :style="{ background: p.color }" />
        <span class="text-gray-600 flex-1 min-w-0 truncate">
          {{ p.label }}
          <span v-if="p.ordenes" class="text-gray-400">
            · {{ p.ordenes }} {{ p.ordenes === 1 ? 'orden' : 'órdenes' }}
          </span>
        </span>
        <span v-if="partes.length > 1" class="text-gray-400 tabular-nums">{{ Math.round(p.pct) }}%</span>
        <span class="font-semibold text-gray-700 tabular-nums shrink-0">{{ cop(p.monto) }}</span>
      </div>
    </div>
  </div>
</template>
