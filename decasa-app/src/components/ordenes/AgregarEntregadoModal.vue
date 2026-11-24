<script setup>
/**
 * Agregar a una orden YA ENTREGADA lo que se le olvidó al vendedor (solo super
 * admin, usuario 2026-11-24).
 *
 * El cliente se llevó todo, pero en la orden falta algo que sí se le agregó:
 * la orden vale menos de lo vendido y no cuadran las bonificaciones. Aquí se
 * agrega ya entregado: la orden sube y sigue entregada. Si salió de una tienda
 * baja ese inventario; si no, no toca stock. Ver App\Services\AgregarProductoEntregado.
 */
import { ref, computed, watch } from 'vue'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { getStockParaCambio } from '@/api/garantias'
import { agregarProductoEntregado } from '@/api/ordenes'
import { pesos } from '@/components/garantias/garantias'
import { XMarkIcon, MagnifyingGlassIcon, PlusCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps({ orden: { type: Object, required: true } })
const emit  = defineEmits(['cerrar', 'hecho'])
const toast = useToast()

// Una restauración solo recibe otro trabajo: ventas y restauraciones van en
// órdenes separadas (el servidor lo frena igual).
const esRestauracion = computed(() => props.orden.serie === 'R'
  || ((props.orden.items ?? []).length > 0 && (props.orden.items ?? []).every(i => i.es_restauracion)))

const tipo     = ref(esRestauracion.value ? 'otro' : 'catalogo')
const cantidad = ref(1)
const precio   = ref(0)
const motivo   = ref('')
const nombre   = ref('')

// Del catálogo
const busqueda      = ref('')
const resultados    = ref([])
const producto      = ref(null)
const stock         = ref(null)
const cargandoStock = ref(false)
const varianteId    = ref(null)
const comboId       = ref(null)
const tiendaId      = ref(null)
const saleDe        = ref('tienda')   // 'tienda' | 'ninguna'

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

async function cargarStock({ conPrecio = false } = {}) {
  if (!producto.value) return
  cargandoStock.value = true
  try {
    const { data } = await getStockParaCambio({
      producto_id: producto.value.id,
      variante_id: varianteId.value || undefined,
      combo_config_id: comboId.value || undefined,
    })
    stock.value = data
    if (conPrecio) precio.value = data.precio_sugerido
    // Por defecto, la tienda de la orden si tiene libres.
    const t = data.tiendas.find(x => x.tienda_id === tiendaId.value)
    if (!t || t.libres < cantidad.value) {
      const deLaOrden = data.tiendas.find(x => x.tienda_id === props.orden.tienda_id && x.libres >= cantidad.value)
      tiendaId.value = deLaOrden?.tienda_id ?? null
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo consultar el inventario.')
  } finally {
    cargandoStock.value = false
  }
}

function escogerProducto(p) {
  producto.value = p
  busqueda.value = ''; resultados.value = []
  varianteId.value = null; comboId.value = null; tiendaId.value = null
  cargarStock({ conPrecio: true })
}
function quitarProducto() {
  producto.value = null; stock.value = null; tiendaId.value = null
}
watch(cantidad, (n) => {
  const t = stock.value?.tiendas.find(x => x.tienda_id === tiendaId.value)
  if (t && t.libres < n) tiendaId.value = null
})

const valorNuevo  = computed(() => (Number(precio.value) || 0) * (Number(cantidad.value) || 0))
const totalDespues = computed(() => (Number(props.orden.valor_total) || 0) + valorNuevo.value)
const saldoDespues = computed(() => (Number(props.orden.saldo_pendiente) || 0) + valorNuevo.value)

const falta = computed(() => {
  if (!(Number(cantidad.value) >= 1)) return 'Cuántos se llevó.'
  if (tipo.value === 'catalogo') {
    if (!producto.value) return 'Busca el producto.'
    if (saleDe.value === 'tienda' && !tiendaId.value) return 'Escoge de qué tienda salió, o marca que no salió del inventario.'
  } else if (nombre.value.trim().length < 2) {
    return 'Escribe qué es lo que se agrega.'
  }
  if (!(Number(precio.value) > 0)) return 'Ponle precio.'
  if (motivo.value.trim().length < 3) return 'Escribe por qué se agrega.'
  return null
})

const guardando = ref(false)
async function guardar() {
  if (falta.value) { toast.error(falta.value); return }
  if (guardando.value) return
  guardando.value = true
  try {
    const payload = {
      tipo: tipo.value,
      cantidad: Number(cantidad.value),
      precio_unitario: Number(precio.value) || 0,
      motivo: motivo.value.trim(),
    }
    if (tipo.value === 'catalogo') {
      Object.assign(payload, {
        producto_id: producto.value.id,
        variante_id: varianteId.value || null,
        combo_config_id: comboId.value || null,
        sale_de: saleDe.value,
        tienda_id: saleDe.value === 'tienda' ? tiendaId.value : null,
      })
    } else {
      payload.nombre = nombre.value.trim()
    }
    const { data } = await agregarProductoEntregado(props.orden.id, payload)
    toast.success(data.message, 8000)
    // Avisos, no errores: lo que queda pendiente (saldo, factura, bonificación pagada).
    for (const a of data?.avisos ?? []) toast.info(a, 12000)
    emit('hecho', data)
  } catch (e) {
    toast.error(e.response?.data?.message
      || Object.values(e.response?.data?.errors ?? {}).flat()[0]
      || 'No se pudo agregar el producto.', 8000)
  } finally {
    guardando.value = false
  }
}

const inputCls = 'w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg shadow-2xl max-h-[92vh] flex flex-col">
        <div class="flex items-start gap-3 px-5 py-4 border-b border-gray-100">
          <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center flex-shrink-0">
            <PlusCircleIcon class="w-5 h-5" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-800">Agregar lo que se olvidó</p>
            <p class="text-[11px] text-gray-400 truncate">
              {{ orden.referencia }} · {{ orden.cliente?.nombre }} · ya entregada
            </p>
          </div>
          <button @click="emit('cerrar')" aria-label="Cerrar" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4 overflow-y-auto">
          <p class="text-[11px] text-gray-500">
            El cliente ya se lo llevó, pero no quedó en la orden. Entra ya entregado: la orden sube y sigue entregada.
          </p>

          <!-- Qué es -->
          <div v-if="!esRestauracion" class="flex bg-gray-100 rounded-xl p-1">
            <button type="button" @click="tipo = 'catalogo'"
              :class="['flex-1 text-xs font-semibold rounded-lg py-1.5', tipo === 'catalogo' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
              Del catálogo
            </button>
            <button type="button" @click="tipo = 'otro'"
              :class="['flex-1 text-xs font-semibold rounded-lg py-1.5', tipo === 'otro' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
              Otro
            </button>
          </div>
          <p v-else class="text-[11px] text-gray-500">
            Es una orden de restauración: aquí se agrega otro trabajo. Un producto del catálogo va en una orden de venta aparte.
          </p>

          <!-- Del catálogo -->
          <template v-if="tipo === 'catalogo'">
            <div v-if="!producto">
              <div class="relative">
                <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input v-model="busqueda" placeholder="Buscar el producto…" class="w-full rounded-xl border border-gray-200 pl-9 pr-3 py-2 text-sm" />
              </div>
              <div v-if="resultados.length" class="mt-1 bg-white border border-gray-200 rounded-xl max-h-56 overflow-y-auto">
                <button v-for="p in resultados" :key="p.id" type="button" @click="escogerProducto(p)"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 flex justify-between gap-2">
                  <span class="truncate">{{ p.nombre }}</span>
                  <span class="text-[11px] text-gray-400 shrink-0">{{ p.categoria }}</span>
                </button>
              </div>
            </div>
            <div v-else class="flex items-center justify-between rounded-xl border border-gray-200 px-3 py-2">
              <span class="text-sm font-semibold text-gray-800 truncate">{{ producto.nombre }}</span>
              <button type="button" @click="quitarProducto" class="text-xs text-blue-600 font-semibold">Cambiar</button>
            </div>

            <div v-if="producto && stock && (stock.variantes.length || stock.opciones.length)" class="grid grid-cols-2 gap-2">
              <select v-if="stock.variantes.length" v-model="varianteId" @change="cargarStock({ conPrecio: true })" :class="inputCls">
                <option :value="null">Sin tela / variante</option>
                <option v-for="v in stock.variantes" :key="v.id" :value="v.id">{{ v.texto }}</option>
              </select>
              <select v-if="stock.opciones.length" v-model="comboId" @change="cargarStock({ conPrecio: true })" :class="inputCls">
                <option :value="null">Sin opción</option>
                <option v-for="o in stock.opciones" :key="o.id" :value="o.id">{{ o.texto }}</option>
              </select>
            </div>

            <div v-if="producto">
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿De dónde salió?</label>
              <AppSpinner v-if="cargandoStock" />
              <div v-else class="space-y-1.5">
                <button
                  v-for="t in stock?.tiendas ?? []" :key="t.tienda_id" type="button"
                  :disabled="t.libres < cantidad"
                  @click="tiendaId = t.tienda_id; saleDe = 'tienda'"
                  :class="['w-full flex justify-between items-center rounded-xl px-3 py-2 border-2 text-sm transition-colors disabled:opacity-40',
                    saleDe === 'tienda' && tiendaId === t.tienda_id ? 'border-blue-500' : 'border-gray-200']"
                >
                  <span class="text-gray-800">{{ t.nombre }}</span>
                  <span :class="['text-xs font-semibold rounded-full px-2 py-0.5', t.libres >= cantidad ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600']">
                    {{ t.libres }} libre{{ t.libres === 1 ? '' : 's' }}
                  </span>
                </button>
                <button
                  type="button" @click="saleDe = 'ninguna'; tiendaId = null"
                  :class="['w-full text-left rounded-xl px-3 py-2 border-2 text-sm transition-colors', saleDe === 'ninguna' ? 'border-blue-500' : 'border-gray-200']"
                >
                  <span class="block font-semibold text-gray-800">No salió del inventario</span>
                  <span class="block text-[11px] text-gray-500">Ya se había descontado, se fabricó o se consiguió aparte. No toca el stock.</span>
                </button>
                <p v-if="saleDe === 'tienda'" class="text-[11px] text-gray-400">
                  Baja del inventario de esa tienda, como cualquier venta entregada.
                </p>
              </div>
            </div>
          </template>

          <!-- Otro -->
          <input v-if="tipo === 'otro'" v-model="nombre" maxlength="200" :class="inputCls"
            :placeholder="esRestauracion ? 'Qué trabajo. Ej: Tapizado de 2 cojines' : 'Qué es. Ej: Cojín decorativo'" />

          <!-- Cantidad y precio -->
          <div v-if="tipo === 'otro' || producto" class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">Cantidad</label>
              <input v-model.number="cantidad" type="number" min="1" :class="inputCls" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">Precio unitario</label>
              <InputPesos v-model="precio" :class="inputCls" />
            </div>
            <p v-if="tipo === 'catalogo' && stock" class="col-span-2 text-[11px] text-gray-400 -mt-1">
              Precio de lista: {{ pesos(stock.precio_sugerido) }}.
            </p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Por qué se agrega</label>
            <textarea v-model="motivo" rows="2" maxlength="300" :class="inputCls + ' resize-none'"
              placeholder="Ej: se le agregó la silla en la tienda y el vendedor no la puso en la orden" />
          </div>

          <!-- La cuenta -->
          <section v-if="valorNuevo > 0" class="rounded-xl border border-gray-200 p-3 space-y-1 text-xs">
            <div class="flex justify-between text-gray-600">
              <span>Total de hoy</span><span>{{ pesos(orden.valor_total) }}</span>
            </div>
            <div class="flex justify-between text-gray-600">
              <span>Lo que se agrega</span><span>+ {{ pesos(valorNuevo) }}</span>
            </div>
            <div class="flex justify-between font-semibold text-gray-800 pt-1 border-t border-gray-100">
              <span>Total nuevo</span><span>{{ pesos(totalDespues) }}</span>
            </div>
            <p class="text-[11px] text-gray-500 pt-1">
              <template v-if="saldoDespues > 0.5">Queda debiendo <strong>{{ pesos(saldoDespues) }}</strong>. Si ya lo pagó, regístralo después con "Registrar pago".</template>
              <template v-else-if="saldoDespues < -0.5">Le quedan <strong>{{ pesos(-saldoDespues) }}</strong> a favor.</template>
              <template v-else>Queda a paz y salvo.</template>
            </p>
          </section>

          <ul class="text-[11px] text-gray-500 space-y-1 list-disc pl-4">
            <li>La bonificación sigue el valor nuevo. Si ya se pagó, la diferencia sale en el próximo pago.</li>
            <li>Si la orden no se ha facturado, la factura sale con esto. Si ya se facturó, queda en Facturación → "Con diferencias".</li>
            <li>Queda en el historial de la orden y se le avisa a quien la vendió.</li>
          </ul>
        </div>

        <div class="flex gap-2.5 p-5 pt-3 border-t border-gray-100">
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5">Cancelar</button>
          <button @click="guardar" :disabled="!!falta || guardando" :title="falta || ''"
            class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 disabled:opacity-50">
            {{ guardando ? 'Guardando…' : 'Agregar a la orden' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
