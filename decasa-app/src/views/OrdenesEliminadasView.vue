<script setup>
/**
 * Historial de las órdenes que un supervisor eliminó.
 *
 * Una venta borrada desaparece de órdenes, reportes y comisiones; aquí queda
 * cómo estaba —productos, pagos, comisiones—, quién la borró, cuándo, por qué
 * y qué pasó con su número. El servidor guarda la foto completa al borrarla.
 */
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { getOrdenesEliminadas } from '@/api/ordenes'
import { useToast } from '@/composables/useToast'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()
const toast  = useToast()

const lista     = ref([])
const cargando  = ref(true)
const pagina    = ref(1)
const hayMas    = ref(false)
const busqueda  = ref('')
const abierta   = ref(null)   // id de la que tiene el detalle abierto

const cop = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-CO')
const fecha = (s) => s
  ? new Date(String(s).replace(' ', 'T') + (String(s).includes('Z') || String(s).includes('+') ? '' : 'Z'))
      .toLocaleString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })
  : ''

const ESTADO = {
  pendiente_anticipo: 'En espera', en_produccion: 'En producción', listo_entrega: 'Lista para entrega',
  en_camino: 'En camino', entregado: 'Entregada', cancelado: 'Cancelada', pendiente_cotizacion: 'Pendiente costo',
}

async function cargar(p = 1) {
  cargando.value = true
  try {
    const { data } = await getOrdenesEliminadas({ page: p, search: busqueda.value.trim() || undefined })
    lista.value  = p === 1 ? data.data : [...lista.value, ...data.data]
    pagina.value = data.current_page
    hayMas.value = data.current_page < data.last_page
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo cargar el historial.')
  } finally {
    cargando.value = false
  }
}

let timer = null
watch(busqueda, () => {
  clearTimeout(timer)
  timer = setTimeout(() => cargar(1), 350)
})

onMounted(() => cargar(1))

function nombreItem(i) {
  return i.producto?.nombre || i.nombre_custom || 'Producto'
}
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <div class="flex items-center gap-2">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">Órdenes eliminadas</h2>
    </div>

    <p class="text-[11px] text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-1.5">
      Lo que se borró sigue aquí como estaba: productos, pagos y comisiones, con quién la eliminó y por qué.
    </p>

    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        placeholder="Buscar por número, cliente o motivo..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <div v-if="cargando && !lista.length" class="text-center text-sm text-gray-400 py-8">Cargando…</div>

    <EmptyState v-else-if="!lista.length" message="No hay órdenes eliminadas. Cuando un supervisor elimine una, quedará aquí." />

    <div v-for="e in lista" :key="e.id" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <button type="button" class="w-full text-left p-4" @click="abierta = abierta === e.id ? null : e.id">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="font-semibold text-gray-800 text-sm">
              {{ e.referencia || ('Orden interna #' + e.orden_id) }}
              <span class="ml-1 text-[10px] font-semibold px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">
                {{ ESTADO[e.estado] ?? e.estado }}
              </span>
            </p>
            <p class="text-xs text-gray-500 truncate">
              {{ e.cliente_nombre || 'Sin cliente' }} · {{ e.vendedor || 'Sin vendedor' }}<template v-if="e.tienda"> · {{ e.tienda }}</template>
            </p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-bold text-gray-800">{{ cop(e.valor_total) }}</p>
            <p class="text-[10px] text-gray-400">pagado {{ cop(e.pagado) }}</p>
          </div>
        </div>

        <div class="mt-2 text-[11px] text-red-700 bg-red-50 border border-red-100 rounded-lg px-2.5 py-1.5">
          <span class="font-semibold">Eliminada por {{ e.eliminada_por || '—' }}</span> · {{ fecha(e.created_at) }}
          <p class="text-gray-700 mt-0.5">“{{ e.motivo }}”</p>
        </div>
        <p class="mt-1.5 text-[11px] text-gray-500">
          <template v-if="e.numeracion === 'correr'">
            Número: se corrieron las siguientes<template v-if="e.corridas.length"> ({{ e.corridas.length }} bajaron uno)</template>.
          </template>
          <template v-else>Número: quedó el hueco.</template>
          <template v-if="e.datos?.devolvio_entregado === false"> · Lo entregado no volvió al inventario.</template>
          <span class="text-indigo-600 font-semibold ml-1">{{ abierta === e.id ? '▴ Ocultar' : '▾ Ver cómo estaba' }}</span>
        </p>
      </button>

      <!-- Cómo estaba -->
      <div v-if="abierta === e.id" class="border-t border-gray-100 px-4 py-3 space-y-3 text-xs">
        <div v-if="e.datos?.items?.length">
          <p class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Productos</p>
          <div v-for="i in e.datos.items" :key="i.id" class="flex justify-between gap-2 py-0.5">
            <span class="text-gray-700 truncate">
              {{ i.cantidad }} × {{ nombreItem(i) }}
              <span v-if="i.cantidad_entregada" class="text-gray-400">· {{ i.cantidad_entregada }} entregada(s)</span>
            </span>
            <span class="text-gray-600 tabular-nums shrink-0">{{ cop(i.precio_unitario * i.cantidad) }}</span>
          </div>
        </div>

        <div v-if="e.datos?.pagos?.length">
          <p class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Pagos</p>
          <div v-for="p in e.datos.pagos" :key="p.id" class="flex justify-between gap-2 py-0.5">
            <span class="text-gray-700">{{ p.metodo || '—' }} · {{ fecha(p.created_at) }}</span>
            <span class="text-gray-600 tabular-nums">{{ cop(p.monto) }}</span>
          </div>
        </div>

        <div v-if="e.datos?.comisiones?.length">
          <p class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Comisiones que tenía</p>
          <div v-for="c in e.datos.comisiones" :key="c.id" class="flex justify-between gap-2 py-0.5">
            <span class="text-gray-700">{{ c.mes_venta }} · {{ c.estado }}</span>
            <span class="text-gray-600 tabular-nums">sobre {{ cop(c.valor_orden) }}</span>
          </div>
        </div>

        <div v-if="e.corridas?.length">
          <p class="text-[10px] font-semibold text-gray-500 uppercase mb-1">Órdenes que bajaron un número</p>
          <p class="font-mono text-gray-600">{{ e.corridas.map(c => `${c.de} → ${c.a}`).join(' · ') }}</p>
        </div>

        <p v-if="e.datos?.fecha_sugerida_vendedor || e.datos?.created_at" class="text-gray-400">
          Vendida el {{ fecha(e.datos.created_at) }}<template v-if="e.datos.canal"> · canal {{ e.datos.canal }}</template>
        </p>
      </div>
    </div>

    <button v-if="hayMas" @click="cargar(pagina + 1)" :disabled="cargando"
            class="w-full text-sm text-blue-600 font-medium py-2 disabled:opacity-50">
      {{ cargando ? 'Cargando…' : 'Ver más' }}
    </button>
  </div>
</template>
