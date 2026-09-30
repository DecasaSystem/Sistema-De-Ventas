<script setup>
/**
 * La cartera: las órdenes que todavía deben, con su Excel.
 *
 * La usan Reportes (supervisor, con el filtro de tienda) y Mis estadísticas
 * (vendedor, la de su tienda). Qué órdenes entran y quién ve cuáles lo decide
 * el servidor (App\Services\Cartera), y el Excel sale de la misma regla: lo
 * que se descarga es lo que se ve.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  ordenes:  { type: Array, default: () => [] },
  // La tienda elegida en el filtro (supervisor). Al vendedor el servidor le
  // da siempre la suya, venga lo que venga.
  tiendaId: { type: [String, Number], default: '' },
  cargando: { type: Boolean, default: false },
})

const router = useRouter()
const toast  = useToast()

const totalSaldo = computed(() => props.ordenes.reduce((s, o) => s + Number(o.saldo_pendiente || 0), 0))

function cop(n) {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n ?? 0)
}
// Los mismos cortes que ya tenía la cartera de Reportes.
function diasColor(d) {
  if (d > 15) return 'bg-red-100 text-red-700'
  if (d > 7)  return 'bg-orange-100 text-orange-700'
  return 'bg-yellow-100 text-yellow-700'
}

const descargando = ref(false)
async function descargarExcel() {
  if (descargando.value) return
  descargando.value = true
  try {
    const params = { tipo: 'pendientes' }
    if (props.tiendaId) params.tienda_id = props.tiendaId
    const res = await api.get('/reportes/exportar', { params, responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const a   = document.createElement('a')
    a.href     = url
    a.download = `cartera_por_cobrar_${new Date().toISOString().slice(0, 10)}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.URL.revokeObjectURL(url)
  } catch {
    // Antes el error solo iba a la consola: el botón parecía no hacer nada.
    toast.error('No se pudo descargar el Excel. Intenta de nuevo.')
  } finally {
    descargando.value = false
  }
}
</script>

<template>
  <div class="space-y-3">
    <!-- Resumen + Excel -->
    <div class="bg-white rounded-xl shadow-sm p-4 flex items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs text-gray-500">
          {{ ordenes.length }} orden{{ ordenes.length !== 1 ? 'es' : '' }} con saldo pendiente
        </p>
        <p class="text-xl font-bold text-red-500 leading-tight">{{ cop(totalSaldo) }}</p>
      </div>
      <button
        type="button"
        @click="descargarExcel"
        :disabled="descargando || !ordenes.length"
        class="flex items-center gap-1.5 rounded-lg bg-green-600 text-white text-sm font-semibold px-3.5 py-2.5 hover:bg-green-700 disabled:opacity-50 flex-shrink-0"
      >
        <ArrowDownTrayIcon class="w-4 h-4" />
        {{ descargando ? 'Descargando…' : 'Excel' }}
      </button>
    </div>

    <p class="text-xs text-gray-400 bg-gray-50 rounded-lg px-3 py-2">
      Es lo que deben hoy, entregado o no: no depende del período.
    </p>

    <p v-if="cargando" class="text-center py-8 text-gray-400 text-sm">Cargando…</p>

    <ul v-else class="space-y-2">
      <li v-for="o in ordenes" :key="o.orden_id"
        @click="router.push({ name: 'orden-detalle', params: { id: o.orden_id } })"
        class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start gap-2 mb-2">
          <div class="min-w-0">
            <p class="font-medium text-sm text-gray-800 truncate">
              <span class="text-gray-400 font-semibold">{{ o.referencia }}</span> · {{ o.cliente }}
            </p>
            <p class="text-xs text-gray-400 truncate">{{ o.vendedor }} · {{ o.tienda }}</p>
          </div>
          <div class="flex flex-col items-end gap-1 flex-shrink-0">
            <BadgeEstado :estado="o.estado" />
            <span v-if="o.dias_sin_pagar != null"
              :class="['text-xs font-semibold px-2 py-0.5 rounded-full', diasColor(o.dias_sin_pagar)]">
              {{ o.dias_sin_pagar }}d
            </span>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-2 text-xs text-center">
          <div>
            <p class="text-gray-400">Total</p>
            <p class="font-semibold text-gray-700">{{ cop(o.valor_total) }}</p>
          </div>
          <div>
            <p class="text-gray-400">Pagado</p>
            <p class="font-semibold text-green-600">{{ cop(o.total_pagado) }}</p>
          </div>
          <div>
            <p class="text-gray-400">Saldo</p>
            <p class="font-bold text-red-500">{{ cop(o.saldo_pendiente) }}</p>
          </div>
        </div>
      </li>
    </ul>
    <p v-if="!cargando && !ordenes.length" class="text-center py-8 text-gray-400 text-sm">No hay cartera pendiente.</p>
  </div>
</template>
