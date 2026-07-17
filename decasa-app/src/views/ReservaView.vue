<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import {
  MagnifyingGlassIcon, PlusIcon, ArchiveBoxIcon,
  PhotoIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'
import { getReservaInventario, addReservaStock, addReservaVarianteStock, removeReservaStock, getReservaMovimientos, getReservaInfo } from '@/api/reserva'
import { getVariantes, crearVariante } from '@/api/inventario'
import { marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import ComboInput from '@/components/common/ComboInput.vue'
import api from '@/api'

const toast      = useToast()
const auth       = useAuthStore()
const soloLectura = computed(() => !auth.isSupervisor)

const fabricaId             = ref(null)
const inventario            = ref([])
const busqueda              = ref('')
const categoriaFiltro       = ref('')
const categoriasDisponibles = ref([])
const loading               = ref(true)
const currentPage    = ref(1)
const tieneMas       = ref(false)
const loadingMore    = ref(false)
const sentinel       = ref(null)
let observer         = null

// Modal agregar stock
const mostrarEntrada  = ref(false)
const itemEntrada     = ref(null)
const cantEntrada     = ref(1)
const motivoEntrada   = ref('')
const loadEntrada     = ref(false)
const errEntrada      = ref('')

// Modal quitar stock
const mostrarSalida   = ref(false)
const itemSalida      = ref(null)
const cantSalida      = ref(1)
const motivoSalida    = ref('')
const loadSalida      = ref(false)
const errSalida       = ref('')

// Modal historial
const mostrarHistorial  = ref(false)
const itemHistorial     = ref(null)
const movimientos       = ref([])
const loadMovimientos   = ref(false)

// Foto modal
const fotoModal    = ref(false)
const fotoProducto = ref(null)

// Variantes tapizado / talla
const variantesReserva    = ref({})   // { producto_id: variante[] }
const varianteCargando    = ref({})

// Variantes personalizadas (configs)
const varianteConfigsReserva = ref({})   // { producto_id: grupos[] }
const vcConfigsCargando      = ref({})
const vcStockModal  = ref(false)
const vcStockItem   = ref(null)  // { config, grupo, productoItem }
const vcStockCant   = ref(1)
const vcStockMotivo = ref('')
const vcStockLoad   = ref(false)
const vcStockError  = ref('')
const vcStockModo   = ref('agregar')  // 'agregar' | 'quitar'
const vcStockSinAsignar = computed(() => {
  if (!vcStockItem.value) return 0
  const { grupo, productoItem } = vcStockItem.value
  const baseStock = productoItem?.cantidad_disponible ?? 0
  const totalTipo = grupo.items.reduce((s, it) => s + (it.stock_disponible ?? 0), 0)
  return Math.max(0, baseStock - totalTipo)
})

// Nueva variante (crear + agregar stock en un paso)
const mostrarNuevaVariante  = ref(false)
const nuevaVarianteProdId   = ref(null)
const nuevaVarianteCant     = ref(1)
const formVariante          = ref({ marca: '', marcaManual: '', marca_tela: '', telaManual: '', nombre_color: '', colorManual: '' })
const varianteTipoTalla     = ref(false)
const formVarianteTalla     = ref({ medida: '', precio_variante: '' })
const varianteCreandoLoad   = ref(false)
const varianteCreandoError  = ref('')

const tiposTelaOpciones = computed(() =>
  formVariante.value.marca && formVariante.value.marca !== 'Otro' ? tiposTelaDeM(formVariante.value.marca) : []
)
const coloresOpciones = computed(() =>
  formVariante.value.marca && formVariante.value.marca !== 'Otro' &&
  formVariante.value.marca_tela && formVariante.value.marca_tela !== 'Otro'
    ? coloresDeTela(formVariante.value.marca, formVariante.value.marca_tela) : []
)
const marcaFinal  = computed(() => formVariante.value.marca === 'Otro'      ? formVariante.value.marcaManual  : formVariante.value.marca)
const telaFinal   = computed(() => formVariante.value.marca_tela === 'Otro' || !tiposTelaOpciones.value.length ? formVariante.value.telaManual  : formVariante.value.marca_tela)
const colorFinal  = computed(() => formVariante.value.nombre_color === 'Otro' || !coloresOpciones.value.length ? formVariante.value.colorManual : formVariante.value.nombre_color)

function abrirNuevaVariante(productoId, esTalla = false) {
  nuevaVarianteProdId.value  = productoId
  varianteTipoTalla.value    = !!esTalla
  nuevaVarianteCant.value    = 1
  formVariante.value         = { marca: '', marcaManual: '', marca_tela: '', telaManual: '', nombre_color: '', colorManual: '' }
  formVarianteTalla.value    = { medida: '', precio_variante: '' }
  varianteCreandoError.value = ''
  mostrarNuevaVariante.value = true
}

async function guardarNuevaVariante() {
  varianteCreandoError.value = ''
  if (!nuevaVarianteCant.value || nuevaVarianteCant.value < 1) {
    varianteCreandoError.value = 'Ingresa una cantidad válida.'
    return
  }

  if (varianteTipoTalla.value) {
    if (!formVarianteTalla.value.medida.trim()) {
      varianteCreandoError.value = 'Ingresa la talla o medida.'
      return
    }
    varianteCreandoLoad.value = true
    try {
      const { data: nuevaVariante } = await crearVariante(nuevaVarianteProdId.value, {
        medida: formVarianteTalla.value.medida.trim(),
        precio_variante: formVarianteTalla.value.precio_variante || null,
      })
      await addReservaVarianteStock({ variante_id: nuevaVariante.id, cantidad: nuevaVarianteCant.value })
      toast.success('Variante de talla creada y stock agregado.')
      mostrarNuevaVariante.value = false
      const { data } = await getVariantes(nuevaVarianteProdId.value, fabricaId.value)
      variantesReserva.value[nuevaVarianteProdId.value] = data
      await cargarInventario(true)
    } catch (e) {
      varianteCreandoError.value = e.response?.data?.message ?? 'Error al crear la variante.'
    } finally {
      varianteCreandoLoad.value = false
    }
    return
  }

  if (!marcaFinal.value || !telaFinal.value || !colorFinal.value) {
    varianteCreandoError.value = 'Completa todos los campos: marca, tipo de tela y color.'
    return
  }
  varianteCreandoLoad.value = true
  try {
    const { data: nuevaVariante } = await crearVariante(nuevaVarianteProdId.value, {
      marca: marcaFinal.value, marca_tela: telaFinal.value, nombre_color: colorFinal.value,
    })
    await addReservaVarianteStock({ variante_id: nuevaVariante.id, cantidad: nuevaVarianteCant.value })
    toast.success('Variante creada y stock agregado a fábrica.')
    mostrarNuevaVariante.value = false
    const { data } = await getVariantes(nuevaVarianteProdId.value, fabricaId.value)
    variantesReserva.value[nuevaVarianteProdId.value] = data
    await cargarInventario(true)
  } catch (e) {
    varianteCreandoError.value = e.response?.data?.message ?? 'Error al crear la variante.'
  } finally {
    varianteCreandoLoad.value = false
  }
}

// Modal stock variante
const mostrarStockVariante   = ref(false)
const varianteStockItem      = ref(null)  // { variante, productoId }
const varianteStockCant      = ref(1)
const varianteStockMotivo    = ref('')
const varianteStockLoading   = ref(false)
const varianteStockError     = ref('')
const varianteStockModo      = ref('agregar')  // 'agregar' | 'quitar'

async function cargarVariantes(item) {
  const pid = item.producto_id
  if (variantesReserva.value[pid] !== undefined) return
  varianteCargando.value[pid] = true
  try {
    const { data } = await getVariantes(pid, fabricaId.value)
    variantesReserva.value[pid] = data
  } finally {
    varianteCargando.value[pid] = false
  }
}

async function cargarVarConfigsReserva(item) {
  const pid = item.producto_id
  if (varianteConfigsReserva.value[pid] !== undefined) return
  vcConfigsCargando.value[pid] = true
  try {
    const params = fabricaId.value ? { tienda_id: fabricaId.value } : {}
    const { data } = await api.get(`/productos/${pid}/variante-configs`, { params })
    varianteConfigsReserva.value[pid] = data.filter(g => g.items.length > 0)
  } catch {
    varianteConfigsReserva.value[pid] = []
  } finally {
    vcConfigsCargando.value[pid] = false
  }
}

function abrirVcStock(configItem, grupo, productoItem) {
  vcStockItem.value   = { config: configItem, grupo, productoItem }
  vcStockCant.value   = 1
  vcStockMotivo.value = ''
  vcStockError.value  = ''
  vcStockModo.value   = 'agregar'
  vcStockModal.value  = true
}

async function guardarVcStock() {
  vcStockError.value = ''
  if (vcStockCant.value < 1) { vcStockError.value = 'Cantidad inválida.'; return }
  vcStockLoad.value = true
  try {
    const endpoint = vcStockModo.value === 'quitar'
      ? '/inventario/variante-configs/salida'
      : '/inventario/variante-configs/entrada'
    await api.post(endpoint, {
      config_id: vcStockItem.value.config.id,
      tienda_id: fabricaId.value,
      cantidad:  vcStockCant.value,
      motivo:    vcStockMotivo.value || undefined,
    })
    toast.success(vcStockModo.value === 'quitar' ? 'Stock reducido.' : 'Stock agregado.')
    vcStockModal.value = false
    const pid = vcStockItem.value.productoItem.producto_id
    delete varianteConfigsReserva.value[pid]
    await cargarVarConfigsReserva(vcStockItem.value.productoItem)
  } catch (e) {
    vcStockError.value = e.response?.data?.message ?? 'Error al actualizar stock.'
  } finally {
    vcStockLoad.value = false
  }
}

async function cargarCategorias() {
  try {
    const { data } = await api.get('/productos/categorias')
    categoriasDisponibles.value = data.filter(c => c)
  } catch {}
}

function seleccionarCategoria(cat) {
  categoriaFiltro.value = cat
  cargarInventario(true)
}

async function cargarInventario(reset = false) {
  if (reset) loading.value = true
  try {
    const page = reset ? 1 : currentPage.value + 1
    const { data } = await getReservaInventario(busqueda.value.trim(), page, categoriaFiltro.value)
    if (reset) {
      inventario.value = data.data
      variantesReserva.value = {}
      varianteConfigsReserva.value = {}
    } else {
      inventario.value.push(...data.data)
    }
    currentPage.value = data.current_page
    tieneMas.value = data.current_page < data.last_page
    // Cargar variantes para tapizados
    nextTick(() => inventario.value.forEach(i => {
      if (i.producto?.es_tapizado || i.producto?.tiene_tallas) cargarVariantes(i)
      cargarVarConfigsReserva(i)
    }))
  } catch {
    if (reset) inventario.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
  if (tieneMas.value) nextTick(setupObserver)
}

function abrirStockVariante(variante, productoId) {
  // Si el producto tiene variantes personalizadas → combo modal
  if (varianteConfigsReserva.value[productoId]?.length > 0) {
    abrirCombModal(variante, productoId)
    return
  }
  varianteStockItem.value = { variante, productoId }
  varianteStockCant.value = 1
  varianteStockMotivo.value = ''
  varianteStockError.value = ''
  varianteStockModo.value = 'agregar'
  mostrarStockVariante.value = true
}

// ── Modal combinación tela × variante personalizada (fábrica) ─────────────────
const combModal             = ref(false)
const combModalProdId       = ref(null)
const combModalVarianteId   = ref(null)
const combModalConfigId     = ref(null)
const combModalCant         = ref(1)
const combModalMotivo       = ref('')
const combModalError        = ref('')
const combModalLoad         = ref(false)
const combModalRawVariantes = ref([])
const combModalModo         = ref('agregar')  // 'agregar' | 'quitar'

const combModalQuitarMax = computed(() => {
  if (!combModalVarianteId.value || !combModalConfigId.value || !combModalProdId.value) return 0
  const entry = (variantesReserva.value[combModalProdId.value] ?? []).find(
    c => c.id === combModalVarianteId.value && c._config_id === combModalConfigId.value
  )
  return entry?.stock_libre ?? 0
})

const combModalMaxCant = computed(() => {
  if (!combModalConfigId.value || !combModalProdId.value) return 0
  const vcGroups = varianteConfigsReserva.value[combModalProdId.value] ?? []
  let customTotal = 0
  for (const g of vcGroups) {
    const optItem = g.items.find(i => i.id === combModalConfigId.value)
    if (optItem) { customTotal = optItem.stock_disponible ?? 0; break }
  }
  const usadoPorCombos = (variantesReserva.value[combModalProdId.value] ?? [])
    .filter(c => c._config_id === combModalConfigId.value)
    .reduce((s, c) => s + (c.stock_disponible ?? 0), 0)
  return Math.max(0, customTotal - usadoPorCombos)
})

async function abrirCombModal(variante, productoId) {
  combModalProdId.value     = productoId
  combModalVarianteId.value = variante.id
  combModalConfigId.value   = variante._config_id ?? null
  combModalCant.value       = 1
  combModalMotivo.value     = ''
  combModalError.value      = ''
  combModalModo.value       = 'agregar'
  combModalRawVariantes.value = []
  combModal.value           = true
  try {
    const { data } = await api.get(`/productos/${productoId}/variantes`, {
      params: { tienda_id: fabricaId.value, skip_combos: 1 }
    })
    combModalRawVariantes.value = data
  } catch { combModalRawVariantes.value = [] }
}

async function guardarCombinacion() {
  combModalError.value = ''
  if (!combModalVarianteId.value) { combModalError.value = 'Selecciona un tipo de tela.'; return }
  if (!combModalConfigId.value)   { combModalError.value = 'Selecciona la variante.'; return }
  if (combModalCant.value < 1)    { combModalError.value = 'Cantidad inválida.'; return }
  combModalLoad.value = true
  try {
    const endpoint = combModalModo.value === 'quitar'
      ? '/inventario/variante-combinaciones/salida'
      : '/inventario/variante-combinaciones/entrada'
    await api.post(endpoint, {
      variante_id: combModalVarianteId.value,
      config_id:   combModalConfigId.value,
      tienda_id:   fabricaId.value,
      cantidad:    combModalCant.value,
      motivo:      combModalMotivo.value || undefined,
    })
    toast.success(combModalModo.value === 'quitar' ? 'Stock de combinación reducido.' : 'Combinación asignada.')
    combModal.value = false
    const pid = combModalProdId.value
    delete variantesReserva.value[pid]
    delete varianteConfigsReserva.value[pid]
    const invItem = inventario.value.find(i => i.producto_id === pid)
    if (invItem) {
      await cargarVariantes(invItem)
      await cargarVarConfigsReserva(invItem)
    }
  } catch (e) {
    combModalError.value = e.response?.data?.message ?? 'Error al guardar.'
  } finally {
    combModalLoad.value = false
  }
}

async function guardarStockVariante() {
  varianteStockError.value = ''
  if (varianteStockCant.value < 1) { varianteStockError.value = 'Cantidad inválida.'; return }
  varianteStockLoading.value = true
  try {
    if (varianteStockModo.value === 'quitar') {
      await api.post('/inventario/variantes/salida', {
        variante_id: varianteStockItem.value.variante.id,
        tienda_id:   fabricaId.value,
        cantidad:    varianteStockCant.value,
        motivo:      varianteStockMotivo.value || undefined,
      })
      toast.success('Stock de variante reducido.')
    } else {
      await addReservaVarianteStock({
        variante_id: varianteStockItem.value.variante.id,
        cantidad: varianteStockCant.value,
        motivo: varianteStockMotivo.value || undefined,
      })
      toast.success('Stock de variante agregado.')
    }
    mostrarStockVariante.value = false
    const pid = varianteStockItem.value.productoId
    const { data } = await getVariantes(pid, fabricaId.value)
    variantesReserva.value[pid] = data
  } catch (e) {
    varianteStockError.value = e.response?.data?.message ?? 'Error al actualizar stock.'
  } finally {
    varianteStockLoading.value = false
  }
}

function loadMore() {
  if (loadingMore.value || !tieneMas.value) return
  loadingMore.value = true
  cargarInventario(false)
}

function setupObserver() {
  if (observer) observer.disconnect()
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && tieneMas.value && !loadingMore.value) loadMore()
  }, { rootMargin: '200px' })
  nextTick(() => { if (sentinel.value) observer.observe(sentinel.value) })
}

function openEntrada(item) {
  itemEntrada.value = item
  cantEntrada.value = 1
  motivoEntrada.value = ''
  errEntrada.value = ''
  mostrarEntrada.value = true
}

async function guardarEntrada() {
  errEntrada.value = ''
  if (!cantEntrada.value || cantEntrada.value < 1) { errEntrada.value = 'Cantidad inválida.'; return }
  loadEntrada.value = true
  try {
    await addReservaStock({
      producto_id: itemEntrada.value.producto_id,
      cantidad: cantEntrada.value,
      motivo: motivoEntrada.value || undefined,
    })
    toast.success('Stock agregado a fábrica.')
    mostrarEntrada.value = false
    await cargarInventario(true)
  } catch (e) {
    errEntrada.value = e.response?.data?.message ?? 'Error al agregar stock.'
  } finally {
    loadEntrada.value = false
  }
}

function openSalida(item) {
  itemSalida.value = item
  cantSalida.value = 1
  motivoSalida.value = ''
  errSalida.value = ''
  mostrarSalida.value = true
}

async function guardarSalida() {
  errSalida.value = ''
  if (!cantSalida.value || cantSalida.value < 1) { errSalida.value = 'Cantidad inválida.'; return }
  loadSalida.value = true
  try {
    await removeReservaStock({
      producto_id: itemSalida.value.producto_id,
      cantidad: cantSalida.value,
      motivo: motivoSalida.value || undefined,
    })
    toast.success('Stock removido de fábrica.')
    mostrarSalida.value = false
    await cargarInventario(true)
  } catch (e) {
    errSalida.value = e.response?.data?.message ?? 'Error al quitar stock.'
  } finally {
    loadSalida.value = false
  }
}

async function openHistorial(item) {
  itemHistorial.value = item
  movimientos.value = []
  loadMovimientos.value = true
  mostrarHistorial.value = true
  try {
    const { data } = await getReservaMovimientos(item.producto_id)
    movimientos.value = data
  } finally {
    loadMovimientos.value = false
  }
}

onMounted(async () => {
  try { const { data } = await getReservaInfo(); fabricaId.value = data.id } catch {}
  cargarCategorias()
  cargarInventario(true)
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        Reserva de Fábrica<span v-if="soloLectura" class="ml-2 text-xs font-normal text-gray-400">(solo lectura)</span>
      </h2>
    </div>

    <div class="flex items-center gap-2 bg-purple-50 rounded-lg px-3 py-2">
      <ArchiveBoxIcon class="w-4 h-4 text-purple-500 flex-shrink-0" />
      <p class="text-xs text-purple-700 font-medium">Productos terminados en fábrica — disponibles para venta directa al cliente</p>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="categoriaFiltro = ''; cargarInventario(true)"
        placeholder="Buscar por nombre o categoría..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
      />
    </div>

    <!-- Filtro por categoría -->
    <div v-if="categoriasDisponibles.length" class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
      <button
        @click="seleccionarCategoria('')"
        :class="[
          'shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          categoriaFiltro === ''
            ? 'bg-purple-600 text-white border-purple-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-purple-400'
        ]"
      >
        Todas
      </button>
      <button
        v-for="cat in categoriasDisponibles"
        :key="cat"
        @click="seleccionarCategoria(cat)"
        :class="[
          'shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          categoriaFiltro === cat
            ? 'bg-purple-600 text-white border-purple-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-purple-400'
        ]"
      >
        {{ cat }}
      </button>
    </div>

    <AppSpinner v-if="loading" />

    <EmptyState v-else-if="inventario.length === 0" message="No hay productos en reserva de fábrica." />

    <template v-else>
      <ul class="space-y-2">
        <li v-for="item in inventario" :key="item.producto_id" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
          <div class="flex items-start gap-3">
            <!-- Foto -->
            <button
              @click="item.producto?.foto_url && (fotoProducto = item.producto, fotoModal = true)"
              :class="['flex-shrink-0 w-12 h-12 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center',
                item.producto?.foto_url ? 'cursor-pointer hover:opacity-75' : 'cursor-default']"
            >
              <img v-if="item.producto?.foto_url" :src="cloudinaryOpt(item.producto.foto_url, 160)" :alt="item.producto.nombre" class="w-full h-full object-cover" />
              <PhotoIcon v-else class="w-6 h-6 text-gray-300" />
            </button>

            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800 truncate">{{ item.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.producto?.categoria }}</p>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
              <button @click="openHistorial(item)" class="text-gray-500 text-xs font-medium flex items-center gap-1">
                <ArchiveBoxIcon class="w-4 h-4" /> Historial
              </button>
            </div>
          </div>

          <!-- Stock -->
          <div class="grid grid-cols-3 gap-2 text-center">
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-800">{{ item.cantidad_disponible }}</p>
              <p class="text-xs text-gray-400">Disponible</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-amber-600">{{ item.cantidad_reservada }}</p>
              <p class="text-xs text-gray-400">Reservado</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold" :class="item.stock_libre > 0 ? 'text-green-600' : 'text-red-500'">{{ item.stock_libre }}</p>
              <p class="text-xs text-gray-400">Libre</p>
            </div>
          </div>

          <!-- Acciones (solo supervisor) -->
          <div v-if="!soloLectura" class="flex gap-2">
            <button @click="openEntrada(item)" class="flex-1 flex items-center justify-center gap-1.5 bg-green-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-green-700">
              <PlusIcon class="w-4 h-4" /> Agregar
            </button>
            <button @click="openSalida(item)" class="flex-1 flex items-center justify-center gap-1.5 bg-red-50 text-red-600 rounded-lg py-2 text-sm font-semibold hover:bg-red-100">
              <XMarkIcon class="w-4 h-4" /> Quitar
            </button>
          </div>

          <!-- Variantes tapizado / talla -->
          <div v-if="item.producto?.es_tapizado || item.producto?.tiene_tallas" class="border-t border-gray-100 pt-2">
            <p class="text-xs font-medium text-purple-700 mb-2">{{ item.producto?.tiene_tallas ? 'Variantes por talla en fábrica' : 'Variantes de tela/color en fábrica' }}</p>
            <div v-if="varianteCargando[item.producto_id]" class="text-xs text-gray-400">Cargando...</div>
            <template v-else-if="variantesReserva[item.producto_id]">
              <div class="flex flex-wrap gap-1.5">
                <component
                  :is="soloLectura ? 'span' : 'button'"
                  v-for="v in variantesReserva[item.producto_id]"
                  :key="v._config_id !== undefined ? 'cfg-' + v._config_id + '-v' + v.id : 'var-' + v.id"
                  v-bind="soloLectura ? {} : { onClick: () => abrirStockVariante(v, item.producto_id) }"
                  :class="['px-2.5 py-1 rounded-full text-xs font-medium border',
                    v.stock_libre > 0
                      ? (v._config_label ? 'bg-indigo-50 border-indigo-300 text-indigo-800' : 'bg-green-50 border-green-300 text-green-800')
                      : 'bg-gray-50 border-gray-200 text-gray-400',
                    !soloLectura && 'transition-colors']"
                >
                  <template v-if="item.producto?.tiene_tallas">
                    {{ v.medida }}<span v-if="v.precio_variante" class="ml-1 opacity-70">${{ Number(v.precio_variante).toLocaleString('es-CO') }}</span>
                  </template>
                  <template v-else>
                    {{ [v.marca_tela, v.nombre_color].filter(Boolean).join(' · ') }}<span v-if="v._config_label" class="text-indigo-600"> · {{ v._config_label }}</span>
                  </template>
                  <span class="ml-1 font-bold">{{ v.stock_libre ?? 0 }}</span>
                </component>
                <span v-if="!variantesReserva[item.producto_id]?.length" class="text-xs text-gray-400 italic">Sin variantes registradas</span>
              </div>
              <button
                v-if="!soloLectura"
                @click="abrirNuevaVariante(item.producto_id, item.producto?.tiene_tallas)"
                class="text-xs text-purple-600 font-medium hover:text-purple-800 mt-1"
              >+ Nueva variante</button>
            </template>
          </div>

          <!-- Variantes personalizadas en fábrica -->
          <div
            v-for="grupo in varianteConfigsReserva[item.producto_id]"
            :key="grupo.tipo_variante_id"
            class="border-t border-gray-100 pt-2"
          >
            <p class="text-xs font-medium text-purple-700 mb-2">{{ grupo.tipo.nombre }}</p>
            <div class="flex flex-wrap gap-1.5">
              <component
                :is="soloLectura ? 'span' : 'button'"
                v-for="configItem in grupo.items"
                :key="configItem.id"
                v-bind="soloLectura ? {} : { onClick: () => abrirVcStock(configItem, grupo, item) }"
                :class="['px-2.5 py-1 rounded-full text-xs font-medium border',
                  (configItem.stock_disponible ?? 0) > 0 ? 'bg-green-50 border-green-300 text-green-800' : 'bg-gray-50 border-gray-200 text-gray-400']"
              >
                {{ configItem.opcion_nombre }}
                <span v-if="grupo.tipo.afecta_precio && configItem.precio_adicional > 0" class="opacity-70 ml-1">
                  +${{ Number(configItem.precio_adicional).toLocaleString('es-CO') }}
                </span>
                <span class="ml-1 font-bold">{{ configItem.stock_disponible ?? 0 }}</span>
              </component>
            </div>
          </div>
        </li>
      </ul>

      <div ref="sentinel" class="py-4 text-center">
        <div v-if="loadingMore" class="text-sm text-gray-400">Cargando más...</div>
        <div v-else-if="!tieneMas && inventario.length > 0" class="text-xs text-gray-300">{{ inventario.length }} productos</div>
      </div>
    </template>

    <!-- Lightbox foto -->
    <Transition name="fade">
      <div v-if="fotoModal" class="fixed inset-0 z-[60] flex items-center justify-center p-6" @click="fotoModal = false">
        <div class="absolute inset-0 bg-black/85" />
        <div class="relative w-full max-w-sm">
          <button @click="fotoModal = false" class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg">
            <XMarkIcon class="w-5 h-5 text-gray-700" />
          </button>
          <div class="bg-white rounded-2xl overflow-hidden shadow-2xl">
            <img :src="cloudinaryOpt(fotoProducto?.foto_url, 800)" :alt="fotoProducto?.nombre" class="w-full object-contain max-h-72" />
            <div class="px-4 py-3 border-t border-gray-100">
              <p class="text-sm font-semibold text-gray-800 text-center">{{ fotoProducto?.nombre }}</p>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal Agregar stock -->
    <Transition name="fade">
      <div v-if="mostrarEntrada" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarEntrada = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">Agregar a fábrica</h3>
              <p class="text-xs text-gray-500 mt-0.5 truncate">{{ itemEntrada?.producto?.nombre }}</p>
            </div>
            <button @click="mostrarEntrada = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
            <input v-model.number="cantEntrada" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="motivoEntrada" type="text" placeholder="Ej: Producción completada..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <p v-if="errEntrada" class="text-xs text-red-600">{{ errEntrada }}</p>
          <div class="flex gap-3">
            <button @click="mostrarEntrada = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarEntrada" :disabled="loadEntrada" class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-50">
              {{ loadEntrada ? 'Guardando...' : 'Agregar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal Quitar stock -->
    <Transition name="fade">
      <div v-if="mostrarSalida" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarSalida = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">Quitar de fábrica</h3>
              <p class="text-xs text-gray-500 mt-0.5 truncate">{{ itemSalida?.producto?.nombre }} — libre: {{ itemSalida?.stock_libre }}</p>
            </div>
            <button @click="mostrarSalida = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
            <input v-model.number="cantSalida" type="number" min="1" :max="itemSalida?.stock_libre" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="motivoSalida" type="text" placeholder="Ej: Pérdida, ajuste..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" />
          </div>
          <p v-if="errSalida" class="text-xs text-red-600">{{ errSalida }}</p>
          <div class="flex gap-3">
            <button @click="mostrarSalida = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarSalida" :disabled="loadSalida" class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-red-700 disabled:opacity-50">
              {{ loadSalida ? 'Guardando...' : 'Quitar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal nueva variante tapizado -->
    <Transition name="fade">
      <div v-if="mostrarNuevaVariante" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarNuevaVariante = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">{{ varianteTipoTalla ? 'Nueva talla' : 'Nueva variante de tela' }}</h3>
            <button @click="mostrarNuevaVariante = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <!-- Formulario talla -->
          <template v-if="varianteTipoTalla">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Talla / Medida *</label>
              <input v-model="formVarianteTalla.medida" type="text" placeholder="Ej: 1.00 x 1.90"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Precio de venta (opcional)</label>
              <input v-model.number="formVarianteTalla.precio_variante" type="number" min="0" placeholder="Ej: 850000"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad en fábrica *</label>
              <input v-model.number="nuevaVarianteCant" type="number" min="1"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
            </div>
          </template>

          <!-- Formulario tela -->
          <template v-else>
          <!-- Marca -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Marca fabricante *</label>
            <ComboInput
              :model-value="formVariante.marca"
              :options="[...marcasOrdenadas, 'Otro']"
              placeholder="Buscar marca..."
              @update:model-value="v => { formVariante.marca = v; formVariante.marca_tela = ''; formVariante.nombre_color = '' }"
            />
            <input v-if="formVariante.marca === 'Otro'" v-model="formVariante.marcaManual"
              class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="Nombre de la marca..." />
          </div>

          <!-- Tipo tela -->
          <div v-if="formVariante.marca">
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de tela *</label>
            <ComboInput
              v-if="tiposTelaOpciones.length"
              :model-value="formVariante.marca_tela"
              :options="[...tiposTelaOpciones, 'Otro']"
              placeholder="Buscar tipo de tela..."
              @update:model-value="v => { formVariante.marca_tela = v; formVariante.nombre_color = '' }"
            />
            <input v-else v-model="formVariante.telaManual"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="Nombre de la tela..." />
            <input v-if="formVariante.marca_tela === 'Otro'" v-model="formVariante.telaManual"
              class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="Nombre de la tela..." />
          </div>

          <!-- Color -->
          <div v-if="formVariante.marca && (formVariante.marca_tela || formVariante.marca === 'Otro')">
            <label class="block text-sm font-medium text-gray-700 mb-1">Color *</label>
            <ComboInput
              v-if="coloresOpciones.length"
              :model-value="formVariante.nombre_color"
              :options="[...coloresOpciones, 'Otro']"
              placeholder="Buscar color..."
              @update:model-value="v => formVariante.nombre_color = v"
            />
            <input v-else v-model="formVariante.colorManual"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="Nombre del color..." />
            <input v-if="formVariante.nombre_color === 'Otro'" v-model="formVariante.colorManual"
              class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="Nombre del color..." />
          </div>

          <!-- Cantidad inicial -->
          <div v-if="marcaFinal && telaFinal && colorFinal">
            <div class="bg-purple-50 rounded-lg px-3 py-2 text-xs text-purple-700 font-medium mb-2">
              {{ marcaFinal }} · {{ telaFinal }} · {{ colorFinal }}
            </div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad en fábrica *</label>
            <input v-model.number="nuevaVarianteCant" type="number" min="1"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          </template><!-- /v-else formulario tela -->

          <p v-if="varianteCreandoError" class="text-xs text-red-600">{{ varianteCreandoError }}</p>
          <div class="flex gap-3 pt-1">
            <button @click="mostrarNuevaVariante = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarNuevaVariante" :disabled="varianteCreandoLoad"
              class="flex-1 bg-purple-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-purple-700 disabled:opacity-50">
              {{ varianteCreandoLoad ? 'Guardando...' : 'Crear y agregar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal stock variante tapizado -->
    <Transition name="fade">
      <div v-if="mostrarStockVariante" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarStockVariante = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">{{ varianteStockModo === 'agregar' ? 'Agregar' : 'Quitar' }} stock de variante</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                {{ varianteStockItem?.variante?.medida ?? [varianteStockItem?.variante?.marca, varianteStockItem?.variante?.marca_tela, varianteStockItem?.variante?.nombre_color].filter(Boolean).join(' · ') }}
              </p>
            </div>
            <button @click="mostrarStockVariante = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <!-- Toggle agregar / quitar -->
          <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm font-medium">
            <button @click="varianteStockModo = 'agregar'; varianteStockCant = 1"
              :class="['flex-1 py-2 transition-colors', varianteStockModo === 'agregar' ? 'bg-purple-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
              + Agregar
            </button>
            <button @click="varianteStockModo = 'quitar'; varianteStockCant = 1"
              :class="['flex-1 py-2 transition-colors', varianteStockModo === 'quitar' ? 'bg-red-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
              − Quitar
            </button>
          </div>
          <p v-if="varianteStockModo === 'agregar'" class="text-xs text-blue-600 bg-blue-50 rounded-lg px-3 py-2">Asegúrate de haber agregado el stock base del producto primero.</p>
          <div v-else class="bg-gray-50 rounded-lg px-3 py-2 text-xs space-y-0.5">
            <div class="flex justify-between text-gray-600">
              <span>Stock disponible (sin reservar)</span>
              <span class="font-semibold" :class="(varianteStockItem?.variante?.stock_libre ?? 0) > 0 ? 'text-green-700' : 'text-red-600'">
                {{ varianteStockItem?.variante?.stock_libre ?? 0 }}
              </span>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Cantidad
              <span v-if="varianteStockModo === 'quitar'" class="text-gray-400 font-normal">(máx {{ varianteStockItem?.variante?.stock_libre ?? 0 }})</span>
            </label>
            <input v-model.number="varianteStockCant" type="number" min="1"
              :max="varianteStockModo === 'quitar' ? (varianteStockItem?.variante?.stock_libre ?? 0) : undefined"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="varianteStockMotivo" type="text"
              :placeholder="varianteStockModo === 'agregar' ? 'Producción completada...' : 'Ajuste de inventario...'"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <p v-if="varianteStockError" class="text-xs text-red-600">{{ varianteStockError }}</p>
          <div class="flex gap-3">
            <button @click="mostrarStockVariante = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarStockVariante"
              :disabled="varianteStockLoading || (varianteStockModo === 'quitar' && (varianteStockItem?.variante?.stock_libre ?? 0) === 0)"
              :class="['flex-1 text-white rounded-lg py-2.5 text-sm font-semibold disabled:opacity-50', varianteStockModo === 'agregar' ? 'bg-purple-600 hover:bg-purple-700' : 'bg-red-600 hover:bg-red-700']">
              {{ varianteStockLoading ? 'Guardando...' : varianteStockModo === 'agregar' ? 'Agregar' : 'Quitar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Asignar/Quitar tela × variante personalizada (combinación) -->
    <Transition name="fade">
      <div v-if="combModal" class="fixed inset-0 z-[65] flex items-end sm:items-center justify-center" @click.self="combModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[88vh] overflow-y-auto">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">{{ combModalModo === 'agregar' ? 'Asignar' : 'Quitar' }} tela × variante</h3>
              <p class="text-xs text-indigo-600 mt-0.5">Combinación tela/color · opción de variante</p>
            </div>
            <button @click="combModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <!-- Toggle agregar / quitar -->
          <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm font-medium">
            <button @click="combModalModo = 'agregar'; combModalCant = 1"
              :class="['flex-1 py-2 transition-colors', combModalModo === 'agregar' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
              + Agregar
            </button>
            <button @click="combModalModo = 'quitar'; combModalCant = 1"
              :class="['flex-1 py-2 transition-colors', combModalModo === 'quitar' ? 'bg-red-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
              − Quitar
            </button>
          </div>
          <div class="space-y-4">
            <div>
              <p class="text-sm font-semibold text-gray-700 mb-2">Tela / Color</p>
              <div class="space-y-1.5 max-h-36 overflow-y-auto pr-1">
                <p v-if="!combModalRawVariantes.length" class="text-xs text-gray-400 italic px-2 py-1">Cargando telas...</p>
                <button
                  v-for="v in combModalRawVariantes" :key="v.id"
                  @click="combModalVarianteId = v.id; combModalCant = 1"
                  :class="['w-full text-left px-3 py-2 rounded-lg border text-sm transition-colors',
                    combModalVarianteId === v.id
                      ? 'border-indigo-500 bg-indigo-50 text-indigo-700 font-medium'
                      : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50']"
                >{{ [v.marca, v.marca_tela, v.nombre_color].filter(Boolean).join(' · ') }}</button>
              </div>
            </div>
            <div>
              <p class="text-sm font-semibold text-gray-700 mb-2">Variante personalizada</p>
              <template v-for="grupo in (varianteConfigsReserva[combModalProdId] ?? [])" :key="grupo.tipo_variante_id">
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-1">{{ grupo.tipo.nombre }}</p>
                <div class="space-y-1.5 mb-3">
                  <button
                    v-for="opt in grupo.items" :key="opt.id"
                    @click="combModalConfigId = opt.id; combModalCant = 1"
                    :class="['w-full text-left px-3 py-2 rounded-lg border text-sm transition-colors',
                      combModalConfigId === opt.id
                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700 font-medium'
                        : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50']"
                  >{{ opt.opcion_nombre }} <span class="text-xs text-gray-400 ml-2">{{ opt.stock_disponible ?? 0 }} asignadas</span></button>
                </div>
              </template>
            </div>
            <template v-if="combModalConfigId && combModalVarianteId">
              <div v-if="combModalModo === 'agregar'" class="bg-indigo-50 rounded-lg px-3 py-2 text-xs text-indigo-700">
                Disponible para asignar: <strong>{{ combModalMaxCant }}</strong>
              </div>
              <div v-else class="bg-red-50 rounded-lg px-3 py-2 text-xs text-red-700">
                Stock disponible en esta combinación: <strong>{{ combModalQuitarMax }}</strong>
              </div>
            </template>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Cantidad
                <span v-if="combModalModo === 'agregar'" class="text-gray-400 font-normal">(máx {{ combModalMaxCant }})</span>
                <span v-else class="text-gray-400 font-normal">(máx {{ combModalQuitarMax }})</span>
              </label>
              <input v-model.number="combModalCant" type="number" min="1"
                :max="combModalModo === 'agregar' ? combModalMaxCant : combModalQuitarMax"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
              <input v-model="combModalMotivo"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                :placeholder="combModalModo === 'agregar' ? 'Entrada de bodega...' : 'Ajuste de inventario...'" />
            </div>
            <p v-if="combModalError" class="text-xs text-red-600">{{ combModalError }}</p>
            <button @click="guardarCombinacion"
              :disabled="combModalLoad || !combModalVarianteId || !combModalConfigId || (combModalModo === 'agregar' ? combModalMaxCant === 0 : combModalQuitarMax === 0)"
              :class="['w-full text-white rounded-lg py-2.5 text-sm font-semibold disabled:opacity-50', combModalModo === 'agregar' ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-red-600 hover:bg-red-700']">
              {{ combModalLoad ? 'Guardando...' : combModalModo === 'agregar' ? 'Asignar' : 'Quitar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal stock variante personalizada -->
    <Transition name="fade">
      <div v-if="vcStockModal" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="vcStockModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">{{ vcStockModo === 'agregar' ? 'Agregar' : 'Quitar' }} stock · variante</h3>
              <p class="text-xs text-gray-500 mt-0.5">{{ vcStockItem?.grupo?.tipo?.nombre }} — {{ vcStockItem?.config?.opcion_nombre }}</p>
            </div>
            <button @click="vcStockModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <!-- Toggle agregar / quitar -->
          <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm font-medium">
            <button @click="vcStockModo = 'agregar'; vcStockCant = 1"
              :class="['flex-1 py-2 transition-colors', vcStockModo === 'agregar' ? 'bg-purple-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
              + Agregar
            </button>
            <button @click="vcStockModo = 'quitar'; vcStockCant = 1"
              :class="['flex-1 py-2 transition-colors', vcStockModo === 'quitar' ? 'bg-red-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
              − Quitar
            </button>
          </div>
          <!-- Info agregar -->
          <template v-if="vcStockModo === 'agregar'">
            <div class="bg-gray-50 rounded-lg px-3 py-2 text-xs space-y-0.5">
              <div class="flex justify-between text-gray-600">
                <span>Stock base del producto</span>
                <span class="font-semibold">{{ vcStockItem?.productoItem?.cantidad_disponible ?? 0 }}</span>
              </div>
              <div class="flex justify-between text-gray-600">
                <span>Sin asignar ({{ vcStockItem?.grupo?.tipo?.nombre }})</span>
                <span class="font-semibold" :class="vcStockSinAsignar > 0 ? 'text-green-700' : 'text-red-600'">{{ vcStockSinAsignar }}</span>
              </div>
              <div class="flex justify-between text-gray-600">
                <span>Ya asignadas a esta opción</span>
                <span class="font-semibold">{{ vcStockItem?.config?.stock_disponible ?? 0 }}</span>
              </div>
            </div>
            <p v-if="vcStockSinAsignar === 0" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">
              No hay unidades sin asignar. Agrega más stock base primero.
            </p>
          </template>
          <!-- Info quitar -->
          <div v-else class="bg-gray-50 rounded-lg px-3 py-2 text-xs space-y-0.5">
            <div class="flex justify-between text-gray-600">
              <span>Stock asignado a esta opción</span>
              <span class="font-semibold">{{ vcStockItem?.config?.stock_disponible ?? 0 }}</span>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Cantidad
              <span v-if="vcStockModo === 'agregar'" class="text-gray-400 font-normal">(máx {{ vcStockSinAsignar }})</span>
              <span v-else class="text-gray-400 font-normal">(máx {{ vcStockItem?.config?.stock_disponible ?? 0 }})</span>
            </label>
            <input v-model.number="vcStockCant" type="number" min="1"
              :max="vcStockModo === 'agregar' ? vcStockSinAsignar : (vcStockItem?.config?.stock_disponible ?? 0)"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <input v-model="vcStockMotivo" type="text"
              :placeholder="vcStockModo === 'agregar' ? 'Producción completada...' : 'Ajuste de inventario...'"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
          </div>
          <p v-if="vcStockError" class="text-xs text-red-600">{{ vcStockError }}</p>
          <div class="flex gap-3">
            <button @click="vcStockModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarVcStock"
              :disabled="vcStockLoad || (vcStockModo === 'agregar' ? vcStockSinAsignar === 0 : (vcStockItem?.config?.stock_disponible ?? 0) === 0)"
              :class="['flex-1 text-white rounded-lg py-2.5 text-sm font-semibold disabled:opacity-50', vcStockModo === 'agregar' ? 'bg-purple-600 hover:bg-purple-700' : 'bg-red-600 hover:bg-red-700']">
              {{ vcStockLoad ? 'Guardando...' : vcStockModo === 'agregar' ? 'Agregar' : 'Quitar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal Historial -->
    <Transition name="fade">
      <div v-if="mostrarHistorial" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarHistorial = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[80vh] flex flex-col">
          <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <div>
              <h3 class="text-lg font-bold text-gray-800">Historial fábrica</h3>
              <p class="text-xs text-gray-500 mt-0.5">{{ itemHistorial?.producto?.nombre }}</p>
            </div>
            <button @click="mostrarHistorial = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-2">
            <div v-if="loadMovimientos" class="text-sm text-gray-400 text-center py-8">Cargando...</div>
            <div v-else-if="movimientos.length === 0" class="text-sm text-gray-400 text-center py-8">Sin movimientos</div>
            <div v-else v-for="m in movimientos" :key="m.id" class="flex items-start gap-3 py-2 border-b border-gray-50 last:border-0">
              <span class="mt-0.5 text-xs font-bold px-2 py-0.5 rounded-full shrink-0"
                :class="m.tipo === 'entrada' ? 'bg-green-100 text-green-700' : m.tipo === 'salida' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">
                {{ m.tipo === 'entrada' ? 'Entrada' : m.tipo === 'salida' ? 'Salida' : 'Reserva' }}
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800">{{ m.cantidad }} unidad(es)</p>
                <p class="text-xs text-gray-500 truncate">{{ m.motivo ?? '—' }}</p>
                <p class="text-xs text-gray-400">{{ new Date(m.created_at).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
