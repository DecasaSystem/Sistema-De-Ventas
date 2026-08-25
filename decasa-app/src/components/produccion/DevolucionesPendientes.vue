<script setup>
/**
 * Lo que volvió en el camión y todavía nadie ha resuelto.
 *
 * Va arriba del tablero del taller porque es donde entra todos los días quien
 * decide. Mientras una devolución siga acá hay un mueble parado en la bodega y
 * un cliente esperando respuesta, así que no se puede quedar escondida detrás
 * de una pestaña.
 *
 * Si no hay ninguna, no se pinta nada: un panel vacío permanente deja de verse
 * a los dos días.
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { getDevoluciones, decidirDevolucion } from '@/api/devoluciones'
import {
  ArrowUturnLeftIcon, WrenchScrewdriverIcon, BanknotesIcon,
  XMarkIcon, ChevronDownIcon, ChevronUpIcon,
} from '@heroicons/vue/24/outline'

const emit = defineEmits(['resuelta'])

const router = useRouter()
const auth   = useAuthStore()
const toast  = useToast()

const pendientes = ref([])
const abierto    = ref(true)
const cargando   = ref(false)

const puedeDecidir = computed(() => auth.gestionaProduccion)

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}
function formatoFecha(f) {
  if (!f) return ''
  return new Date(f + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getDevoluciones({ estado: 'pendiente' })
    pendientes.value = data
  } catch {} finally {
    cargando.value = false
  }
}
onMounted(cargar)
defineExpose({ cargar })

// ── Vuelve al taller ────────────────────────────────────────────────────────
const enviando = ref(null)

async function aProduccion(d) {
  if (!confirm(`¿"${d.producto}" vuelve al taller para arreglo?`)) return
  enviando.value = d.id
  try {
    await decidirDevolucion(d.id, { decision: 'a_produccion' })
    toast.success('Vuelve al taller: ya aparece en el tablero')
    await cargar()
    emit('resuelta')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    enviando.value = null
  }
}

// ── Se cancela y se devuelve la plata ───────────────────────────────────────
const reembolsando = ref(null)
const monto        = ref(0)
const notas        = ref('')
const guardando    = ref(false)

function abrirReembolso(d) {
  reembolsando.value = d
  // Se sugiere lo que pagó por esas unidades; se puede cambiar.
  monto.value = d.monto_sugerido
  notas.value = ''
}

async function confirmarReembolso() {
  guardando.value = true
  try {
    await decidirDevolucion(reembolsando.value.id, {
      decision: 'reembolso',
      monto: Number(monto.value) || 0,
      notas: notas.value.trim() || null,
    })
    toast.success('Registrado: la salida quedó en la caja de la tienda')
    reembolsando.value = null
    await cargar()
    emit('resuelta')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div v-if="pendientes.length" class="mb-4">
    <div class="bg-orange-50 border border-orange-300 rounded-xl overflow-hidden">
      <button @click="abierto = !abierto" class="w-full flex items-center gap-2.5 px-4 py-3 text-left">
        <div class="w-9 h-9 rounded-xl bg-orange-100 flex items-center justify-center shrink-0">
          <ArrowUturnLeftIcon class="w-5 h-5 text-orange-700" />
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-bold text-orange-900">
            {{ pendientes.length }}
            {{ pendientes.length === 1 ? 'producto devuelto' : 'productos devueltos' }}
          </p>
          <p class="text-[11px] text-orange-700">
            {{ puedeDecidir ? 'Hay que decidir si se arregla o se cancela.' : 'Esperando que producción decida.' }}
          </p>
        </div>
        <component :is="abierto ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-orange-600 shrink-0" />
      </button>

      <div v-if="abierto" class="px-3 pb-3 space-y-2">
        <div v-for="d in pendientes" :key="d.id" class="bg-white rounded-xl p-3 border border-orange-200">
          <div class="flex items-start gap-3">
            <!-- La foto del daño se abre a tamaño completo: en miniatura no se
                 alcanza a ver si el golpe tiene arreglo. -->
            <a v-if="d.foto_url" :href="d.foto_url" target="_blank" rel="noopener" class="shrink-0">
              <img :src="d.foto_url" class="w-14 h-14 rounded-lg object-cover border border-gray-100" />
            </a>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-gray-800">
                <span v-if="d.cantidad > 1" class="text-orange-700">{{ d.cantidad }}× </span>{{ d.producto }}
              </p>
              <button
                @click="router.push({ name: 'orden-detalle', params: { id: d.orden_id } })"
                class="text-[11px] text-blue-600 hover:text-blue-700"
              >{{ d.orden_referencia }} · {{ d.cliente }}</button>
              <p class="text-xs text-gray-600 mt-1">{{ d.motivo }}</p>
              <p class="text-[11px] text-gray-400 mt-0.5">
                Devuelto el {{ formatoFecha(d.fecha) }}<span v-if="d.reportado_por"> · lo trajo {{ d.reportado_por }}</span>
              </p>
            </div>
          </div>

          <div v-if="puedeDecidir" class="flex gap-2 mt-3">
            <!-- Es lo que pasa casi siempre, así que va primero y en color -->
            <button
              @click="aProduccion(d)"
              :disabled="enviando === d.id"
              class="flex-1 bg-blue-600 text-white text-xs font-semibold rounded-lg px-2 py-2 hover:bg-blue-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
            >
              <WrenchScrewdriverIcon class="w-4 h-4" />
              {{ enviando === d.id ? 'Guardando...' : 'Vuelve al taller' }}
            </button>
            <button
              @click="abrirReembolso(d)"
              class="flex-1 bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg px-2 py-2 hover:bg-gray-200 transition-colors flex items-center justify-center gap-1.5"
            >
              <BanknotesIcon class="w-4 h-4" />
              Cancelar y devolver
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Cancelar y devolver la plata -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="reembolsando" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="reembolsando = null">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
              <div class="min-w-0">
                <p class="font-semibold text-gray-800 truncate">Cancelar y devolver la plata</p>
                <p class="text-[11px] text-gray-400 truncate">{{ reembolsando.producto }} — {{ reembolsando.cliente }}</p>
              </div>
              <button @click="reembolsando = null" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-4">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuánto se le devuelve? *</label>
                <InputPesos v-model="monto" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                <p class="text-[11px] text-gray-400 mt-1">
                  Sugerido: {{ formatoPesos(reembolsando.monto_sugerido) }}, que es lo que pagó por
                  {{ reembolsando.cantidad > 1 ? 'esas unidades' : 'esa unidad' }}.
                </p>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas</label>
                <textarea v-model="notas" rows="2" placeholder="Lo que valga la pena dejar dicho..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl p-3">
                Queda una salida en la caja de la tienda por ese valor, para que cuadre al cerrar el
                día. Si con esto no queda nada más por entregar, la orden se cancela.
              </p>
            </div>

            <div class="flex gap-2.5 p-5 pt-0">
              <button @click="reembolsando = null" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="confirmarReembolso" :disabled="guardando"
                class="flex-1 bg-red-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-red-700 transition-colors disabled:opacity-50"
              >
                {{ guardando ? 'Guardando...' : 'Confirmar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
