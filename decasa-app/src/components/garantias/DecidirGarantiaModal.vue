<script setup>
/**
 * El dictamen de una garantía.
 *
 * Cinco salidas, las del anexo: arreglarlo en el taller, arreglarlo en la
 * casa, cambiarlo por otro igual, cambiarlo por otro producto, o "no
 * procede" con su causal. El cambio consulta el inventario de verdad —cuántos
 * libres hay en cada tienda— y, si no hay en ninguna, se puede mandar a
 * fabricar. Cambiar por otro producto mueve el valor de la orden: solo un
 * supervisor, y el precio lo pone él (por defecto el de lista).
 */
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/api'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { getOpcionesGarantia, getStockParaCambio, decidirGarantia } from '@/api/garantias'
import { PREFERENCIA_TEXTO, fechaCorta, pesos } from './garantias'
import {
  XMarkIcon, WrenchScrewdriverIcon, HomeIcon, ArrowPathIcon, ArrowsRightLeftIcon, NoSymbolIcon,
  MagnifyingGlassIcon, BanknotesIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({ garantia: { type: Object, required: true } })
const emit  = defineEmits(['cerrar', 'decidida'])

const auth  = useAuthStore()
const toast = useToast()
const g     = computed(() => props.garantia)

const opciones = ref({ causales: [], procesos: [], personas: [] })
onMounted(async () => {
  try { opciones.value = (await getOpcionesGarantia()).data } catch {}
  if (g.value.dentro_de_garantia === false) causal.value = 'vencida'
  // Lo que el cliente prefirió, preseleccionado: casi siempre es lo que se hace.
  decision.value = { arreglar: 'taller', cambiar_mismo: 'cambio_mismo',
    cambiar_otro: auth.isSupervisor ? 'cambio_otro' : '', reembolso: auth.isSupervisor ? 'reembolso' : '' }[g.value.preferencia_cliente] || ''
  // Lo que pagó por esas unidades; si ya se había aprobado, lo aprobado.
  montoReembolso.value = g.value.monto_reembolso ?? g.value.monto_sugerido ?? 0
})

const DECISIONES = computed(() => [
  { v: 'taller',       t: 'Arreglar en el taller', icon: WrenchScrewdriverIcon },
  { v: 'domicilio',    t: 'Arreglar en la casa',   icon: HomeIcon },
  { v: 'cambio_mismo', t: 'Otro igual',            icon: ArrowPathIcon },
  { v: 'cambio_otro',  t: 'Otro producto',         icon: ArrowsRightLeftIcon, soloSupervisor: true },
  { v: 'reembolso',    t: 'Devolver la plata',     icon: BanknotesIcon,       soloSupervisor: true },
  { v: 'no_procede',   t: 'No procede',            icon: NoSymbolIcon },
])

const decision  = ref('')
const notas     = ref('')
const procesos  = ref([])
const visitaPor = ref('')
const visitaFecha = ref(new Date(Date.now() + 86400000).toISOString().slice(0, 10))
const causal    = ref('')
const montoReembolso = ref(0)
const guardando = ref(false)

function alternarProceso(clave) {
  const i = procesos.value.indexOf(clave)
  i === -1 ? procesos.value.push(clave) : procesos.value.splice(i, 1)
}

// ── Inventario para el cambio ───────────────────────────────────────────────
const stock      = ref(null)   // respuesta de /garantias/stock
const cargandoStock = ref(false)
const tiendaId   = ref(null)
const fabricar   = ref(false)
const varianteId = ref(null)
const comboId    = ref(null)
const precio     = ref(0)

async function cargarStock(productoId, { conPrecio = false } = {}) {
  cargandoStock.value = true
  try {
    const { data } = await getStockParaCambio({
      producto_id: productoId,
      variante_id: varianteId.value || undefined,
      combo_config_id: comboId.value || undefined,
    })
    stock.value = data
    if (conPrecio) precio.value = data.precio_sugerido
    // Si la tienda escogida ya no alcanza, se suelta.
    const t = data.tiendas.find(x => x.tienda_id === tiendaId.value)
    if (!t || t.libres < g.value.cantidad) tiendaId.value = null
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo consultar el inventario.')
  } finally {
    cargandoStock.value = false
  }
}

// "Otro igual" de catálogo: la misma tela/opción del renglón.
watch(decision, (d) => {
  tiendaId.value = null; fabricar.value = false; stock.value = null
  if (d === 'cambio_mismo' && !g.value.va_al_taller && g.value.producto_id) {
    varianteId.value = g.value.variante_id; comboId.value = g.value.combo_config_id
    cargarStock(g.value.producto_id)
  }
  if (d === 'cambio_otro') { productoNuevo.value = null; varianteId.value = null; comboId.value = null }
  // Hacerlo de nuevo es todo el flujo: se ofrecen todos los procesos marcados.
  if (d === 'cambio_mismo' && g.value.va_al_taller && !procesos.value.length) {
    procesos.value = opciones.value.procesos.map(p => p.clave)
  }
})

// ── Otro producto ───────────────────────────────────────────────────────────
const busqueda   = ref('')
const resultados = ref([])
const productoNuevo = ref(null)
let tBusqueda = null
watch(busqueda, (q) => {
  clearTimeout(tBusqueda)
  if (q.trim().length < 2) { resultados.value = []; return }
  tBusqueda = setTimeout(async () => {
    try {
      const { data } = await api.get('/productos', { params: { search: q.trim(), limit: 12 }, silencioso: true })
      resultados.value = data
    } catch { resultados.value = [] }
  }, 300)
})
function escogerProducto(p) {
  productoNuevo.value = p
  busqueda.value = ''; resultados.value = []
  varianteId.value = null; comboId.value = null; tiendaId.value = null; fabricar.value = false
  cargarStock(p.id, { conPrecio: true })
}
function cambioVariante() {
  if (productoNuevo.value) cargarStock(productoNuevo.value.id, { conPrecio: true })
}

const valorViejo = computed(() => Number(g.value.precio_unitario || 0) * g.value.cantidad)
const diferencia = computed(() => Number(precio.value || 0) * g.value.cantidad - valorViejo.value)

// ── Guardar ─────────────────────────────────────────────────────────────────
const listo = computed(() => {
  switch (decision.value) {
    case 'taller':       return true
    case 'domicilio':    return !!visitaPor.value && !!visitaFecha.value
    case 'cambio_mismo': return g.value.va_al_taller || fabricar.value || !!tiendaId.value
    case 'cambio_otro':  return !!productoNuevo.value && (fabricar.value || !!tiendaId.value) && precio.value !== '' && precio.value !== null
    case 'no_procede':   return !!causal.value
    case 'reembolso':    return montoReembolso.value !== '' && montoReembolso.value !== null
    default:             return false
  }
})

async function guardar() {
  if (!listo.value || guardando.value) return
  const payload = { decision: decision.value, notas: notas.value.trim() || null }
  if (decision.value === 'taller' || (decision.value === 'cambio_mismo' && g.value.va_al_taller)) {
    payload.procesos = procesos.value
  }
  if (decision.value === 'domicilio') {
    payload.visita_por_id = visitaPor.value
    payload.visita_fecha  = visitaFecha.value
  }
  if (decision.value === 'cambio_mismo' && !g.value.va_al_taller) {
    payload.fabricar  = fabricar.value
    payload.tienda_id = fabricar.value ? null : tiendaId.value
  }
  if (decision.value === 'cambio_otro') {
    Object.assign(payload, {
      producto_id: productoNuevo.value.id,
      variante_id: varianteId.value || null,
      combo_config_id: comboId.value || null,
      fabricar: fabricar.value,
      tienda_id: fabricar.value ? null : tiendaId.value,
      precio_unitario: Number(precio.value) || 0,
    })
  }
  if (decision.value === 'no_procede') payload.causal = causal.value
  if (decision.value === 'reembolso') payload.monto = Number(montoReembolso.value) || 0

  guardando.value = true
  try {
    const { data } = await decidirGarantia(g.value.id, payload)
    toast.success({
      taller:       data.estado === 'en_taller' ? 'Entró al taller: ya aparece en el tablero' : 'Listo. Cuando llegue el mueble, márcalo como recibido',
      domicilio:    'Visita programada; ya le llegó el aviso',
      cambio_mismo: 'Cambio registrado',
      cambio_otro:  'Cambio registrado; el saldo de la orden se ajustó',
      no_procede:   'Cerrada como no procede',
      reembolso:    'Aprobado. La plata sale cuando devuelva el producto',
    }[decision.value])
    emit('decidida', data)
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg shadow-2xl max-h-[92vh] flex flex-col">
        <div class="flex items-start gap-3 px-5 py-4 border-b border-gray-100">
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-800">Dictamen de garantía</p>
            <p class="text-[11px] text-gray-400 truncate">
              <span v-if="g.cantidad > 1">{{ g.cantidad }}× </span>{{ g.producto }} · {{ g.orden_referencia }} · {{ g.cliente }}
            </p>
          </div>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4 overflow-y-auto">
          <!-- Lo que hay que saber para decidir -->
          <div class="rounded-xl border border-gray-200 p-3 space-y-1">
            <p class="text-sm text-gray-700">{{ g.motivo }}</p>
            <div v-if="g.fotos?.length" class="flex gap-1.5 pt-1">
              <a v-for="f in g.fotos" :key="f" :href="f" target="_blank" rel="noopener">
                <img :src="f" class="w-12 h-12 rounded-lg object-cover border border-gray-100" />
              </a>
            </div>
            <p class="text-[11px] text-gray-500">
              Entregado el {{ fechaCorta(g.fecha_entrega) || '—' }}
              <template v-if="g.vence_el">
                · <span :class="g.dentro_de_garantia ? 'text-green-700 font-semibold' : 'text-red-600 font-semibold'">
                  {{ g.dentro_de_garantia ? 'vigente' : 'vencida' }} ({{ fechaCorta(g.vence_el) }})
                </span>
              </template>
              <template v-else> · sin plazo propio: lo valoras tú</template>
            </p>
            <p v-if="g.preferencia_cliente" class="text-[11px] text-gray-500">
              El cliente pide: <strong>{{ PREFERENCIA_TEXTO[g.preferencia_cliente] }}</strong>
              · el mueble está {{ g.donde_esta === 'tienda' ? 'en la tienda' : 'en su casa' }}
            </p>
            <p v-else class="text-[11px] text-gray-500">El mueble está {{ g.donde_esta === 'tienda' ? 'en la tienda' : 'en la casa del cliente' }}</p>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <button
              v-for="d in DECISIONES" :key="d.v" type="button"
              :disabled="d.soloSupervisor && !auth.isSupervisor"
              @click="decision = d.v"
              :class="['flex items-center gap-2 text-left text-xs font-semibold rounded-xl px-3 py-2.5 border-2 transition-colors disabled:opacity-40',
                decision === d.v ? 'border-blue-500 text-blue-800' : 'border-gray-200 text-gray-700 hover:border-gray-300']"
            >
              <component :is="d.icon" class="w-4 h-4 shrink-0" />
              {{ d.t }}
            </button>
          </div>
          <p v-if="!auth.isSupervisor" class="text-[11px] text-gray-400 -mt-2">Cambiar por otro producto o devolver la plata mueve el valor de la orden: lo decide un supervisor.</p>

          <!-- Taller / fabricar de nuevo: qué procesos -->
          <div v-if="decision === 'taller' || (decision === 'cambio_mismo' && g.va_al_taller)">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">
              {{ decision === 'taller' ? '¿Qué hay que rehacer?' : 'Se fabrica de nuevo. ¿Con qué procesos?' }}
            </label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="p in opciones.procesos" :key="p.clave" type="button" @click="alternarProceso(p.clave)"
                :class="['text-xs font-semibold rounded-full px-3 py-1 border transition-colors',
                  procesos.includes(p.clave) ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600']"
              >{{ p.nombre }}</button>
            </div>
            <p class="text-[11px] text-gray-400 mt-1.5">
              Despacho va siempre al final. Los pasos de fabricación que ya tiene se conservan.
              <template v-if="decision === 'taller' && g.donde_esta !== 'tienda'">
                Como el mueble está en la casa, los pasos se arman cuando llegue al taller.
              </template>
            </p>
          </div>

          <!-- Domicilio -->
          <div v-if="decision === 'domicilio'" class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Quién va?</label>
              <select v-model="visitaPor" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
                <option value="" disabled>Escoge…</option>
                <option v-for="p in opciones.personas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué día?</label>
              <input v-model="visitaFecha" type="date" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
            </div>
          </div>

          <!-- Otro producto: buscarlo -->
          <div v-if="decision === 'cambio_otro'" class="space-y-2">
            <div v-if="!productoNuevo" class="relative">
              <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input v-model="busqueda" placeholder="Buscar el producto nuevo…" class="w-full rounded-xl border border-gray-200 pl-9 pr-3 py-2 text-sm" />
              <div v-if="resultados.length" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-lg max-h-56 overflow-y-auto">
                <button v-for="p in resultados" :key="p.id" type="button" @click="escogerProducto(p)"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 flex justify-between gap-2">
                  <span class="truncate">{{ p.nombre }}</span>
                  <span class="text-[11px] text-gray-400 shrink-0">{{ p.categoria }}</span>
                </button>
              </div>
            </div>
            <div v-else class="flex items-center justify-between rounded-xl border border-gray-200 px-3 py-2">
              <span class="text-sm font-semibold text-gray-800 truncate">{{ productoNuevo.nombre }}</span>
              <button type="button" @click="productoNuevo = null; stock = null" class="text-xs text-blue-600">Cambiar</button>
            </div>
            <div v-if="productoNuevo && stock && (stock.variantes.length || stock.opciones.length)" class="grid grid-cols-2 gap-2">
              <select v-if="stock.variantes.length" v-model="varianteId" @change="cambioVariante" class="rounded-xl border border-gray-200 px-3 py-2 text-sm">
                <option :value="null">Sin tela / variante</option>
                <option v-for="v in stock.variantes" :key="v.id" :value="v.id">{{ v.texto }}</option>
              </select>
              <select v-if="stock.opciones.length" v-model="comboId" @change="cambioVariante" class="rounded-xl border border-gray-200 px-3 py-2 text-sm">
                <option :value="null">Sin opción</option>
                <option v-for="o in stock.opciones" :key="o.id" :value="o.id">{{ o.texto }}</option>
              </select>
            </div>
          </div>

          <!-- De qué tienda sale (otro igual de catálogo, u otro producto) -->
          <div v-if="(decision === 'cambio_mismo' && !g.va_al_taller) || (decision === 'cambio_otro' && productoNuevo)">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">
              ¿De dónde sale? <span class="font-normal text-gray-400">— libres de verdad (lo apartado para otras órdenes no cuenta)</span>
            </label>
            <AppSpinner v-if="cargandoStock" />
            <div v-else class="space-y-1.5">
              <button
                v-for="t in stock?.tiendas ?? []" :key="t.tienda_id" type="button"
                :disabled="t.libres < g.cantidad"
                @click="tiendaId = t.tienda_id; fabricar = false"
                :class="['w-full flex justify-between items-center rounded-xl px-3 py-2 border-2 text-sm transition-colors disabled:opacity-40',
                  tiendaId === t.tienda_id && !fabricar ? 'border-teal-500' : 'border-gray-200']"
              >
                <span class="text-gray-800">{{ t.nombre }}</span>
                <span :class="['text-xs font-semibold rounded-full px-2 py-0.5', t.libres >= g.cantidad ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600']">
                  {{ t.libres }} libre{{ t.libres === 1 ? '' : 's' }}
                </span>
              </button>
              <p v-if="stock && !stock.tiendas.some(t => t.libres >= g.cantidad)" class="text-[11px] text-amber-700">
                No hay {{ g.cantidad > 1 ? `${g.cantidad} libres` : 'uno libre' }} en ninguna tienda.
              </p>
              <button
                type="button" @click="fabricar = true; tiendaId = null"
                :class="['w-full text-left rounded-xl px-3 py-2 border-2 text-sm transition-colors',
                  fabricar ? 'border-teal-500' : 'border-gray-200']"
              >
                <span class="font-semibold text-gray-800">Mandarlo a fabricar</span>
                <span class="block text-[11px] text-gray-500">Queda en producción como un pedido para fabricar.</span>
              </button>
            </div>
          </div>

          <!-- Precio del nuevo -->
          <div v-if="decision === 'cambio_otro' && productoNuevo">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Precio unitario que se le cobra</label>
            <InputPesos v-model="precio" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
            <p class="text-[11px] mt-1" :class="diferencia > 0.5 ? 'text-amber-700' : diferencia < -0.5 ? 'text-green-700' : 'text-gray-500'">
              Lo dañado valía {{ pesos(valorViejo) }}.
              <template v-if="diferencia > 0.5">El cliente paga la diferencia: {{ pesos(diferencia) }}.</template>
              <template v-else-if="diferencia < -0.5">Le quedan a favor {{ pesos(diferencia) }}.</template>
              <template v-else>Sin diferencia.</template>
              Sugerido: {{ pesos(stock?.precio_sugerido) }}.
            </p>
          </div>

          <!-- Devolver la plata -->
          <div v-if="decision === 'reembolso'">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuánto se le devuelve?</label>
            <InputPesos v-model="montoReembolso" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
            <p class="text-[11px] text-gray-500 mt-1">
              Sugerido: {{ pesos(g.monto_sugerido) }}, que es lo que pagó por {{ g.cantidad > 1 ? 'esas unidades' : 'esa unidad' }}
              (con descuentos y lo que aún debiera). Puedes poner otro valor.
            </p>
            <p class="text-[11px] text-gray-500 mt-1">
              La plata no sale todavía: sale cuando el cliente devuelva el producto y lo registres.
              Ahí deja de contar como venta (meta y comisión pendiente); una comisión ya pagada no cambia.
            </p>
          </div>

          <!-- No procede -->
          <div v-if="decision === 'no_procede'">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Por qué no procede? (anexo de garantías)</label>
            <select v-model="causal" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
              <option value="" disabled>Escoge la causal…</option>
              <option v-for="c in opciones.causales" :key="c.clave" :value="c.clave">{{ c.texto }}</option>
            </select>
            <p class="text-[11px] text-gray-400 mt-1">Si el cliente quiere el arreglo pagado, se le hace una orden de restauración.</p>
          </div>

          <div v-if="decision">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas <span class="font-normal text-gray-400">— quedan en la orden</span></label>
            <textarea v-model="notas" rows="2" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm resize-none" />
          </div>
        </div>

        <div class="flex gap-2.5 p-5 pt-3 border-t border-gray-100">
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5">Cancelar</button>
          <button @click="guardar" :disabled="!listo || guardando"
            class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 disabled:opacity-50">
            {{ guardando ? 'Guardando...' : 'Guardar dictamen' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
