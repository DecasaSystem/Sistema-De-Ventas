<script setup>
/**
 * Qué tan acertado es el cotizador con IA: compara su estimado con el costo que
 * fijó el ebanista al responder cada consulta de costo.
 */
import { ref, onMounted } from 'vue'
import { getPrecisionCotizador } from '@/api/configuracion'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { formatPct } from '@/utils/descuentos'
import { useCostos } from './useCostos'

const { formatPeso } = useCostos()

const datos    = ref(null)
const cargando = ref(true)
const error    = ref(false)

onMounted(async () => {
  try {
    const { data } = await getPrecisionCotizador()
    datos.value = data
  } catch {
    error.value = true
  } finally {
    cargando.value = false
  }
})

// Error: cerca de 0 es bueno. Sesgo negativo = la IA se queda corta (riesgo de vender a pérdida).
const colorError = v => v <= 10 ? 'text-green-600' : v <= 25 ? 'text-amber-600' : 'text-red-600'
const colorSesgo = v => Math.abs(v) <= 8 ? 'text-green-600' : v < 0 ? 'text-red-600' : 'text-amber-600'
const signo = v => (v > 0 ? '+' : '') + formatPct(v) + '%'
</script>

<template>
  <AppSpinner v-if="cargando" />

  <p v-else-if="error" class="text-center py-12 text-sm text-gray-400">No se pudo cargar la precisión del cotizador.</p>

  <div v-else class="space-y-4">
    <p class="text-xs text-gray-500 px-1">
      Compara lo que estimó la IA con el costo que fijó el ebanista al responder cada consulta de costo.
      Sirve para saber cuánto confiar en el cotizador y si está mejorando.
    </p>

    <div v-if="!datos.hay_datos" class="bg-white rounded-xl shadow-sm p-6 text-center text-sm text-gray-500">
      {{ datos.mensaje }}
    </div>

    <template v-else>
      <div class="grid grid-cols-3 gap-2">
        <div class="bg-white rounded-xl shadow-sm p-3">
          <span class="block text-2xl font-bold text-gray-800 tabular-nums">{{ datos.total_casos }}</span>
          <span class="block text-xs text-gray-500">casos revisados</span>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3">
          <span class="block text-2xl font-bold tabular-nums" :class="colorError(datos.global.error_medio_abs)">{{ formatPct(datos.global.error_medio_abs) }}%</span>
          <span class="block text-xs text-gray-500">de error promedio</span>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3">
          <span class="block text-2xl font-bold text-gray-800 tabular-nums">{{ datos.global.dentro_10pct_ratio }}%</span>
          <span class="block text-xs text-gray-500">acertó ±10%</span>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-sm font-semibold" :class="colorSesgo(datos.global.sesgo_medio)">
          {{ datos.global.sesgo_medio > 0 ? 'Tiende a pasarse' : datos.global.sesgo_medio < 0 ? 'Tiende a quedarse corta' : 'Sin tendencia' }}
          ({{ signo(datos.global.sesgo_medio) }} en promedio)
        </p>
        <p class="text-xs mt-0.5" :class="datos.global.sesgo_medio < -8 ? 'text-red-600' : 'text-gray-500'">
          {{ datos.global.sesgo_medio < -8
            ? 'Estima por debajo del costo real: revisa sus cotizaciones antes de vender.'
            : 'Mientras más cerca de 0 %, más confiable.' }}
        </p>
      </div>

      <section>
        <h2 class="text-sm font-semibold text-gray-500 mb-1.5 px-1">Por categoría</h2>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
          <div class="grid grid-cols-[1fr_auto_auto_auto] gap-x-4 px-4 py-2 text-xs font-medium text-gray-400 border-b border-gray-100">
            <span>Categoría</span><span class="text-right">Casos</span><span class="text-right">Error</span><span class="text-right">Tendencia</span>
          </div>
          <div v-for="c in datos.por_categoria" :key="c.categoria"
            class="grid grid-cols-[1fr_auto_auto_auto] gap-x-4 px-4 py-2 text-sm border-b border-gray-100 last:border-0 tabular-nums">
            <span class="text-gray-700 truncate capitalize">{{ c.categoria.toLowerCase() }}</span>
            <span class="text-right text-gray-500">{{ c.n }}</span>
            <span class="text-right font-medium" :class="colorError(c.error_medio_abs)">{{ formatPct(c.error_medio_abs) }}%</span>
            <span class="text-right font-medium" :class="colorSesgo(c.sesgo_medio)">{{ signo(c.sesgo_medio) }}</span>
          </div>
        </div>
      </section>

      <section>
        <h2 class="text-sm font-semibold text-gray-500 mb-1.5 px-1">Últimas correcciones</h2>
        <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
          <div v-for="(r, i) in datos.recientes" :key="i" class="flex items-start justify-between gap-3 px-4 py-3">
            <div class="min-w-0">
              <p class="text-sm text-gray-800 truncate">{{ r.mueble }}</p>
              <p class="text-xs text-gray-500 tabular-nums">IA {{ formatPeso(r.precio_ia) }}, real {{ formatPeso(r.precio_real) }}</p>
            </div>
            <span class="text-sm font-semibold flex-shrink-0 tabular-nums" :class="colorError(Math.abs(r.error_pct))">{{ signo(r.error_pct) }}</span>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
