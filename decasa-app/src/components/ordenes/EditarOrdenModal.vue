<script setup>
import { ref, watch } from 'vue'
import { editarOrden, buscarProductos } from '@/api/ordenes'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'
import ComboInput from '@/components/common/ComboInput.vue'
import { XMarkIcon, SparklesIcon, MagnifyingGlassIcon, SwatchIcon } from '@heroicons/vue/24/outline'
import api from '@/api'

const props = defineProps({
  show: Boolean,
  orden: { type: Object, required: true },
})
const emit = defineEmits(['close', 'guardado'])
const toast = useToast()

const notas          = ref('')
const canal          = ref('')
const direccionEnvio = ref('')
const ciudadEnvio    = ref('')
const items          = ref([])
const guardando = ref(false)

function precioEfectivo(item) {
  const base = item.precio_unitario ?? 0
  const pct  = item._descuento_pct ?? 0
  if (!pct) return base
  return Math.round(base * (1 - pct / 100))
}

const auth       = useAuthStore()

// product search per item
const buscando  = ref({})
const resultados = ref({})
const query = ref({})

watch(() => props.show, (v) => {
  if (!v) return
  mostrarStockTela.value = {}
  cargarTelas()
  notas.value          = props.orden.notas ?? ''
  canal.value          = props.orden.canal ?? ''
  direccionEnvio.value = props.orden.direccion_envio ?? ''
  ciudadEnvio.value    = props.orden.ciudad_envio ?? ''
  items.value = (props.orden.items ?? []).map(item => ({
    id: item.id,
    es_personalizado: item.es_personalizado,
    producto_id: item.producto?.id ?? item.producto_id,
    producto_nombre: item.producto?.nombre ?? '',
    cantidad: item.cantidad,
    precio_unitario: item.precio_unitario,
    _descuento_pct: 0,
    fecha_entrega_prom: item.fecha_entrega_prom
      ? String(item.fecha_entrega_prom).substring(0, 10)
      : '',
    specs: {
      marca:       item.specs_personalizacion?.marca       ?? '',
      tela:        item.specs_personalizacion?.tela        ?? '',
      color:       item.specs_personalizacion?.color       ?? '',
      medidas:     item.specs_personalizacion?.medidas     ?? '',
      acabado:     item.specs_personalizacion?.acabado     ?? '',
      descripcion: item.specs_personalizacion?.descripcion ?? '',
    },
  }))
  query.value = {}
  resultados.value = {}
  buscando.value = {}
})

// ── Inventario de telas ──────────────────────────────────────────────────────
const telaMetrosMap    = ref({})  // "marca|tipo|color" → metros_libres
const telasConStock    = ref([])  // [{ marca, tipo, color, metros_libres }]
const mostrarStockTela = ref({})  // { itemId: bool } para expandir el panel

async function cargarTelas() {
  try {
    const { data } = await api.get('/inventario-telas')
    const map = {}
    for (const t of data) {
      map[`${t.marca}|${t.tipo}|${t.color}`] = t.metros_libres
    }
    telaMetrosMap.value = map
    telasConStock.value = data
      .filter(t => t.metros_libres > 0)
      .sort((a, b) => b.metros_libres - a.metros_libres)
  } catch {}
}

function metrosLibresItem(item) {
  if (!item.specs.marca || !item.specs.tela || !item.specs.color) return null
  const m = telaMetrosMap.value[`${item.specs.marca}|${item.specs.tela}|${item.specs.color}`]
  return m ?? null
}

function telasDisponiblesParaItem(item) {
  // Filtrar por marca si está seleccionada
  return item.specs.marca
    ? telasConStock.value.filter(t => t.marca === item.specs.marca)
    : telasConStock.value
}

function seleccionarTelaStock(item, tela) {
  item.specs.marca = tela.marca
  item.specs.tela  = tela.tipo
  item.specs.color = tela.color
  mostrarStockTela.value[item.id] = false
}

function colorMetros(m) {
  if (m <= 0)  return 'bg-red-100 text-red-700'
  if (m <= 3)  return 'bg-orange-100 text-orange-700'
  if (m <= 8)  return 'bg-yellow-100 text-yellow-700'
  return 'bg-green-100 text-green-700'
}

// ── Tela cascade ────────────────────────────────────────────────────────────
const _todosTipos = (() => {
  const s = new Set()
  Object.values(TELAS_CATALOGO).forEach(m => Object.keys(m).forEach(t => s.add(t)))
  return [...s].sort()
})()

function tiposParaItem(item) {
  return item.specs.marca ? tiposTelaDeM(item.specs.marca) : _todosTipos
}
function coloresParaItem(item) {
  if (item.specs.marca && item.specs.tela) return coloresDeTela(item.specs.marca, item.specs.tela)
  if (item.specs.tela) {
    const s = new Set()
    Object.values(TELAS_CATALOGO).forEach(m => (m[item.specs.tela] ?? []).forEach(c => s.add(c)))
    return [...s].sort()
  }
  return []
}
function onMarcaChange(item, v) { item.specs.marca = v; item.specs.tela = ''; item.specs.color = '' }
function onTelaChange(item, v)  { item.specs.tela = v;  item.specs.color = '' }

// ── Búsqueda de producto ─────────────────────────────────────────────────────
let debounceTimer = null
async function onBuscarProducto(itemId, term) {
  query.value[itemId] = term
  clearTimeout(debounceTimer)
  if (!term || term.length < 2) { resultados.value[itemId] = []; return }
  debounceTimer = setTimeout(async () => {
    buscando.value[itemId] = true
    try {
      const { data } = await buscarProductos(term)
      resultados.value[itemId] = Array.isArray(data) ? data : (data.data ?? [])
    } catch { resultados.value[itemId] = [] }
    finally { buscando.value[itemId] = false }
  }, 300)
}

function seleccionarProducto(item, producto) {
  item.producto_id   = producto.id
  item.producto_nombre = producto.nombre
  query.value[item.id] = ''
  resultados.value[item.id] = []
}

// ── Guardar ──────────────────────────────────────────────────────────────────
async function guardar() {
  guardando.value = true
  try {
    const payload = {
      notas:           notas.value,
      canal:           canal.value,
      direccion_envio: direccionEnvio.value || null,
      ciudad_envio:    ciudadEnvio.value    || null,
      items: items.value.map(item => {
        const out = {
          id:               item.id,
          precio_unitario:  precioEfectivo(item),
          fecha_entrega_prom: item.fecha_entrega_prom || null,
        }
        if (item.es_personalizado) {
          out.specs_personalizacion = {
            marca:       item.specs.marca,
            tela:        item.specs.tela,
            color:       item.specs.color,
            medidas:     item.specs.medidas,
            acabado:     item.specs.acabado,
            descripcion: item.specs.descripcion,
          }
        } else {
          out.cantidad    = parseInt(item.cantidad)
          out.producto_id = item.producto_id
        }
        return out
      }),
    }
    const { data } = await editarOrden(props.orden.id, payload)
    toast.success('Orden actualizada correctamente.')
    emit('guardado', data)
    emit('close')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al guardar los cambios.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="emit('close')">
        <div class="absolute inset-0 bg-black/50" @click="emit('close')" />

        <div class="relative w-full sm:max-w-lg max-h-[90vh] overflow-y-auto bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col">
          <!-- Header -->
          <div class="sticky top-0 bg-white z-10 flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div>
              <h3 class="font-bold text-gray-900">Editar orden #{{ orden.id }}</h3>
              <p class="text-xs text-gray-500 mt-0.5">Los cambios quedan registrados con tu nombre</p>
            </div>
            <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
              <XMarkIcon class="w-5 h-5 text-gray-500" />
            </button>
          </div>

          <div class="p-5 space-y-5 overflow-y-auto">
            <!-- Orden -->
            <div class="space-y-3">
              <p class="text-xs font-semibold text-gray-500 uppercase">Información general</p>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Canal de venta</label>
                <select
                  v-model="canal"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="" disabled>Seleccionar...</option>
                  <option value="fisica">Física</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="instagram">Instagram</option>
                  <option value="facebook">Facebook</option>
                  <option value="pagina">Página web</option>
                  <option value="red_social">Red social</option>
                  <option value="otro">Otro</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Notas</label>
                <textarea
                  v-model="notas"
                  rows="2"
                  placeholder="Notas internas de la orden..."
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Dirección de envío</label>
                <input
                  v-model="direccionEnvio"
                  type="text"
                  placeholder="Calle, número, barrio..."
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Ciudad de envío</label>
                <input
                  v-model="ciudadEnvio"
                  type="text"
                  placeholder="Ciudad..."
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
            </div>

            <!-- Ítems -->
            <div
              v-for="item in items"
              :key="item.id"
              class="border border-gray-200 rounded-xl p-4 space-y-3"
            >
              <div class="flex items-center gap-2">
                <SparklesIcon v-if="item.es_personalizado" class="w-4 h-4 text-purple-500 flex-shrink-0" />
                <p class="font-medium text-sm text-gray-800 truncate">{{ item.producto_nombre }}</p>
              </div>

              <!-- Precio + fecha -->
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Precio unitario</label>
                  <input
                    v-model="item.precio_unitario"
                    type="number"
                    min="0"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Fecha entrega</label>
                  <input
                    v-if="auth.usuario?.rol === 'supervisor'"
                    v-model="item.fecha_entrega_prom"
                    type="date"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  <p v-else class="text-sm text-gray-800 py-2">
                    {{ item.fecha_entrega_prom || '—' }}
                  </p>
                </div>
              </div>

              <!-- Descuento -->
              <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500 flex-shrink-0">Descuento</label>
                <div class="flex items-center gap-1 flex-1">
                  <input
                    v-model.number="item._descuento_pct"
                    type="number"
                    min="0"
                    max="99"
                    step="1"
                    placeholder="0"
                    class="w-20 rounded-lg border border-gray-300 px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  <span class="text-xs text-gray-400">%</span>
                </div>
                <div v-if="item._descuento_pct > 0" class="text-xs text-green-700 bg-green-50 px-2 py-1 rounded-lg font-medium flex-shrink-0">
                  {{ new Intl.NumberFormat('es-CO').format(precioEfectivo(item)) }} c/u
                </div>
              </div>

              <!-- No personalizado: producto + cantidad -->
              <template v-if="!item.es_personalizado">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Cantidad</label>
                  <input
                    v-model="item.cantidad"
                    type="number"
                    min="1"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>

                <!-- Búsqueda de producto -->
                <div class="relative">
                  <label class="block text-xs font-medium text-gray-600 mb-1">Producto</label>
                  <div class="flex gap-2">
                    <div class="flex-1 relative">
                      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                      <input
                        :value="query[item.id] ?? ''"
                        @input="onBuscarProducto(item.id, $event.target.value)"
                        type="text"
                        placeholder="Buscar producto..."
                        class="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                  </div>
                  <p class="text-xs text-gray-500 mt-1">
                    Actual: <span class="font-medium text-gray-700">{{ item.producto_nombre }}</span>
                  </p>
                  <!-- Resultados -->
                  <div
                    v-if="resultados[item.id]?.length"
                    class="absolute z-20 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg max-h-48 overflow-y-auto"
                  >
                    <button
                      v-for="prod in resultados[item.id]"
                      :key="prod.id"
                      @mousedown.prevent="seleccionarProducto(item, prod)"
                      class="w-full text-left px-4 py-2.5 hover:bg-blue-50 transition-colors border-b border-gray-50 last:border-0"
                    >
                      <p class="text-sm font-medium text-gray-800">{{ prod.nombre }}</p>
                      <p class="text-xs text-gray-400">{{ prod.categoria }}</p>
                    </button>
                  </div>
                  <p v-if="buscando[item.id]" class="text-xs text-gray-400 mt-1">Buscando...</p>
                </div>
              </template>

              <!-- Personalizado: specs -->
              <template v-else>
                <div class="space-y-3 pt-1 border-t border-purple-100">
                  <p class="text-xs font-medium text-purple-600">Especificaciones de personalización</p>

                  <!-- Panel: telas con stock disponible -->
                  <div class="rounded-xl border border-green-200 bg-green-50 overflow-hidden">
                    <button
                      type="button"
                      class="w-full flex items-center justify-between px-3 py-2.5 text-left"
                      @click="mostrarStockTela[item.id] = !mostrarStockTela[item.id]"
                    >
                      <span class="flex items-center gap-2 text-xs font-semibold text-green-800">
                        <SwatchIcon class="w-4 h-4" />
                        Telas con stock disponible
                        <span class="bg-green-200 text-green-900 text-[10px] px-1.5 py-0.5 rounded-full font-bold">
                          {{ telasDisponiblesParaItem(item).length }}
                        </span>
                      </span>
                      <span class="text-green-600 text-xs">{{ mostrarStockTela[item.id] ? '▲' : '▼' }}</span>
                    </button>

                    <div v-if="mostrarStockTela[item.id]" class="border-t border-green-200 max-h-52 overflow-y-auto divide-y divide-green-100">
                      <div
                        v-if="!telasDisponiblesParaItem(item).length"
                        class="px-3 py-3 text-xs text-green-700 text-center"
                      >
                        Sin telas con stock{{ item.specs.marca ? ' para ' + item.specs.marca : '' }}
                      </div>
                      <button
                        v-for="t in telasDisponiblesParaItem(item)"
                        :key="`${t.marca}|${t.tipo}|${t.color}`"
                        type="button"
                        @click="seleccionarTelaStock(item, t)"
                        class="w-full flex items-center justify-between px-3 py-2.5 hover:bg-green-100 transition-colors text-left"
                      >
                        <div class="min-w-0 flex-1">
                          <p class="text-xs font-semibold text-gray-800">{{ t.marca }} — {{ t.tipo }}</p>
                          <p class="text-[11px] text-gray-500">{{ t.color }}</p>
                        </div>
                        <span :class="['ml-2 flex-shrink-0 text-[11px] font-bold px-2 py-0.5 rounded-full', colorMetros(t.metros_libres)]">
                          {{ t.metros_libres }}m libres
                        </span>
                      </button>
                    </div>
                  </div>

                  <!-- Metros disponibles para selección actual -->
                  <div
                    v-if="metrosLibresItem(item) !== null"
                    :class="['rounded-lg px-3 py-2 text-xs font-semibold flex items-center gap-2', colorMetros(metrosLibresItem(item))]"
                  >
                    <SwatchIcon class="w-3.5 h-3.5 flex-shrink-0" />
                    Selección actual: {{ metrosLibresItem(item) }}m libres disponibles
                    <span v-if="metrosLibresItem(item) <= 0" class="font-normal">(sin stock)</span>
                  </div>

                  <div class="grid grid-cols-2 gap-3">
                    <div>
                      <label class="block text-xs font-medium text-gray-600 mb-1">Marca de tela</label>
                      <ComboInput
                        :model-value="item.specs.marca"
                        :options="marcasOrdenadas"
                        placeholder="Marca..."
                        @update:model-value="v => onMarcaChange(item, v)"
                      />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-600 mb-1">Tipo de tela</label>
                      <ComboInput
                        :model-value="item.specs.tela"
                        :options="tiposParaItem(item)"
                        placeholder="Tipo..."
                        @update:model-value="v => onTelaChange(item, v)"
                      />
                    </div>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Color</label>
                    <ComboInput
                      :model-value="item.specs.color"
                      :options="coloresParaItem(item)"
                      placeholder="Color..."
                      @update:model-value="v => item.specs.color = v"
                    />
                  </div>
                  <div class="grid grid-cols-2 gap-3">
                    <div>
                      <label class="block text-xs font-medium text-gray-600 mb-1">Medidas</label>
                      <input
                        v-model="item.specs.medidas"
                        type="text"
                        placeholder="ej. 2m x 1.5m"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-600 mb-1">Acabado</label>
                      <input
                        v-model="item.specs.acabado"
                        type="text"
                        placeholder="ej. madera nogal"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Descripción adicional</label>
                    <textarea
                      v-model="item.specs.descripcion"
                      rows="2"
                      placeholder="Detalles adicionales de personalización..."
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                    />
                  </div>
                </div>
              </template>
            </div>
          </div>

          <!-- Footer -->
          <div class="sticky bottom-0 bg-white border-t border-gray-100 px-5 py-4 flex gap-3">
            <button
              @click="emit('close')"
              class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors"
            >
              Cancelar
            </button>
            <button
              @click="guardar"
              :disabled="guardando"
              class="flex-1 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >
              {{ guardando ? 'Guardando...' : 'Guardar cambios' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
