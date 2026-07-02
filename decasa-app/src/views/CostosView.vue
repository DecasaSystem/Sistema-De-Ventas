<script setup>
import { ref, computed, onMounted } from 'vue'
import { getFichas, getFicha, crearFicha, getMaterialesSugeridos, actualizarItems, reimportarFichas } from '@/api/fichas'
import { getMateriales, crearMaterial, actualizarMaterial, importarMateriales } from '@/api/materiales'
import { getCostos, guardarCostos, crearCargo, eliminarCargo, crearProceso, eliminarProceso } from '@/api/configuracion'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
import * as XLSX from 'xlsx'
import {
  MagnifyingGlassIcon,
  XMarkIcon,
  ArrowPathIcon,
  ChevronRightIcon,
  WrenchScrewdriverIcon,
  CubeIcon,
  PencilSquareIcon,
  CheckIcon,
  PlusIcon,
  TrashIcon,
  ArrowDownTrayIcon,
} from '@heroicons/vue/24/outline'

// ── TAB activo ────────────────────────────────────────────────────────────────
const tab = ref('productos') // 'productos' | 'materiales' | 'tarifas'

// ════════════════════════════════════════════════════════════════════════════════
// TAB: PRODUCTOS
// ════════════════════════════════════════════════════════════════════════════════

const fichas     = ref([])
const categorias = ref([])
const loading    = ref(false)
const search     = ref('')
const catActiva  = ref('')

async function cargar() {
  loading.value = true
  try {
    const res = await getFichas({ search: search.value, categoria: catActiva.value })
    fichas.value     = res.data.fichas
    categorias.value = res.data.categorias
  } finally {
    loading.value = false
  }
}

function limpiarBusqueda() {
  search.value    = ''
  catActiva.value = ''
  cargar()
}

let debounce = null
function onSearch() {
  clearTimeout(debounce)
  debounce = setTimeout(cargar, 350)
}

const fichasAgrupadas = computed(() => {
  const grupos = {}
  for (const f of fichas.value) {
    if (!grupos[f.categoria]) grupos[f.categoria] = []
    grupos[f.categoria].push(f)
  }
  return grupos
})

// ── Detalle ficha ─────────────────────────────────────────────────────────────
const fichaDetalle   = ref(null)
const loadingDetalle = ref(false)
const modoEdicion    = ref(false)
const guardando      = ref(false)
const hayCambios     = ref(false)

async function verDetalle(id) {
  loadingDetalle.value = true
  fichaDetalle.value   = null
  modoEdicion.value    = false
  hayCambios.value     = false
  try {
    const res = await getFicha(id)
    const data = res.data
    if (data.items) {
      data.items = data.items.map(i => ({
        ...i,
        cantidad:        parseFloat(i.cantidad),
        precio_unitario: parseFloat(i.precio_unitario),
        subtotal:        parseFloat(i.subtotal),
      }))
    }
    fichaDetalle.value = data
  } finally {
    loadingDetalle.value = false
  }
}

function cerrarDetalle() {
  if (hayCambios.value && !confirm('Hay cambios sin guardar. ¿Cerrar de todas formas?')) return
  fichaDetalle.value = null
  modoEdicion.value  = false
  hayCambios.value   = false
}

function onCampoChange(item) {
  const cant = parseFloat(item.cantidad)        || 0
  const pu   = parseFloat(item.precio_unitario) || 0
  item.subtotal    = parseFloat((cant * pu).toFixed(2))
  hayCambios.value = true
  recalcularTotalesDetalle()
}

function recalcularTotalesDetalle() {
  const items = fichaDetalle.value.items
  fichaDetalle.value.costo_materiales = items.filter(i => !i.es_mano_obra).reduce((s, i) => s + parseFloat(i.subtotal), 0)
  fichaDetalle.value.costo_mano_obra  = items.filter(i =>  i.es_mano_obra).reduce((s, i) => s + parseFloat(i.subtotal), 0)
  fichaDetalle.value.costo_total      = fichaDetalle.value.costo_materiales + fichaDetalle.value.costo_mano_obra
}

async function guardarCambios() {
  guardando.value = true
  try {
    await actualizarItems(
      fichaDetalle.value.id,
      fichaDetalle.value.items.map(i => ({
        id:              i.id,
        cantidad:        parseFloat(i.cantidad),
        precio_unitario: parseFloat(i.precio_unitario),
        subtotal:        parseFloat(i.subtotal),
      })),
      fichaDetalle.value.nombre,
    )
    hayCambios.value  = false
    modoEdicion.value = false
    const idx = fichas.value.findIndex(f => f.id === fichaDetalle.value.id)
    if (idx !== -1) {
      fichas.value[idx].nombre           = fichaDetalle.value.nombre
      fichas.value[idx].costo_materiales = fichaDetalle.value.costo_materiales
      fichas.value[idx].costo_mano_obra  = fichaDetalle.value.costo_mano_obra
      fichas.value[idx].costo_total      = fichaDetalle.value.costo_total
    }
  } finally {
    guardando.value = false
  }
}

const secciones = computed(() => {
  if (!fichaDetalle.value) return []
  const mapa = {}
  for (const item of fichaDetalle.value.items) {
    const key = item.seccion || 'General'
    if (!mapa[key]) mapa[key] = []
    mapa[key].push(item)
  }
  return Object.entries(mapa).map(([nombre, items]) => ({ nombre, items }))
})

const seccionesConCosto = computed(() => {
  return secciones.value.map(s => ({
    ...s,
    costo_materiales: s.items.filter(i => !i.es_mano_obra).reduce((a, i) => a + parseFloat(i.subtotal), 0),
    costo_mano_obra:  s.items.filter(i =>  i.es_mano_obra).reduce((a, i) => a + parseFloat(i.subtotal), 0),
    costo_total:      s.items.reduce((a, i) => a + parseFloat(i.subtotal), 0),
  }))
})

const esMultiVariante = computed(() => secciones.value.length > 1)

// ── Nuevo producto ────────────────────────────────────────────────────────────
const mostrarFormNuevo = ref(false)
const creando          = ref(false)
let _tempId = 0

const formNuevo = ref({ nombre: '', categoria: '', materiales: [], manoObra: [] })

function abrirFormNuevo() {
  formNuevo.value = { nombre: '', categoria: '', materiales: [nuevoItemMaterial()], manoObra: [] }
  mostrarFormNuevo.value = true
}

function nuevoItemMaterial() { return { _id: _tempId++, descripcion: '', cantidad: 1, unidad: '', precio_unitario: 0, subtotal: 0 } }
function nuevoItemManoObra()  { return { _id: _tempId++, descripcion: '', cantidad: 1, precio_unitario: 0, subtotal: 0 } }

function recalcularItemForm(item) {
  item.subtotal = parseFloat(((parseFloat(item.cantidad) || 0) * (parseFloat(item.precio_unitario) || 0)).toFixed(2))
}

function eliminarItem(lista, id) {
  const idx = lista.findIndex(i => i._id === id)
  if (idx !== -1) lista.splice(idx, 1)
}

const totalMatForm = computed(() => formNuevo.value.materiales.reduce((s, i) => s + parseFloat(i.subtotal || 0), 0))
const totalMOForm  = computed(() => formNuevo.value.manoObra.reduce((s, i)  => s + parseFloat(i.subtotal || 0), 0))
const totalForm    = computed(() => totalMatForm.value + totalMOForm.value)

async function guardarNuevo() {
  if (!formNuevo.value.nombre.trim())    { alert('Ingresa el nombre del producto'); return }
  if (!formNuevo.value.categoria.trim()) { alert('Ingresa la categoría'); return }

  const items = [
    ...formNuevo.value.materiales.filter(i => i.descripcion.trim()).map((i, idx) => ({
      seccion: 'Materiales', descripcion: i.descripcion, cantidad: parseFloat(i.cantidad) || 0,
      unidad: i.unidad, precio_unitario: parseFloat(i.precio_unitario) || 0,
      subtotal: parseFloat(i.subtotal) || 0, es_mano_obra: false, orden: idx,
    })),
    ...formNuevo.value.manoObra.filter(i => i.descripcion.trim()).map((i, idx) => ({
      seccion: 'Mano de obra', descripcion: i.descripcion, cantidad: parseFloat(i.cantidad) || 0,
      unidad: null, precio_unitario: parseFloat(i.precio_unitario) || 0,
      subtotal: parseFloat(i.subtotal) || 0, es_mano_obra: true, orden: idx,
    })),
  ]

  if (items.length === 0) { alert('Agrega al menos un material o mano de obra'); return }

  creando.value = true
  try {
    const res = await crearFicha({ nombre: formNuevo.value.nombre, categoria: formNuevo.value.categoria, items })
    mostrarFormNuevo.value = false
    await cargar()
    verDetalle(res.data.id)
  } finally {
    creando.value = false
  }
}

// ── Agregar proceso desde tarifa ──────────────────────────────────────────────
function agregarDesdeTarifa(p) {
  const item           = nuevoItemManoObra()
  item.descripcion     = p.descripcion || p.proceso.replace(/_/g, ' ')
  item.unidad          = 'horas'
  item.precio_unitario = Math.round(tarifaDiariaFor(p.cargo))  // incentivo por hora
  item.cantidad        = parseFloat(p._horas) || 0                  // horas típicas del proceso
  recalcularItemForm(item)
  formNuevo.value.manoObra.push(item)
}

// ── Autocomplete materiales ───────────────────────────────────────────────────
const sugerencias = ref([])
const idBuscando  = ref(null)
let debounceAuto  = null

async function onMaterialInput(item) {
  recalcularItemForm(item)
  clearTimeout(debounceAuto)
  if (item.descripcion.length < 2) { sugerencias.value = []; idBuscando.value = null; return }
  idBuscando.value = item._id
  debounceAuto = setTimeout(async () => {
    const res = await getMaterialesSugeridos(item.descripcion)
    if (idBuscando.value === item._id) sugerencias.value = res.data
  }, 300)
}

function seleccionarSugerencia(item, sug) {
  item.descripcion     = sug.descripcion
  item.unidad          = sug.unidad
  item.precio_unitario = sug.precio_promedio
  recalcularItemForm(item)
  sugerencias.value = []
  idBuscando.value  = null
}

function cerrarSugerencias() {
  setTimeout(() => { sugerencias.value = []; idBuscando.value = null }, 150)
}

// ── Reimportar ────────────────────────────────────────────────────────────────
const reimportando = ref(false)

async function reimportar() {
  if (!confirm('¿Reimportar todas las fichas técnicas desde los archivos Excel? Esto borrará y recargará todos los datos.')) return
  reimportando.value = true
  try {
    await reimportarFichas()
    await cargar()
  } finally {
    reimportando.value = false
  }
}

// ════════════════════════════════════════════════════════════════════════════════
// TAB: MATERIALES
// ════════════════════════════════════════════════════════════════════════════════

const materiales       = ref([])
const loadingMat       = ref(false)
const searchMat        = ref('')
const materialEditando = ref(null)   // { ...material, _precio: '' } — modal edición
const mostrarFormMat   = ref(false)  // modal nuevo material
const guardandoMat     = ref(false)
const afectados        = ref(null)   // mensaje post-update

const formMat = ref({ nombre: '', descripcion: '', unidad: '', precio_unitario: '' })

async function cargarMateriales() {
  loadingMat.value = true
  try {
    const res = await getMateriales(searchMat.value)
    materiales.value = res.data
  } finally {
    loadingMat.value = false
  }
}

let debounceMat = null
function onSearchMat() {
  clearTimeout(debounceMat)
  debounceMat = setTimeout(cargarMateriales, 350)
}

function abrirEdicion(mat) {
  materialEditando.value = {
    ...mat,
    _precio:      String(mat.precio_unitario),
    _descripcion: mat.descripcion || '',
    _unidad:      mat.unidad || '',
  }
  afectados.value = null
}

async function guardarMaterial() {
  const mat = materialEditando.value
  guardandoMat.value = true
  try {
    const res = await actualizarMaterial(mat.id, {
      nombre:           mat.nombre,
      descripcion:      mat._descripcion,
      unidad:           mat._unidad,
      precio_unitario:  parseFloat(mat._precio) || 0,
    })
    afectados.value = res.data.productos_afectados
    // Actualizar en la lista local
    const idx = materiales.value.findIndex(m => m.id === mat.id)
    if (idx !== -1) {
      materiales.value[idx] = { ...materiales.value[idx], ...res.data }
    }
    materialEditando.value = null
    // Si hubo cambios en precios, recargar la lista de productos también
    if (res.data.productos_afectados > 0) cargar()
  } finally {
    guardandoMat.value = false
  }
}

async function guardarNuevoMaterial() {
  if (!formMat.value.nombre.trim()) { alert('Ingresa el nombre del material'); return }
  guardandoMat.value = true
  try {
    await crearMaterial({
      nombre:          formMat.value.nombre.trim().toUpperCase(),
      descripcion:     formMat.value.descripcion,
      unidad:          formMat.value.unidad.trim().toUpperCase(),
      precio_unitario: parseFloat(formMat.value.precio_unitario) || 0,
    })
    mostrarFormMat.value = false
    formMat.value = { nombre: '', descripcion: '', unidad: '', precio_unitario: '' }
    cargarMateriales()
  } finally {
    guardandoMat.value = false
  }
}

async function sincronizarMateriales() {
  loadingMat.value = true
  try {
    await importarMateriales()
    cargarMateriales()
  } finally {
    loadingMat.value = false
  }
}

function exportarMaterialesExcel() {
  const cop = v => '$' + Math.round(parseFloat(v) || 0).toLocaleString('es-CO')

  const filas = materiales.value.map(m => ({
    'Nombre':          m.nombre,
    'Unidad':          m.unidad ?? '',
    'Precio unitario': cop(m.precio_unitario),
    'Descripción':     m.descripcion ?? '',
  }))

  const hoja  = XLSX.utils.json_to_sheet(filas)
  const libro = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(libro, hoja, 'Materiales')

  hoja['!cols'] = [{ wch: 40 }, { wch: 12 }, { wch: 20 }, { wch: 40 }]

  XLSX.writeFile(libro, 'materiales_decasa.xlsx')
}

// ════════════════════════════════════════════════════════════════════════════════
// TAB: TARIFAS
// ════════════════════════════════════════════════════════════════════════════════

const salarios       = ref([])
const procesos       = ref([])
const loadingTarifas = ref(false)
const guardandoTar   = ref(false)
const tarifasDirty   = ref(false)

// ── Nuevo cargo ───────────────────────────────────────────────────────────────
const CARGOS_BASE    = ['carpintero', 'tapicero', 'costurera', 'lacador']
const mostrarFormCargo = ref(false)
const guardandoCargo   = ref(false)
const errCargo         = ref('')
const formCargo = ref({ cargo: '', descripcion: '', salario_mensual: '', dias: '26', tarifa_hora: '' })

async function guardarNuevoCargo() {
  errCargo.value = ''
  if (!formCargo.value.cargo.trim() || !formCargo.value.salario_mensual) {
    errCargo.value = 'Nombre y salario son obligatorios.'; return
  }
  guardandoCargo.value = true
  try {
    await crearCargo({
      cargo:              formCargo.value.cargo.trim(),
      descripcion:        formCargo.value.descripcion.trim(),
      salario_mensual:    parseFloat(formCargo.value.salario_mensual) || 0,
      dias_laborales_mes: parseInt(formCargo.value.dias) || 26,
      tarifa_hora:        parseFloat(formCargo.value.tarifa_hora) || 0,
    })
    mostrarFormCargo.value = false
    formCargo.value = { cargo: '', descripcion: '', salario_mensual: '', dias: '26', tarifa_hora: '' }
    await cargarTarifas()
  } catch (e) {
    errCargo.value = e.response?.data?.message ?? 'Error al crear el cargo.'
  } finally {
    guardandoCargo.value = false
  }
}

async function borrarCargo(cargo) {
  if (!confirm(`¿Eliminar el cargo "${cargo}"? Los trabajos vinculados deben borrarse primero.`)) return
  try {
    await eliminarCargo(cargo)
    await cargarTarifas()
  } catch (e) {
    alert(e.response?.data?.message ?? 'No se pudo eliminar.')
  }
}

// ── Nuevo proceso por cargo ────────────────────────────────────────────────────
const formProceso     = ref({})   // { [cargo]: { nombre, descripcion, unidad, horas, visible, guardando, err } }
const UNIDADES        = ['pieza', 'puesto', 'm2', 'ml', 'hora']

function abrirFormProceso(cargo) {
  formProceso.value = {
    ...formProceso.value,
    [cargo]: { nombre: '', descripcion: '', unidad: 'pieza', horas: '', visible: true, guardando: false, err: '' }
  }
}

async function guardarNuevoProceso(cargo) {
  const f = formProceso.value[cargo]
  f.err = ''
  if (!f.nombre.trim() || !f.horas) { f.err = 'Nombre y horas son obligatorios.'; return }
  f.guardando = true
  try {
    await crearProceso({
      nombre:      f.nombre.trim(),
      descripcion: f.descripcion.trim() || undefined,
      unidad:      f.unidad,
      cargo,
      horas:       parseFloat(f.horas) || 0,
    })
    formProceso.value[cargo].visible = false
    await cargarTarifas()
  } catch (e) {
    f.err = e.response?.data?.message ?? 'Error al crear el trabajo.'
  } finally {
    f.guardando = false
  }
}

async function borrarProceso(id, nombre) {
  if (!confirm(`¿Eliminar el trabajo "${nombre}"?`)) return
  try {
    await eliminarProceso(id)
    await cargarTarifas()
  } catch (e) {
    alert(e.response?.data?.message ?? 'No se pudo eliminar.')
  }
}

async function cargarTarifas() {
  loadingTarifas.value = true
  try {
    const res = await getCostos()
    salarios.value = res.data.salarios.map(s => ({
      ...s,
      _salario:    String(s.salario_mensual),
      _dias:       String(s.dias_laborales_mes || 26),
      _tarifaHora: String(s.tarifa_hora || 0),
    }))
    procesos.value = res.data.procesos.map(p => ({ ...p, _horas: String(Math.round((p.dias_por_unidad ?? 0) * 8 * 100) / 100) }))
  } finally {
    loadingTarifas.value = false
  }
}

// Sueldo diario = salario fijo / días (informativo, no cambia con el incentivo)
function sueldoDiarioFor(cargo) {
  const s = salarios.value.find(s => s.cargo === cargo)
  if (!s) return 0
  return Math.round((parseFloat(s._salario) || 0) / (parseInt(s._dias) || 1))
}

// Sueldo por hora = salario fijo / días / 8 (informativo, no editable)
function sueldoHoraFor(cargo) {
  return Math.round(sueldoDiarioFor(cargo) / 8)
}

// calcTarifa usa tarifa_hora del incentivo (campo independiente)
function calcTarifa(p) {
  const s = salarios.value.find(s => s.cargo === p.cargo)
  const tarifaHora = s ? (parseFloat(s._tarifaHora) || 0) : 0
  return Math.round(tarifaHora * (parseFloat(p._horas) || 0))
}

// tarifaDiariaFor se mantiene para compatibilidad con agregarDesdeTarifa
function tarifaDiariaFor(cargo) {
  const s = salarios.value.find(s => s.cargo === cargo)
  return s ? (parseFloat(s._tarifaHora) || 0) : 0  // devuelve tarifa/hora del incentivo
}

const procesosAgrupados = computed(() => {
  const grupos = {}
  for (const p of procesos.value) {
    const key = p.cargo || 'Sin cargo'
    if (!grupos[key]) grupos[key] = []
    grupos[key].push(p)
  }
  return Object.entries(grupos)
})

async function guardarTarifas() {
  guardandoTar.value = true
  try {
    await guardarCostos({
      salarios: salarios.value.map(s => ({
        cargo:              s.cargo,
        salario_mensual:    parseFloat(s._salario)    || 0,
        dias_laborales_mes: parseInt(s._dias)          || 26,
        tarifa_hora:        parseFloat(s._tarifaHora)  || 0,
      })),
      procesos: procesos.value.map(p => ({
        id:              p.id,
        dias_por_unidad: (parseFloat(p._horas) || 0) / 8,
      })),
    })
    tarifasDirty.value = false
    await cargarTarifas()
  } finally {
    guardandoTar.value = false
  }
}

// ── Formateo ──────────────────────────────────────────────────────────────────
function formatPeso(valor) {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(valor)
}

function formatCantidad(valor) {
  const n = parseFloat(valor)
  return Number.isInteger(n) ? n.toString() : parseFloat(n.toFixed(4)).toString()
}

function seccionSubtotal(items) {
  return items.reduce((s, i) => s + parseFloat(i.subtotal || 0), 0)
}

onMounted(() => {
  cargar()
  cargarMateriales()
  cargarTarifas()
})
</script>

<template>
  <div class="min-h-screen bg-gray-50 pb-24">

    <!-- ── Header con tabs ───────────────────────────────────────────────────── -->
    <div class="sticky top-0 z-10 bg-white border-b border-gray-200">
      <div class="flex items-center justify-between px-4 pt-3 pb-2">
        <h1 class="text-lg font-bold text-gray-800">Costos de producción</h1>
        <div class="flex items-center gap-2">
          <button v-if="tab === 'productos' && auth.isSupervisor" @click="reimportar" :disabled="reimportando" class="p-1.5 text-gray-400 hover:text-gray-600 disabled:opacity-40">
            <ArrowPathIcon class="w-4 h-4" :class="{ 'animate-spin': reimportando }" />
          </button>
          <button v-if="tab === 'productos'" @click="abrirFormNuevo"
            class="flex items-center gap-1 text-xs bg-blue-600 text-white rounded-lg px-3 py-1.5 font-medium hover:bg-blue-700">
            <PlusIcon class="w-3.5 h-3.5" />Nuevo
          </button>
          <button v-if="tab === 'materiales'" @click="exportarMaterialesExcel" :disabled="!materiales.length"
            class="flex items-center gap-1 text-xs bg-green-600 text-white rounded-lg px-3 py-1.5 font-medium hover:bg-green-700 disabled:opacity-40">
            <ArrowDownTrayIcon class="w-3.5 h-3.5" />Excel
          </button>
          <button v-if="tab === 'materiales'" @click="mostrarFormMat = true"
            class="flex items-center gap-1 text-xs bg-blue-600 text-white rounded-lg px-3 py-1.5 font-medium hover:bg-blue-700">
            <PlusIcon class="w-3.5 h-3.5" />Nuevo
          </button>
          <button v-if="tab === 'tarifas'" @click="guardarTarifas" :disabled="guardandoTar || !tarifasDirty"
            class="flex items-center gap-1 text-xs bg-blue-600 text-white rounded-lg px-3 py-1.5 font-medium hover:bg-blue-700 disabled:opacity-40">
            <ArrowPathIcon v-if="guardandoTar" class="w-3.5 h-3.5 animate-spin" />
            <CheckIcon v-else class="w-3.5 h-3.5" />
            {{ guardandoTar ? 'Guardando...' : 'Guardar' }}
          </button>
        </div>
      </div>

      <!-- Tabs -->
      <div class="flex border-t border-gray-100">
        <button
          @click="tab = 'productos'"
          :class="['flex-1 py-2 text-sm font-medium transition-colors border-b-2', tab === 'productos' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500']"
        >Productos</button>
        <button
          @click="tab = 'materiales'"
          :class="['flex-1 py-2 text-sm font-medium transition-colors border-b-2', tab === 'materiales' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500']"
        >Materiales</button>
        <button
          @click="tab = 'tarifas'"
          :class="['flex-1 py-2 text-sm font-medium transition-colors border-b-2', tab === 'tarifas' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500']"
        >Tarifas</button>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <!-- TAB PRODUCTOS                                                            -->
    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'productos'">
      <div class="px-4 pt-3 pb-2 bg-white border-b border-gray-100">
        <div class="relative mb-2">
          <MagnifyingGlassIcon class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" />
          <input v-model="search" @input="onSearch" type="text" placeholder="Buscar producto..."
            class="w-full pl-9 pr-8 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" />
          <button v-if="search" @click="limpiarBusqueda" class="absolute right-2.5 top-2.5"><XMarkIcon class="w-4 h-4 text-gray-400" /></button>
        </div>
        <div class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
          <button @click="catActiva = ''; cargar()"
            :class="['flex-shrink-0 px-3 py-1 rounded-full text-xs font-medium', catActiva === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600']">Todas</button>
          <button v-for="cat in categorias" :key="cat" @click="catActiva = cat; cargar()"
            :class="['flex-shrink-0 px-3 py-1 rounded-full text-xs font-medium', catActiva === cat ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600']">{{ cat }}</button>
        </div>
      </div>

      <div class="px-4 pt-4">
        <div v-if="loading" class="flex justify-center py-16"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>
        <div v-else-if="fichas.length === 0" class="text-center py-16 text-gray-400">
          <CubeIcon class="w-12 h-12 mx-auto mb-2 opacity-40" /><p class="text-sm">No se encontraron productos</p>
        </div>
        <template v-else>
          <div v-for="(grupo, categoria) in fichasAgrupadas" :key="categoria" class="mb-6">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">{{ categoria }}</h2>
            <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
              <button v-for="ficha in grupo" :key="ficha.id" @click="verDetalle(ficha.id)"
                class="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-left">
                <div class="flex-1 min-w-0 mr-3">
                  <p class="text-sm font-medium text-gray-800 truncate">{{ ficha.nombre }}</p>
                  <div class="flex gap-3 mt-0.5">
                    <span class="text-xs text-gray-400">Mat: <span class="text-gray-600">{{ formatPeso(ficha.costo_materiales) }}</span></span>
                    <span class="text-xs text-gray-400">M.O: <span class="text-gray-600">{{ formatPeso(ficha.costo_mano_obra) }}</span></span>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <span class="text-sm font-bold text-blue-700">{{ formatPeso(ficha.costo_total) }}</span>
                  <ChevronRightIcon class="w-4 h-4 text-gray-300" />
                </div>
              </button>
            </div>
          </div>
        </template>
      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <!-- TAB MATERIALES                                                           -->
    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'materiales'">
      <div class="px-4 pt-3 pb-2 bg-white border-b border-gray-100">
        <div class="relative">
          <MagnifyingGlassIcon class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" />
          <input v-model="searchMat" @input="onSearchMat" type="text" placeholder="Buscar material..."
            class="w-full pl-9 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <p class="text-xs text-gray-400 mt-1.5">{{ materiales.length }} materiales · toca uno para editar su precio</p>
      </div>

      <!-- Mensaje de éxito post-update -->
      <div v-if="afectados !== null" class="mx-4 mt-3 px-3 py-2 bg-green-50 border border-green-200 rounded-lg text-xs text-green-700">
        ✓ Precio actualizado — {{ afectados }} productos recalculados
        <button @click="afectados = null" class="ml-2 text-green-500 font-bold">×</button>
      </div>

      <div v-if="loadingMat" class="flex justify-center py-16"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>

      <div v-else class="px-4 pt-3">
        <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
          <button
            v-for="mat in materiales"
            :key="mat.id"
            @click="abrirEdicion(mat)"
            class="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 text-left"
          >
            <div class="flex-1 min-w-0 mr-3">
              <p class="text-sm font-medium text-gray-800 truncate">{{ mat.nombre }}</p>
              <div class="flex items-center gap-2 mt-0.5">
                <span v-if="mat.unidad" class="text-xs text-gray-400">{{ mat.unidad }}</span>
                <span v-if="mat.descripcion" class="text-xs text-gray-400 truncate">· {{ mat.descripcion }}</span>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <span class="text-sm font-bold text-blue-700">{{ formatPeso(mat.precio_unitario) }}</span>
              <PencilSquareIcon class="w-4 h-4 text-gray-300" />
            </div>
          </button>
        </div>
      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <!-- TAB TARIFAS                                                               -->
    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'tarifas'">
      <div v-if="loadingTarifas" class="flex justify-center py-16"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>
      <div v-else class="px-4 pt-4 space-y-6 pb-8">

        <!-- Salarios por cargo -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Salarios por cargo</h2>
            <button @click="mostrarFormCargo = !mostrarFormCargo"
              class="flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 transition-colors">
              <PlusIcon class="w-3.5 h-3.5" />
              Nuevo cargo
            </button>
          </div>

          <!-- Formulario nuevo cargo -->
          <div v-if="mostrarFormCargo" class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-3 space-y-3">
            <p class="text-xs font-semibold text-blue-800">Nuevo tipo de operario</p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-[10px] text-gray-500 uppercase font-medium">Nombre del cargo *</label>
                <input v-model="formCargo.cargo" type="text" placeholder="ej: pintor"
                  class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="text-[10px] text-gray-500 uppercase font-medium">Descripción</label>
                <input v-model="formCargo.descripcion" type="text" placeholder="ej: Operario de pintura"
                  class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="text-[10px] text-gray-500 uppercase font-medium">Salario mensual *</label>
                <input v-model="formCargo.salario_mensual" type="number" step="50000" min="0" placeholder="ej: 2500000"
                  class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="text-[10px] text-blue-500 uppercase font-medium">Incentivo / hora</label>
                <input v-model="formCargo.tarifa_hora" type="number" step="500" min="0" placeholder="ej: 8000"
                  class="w-full mt-0.5 text-sm border border-blue-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-blue-50" />
              </div>
            </div>
            <p v-if="errCargo" class="text-xs text-red-600">{{ errCargo }}</p>
            <div class="flex gap-2">
              <button @click="mostrarFormCargo = false; errCargo = ''"
                class="flex-1 text-xs py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50">Cancelar</button>
              <button @click="guardarNuevoCargo" :disabled="guardandoCargo"
                class="flex-1 text-xs py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 disabled:opacity-50">
                {{ guardandoCargo ? 'Guardando...' : 'Crear cargo' }}
              </button>
            </div>
          </div>

          <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
            <div v-for="s in salarios" :key="s.cargo" class="px-4 py-3">
              <div class="flex items-center justify-between mb-2">
                <div>
                  <p class="text-sm font-semibold text-gray-800 capitalize">{{ s.cargo.replace(/_/g, ' ') }}</p>
                  <p v-if="s.descripcion" class="text-xs text-gray-400">{{ s.descripcion }}</p>
                </div>
                <div class="flex items-center gap-2">
                  <div class="text-right">
                    <p class="text-xs text-gray-400">Sueldo / día</p>
                    <p class="text-sm font-bold text-blue-700">{{ formatPeso(sueldoDiarioFor(s.cargo)) }}</p>
                  </div>
                  <button v-if="!CARGOS_BASE.includes(s.cargo)" @click="borrarCargo(s.cargo)"
                    class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar cargo">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                  <label class="text-[10px] text-gray-400 uppercase font-medium">Salario mensual</label>
                  <input v-model="s._salario" @input="tarifasDirty = true" type="number" step="1000" min="0"
                    class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label class="text-[10px] text-gray-400 uppercase font-medium">Días / mes</label>
                  <input v-model="s._dias" @input="tarifasDirty = true" type="number" step="1" min="1" max="31"
                    class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-3 pt-2 border-t border-gray-100">
                <div>
                  <label class="text-[10px] text-gray-400 uppercase font-medium">Sueldo / hora</label>
                  <div class="mt-0.5 text-sm font-medium text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
                    {{ formatPeso(sueldoHoraFor(s.cargo)) }}
                  </div>
                </div>
                <div>
                  <label class="text-[10px] text-blue-500 uppercase font-medium">Incentivo / hora</label>
                  <input v-model="s._tarifaHora" @input="tarifasDirty = true" type="number" step="1000" min="0"
                    class="w-full mt-0.5 text-sm border border-blue-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-blue-50" />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Tiempos de proceso por cargo -->
        <div v-for="[cargo, items] in procesosAgrupados" :key="cargo">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider capitalize">{{ cargo.replace(/_/g, ' ') }}</h2>
            <div class="flex items-center gap-3">
              <span class="text-xs text-gray-400">Incentivo {{ formatPeso(tarifaDiariaFor(cargo)) }}/h</span>
              <button @click="abrirFormProceso(cargo)"
                class="flex items-center gap-1 text-xs font-semibold text-green-600 hover:text-green-800 transition-colors">
                <PlusIcon class="w-3.5 h-3.5" />
                Nuevo trabajo
              </button>
            </div>
          </div>

          <!-- Formulario nuevo proceso -->
          <div v-if="formProceso[cargo]?.visible" class="bg-green-50 border border-green-200 rounded-xl p-4 mb-2 space-y-3">
            <p class="text-xs font-semibold text-green-800">Nuevo trabajo para <span class="capitalize">{{ cargo.replace(/_/g, ' ') }}</span></p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-[10px] text-gray-500 uppercase font-medium">Nombre del trabajo *</label>
                <input v-model="formProceso[cargo].nombre" type="text" placeholder="ej: lijada"
                  class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500" />
              </div>
              <div>
                <label class="text-[10px] text-gray-500 uppercase font-medium">Unidad</label>
                <select v-model="formProceso[cargo].unidad"
                  class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                  <option v-for="u in UNIDADES" :key="u" :value="u">{{ u }}</option>
                </select>
              </div>
              <div class="col-span-2">
                <label class="text-[10px] text-gray-500 uppercase font-medium">Descripción <span class="font-normal">(opcional)</span></label>
                <input v-model="formProceso[cargo].descripcion" type="text" placeholder="ej: Lijado previo antes de lacado"
                  class="w-full mt-0.5 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500" />
              </div>
              <div>
                <label class="text-[10px] text-gray-500 uppercase font-medium">Horas por {{ formProceso[cargo].unidad }} *</label>
                <div class="flex items-center gap-1 mt-0.5">
                  <input v-model="formProceso[cargo].horas" type="number" step="0.5" min="0" placeholder="ej: 2"
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500" />
                  <span class="text-xs text-gray-400 whitespace-nowrap">h</span>
                </div>
              </div>
              <div class="flex items-end">
                <div class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
                  <p class="text-[10px] text-gray-400 uppercase font-medium">Tarifa estimada</p>
                  <p class="text-sm font-bold text-orange-600">
                    {{ formatPeso(Math.round((parseFloat(tarifaDiariaFor(cargo)) || 0) * (parseFloat(formProceso[cargo].horas) || 0))) }}
                  </p>
                </div>
              </div>
            </div>
            <p v-if="formProceso[cargo].err" class="text-xs text-red-600">{{ formProceso[cargo].err }}</p>
            <div class="flex gap-2">
              <button @click="formProceso[cargo].visible = false"
                class="flex-1 text-xs py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50">Cancelar</button>
              <button @click="guardarNuevoProceso(cargo)" :disabled="formProceso[cargo].guardando"
                class="flex-1 text-xs py-2 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 disabled:opacity-50">
                {{ formProceso[cargo].guardando ? 'Guardando...' : 'Crear trabajo' }}
              </button>
            </div>
          </div>

          <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
            <div v-for="p in items" :key="p.id" class="px-4 py-3">
              <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800">{{ p.descripcion || p.proceso.replace(/_/g, ' ') }}</p>
                  <p v-if="p.descripcion && p.descripcion !== p.proceso" class="text-xs text-gray-400 mt-0.5">{{ p.proceso.replace(/_/g, ' ') }}</p>
                </div>
                <div class="flex items-start gap-2">
                  <div class="text-right flex-shrink-0">
                    <p class="text-xs text-gray-400">Tarifa</p>
                    <p class="text-sm font-bold text-orange-600">{{ formatPeso(calcTarifa(p)) }}</p>
                    <p v-if="p.unidad" class="text-[10px] text-gray-400">por {{ p.unidad }}</p>
                  </div>
                  <button v-if="p.aplica_a === 'personalizado'" @click="borrarProceso(p.id, p.descripcion || p.proceso)"
                    class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors mt-0.5" title="Eliminar">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </div>
              <div class="mt-2 flex items-center gap-2">
                <label class="text-[10px] text-gray-400 uppercase font-medium whitespace-nowrap">Horas por {{ p.unidad || 'unidad' }}</label>
                <input v-model="p._horas" @input="tarifasDirty = true" type="number" step="0.5" min="0"
                  class="w-24 text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <span class="text-xs text-gray-400">h</span>
              </div>
            </div>
          </div>
        </div>

      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <!-- MODALES                                                                   -->
    <!-- ════════════════════════════════════════════════════════════════════════ -->
    <Teleport to="body">

      <!-- ── Modal detalle producto ──────────────────────────────────────────── -->
      <div v-if="fichaDetalle || loadingDetalle" class="fixed inset-0 z-50 flex flex-col bg-white">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-4 py-3 flex items-center gap-3">
          <button @click="cerrarDetalle" class="p-1 -ml-1"><XMarkIcon class="w-5 h-5 text-gray-600" /></button>
          <div class="flex-1 min-w-0">
            <input v-if="modoEdicion && fichaDetalle"
              v-model="fichaDetalle.nombre"
              @input="hayCambios = true"
              type="text"
              class="w-full text-sm font-semibold text-gray-800 border border-blue-300 rounded-lg px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-blue-50" />
            <p v-else class="text-sm font-semibold text-gray-800 truncate">{{ fichaDetalle?.nombre }}</p>
            <p class="text-xs text-gray-400">{{ fichaDetalle?.categoria }}</p>
          </div>
          <template v-if="fichaDetalle">
            <button v-if="!modoEdicion" @click="modoEdicion = true" class="flex items-center gap-1 text-xs text-blue-600 font-medium px-2 py-1">
              <PencilSquareIcon class="w-4 h-4" />Editar
            </button>
            <template v-else>
              <button @click="modoEdicion = false; hayCambios = false; verDetalle(fichaDetalle.id)" class="text-xs text-gray-500 px-2 py-1">Cancelar</button>
              <button @click="guardarCambios" :disabled="guardando || !hayCambios"
                class="flex items-center gap-1 text-xs bg-blue-600 text-white rounded-lg px-3 py-1.5 font-medium disabled:opacity-50">
                <CheckIcon v-if="!guardando" class="w-3.5 h-3.5" />
                <ArrowPathIcon v-else class="w-3.5 h-3.5 animate-spin" />
                {{ guardando ? 'Guardando...' : 'Guardar' }}
              </button>
            </template>
          </template>
        </div>

        <div v-if="loadingDetalle" class="flex justify-center py-16"><div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" /></div>
        <div v-else-if="fichaDetalle" class="flex-1 overflow-y-auto pb-8">
          <!-- Ficha con una sola sección: mostrar totales combinados -->
          <div v-if="!esMultiVariante" class="grid grid-cols-3 border-b border-gray-100">
            <div class="text-center py-4 border-r border-gray-100">
              <p class="text-xs text-gray-400 mb-1">Materiales</p>
              <p class="text-sm font-bold text-gray-700">{{ formatPeso(fichaDetalle.costo_materiales) }}</p>
            </div>
            <div class="text-center py-4 border-r border-gray-100">
              <p class="text-xs text-gray-400 mb-1">Mano de obra</p>
              <p class="text-sm font-bold text-gray-700">{{ formatPeso(fichaDetalle.costo_mano_obra) }}</p>
            </div>
            <div class="text-center py-4 bg-blue-50">
              <p class="text-xs text-blue-500 mb-1">Total</p>
              <p class="text-sm font-bold text-blue-700">{{ formatPeso(fichaDetalle.costo_total) }}</p>
            </div>
          </div>
          <!-- Ficha multi-variante: mostrar costo por cada sección -->
          <div v-else class="border-b border-gray-100 px-4 py-3 space-y-2">
            <p class="text-[10px] text-amber-600 font-semibold uppercase tracking-wider">Contiene {{ seccionesConCosto.length }} variantes — costo individual por variante:</p>
            <div v-for="s in seccionesConCosto" :key="s.nombre" class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
              <p class="text-xs font-medium text-gray-700 truncate flex-1 mr-2">{{ s.nombre }}</p>
              <div class="flex gap-3 text-right flex-shrink-0">
                <span class="text-[10px] text-gray-400">Mat: <span class="text-gray-600 font-medium">{{ formatPeso(s.costo_materiales) }}</span></span>
                <span class="text-[10px] text-gray-400">M.O: <span class="text-gray-600 font-medium">{{ formatPeso(s.costo_mano_obra) }}</span></span>
                <span class="text-xs font-bold text-blue-700">{{ formatPeso(s.costo_total) }}</span>
              </div>
            </div>
          </div>
          <div v-if="modoEdicion" class="mx-4 mt-3 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
            Cambia cantidad o valor unitario — el subtotal y el total se recalculan automáticamente.
          </div>
          <div class="px-4 pt-4 space-y-5">
            <div v-for="seccion in secciones" :key="seccion.nombre">
              <div class="flex items-center justify-between mb-2">
                <h3 class="text-xs font-semibold text-blue-700 uppercase tracking-wider flex items-center gap-1">
                  <WrenchScrewdriverIcon class="w-3.5 h-3.5" />{{ seccion.nombre }}
                </h3>
                <span class="text-xs font-bold text-gray-700">{{ formatPeso(seccionSubtotal(seccion.items)) }}</span>
              </div>
              <div class="bg-gray-50 rounded-xl overflow-x-auto">
                <table class="w-full text-xs min-w-[520px]">
                  <thead>
                    <tr class="text-[10px] font-semibold text-gray-400 uppercase border-b border-gray-200">
                      <th class="px-3 py-2 text-left">Material</th>
                      <th class="px-2 py-2 text-right">Cant.</th>
                      <th class="px-2 py-2 text-left">Descripción</th>
                      <th class="px-2 py-2 text-right">Vr. Unit.</th>
                      <th class="px-3 py-2 text-right">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in seccion.items" :key="item.id"
                      :class="['border-b border-gray-100 last:border-0', item.es_mano_obra ? 'bg-orange-50' : '']">
                      <td class="px-3 py-2 text-gray-700">{{ item.descripcion }}</td>
                      <td class="px-2 py-1.5 text-right">
                        <input v-if="modoEdicion" v-model="item.cantidad" @input="onCampoChange(item)" type="number" step="any" min="0"
                          class="w-16 text-right text-xs border border-blue-300 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-blue-500 bg-white" />
                        <span v-else class="text-gray-500">{{ formatCantidad(item.cantidad) }}</span>
                      </td>
                      <td class="px-2 py-2 text-gray-400">{{ item.unidad }}</td>
                      <td class="px-2 py-1.5 text-right">
                        <input v-if="modoEdicion" v-model="item.precio_unitario" @input="onCampoChange(item)" type="number" step="any" min="0"
                          class="w-24 text-right text-xs border border-blue-300 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-blue-500 bg-white" />
                        <span v-else class="text-gray-500">{{ formatPeso(item.precio_unitario) }}</span>
                      </td>
                      <td class="px-3 py-2 text-right font-medium" :class="item.es_mano_obra ? 'text-orange-600' : 'text-gray-700'">
                        {{ formatPeso(item.subtotal) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Modal nuevo producto ────────────────────────────────────────────── -->
      <div v-if="mostrarFormNuevo" class="fixed inset-0 z-50 flex flex-col bg-white">
        <div class="sticky top-0 bg-white border-b border-gray-200 px-4 py-3 flex items-center gap-3">
          <button @click="mostrarFormNuevo = false" class="p-1 -ml-1"><XMarkIcon class="w-5 h-5 text-gray-600" /></button>
          <p class="flex-1 text-sm font-semibold text-gray-800">Nuevo producto</p>
          <button @click="guardarNuevo" :disabled="creando"
            class="flex items-center gap-1 text-xs bg-blue-600 text-white rounded-lg px-3 py-1.5 font-medium disabled:opacity-50">
            <CheckIcon v-if="!creando" class="w-3.5 h-3.5" />
            <ArrowPathIcon v-else class="w-3.5 h-3.5 animate-spin" />
            {{ creando ? 'Creando...' : 'Crear' }}
          </button>
        </div>
        <div class="flex-1 overflow-y-auto pb-8">
          <div class="px-4 pt-4 space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Nombre del producto</label>
              <input v-model="formNuevo.nombre" type="text" placeholder="Ej: SOFA MODERNO 3 PUESTOS"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
              <input v-model="formNuevo.categoria" type="text" list="lista-categorias" placeholder="Ej: SOFAS"
                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              <datalist id="lista-categorias"><option v-for="cat in categorias" :key="cat" :value="cat" /></datalist>
            </div>
          </div>

          <div class="grid grid-cols-3 mx-4 mt-4 rounded-xl overflow-hidden border border-gray-200">
            <div class="text-center py-3 border-r border-gray-200 bg-gray-50">
              <p class="text-[10px] text-gray-400 mb-0.5">Materiales</p>
              <p class="text-sm font-bold text-gray-700">{{ formatPeso(totalMatForm) }}</p>
            </div>
            <div class="text-center py-3 border-r border-gray-200 bg-gray-50">
              <p class="text-[10px] text-gray-400 mb-0.5">Mano de obra</p>
              <p class="text-sm font-bold text-orange-600">{{ formatPeso(totalMOForm) }}</p>
            </div>
            <div class="text-center py-3 bg-blue-50">
              <p class="text-[10px] text-blue-500 mb-0.5">Total</p>
              <p class="text-sm font-bold text-blue-700">{{ formatPeso(totalForm) }}</p>
            </div>
          </div>

          <!-- Materiales -->
          <div class="px-4 mt-5">
            <div class="flex items-center justify-between mb-3">
              <h2 class="text-sm font-semibold text-gray-700 flex items-center gap-1.5"><CubeIcon class="w-4 h-4 text-gray-500" />Materiales</h2>
              <button @click="formNuevo.materiales.push(nuevoItemMaterial())" class="flex items-center gap-1 text-xs text-blue-600 font-medium"><PlusIcon class="w-4 h-4" />Agregar</button>
            </div>
            <div class="space-y-2">
              <div v-for="item in formNuevo.materiales" :key="item._id" class="bg-white border border-gray-200 rounded-xl p-3 space-y-2">
                <div class="relative">
                  <label class="text-[10px] text-gray-400 uppercase font-medium">Material</label>
                  <input v-model="item.descripcion" @input="onMaterialInput(item)" @blur="cerrarSugerencias" type="text" placeholder="Nombre del material..."
                    class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 mt-0.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  <div v-if="sugerencias.length && idBuscando === item._id"
                    class="absolute left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-xl shadow-lg z-10 overflow-hidden">
                    <button v-for="sug in sugerencias" :key="sug.descripcion" @mousedown.prevent="seleccionarSugerencia(item, sug)"
                      class="w-full text-left px-3 py-2.5 hover:bg-blue-50 flex items-center justify-between border-b border-gray-50 last:border-0">
                      <div>
                        <p class="text-xs font-medium text-gray-800">{{ sug.descripcion }}</p>
                        <p class="text-[10px] text-gray-400">{{ sug.unidad }}</p>
                      </div>
                      <span class="text-xs font-semibold text-blue-600 ml-2 flex-shrink-0">{{ formatPeso(sug.precio_promedio) }}</span>
                    </button>
                  </div>
                </div>
                <div class="grid grid-cols-4 gap-2">
                  <div>
                    <label class="text-[10px] text-gray-400 uppercase font-medium">Cant.</label>
                    <input v-model="item.cantidad" @input="recalcularItemForm(item)" type="number" step="any" min="0"
                      class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 mt-0.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <div>
                    <label class="text-[10px] text-gray-400 uppercase font-medium">Descripción</label>
                    <input v-model="item.unidad" type="text" placeholder="Metros..."
                      class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 mt-0.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <div>
                    <label class="text-[10px] text-gray-400 uppercase font-medium">Vr. Unit.</label>
                    <input v-model="item.precio_unitario" @input="recalcularItemForm(item)" type="number" step="any" min="0"
                      class="w-full text-sm border border-gray-300 rounded-lg px-2 py-1.5 mt-0.5 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <div>
                    <label class="text-[10px] text-gray-400 uppercase font-medium">Subtotal</label>
                    <p class="text-sm font-bold text-gray-700 py-1.5 mt-0.5">{{ formatPeso(item.subtotal) }}</p>
                  </div>
                </div>
                <div class="flex justify-end">
                  <button v-if="formNuevo.materiales.length > 1" @click="eliminarItem(formNuevo.materiales, item._id)"
                    class="text-xs text-red-400 hover:text-red-600 flex items-center gap-0.5"><TrashIcon class="w-3.5 h-3.5" />Eliminar</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Mano de obra -->
          <div class="px-4 mt-6 pb-4">
            <h2 class="text-sm font-semibold text-orange-600 flex items-center gap-1.5 mb-3">
              <WrenchScrewdriverIcon class="w-4 h-4" />Mano de obra
            </h2>

            <!-- Chips de procesos disponibles -->
            <div class="space-y-3 mb-4">
              <div v-for="[cargo, items] in procesosAgrupados" :key="cargo">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5 capitalize">{{ cargo }}</p>
                <div class="flex flex-wrap gap-1.5">
                  <button v-for="p in items" :key="p.id" @click="agregarDesdeTarifa(p)"
                    class="flex items-center gap-1 pl-2 pr-2.5 py-1 rounded-full text-xs border border-orange-200 bg-white text-orange-700 hover:bg-orange-50 active:scale-95 transition-transform">
                    <PlusIcon class="w-3 h-3 flex-shrink-0" />
                    <span>{{ (p.descripcion || p.proceso).split('(')[0].trim().split(' por ')[0] }}</span>
                    <span class="text-[10px] text-orange-400 ml-0.5">{{ formatPeso(tarifaDiariaFor(p.cargo)) }}/h · {{ p._horas }}h</span>
                  </button>
                </div>
              </div>
            </div>

            <!-- Items agregados -->
            <div v-if="formNuevo.manoObra.length" class="space-y-2">
              <div v-for="item in formNuevo.manoObra" :key="item._id"
                class="bg-orange-50 border border-orange-200 rounded-xl px-3 py-2.5 flex items-center gap-3">
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 truncate">{{ item.descripcion }}</p>
                  <p class="text-[10px] text-gray-400">{{ formatPeso(item.precio_unitario) }} / {{ item.unidad || 'unidad' }}</p>
                </div>
                <div class="flex items-center gap-1.5">
                  <label class="text-[10px] text-gray-400">Cant.</label>
                  <input v-model="item.cantidad" @input="recalcularItemForm(item)" type="number" step="any" min="0"
                    class="w-14 text-center text-sm border border-orange-200 rounded-lg px-1 py-1.5 focus:outline-none focus:ring-2 focus:ring-orange-400 bg-white" />
                </div>
                <p class="text-sm font-bold text-orange-600 w-20 text-right shrink-0">{{ formatPeso(item.subtotal) }}</p>
                <button @click="eliminarItem(formNuevo.manoObra, item._id)">
                  <TrashIcon class="w-4 h-4 text-red-400 hover:text-red-600" />
                </button>
              </div>
            </div>
            <p v-else class="text-xs text-gray-400 text-center py-2">Toca un proceso para agregarlo</p>
          </div>
        </div>
      </div>

      <!-- ── Modal editar material ───────────────────────────────────────────── -->
      <div v-if="materialEditando" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full max-w-md p-5">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-bold text-gray-800">Editar material</h2>
            <button @click="materialEditando = null"><XMarkIcon class="w-5 h-5 text-gray-400" /></button>
          </div>

          <div class="space-y-3">
            <div>
              <label class="text-xs font-medium text-gray-600">Nombre</label>
              <input v-model="materialEditando.nombre" type="text"
                class="w-full mt-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="text-xs font-medium text-gray-600">Descripción</label>
              <input v-model="materialEditando._unidad" type="text" placeholder="Lamina, Metros..."
                class="w-full mt-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="text-xs font-medium text-gray-600">Precio unitario</label>
              <input v-model="materialEditando._precio" type="number" step="any" min="0"
                class="w-full mt-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              <p class="text-[10px] text-amber-600 mt-1">⚠ Cambiar el precio actualizará todos los productos que usen este material</p>
            </div>
          </div>

          <div class="flex gap-2 mt-5">
            <button @click="materialEditando = null" class="flex-1 py-2 text-sm text-gray-600 border border-gray-300 rounded-xl hover:bg-gray-50">Cancelar</button>
            <button @click="guardarMaterial" :disabled="guardandoMat"
              class="flex-1 py-2 text-sm font-medium text-white bg-blue-600 rounded-xl disabled:opacity-50 hover:bg-blue-700 flex items-center justify-center gap-1">
              <ArrowPathIcon v-if="guardandoMat" class="w-4 h-4 animate-spin" />
              {{ guardandoMat ? 'Guardando...' : 'Guardar y propagar' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ── Modal nuevo material ────────────────────────────────────────────── -->
      <div v-if="mostrarFormMat" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full max-w-md p-5">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-bold text-gray-800">Nuevo material</h2>
            <button @click="mostrarFormMat = false"><XMarkIcon class="w-5 h-5 text-gray-400" /></button>
          </div>
          <div class="space-y-3">
            <div>
              <label class="text-xs font-medium text-gray-600">Nombre <span class="text-red-500">*</span></label>
              <input v-model="formMat.nombre" type="text" placeholder="Ej: FLOR MORADO 18 M.M"
                class="w-full mt-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="text-xs font-medium text-gray-600">Descripción</label>
              <input v-model="formMat.unidad" type="text" placeholder="LAMINA, METROS..."
                class="w-full mt-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="text-xs font-medium text-gray-600">Precio unitario <span class="text-red-500">*</span></label>
              <input v-model="formMat.precio_unitario" type="number" step="any" min="0" placeholder="0"
                class="w-full mt-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
          </div>
          <div class="flex gap-2 mt-5">
            <button @click="mostrarFormMat = false" class="flex-1 py-2 text-sm text-gray-600 border border-gray-300 rounded-xl hover:bg-gray-50">Cancelar</button>
            <button @click="guardarNuevoMaterial" :disabled="guardandoMat"
              class="flex-1 py-2 text-sm font-medium text-white bg-blue-600 rounded-xl disabled:opacity-50 flex items-center justify-center gap-1">
              <ArrowPathIcon v-if="guardandoMat" class="w-4 h-4 animate-spin" />
              {{ guardandoMat ? 'Guardando...' : 'Crear' }}
            </button>
          </div>
        </div>
      </div>

    </Teleport>
  </div>
</template>
