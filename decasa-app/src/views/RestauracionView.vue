<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/api'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela, cargarCatalogoDB } from '@/data/telasCatalogo'
import {
  PlusIcon,
  MagnifyingGlassIcon,
  XMarkIcon,
  ArrowPathIcon,
  TrashIcon,
  CheckCircleIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  UserCircleIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import { getRestauraciones, createRestauracion } from '@/api/restauraciones'
import { getClientes } from '@/api/clientes'
import { useToast } from '@/composables/useToast'
import { useTelaFotos } from '@/composables/useTelaFotos'

const auth  = useAuthStore()
const toast = useToast()

// Fotos de tela (cargadas una sola vez, cacheadas y optimizadas)
const { cargarFotosTela, fotoDeTela } = useTelaFotos()
cargarFotosTela()

// ── Telas ─────────────────────────────────────────────────────────────────────
const telaMetrosMap = ref({})

function tieneStock(marca, tipo, color) {
  return (telaMetrosMap.value[`${marca}|${tipo}|${color}`] ?? 0) > 0
}
function marcasConStock() {
  return marcasOrdenadas.value.filter(m =>
    Object.keys(TELAS_CATALOGO[m] ?? {}).some(tipo =>
      (TELAS_CATALOGO[m][tipo] ?? []).some(color => tieneStock(m, tipo, color))
    )
  )
}
function tiposConStock(marca) {
  return tiposTelaDeM(marca).filter(tipo =>
    (TELAS_CATALOGO[marca]?.[tipo] ?? []).some(color => tieneStock(marca, tipo, color))
  )
}
function coloresConStock(marca, tipo) {
  return coloresDeTela(marca, tipo).filter(color => tieneStock(marca, tipo, color))
}
function getTelaSelection(item, key) {
  if (!item._telaSelections[key]) {
    item._telaSelections[key] = { marca: '', tipo: '', color: '' }
  }
  return item._telaSelections[key]
}
function telaResumidaCampo(item, key) {
  const s = item._telaSelections?.[key]
  if (!s?.marca || !s?.tipo || !s?.color) return ''
  return `${s.marca} · ${s.tipo} · ${s.color}`
}

// ── Lista ─────────────────────────────────────────────────────────────────────
const restauraciones = ref([])
const loading        = ref(true)
const hasMore        = ref(false)
const currentPage    = ref(1)
const busqueda       = ref('')
const filtroEstado   = ref('')

const estadosOpts = [
  { value: '',                      label: 'Todos' },
  { value: 'en_produccion',         label: 'En producción' },
  { value: 'listo_entrega',         label: 'Listo' },
  { value: 'entregado',             label: 'Entregado' },
]

async function fetchLista(page = 1, append = false) {
  if (page === 1) loading.value = true
  try {
    const params = { page }
    if (busqueda.value)     params.search = busqueda.value
    if (filtroEstado.value) params.estado = filtroEstado.value
    const { data } = await getRestauraciones(params)
    const list = data.data ?? []
    restauraciones.value = append ? [...restauraciones.value, ...list] : list
    hasMore.value    = data.current_page < data.last_page
    currentPage.value = data.current_page
  } catch {
    if (page === 1) restauraciones.value = []
  } finally {
    loading.value = false
  }
}

function buscar() {
  currentPage.value = 1
  fetchLista(1)
}

watch(filtroEstado, () => { currentPage.value = 1; fetchLista(1) })

function formatFecha(d) {
  if (!d) return '—'
  return new Date(String(d).substring(0, 10) + 'T00:00:00')
    .toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatPesos(n) {
  return Number(n).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function estadoBadge(r) {
  const items = r.items ?? []
  const estados = items.flatMap(i => i.produccion ? [i.produccion.estado] : [])
  if (estados.every(e => e === 'entregado')) return { label: 'Entregado',       cls: 'bg-gray-100 text-gray-500' }
  if (estados.every(e => e === 'listo'))     return { label: 'Listo',           cls: 'bg-blue-100 text-blue-700' }
  if (estados.some(e => e === 'en_proceso')) return { label: 'En proceso',      cls: 'bg-green-100 text-green-700' }
  if (estados.some(e => e === 'pendiente_despachador')) return { label: 'En despacho', cls: 'bg-purple-100 text-purple-700' }
  return { label: 'Pendiente', cls: 'bg-yellow-100 text-yellow-700' }
}

function estadoIcon(r) {
  const badge = estadoBadge(r)
  if (badge.label === 'Entregado' || badge.label === 'Listo') return CheckCircleIcon
  if (badge.label === 'En proceso') return WrenchScrewdriverIcon
  return ClockIcon
}

// ── Formulario nuevo ──────────────────────────────────────────────────────────
const showForm = ref(false)
const saving   = ref(false)

// Cliente
const clienteQuery     = ref('')
const clienteOpts      = ref([])
const clienteSelected  = ref(null)
const clienteLoading   = ref(false)
let clienteTimer = null

watch(clienteQuery, (val) => {
  if (clienteSelected.value) return
  clearTimeout(clienteTimer)
  if (!val.trim()) { clienteOpts.value = []; return }
  clienteTimer = setTimeout(async () => {
    clienteLoading.value = true
    try {
      const { data } = await getClientes({ search: val, per_page: 8 })
      clienteOpts.value = data.data ?? data
    } catch {
      clienteOpts.value = []
    } finally {
      clienteLoading.value = false
    }
  }, 300)
})

function seleccionarCliente(c) {
  clienteSelected.value = c
  clienteQuery.value    = c.nombre
  clienteOpts.value     = []
}

function limpiarCliente() {
  clienteSelected.value = null
  clienteQuery.value    = ''
  clienteOpts.value     = []
}

// Items
const itemVacio = () => ({ nombre_mueble: '', descripcion_trabajo: '', cantidad: 1, precio_unitario: '', _retapizar: false, _telaSelections: {} })
const items = ref([itemVacio()])

function agregarItem() {
  items.value.push(itemVacio())
}

function quitarItem(idx) {
  items.value.splice(idx, 1)
}

const notas = ref('')

const total = computed(() =>
  items.value.reduce((s, i) => s + (Number(i.cantidad) || 0) * (Number(i.precio_unitario) || 0), 0)
)

function resetForm() {
  clienteSelected.value = null
  clienteQuery.value    = ''
  clienteOpts.value     = []
  items.value = [itemVacio()]
  notas.value = ''
}

async function guardar() {
  if (!clienteSelected.value) {
    toast.error('Selecciona un cliente.')
    return
  }
  if (items.value.some(i => !i.nombre_mueble.trim())) {
    toast.error('Completa el nombre del mueble en todos los ítems.')
    return
  }
  if (items.value.some(i => !i.precio_unitario || Number(i.precio_unitario) <= 0)) {
    toast.error('Todos los ítems deben tener un precio mayor a 0.')
    return
  }
  for (const i of items.value) {
    if (i._retapizar && !telaResumidaCampo(i, 'tela')) {
      toast.error(`Selecciona la tela para "${i.nombre_mueble || 'ítem'}" (marcaste retapizar).`)
      return
    }
  }

  saving.value = true
  try {
    await createRestauracion({
      cliente_id: clienteSelected.value.id,
      tienda_id:  auth.usuario.tienda_default_id,
      notas:      notas.value || null,
      items:      items.value.map(i => ({
        nombre_mueble:       i.nombre_mueble.trim(),
        descripcion_trabajo: i.descripcion_trabajo.trim() || null,
        cantidad:            Number(i.cantidad),
        precio_unitario:     Number(i.precio_unitario),
        retapizar:           i._retapizar || false,
        tela:                i._retapizar ? (telaResumidaCampo(i, 'tela') || null) : null,
      })),
    })
    toast.success('Restauración creada y enviada a producción.')
    showForm.value = false
    resetForm()
    fetchLista(1)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al guardar.')
  } finally {
    saving.value = false
  }
}

function abrirForm() {
  resetForm()
  showForm.value = true
}

onMounted(async () => {
  fetchLista(1)
  try {
    await cargarCatalogoDB(api)
    const { data: telasData } = await api.get('/inventario-telas')
    const map = {}
    for (const t of telasData) {
      map[`${t.marca}|${t.tipo}|${t.color}`] = t.metros_libres
    }
    telaMetrosMap.value = map
  } catch {}
})
</script>

<template>
  <div class="min-h-screen bg-gray-50 pb-28">

    <!-- Header -->
    <div class="bg-white border-b border-gray-200 px-4 pt-4 pb-3 sticky top-0 z-10">
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <ArrowPathIcon class="w-5 h-5 text-indigo-600" />
          <h1 class="text-lg font-semibold text-gray-900">Restauraciones</h1>
        </div>
        <button
          @click="abrirForm"
          class="flex items-center gap-1.5 bg-indigo-600 text-white text-sm font-medium px-3 py-2 rounded-xl active:scale-95 transition-transform"
        >
          <PlusIcon class="w-4 h-4" />
          Nueva
        </button>
      </div>

      <!-- Búsqueda -->
      <div class="relative">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input
          v-model="busqueda"
          @keyup.enter="buscar"
          placeholder="Buscar por cliente…"
          class="w-full pl-9 pr-4 py-2.5 bg-gray-100 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
        />
      </div>

      <!-- Filtro estado -->
      <div class="flex gap-2 mt-2 overflow-x-auto pb-1">
        <button
          v-for="opt in estadosOpts"
          :key="opt.value"
          @click="filtroEstado = opt.value"
          :class="[
            'shrink-0 text-xs px-3 py-1.5 rounded-full font-medium transition-colors',
            filtroEstado === opt.value
              ? 'bg-indigo-600 text-white'
              : 'bg-gray-100 text-gray-600'
          ]"
        >{{ opt.label }}</button>
      </div>
    </div>

    <!-- Lista -->
    <div class="px-4 py-3 space-y-3">

      <div v-if="loading" class="space-y-3">
        <div v-for="n in 4" :key="n" class="bg-white rounded-2xl p-4 animate-pulse">
          <div class="h-4 bg-gray-200 rounded w-1/2 mb-2"></div>
          <div class="h-3 bg-gray-100 rounded w-3/4"></div>
        </div>
      </div>

      <div
        v-else-if="restauraciones.length === 0"
        class="text-center py-16 text-gray-400"
      >
        <ArrowPathIcon class="w-12 h-12 mx-auto mb-3 opacity-30" />
        <p class="text-sm">No hay restauraciones aún</p>
      </div>

      <div
        v-for="r in restauraciones"
        :key="r.id"
        class="bg-white rounded-2xl p-4 shadow-sm"
      >
        <!-- Encabezado tarjeta -->
        <div class="flex items-start justify-between gap-2 mb-2">
          <div class="flex items-center gap-2 min-w-0">
            <UserCircleIcon class="w-5 h-5 text-gray-400 shrink-0" />
            <span class="font-semibold text-gray-900 text-sm truncate">{{ r.cliente?.nombre }}</span>
          </div>
          <span :class="['shrink-0 text-xs px-2 py-0.5 rounded-full font-medium', estadoBadge(r).cls]">
            {{ estadoBadge(r).label }}
          </span>
        </div>

        <!-- Items -->
        <div class="space-y-1 mb-2">
          <div
            v-for="item in r.items"
            :key="item.id"
            class="flex items-start gap-1.5 text-xs text-gray-600"
          >
            <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-indigo-300 shrink-0"></span>
            <span>
              <span class="font-medium text-gray-800">{{ item.nombre_custom }}</span>
              <span v-if="item.cantidad > 1" class="text-gray-400"> ×{{ item.cantidad }}</span>
              <span v-if="item.specs_personalizacion?.descripcion_trabajo" class="text-gray-400"> — {{ item.specs_personalizacion.descripcion_trabajo }}</span>
              <span v-if="item.specs_personalizacion?.retapizar" class="ml-1 text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-medium">retapizar</span>
              <span v-if="item.specs_personalizacion?.tela" class="text-gray-400"> · {{ item.specs_personalizacion.tela }}</span>
            </span>
          </div>
        </div>

        <!-- Pie -->
        <div class="flex items-center justify-between text-xs text-gray-400">
          <span>{{ formatFecha(r.created_at) }}</span>
          <span class="font-semibold text-gray-700">{{ formatPesos(r.valor_total) }}</span>
        </div>

        <div v-if="r.notas" class="mt-1 text-xs text-gray-400 italic truncate">{{ r.notas }}</div>
      </div>

      <button
        v-if="hasMore && !loading"
        @click="fetchLista(currentPage + 1, true)"
        class="w-full py-3 text-sm text-indigo-600 font-medium"
      >
        Ver más
      </button>
    </div>

    <!-- ── Formulario (bottom sheet) ─────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="sheet">
        <div
          v-if="showForm"
          class="fixed inset-0 z-50 flex flex-col justify-end bg-black/40"
          @click.self="showForm = false"
        >
          <div class="bg-white rounded-t-3xl max-h-[92vh] flex flex-col">

            <!-- Cabecera del sheet -->
            <div class="flex items-center justify-between px-4 pt-4 pb-3 border-b border-gray-100 shrink-0">
              <h2 class="font-semibold text-gray-900">Nueva restauración</h2>
              <button @click="showForm = false" class="p-1 text-gray-400">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <!-- Cuerpo scrollable -->
            <div class="overflow-y-auto px-4 py-4 space-y-5 flex-1">

              <!-- Cliente -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Cliente</label>
                <div class="relative">
                  <input
                    v-model="clienteQuery"
                    @input="clienteSelected = null"
                    placeholder="Buscar cliente…"
                    class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
                    :readonly="!!clienteSelected"
                  />
                  <button
                    v-if="clienteSelected"
                    @click="limpiarCliente"
                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400"
                  >
                    <XMarkIcon class="w-4 h-4" />
                  </button>
                </div>
                <!-- Dropdown resultados -->
                <div
                  v-if="clienteOpts.length > 0 && !clienteSelected"
                  class="border border-gray-200 rounded-xl mt-1 overflow-hidden shadow-sm"
                >
                  <button
                    v-for="c in clienteOpts"
                    :key="c.id"
                    @click="seleccionarCliente(c)"
                    class="w-full text-left px-3 py-2.5 text-sm hover:bg-gray-50 border-b border-gray-100 last:border-0"
                  >
                    <span class="font-medium text-gray-900">{{ c.nombre }}</span>
                    <span v-if="c.telefono" class="ml-2 text-gray-400 text-xs">{{ c.telefono }}</span>
                  </button>
                </div>
                <p v-if="clienteLoading" class="text-xs text-gray-400 mt-1">Buscando…</p>
              </div>

              <!-- Items -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <label class="text-sm font-medium text-gray-700">Muebles / ítems</label>
                  <button
                    @click="agregarItem"
                    class="text-xs text-indigo-600 font-medium flex items-center gap-1"
                  >
                    <PlusIcon class="w-3.5 h-3.5" /> Agregar
                  </button>
                </div>

                <div class="space-y-3">
                  <div
                    v-for="(item, idx) in items"
                    :key="idx"
                    class="bg-gray-50 rounded-xl p-3 space-y-2"
                  >
                    <div class="flex items-center justify-between">
                      <span class="text-xs font-semibold text-gray-500">Ítem {{ idx + 1 }}</span>
                      <button
                        v-if="items.length > 1"
                        @click="quitarItem(idx)"
                        class="p-1 text-red-400"
                      >
                        <TrashIcon class="w-4 h-4" />
                      </button>
                    </div>

                    <input
                      v-model="item.nombre_mueble"
                      placeholder="Nombre del mueble (ej. Sofá 3 puestos)"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200 bg-white"
                    />
                    <input
                      v-model="item.descripcion_trabajo"
                      placeholder="Trabajo a realizar (ej. Tapizado + laca)"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200 bg-white"
                    />
                    <div class="flex gap-2">
                      <div class="flex-1">
                        <label class="text-xs text-gray-500 mb-1 block">Cant.</label>
                        <input
                          v-model.number="item.cantidad"
                          type="number"
                          min="1"
                          class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200 bg-white"
                        />
                      </div>
                      <div class="flex-[2]">
                        <label class="text-xs text-gray-500 mb-1 block">Precio unitario</label>
                        <input
                          v-model.number="item.precio_unitario"
                          type="number"
                          min="0"
                          placeholder="0"
                          class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200 bg-white"
                        />
                      </div>
                    </div>

                    <!-- Retapizar -->
                    <label class="flex items-center gap-2 cursor-pointer select-none pt-1">
                      <input
                        type="checkbox"
                        v-model="item._retapizar"
                        @change="if (!item._retapizar) item._telaSelections = {}"
                        class="w-4 h-4 rounded accent-indigo-600"
                      />
                      <span class="text-sm text-gray-700 font-medium">Retapizar</span>
                    </label>

                    <!-- Picker de tela (solo si retapizar) -->
                    <div v-if="item._retapizar" class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-2">
                      <p class="text-xs font-semibold text-amber-800">Selecciona la tela <span class="text-red-500">*</span></p>

                      <select
                        v-model="getTelaSelection(item, 'tela').marca"
                        @change="getTelaSelection(item, 'tela').tipo = ''; getTelaSelection(item, 'tela').color = ''"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-amber-300"
                      >
                        <option value="">— elige la marca —</option>
                        <option v-for="m in marcasConStock()" :key="m" :value="m">{{ m }}</option>
                      </select>

                      <template v-if="getTelaSelection(item, 'tela').marca">
                        <select
                          v-model="getTelaSelection(item, 'tela').tipo"
                          @change="getTelaSelection(item, 'tela').color = ''"
                          class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-amber-300"
                        >
                          <option value="">— tipo de tela —</option>
                          <option v-for="t in tiposConStock(getTelaSelection(item, 'tela').marca)" :key="t" :value="t">{{ t }}</option>
                        </select>

                        <template v-if="getTelaSelection(item, 'tela').tipo">
                          <select
                            v-model="getTelaSelection(item, 'tela').color"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-amber-300"
                          >
                            <option value="">— color —</option>
                            <option v-for="c in coloresConStock(getTelaSelection(item, 'tela').marca, getTelaSelection(item, 'tela').tipo)" :key="c" :value="c">{{ c }}</option>
                          </select>
                        </template>
                      </template>

                      <div v-if="telaResumidaCampo(item, 'tela')" class="flex items-center gap-2">
                        <img
                          v-if="fotoDeTela(getTelaSelection(item, 'tela').marca, getTelaSelection(item, 'tela').tipo, getTelaSelection(item, 'tela').color)"
                          :src="fotoDeTela(getTelaSelection(item, 'tela').marca, getTelaSelection(item, 'tela').tipo, getTelaSelection(item, 'tela').color)"
                          class="w-9 h-9 rounded-lg object-cover border border-amber-200"
                        />
                        <p class="text-xs font-semibold text-amber-700">✓ {{ telaResumidaCampo(item, 'tela') }}</p>
                      </div>
                      <p v-else-if="Object.keys(telaMetrosMap).length && !marcasConStock().length" class="text-xs text-red-600 italic">
                        No hay telas disponibles en este momento.
                      </p>
                      <p v-else class="text-xs text-amber-600 italic">Selecciona qué tela usará el tapicero</p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Total -->
              <div class="bg-indigo-50 rounded-xl px-4 py-3 flex justify-between items-center">
                <span class="text-sm font-medium text-indigo-700">Total</span>
                <span class="text-base font-bold text-indigo-700">{{ formatPesos(total) }}</span>
              </div>

              <!-- Notas -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notas (opcional)</label>
                <textarea
                  v-model="notas"
                  rows="2"
                  placeholder="Observaciones adicionales…"
                  class="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 resize-none"
                />
              </div>
            </div>

            <!-- Botón guardar -->
            <div class="px-4 pb-6 pt-3 border-t border-gray-100 shrink-0">
              <button
                @click="guardar"
                :disabled="saving"
                class="w-full bg-indigo-600 disabled:opacity-50 text-white font-semibold py-3.5 rounded-2xl active:scale-95 transition-transform"
              >
                {{ saving ? 'Guardando…' : 'Crear y enviar a producción' }}
              </button>
            </div>

          </div>
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<style scoped>
.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.2s ease;
}
.sheet-enter-active .bg-white,
.sheet-leave-active .bg-white {
  transition: transform 0.25s ease;
}
.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}
.sheet-enter-from .bg-white,
.sheet-leave-to .bg-white {
  transform: translateY(100%);
}
</style>
