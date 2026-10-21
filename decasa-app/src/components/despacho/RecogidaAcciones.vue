<script>
// Compartido por todas las tarjetas de la pantalla: los destinos se piden una vez.
let pedirDestinos = null
</script>

<script setup>
/**
 * Lo que el cliente devolvió en un cambio de producto y falta traer.
 *
 * La orden ya cambió; esto es solo el mueble. "Ya llegó" lo entra al
 * inventario de donde se dijo (se puede cambiar ahí mismo: llegó rayado →
 * merma, mejor a la fábrica). "Reprogramar" mueve el día o lo deja para
 * cuando le lleven el nuevo. Se usa en el detalle de la orden y en Despacho.
 */
import { ref, computed } from 'vue'
import { useToast } from '@/composables/useToast'
import { getDestinosDevolucion, recibirDevolucion, reprogramarRecogida } from '@/api/devoluciones'
import { TruckIcon, CalendarIcon } from '@heroicons/vue/24/outline'

const props = defineProps({ devolucion: { type: Object, required: true } })
const emit  = defineEmits(['cambio'])
const toast = useToast()
const d     = computed(() => props.devolucion)

const hoy = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)

function fechaBonita(iso) {
  if (!iso) return ''
  const f = new Date(iso + 'T12:00:00')
  return f.toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric', month: 'short' })
}

const atrasada = computed(() => d.value.recoger_el && d.value.recoger_el < hoy)
const esHoy    = computed(() => d.value.recoger_el === hoy)

const cuando = computed(() => d.value.recoger_el
  ? `Se recoge el ${fechaBonita(d.value.recoger_el)}`
  : 'Se recoge cuando le lleven el nuevo')
const adonde = computed(() => d.value.destino === 'merma' ? 'sale como merma' : `va a ${d.value.tienda_destino ?? 'la tienda'}`)

// ── Ya llegó ───────────────────────────────────────────────────────────────
// Los destinos se piden una vez y se comparten entre todas las tarjetas
// (pedirDestinos vive en el <script> de arriba, uno para todas).
const destinos   = ref([])
async function cargarDestinos() {
  pedirDestinos ??= getDestinosDevolucion().then(r => r.data).catch(() => [])
  destinos.value = await pedirDestinos
  // Si falló, que la próxima vez lo intente de nuevo.
  if (!destinos.value.length) pedirDestinos = null
}

const recibiendo  = ref(false)
const destinoSel  = ref('')
const guardando   = ref(false)

async function abrirRecibir() {
  reprogramando.value = false
  recibiendo.value = true
  destinoSel.value = d.value.destino === 'merma' ? 'merma' : String(d.value.tienda_destino_id ?? '')
  await cargarDestinos()
}

async function confirmarRecibir() {
  if (!destinoSel.value) { toast.error('Escoge a dónde llegó.'); return }
  guardando.value = true
  try {
    const payload = destinoSel.value === 'merma'
      ? { destino: 'merma' }
      : { destino: 'tienda', tienda_id: Number(destinoSel.value) }
    await recibirDevolucion(d.value.id, payload)
    toast.success(destinoSel.value === 'merma' ? 'Recibido. Sale como merma.' : 'Recibido y entrado al inventario.')
    recibiendo.value = false
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar.')
  } finally {
    guardando.value = false
  }
}

// ── Reprogramar ────────────────────────────────────────────────────────────
const reprogramando = ref(false)
const modo  = ref('programada')
const fecha = ref('')

function abrirReprogramar() {
  recibiendo.value = false
  reprogramando.value = true
  modo.value  = d.value.recoger_con_entrega ? 'con_entrega' : 'programada'
  fecha.value = d.value.recoger_el ?? ''
}

async function confirmarReprogramar() {
  if (modo.value === 'programada' && !fecha.value) { toast.error('Escoge el día.'); return }
  guardando.value = true
  try {
    await reprogramarRecogida(d.value.id, { recogida: modo.value, fecha_recogida: modo.value === 'programada' ? fecha.value : null })
    toast.success('Recogida reprogramada')
    reprogramando.value = false
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo reprogramar.')
  } finally {
    guardando.value = false
  }
}

const inputCls = 'w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'
</script>

<template>
  <div class="space-y-2">
    <p class="flex items-center gap-1.5 text-[11px] font-semibold"
      :class="atrasada ? 'text-red-700' : esHoy ? 'text-amber-700' : 'text-gray-600'">
      <TruckIcon class="w-3.5 h-3.5 shrink-0" />
      <span>{{ cuando }}<template v-if="atrasada"> (atrasada)</template><template v-else-if="esHoy"> (hoy)</template> · {{ adonde }}</span>
    </p>

    <div v-if="!recibiendo && !reprogramando" class="flex gap-2">
      <button type="button" @click="abrirRecibir"
        class="flex-1 bg-blue-600 text-white rounded-lg py-1.5 text-xs font-semibold hover:bg-blue-700">Ya llegó</button>
      <button type="button" @click="abrirReprogramar"
        class="flex-1 bg-white border border-gray-300 text-gray-700 rounded-lg py-1.5 text-xs font-semibold flex items-center justify-center gap-1">
        <CalendarIcon class="w-3.5 h-3.5" /> Reprogramar
      </button>
    </div>

    <!-- Llegó: a dónde -->
    <div v-if="recibiendo" class="rounded-lg border border-gray-200 p-2.5 space-y-2">
      <label class="block text-[11px] font-semibold text-gray-500">¿A dónde llegó?</label>
      <select v-model="destinoSel" :class="inputCls">
        <option value="" disabled>Escoge…</option>
        <option v-for="t in destinos" :key="t.id" :value="String(t.id)">{{ t.es_fabrica ? `${t.nombre} (fábrica / bodega)` : t.nombre }}</option>
        <option value="merma">Merma: llegó dañado, no vuelve a la venta</option>
      </select>
      <div class="flex gap-2">
        <button type="button" @click="recibiendo = false" class="flex-1 bg-white border border-gray-300 text-gray-700 rounded-lg py-1.5 text-xs font-semibold">Cancelar</button>
        <button type="button" @click="confirmarRecibir" :disabled="guardando"
          class="flex-1 bg-blue-600 text-white rounded-lg py-1.5 text-xs font-semibold disabled:opacity-50">{{ guardando ? 'Guardando...' : 'Confirmar' }}</button>
      </div>
    </div>

    <!-- Reprogramar -->
    <div v-if="reprogramando" class="rounded-lg border border-gray-200 p-2.5 space-y-2">
      <div class="flex gap-1 bg-gray-100 rounded-lg p-1">
        <button type="button" @click="modo = 'programada'"
          :class="['flex-1 py-1 text-[11px] font-semibold rounded-md', modo === 'programada' ? 'bg-white shadow-sm text-blue-700' : 'text-gray-500']">Otro día</button>
        <button type="button" @click="modo = 'con_entrega'"
          :class="['flex-1 py-1 text-[11px] font-semibold rounded-md', modo === 'con_entrega' ? 'bg-white shadow-sm text-blue-700' : 'text-gray-500']">Con la entrega del nuevo</button>
      </div>
      <input v-if="modo === 'programada'" v-model="fecha" type="date" :min="hoy" :class="inputCls" />
      <div class="flex gap-2">
        <button type="button" @click="reprogramando = false" class="flex-1 bg-white border border-gray-300 text-gray-700 rounded-lg py-1.5 text-xs font-semibold">Cancelar</button>
        <button type="button" @click="confirmarReprogramar" :disabled="guardando"
          class="flex-1 bg-blue-600 text-white rounded-lg py-1.5 text-xs font-semibold disabled:opacity-50">{{ guardando ? 'Guardando...' : 'Guardar' }}</button>
      </div>
    </div>
  </div>
</template>
