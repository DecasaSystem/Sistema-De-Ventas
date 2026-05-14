<script setup>
import { ref, watch } from 'vue'
import { editarOrden, buscarProductos } from '@/api/ordenes'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'
import ComboInput from '@/components/common/ComboInput.vue'
import { XMarkIcon, SparklesIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

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

const auth       = useAuthStore()

// product search per item
const buscando  = ref({})
const resultados = ref({})
const query = ref({})

watch(() => props.show, (v) => {
  if (!v) return
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
          precio_unitario:  parseFloat(item.precio_unitario),
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
                  <option value="fisica">Física</option>
                  <option value="whatsapp">WhatsApp</option>
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
