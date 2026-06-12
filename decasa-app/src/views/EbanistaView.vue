<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { CheckCircleIcon, WrenchScrewdriverIcon, ClockIcon, ArrowTopRightOnSquareIcon, UserPlusIcon, XMarkIcon, ArrowUturnLeftIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { getMisPasos, completarPaso, getHistorialPasos, devolverPaso } from '@/api/produccion'
import { useToast } from '@/composables/useToast'
import { useRealtime } from '@/composables/useRealtime'
import { usePasosStore } from '@/stores/pasos'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()
const auth   = useAuthStore()
const toast  = useToast()
const pasosStore = usePasosStore()

function verOrden(paso) {
  const id = paso.produccion?.orden_item?.orden?.id
  if (id) router.push({ name: 'orden-detalle', params: { id } })
}

const tab = ref('activos')

const pasos   = ref([])
const loading = ref(true)
const completandoId = ref(null)
const mostrarModal   = ref(false)
const pasoConfirmar  = ref(null)
const trabajadores   = ref([])
const inputTrabajador = ref('')

const historial        = ref([])
const loadingHistorial = ref(false)

const PROCESO_LABEL = { ebanisteria: 'Ebanistería', tapizado: 'Tapizado', laca: 'Laca', esqueleteria: 'Esqueletería', pintura: 'Pintura', costura: 'Costura' }

// ── Devolver paso ──────────────────────────────────────────────────────────────
const mostrarModalDevolver = ref(false)
const pasoDevolver         = ref(null)   // paso actual (el que detectó el problema)
const destinoId            = ref(null)   // id del paso al que se devuelve
const motivoDevolver       = ref('')
const devolviendo          = ref(false)

function pasosAnterioresCompletados(paso) {
  return (paso.produccion?.pasos ?? [])
    .filter(p => p.estado === 'completado' && p.orden < paso.orden)
}

function abrirDevolver(paso) {
  pasoDevolver.value   = paso
  destinoId.value      = null
  motivoDevolver.value = ''
  mostrarModalDevolver.value = true
}

async function confirmarDevolver() {
  if (!destinoId.value || !motivoDevolver.value.trim()) return
  devolviendo.value = true
  try {
    await devolverPaso(pasoDevolver.value.id, {
      paso_destino_id: destinoId.value,
      motivo:          motivoDevolver.value.trim(),
    })
    mostrarModalDevolver.value = false
    toast.success('Paso devuelto para corrección.')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al devolver el paso.')
  } finally {
    devolviendo.value = false
  }
}
const PROCESO_COLOR = {
  ebanisteria:  'bg-orange-100 text-orange-700',
  tapizado:     'bg-teal-100 text-teal-700',
  laca:         'bg-indigo-100 text-indigo-700',
  esqueleteria: 'bg-yellow-100 text-yellow-700',
  pintura:      'bg-purple-100 text-purple-700',
  costura:      'bg-pink-100 text-pink-700',
}

async function cargar() {
  loading.value = true
  try {
    const { data } = await getMisPasos()
    pasos.value = Array.isArray(data) ? data : []
    pasosStore.pasos = pasos.value
  } catch {
    pasos.value = []
  } finally {
    loading.value = false
  }
}

async function cargarHistorial() {
  loadingHistorial.value = true
  try {
    const { data } = await getHistorialPasos()
    historial.value = Array.isArray(data) ? data : []
  } catch {
    historial.value = []
  } finally {
    loadingHistorial.value = false
  }
}

function abrirConfirmar(paso) {
  pasoConfirmar.value   = paso
  trabajadores.value    = []
  inputTrabajador.value = ''
  mostrarModal.value    = true
}

function agregarTrabajador() {
  const nombre = inputTrabajador.value.trim()
  if (!nombre || trabajadores.value.includes(nombre)) return
  trabajadores.value.push(nombre)
  inputTrabajador.value = ''
}

function quitarTrabajador(nombre) {
  trabajadores.value = trabajadores.value.filter(t => t !== nombre)
}

function onInputKeydown(e) {
  if (e.key === 'Enter') { e.preventDefault(); agregarTrabajador() }
}

async function confirmarListo() {
  const paso = pasoConfirmar.value
  if (!paso || trabajadores.value.length === 0) return
  completandoId.value = paso.id
  mostrarModal.value  = false
  try {
    await completarPaso(paso.id, { trabajadores: trabajadores.value })
    toast.success('¡Paso completado!')
    await cargar()
    await cargarHistorial()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al completar el paso.')
  } finally {
    completandoId.value = null
  }
}

function formatFecha(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(String(dateStr).substring(0, 10) + 'T00:00:00')
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function progresoTexto(pasoActual) {
  const todos = pasoActual.produccion?.pasos ?? []
  const completados = todos.filter(p => p.estado === 'completado').length
  return `${completados}/${todos.length} pasos completados`
}

const { listen } = useRealtime()

onMounted(async () => {
  await Promise.all([cargar(), cargarHistorial()])
  listen('produccion', 'produccion.actualizada', cargar)
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <WrenchScrewdriverIcon class="w-6 h-6 text-orange-600" />
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        {{ auth.isTapicero ? 'Mis pasos de producción' : 'Mis pasos' }}
      </h2>
    </div>

    <p class="text-xs text-gray-500">
      {{ auth.isTapicero
        ? 'Pasos de tapizado, esqueletería, costura y pintura asignados a ti'
        : auth.isDespachador
          ? 'Pasos de pintura asignados a ti'
          : 'Pasos de ebanistería, laca y pintura asignados a ti' }}
    </p>

    <!-- Tabs -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'activos'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'activos' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Activos
        <span v-if="pasos.length" class="ml-1 inline-flex items-center justify-center w-5 h-5 text-xs bg-orange-100 text-orange-700 rounded-full font-bold">{{ pasos.length }}</span>
      </button>
      <button
        @click="tab = 'historial'"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors', tab === 'historial' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >
        Historial
      </button>
    </div>

    <!-- ── TAB ACTIVOS ─────────────────────────────────────────────────────── -->
    <template v-if="tab === 'activos'">

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Empty -->
    <EmptyState
      v-else-if="pasos.length === 0"
      message="No tienes pasos activos en este momento."
    />

    <!-- Lista de pasos -->
    <template v-else>
      <ul class="space-y-3">
        <li
          v-for="paso in pasos"
          :key="paso.id"
          class="bg-white rounded-xl shadow-sm p-4 space-y-3"
        >
          <!-- Tipo de proceso + producto -->
          <div class="flex items-start gap-3">
            <!-- Foto del producto -->
            <img
              v-if="paso.produccion?.orden_item?.producto?.foto_url"
              :src="paso.produccion.orden_item.producto.foto_url"
              :alt="paso.produccion.orden_item.producto.nombre"
              class="w-16 h-16 rounded-xl object-cover flex-shrink-0 border border-gray-100"
            />
            <div v-else class="w-16 h-16 rounded-xl bg-gray-100 flex-shrink-0 flex items-center justify-center">
              <WrenchScrewdriverIcon class="w-7 h-7 text-gray-300" />
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-1">
                <span :class="['inline-block text-xs font-bold px-2.5 py-1 rounded-full mb-1', PROCESO_COLOR[paso.tipo_proceso]]">
                  {{ PROCESO_LABEL[paso.tipo_proceso] }}
                </span>
                <span class="text-xs bg-blue-50 text-blue-600 font-medium px-2 py-0.5 rounded-full flex-shrink-0">
                  Paso {{ paso.orden }}
                </span>
              </div>
              <p class="font-semibold text-sm text-gray-800 truncate">{{ paso.produccion?.orden_item?.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ paso.produccion?.orden_item?.producto?.categoria }}</p>
            </div>
          </div>

          <!-- Progreso de pasos -->
          <div v-if="paso.produccion?.pasos?.length" class="flex items-center gap-1.5">
            <span class="text-xs text-gray-400 mr-1">{{ progresoTexto(paso) }}</span>
            <div class="flex gap-1">
              <span
                v-for="p in paso.produccion.pasos"
                :key="p.id"
                :class="[
                  'inline-block w-6 h-1.5 rounded-full',
                  p.estado === 'completado' ? 'bg-green-400' :
                  p.estado === 'en_proceso'  ? 'bg-blue-500' :
                  'bg-gray-200'
                ]"
                :title="PROCESO_LABEL[p.tipo_proceso] ?? p.tipo_proceso"
              />
            </div>
          </div>

          <!-- Info del cliente -->
          <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
            <div>
              <p class="text-gray-400">Cliente</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.cliente?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Teléfono</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.cliente?.telefono ?? '—' }}</p>
            </div>
            <div>
              <p class="text-gray-400">Vendedor</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.vendedor?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Tienda</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.tienda?.nombre }}</p>
            </div>
            <div v-if="paso.produccion?.fecha_compromiso" class="col-span-2">
              <p class="text-gray-400">Fecha compromiso</p>
              <p class="font-medium text-gray-700">{{ formatFecha(paso.produccion.fecha_compromiso) }}</p>
            </div>
          </div>

          <!-- Specs del producto si hay -->
          <div
            v-if="paso.produccion?.orden_item?.specs_personalizacion"
            class="bg-gray-50 rounded-lg px-3 py-2 text-xs text-gray-600 space-y-0.5"
          >
            <p
              v-for="(val, key) in paso.produccion.orden_item.specs_personalizacion"
              :key="key"
            >
              <span class="text-gray-400 capitalize">{{ key }}:</span> {{ val }}
            </p>
          </div>

          <!-- Motivo de devolución -->
          <div
            v-if="paso.ultimo_rechazo"
            class="flex items-start gap-2 bg-red-50 border border-red-200 rounded-xl px-3 py-2.5"
          >
            <ExclamationTriangleIcon class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
            <div class="min-w-0">
              <p class="text-xs font-semibold text-red-700">Devuelto para corrección</p>
              <p class="text-xs text-red-600 mt-0.5 leading-snug">{{ paso.ultimo_rechazo }}</p>
              <p v-if="paso.rechazos > 1" class="text-[10px] text-red-400 mt-1">{{ paso.rechazos }} devoluciones en este paso</p>
            </div>
          </div>

          <!-- Botones -->
          <div class="flex gap-2">
            <button
              @click="verOrden(paso)"
              class="flex-1 bg-gray-100 text-gray-700 rounded-xl py-2.5 text-sm font-semibold hover:bg-gray-200 transition-colors flex items-center justify-center gap-1.5"
            >
              <ArrowTopRightOnSquareIcon class="w-4 h-4" />
              Ver orden
            </button>
            <button
              @click="abrirConfirmar(paso)"
              :disabled="completandoId === paso.id"
              class="flex-[2] bg-green-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-green-700 disabled:opacity-50 transition-colors flex items-center justify-center gap-2"
            >
              <CheckCircleIcon class="w-5 h-5" />
              {{ completandoId === paso.id ? 'Procesando...' : 'Listo — paso terminado' }}
            </button>
          </div>

          <!-- Devolver (solo si hay pasos anteriores completados) -->
          <button
            v-if="pasosAnterioresCompletados(paso).length > 0"
            @click="abrirDevolver(paso)"
            class="w-full bg-red-50 text-red-600 border border-red-200 rounded-xl py-2 text-sm font-semibold hover:bg-red-100 transition-colors flex items-center justify-center gap-1.5"
          >
            <ArrowUturnLeftIcon class="w-4 h-4" />
            Hay un defecto — devolver paso anterior
          </button>
        </li>
      </ul>
    </template>
    </template><!-- /tab activos -->

    <!-- ── TAB HISTORIAL ──────────────────────────────────────────────────── -->
    <template v-if="tab === 'historial'">
      <AppSpinner v-if="loadingHistorial" />

      <EmptyState
        v-else-if="historial.length === 0"
        message="Todavía no has completado ningún paso."
      />

      <ul v-else class="space-y-3">
        <li
          v-for="paso in historial"
          :key="paso.id"
          class="bg-white rounded-xl shadow-sm p-4 space-y-3"
        >
          <!-- Foto + nombre -->
          <div class="flex items-start gap-3">
            <img
              v-if="paso.produccion?.orden_item?.producto?.foto_url"
              :src="paso.produccion.orden_item.producto.foto_url"
              :alt="paso.produccion.orden_item.producto.nombre"
              class="w-16 h-16 rounded-xl object-cover flex-shrink-0 border border-gray-100"
            />
            <div v-else class="w-16 h-16 rounded-xl bg-gray-100 flex-shrink-0 flex items-center justify-center">
              <CheckCircleIcon class="w-7 h-7 text-gray-300" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-1">
                <span :class="['inline-block text-xs font-bold px-2.5 py-1 rounded-full mb-1', PROCESO_COLOR[paso.tipo_proceso]]">
                  {{ PROCESO_LABEL[paso.tipo_proceso] }}
                </span>
                <span class="text-xs text-green-600 font-semibold flex items-center gap-1 flex-shrink-0">
                  <CheckCircleIcon class="w-3.5 h-3.5" />
                  Completado
                </span>
              </div>
              <p class="font-semibold text-sm text-gray-800 truncate">{{ paso.produccion?.orden_item?.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ paso.produccion?.orden_item?.producto?.categoria }}</p>
            </div>
          </div>

          <!-- Info -->
          <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
            <div>
              <p class="text-gray-400">Cliente</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.cliente?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Teléfono</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.cliente?.telefono ?? '—' }}</p>
            </div>
            <div>
              <p class="text-gray-400">Vendedor</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.vendedor?.nombre }}</p>
            </div>
            <div>
              <p class="text-gray-400">Tienda</p>
              <p class="font-medium text-gray-700">{{ paso.produccion?.orden_item?.orden?.tienda?.nombre }}</p>
            </div>
            <div class="col-span-2">
              <p class="text-gray-400">Completado</p>
              <p class="font-medium text-gray-700 flex items-center gap-1">
                <ClockIcon class="w-3.5 h-3.5" />
                {{ formatFecha(paso.completado_at) }}
              </p>
            </div>
            <div v-if="paso.trabajadores?.length" class="col-span-2">
              <p class="text-gray-400">Responsables</p>
              <div class="flex flex-wrap gap-1 mt-1">
                <span
                  v-for="t in paso.trabajadores"
                  :key="t"
                  class="inline-flex items-center bg-blue-50 text-blue-700 text-xs font-medium px-2.5 py-0.5 rounded-full"
                >{{ t }}</span>
              </div>
            </div>
          </div>

          <!-- Ver orden -->
          <button
            @click="verOrden(paso)"
            class="w-full bg-gray-100 text-gray-700 rounded-xl py-2.5 text-sm font-semibold hover:bg-gray-200 transition-colors flex items-center justify-center gap-1.5"
          >
            <ArrowTopRightOnSquareIcon class="w-4 h-4" />
            Ver orden
          </button>
        </li>
      </ul>
    </template><!-- /tab historial -->

    <!-- Modal devolver paso -->
    <Transition name="fade">
      <div v-if="mostrarModalDevolver" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarModalDevolver = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[90vh] overflow-y-auto">
          <div class="flex items-center gap-2">
            <ExclamationTriangleIcon class="w-5 h-5 text-red-500" />
            <h3 class="text-lg font-bold text-gray-800">Reportar defecto</h3>
          </div>
          <p class="text-sm text-gray-600">
            Estás en <strong>{{ PROCESO_LABEL[pasoDevolver?.tipo_proceso] }}</strong>.
            Selecciona el paso que tiene el defecto — ese paso y todos los siguientes se reiniciarán para corrección.
          </p>

          <!-- Pasos anteriores -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">Paso con el defecto</label>
            <div class="space-y-2">
              <label
                v-for="p in pasosAnterioresCompletados(pasoDevolver)"
                :key="p.id"
                :class="[
                  'flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-colors',
                  destinoId === p.id ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:border-gray-300'
                ]"
              >
                <input type="radio" :value="p.id" v-model="destinoId" class="accent-red-500" />
                <div>
                  <span :class="['inline-block text-xs font-bold px-2 py-0.5 rounded-full', PROCESO_COLOR[p.tipo_proceso]]">
                    {{ PROCESO_LABEL[p.tipo_proceso] }}
                  </span>
                  <span class="text-xs text-gray-400 ml-1">Paso {{ p.orden }}</span>
                </div>
              </label>
            </div>
          </div>

          <!-- Motivo -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">Descripción del defecto</label>
            <textarea
              v-model="motivoDevolver"
              rows="3"
              placeholder="Ej: La madera quedó mal lijada, hay astillas en el lateral derecho..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 resize-none"
            />
          </div>

          <div class="flex gap-3">
            <button @click="mostrarModalDevolver = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="confirmarDevolver"
              :disabled="!destinoId || !motivoDevolver.trim() || devolviendo"
              class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            >
              {{ devolviendo ? 'Devolviendo...' : 'Devolver para corrección' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal de confirmación con asignación de trabajadores -->
    <Transition name="fade">
      <div v-if="mostrarModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <h3 class="text-lg font-bold text-gray-800">Registrar responsables</h3>
          <p class="text-sm text-gray-600">
            Paso de <strong>{{ PROCESO_LABEL[pasoConfirmar?.tipo_proceso] }}</strong>
            — <strong>{{ pasoConfirmar?.produccion?.orden_item?.producto?.nombre }}</strong>
          </p>

          <!-- Input de trabajadores -->
          <div class="space-y-2">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">
              Trabajadores que realizaron este paso
            </label>

            <!-- Chips de trabajadores ya agregados -->
            <div v-if="trabajadores.length" class="flex flex-wrap gap-1.5 mb-2">
              <span
                v-for="t in trabajadores"
                :key="t"
                class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 text-xs font-medium px-2.5 py-1 rounded-full"
              >
                {{ t }}
                <button @click="quitarTrabajador(t)" class="hover:text-red-500 transition-colors">
                  <XMarkIcon class="w-3 h-3" />
                </button>
              </span>
            </div>

            <!-- Input + botón agregar -->
            <div class="flex gap-2">
              <input
                v-model="inputTrabajador"
                @keydown="onInputKeydown"
                type="text"
                placeholder="Nombre del trabajador..."
                class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <button
                @click="agregarTrabajador"
                :disabled="!inputTrabajador.trim()"
                class="bg-blue-600 text-white rounded-lg px-3 py-2 hover:bg-blue-700 disabled:opacity-40 transition-colors"
              >
                <UserPlusIcon class="w-4 h-4" />
              </button>
            </div>
            <p class="text-xs text-gray-400">Presiona Enter o el botón para agregar. Puedes agregar varios.</p>
          </div>

          <p v-if="trabajadores.length === 0" class="text-xs text-amber-600 bg-amber-50 rounded-lg px-3 py-2">
            Debes agregar al menos un trabajador para poder marcar el paso como listo.
          </p>

          <div class="flex gap-3">
            <button @click="mostrarModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="confirmarListo"
              :disabled="trabajadores.length === 0"
              class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            >
              Confirmar listo
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
