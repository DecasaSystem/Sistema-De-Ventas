<script setup>
/**
 * Facturación electrónica (Alegra). Lo que pidió el dueño (2026-11-09): la
 * orden se entrega → le aparece aquí al facturador → entra, revisa todo y
 * toca Facturar. Qué sale aquí lo deciden los Ajustes (solo supervisor).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppSpinner from '@/components/common/AppSpinner.vue'
import FacturacionAjustes from '@/components/facturacion/FacturacionAjustes.vue'
import { estadoFE, pendientesFE, facturasFE, ESTADOS_FE } from '@/api/facturacionElectronica'
import { pesos } from '@/utils/pesos'
import { MagnifyingGlassIcon, ArrowPathIcon, ExclamationTriangleIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()

const estado   = ref(null)
const tab      = ref(route.query.pestana || 'pendientes')
const buscar   = ref('')
const filas    = ref([])
const cargando = ref(true)
const error    = ref('')

const tabs = computed(() => [
  { key: 'pendientes',  label: 'Por facturar' },
  { key: 'hechas',      label: 'Hechas' },
  { key: 'problemas',   label: 'Problemas' },
  { key: 'diferencias', label: 'Diferencias' },
  ...(estado.value?.puede_ajustes ? [{ key: 'ajustes', label: 'Ajustes' }] : []),
])

const MOMENTOS = {
  al_entregar:  'cuando la orden se entrega',
  al_confirmar: 'apenas entra el primer pago',
  por_pago:     'una factura por cada pago',
}

async function cargarEstado() {
  try {
    const { data } = await estadoFE()
    estado.value = data
  } catch (e) {
    error.value = e.response?.status === 403
      ? 'Tu cuenta no tiene la función de facturación. Pídele al supervisor que la active.'
      : (e.response?.data?.message ?? 'No se pudo cargar.')
  }
}

async function cargar() {
  if (tab.value === 'ajustes') { cargando.value = false; return }
  cargando.value = true
  error.value = ''
  try {
    const params = { buscar: buscar.value || undefined }
    const { data } = tab.value === 'pendientes'
      ? await pendientesFE(params)
      : await facturasFE({ ...params, grupo: tab.value })
    filas.value = data.data ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar la lista.'
    filas.value = []
  } finally {
    cargando.value = false
  }
}

function abrir(f) {
  router.push({
    name: 'factura-orden',
    params: { id: f.orden_id },
    query: f.pago_id ? { pago: f.pago_id } : {},
  })
}

function fecha(v) {
  if (!v) return ''
  return new Date(v).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', timeZone: 'America/Bogota' })
}

let t
watch(buscar, () => { clearTimeout(t); t = setTimeout(cargar, 350) })
watch(tab, (v) => {
  router.replace({ query: v === 'pendientes' ? {} : { pestana: v } })
  cargar()
})

async function alGuardarAjustes() {
  await cargarEstado()
}

onMounted(async () => {
  await cargarEstado()
  cargar()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-bold text-gray-800">Facturación electrónica</h2>
      <button @click="cargar()" :disabled="cargando" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-40">
        <ArrowPathIcon class="w-5 h-5" />
      </button>
    </div>

    <!-- Cómo está funcionando, en una línea -->
    <div v-if="estado" class="bg-white rounded-xl shadow-sm p-3 text-xs text-gray-600 space-y-1">
      <div class="flex flex-wrap gap-1.5">
        <span :class="['px-2 py-0.5 rounded-full font-semibold', estado.activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600']">
          {{ estado.activo ? 'Prendida' : 'Apagada' }}
        </span>
        <span v-if="estado.activo" :class="['px-2 py-0.5 rounded-full font-semibold', estado.modo === 'emitir' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700']">
          {{ estado.modo === 'emitir' ? 'Va a la DIAN' : 'Modo prueba: solo borradores' }}
        </span>
        <span :class="['px-2 py-0.5 rounded-full font-semibold', estado.conectado ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700']">
          {{ estado.conectado ? 'Alegra conectado' : 'Alegra sin conectar' }}
        </span>
      </div>
      <p v-if="estado.activo">
        Se factura {{ MOMENTOS[estado.momento] }}<span v-if="estado.desde">, desde el {{ estado.desde }}</span>.
        {{ estado.requiere_revision ? 'Cada factura la revisa una persona antes de salir.' : 'Lo que no tiene advertencias sale solo.' }}
      </p>
      <p v-else>Mientras esté apagada no sale nada aquí. {{ estado.puede_ajustes ? 'Se prende en Ajustes.' : 'La prende un supervisor.' }}</p>
    </div>

    <div class="flex bg-gray-100 rounded-xl p-1 gap-1 overflow-x-auto">
      <button v-for="t in tabs" :key="t.key" @click="tab = t.key"
        :class="['flex-1 whitespace-nowrap px-2 py-2 text-xs font-semibold rounded-lg', tab === t.key ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">
        {{ t.label }}
      </button>
    </div>

    <FacturacionAjustes v-if="tab === 'ajustes'" @guardado="alGuardarAjustes" />

    <template v-else>
      <div class="relative">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input v-model="buscar" type="text" placeholder="Cliente, cédula, # orden o # factura"
          class="w-full pl-9 pr-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>

      <div v-if="error" class="bg-white border border-red-200 rounded-xl p-3 text-sm text-red-700 flex gap-2">
        <ExclamationTriangleIcon class="w-5 h-5 shrink-0" /> {{ error }}
      </div>

      <AppSpinner v-if="cargando" />

      <div v-else-if="!filas.length && !error" class="py-12 text-center text-sm text-gray-400">
        {{ tab === 'pendientes' ? 'No hay nada por facturar.' : 'No hay nada aquí.' }}
      </div>

      <div v-else class="space-y-2">
        <button v-for="f in filas" :key="(f.id ?? '') + '-' + f.orden_id + '-' + (f.pago_id ?? '')" @click="abrir(f)"
          class="w-full bg-white rounded-xl shadow-sm p-3 text-left flex items-center gap-3 hover:shadow-md">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm font-bold text-gray-800">{{ f.referencia }}</span>
              <span v-if="f.numero" class="text-xs font-semibold text-blue-700">{{ f.numero }}</span>
              <span v-if="f.estado && ESTADOS_FE[f.estado] && tab !== 'pendientes'"
                :class="['px-2 py-0.5 rounded-full text-xs font-semibold', ESTADOS_FE[f.estado].clase]">
                {{ ESTADOS_FE[f.estado].label }}
              </span>
              <span v-if="f.pago" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">
                {{ f.pago.tipo }} · {{ f.pago.metodo }}
              </span>
              <span v-if="f.intento" :class="['px-2 py-0.5 rounded-full text-xs font-semibold', ESTADOS_FE[f.intento.estado]?.clase]">
                {{ ESTADOS_FE[f.intento.estado]?.label }}
              </span>
              <span v-if="tab === 'pendientes' && f.dias_sin_facturar > 0" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700"
                title="Se factura el mismo día de la entrega">
                Atrasada · {{ f.dias_sin_facturar === 1 ? '1 día' : `${f.dias_sin_facturar} días` }}
              </span>
              <span v-if="f.faltan?.length" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700">
                Falta {{ f.faltan.join(', ') }}
              </span>
            </div>
            <p class="text-sm text-gray-600 truncate">{{ f.cliente }}<span v-if="f.documento" class="text-gray-400"> · {{ f.documento }}</span></p>
            <p class="text-xs text-gray-400">
              {{ f.tienda }}<span v-if="f.fecha || f.emitida_at"> · {{ fecha(f.fecha || f.emitida_at) }}</span>
              <span v-if="f.usuario"> · {{ f.usuario }}</span>
            </p>
            <p v-if="f.error" class="text-xs text-red-600 mt-1 line-clamp-2">{{ f.error }}</p>
            <p v-if="f.orden_cancelada && f.diferencia !== null && f.diferencia !== undefined" class="text-xs text-amber-700 mt-1">
              Orden cancelada después de facturar: hay que anular la factura con nota crédito.
            </p>
            <p v-else-if="f.diferencia !== null && f.diferencia !== undefined" class="text-xs text-amber-700 mt-1">
              La orden cambió {{ pesos(f.diferencia) }} después de facturar.
            </p>
            <p v-if="f.pagos_sin_registrar?.length" class="text-xs text-amber-700 mt-1">
              {{ f.pagos_sin_registrar.length }} pago(s) sin registrar en Alegra.
            </p>
          </div>
          <div class="text-right shrink-0">
            <p class="text-sm font-bold text-gray-800">{{ pesos(f.valor ?? f.total) }}</p>
            <ChevronRightIcon class="w-4 h-4 text-gray-300 ml-auto mt-1" />
          </div>
        </button>
      </div>
    </template>
  </div>
</template>
