<script setup>
import { ref, computed, watch, onMounted } from 'vue'
// watch sigue importado para los watchers de paso 2 (mismasCantidades, tiendasSelec)
import {
  MagnifyingGlassIcon,
  PlusIcon,
  XMarkIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  CheckIcon,
  ArchiveBoxArrowDownIcon,
  ChevronDownIcon,
  ChevronUpIcon,
} from '@heroicons/vue/24/outline'
import {
  crearSurtido,
  getSurtidos,
  getSurtido,
  getVendedoresTienda,
} from '@/api/surtidos'
import { getTiendas } from '@/api/ordenes'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import ComboInput from '@/components/common/ComboInput.vue'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'

const toast = useToast()

// ── Telas — solo para productos tapizados ────────────────────────────────────
const KEYWORDS_TELA = ['sofa', 'sofá', 'silla', 'sillón', 'sillon', 'mueble', 'tapiceria', 'tapicería', 'tapizado']
function necesitaTela(prod) {
  const cat = (prod?.categoria ?? '').toLowerCase().trim()
  return KEYWORDS_TELA.some(k => cat.includes(k))
}

// ── Telas — opciones en cascada ───────────────────────────────────────────────
const _todosTipos = (() => {
  const s = new Set()
  Object.values(TELAS_CATALOGO).forEach(m => Object.keys(m).forEach(t => s.add(t)))
  return [...s].sort()
})()

function tiposParaEsp(esp) {
  const tipos = esp.marca ? tiposTelaDeM(esp.marca) : _todosTipos
  return tipos
}

function coloresParaEsp(esp) {
  if (esp.marca && esp.tela) return coloresDeTela(esp.marca, esp.tela)
  if (esp.tela) {
    const s = new Set()
    Object.values(TELAS_CATALOGO).forEach(m => (m[esp.tela] ?? []).forEach(c => s.add(c)))
    return [...s].sort()
  }
  return []
}

function onMarcaChange(item, v) {
  item.especificaciones.marca = v
  item.especificaciones.tela  = ''
  item.especificaciones.color = ''
}

function onTelaChange(item, v) {
  item.especificaciones.tela  = v
  item.especificaciones.color = ''
}

// ── Tabs ─────────────────────────────────────────────────────────────────────
const tabActivo = ref('nuevo')  // 'nuevo' | 'historial'

// ── Wizard paso ──────────────────────────────────────────────────────────────
const paso = ref(1)

// ── Paso 1 — Productos ────────────────────────────────────────────────────────
const busquedaProd    = ref('')
const catalogoProd    = ref([])      // todos los productos precargados (id, nombre, categoria, foto_url)
const cargandoCat     = ref(false)
const productosAgr    = ref([])      // [{producto, cantidad, especificaciones}]
const especAbiertos   = ref({})      // { index: bool }

// Filtro client-side — sin llamadas a la API al escribir
const resultados = computed(() => {
  const term = busquedaProd.value.trim().toLowerCase()
  if (!term || term.length < 1) return []
  const yaAgregados = new Set(productosAgr.value.map(p => p.producto.id))
  return catalogoProd.value
    .filter(p =>
      !yaAgregados.has(p.id) &&
      (p.nombre.toLowerCase().includes(term) || (p.categoria ?? '').toLowerCase().includes(term))
    )
    .slice(0, 10)
})

async function precargarCatalogo() {
  if (catalogoProd.value.length > 0) return   // ya cargado
  cargandoCat.value = true
  try {
    // Carga en páginas de 100 en paralelo para no bloquear
    const first = await api.get('/productos', { params: { per_page: 100, page: 1 } })
    const resp  = first.data
    const items = resp.data ?? resp
    const total = resp.last_page ?? 1

    if (total <= 1) {
      catalogoProd.value = items
      return
    }

    // Páginas adicionales en paralelo
    const extras = await Promise.all(
      Array.from({ length: total - 1 }, (_, i) =>
        api.get('/productos', { params: { per_page: 100, page: i + 2 } })
          .then(r => r.data.data ?? r.data)
      )
    )
    catalogoProd.value = items.concat(...extras)
  } catch {} finally {
    cargandoCat.value = false
  }
}

function agregarProducto(prod) {
  if (productosAgr.value.some(p => p.producto.id === prod.id)) return
  productosAgr.value.push({ producto: prod, cantidad: 1, especificaciones: { marca: '', tela: '', color: '', medidas: '', acabado: '' } })
  busquedaProd.value = ''
}

function quitarProducto(idx) {
  productosAgr.value.splice(idx, 1)
}

function toggleEspec(idx) {
  especAbiertos.value[idx] = !especAbiertos.value[idx]
}

const paso1Valido = computed(() => productosAgr.value.length > 0 && productosAgr.value.every(p => p.cantidad >= 1))

// ── Paso 2 — Tiendas ──────────────────────────────────────────────────────────
const tiendas              = ref([])
const tiendasSelec         = ref([])     // [tienda_id, ...]
const mismasCantidades     = ref(true)
const cantidadesPorTienda  = ref({})     // { tienda_id: [{producto_id, cantidad}] }

const todasSelec = computed(() =>
  tiendas.value.length > 0 && tiendasSelec.value.length === tiendas.value.length
)

function toggleTodas() {
  tiendasSelec.value = todasSelec.value ? [] : tiendas.value.map(t => t.id)
}

watch(mismasCantidades, (v) => {
  if (!v) inicializarCantidadesPorTienda()
})

watch(tiendasSelec, () => {
  if (!mismasCantidades.value) inicializarCantidadesPorTienda()
})

function inicializarCantidadesPorTienda() {
  tiendasSelec.value.forEach(tid => {
    if (!cantidadesPorTienda.value[tid]) {
      cantidadesPorTienda.value[tid] = productosAgr.value.map(p => ({
        producto_id: p.producto.id,
        nombre: p.producto.nombre,
        cantidad: p.cantidad,
        especificaciones: p.especificaciones,
      }))
    }
  })
}

const paso2Valido = computed(() => tiendasSelec.value.length > 0)

// ── Paso 3 — Vendedores validadores ──────────────────────────────────────────
const vendedoresPorTienda  = ref({})    // { tienda_id: [usuarios] }
const validadoresPorTienda = ref({})    // { tienda_id: usuario_id }
const cargandoVendedores   = ref(false)

async function cargarVendedores() {
  cargandoVendedores.value = true
  try {
    await Promise.all(
      tiendasSelec.value.map(async (tid) => {
        if (!vendedoresPorTienda.value[tid]) {
          const { data } = await getVendedoresTienda(tid)
          vendedoresPorTienda.value[tid] = data
        }
      })
    )
  } finally {
    cargandoVendedores.value = false
  }
}

const paso3Valido = computed(() =>
  tiendasSelec.value.every(tid => validadoresPorTienda.value[tid])
)

function nombreTienda(id) {
  return tiendas.value.find(t => t.id === id)?.nombre ?? `Tienda #${id}`
}

// ── Paso 4 — Revisión ────────────────────────────────────────────────────────
const notasSurtido = ref('')
const enviando     = ref(false)
const errEnvio     = ref('')

function itemsPorTienda(tid) {
  if (mismasCantidades.value) {
    return productosAgr.value.map(p => ({
      producto_id: p.producto.id,
      nombre: p.producto.nombre,
      cantidad: p.cantidad,
      especificaciones: especificacionesLimpias(p.especificaciones),
    }))
  }
  return (cantidadesPorTienda.value[tid] ?? []).map(p => ({
    producto_id: p.producto_id,
    nombre: p.nombre,
    cantidad: p.cantidad,
    especificaciones: especificacionesLimpias(p.especificaciones),
  }))
}

function especificacionesLimpias(esp) {
  const clean = Object.fromEntries(Object.entries(esp ?? {}).filter(([, v]) => v?.trim()))
  return Object.keys(clean).length ? clean : null
}

async function enviarSurtido() {
  enviando.value = true
  errEnvio.value = ''
  try {
    const payload = {
      notas: notasSurtido.value || null,
      tiendas: tiendasSelec.value.map(tid => ({
        tienda_id:               tid,
        vendedor_validador_id:   validadoresPorTienda.value[tid],
        items: itemsPorTienda(tid).map(({ producto_id, cantidad, especificaciones }) => ({
          producto_id,
          cantidad,
          especificaciones,
        })),
      })),
    }
    await crearSurtido(payload)
    toast.success('Surtido enviado correctamente. Los vendedores han sido notificados.')
    resetWizard()
    tabActivo.value = 'historial'
    await cargarHistorial()
  } catch (e) {
    errEnvio.value = e.response?.data?.message ?? 'Error al enviar el surtido.'
  } finally {
    enviando.value = false
  }
}

function resetWizard() {
  paso.value               = 1
  productosAgr.value       = []
  tiendasSelec.value       = []
  mismasCantidades.value   = true
  cantidadesPorTienda.value = {}
  vendedoresPorTienda.value = {}
  validadoresPorTienda.value = {}
  notasSurtido.value       = ''
  errEnvio.value           = ''
  busquedaProd.value       = ''
}

async function avanzar() {
  if (paso.value === 2 && !paso2Valido.value) return
  if (paso.value === 2) await cargarVendedores()
  if (paso.value < 4) paso.value++
}

function retroceder() {
  if (paso.value > 1) paso.value--
}

// ── Historial ─────────────────────────────────────────────────────────────────
const historial      = ref([])
const cargandoHist   = ref(false)
const detalleAbierto = ref({})
const detalleData    = ref({})

async function cargarHistorial() {
  cargandoHist.value = true
  try {
    const { data } = await getSurtidos()
    historial.value = data.data ?? data
  } catch {} finally {
    cargandoHist.value = false
  }
}

async function toggleDetalle(id) {
  detalleAbierto.value[id] = !detalleAbierto.value[id]
  if (detalleAbierto.value[id] && !detalleData.value[id]) {
    try {
      const { data } = await getSurtido(id)
      detalleData.value[id] = data
    } catch {}
  }
}

function badgeEstado(estado) {
  const map = {
    enviado:          'bg-amber-100 text-amber-700',
    completado:       'bg-green-100 text-green-700',
    rechazado_parcial: 'bg-red-100 text-red-700',
  }
  return map[estado] ?? 'bg-gray-100 text-gray-600'
}

function labelEstado(estado) {
  return { enviado: 'Enviado', completado: 'Completado', rechazado_parcial: 'Rechazado parcial' }[estado] ?? estado
}

function badgeEstadoTienda(estado) {
  const map = { pendiente: 'bg-amber-100 text-amber-700', aceptado: 'bg-green-100 text-green-700', rechazado: 'bg-red-100 text-red-700' }
  return map[estado] ?? 'bg-gray-100 text-gray-600'
}

function fmtFecha(iso) {
  return iso ? new Date(iso).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
}

onMounted(async () => {
  const { data } = await getTiendas()
  tiendas.value = data
  precargarCatalogo()   // carga en segundo plano, no bloquea la UI
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-10">

    <!-- Header -->
    <div class="flex items-center gap-2">
      <ArchiveBoxArrowDownIcon class="w-6 h-6 text-blue-600" />
      <h2 class="text-lg font-bold text-gray-800 flex-1">Surtir tiendas</h2>
    </div>

    <!-- Tabs -->
    <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
      <button
        v-for="tab in [{ k: 'nuevo', label: 'Nuevo surtido' }, { k: 'historial', label: 'Historial' }]"
        :key="tab.k"
        @click="tabActivo = tab.k; tab.k === 'historial' && cargarHistorial()"
        :class="[
          'flex-1 py-2 rounded-lg text-sm font-semibold transition-colors',
          tabActivo === tab.k ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500',
        ]"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- ═══════════════ TAB: NUEVO SURTIDO (WIZARD) ═══════════════ -->
    <template v-if="tabActivo === 'nuevo'">

      <!-- Stepper -->
      <div class="flex items-center gap-0">
        <div
          v-for="(label, i) in ['Productos', 'Tiendas', 'Validadores', 'Revisión']"
          :key="i"
          class="flex items-center gap-0 flex-1"
        >
          <div class="flex flex-col items-center flex-shrink-0">
            <div :class="[
              'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-colors',
              paso > i + 1 ? 'bg-green-500 text-white' : paso === i + 1 ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-400'
            ]">
              <CheckIcon v-if="paso > i + 1" class="w-4 h-4" />
              <span v-else>{{ i + 1 }}</span>
            </div>
            <p class="text-[10px] mt-0.5 font-medium" :class="paso === i + 1 ? 'text-blue-600' : 'text-gray-400'">
              {{ label }}
            </p>
          </div>
          <div v-if="i < 3" :class="['flex-1 h-0.5 mb-4', paso > i + 1 ? 'bg-green-400' : 'bg-gray-200']" />
        </div>
      </div>

      <!-- ── PASO 1: Productos ── -->
      <div v-if="paso === 1" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">¿Qué productos vas a enviar?</h3>

        <!-- Buscador -->
        <div class="relative">
          <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            v-model="busquedaProd"
            :placeholder="cargandoCat ? 'Cargando catálogo...' : 'Buscar producto por nombre o categoría...'"
            :disabled="cargandoCat"
            class="w-full rounded-lg border border-gray-300 pl-9 pr-10 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-400"
          />
          <!-- Spinner de carga inicial del catálogo -->
          <div v-if="cargandoCat" class="absolute right-3 top-1/2 -translate-y-1/2">
            <div class="w-4 h-4 border-2 border-blue-400 border-t-transparent rounded-full animate-spin" />
          </div>
          <!-- X para limpiar -->
          <button
            v-else-if="busquedaProd"
            @click="busquedaProd = ''"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>

          <!-- Resultados — filtrado client-side, instantáneo -->
          <div v-if="resultados.length" class="absolute inset-x-0 top-full mt-1 bg-white rounded-xl shadow-lg border border-gray-200 z-20 max-h-52 overflow-y-auto">
            <button
              v-for="p in resultados"
              :key="p.id"
              @click="agregarProducto(p)"
              class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-blue-50 transition-colors"
            >
              <img v-if="p.foto_url" :src="p.foto_url" class="w-8 h-8 rounded object-cover flex-shrink-0" />
              <div class="w-8 h-8 rounded bg-gray-100 flex-shrink-0" v-else />
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ p.nombre }}</p>
                <p class="text-xs text-gray-400">{{ p.categoria }}</p>
              </div>
              <PlusIcon class="w-4 h-4 text-blue-500 flex-shrink-0" />
            </button>
          </div>
          <!-- Sin resultados -->
          <div v-else-if="busquedaProd.length >= 1 && !cargandoCat && resultados.length === 0"
            class="absolute inset-x-0 top-full mt-1 bg-white rounded-xl shadow-lg border border-gray-200 z-20 px-4 py-3 text-xs text-gray-400 text-center">
            Sin resultados para "{{ busquedaProd }}"
          </div>
        </div>

        <!-- Lista de productos agregados -->
        <div v-if="productosAgr.length === 0" class="text-center py-8 text-sm text-gray-400">
          Busca y agrega productos para surtir
        </div>

        <div v-for="(item, idx) in productosAgr" :key="item.producto.id" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
          <!-- Fila principal -->
          <div class="flex items-center gap-3 px-3 py-3">
            <img v-if="item.producto.foto_url" :src="item.producto.foto_url" class="w-10 h-10 rounded-lg object-cover flex-shrink-0" />
            <div class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0" v-else />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ item.producto.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.producto.categoria }}</p>
            </div>
            <!-- Cantidad -->
            <div class="flex items-center gap-1 flex-shrink-0">
              <button @click="item.cantidad > 1 && item.cantidad--" class="w-7 h-7 rounded-full bg-gray-100 text-gray-600 text-lg leading-none flex items-center justify-center hover:bg-gray-200">−</button>
              <input
                v-model.number="item.cantidad"
                type="number"
                min="1"
                class="w-12 text-center rounded border border-gray-300 py-1 text-sm font-bold focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <button @click="item.cantidad++" class="w-7 h-7 rounded-full bg-gray-100 text-gray-600 text-lg leading-none flex items-center justify-center hover:bg-gray-200">+</button>
            </div>
            <button @click="quitarProducto(idx)" class="text-red-400 hover:text-red-600 flex-shrink-0 ml-1">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <!-- Toggle especificaciones -->
          <button
            @click="toggleEspec(idx)"
            class="w-full flex items-center justify-between px-3 py-1.5 border-t border-gray-100 text-xs text-gray-500 hover:bg-gray-50"
          >
            <span>Especificaciones opcionales (tela, color, medidas...)</span>
            <component :is="especAbiertos[idx] ? ChevronUpIcon : ChevronDownIcon" class="w-3.5 h-3.5" />
          </button>

          <Transition name="slide">
            <div v-if="especAbiertos[idx]" class="px-3 pb-3 pt-2 bg-gray-50 border-t border-gray-100 space-y-2">

              <!-- Tela/color — solo para productos tapizados -->
              <template v-if="necesitaTela(item.producto)">
                <!-- Fila 1: Marca + Tipo de tela -->
                <div class="grid grid-cols-2 gap-2">
                  <div>
                    <label class="text-[11px] font-medium text-gray-500">Marca de tela</label>
                    <ComboInput
                      :model-value="item.especificaciones.marca"
                      :options="marcasOrdenadas"
                      placeholder="Ej: Visual, Arthometextil…"
                      class="mt-0.5"
                      @update:model-value="v => onMarcaChange(item, v)"
                    />
                  </div>
                  <div>
                    <label class="text-[11px] font-medium text-gray-500">Tipo de tela</label>
                    <ComboInput
                      :model-value="item.especificaciones.tela"
                      :options="tiposParaEsp(item.especificaciones)"
                      placeholder="Ej: Bistro, Kanvas…"
                      class="mt-0.5"
                      @update:model-value="v => onTelaChange(item, v)"
                    />
                  </div>
                </div>

                <!-- Fila 2: Color (ancho completo) -->
                <div>
                  <label class="text-[11px] font-medium text-gray-500">Color</label>
                  <ComboInput
                    :model-value="item.especificaciones.color"
                    :options="coloresParaEsp(item.especificaciones)"
                    :placeholder="coloresParaEsp(item.especificaciones).length ? 'Selecciona o escribe un color…' : 'Ej: Marfil, Beige…'"
                    class="mt-0.5"
                    @update:model-value="v => item.especificaciones.color = v"
                  />
                </div>
              </template>

              <!-- Fila 3: Medidas + Acabado -->
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="text-[11px] font-medium text-gray-500">Medidas</label>
                  <input
                    v-model="item.especificaciones.medidas"
                    type="text"
                    placeholder="Ej: 2.20 x 1.10 m"
                    class="mt-0.5 w-full rounded border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label class="text-[11px] font-medium text-gray-500">Acabado</label>
                  <input
                    v-model="item.especificaciones.acabado"
                    type="text"
                    placeholder="Ej: madera, negro…"
                    class="mt-0.5 w-full rounded border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

            </div>
          </Transition>
        </div>

        <!-- Botón siguiente -->
        <button
          @click="avanzar"
          :disabled="!paso1Valido"
          class="w-full flex items-center justify-center gap-2 bg-blue-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          Siguiente: seleccionar tiendas
          <ChevronRightIcon class="w-4 h-4" />
        </button>
      </div>

      <!-- ── PASO 2: Tiendas ── -->
      <div v-else-if="paso === 2" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">¿A qué tiendas vas a enviar?</h3>

        <!-- Toggle mismas cantidades -->
        <label class="flex items-center gap-3 bg-blue-50 rounded-xl px-4 py-3 cursor-pointer">
          <div
            @click="mismasCantidades = !mismasCantidades"
            :class="[
              'relative w-10 h-6 rounded-full transition-colors flex-shrink-0',
              mismasCantidades ? 'bg-blue-600' : 'bg-gray-300'
            ]"
          >
            <span :class="['absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform', mismasCantidades ? 'translate-x-4' : '']" />
          </div>
          <div>
            <p class="text-sm font-semibold text-gray-700">Misma cantidad para todas las tiendas</p>
            <p class="text-xs text-gray-500">{{ mismasCantidades ? 'Cada tienda recibirá exactamente lo mismo' : 'Puedes ajustar la cantidad por tienda' }}</p>
          </div>
        </label>

        <!-- Selector de tiendas -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm divide-y divide-gray-100">
          <!-- Seleccionar todas -->
          <label class="flex items-center gap-3 px-4 py-3 cursor-pointer select-none">
            <input
              type="checkbox"
              :checked="todasSelec"
              :indeterminate="tiendasSelec.length > 0 && !todasSelec"
              @change="toggleTodas"
              class="w-4 h-4 rounded text-blue-600"
            />
            <span class="text-sm font-semibold text-gray-800">Todas las tiendas</span>
          </label>

          <label
            v-for="t in tiendas"
            :key="t.id"
            class="flex items-start gap-3 px-4 py-3 cursor-pointer select-none"
          >
            <input
              type="checkbox"
              :value="t.id"
              v-model="tiendasSelec"
              class="mt-0.5 w-4 h-4 rounded text-blue-600"
            />
            <div class="flex-1">
              <p class="text-sm font-medium text-gray-800">{{ t.nombre }}</p>
              <p v-if="t.ciudad" class="text-xs text-gray-400">{{ t.ciudad }}</p>

              <!-- Cantidades por tienda (si mismasCantidades = false) -->
              <div
                v-if="!mismasCantidades && tiendasSelec.includes(t.id) && cantidadesPorTienda[t.id]"
                class="mt-2 space-y-1.5"
              >
                <div
                  v-for="pi in cantidadesPorTienda[t.id]"
                  :key="pi.producto_id"
                  class="flex items-center gap-2 bg-gray-50 rounded-lg px-2 py-1.5"
                >
                  <p class="text-xs text-gray-700 flex-1 truncate">{{ pi.nombre }}</p>
                  <div class="flex items-center gap-1">
                    <button @click.prevent="pi.cantidad > 1 && pi.cantidad--" class="w-6 h-6 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm leading-none">−</button>
                    <input
                      v-model.number="pi.cantidad"
                      type="number"
                      min="1"
                      @click.stop
                      class="w-10 text-center rounded border border-gray-300 py-0.5 text-xs font-bold focus:outline-none"
                    />
                    <button @click.prevent="pi.cantidad++" class="w-6 h-6 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm leading-none">+</button>
                  </div>
                </div>
              </div>
            </div>
          </label>
        </div>

        <div class="flex gap-2">
          <button @click="retroceder" class="flex items-center gap-1 border border-gray-300 text-gray-600 rounded-xl px-4 py-3 text-sm font-semibold hover:bg-gray-50">
            <ChevronLeftIcon class="w-4 h-4" />
            Atrás
          </button>
          <button
            @click="avanzar"
            :disabled="!paso2Valido"
            class="flex-1 flex items-center justify-center gap-2 bg-blue-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            Siguiente: elegir validadores
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- ── PASO 3: Validadores ── -->
      <div v-else-if="paso === 3" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">¿Quién valida la recepción en cada tienda?</h3>
        <p class="text-xs text-gray-500">Selecciona el vendedor de cada tienda que confirmará que los productos llegaron correctamente.</p>

        <div v-if="cargandoVendedores" class="text-center py-8">
          <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin mx-auto" />
        </div>

        <div v-else class="space-y-3">
          <div
            v-for="tid in tiendasSelec"
            :key="tid"
            class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"
          >
            <p class="text-sm font-semibold text-gray-800 mb-2">{{ nombreTienda(tid) }}</p>
            <select
              v-model="validadoresPorTienda[tid]"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option :value="undefined">Seleccionar vendedor validador...</option>
              <option
                v-for="v in (vendedoresPorTienda[tid] ?? [])"
                :key="v.id"
                :value="v.id"
              >
                {{ v.nombre }}{{ v.tienda_default_id !== tid ? ` — ${v.tienda_default?.nombre ?? 'otra tienda'}` : '' }}
              </option>
            </select>
            <p v-if="cargandoVendedores" class="text-xs text-gray-400 mt-1.5">Cargando vendedores...</p>
            <p v-else-if="!(vendedoresPorTienda[tid]?.length)" class="text-xs text-amber-600 mt-1.5">
              No se encontraron vendedores activos en el sistema.
            </p>
          </div>
        </div>

        <div class="flex gap-2">
          <button @click="retroceder" class="flex items-center gap-1 border border-gray-300 text-gray-600 rounded-xl px-4 py-3 text-sm font-semibold hover:bg-gray-50">
            <ChevronLeftIcon class="w-4 h-4" />
            Atrás
          </button>
          <button
            @click="avanzar"
            :disabled="!paso3Valido"
            class="flex-1 flex items-center justify-center gap-2 bg-blue-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            Revisar y enviar
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- ── PASO 4: Revisión y envío ── -->
      <div v-else-if="paso === 4" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">Revisa el surtido antes de enviar</h3>

        <!-- Resumen por tienda -->
        <div
          v-for="tid in tiendasSelec"
          :key="tid"
          class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 space-y-2"
        >
          <div class="flex items-center justify-between">
            <p class="text-sm font-bold text-gray-800">{{ nombreTienda(tid) }}</p>
            <p class="text-xs text-gray-500">
              Valida: <span class="font-medium text-gray-700">
                {{ vendedoresPorTienda[tid]?.find(v => v.id === validadoresPorTienda[tid])?.nombre }}
              </span>
            </p>
          </div>
          <div class="space-y-1">
            <div
              v-for="item in itemsPorTienda(tid)"
              :key="item.producto_id"
              class="flex items-center gap-2 bg-gray-50 rounded-lg px-2.5 py-1.5 text-xs"
            >
              <span class="flex-1 text-gray-700 font-medium truncate">{{ item.nombre }}</span>
              <span v-if="item.especificaciones" class="text-gray-400 truncate max-w-[140px]">
                {{ [item.especificaciones.marca, item.especificaciones.tela, item.especificaciones.color, item.especificaciones.medidas, item.especificaciones.acabado].filter(Boolean).join(' · ') }}
              </span>
              <span class="font-bold text-green-700 flex-shrink-0">× {{ item.cantidad }}</span>
            </div>
          </div>
        </div>

        <!-- Notas -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Notas generales (opcional)</label>
          <textarea
            v-model="notasSurtido"
            rows="2"
            placeholder="Instrucciones especiales, referencia de guía, etc."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
          />
        </div>

        <p v-if="errEnvio" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ errEnvio }}</p>

        <div class="flex gap-2">
          <button @click="retroceder" class="flex items-center gap-1 border border-gray-300 text-gray-600 rounded-xl px-4 py-3 text-sm font-semibold hover:bg-gray-50">
            <ChevronLeftIcon class="w-4 h-4" />
            Atrás
          </button>
          <button
            @click="enviarSurtido"
            :disabled="enviando"
            class="flex-1 flex items-center justify-center gap-2 bg-green-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-green-700 disabled:opacity-50 transition-colors"
          >
            <ArchiveBoxArrowDownIcon class="w-4 h-4" />
            {{ enviando ? 'Enviando...' : 'Enviar surtido' }}
          </button>
        </div>
      </div>
    </template>

    <!-- ═══════════════ TAB: HISTORIAL ═══════════════ -->
    <template v-else-if="tabActivo === 'historial'">
      <div v-if="cargandoHist" class="flex justify-center py-10">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else-if="historial.length === 0" class="text-center py-10 text-sm text-gray-400">
        No hay surtidos registrados.
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="s in historial"
          :key="s.id"
          class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden"
        >
          <!-- Cabecera -->
          <button
            @click="toggleDetalle(s.id)"
            class="w-full flex items-center justify-between px-4 py-3 text-left"
          >
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <p class="text-sm font-semibold text-gray-800">Surtido #{{ s.id }}</p>
                <span :class="['px-2 py-0.5 rounded-full text-xs font-semibold', badgeEstado(s.estado)]">
                  {{ labelEstado(s.estado) }}
                </span>
              </div>
              <p class="text-xs text-gray-400 mt-0.5">
                {{ fmtFecha(s.created_at) }} · {{ s.tiendas?.length ?? 0 }} tienda(s)
              </p>
            </div>
            <component :is="detalleAbierto[s.id] ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2" />
          </button>

          <!-- Detalle expandible -->
          <Transition name="slide">
            <div v-if="detalleAbierto[s.id] && detalleData[s.id]" class="border-t border-gray-100 px-4 pb-4 pt-3 space-y-3">
              <div
                v-for="st in detalleData[s.id].tiendas"
                :key="st.id"
                class="rounded-lg border border-gray-100 overflow-hidden"
              >
                <div class="flex items-center justify-between px-3 py-2 bg-gray-50">
                  <p class="text-xs font-semibold text-gray-700">{{ st.tienda?.nombre }}</p>
                  <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500">{{ st.vendedor_validador?.nombre }}</span>
                    <span :class="['px-1.5 py-0.5 rounded-full text-xs font-semibold', badgeEstadoTienda(st.estado)]">
                      {{ st.estado }}
                    </span>
                  </div>
                </div>
                <div class="divide-y divide-gray-50">
                  <div v-for="item in st.items" :key="item.id" class="flex items-center gap-2 px-3 py-1.5 text-xs">
                    <span class="flex-1 text-gray-600 truncate">{{ item.producto?.nombre }}</span>
                    <span class="font-bold text-gray-700">× {{ item.cantidad }}</span>
                  </div>
                </div>
                <p v-if="st.notas_vendedor" class="px-3 pb-2 text-xs text-red-600 italic">
                  Motivo rechazo: "{{ st.notas_vendedor }}"
                </p>
              </div>

              <p v-if="detalleData[s.id].notas" class="text-xs text-gray-500 italic">
                Notas: "{{ detalleData[s.id].notas }}"
              </p>
            </div>
          </Transition>
        </div>
      </div>
    </template>

  </div>
</template>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: all 0.18s ease; }
.slide-enter-from, .slide-leave-to       { opacity: 0; transform: translateY(-5px); }
</style>
