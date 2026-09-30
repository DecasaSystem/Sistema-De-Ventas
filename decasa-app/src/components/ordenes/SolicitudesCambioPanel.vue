<script setup>
/**
 * Los cambios de dinero pedidos para esta orden.
 *
 * El pendiente va arriba y a la vista: qué cambia (antes → después), quién
 * lo pidió, por qué y con qué soporte. El supervisor lo aprueba —y se aplica
 * como una edición normal— o lo rechaza diciendo por qué; quien lo pidió puede
 * retirarlo mientras nadie lo responda. Los ya respondidos quedan abajo,
 * plegados, como historial.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { getSolicitudesCambio, aprobarSolicitudCambio, rechazarSolicitudCambio, cancelarSolicitudCambio } from '@/api/ordenes'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { ShieldCheckIcon, ArrowRightIcon, CheckIcon, XMarkIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  ordenId: { type: [Number, String], required: true },
})
const emit = defineEmits(['aplicada'])

const auth  = useAuthStore()
const toast = useToast()

const solicitudes = ref([])
const cargando    = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getSolicitudesCambio(props.ordenId)
    solicitudes.value = data ?? []
  } catch {
    solicitudes.value = []
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)
watch(() => props.ordenId, cargar)
defineExpose({ cargar })

const pendiente  = computed(() => solicitudes.value.find(s => s.estado === 'pendiente') ?? null)
const historial  = computed(() => solicitudes.value.filter(s => s.estado !== 'pendiente'))
const verHistorial = ref(false)

const esSupervisor = computed(() => auth.usuario?.rol === 'supervisor')
const esMia = computed(() => pendiente.value && Number(pendiente.value.solicitante_id) === Number(auth.usuario?.id))

function valor(v, fila) {
  if (v === null || v === undefined || v === '') return '—'
  if (fila.tipo === 'plata' && typeof v === 'number') return '$' + Math.round(v).toLocaleString('es-CO')
  return String(v)
}
function fecha(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}
const ESTADO = {
  aprobada:  { texto: 'Aprobada',  cls: 'bg-green-100 text-green-800' },
  rechazada: { texto: 'Rechazada', cls: 'bg-red-100 text-red-800' },
  cancelada: { texto: 'Retirada',  cls: 'bg-gray-100 text-gray-600' },
}

const fotoAmpliada = ref(null)

// ── Responder ───────────────────────────────────────────────────────────────
const trabajando   = ref(false)
const rechazando   = ref(false)
const razon        = ref('')

async function aprobar() {
  if (trabajando.value) return
  trabajando.value = true
  try {
    await aprobarSolicitudCambio(pendiente.value.id)
    toast.success('Cambio aprobado y aplicado a la orden.')
    await cargar()
    emit('aplicada')
  } catch (e) {
    // Si no se pudo aplicar (sin stock, la orden cambió…) sigue pendiente:
    // se puede rechazar con esa razón.
    toast.error(e.response?.data?.message ?? 'No se pudo aprobar.', 10000)
  } finally {
    trabajando.value = false
  }
}

async function rechazar() {
  if (trabajando.value || razon.value.trim().length < 3) return
  trabajando.value = true
  try {
    await rechazarSolicitudCambio(pendiente.value.id, razon.value.trim())
    toast.success('Solicitud rechazada. Se le avisó al vendedor.')
    rechazando.value = false
    razon.value = ''
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo rechazar.')
  } finally {
    trabajando.value = false
  }
}

async function retirar() {
  if (trabajando.value) return
  trabajando.value = true
  try {
    await cancelarSolicitudCambio(pendiente.value.id)
    toast.success('Solicitud retirada.')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo retirar.')
  } finally {
    trabajando.value = false
  }
}
</script>

<template>
  <div v-if="solicitudes.length" class="space-y-2">
    <!-- ── Pendiente ─────────────────────────────────────────────────── -->
    <section v-if="pendiente" class="rounded-xl border-2 border-amber-300 bg-amber-50 overflow-hidden">
      <header class="flex items-start gap-3 px-4 pt-4">
        <div class="w-9 h-9 rounded-full bg-amber-200 text-amber-800 flex items-center justify-center flex-shrink-0">
          <ShieldCheckIcon class="w-5 h-5" />
        </div>
        <div class="min-w-0">
          <p class="text-sm font-bold text-amber-900">Cambio de dinero esperando aprobación</p>
          <p class="text-xs text-amber-800 mt-0.5">
            Lo pidió <span class="font-semibold">{{ pendiente.solicitante?.nombre ?? 'un vendedor' }}</span>
            <template v-if="pendiente.supervisor"> a <span class="font-semibold">{{ pendiente.supervisor.nombre }}</span></template>
            · {{ fecha(pendiente.created_at) }}
          </p>
        </div>
      </header>

      <div class="px-4 py-3 space-y-3">
        <ul class="rounded-lg bg-white border border-amber-200 divide-y divide-amber-100">
          <li v-for="(c, i) in pendiente.resumen" :key="i" class="px-3 py-2">
            <p class="text-sm font-medium text-gray-800">{{ c.label }}</p>
            <p class="flex items-center gap-2 text-sm mt-0.5">
              <span class="text-gray-400 line-through">{{ valor(c.antes, c) }}</span>
              <ArrowRightIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
              <span class="font-semibold text-gray-900">{{ valor(c.despues, c) }}</span>
            </p>
          </li>
        </ul>

        <div>
          <p class="text-[11px] font-semibold text-amber-900 uppercase tracking-wide">Motivo</p>
          <p class="text-sm text-gray-800 whitespace-pre-line mt-0.5">{{ pendiente.motivo }}</p>
        </div>

        <div>
          <p class="text-[11px] font-semibold text-amber-900 uppercase tracking-wide mb-1">Soporte</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="(url, i) in pendiente.soportes" :key="url" type="button"
              @click="fotoAmpliada = url" :aria-label="`Ver soporte ${i + 1}`"
              class="w-20 h-20 rounded-lg overflow-hidden border border-amber-200 bg-white">
              <img :src="cloudinaryOpt(url, 160)" alt="" class="w-full h-full object-cover" />
            </button>
          </div>
        </div>

        <!-- Supervisor: responder -->
        <template v-if="esSupervisor">
          <div v-if="rechazando" class="space-y-2">
            <label for="razon-rechazo" class="block text-xs font-semibold text-gray-700">¿Por qué no se aprueba?</label>
            <textarea id="razon-rechazo" v-model="razon" rows="2" maxlength="1000"
              placeholder="Se lo decimos al vendedor tal cual."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 resize-none" />
            <div class="flex gap-2">
              <button @click="rechazando = false; razon = ''" class="flex-1 rounded-lg bg-white border border-gray-300 text-gray-700 py-2.5 text-sm font-semibold">Volver</button>
              <button @click="rechazar" :disabled="trabajando || razon.trim().length < 3"
                class="flex-1 rounded-lg bg-red-600 text-white py-2.5 text-sm font-bold hover:bg-red-700 disabled:opacity-50">
                {{ trabajando ? 'Rechazando…' : 'Rechazar' }}
              </button>
            </div>
          </div>
          <div v-else class="flex gap-2">
            <button @click="rechazando = true" :disabled="trabajando"
              class="flex-1 flex items-center justify-center gap-1.5 rounded-lg bg-white border border-red-300 text-red-700 py-2.5 text-sm font-bold hover:bg-red-50 disabled:opacity-50">
              <XMarkIcon class="w-4 h-4" /> Rechazar
            </button>
            <button @click="aprobar" :disabled="trabajando"
              class="flex-1 flex items-center justify-center gap-1.5 rounded-lg bg-green-700 text-white py-2.5 text-sm font-bold hover:bg-green-800 disabled:opacity-50">
              <CheckIcon class="w-4 h-4" /> {{ trabajando ? 'Aplicando…' : 'Aprobar y aplicar' }}
            </button>
          </div>
        </template>

        <!-- Quien la pidió: puede retirarla -->
        <div v-else-if="esMia" class="flex items-center justify-between gap-3">
          <p class="text-xs text-amber-800">Te avisamos cuando un supervisor la responda.</p>
          <button @click="retirar" :disabled="trabajando"
            class="rounded-lg bg-white border border-gray-300 text-gray-700 px-3 py-2 text-xs font-semibold hover:bg-gray-50 disabled:opacity-50 flex-shrink-0">
            Retirar solicitud
          </button>
        </div>
      </div>
    </section>

    <!-- ── Historial ─────────────────────────────────────────────────── -->
    <section v-if="historial.length" class="rounded-xl bg-white shadow-sm">
      <button type="button" @click="verHistorial = !verHistorial"
        class="w-full flex items-center justify-between px-4 py-3 text-sm font-semibold text-gray-700">
        Cambios de dinero pedidos ({{ historial.length }})
        <ChevronDownIcon :class="['w-4 h-4 text-gray-400 transition-transform', verHistorial ? 'rotate-180' : '']" />
      </button>
      <ul v-if="verHistorial" class="divide-y divide-gray-100 border-t border-gray-100">
        <li v-for="s in historial" :key="s.id" class="px-4 py-3 space-y-1">
          <div class="flex items-center justify-between gap-2">
            <p class="text-xs text-gray-500">
              {{ s.solicitante?.nombre ?? 'Vendedor' }} · {{ fecha(s.created_at) }}
            </p>
            <span :class="['text-[11px] font-semibold rounded-full px-2 py-0.5', ESTADO[s.estado]?.cls]">
              {{ ESTADO[s.estado]?.texto ?? s.estado }}
            </span>
          </div>
          <p class="text-sm text-gray-800">
            <span v-for="(c, i) in s.resumen" :key="i">{{ i ? ' · ' : '' }}{{ c.label }}: {{ valor(c.antes, c) }} → {{ valor(c.despues, c) }}</span>
          </p>
          <p class="text-xs text-gray-500">Motivo: {{ s.motivo }}</p>
          <p v-if="s.revisado_por" class="text-xs text-gray-500">
            {{ s.estado === 'aprobada' ? 'Aprobó' : 'Rechazó' }} {{ s.revisado_por.nombre }}<span v-if="s.respuesta">: “{{ s.respuesta }}”</span>
          </p>
        </li>
      </ul>
    </section>

    <!-- Soporte ampliado -->
    <div v-if="fotoAmpliada" class="fixed inset-0 z-[90] bg-black/90 flex items-center justify-center p-4" @click="fotoAmpliada = null">
      <img :src="cloudinaryOpt(fotoAmpliada, 1400)" alt="Soporte" class="max-w-full max-h-full object-contain rounded-lg" />
    </div>
  </div>
</template>
