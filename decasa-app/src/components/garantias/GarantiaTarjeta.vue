<script setup>
/**
 * Una garantía con su historia y lo que toca hacer ahora.
 *
 * La misma tarjeta sale en la orden (para el vendedor, que es a quien el
 * cliente llama) y en la bandeja del taller (para quien decide). Los botones
 * dependen del estado y de quién mira: el dictamen es de quien gestiona el
 * taller o un supervisor; "ya llegó" lo marca también quien despacha; la
 * visita la registra quien fue.
 */
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { recibirGarantiaEnTaller, registrarVisitaGarantia, recibirDevolucionGarantia } from '@/api/garantias'
import { getTiendas } from '@/api/ordenes'
import { comprimirImagen, comprimirAlTomar } from '@/utils/comprimirImagen'
import DecidirGarantiaModal from './DecidirGarantiaModal.vue'
import { ESTADOS, DECISION_TEXTO, PREFERENCIA_TEXTO, fechaCorta, pesos, diasPara } from './garantias'
import { ShieldCheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  garantia:    { type: Object, required: true },
  mostrarOrden: { type: Boolean, default: false },
})
const emit = defineEmits(['cambio'])

const router = useRouter()
const auth   = useAuthStore()
const toast  = useToast()
const g      = computed(() => props.garantia)

const puedeDecidir = computed(() => auth.isSupervisor || auth.gestionaProduccion)
const estado       = computed(() => ESTADOS[g.value.estado] ?? { texto: g.value.estado, cls: 'bg-gray-200 text-gray-700' })

// Plazo legal para responder (15 días hábiles) mientras no haya dictamen.
const diasRespuesta = computed(() => g.value.estado === 'pendiente' ? diasPara(g.value.responder_antes_de) : null)
// Los 30 días del anexo desde que el taller lo recibe.
const diasDevolver  = computed(() => g.value.estado === 'en_taller' ? diasPara(g.value.devolver_antes_de) : null)

const decidiendo = ref(false)
const enviando   = ref(false)

async function recibir() {
  if (!confirm(`¿"${g.value.producto}" ya llegó al taller? Se arman los pasos del arreglo.`)) return
  enviando.value = true
  try {
    await recibirGarantiaEnTaller(g.value.id)
    toast.success('Recibido: el arreglo ya aparece en el tablero')
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    enviando.value = false
  }
}

// ── Reembolso: llegó lo que devolvió el cliente ────────────────────────────
// La plata sale aquí, no al aprobarlo: así nunca se devuelve sin tener el
// mueble. Es mover plata, así que solo un supervisor.
const devolviendo  = ref(false)
const devMetodo    = ref('efectivo')
const devReferencia = ref('')
const devDestino   = ref('merma')
const devTienda    = ref('')
const tiendas      = ref([])

async function abrirDevolucion() {
  devolviendo.value = true
  if (!tiendas.value.length) {
    try { tiendas.value = (await getTiendas()).data.filter(t => t.activa !== false && !t.es_independientes) } catch {}
  }
}

async function guardarDevolucion() {
  if (devDestino.value === 'inventario' && !devTienda.value) { toast.error('Escoge a qué tienda vuelve.'); return }
  enviando.value = true
  try {
    await recibirDevolucionGarantia(g.value.id, {
      metodo: devMetodo.value,
      referencia: devReferencia.value.trim() || null,
      destino: devDestino.value,
      tienda_id: devDestino.value === 'inventario' ? devTienda.value : null,
    })
    toast.success('Listo: se registró la devolución de la plata')
    devolviendo.value = false
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    enviando.value = false
  }
}

// ── Visita a domicilio ──────────────────────────────────────────────────────
const puedeVisita = computed(() => g.value.estado === 'a_domicilio'
  && (puedeDecidir.value || Number(g.value.visita_por_id) === Number(auth.usuario?.id)))
const visitando   = ref(false)
const visitaNotas = ref('')
const visitaFotos = ref([])

async function fotoVisita(e) {
  for (const original of Array.from(e.target.files ?? []).slice(0, 6 - visitaFotos.value.length)) {
    const file = await comprimirAlTomar(original)
    visitaFotos.value.push({ file, preview: URL.createObjectURL(file) })
  }
  e.target.value = ''
}

async function guardarVisita() {
  if (visitaNotas.value.trim().length < 3) { toast.error('Escribe qué se encontró y qué se hizo.'); return }
  enviando.value = true
  try {
    const urls = []
    for (const f of visitaFotos.value) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(f.file), 'visita.jpg')
      fd.append('folder', 'garantias')
      urls.push((await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.url)
    }
    await registrarVisitaGarantia(g.value.id, { notas: visitaNotas.value.trim(), fotos: urls })
    toast.success('Garantía resuelta')
    visitando.value = false
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div class="bg-white rounded-xl border border-gray-200 p-3">
    <div class="flex items-start gap-3">
      <a v-if="g.fotos?.length" :href="g.fotos[0]" target="_blank" rel="noopener" class="shrink-0">
        <img :src="g.fotos[0]" class="w-14 h-14 rounded-lg object-cover border border-gray-100" />
      </a>
      <div v-else class="w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center shrink-0">
        <ShieldCheckIcon class="w-6 h-6 text-gray-400" />
      </div>
      <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-2">
          <p class="text-sm font-semibold text-gray-800">
            <span v-if="g.cantidad > 1" class="text-blue-700">{{ g.cantidad }}× </span>{{ g.producto }}
          </p>
          <span :class="['text-[11px] font-semibold rounded-full px-2 py-0.5 shrink-0', estado.cls]">{{ estado.texto }}</span>
        </div>
        <button v-if="mostrarOrden" @click="router.push({ name: 'orden-detalle', params: { id: g.orden_id } })"
          class="text-[11px] text-blue-600 hover:text-blue-700">{{ g.orden_referencia }} · {{ g.cliente }}</button>
        <p class="text-xs text-gray-600 mt-0.5">{{ g.motivo }}</p>
        <p class="text-[11px] text-gray-400 mt-0.5">
          Reportada el {{ fechaCorta(g.fecha_reporte) }}<span v-if="g.reportado_por"> por {{ g.reportado_por }}</span>
          · entregado el {{ fechaCorta(g.fecha_entrega) || '—' }}
          <template v-if="g.vence_el">
            · <span :class="g.dentro_de_garantia ? 'text-green-700' : 'text-red-600 font-semibold'">
              {{ g.dentro_de_garantia ? 'vigente' : 'vencida' }} ({{ fechaCorta(g.vence_el) }})
            </span>
          </template>
        </p>
        <p v-if="g.preferencia_cliente && g.estado === 'pendiente'" class="text-[11px] text-gray-500">
          El cliente pide: {{ PREFERENCIA_TEXTO[g.preferencia_cliente] }} · está {{ g.donde_esta === 'tienda' ? 'en la tienda' : 'en su casa' }}
        </p>

        <!-- Plazos -->
        <p v-if="diasRespuesta !== null" :class="['text-[11px] font-semibold mt-1', diasRespuesta < 0 ? 'text-red-600' : diasRespuesta <= 3 ? 'text-amber-700' : 'text-gray-500']">
          {{ diasRespuesta < 0 ? `Se pasó el plazo para responder (${fechaCorta(g.responder_antes_de)})` : `Responder antes del ${fechaCorta(g.responder_antes_de)}` }}
        </p>
        <p v-if="diasDevolver !== null" :class="['text-[11px] font-semibold mt-1', diasDevolver < 0 ? 'text-red-600' : diasDevolver <= 5 ? 'text-amber-700' : 'text-gray-500']">
          En el taller desde el {{ fechaCorta(g.recibido_en_taller_at) }} · devolverlo antes del {{ fechaCorta(g.devolver_antes_de) }}
        </p>

        <!-- Lo que se decidió -->
        <div v-if="g.decision" class="mt-1.5 text-[11px] text-gray-600 border-t border-gray-100 pt-1.5 space-y-0.5">
          <p>
            <strong>{{ DECISION_TEXTO[g.decision] }}</strong><span v-if="g.decidido_por"> — {{ g.decidido_por }}</span>
          </p>
          <p v-if="g.estado === 'por_recoger'">Hay que recogerlo en la casa del cliente y traerlo al taller.</p>
          <p v-if="g.decision === 'domicilio' && g.visita_por">Va {{ g.visita_por }} el {{ fechaCorta(g.visita_fecha) }}.</p>
          <p v-if="g.producto_nuevo">Cambio por {{ g.producto_nuevo }}<template v-if="g.diferencia_valor">: {{ g.diferencia_valor > 0 ? 'paga la diferencia de' : 'le quedan a favor' }} {{ pesos(g.diferencia_valor) }}</template>.</p>
          <p v-if="g.decision === 'reembolso'">
            Se le devuelven <strong>{{ pesos(g.monto_reembolso) }}</strong>
            <template v-if="g.estado === 'por_devolver'"> cuando devuelva el producto.</template>
            <template v-else-if="g.destino_devuelto === 'inventario'">; lo devuelto volvió a {{ g.tienda_devuelto }}.</template>
            <template v-else-if="g.destino_devuelto">; lo devuelto salió como merma.</template>
          </p>
          <p v-if="g.causal_texto">Causal: {{ g.causal_texto }}</p>
          <p v-if="g.notas_decision" class="italic text-gray-500">{{ g.notas_decision }}</p>
          <p v-if="g.visita_notas">En la visita: {{ g.visita_notas }}</p>
          <p v-if="g.estado === 'en_taller' || g.estado === 'cambio'" class="text-gray-500">
            Se cierra sola cuando se le entregue {{ g.producto_nuevo ? 'el reemplazo' : 'el mueble' }} desde la orden (con acta).
          </p>
          <p v-if="g.estado === 'resuelta'" class="text-green-700 font-semibold">
            Resuelta el {{ fechaCorta(g.resuelta_at) }}<span v-if="g.resuelta_por"> — {{ g.resuelta_por }}</span>
          </p>
        </div>
      </div>
    </div>

    <div v-if="(puedeDecidir && ['pendiente', 'a_domicilio', 'por_devolver'].includes(g.estado)) || (g.estado === 'por_recoger' && (puedeDecidir || auth.puedeDespacho)) || puedeVisita || (g.estado === 'por_devolver' && auth.isSupervisor)"
      class="flex flex-wrap gap-2 mt-3">
      <button v-if="puedeDecidir && ['pendiente', 'a_domicilio', 'por_devolver'].includes(g.estado)" @click="decidiendo = true"
        class="flex-1 bg-blue-600 text-white text-xs font-semibold rounded-lg px-3 py-2 hover:bg-blue-700">
        {{ g.estado === 'pendiente' ? 'Dar dictamen' : 'Cambiar decisión' }}
      </button>
      <button v-if="g.estado === 'por_recoger' && (puedeDecidir || auth.puedeDespacho)" @click="recibir" :disabled="enviando"
        class="flex-1 bg-blue-600 text-white text-xs font-semibold rounded-lg px-3 py-2 hover:bg-blue-700 disabled:opacity-50">
        Ya llegó al taller
      </button>
      <button v-if="g.estado === 'por_devolver' && auth.isSupervisor" @click="abrirDevolucion"
        class="flex-1 bg-red-600 text-white text-xs font-semibold rounded-lg px-3 py-2 hover:bg-red-700">
        Llegó el producto: devolver la plata
      </button>
      <button v-if="puedeVisita" @click="visitando = true"
        class="flex-1 bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg px-3 py-2 hover:bg-gray-200">
        Registrar visita
      </button>
    </div>

    <DecidirGarantiaModal v-if="decidiendo" :garantia="g" @cerrar="decidiendo = false" @decidida="decidiendo = false; emit('cambio')" />

    <Teleport to="body">
      <div v-if="visitando" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="visitando = false">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl p-5 space-y-3">
          <div class="flex items-center justify-between">
            <p class="font-semibold text-gray-800">Visita de garantía</p>
            <button @click="visitando = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>
          <p class="text-[11px] text-gray-500">{{ g.producto }} · {{ g.cliente }}</p>
          <textarea v-model="visitaNotas" rows="3" placeholder="Qué se encontró y qué se hizo"
            class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm resize-none" />
          <div class="flex flex-wrap gap-2">
            <img v-for="f in visitaFotos" :key="f.preview" :src="f.preview" class="w-14 h-14 rounded-lg object-cover border border-gray-200" />
            <label class="w-14 h-14 flex items-center justify-center border-2 border-dashed border-gray-300 rounded-lg cursor-pointer text-lg">
              📷<input type="file" accept="image/*" multiple @change="fotoVisita" class="hidden" />
            </label>
          </div>
          <p class="text-[11px] text-gray-400">Si allá no se pudo arreglar, quien decide puede cambiar la decisión (traerlo al taller o cambiarlo).</p>
          <div class="flex gap-2.5">
            <button @click="visitando = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5">Cancelar</button>
            <button @click="guardarVisita" :disabled="enviando"
              class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 disabled:opacity-50">
              {{ enviando ? 'Guardando...' : 'Quedó arreglado' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="devolviendo" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="devolviendo = false">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div class="min-w-0">
              <p class="font-semibold text-gray-800">Devolver la plata</p>
              <p class="text-[11px] text-gray-500 truncate">{{ g.cantidad > 1 ? `${g.cantidad}× ` : '' }}{{ g.producto }} · {{ g.cliente }}</p>
            </div>
            <button @click="devolviendo = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <p class="text-2xl font-bold text-gray-800">{{ pesos(g.monto_reembolso) }}</p>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cómo se le devuelve?</label>
            <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
              <button v-for="m in [['efectivo', 'Efectivo'], ['transferencia', 'Transferencia'], ['otro', 'Otro']]" :key="m[0]" type="button" @click="devMetodo = m[0]"
                :class="['flex-1 text-xs font-semibold rounded-lg px-2 py-1.5', devMetodo === m[0] ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
                {{ m[1] }}
              </button>
            </div>
            <p v-if="devMetodo === 'efectivo'" class="text-[11px] text-gray-400 mt-1">Sale de la misma caja por donde entró la venta.</p>
            <input v-else v-model="devReferencia" placeholder="Referencia (opcional)" class="mt-2 w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué se hace con lo que devolvió?</label>
            <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
              <button type="button" @click="devDestino = 'merma'"
                :class="['flex-1 text-xs font-semibold rounded-lg px-2 py-1.5', devDestino === 'merma' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
                Merma (está dañado)
              </button>
              <button type="button" @click="devDestino = 'inventario'" :disabled="!g.producto_id"
                :class="['flex-1 text-xs font-semibold rounded-lg px-2 py-1.5 disabled:opacity-40', devDestino === 'inventario' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
                Vuelve al inventario
              </button>
            </div>
            <select v-if="devDestino === 'inventario'" v-model="devTienda" class="mt-2 w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
              <option value="" disabled>¿A qué tienda?</option>
              <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
            </select>
            <p v-if="!g.producto_id" class="text-[11px] text-gray-400 mt-1">No es de catálogo: no tiene inventario al cual volver.</p>
          </div>

          <p class="text-[11px] text-gray-500 border border-gray-200 rounded-xl px-3 py-2">
            Lo devuelto deja de contar como venta: baja el total de la orden, la meta y la comisión pendiente.
            Una comisión ya pagada no cambia. Si ya no le queda nada al cliente, la orden se cancela.
          </p>

          <div class="flex gap-2.5">
            <button @click="devolviendo = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5">Cancelar</button>
            <button @click="guardarDevolucion" :disabled="enviando"
              class="flex-1 bg-red-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 disabled:opacity-50">
              {{ enviando ? 'Guardando...' : 'Registrar devolución' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
