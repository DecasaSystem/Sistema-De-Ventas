<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  MagnifyingGlassIcon,
  ExclamationTriangleIcon,
  PlusIcon,
  PencilIcon,

  
  ArchiveBoxIcon,
  PhotoIcon,
  XMarkIcon,
  CheckCircleIcon,
  XCircleIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  ArrowRightIcon,
} from '@heroicons/vue/24/outline'
import { getInventario, addStock, removeStock, getVariantes, crearVariante, addStockVariante, getMovimientos } from '@/api/inventario'
import SurtidosPendientesPanel from '@/components/inventario/SurtidosPendientesPanel.vue'
import ModalCatalogoTela from '@/components/inventario/ModalCatalogoTela.vue'
import { getTrasladosPendientes, aceptarTraslado, rechazarTraslado } from '@/api/traslados'
import { useRealtime } from '@/composables/useRealtime'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { useToast } from '@/composables/useToast'
import { getTiendas } from '@/api/ordenes'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import api from '@/api'
import { comprimirImagen } from '@/utils/comprimirImagen'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const CATEGORY_LABELS = {
  'cajoneros':       'Cajoneros',
  'camas':           'Camas',
  'colchones':       'Colchones',
  'comedores':       'Bases comedor',
  'cunas':           'Cunas',
  'Electrica':       'Eléctrica',
  'escritorios':     'Escritorios',
  'mesas_aux':       'Mesas aux.',
  'mesas_centro':    'Mesas centro',
  'mesas_noche':     'Mesas noche',
  'mesas_tv':        'Mesas TV',
  'Puff':            'Puff',
  'Reloj':           'Relojes',
  'sillas_aux':      'Sillas de sala',
  'sillas_barra':    'Sillas de barra',
  'sillas_comedor':  'Sillas comedor',
  'sofa_camas':      'Sofacamas',
  'sofas':           'Sofás',
  'sofas_modulares': 'Modulares',
}

const tiendas = ref([])
const tiendaId = ref(auth.usuario?.tienda_default_id ?? '')
const inventario = ref([])
const busqueda = ref('')
const categoriaFiltro = ref('')
const categoriasDisponibles = ref([])
const loading = ref(!!auth.usuario?.tienda_default_id)
const currentPage = ref(1)
const lastPage = ref(1)
const tieneMas = ref(false)
const loadingMore = ref(false)
const mostrarGestionar = ref(false)
const itemGestionar = ref(null)
const mostrarAgregarStock = ref(false)

const nuevoStock = ref(0)
const stockMotivo = ref('')
const nuevoPrecio = ref(0)
const gestionError = ref('')
const gestionLoading = ref(false)
const stockError = ref('')
const stockLoading = ref(false)

const quitarStockCant   = ref(0)
const quitarStockMotivo = ref('')
const quitarStockError  = ref('')
const quitarStockLoad   = ref(false)

const fotoModal = ref(false)
const fotoProducto = ref(null)

// ── Cambiar nombre / descripción desde gestionar ─────────────────────────────
const gestionNombre       = ref('')
const gestionDescripcion  = ref('')
const gestionMedidas      = ref('')
const gestionMaterial     = ref('')
const gestionInfoLoading  = ref(false)
const gestionInfoError    = ref('')

async function guardarNombreDescripcion() {
  gestionInfoError.value = ''
  if (!gestionNombre.value.trim()) {
    gestionInfoError.value = 'El nombre no puede estar vacío.'
    return
  }
  gestionInfoLoading.value = true
  try {
    await api.patch(`/productos/${itemGestionar.value.producto_id}`, {
      nombre:      gestionNombre.value.trim(),
      descripcion: gestionDescripcion.value.trim() || null,
      medidas:     gestionMedidas.value.trim() || null,
      material:    gestionMaterial.value.trim() || null,
    })
    if (itemGestionar.value.producto) {
      itemGestionar.value.producto.nombre      = gestionNombre.value.trim()
      itemGestionar.value.producto.descripcion = gestionDescripcion.value.trim() || null
      itemGestionar.value.producto.medidas     = gestionMedidas.value.trim() || null
      itemGestionar.value.producto.material    = gestionMaterial.value.trim() || null
    }
    const idx = inventario.value.findIndex(i => i.producto_id === itemGestionar.value.producto_id)
    if (idx !== -1 && inventario.value[idx].producto) {
      inventario.value[idx].producto.nombre = gestionNombre.value.trim()
    }
    toast.success('Información guardada.')
  } catch (e) {
    gestionInfoError.value = e.response?.data?.message ?? 'Error al guardar.'
  } finally {
    gestionInfoLoading.value = false
  }
}

// ── Cambiar foto desde gestionar ──────────────────────────────────────────────
const gestionFotoFile       = ref(null)
const gestionFotoPreviewUrl = ref('')
const gestionFotoInput      = ref(null)
const gestionFotoLoading    = ref(false)
const gestionFotoError      = ref('')

// Segunda foto
const gestionFoto2File       = ref(null)
const gestionFoto2PreviewUrl = ref('')
const gestionFoto2Input      = ref(null)
const gestionFoto2Loading    = ref(false)
const gestionFoto2Error      = ref('')

function onGestionFotoChange(e) {
  const file = e.target.files[0]
  if (!file) return
  if (gestionFotoPreviewUrl.value) URL.revokeObjectURL(gestionFotoPreviewUrl.value)
  gestionFotoFile.value = file
  gestionFotoPreviewUrl.value = URL.createObjectURL(file)
}

function quitarGestionFoto() {
  if (gestionFotoPreviewUrl.value) URL.revokeObjectURL(gestionFotoPreviewUrl.value)
  gestionFotoFile.value = null
  gestionFotoPreviewUrl.value = ''
  if (gestionFotoInput.value) gestionFotoInput.value.value = ''
}

function onGestionFoto2Change(e) {
  const file = e.target.files[0]
  if (!file) return
  if (gestionFoto2PreviewUrl.value) URL.revokeObjectURL(gestionFoto2PreviewUrl.value)
  gestionFoto2File.value = file
  gestionFoto2PreviewUrl.value = URL.createObjectURL(file)
}

function quitarGestionFoto2() {
  if (gestionFoto2PreviewUrl.value) URL.revokeObjectURL(gestionFoto2PreviewUrl.value)
  gestionFoto2File.value = null
  gestionFoto2PreviewUrl.value = ''
  if (gestionFoto2Input.value) gestionFoto2Input.value.value = ''
}

async function guardarFoto2Producto() {
  gestionFoto2Error.value = ''
  gestionFoto2Loading.value = true
  try {
    const fd = new FormData()
    fd.append('foto', await comprimirImagen(gestionFoto2File.value), 'producto.jpg')
    const { data: upload } = await api.post('/upload/foto', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    await api.patch(`/productos/${itemGestionar.value.producto_id}`, { foto_url_2: upload.url })
    if (itemGestionar.value.producto) itemGestionar.value.producto.foto_url_2 = upload.url
    quitarGestionFoto2()
    toast.success('Segunda foto actualizada.')
    await cargarInventario(true)
  } catch (e) {
    gestionFoto2Error.value = e.response?.data?.message ?? 'Error al subir la foto.'
  } finally {
    gestionFoto2Loading.value = false
  }
}

async function guardarFotoProducto() {
  gestionFotoError.value = ''
  gestionFotoLoading.value = true
  try {
    const fd = new FormData()
    fd.append('foto', await comprimirImagen(gestionFotoFile.value), 'producto.jpg')
    const { data: upload } = await api.post('/upload/foto', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    await api.patch(`/productos/${itemGestionar.value.producto_id}`, { foto_url: upload.url })
    if (itemGestionar.value.producto) itemGestionar.value.producto.foto_url = upload.url
    quitarGestionFoto()
    toast.success('Foto actualizada.')
    await cargarInventario(true)
  } catch (e) {
    gestionFotoError.value = e.response?.data?.message ?? 'Error al subir la foto.'
  } finally {
    gestionFotoLoading.value = false
  }
}

const mostrarHistorial = ref(false)
const itemHistorial = ref(null)
const movimientos = ref([])
const movimientosLoading = ref(false)

function verFoto(producto) {
  fotoProducto.value = producto
  fotoModal.value = true
}

async function abrirHistorial(item) {
  itemHistorial.value = { producto_id: item.producto_id, producto_nombre: item.producto?.nombre }
  movimientos.value = []
  movimientosLoading.value = true
  mostrarHistorial.value = true
  try {
    const tid = esVistaGlobal.value ? null : tiendaId.value
    const { data } = await getMovimientos(item.producto_id, tid)
    movimientos.value = data
  } catch {
    movimientos.value = []
  } finally {
    movimientosLoading.value = false
  }
}

// ── Agregar producto ──────────────────────────────────────────────────────────
const mostrarAgregarProducto = ref(false)
const mostrarCatalogoTela    = ref(false)
const creandoProducto = ref(false)
const subiendoFoto = ref(false)
const errCrearProducto = ref('')
const tiendasFormSeleccionadas = ref([])

const fotoFile = ref(null)
const fotoPreviewUrl = ref('')
const fotoInput = ref(null)

const formProducto = ref({
  nombre: '',
  categoria: '',
  precio_base: '',
  personalizable: false,
  es_tapizado: false,
  descripcion: '',
  medidas: '',
  material: '',
})

const categoriasExistentes = ref([])
const categoriaSeleccion   = ref('')   // valor del <select>; '__nueva__' activa el input libre

const todasTiendasSeleccionadas = computed(
  () => tiendas.value.length > 0 && tiendasFormSeleccionadas.value.length === tiendas.value.length
)

function toggleTodasTiendas() {
  if (todasTiendasSeleccionadas.value) {
    tiendasFormSeleccionadas.value = []
  } else {
    tiendasFormSeleccionadas.value = tiendas.value.map((t) => t.id)
  }
}

function onFotoChange(e) {
  const file = e.target.files[0]
  if (!file) return
  if (fotoPreviewUrl.value) URL.revokeObjectURL(fotoPreviewUrl.value)
  fotoFile.value = file
  fotoPreviewUrl.value = URL.createObjectURL(file)
}

function quitarFoto() {
  if (fotoPreviewUrl.value) URL.revokeObjectURL(fotoPreviewUrl.value)
  fotoFile.value = null
  fotoPreviewUrl.value = ''
  if (fotoInput.value) fotoInput.value.value = ''
}

async function abrirAgregarProducto() {
  formProducto.value = { nombre: '', categoria: '', precio_base: '', personalizable: false, es_tapizado: false, descripcion: '', medidas: '', material: '' }
  categoriaSeleccion.value = ''
  tiendasFormSeleccionadas.value = auth.isSupervisor ? tiendas.value.map((t) => t.id) : []
  quitarFoto()
  errCrearProducto.value = ''
  mostrarAgregarProducto.value = true
  try {
    const { data } = await api.get('/productos/categorias')
    categoriasExistentes.value = data
  } catch {}
}

const CATEGORIAS_TAPIZADAS = /sofa|sofá|silla|modular/i

function esTapizadoPorNombre(texto) {
  return CATEGORIAS_TAPIZADAS.test(texto ?? '')
}

function onNombreProductoInput() {
  if (esTapizadoPorNombre(formProducto.value.nombre)) {
    formProducto.value.es_tapizado = true
  }
}

function onCategoriaSelect(val) {
  categoriaSeleccion.value = val
  if (val !== '__nueva__') {
    formProducto.value.categoria = val
    if (esTapizadoPorNombre(val)) formProducto.value.es_tapizado = true
  } else {
    formProducto.value.categoria = ''
  }
}

async function crearProducto() {
  errCrearProducto.value = ''
  if (!formProducto.value.nombre.trim()) {
    errCrearProducto.value = 'El nombre es obligatorio.'
    return
  }
  if (!formProducto.value.precio_base || Number(formProducto.value.precio_base) < 0) {
    errCrearProducto.value = 'El precio base es obligatorio.'
    return
  }
  if (auth.isSupervisor && tiendasFormSeleccionadas.value.length === 0) {
    errCrearProducto.value = 'Selecciona al menos una tienda.'
    return
  }

  creandoProducto.value = true
  try {
    // 1. Subir foto a Cloudinary si el usuario seleccionó una
    let foto_url = undefined
    if (fotoFile.value) {
      subiendoFoto.value = true
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(fotoFile.value), 'producto.jpg')
      const { data } = await api.post('/upload/foto', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      foto_url = data.url
      subiendoFoto.value = false
    }

    // 2. Crear el producto con la URL obtenida
    const payload = {
      ...formProducto.value,
      precio_base: Number(formProducto.value.precio_base),
      ...(foto_url ? { foto_url } : {}),
    }
    if (auth.isSupervisor) payload.tiendas = tiendasFormSeleccionadas.value
    await api.post('/productos', payload)
    mostrarAgregarProducto.value = false
    if (tiendaId.value) await cargarInventario()
  } catch (e) {
    subiendoFoto.value = false
    errCrearProducto.value = e.response?.data?.message ?? 'Error al crear el producto.'
  } finally {
    creandoProducto.value = false
  }
}

const esVistaGlobal   = computed(() => tiendaId.value === 'todas')
const puedeGestionar  = computed(() =>
  auth.isSupervisor || String(tiendaId.value) === String(auth.usuario?.tienda_default_id)
)

const sentinel = ref(null)
let observer = null

async function cargarTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
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
  if (!tiendaId.value) return
  if (reset) loading.value = true
  let nuevosItems = []
  try {
    const page = reset ? 1 : currentPage.value + 1
    const { data } = await getInventario(tiendaId.value, busqueda.value.trim(), page, categoriaFiltro.value)
    nuevosItems = data.data
    if (reset) {
      inventario.value = nuevosItems
    } else {
      inventario.value.push(...nuevosItems)
    }
    currentPage.value = data.current_page
    lastPage.value = data.last_page
    tieneMas.value = data.current_page < data.last_page
  } catch {
    if (reset) inventario.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
  if (tieneMas.value) nextTick(setupObserver)
  nextTick(() => {
    nuevosItems.forEach(i => { if (i.producto?.es_tapizado || i.producto?.tiene_tallas) cargarVariantes(i) })
  })
}

function loadMore() {
  if (loadingMore.value || !tieneMas.value) return
  loadingMore.value = true
  cargarInventario(false)
}

function setupObserver() {
  if (observer) observer.disconnect()
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && tieneMas.value && !loadingMore.value) {
      loadMore()
    }
  }, { rootMargin: '200px' })
  nextTick(() => {
    if (sentinel.value) observer.observe(sentinel.value)
  })
}

function openGestionar(item) {
  itemGestionar.value = item
  nuevoPrecio.value = parseFloat(item.producto?.precio_base ?? 0)
  gestionNombre.value      = item.producto?.nombre ?? ''
  gestionDescripcion.value = item.producto?.descripcion ?? ''
  gestionMedidas.value     = item.producto?.medidas ?? ''
  gestionMaterial.value    = item.producto?.material ?? ''
  gestionInfoError.value   = ''
  nuevoStock.value = 0
  stockMotivo.value = ''
  gestionError.value = ''
  stockError.value = ''
  quitarStockCant.value   = 0
  quitarStockMotivo.value = ''
  quitarStockError.value  = ''
  quitarGestionFoto()
  gestionFotoError.value = ''
  quitarGestionFoto2()
  gestionFoto2Error.value = ''
  mostrarGestionar.value = true
}

async function guardarPrecio() {
  gestionError.value = ''
  gestionLoading.value = true
  try {
    await api.patch(`/productos/${itemGestionar.value.producto_id}`, {
      precio_base: nuevoPrecio.value,
    })
    toast.success('Precio actualizado.')
    mostrarGestionar.value = false
    await cargarInventario(true)
  } catch (e) {
    gestionError.value = e.response?.data?.message ?? 'Error al actualizar el precio.'
  } finally {
    gestionLoading.value = false
  }
}

async function guardarStock() {
  stockError.value = ''
  if (!nuevoStock.value || nuevoStock.value < 1) {
    stockError.value = 'Ingresa una cantidad válida.'
    return
  }
  stockLoading.value = true
  try {
    await addStock({
      producto_id: itemGestionar.value.producto_id,
      tienda_id: esVistaGlobal.value ? 'todas' : tiendaId.value,
      cantidad: nuevoStock.value,
      motivo: stockMotivo.value || undefined,
    })
    mostrarGestionar.value = false
    await cargarInventario(true)
  } catch (e) {
    stockError.value = e.response?.data?.message ?? 'Error al agregar stock.'
  } finally {
    stockLoading.value = false
  }
}

async function quitarStock() {
  quitarStockError.value = ''
  if (!quitarStockCant.value || quitarStockCant.value < 1) {
    quitarStockError.value = 'Ingresa una cantidad válida.'
    return
  }
  quitarStockLoad.value = true
  try {
    await removeStock({
      producto_id: itemGestionar.value.producto_id,
      tienda_id:   tiendaId.value,
      cantidad:    quitarStockCant.value,
      motivo:      quitarStockMotivo.value || undefined,
    })
    mostrarGestionar.value = false
    await cargarInventario(true)
  } catch (e) {
    quitarStockError.value = e.response?.data?.message ?? 'Error al quitar stock.'
  } finally {
    quitarStockLoad.value = false
  }
}

function esTapizado(item) {
  return !!item.producto?.es_tapizado
}

function esTalla(item) {
  return !!item.producto?.tiene_tallas
}

async function toggleEsTapizado() {
  const nuevoValor = !itemGestionar.value.producto.es_tapizado
  try {
    await api.patch(`/productos/${itemGestionar.value.producto_id}`, { es_tapizado: nuevoValor })
    itemGestionar.value.producto.es_tapizado = nuevoValor
    const idx = inventario.value.findIndex(i => i.producto_id === itemGestionar.value.producto_id)
    if (idx !== -1) {
      inventario.value[idx].producto.es_tapizado = nuevoValor
      if (nuevoValor && !variantesData.value[itemGestionar.value.producto_id]) {
        await cargarVariantes(inventario.value[idx])
      }
    }
  } catch {
    // silencioso — el toggle se revierte al no cambiar el ref
  }
}

async function toggleTieneTallas() {
  const nuevoValor = !itemGestionar.value.producto.tiene_tallas
  try {
    await api.patch(`/productos/${itemGestionar.value.producto_id}`, { tiene_tallas: nuevoValor })
    itemGestionar.value.producto.tiene_tallas = nuevoValor
    const idx = inventario.value.findIndex(i => i.producto_id === itemGestionar.value.producto_id)
    if (idx !== -1) {
      inventario.value[idx].producto.tiene_tallas = nuevoValor
      if (nuevoValor && !variantesData.value[itemGestionar.value.producto_id]) {
        await cargarVariantes(inventario.value[idx])
      }
    }
  } catch {
    // silencioso
  }
}

// ── Variantes ─────────────────────────────────────────────────────────────────
const variantesAbiertas  = ref({})   // { producto_id: bool }
const variantesData      = ref({})   // { producto_id: Variante[] }
const varianteCargando   = ref({})   // { producto_id: bool }

const mostrarStockVariante   = ref(false)
const varianteStockItem      = ref(null)   // { variante, productoId }
const varianteStockCantidad  = ref(1)
const varianteStockMotivo    = ref('')
const varianteStockLoading   = ref(false)
const varianteStockError     = ref('')

const mostrarNuevaVariante  = ref(false)
const varianteProdId        = ref(null)
const varianteTipoTalla     = ref(false)
const formVariante          = ref({
  marca: '', marcaManual: '',
  marca_tela: '', telaManual: '',
  nombre_color: '', colorManual: '',
})
const formVarianteTalla     = ref({ medida: '', precio_variante: '' })
const varianteCreandoLoad   = ref(false)
const varianteCreandoError  = ref('')

const tiposTelaOpciones = computed(() =>
  formVariante.value.marca && formVariante.value.marca !== 'Otro'
    ? tiposTelaDeM(formVariante.value.marca)
    : []
)
const coloresOpciones = computed(() =>
  formVariante.value.marca && formVariante.value.marca !== 'Otro' &&
  formVariante.value.marca_tela && formVariante.value.marca_tela !== 'Otro'
    ? coloresDeTela(formVariante.value.marca, formVariante.value.marca_tela)
    : []
)
const marcaFinal = computed(() =>
  formVariante.value.marca === 'Otro' ? formVariante.value.marcaManual : formVariante.value.marca
)
const telaFinal = computed(() =>
  formVariante.value.marca_tela === 'Otro' ? formVariante.value.telaManual : formVariante.value.marca_tela
)
const colorFinal = computed(() =>
  formVariante.value.nombre_color === 'Otro' ? formVariante.value.colorManual : formVariante.value.nombre_color
)

async function cargarVariantes(item) {
  const pid = item.producto_id
  if (variantesData.value[pid] !== undefined) return
  varianteCargando.value[pid] = true
  try {
    const { data } = await getVariantes(pid, esVistaGlobal.value ? null : tiendaId.value)
    variantesData.value[pid] = data
  } finally {
    varianteCargando.value[pid] = false
  }
}

async function toggleVariantes(item) {
  const pid = item.producto_id
  variantesAbiertas.value[pid] = !variantesAbiertas.value[pid]
  if (!variantesData.value[pid]) {
    await cargarVariantes(item)
  }
}

const varianteStockSinAsignar = computed(() => {
  if (!varianteStockItem.value) return 0
  const { productoId, item } = varianteStockItem.value
  const baseDisp = item?.cantidad_disponible ?? 0
  const variantes = variantesData.value[productoId] ?? []
  const totalAsignado = variantes.reduce((s, v) => s + (v.stock_disponible ?? 0), 0)
  return Math.max(0, baseDisp - totalAsignado)
})

function abrirStockVariante(variante, item) {
  varianteStockItem.value   = { variante, productoId: item.producto_id, item }
  varianteStockCantidad.value = 1
  varianteStockMotivo.value  = ''
  varianteStockError.value   = ''
  mostrarStockVariante.value = true
}

async function guardarStockVariante() {
  varianteStockError.value  = ''
  if (varianteStockCantidad.value < 1) {
    varianteStockError.value = 'Ingresa una cantidad válida.'
    return
  }
  varianteStockLoading.value = true
  try {
    await addStockVariante({
      variante_id: varianteStockItem.value.variante.id,
      tienda_id:   tiendaId.value,
      cantidad:    varianteStockCantidad.value,
      motivo:      varianteStockMotivo.value || undefined,
    })
    mostrarStockVariante.value = false
    // Recargar variantes del producto
    const pid = varianteStockItem.value.productoId
    const { data } = await getVariantes(pid, tiendaId.value)
    variantesData.value[pid] = data
  } catch (e) {
    varianteStockError.value = e.response?.data?.message ?? 'Error al agregar stock.'
  } finally {
    varianteStockLoading.value = false
  }
}

function abrirNuevaVariante(item) {
  varianteProdId.value       = item.producto_id
  varianteTipoTalla.value    = esTalla(item)
  formVariante.value         = { marca: '', marcaManual: '', marca_tela: '', telaManual: '', nombre_color: '', colorManual: '' }
  formVarianteTalla.value    = { medida: '', precio_variante: '' }
  varianteCreandoError.value = ''
  mostrarNuevaVariante.value = true
}

async function guardarNuevaVariante() {
  varianteCreandoError.value = ''

  if (varianteTipoTalla.value) {
    if (!formVarianteTalla.value.medida.trim()) {
      varianteCreandoError.value = 'Ingresa la medida (ej: 1.60x1.90).'
      return
    }
    varianteCreandoLoad.value = true
    try {
      await crearVariante(varianteProdId.value, {
        medida:          formVarianteTalla.value.medida.trim(),
        precio_variante: formVarianteTalla.value.precio_variante || null,
      })
      mostrarNuevaVariante.value = false
      const { data } = await getVariantes(varianteProdId.value, tiendaId.value)
      variantesData.value[varianteProdId.value] = data
    } catch (e) {
      varianteCreandoError.value = e.response?.data?.message ?? 'Error al crear variante.'
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
    await crearVariante(varianteProdId.value, {
      marca:        marcaFinal.value,
      marca_tela:   telaFinal.value,
      nombre_color: colorFinal.value,
    })
    mostrarNuevaVariante.value = false
    // Recargar variantes
    const { data } = await getVariantes(varianteProdId.value, tiendaId.value)
    variantesData.value[varianteProdId.value] = data
  } catch (e) {
    varianteCreandoError.value = e.response?.data?.message ?? 'Error al crear variante.'
  } finally {
    varianteCreandoLoad.value = false
  }
}

const { listen } = useRealtime()
const toast = useToast()

// ── Traslados pendientes de validación (vendedor validador) ───────────────────
const trasladosPend        = ref([])
const tPendAbiertos        = ref({})
const tPendRechazando      = ref({})
const tPendModalRechazar   = ref(null)
const tPendNotasRechazar   = ref('')
const tPendRechazandoLoad  = ref(false)

// Modal aceptar con cantidades por item
const tPendModalAceptar   = ref(null)   // traslado que se está aceptando
const tPendCantidades     = ref({})     // { item.id: cantidad_aceptada }
const tPendAceptandoLoad  = ref(false)

async function cargarTrasladosPendientes() {
  if (auth.usuario?.rol !== 'vendedor') return
  try {
    const { data } = await getTrasladosPendientes()
    trasladosPend.value = data
  } catch {}
}

function tPendAbrirAceptar(tr) {
  tPendModalAceptar.value = tr
  const cantidades = {}
  for (const item of tr.items ?? []) {
    cantidades[item.id] = item.cantidad
  }
  tPendCantidades.value = cantidades
}

async function tPendConfirmarAceptar() {
  const tr = tPendModalAceptar.value
  if (!tr) return
  tPendAceptandoLoad.value = true
  try {
    const items = (tr.items ?? []).map(item => ({
      id: item.id,
      cantidad_aceptada: tPendCantidades.value[item.id] ?? item.cantidad,
    }))
    await aceptarTraslado(tr.id, { items })
    trasladosPend.value = trasladosPend.value.filter(t => t.id !== tr.id)
    cargarInventario(true)
    toast.success(`Traslado #${tr.id} aceptado. Inventario actualizado.`)
    tPendModalAceptar.value = null
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al aceptar el traslado.')
  } finally {
    tPendAceptandoLoad.value = false
  }
}

async function tPendConfirmarRechazar() {
  if (!tPendModalRechazar.value) return
  tPendRechazandoLoad.value = true
  try {
    await rechazarTraslado(tPendModalRechazar.value.id, tPendNotasRechazar.value || null)
    trasladosPend.value = trasladosPend.value.filter(t => t.id !== tPendModalRechazar.value.id)
    toast.info(`Traslado #${tPendModalRechazar.value.id} rechazado.`)
    tPendModalRechazar.value = null
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al rechazar.')
  } finally {
    tPendRechazandoLoad.value = false
  }
}

onMounted(async () => {
  await Promise.all([cargarTiendas(), cargarCategorias()])
  cargarTrasladosPendientes()
  if (tiendaId.value) {
    await cargarInventario(true)
  }

  listen('inventario', 'inventario.actualizado', (e) => {
    // Recargar solo si el evento es de la tienda que se está viendo
    const tiendaActual = String(tiendaId.value)
    if (tiendaActual === 'todas' || tiendaActual === String(e.tienda_id)) {
      // Limpiar cache de variantes para que se recarguen al expandir
      variantesData.value = {}
      variantesAbiertas.value = {}
      cargarInventario(true)
    }
  })

  // Auto-abrir historial desde notificación
  const queryAbrir = route.query.abrir
  if (queryAbrir) {
    const ids = queryAbrir.split(',').map(Number).filter(Boolean)
    const wait = setInterval(() => {
      const item = inventario.value.find(i => ids.includes(i.producto_id))
      if (item) {
        clearInterval(wait)
        abrirHistorial(item)
      }
    }, 200)
    setTimeout(() => clearInterval(wait), 10000)
  }
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Inventario</h2>
      <button
        v-if="auth.isSupervisor"
        @click="mostrarCatalogoTela = true"
        class="flex items-center gap-1.5 bg-white border border-gray-300 text-gray-700 text-sm font-semibold px-3 py-2 rounded-lg hover:bg-gray-50 transition-colors"
      >
        <PlusIcon class="w-4 h-4" />
        Tela
      </button>
      <button
        @click="abrirAgregarProducto"
        class="flex items-center gap-1.5 bg-blue-600 text-white text-sm font-semibold px-3 py-2 rounded-lg hover:bg-blue-700 transition-colors"
      >
        <PlusIcon class="w-4 h-4" />
        Producto
      </button>
    </div>

    <!-- Selector de tienda -->
    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
      <select
        v-model="tiendaId"
        @change="categoriaFiltro = ''; cargarInventario(true)"
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <option value="">Seleccionar tienda...</option>
        <option value="todas">Todas las tiendas</option>
        <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
      </select>
    </div>

    <!-- Buscador -->
    <div v-if="tiendaId" class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="cargarInventario(true)"
        placeholder="Buscar por nombre o categoría..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtro por categoría -->
    <div v-if="tiendaId && categoriasDisponibles.length" class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
      <button
        @click="seleccionarCategoria('')"
        :class="[
          'shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          categoriaFiltro === ''
            ? 'bg-blue-600 text-white border-blue-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'
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
            ? 'bg-blue-600 text-white border-blue-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'
        ]"
      >
        {{ CATEGORY_LABELS[cat] ?? cat }}
      </button>
    </div>

    <!-- Panel de surtidos pendientes (solo vendedor) -->
    <SurtidosPendientesPanel v-if="!auth.isSupervisor" @aceptado="cargarInventario(true)" />

    <!-- Panel de traslados pendientes de validación (solo vendedor validador) -->
    <div v-if="trasladosPend.length > 0" class="space-y-3">
      <div class="flex items-center gap-2">
        <ArrowRightIcon class="w-5 h-5 text-blue-500" />
        <h3 class="text-sm font-bold text-gray-800">Traslados pendientes ({{ trasladosPend.length }})</h3>
      </div>

      <div
        v-for="tr in trasladosPend"
        :key="tr.id"
        class="bg-blue-50 border border-blue-200 rounded-xl overflow-hidden shadow-sm"
      >
        <!-- Cabecera -->
        <button
          @click="tPendAbiertos[tr.id] = !tPendAbiertos[tr.id]"
          class="w-full flex items-center justify-between px-4 py-3 text-left"
        >
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">
              Traslado #{{ tr.id }}
              <span class="ml-1 text-xs font-normal text-gray-500">de {{ tr.supervisor?.nombre }}</span>
            </p>
            <p class="text-xs text-gray-500 mt-0.5">
              {{ tr.tienda_origen?.nombre }} → {{ tr.tienda_destino?.nombre }} · {{ tr.items?.length ?? 0 }} producto(s)
            </p>
          </div>
          <component :is="tPendAbiertos[tr.id] ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2" />
        </button>

        <!-- Productos -->
        <Transition name="slide">
          <div v-if="tPendAbiertos[tr.id]" class="border-t border-blue-100 px-4 pb-3 pt-2 space-y-2">
            <div
              v-for="item in tr.items"
              :key="item.id"
              class="flex items-center gap-3 bg-white rounded-lg px-3 py-2"
            >
              <img v-if="item.producto?.foto_url" :src="item.producto.foto_url" class="w-9 h-9 rounded-lg object-cover flex-shrink-0" />
              <div class="w-9 h-9 rounded-lg bg-gray-100 flex-shrink-0" v-else />
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ item.producto?.nombre }}</p>
                <p class="text-xs text-gray-400">{{ item.producto?.categoria }}</p>
              </div>
              <span class="text-sm font-bold text-green-700 flex-shrink-0">+{{ item.cantidad }}</span>
            </div>
            <p v-if="tr.notas" class="text-xs text-gray-500 italic">Notas: "{{ tr.notas }}"</p>
          </div>
        </Transition>

        <!-- Acciones -->
        <div class="flex gap-2 px-4 pb-3" :class="{ 'border-t border-blue-100 pt-3': !tPendAbiertos[tr.id] }">
          <button
            @click="tPendAbrirAceptar(tr)"
            class="flex-1 flex items-center justify-center gap-1.5 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors"
          >
            <CheckCircleIcon class="w-4 h-4" />
            Confirmar recepción
          </button>
          <button
            @click="tPendModalRechazar = tr; tPendNotasRechazar = ''"
            class="px-4 flex items-center gap-1.5 border border-red-300 text-red-600 rounded-lg py-2.5 text-sm font-semibold hover:bg-red-50 transition-colors"
          >
            <XCircleIcon class="w-4 h-4" />
            Rechazar
          </button>
        </div>
      </div>
    </div>

    <!-- Modal confirmar recepción de traslado (por item) -->
    <Transition name="fade">
      <div
        v-if="tPendModalAceptar"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
        @click.self="tPendModalAceptar = null"
      >
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[85vh] flex flex-col">
          <div class="flex items-center justify-between flex-shrink-0">
            <h3 class="text-base font-bold text-gray-800">Confirmar recepción #{{ tPendModalAceptar.id }}</h3>
            <button @click="tPendModalAceptar = null" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <p class="text-xs text-gray-500 flex-shrink-0">Ajusta la cantidad recibida de cada producto. Pon 0 si un item no llegó o fue rechazado.</p>
          <div class="overflow-y-auto flex-1 space-y-2">
            <div
              v-for="item in tPendModalAceptar.items"
              :key="item.id"
              class="flex items-center gap-3 bg-gray-50 rounded-lg px-3 py-2"
            >
              <img v-if="item.producto?.foto_url" :src="item.producto.foto_url" class="w-9 h-9 rounded-lg object-cover flex-shrink-0" />
              <div class="w-9 h-9 rounded-lg bg-gray-100 flex-shrink-0" v-else />
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ item.producto?.nombre }}</p>
                <p class="text-xs text-gray-400">Enviado: {{ item.cantidad }}</p>
              </div>
              <input
                type="number"
                :min="0"
                :max="item.cantidad"
                v-model.number="tPendCantidades[item.id]"
                class="w-16 rounded-lg border border-gray-300 text-center text-sm font-bold py-1.5 focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="{ 'text-red-600 border-red-300': tPendCantidades[item.id] < item.cantidad }"
              />
            </div>
          </div>
          <div class="flex gap-2 flex-shrink-0">
            <button
              @click="tPendModalAceptar = null"
              class="flex-1 border border-gray-300 text-gray-600 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-50"
            >
              Cancelar
            </button>
            <button
              @click="tPendConfirmarAceptar"
              :disabled="tPendAceptandoLoad"
              class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-bold hover:bg-green-700 disabled:opacity-50"
            >
              {{ tPendAceptandoLoad ? 'Guardando...' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal rechazar traslado -->
    <Transition name="fade">
      <div
        v-if="tPendModalRechazar"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
        @click.self="tPendModalRechazar = null"
      >
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">Rechazar traslado #{{ tPendModalRechazar.id }}</h3>
            <button @click="tPendModalRechazar = null" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <textarea
              v-model="tPendNotasRechazar"
              rows="3"
              placeholder="Ej: Los productos no llegaron completos..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 resize-none"
            />
          </div>
          <div class="flex gap-2">
            <button
              @click="tPendModalRechazar = null"
              class="flex-1 border border-gray-300 text-gray-600 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-50"
            >
              Cancelar
            </button>
            <button
              @click="tPendConfirmarRechazar"
              :disabled="tPendRechazandoLoad"
              class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-bold hover:bg-red-700 disabled:opacity-50"
            >
              {{ tPendRechazandoLoad ? 'Rechazando...' : 'Confirmar rechazo' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Indicador vista global -->
    <div v-if="esVistaGlobal" class="flex items-center gap-2 bg-blue-50 rounded-lg px-3 py-2">
      <ArchiveBoxIcon class="w-4 h-4 text-blue-500 flex-shrink-0" />
      <p class="text-xs text-blue-600 font-medium">Mostrando stock total de todas las tiendas</p>
    </div>

    <!-- Indicador solo consulta (tienda ajena) -->
    <div v-else-if="tiendaId && !puedeGestionar" class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
      <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 flex-shrink-0" />
      <p class="text-xs text-amber-700 font-medium">Solo consulta — puedes ver el stock pero no modificarlo</p>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="tiendaId && loading" />

    <!-- Empty -->
    <EmptyState
      v-else-if="tiendaId && inventario.length === 0"
      :message="esVistaGlobal ? 'No hay productos en ninguna tienda.' : 'No hay productos en esta tienda.'"
    />

    <!-- Lista -->
    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="item in inventario"
          :key="item.id"
          class="bg-white rounded-xl shadow-sm p-4 space-y-2"
        >
          <div class="flex justify-between items-start gap-2">
            <!-- Thumbnail foto -->
            <button
              @click="item.producto?.foto_url && verFoto(item.producto)"
              :class="[
                'flex-shrink-0 w-12 h-12 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center',
                item.producto?.foto_url ? 'cursor-pointer hover:opacity-75 transition-opacity' : 'cursor-default'
              ]"
              :title="item.producto?.foto_url ? 'Ver foto' : 'Sin foto'"
            >
              <img
                v-if="item.producto?.foto_url"
                :src="cloudinaryOpt(item.producto.foto_url, 160)"
                :alt="item.producto.nombre"
                class="w-full h-full object-cover"
                @error="$event.target.style.display='none'; $event.target.nextElementSibling.style.display='flex'"
              />
              <PhotoIcon class="w-6 h-6 text-gray-300" :style="item.producto?.foto_url ? 'display:none' : ''" />
            </button>

            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800 truncate">{{ item.producto?.nombre }}</p>
              <div class="flex items-center gap-1.5">
                <p class="text-xs text-gray-400">{{ item.producto?.categoria }}</p>
                <span v-if="esVistaGlobal && item.tiendas_count" class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  {{ item.tiendas_count }} {{ item.tiendas_count === 1 ? 'tienda' : 'tiendas' }}
                </span>
              </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <button
                @click="abrirHistorial(item)"
                class="text-gray-500 text-xs font-medium flex items-center gap-1"
              >
                <ArchiveBoxIcon class="w-4 h-4" />
                Historial
              </button>
              <button
                v-if="puedeGestionar"
                @click="openGestionar(item)"
                class="text-blue-600 text-xs font-medium flex items-center gap-1"
              >
                <PencilIcon class="w-4 h-4" />
                Gestionar
              </button>
            </div>
          </div>

          <!-- Stock -->
          <div :class="esVistaGlobal ? 'grid grid-cols-3 gap-2 text-center' : 'grid grid-cols-4 gap-2 text-center'">
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-800">{{ item.cantidad_disponible }}</p>
              <p class="text-xs text-gray-400">Disponible</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-500">{{ item.cantidad_reservada }}</p>
              <p class="text-xs text-gray-400">Reservado</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-green-600">{{ item.stock_libre }}</p>
              <p class="text-xs text-gray-400">Libre</p>
            </div>
            <div v-if="!esVistaGlobal" class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-600">{{ item.stock_minimo }}</p>
              <p class="text-xs text-gray-400">Mínimo</p>
            </div>
          </div>

          <!-- Precio y badge bajo stock -->
          <div class="flex justify-between items-center">
            <MoneyDisplay :amount="parseFloat(item.producto?.precio_base ?? 0)" bold />
            <span
              v-if="item.bajo_stock"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700"
            >
              <ExclamationTriangleIcon class="w-3.5 h-3.5" />
              Bajo stock
            </span>
          </div>

          <!-- Variantes tela/color o talla — para productos tapizados o con tallas -->
          <div v-if="esTapizado(item) || esTalla(item)" class="border-t border-gray-100 pt-2">
            <div class="flex items-center gap-2">
              <span class="text-xs text-blue-600 font-medium">{{ esTalla(item) ? 'Variantes por talla' : 'Variantes de tela/color' }}</span>
              <span v-if="variantesData[item.producto_id]?.length"
                class="bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full text-xs font-bold">
                {{ variantesData[item.producto_id].length }}
              </span>
            </div>

            <div class="mt-2 space-y-2">
              <div v-if="varianteCargando[item.producto_id]" class="text-xs text-gray-400">Cargando...</div>
              <template v-else-if="variantesData[item.producto_id]">
                <!-- Chips de variantes -->
                <div class="flex flex-wrap gap-1.5">
                  <button
                    v-for="v in variantesData[item.producto_id]"
                    :key="v.id"
                    @click="!esVistaGlobal && puedeGestionar && abrirStockVariante(v, item)"
                    :class="['px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
                      v.stock_libre > 0
                        ? 'bg-green-50 border-green-300 text-green-800'
                        : 'bg-gray-50 border-gray-200 text-gray-400',
                      puedeGestionar ? 'cursor-pointer hover:opacity-75' : 'cursor-default']"
                    :title="(esTalla(item) ? v.medida : [v.marca, v.marca_tela, v.nombre_color].filter(Boolean).join(' · ')) + (puedeGestionar ? ' — clic para agregar stock' : '')"
                  >
                    <template v-if="esTalla(item)">
                      {{ v.medida }}
                      <span v-if="v.precio_variante" class="text-gray-500"> ${{ Number(v.precio_variante).toLocaleString('es-CO') }}</span>
                    </template>
                    <template v-else>{{ v.marca_tela }} · {{ v.nombre_color }}</template>
                    <span class="ml-1 font-bold">{{ v.stock_libre ?? '—' }}</span>
                  </button>
                  <span v-if="!variantesData[item.producto_id]?.length" class="text-xs text-gray-400 italic">
                    Sin variantes registradas
                  </span>
                </div>

                <button
                  v-if="!esVistaGlobal && puedeGestionar"
                  @click="abrirNuevaVariante(item)"
                  class="text-xs text-blue-500 font-medium flex items-center gap-0.5 hover:text-blue-700"
                >
                  {{ esTalla(item) ? '+ Nueva talla' : '+ Nueva variante' }}
                </button>
              </template>
              <div v-else class="text-xs text-gray-400 italic">Cargando variantes...</div>
            </div>
          </div>
        </li>
      </ul>

      <!-- Sentinel scroll infinito -->
      <div ref="sentinel" class="py-4 text-center">
        <div v-if="loadingMore" class="text-sm text-gray-400">Cargando más...</div>
        <div v-else-if="!tieneMas && inventario.length > 0" class="text-xs text-gray-300">
          Mostrando {{ inventario.length }} productos
        </div>
      </div>
    </template>

    <!-- Modal Catálogo Tela -->
    <ModalCatalogoTela v-if="mostrarCatalogoTela" @close="mostrarCatalogoTela = false" />

    <!-- Modal Agregar Producto -->
    <Transition name="fade">
      <div v-if="mostrarAgregarProducto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarAgregarProducto = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg flex flex-col max-h-[90vh]">

          <!-- Cabecera fija -->
          <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <h3 class="text-lg font-bold text-gray-800">Nuevo producto</h3>
            <button @click="mostrarAgregarProducto = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <!-- Cuerpo scrollable -->
          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-3">

            <!-- Nombre -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
              <input v-model="formProducto.nombre" @input="onNombreProductoInput" type="text" placeholder="Ej: Sofá 3 puestos..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>

            <!-- Categoría + Precio -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                <select
                  :value="categoriaSeleccion"
                  @change="onCategoriaSelect($event.target.value)"
                  class="w-full rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                >
                  <option value="">Sin categoría</option>
                  <option v-for="cat in categoriasExistentes" :key="cat" :value="cat">{{ cat }}</option>
                  <option value="__nueva__">＋ Nueva categoría...</option>
                </select>
                <!-- Input libre cuando selecciona "Nueva categoría" -->
                <input
                  v-if="categoriaSeleccion === '__nueva__'"
                  v-model="formProducto.categoria"
                  type="text"
                  placeholder="Escribe la nueva categoría"
                  class="mt-1.5 w-full rounded-lg border border-blue-400 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  autofocus
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Precio base <span class="text-red-500">*</span></label>
                <input v-model="formProducto.precio_base" type="number" min="0" placeholder="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
            </div>

            <!-- Medidas + Material -->
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Medidas</label>
                <input v-model="formProducto.medidas" type="text" placeholder="Ej: 200x90x80 cm" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Material</label>
                <input v-model="formProducto.material" type="text" placeholder="Ej: Cuero, tela..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
            </div>

            <!-- Foto -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Foto del producto</label>
              <input ref="fotoInput" type="file" accept="image/*" class="hidden" @change="onFotoChange" />

              <!-- Preview grande cuando hay foto seleccionada -->
              <div v-if="fotoPreviewUrl" class="space-y-2">
                <div class="relative rounded-xl overflow-hidden border-2 border-blue-300 bg-gray-50">
                  <img :src="fotoPreviewUrl" alt="Vista previa" class="w-full object-contain" style="max-height: 220px;" />
                  <button
                    type="button"
                    @click="quitarFoto"
                    class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow-lg"
                  >
                    <XMarkIcon class="w-4 h-4" />
                  </button>
                </div>
                <div class="flex items-center justify-between">
                  <p class="text-xs text-gray-500 truncate max-w-[200px]">{{ fotoFile?.name }}</p>
                  <button type="button" @click="fotoInput.click()" class="text-xs text-blue-600 font-medium hover:underline">Cambiar</button>
                </div>
              </div>

              <!-- Placeholder cuando no hay foto -->
              <button v-else type="button" @click="fotoInput.click()" class="w-full flex flex-col items-center gap-2 border-2 border-dashed border-gray-300 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
                <PhotoIcon class="w-8 h-8 text-gray-300" />
                <span class="text-sm text-gray-500">Toca para seleccionar foto</span>
                <span class="text-xs text-gray-400">JPG, PNG, WEBP · máx 5 MB</span>
              </button>
            </div>

            <!-- Descripción -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
              <textarea v-model="formProducto.descripcion" rows="2" placeholder="Descripción del producto..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none" />
            </div>

            <!-- Personalizable + Tapizado -->
            <div class="space-y-2">
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" v-model="formProducto.personalizable" class="rounded w-4 h-4 text-blue-600" />
                <span class="text-sm text-gray-700">Producto personalizable (permite specs al vender)</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" v-model="formProducto.es_tapizado" class="rounded w-4 h-4 text-amber-600" />
                <span class="text-sm text-gray-700">Lleva tapizado <span class="text-gray-400">(activa selección de tela/color en inventario y surtir)</span></span>
              </label>
            </div>

            <div class="border-t border-gray-100" />

            <!-- ── Tiendas ── -->

            <!-- Supervisor: selector de tiendas -->
            <div v-if="auth.isSupervisor">
              <label class="block text-sm font-medium text-gray-700 mb-2">Disponible en tiendas <span class="text-red-500">*</span></label>

              <!-- Todas -->
              <label class="flex items-center gap-2 cursor-pointer mb-2 select-none">
                <input
                  type="checkbox"
                  :checked="todasTiendasSeleccionadas"
                  :indeterminate="tiendasFormSeleccionadas.length > 0 && !todasTiendasSeleccionadas"
                  @change="toggleTodasTiendas"
                  class="rounded w-4 h-4 text-blue-600"
                />
                <span class="text-sm font-semibold text-gray-800">Todas las tiendas</span>
              </label>

              <!-- Por tienda -->
              <div class="space-y-1.5 pl-6">
                <label
                  v-for="t in tiendas"
                  :key="t.id"
                  class="flex items-center gap-2 cursor-pointer select-none"
                >
                  <input
                    type="checkbox"
                    :value="t.id"
                    v-model="tiendasFormSeleccionadas"
                    class="rounded w-4 h-4 text-blue-600"
                  />
                  <span class="text-sm text-gray-700">{{ t.nombre }}<span v-if="t.ciudad" class="text-gray-400"> · {{ t.ciudad }}</span></span>
                </label>
              </div>
            </div>

            <!-- Vendedor: solo su tienda -->
            <div v-else class="bg-blue-50 rounded-lg px-3 py-2.5 flex items-center gap-2">
              <ArchiveBoxIcon class="w-4 h-4 text-blue-500 flex-shrink-0" />
              <div>
                <p class="text-xs text-blue-500 font-medium">Se creará en tu tienda</p>
                <p class="text-sm font-semibold text-blue-700">
                  {{ tiendas.find(t => t.id == auth.usuario?.tienda_default_id)?.nombre ?? 'Tu tienda' }}
                </p>
              </div>
            </div>

            <p v-if="errCrearProducto" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ errCrearProducto }}</p>
          </div>

          <!-- Pie fijo -->
          <div class="px-5 pb-5 pt-3 border-t border-gray-100 flex-shrink-0">
            <button
              @click="crearProducto"
              :disabled="creandoProducto"
              class="w-full bg-blue-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >
              {{ subiendoFoto ? 'Subiendo foto...' : creandoProducto ? 'Creando...' : 'Crear producto' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Lightbox foto -->
    <Transition name="fade">
      <div
        v-if="fotoModal"
        class="fixed inset-0 z-[60] flex items-center justify-center p-6"
        @click.self="fotoModal = false"
      >
        <div class="absolute inset-0 bg-black/85" @click="fotoModal = false" />
        <div class="relative w-full max-w-sm">
          <button
            @click="fotoModal = false"
            class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg"
          >
            <XMarkIcon class="w-5 h-5 text-gray-700" />
          </button>
          <div class="bg-white rounded-2xl overflow-hidden shadow-2xl">
            <img
              :src="cloudinaryOpt(fotoProducto?.foto_url, 800)"
              :alt="fotoProducto?.nombre"
              class="w-full object-contain max-h-72"
            />
            <div class="px-4 py-3 border-t border-gray-100">
              <p class="text-sm font-semibold text-gray-800 text-center">{{ fotoProducto?.nombre }}</p>
              <p v-if="fotoProducto?.categoria" class="text-xs text-gray-400 text-center mt-0.5">{{ fotoProducto?.categoria }}</p>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal Gestionar -->
    <Transition name="fade">
      <div v-if="mostrarGestionar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarGestionar = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md flex flex-col max-h-[90vh]">

          <!-- Cabecera fija -->
          <div class="flex items-center gap-3 px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <div
              v-if="itemGestionar?.producto?.foto_url"
              class="w-10 h-10 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0 cursor-pointer hover:opacity-80 transition-opacity"
              @click="verFoto(itemGestionar.producto)"
              title="Ver foto completa"
            >
              <img :src="cloudinaryOpt(itemGestionar.producto.foto_url, 80)" :alt="itemGestionar.producto.nombre" class="w-full h-full object-cover" />
            </div>
            <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0" v-else>
              <PhotoIcon class="w-5 h-5 text-gray-300" />
            </div>
            <div class="flex-1 min-w-0">
              <h3 class="text-base font-bold text-gray-800 leading-tight">Gestionar producto</h3>
              <p class="text-xs text-gray-500 truncate mt-0.5">{{ itemGestionar?.producto?.nombre }}</p>
            </div>
            <button @click="mostrarGestionar = false" class="text-gray-400 text-2xl leading-none flex-shrink-0 ml-1">&times;</button>
          </div>

          <!-- Cuerpo scrollable -->
          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">

            <!-- Cambiar precio -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Precio base</label>
              <input
                v-model.number="nuevoPrecio"
                type="number"
                min="0"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <p v-if="gestionError" class="text-xs text-red-600 mt-1">{{ gestionError }}</p>
              <button
                @click="guardarPrecio"
                :disabled="gestionLoading"
                class="mt-2 w-full bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
              >
                {{ gestionLoading ? 'Guardando...' : 'Actualizar precio' }}
              </button>
            </div>

            <div class="border-t border-gray-100" />

            <!-- Nombre y descripción -->
            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del producto</label>
                <input
                  v-model="gestionNombre"
                  type="text"
                  maxlength="150"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Nombre del producto"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                <textarea
                  v-model="gestionDescripcion"
                  rows="3"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                  placeholder="Descripción del producto (opcional)"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Medidas</label>
                <input
                  v-model="gestionMedidas"
                  type="text"
                  maxlength="200"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Ej: 200x90x80 cm (opcional)"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Material</label>
                <input
                  v-model="gestionMaterial"
                  type="text"
                  maxlength="200"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Ej: Cuero, madera, tela... (opcional)"
                />
              </div>
              <p v-if="gestionInfoError" class="text-xs text-red-600">{{ gestionInfoError }}</p>
              <button
                @click="guardarNombreDescripcion"
                :disabled="gestionInfoLoading"
                class="w-full bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
              >
                {{ gestionInfoLoading ? 'Guardando...' : 'Guardar información' }}
              </button>
            </div>

            <div class="border-t border-gray-100" />

            <!-- Cambiar foto -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Foto del producto</label>
              <input ref="gestionFotoInput" type="file" accept="image/*" class="hidden" @change="onGestionFotoChange" />

              <div v-if="gestionFotoPreviewUrl" class="space-y-2">
                <div class="relative rounded-xl overflow-hidden border-2 border-blue-300 bg-gray-50">
                  <img :src="gestionFotoPreviewUrl" alt="Nueva foto" class="w-full object-contain max-h-40" />
                  <button type="button" @click="quitarGestionFoto" class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow-lg">
                    <XMarkIcon class="w-4 h-4" />
                  </button>
                </div>
                <p v-if="gestionFotoError" class="text-xs text-red-600">{{ gestionFotoError }}</p>
                <button
                  @click="guardarFotoProducto"
                  :disabled="gestionFotoLoading"
                  class="w-full bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
                >
                  {{ gestionFotoLoading ? 'Subiendo a Cloudinary...' : 'Guardar nueva foto' }}
                </button>
              </div>

              <button
                v-else
                type="button"
                @click="gestionFotoInput.click()"
                class="w-full flex items-center gap-3 border-2 border-dashed border-gray-300 rounded-xl px-4 py-3 hover:border-blue-400 hover:bg-blue-50 transition-colors cursor-pointer"
              >
                <div class="w-9 h-9 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0 flex items-center justify-center">
                  <img v-if="itemGestionar?.producto?.foto_url" :src="itemGestionar.producto.foto_url" class="w-full h-full object-cover" />
                  <PhotoIcon v-else class="w-5 h-5 text-gray-300" />
                </div>
                <div class="text-left">
                  <p class="text-sm font-medium text-gray-700">{{ itemGestionar?.producto?.foto_url ? 'Cambiar foto' : 'Agregar foto' }}</p>
                  <p class="text-xs text-gray-400">JPG, PNG, WEBP · se guarda en Cloudinary</p>
                </div>
              </button>
            </div>

            <!-- Segunda foto (para IA) -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Foto adicional <span class="text-xs font-normal text-gray-400">(para la IA de WhatsApp/Instagram)</span></label>
              <input ref="gestionFoto2Input" type="file" accept="image/*" class="hidden" @change="onGestionFoto2Change" />

              <div v-if="gestionFoto2PreviewUrl" class="space-y-2">
                <div class="relative rounded-xl overflow-hidden border-2 border-blue-300 bg-gray-50">
                  <img :src="gestionFoto2PreviewUrl" alt="Segunda foto" class="w-full object-contain max-h-40" />
                  <button type="button" @click="quitarGestionFoto2" class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow-lg">
                    <XMarkIcon class="w-4 h-4" />
                  </button>
                </div>
                <p v-if="gestionFoto2Error" class="text-xs text-red-600">{{ gestionFoto2Error }}</p>
                <button
                  @click="guardarFoto2Producto"
                  :disabled="gestionFoto2Loading"
                  class="w-full bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
                >
                  {{ gestionFoto2Loading ? 'Subiendo...' : 'Guardar segunda foto' }}
                </button>
              </div>

              <button
                v-else
                type="button"
                @click="gestionFoto2Input.click()"
                class="w-full flex items-center gap-3 border-2 border-dashed border-gray-300 rounded-xl px-4 py-3 hover:border-blue-400 hover:bg-blue-50 transition-colors cursor-pointer"
              >
                <div class="w-9 h-9 rounded-lg overflow-hidden bg-gray-100 flex-shrink-0 flex items-center justify-center">
                  <img v-if="itemGestionar?.producto?.foto_url_2" :src="itemGestionar.producto.foto_url_2" class="w-full h-full object-cover" />
                  <PhotoIcon v-else class="w-5 h-5 text-gray-300" />
                </div>
                <div class="text-left">
                  <p class="text-sm font-medium text-gray-700">{{ itemGestionar?.producto?.foto_url_2 ? 'Cambiar foto adicional' : 'Agregar foto adicional' }}</p>
                  <p class="text-xs text-gray-400">JPG, PNG, WEBP · ángulo diferente del producto</p>
                </div>
              </button>
            </div>

            <div class="border-t border-gray-100" />

            <!-- Toggle tapizado -->
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-medium text-gray-700">Producto tapizado</p>
                <p class="text-xs text-gray-400">Activa para sofás, sillas y sofacamas</p>
              </div>
              <button
                @click="toggleEsTapizado"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  itemGestionar?.producto?.es_tapizado ? 'bg-blue-600' : 'bg-gray-200'
                ]"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                    itemGestionar?.producto?.es_tapizado ? 'translate-x-6' : 'translate-x-1'
                  ]"
                />
              </button>
            </div>

            <!-- Toggle tiene tallas -->
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-medium text-gray-700">Variantes por medida</p>
                <p class="text-xs text-gray-400">Activa para camas y colchones con precio por talla</p>
              </div>
              <button
                @click="toggleTieneTallas"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  itemGestionar?.producto?.tiene_tallas ? 'bg-blue-600' : 'bg-gray-200'
                ]"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                    itemGestionar?.producto?.tiene_tallas ? 'translate-x-6' : 'translate-x-1'
                  ]"
                />
              </button>
            </div>

            <div class="border-t border-gray-100" />

            <!-- Agregar stock -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Agregar stock</label>
              <div class="flex gap-2">
                <input
                  v-model.number="nuevoStock"
                  type="number"
                  min="1"
                  class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Cantidad"
                />
                <button
                  @click="guardarStock"
                  :disabled="stockLoading"
                  class="bg-green-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-green-700 disabled:opacity-50 flex items-center gap-1"
                >
                  <PlusIcon class="w-4 h-4" />
                  Agregar
                </button>
              </div>
              <p v-if="esVistaGlobal" class="text-xs text-blue-600 mt-1">Se agregará a todas las tiendas donde existe este producto</p>
              <input
                v-model="stockMotivo"
                class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Motivo (opcional)"
              />
              <p v-if="stockError" class="text-xs text-red-600 mt-1">{{ stockError }}</p>
            </div>

            <!-- Quitar stock — solo tienda individual -->
            <template v-if="!esVistaGlobal">
              <div class="border-t border-gray-100" />
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quitar stock</label>
                <div class="flex gap-2">
                  <input
                    v-model.number="quitarStockCant"
                    type="number"
                    min="1"
                    class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                    placeholder="Cantidad a quitar"
                  />
                  <button
                    @click="quitarStock"
                    :disabled="quitarStockLoad"
                    class="bg-red-500 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-red-600 disabled:opacity-50 flex items-center gap-1"
                  >
                    <XMarkIcon class="w-4 h-4" />
                    Quitar
                  </button>
                </div>
                <input
                  v-model="quitarStockMotivo"
                  class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                  placeholder="Motivo (ej: Error de conteo, daño...)"
                />
                <p v-if="quitarStockError" class="text-xs text-red-600 mt-1">{{ quitarStockError }}</p>
              </div>
            </template>

          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Historial de movimientos -->
    <Transition name="fade">
      <div v-if="mostrarHistorial" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarHistorial = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[80vh] flex flex-col">
          <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <div>
              <h3 class="text-lg font-bold text-gray-800">Historial de movimientos</h3>
              <p class="text-xs text-gray-500 mt-0.5">{{ itemHistorial?.producto_nombre }}</p>
            </div>
            <button @click="mostrarHistorial = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-2">
            <div v-if="movimientosLoading" class="text-sm text-gray-400 text-center py-8">Cargando...</div>
            <div v-else-if="movimientos.length === 0" class="text-sm text-gray-400 text-center py-8">Sin movimientos registrados</div>
            <div v-else v-for="m in movimientos" :key="m.id" class="flex items-start gap-3 py-2 border-b border-gray-50 last:border-0">
              <span
                class="mt-0.5 text-xs font-bold px-2 py-0.5 rounded-full shrink-0"
                :class="m.tipo === 'entrada' ? 'bg-green-100 text-green-700' : m.tipo === 'reserva' ? 'bg-amber-100 text-amber-700' : m.tipo === 'salida' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'"
              >
                {{ m.tipo === 'entrada' ? 'Entrada' : m.tipo === 'salida' ? 'Salida' : m.tipo === 'reserva' ? 'Reserva' : 'Liberación' }}
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800">{{ m.cantidad }} unidad(es)</p>
                <p v-if="m.variante" class="text-xs text-gray-600 truncate">
                  {{ [m.variante.marca, m.variante.marca_tela, m.variante.nombre_color].filter(Boolean).join(' · ') }}
                </p>
                <p class="text-xs text-gray-500 truncate">{{ m.motivo ?? '—' }}</p>
                <p class="text-xs text-gray-400">{{ new Date(m.created_at).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Agregar stock a variante -->
    <Transition name="fade">
      <div v-if="mostrarStockVariante" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarStockVariante = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-800">Agregar stock · variante</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                {{ [varianteStockItem?.variante?.marca, varianteStockItem?.variante?.marca_tela, varianteStockItem?.variante?.nombre_color].filter(Boolean).join(' · ') }}
              </p>
            </div>
            <button @click="mostrarStockVariante = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div class="space-y-3">
            <!-- Info de disponibilidad -->
            <div class="bg-gray-50 rounded-lg px-3 py-2 text-xs space-y-0.5">
              <div class="flex justify-between text-gray-600">
                <span>Stock base del producto</span>
                <span class="font-semibold">{{ varianteStockItem?.item?.cantidad_disponible ?? 0 }}</span>
              </div>
              <div class="flex justify-between text-gray-600">
                <span>Sin asignar a variantes</span>
                <span class="font-semibold" :class="varianteStockSinAsignar > 0 ? 'text-green-700' : 'text-red-600'">
                  {{ varianteStockSinAsignar }}
                </span>
              </div>
            </div>
            <p v-if="varianteStockSinAsignar === 0" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">
              No hay unidades sin asignar. Agrega más stock base primero en "Gestionar".
            </p>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Cantidad <span class="text-gray-400 font-normal">(máx {{ varianteStockSinAsignar }})</span>
              </label>
              <input v-model.number="varianteStockCantidad" type="number" min="1" :max="varianteStockSinAsignar"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
              <input v-model="varianteStockMotivo"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Entrada de bodega..." />
            </div>
            <p v-if="varianteStockError" class="text-xs text-red-600">{{ varianteStockError }}</p>
            <button @click="guardarStockVariante" :disabled="varianteStockLoading || varianteStockSinAsignar === 0"
              class="w-full bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-50">
              {{ varianteStockLoading ? 'Guardando...' : 'Agregar stock' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Nueva variante (supervisor) -->
    <Transition name="fade">
      <div v-if="mostrarNuevaVariante" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarNuevaVariante = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">{{ varianteTipoTalla ? 'Nueva talla' : 'Nueva variante de tela' }}</h3>
            <button @click="mostrarNuevaVariante = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <div class="space-y-3">
            <!-- Formulario: producto con tallas (ej: colchones) -->
            <template v-if="varianteTipoTalla">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Medida <span class="text-red-500">*</span></label>
                <input
                  v-model="formVarianteTalla.medida"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Ej: 1.60x1.90, Queen, King..."
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Precio (opcional)</label>
                <input
                  v-model="formVarianteTalla.precio_variante"
                  type="number"
                  min="0"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Precio en pesos..."
                />
              </div>
            </template>

            <!-- Formulario: producto tapizado (tela/color) -->
            <template v-else>
              <!-- 1. Marca fabricante -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Marca fabricante <span class="text-red-500">*</span></label>
                <select
                  v-model="formVariante.marca"
                  @change="formVariante.marca_tela = ''; formVariante.nombre_color = ''"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Seleccionar...</option>
                  <option v-for="m in marcasOrdenadas" :key="m" :value="m">{{ m }}</option>
                  <option value="Otro">Otro (ingresar manualmente)</option>
                </select>
                <input
                  v-if="formVariante.marca === 'Otro'"
                  v-model="formVariante.marcaManual"
                  class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Nombre de la marca..."
                />
              </div>

              <!-- 2. Tipo de tela -->
              <div v-if="formVariante.marca">
                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de tela <span class="text-red-500">*</span></label>
                <select
                  v-if="tiposTelaOpciones.length"
                  v-model="formVariante.marca_tela"
                  @change="formVariante.nombre_color = ''"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Seleccionar...</option>
                  <option v-for="t in tiposTelaOpciones" :key="t" :value="t">{{ t }}</option>
                  <option value="Otro">Otro (ingresar manualmente)</option>
                </select>
                <input
                  v-else
                  v-model="formVariante.telaManual"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Nombre de la tela..."
                />
                <input
                  v-if="formVariante.marca_tela === 'Otro'"
                  v-model="formVariante.telaManual"
                  class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Nombre de la tela..."
                />
              </div>

              <!-- 3. Color -->
              <div v-if="formVariante.marca && (formVariante.marca_tela || formVariante.marca === 'Otro')">
                <label class="block text-sm font-medium text-gray-700 mb-1">Color <span class="text-red-500">*</span></label>
                <select
                  v-if="coloresOpciones.length"
                  v-model="formVariante.nombre_color"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Seleccionar...</option>
                  <option v-for="c in coloresOpciones" :key="c" :value="c">{{ c }}</option>
                  <option value="Otro">Otro (ingresar manualmente)</option>
                </select>
                <input
                  v-else
                  v-model="formVariante.colorManual"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Nombre del color..."
                />
                <input
                  v-if="formVariante.nombre_color === 'Otro'"
                  v-model="formVariante.colorManual"
                  class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  placeholder="Nombre del color..."
                />
              </div>

              <!-- Preview -->
              <div v-if="marcaFinal && telaFinal && colorFinal" class="bg-blue-50 rounded-lg px-3 py-2 text-xs text-blue-700 font-medium">
                Variante: {{ marcaFinal }} · {{ telaFinal }} · {{ colorFinal }}
              </div>
            </template>

            <p v-if="varianteCreandoError" class="text-xs text-red-600">{{ varianteCreandoError }}</p>
            <button @click="guardarNuevaVariante" :disabled="varianteCreandoLoad"
              class="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
              {{ varianteCreandoLoad ? 'Guardando...' : 'Crear variante' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.slide-enter-active, .slide-leave-active { transition: all 0.18s ease; }
.slide-enter-from, .slide-leave-to       { opacity: 0; transform: translateY(-6px); }
</style>
