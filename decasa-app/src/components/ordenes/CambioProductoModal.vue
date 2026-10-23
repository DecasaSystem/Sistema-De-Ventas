<script setup>
/**
 * El cliente ya recibió el mueble y después dice que no le sirvió.
 *
 * No es garantía (no se dañó) ni lo que devuelve el conductor en la puerta: es
 * el respaldo para cuando, días después, lo quiere cambiar. En un solo paso se
 * escoge qué devuelve y qué se lleva:
 *  - del catálogo, de la tienda que lo tenga libre (se aparta),
 *  - del catálogo, para fabricar,
 *  - un diseño especial (entra a producción y pide su declaración),
 *  - o "lo decide después": la orden se reabre y el producto se agrega en
 *    Editar (solo con la orden entregada, como antes).
 *
 * Lo ya pagado se queda: si lo nuevo vale más, la venta sube y paga la
 * diferencia; si vale menos, le queda a favor. Lo hace un supervisor.
 */
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { getStockParaCambio } from '@/api/garantias'
import { cambiarProductoEntregado } from '@/api/ordenes'
import { getDestinosDevolucion } from '@/api/devoluciones'
import { comprimirImagen, comprimirAlTomar } from '@/utils/comprimirImagen'
import { pesos } from '@/components/garantias/garantias'
import {
  XMarkIcon, MagnifyingGlassIcon, BuildingStorefrontIcon, WrenchScrewdriverIcon, PencilIcon, ClockIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({ orden: { type: Object, required: true } })
const emit  = defineEmits(['cerrar', 'hecho'])
const toast = useToast()

const nombreItem = (i) => i.producto?.nombre ?? i.nombre_custom ?? 'Producto'

// ── Qué devuelve ────────────────────────────────────────────────────────────
// Solo lo que tiene en la casa. En una orden entregada es todo el renglón.
const enCasa = (i) => props.orden.estado === 'entregado'
  ? Math.max(Number(i.cantidad_entregada) || 0, Number(i.cantidad) || 0)
  : Number(i.cantidad_entregada) || 0

const devolvibles = computed(() =>
  (props.orden.items ?? []).filter(i => !i.devuelto_en && enCasa(i) > 0)
)

const itemId    = ref(devolvibles.value[0]?.id ?? null)
const item      = computed(() => devolvibles.value.find(i => i.id === itemId.value) ?? null)
const maxDev    = computed(() => item.value ? enCasa(item.value) : 0)
const cantidad  = ref(maxDev.value || 1)
const motivo    = ref('')

// ¿Puede volver a la venta? Lo de catálogo (y lo fabricado igual al
// catálogo). Un diseño especial puede llegar a una tienda o a la fábrica,
// pero no entra al inventario. La misma regla que el servidor.
const entraAlInventario = computed(() => !!item.value?.producto_id && !item.value?.producto_unico
  && (!item.value?.es_personalizado || !!item.value?.fabricar_pedido))

watch(itemId, () => {
  cantidad.value = maxDev.value || 1
  ponerDestinoPorDefecto()
})
watch(cantidad, (n) => { cantNueva.value = n })

// ── Qué se lleva ────────────────────────────────────────────────────────────
const permiteDespues = computed(() => props.orden.estado === 'entregado')
const OPCIONES = computed(() => [
  { v: 'catalogo',        t: 'Del catálogo',     d: 'De una tienda o para fabricar', icon: BuildingStorefrontIcon },
  { v: 'diseno_especial', t: 'Diseño especial',  d: 'A la medida, se fabrica',       icon: PencilIcon },
  ...(permiteDespues.value
    ? [{ v: 'despues', t: 'Lo decide después', d: 'Se reabre y se agrega en Editar', icon: ClockIcon }]
    : []),
])
const tipo      = ref('catalogo')
const cantNueva = ref(cantidad.value)
const precio    = ref(0)
const fechaEntrega = ref('')

// Del catálogo
const busqueda      = ref('')
const resultados    = ref([])
const productoNuevo = ref(null)
const stock         = ref(null)
const cargandoStock = ref(false)
const varianteId    = ref(null)
const comboId       = ref(null)
const tiendaId      = ref(null)
const fabricar      = ref(false)
// Lo que se manda a fabricar casi nunca sale idéntico: otras patas, otro
// acabado. Va al taller como las notas de Nueva orden.
const notasTaller   = ref('')

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
  if (!productoNuevo.value) return
  cargandoStock.value = true
  try {
    const { data } = await getStockParaCambio({
      producto_id: productoNuevo.value.id,
      variante_id: varianteId.value || undefined,
      combo_config_id: comboId.value || undefined,
    })
    stock.value = data
    if (conPrecio) precio.value = data.precio_sugerido
    // Si la tienda escogida ya no alcanza, se suelta.
    const t = data.tiendas.find(x => x.tienda_id === tiendaId.value)
    if (!t || t.libres < cantNueva.value) tiendaId.value = null
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo consultar el inventario.')
  } finally {
    cargandoStock.value = false
  }
}

function escogerProducto(p) {
  productoNuevo.value = p
  busqueda.value = ''; resultados.value = []
  varianteId.value = null; comboId.value = null; tiendaId.value = null; fabricar.value = false
  cargarStock({ conPrecio: true })
}
function quitarProducto() {
  productoNuevo.value = null; stock.value = null; tiendaId.value = null; fabricar.value = false
}
watch(cantNueva, (n) => {
  const t = stock.value?.tiendas.find(x => x.tienda_id === tiendaId.value)
  if (t && t.libres < n) tiendaId.value = null
})

// Diseño especial: lo mismo que pide la ficha de la declaración.
const especial = ref({ nombre: '', ancho: '', largo: '', alto: '', tela: '', madera: '', detalles: '' })
const bocetos  = ref([])   // { file, preview }

async function agregarBoceto(e) {
  for (const original of Array.from(e.target.files ?? [])) {
    if (bocetos.value.length >= 8) break
    const file = await comprimirAlTomar(original)
    bocetos.value.push({ file, preview: URL.createObjectURL(file) })
  }
  e.target.value = ''
}

// ── Lo que devuelve: a dónde va y cuándo se recoge ─────────────────────────
// La orden cambia de una al guardar. Lo que se puede dejar para después es
// traer el mueble: hasta que llegue no entra al inventario.
const destinos   = ref([])
const destinoSel = ref('')          // id de tienda (texto) o 'merma'
const recogida   = ref('ya')        // 'ya' | 'programada' | 'con_entrega'
const hoy = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const fechaRecogida = ref('')

function ponerDestinoPorDefecto() {
  // Por defecto, la tienda de donde salió (si sigue abierta).
  const origen = String(item.value?.tienda_origen_id ?? props.orden.tienda_id ?? '')
  destinoSel.value = destinos.value.some(t => String(t.id) === origen)
    ? origen
    : (destinos.value[0] ? String(destinos.value[0].id) : 'merma')
}
onMounted(async () => {
  try { destinos.value = (await getDestinosDevolucion()).data } catch { destinos.value = [] }
  ponerDestinoPorDefecto()
})

const RECOGIDAS = computed(() => [
  { v: 'ya',          t: 'Ya está aquí', d: 'Entra de una' },
  { v: 'programada',  t: 'Programar',    d: 'Se recoge otro día' },
  ...(tipo.value !== 'despues' ? [{ v: 'con_entrega', t: 'Con el nuevo', d: 'Al llevarle el reemplazo' }] : []),
])

watch(tipo, (t) => {
  if (t === 'despues' && recogida.value === 'con_entrega') recogida.value = 'programada'
  if (t === 'diseno_especial') precio.value = 0
  if (t === 'catalogo' && stock.value) precio.value = stock.value.precio_sugerido
})

// ── La cuenta ───────────────────────────────────────────────────────────────
const valorViejo = computed(() => (Number(item.value?.precio_unitario) || 0) * (Number(cantidad.value) || 0))
const valorNuevo = computed(() => tipo.value === 'despues' ? 0 : (Number(precio.value) || 0) * (Number(cantNueva.value) || 0))
const diferencia = computed(() => valorNuevo.value - valorViejo.value)
const saldoDespues = computed(() => (Number(props.orden.saldo_pendiente) || 0) + diferencia.value)

// ── Guardar ─────────────────────────────────────────────────────────────────
const guardando = ref(false)

const falta = computed(() => {
  if (!item.value) return 'Escoge qué devuelve.'
  if (!(cantidad.value >= 1 && cantidad.value <= maxDev.value)) return `Puede devolver de 1 a ${maxDev.value}.`
  if (motivo.value.trim().length < 3) return 'Escribe por qué lo devuelve.'
  if (!destinoSel.value) return 'Escoge a dónde va lo que devuelve.'
  if (recogida.value === 'programada' && !fechaRecogida.value) return 'Escoge qué día se recoge.'
  if (recogida.value === 'programada' && fechaRecogida.value < hoy) return 'La recogida no puede ser un día que ya pasó.'
  if (tipo.value === 'despues') return null
  if (!(cantNueva.value >= 1)) return 'Cuántos se lleva.'
  if (tipo.value === 'catalogo') {
    if (!productoNuevo.value) return 'Busca el producto nuevo.'
    if (!fabricar.value && !tiendaId.value) return 'Escoge de qué tienda sale o mándalo a fabricar.'
    if (precio.value === '' || precio.value === null) return 'Ponle precio.'
  }
  if (tipo.value === 'diseno_especial') {
    if (especial.value.nombre.trim().length < 2) return 'Escribe qué mueble es.'
    if (!(Number(precio.value) > 0)) return 'Ponle precio al diseño especial.'
  }
  return null
})

async function subirBocetos() {
  const urls = []
  for (const b of bocetos.value) {
    const fd = new FormData()
    fd.append('foto', await comprimirImagen(b.file), 'boceto.jpg')
    fd.append('folder', 'bocetos')
    urls.push((await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })).data.url)
  }
  return urls
}

async function guardar() {
  if (falta.value) { toast.error(falta.value); return }
  if (guardando.value) return
  guardando.value = true
  try {
    const payload = {
      orden_item_id: item.value.id,
      cantidad: Number(cantidad.value),
      motivo: motivo.value.trim(),
      devuelto: {
        destino: destinoSel.value === 'merma' ? 'merma' : 'tienda',
        tienda_id: destinoSel.value === 'merma' ? null : Number(destinoSel.value),
        recogida: recogida.value,
        fecha_recogida: recogida.value === 'programada' ? fechaRecogida.value : null,
      },
    }
    if (tipo.value === 'catalogo') {
      payload.reemplazo = {
        tipo: 'catalogo',
        producto_id: productoNuevo.value.id,
        variante_id: varianteId.value || null,
        combo_config_id: comboId.value || null,
        fabricar: fabricar.value,
        tienda_id: fabricar.value ? null : tiendaId.value,
        notas: fabricar.value ? (notasTaller.value.trim() || null) : null,
        cantidad: Number(cantNueva.value),
        precio_unitario: Number(precio.value) || 0,
        fecha_entrega_prom: fechaEntrega.value || null,
      }
    } else if (tipo.value === 'diseno_especial') {
      const e = especial.value
      payload.reemplazo = {
        tipo: 'diseno_especial',
        nombre: e.nombre.trim(),
        ancho: e.ancho.trim() || null, largo: e.largo.trim() || null, alto: e.alto.trim() || null,
        tela: e.tela.trim() || null, madera: e.madera.trim() || null, detalles: e.detalles.trim() || null,
        boceto_urls: await subirBocetos(),
        cantidad: Number(cantNueva.value),
        precio_unitario: Number(precio.value) || 0,
        fecha_entrega_prom: fechaEntrega.value || null,
      }
    }

    const { data } = await cambiarProductoEntregado(props.orden.id, payload)
    toast.success(data.message, 9000)
    emit('hecho', data)
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar el cambio.', 7000)
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
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-800">Cambio de producto</p>
            <p class="text-[11px] text-gray-400 truncate">
              {{ orden.referencia }} · {{ orden.cliente?.nombre }} · no le sirvió (no es garantía)
            </p>
          </div>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4 overflow-y-auto">
          <!-- 1. Lo que devuelve -->
          <section class="space-y-2">
            <p class="text-xs font-semibold text-gray-500 uppercase">1 · Qué devuelve</p>
            <select v-model="itemId" :class="inputCls">
              <option v-for="i in devolvibles" :key="i.id" :value="i.id">
                {{ enCasa(i) }} × {{ nombreItem(i) }}{{ i.variante_texto ? ` (${i.variante_texto})` : '' }} — {{ pesos(i.precio_unitario) }} c/u
              </option>
            </select>
            <div v-if="maxDev > 1" class="flex items-center gap-2">
              <label class="text-xs text-gray-600">¿Cuántas devuelve?</label>
              <input v-model.number="cantidad" type="number" min="1" :max="maxDev"
                class="w-20 rounded-xl border border-gray-200 px-3 py-1.5 text-sm" />
              <span class="text-[11px] text-gray-400">de {{ maxDev }}</span>
            </div>
            <textarea v-model="motivo" rows="2" :class="inputCls + ' resize-none'"
              placeholder="Por qué no le sirvió. Ej: no le cupo en la sala, no le gustó el color" />
          </section>

          <!-- 2. Lo que se lleva -->
          <section class="space-y-2">
            <p class="text-xs font-semibold text-gray-500 uppercase">2 · Qué se lleva</p>
            <div class="grid gap-2" :class="OPCIONES.length === 3 ? 'grid-cols-3' : 'grid-cols-2'">
              <button
                v-for="o in OPCIONES" :key="o.v" type="button" @click="tipo = o.v"
                :class="['flex flex-col items-start gap-1 text-left rounded-xl px-3 py-2.5 border-2 transition-colors',
                  tipo === o.v ? 'border-blue-500' : 'border-gray-200 hover:border-gray-300']"
              >
                <component :is="o.icon" :class="['w-4 h-4', tipo === o.v ? 'text-blue-600' : 'text-gray-500']" />
                <span :class="['text-xs font-semibold', tipo === o.v ? 'text-blue-800' : 'text-gray-700']">{{ o.t }}</span>
                <span class="text-[10px] text-gray-400 leading-tight">{{ o.d }}</span>
              </button>
            </div>

            <!-- Del catálogo -->
            <template v-if="tipo === 'catalogo'">
              <div v-if="!productoNuevo">
                <div class="relative">
                  <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                  <input v-model="busqueda" placeholder="Buscar el producto nuevo…" class="w-full rounded-xl border border-gray-200 pl-9 pr-3 py-2 text-sm" />
                </div>
                <!-- En la lista y no flotando: dentro de la hoja con scroll, un
                     menú flotante queda cortado abajo en el celular. -->
                <div v-if="resultados.length" class="mt-1 bg-white border border-gray-200 rounded-xl max-h-56 overflow-y-auto">
                  <button v-for="p in resultados" :key="p.id" type="button" @click="escogerProducto(p)"
                    class="w-full text-left px-3 py-2 text-sm hover:bg-gray-100 flex justify-between gap-2">
                    <span class="truncate">{{ p.nombre }}</span>
                    <span class="text-[11px] text-gray-400 shrink-0">{{ p.categoria }}</span>
                  </button>
                </div>
              </div>
              <div v-else class="flex items-center justify-between rounded-xl border border-gray-200 px-3 py-2">
                <span class="text-sm font-semibold text-gray-800 truncate">{{ productoNuevo.nombre }}</span>
                <button type="button" @click="quitarProducto" class="text-xs text-blue-600 font-semibold">Cambiar</button>
              </div>

              <div v-if="productoNuevo && stock && (stock.variantes.length || stock.opciones.length)" class="grid grid-cols-2 gap-2">
                <select v-if="stock.variantes.length" v-model="varianteId" @change="cargarStock({ conPrecio: true })" :class="inputCls">
                  <option :value="null">Sin tela / variante</option>
                  <option v-for="v in stock.variantes" :key="v.id" :value="v.id">{{ v.texto }}</option>
                </select>
                <select v-if="stock.opciones.length" v-model="comboId" @change="cargarStock({ conPrecio: true })" :class="inputCls">
                  <option :value="null">Sin opción</option>
                  <option v-for="o in stock.opciones" :key="o.id" :value="o.id">{{ o.texto }}</option>
                </select>
              </div>

              <div v-if="productoNuevo">
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">
                  ¿De dónde sale? <span class="font-normal text-gray-400">— libres de verdad (lo apartado para otras órdenes no cuenta)</span>
                </label>
                <AppSpinner v-if="cargandoStock" />
                <div v-else class="space-y-1.5">
                  <button
                    v-for="t in stock?.tiendas ?? []" :key="t.tienda_id" type="button"
                    :disabled="t.libres < cantNueva"
                    @click="tiendaId = t.tienda_id; fabricar = false"
                    :class="['w-full flex justify-between items-center rounded-xl px-3 py-2 border-2 text-sm transition-colors disabled:opacity-40',
                      tiendaId === t.tienda_id && !fabricar ? 'border-teal-500' : 'border-gray-200']"
                  >
                    <span class="text-gray-800">{{ t.nombre }}</span>
                    <span :class="['text-xs font-semibold rounded-full px-2 py-0.5', t.libres >= cantNueva ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600']">
                      {{ t.libres }} libre{{ t.libres === 1 ? '' : 's' }}
                    </span>
                  </button>
                  <p v-if="stock && !stock.tiendas.some(t => t.libres >= cantNueva)" class="text-[11px] text-amber-700">
                    No hay {{ cantNueva > 1 ? `${cantNueva} libres` : 'uno libre' }} en ninguna tienda.
                  </p>
                  <button
                    type="button" @click="fabricar = true; tiendaId = null"
                    :class="['w-full text-left rounded-xl px-3 py-2 border-2 text-sm transition-colors', fabricar ? 'border-teal-500' : 'border-gray-200']"
                  >
                    <span class="flex items-center gap-1.5 font-semibold text-gray-800">
                      <WrenchScrewdriverIcon class="w-4 h-4 text-gray-500" /> Mandarlo a fabricar
                    </span>
                    <span class="block text-[11px] text-gray-500">Entra a producción como un pedido para fabricar.</span>
                  </button>
                  <div v-if="fabricar">
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas para el taller <span class="font-normal text-gray-400">— opcional</span></label>
                    <textarea v-model="notasTaller" rows="2" :class="inputCls + ' resize-none'"
                      placeholder="Ej: patas en negro mate, sin botones en el espaldar, laca nogal…" />
                  </div>
                </div>
              </div>
            </template>

            <!-- Diseño especial -->
            <template v-if="tipo === 'diseno_especial'">
              <input v-model="especial.nombre" placeholder="Qué mueble es. Ej: Sofá en L a la medida" :class="inputCls" />
              <div class="grid grid-cols-3 gap-2">
                <input v-model="especial.ancho" placeholder="Ancho" :class="inputCls" />
                <input v-model="especial.largo" placeholder="Largo" :class="inputCls" />
                <input v-model="especial.alto"  placeholder="Alto"  :class="inputCls" />
              </div>
              <div class="grid grid-cols-2 gap-2">
                <input v-model="especial.tela"   placeholder="Tela"   :class="inputCls" />
                <input v-model="especial.madera" placeholder="Madera" :class="inputCls" />
              </div>
              <textarea v-model="especial.detalles" rows="2" placeholder="Detalles para el taller y el cliente" :class="inputCls + ' resize-none'" />
              <div>
                <div class="flex flex-wrap gap-1.5">
                  <div v-for="(b, k) in bocetos" :key="b.preview" class="relative">
                    <img :src="b.preview" class="w-14 h-14 rounded-lg object-cover border border-gray-100" />
                    <button type="button" @click="bocetos.splice(k, 1)"
                      class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-gray-700 text-white flex items-center justify-center">
                      <XMarkIcon class="w-3 h-3" />
                    </button>
                  </div>
                  <label v-if="bocetos.length < 8"
                    class="w-14 h-14 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center text-[10px] text-gray-500 text-center cursor-pointer">
                    + Boceto
                    <input type="file" accept="image/*" multiple class="hidden" @change="agregarBoceto" />
                  </label>
                </div>
                <p class="text-[11px] text-gray-400 mt-1">
                  Con esto se arma la ficha de la declaración de diseños especiales: después de guardar,
                  la orden te pide mandarla a firmar.
                </p>
              </div>
            </template>

            <p v-if="tipo === 'despues'" class="text-[11px] text-gray-600 bg-gray-100 rounded-lg px-2.5 py-2">
              Lo devuelto deja de cobrarse y la orden vuelve a estar abierta. El producto nuevo se agrega desde
              <strong>Editar orden</strong>. Mientras tanto, lo que pagó queda a su favor.
            </p>

            <!-- Cantidad, precio y fecha del nuevo -->
            <div v-if="tipo !== 'despues' && (tipo === 'diseno_especial' || productoNuevo)" class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Cantidad</label>
                <input v-model.number="cantNueva" type="number" min="1" :class="inputCls" />
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Precio unitario</label>
                <InputPesos v-model="precio" :class="inputCls" />
              </div>
              <div v-if="tipo === 'diseno_especial' || fabricar" class="col-span-2">
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Fecha prometida <span class="font-normal text-gray-400">— opcional</span></label>
                <input v-model="fechaEntrega" type="date" :class="inputCls" />
              </div>
              <p v-if="tipo === 'catalogo' && stock" class="col-span-2 text-[11px] text-gray-400 -mt-1">
                Precio de lista: {{ pesos(stock.precio_sugerido) }}.
              </p>
            </div>
          </section>

          <!-- 3. Lo que devuelve: a dónde va y cuándo -->
          <section v-if="item" class="space-y-2">
            <p class="text-xs font-semibold text-gray-500 uppercase">3 · Qué pasa con lo que devuelve</p>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿A dónde va?</label>
              <select v-model="destinoSel" :class="inputCls">
                <option v-for="t in destinos" :key="t.id" :value="String(t.id)">
                  {{ t.es_fabrica ? t.nombre + ' (fábrica / bodega)' : t.nombre }}
                </option>
                <option value="merma">Merma: llegó dañado, no vuelve a la venta</option>
              </select>
              <p v-if="destinoSel !== 'merma' && !entraAlInventario" class="text-[11px] text-gray-400 mt-1">
                No es de catálogo: queda anotado dónde está, pero no entra al inventario.
              </p>
              <p v-else-if="destinoSel !== 'merma' && item.retapizar" class="text-[11px] text-orange-700 mt-1">
                Se le cambió la tela: entra al producto sin tela asignada. Asígnasela en Inventario.
              </p>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuándo llega?</label>
              <div class="grid gap-2" :class="RECOGIDAS.length === 3 ? 'grid-cols-3' : 'grid-cols-2'">
                <button
                  v-for="r in RECOGIDAS" :key="r.v" type="button" @click="recogida = r.v"
                  :class="['text-left rounded-xl px-3 py-2 border-2 transition-colors',
                    recogida === r.v ? 'border-blue-500' : 'border-gray-200 hover:border-gray-300']"
                >
                  <span :class="['block text-xs font-semibold', recogida === r.v ? 'text-blue-800' : 'text-gray-700']">{{ r.t }}</span>
                  <span class="block text-[10px] text-gray-400 leading-tight">{{ r.d }}</span>
                </button>
              </div>
              <input v-if="recogida === 'programada'" v-model="fechaRecogida" type="date" :min="hoy" :class="inputCls + ' mt-2'" />
              <p class="text-[11px] text-gray-500 mt-1.5">
                <template v-if="recogida === 'ya'">
                  {{ destinoSel === 'merma' ? 'Queda como merma.' : (entraAlInventario ? 'Entra al inventario de una.' : 'Queda anotado que ya está allá.') }}
                </template>
                <template v-else>
                  La orden cambia ya; el mueble queda en Despacho → <strong>Por recoger</strong>
                  y {{ entraAlInventario && destinoSel !== 'merma' ? 'entra al inventario' : 'se cierra' }} cuando lo marquen como llegado.
                </template>
              </p>
            </div>
          </section>

          <!-- 4. La cuenta -->
          <section v-if="item && (tipo !== 'catalogo' || productoNuevo)" class="rounded-xl border border-gray-200 p-3 space-y-1 text-xs">
            <div class="flex justify-between text-gray-600">
              <span>Lo que devuelve</span><span>− {{ pesos(valorViejo) }}</span>
            </div>
            <div v-if="tipo !== 'despues'" class="flex justify-between text-gray-600">
              <span>Lo que se lleva</span><span>+ {{ pesos(valorNuevo) }}</span>
            </div>
            <div class="flex justify-between font-semibold pt-1 border-t border-gray-100"
              :class="diferencia > 0.5 ? 'text-amber-700' : diferencia < -0.5 ? 'text-green-700' : 'text-gray-700'">
              <span>{{ diferencia > 0.5 ? 'La venta sube' : diferencia < -0.5 ? 'La venta baja' : 'Sin diferencia' }}</span>
              <span>{{ pesos(Math.abs(diferencia)) }}</span>
            </div>
            <p class="text-[11px] text-gray-500 pt-1">
              Lo que ya pagó se queda en la orden.
              <template v-if="saldoDespues > 0.5">Queda debiendo <strong>{{ pesos(saldoDespues) }}</strong>.</template>
              <template v-else-if="saldoDespues < -0.5">Le quedan <strong>{{ pesos(-saldoDespues) }}</strong> a favor.</template>
              <template v-else>Queda a paz y salvo.</template>
            </p>
          </section>
        </div>

        <div class="flex gap-2.5 p-5 pt-3 border-t border-gray-100">
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5">Cancelar</button>
          <button @click="guardar" :disabled="!!falta || guardando" :title="falta || ''"
            class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 disabled:opacity-50">
            {{ guardando ? 'Guardando...' : 'Registrar cambio' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
