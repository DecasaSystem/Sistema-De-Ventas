<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import { getVariantes } from '@/api/inventario'
import { getReservaInfo, getReservaStockLote } from '@/api/reserva'
import { updateCliente, CATEGORIAS_DISPONIBLES } from '@/api/clientes'
import { SPECS_TEMPLATES, resolverCategoria, camposParaModo, specsToDescripcion, extraerDimensiones } from '@/constants/specsConfig'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { ArrowPathIcon, SparklesIcon, XMarkIcon } from '@heroicons/vue/24/solid'
import { ArrowPathIcon as ArrowPathOutlineIcon, PhotoIcon, UserGroupIcon, ArrowPathIcon as ConvertIcon, ExclamationTriangleIcon, PencilIcon, MapPinIcon, SwatchIcon, CurrencyDollarIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { getReceptores, crearConsulta } from '@/api/consultas'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import BocetoCanvas from '@/components/BocetoCanvas.vue'
import DireccionColombia from '@/components/DireccionColombia.vue'

const router = useRouter()
const auth   = useAuthStore()
const toast  = useToast()

// ── Pasos ─────────────────────────────────────────────────────────────────────
const step = ref(1)

// ── Tiendas ───────────────────────────────────────────────────────────────────
const tiendas = ref([])

// Telas con metros disponibles (para filtrar el picker en fabricar bajo pedido)
const telaMetrosMap = ref({}) // clave "marca|tipo|color" → metros_libres
onMounted(async () => {
  const [{ data: tiendasData }, { data: telasData }] = await Promise.all([
    api.get('/tiendas'),
    api.get('/inventario-telas').catch(() => ({ data: [] })),
  ])
  tiendas.value = tiendasData
  const map = {}
  for (const t of telasData) {
    map[`${t.marca}|${t.tipo}|${t.color}`] = t.metros_libres
  }
  telaMetrosMap.value = map
})

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

// ── Paso 1: Cliente ───────────────────────────────────────────────────────────
const clienteQuery     = ref('')
const clienteResultados = ref([])
const clienteSeleccionado = ref(null)
const buscandoCliente   = ref(false)
const modoNuevoCliente  = ref(false)
const nuevoCliente = ref({ nombre: '', cedula: '', telefono: '', email: '', direccion: '', tipo: 'oficial', categorias_interes: [], notas_interes: '' })
const creandoCliente = ref(false)
const errCliente = ref('')

// Completar datos de interesado antes de continuar
const formCompletarCliente = ref({ nombre: '', cedula: '', telefono: '', email: '', direccion: '' })
const guardandoCompletarCliente = ref(false)
const errCompletarCliente       = ref('')

// Un cliente requiere completar si es interesado O le falta algún campo obligatorio
const clienteRequiereCompletar = computed(() => {
  const c = clienteSeleccionado.value
  if (!c) return false
  return c.tipo === 'interesado' || !c.cedula || !c.telefono || !c.direccion
})

watch(clienteSeleccionado, (c) => {
  if (c) {
    formCompletarCliente.value = {
      nombre:    c.nombre    || '',
      cedula:    c.cedula    || '',
      telefono:  c.telefono  || '',
      email:     c.email     || '',
      direccion: c.direccion || '',
    }
    errCompletarCliente.value = ''
  }
})

async function completarYConvertirCliente() {
  errCompletarCliente.value = ''
  const f = formCompletarCliente.value
  if (!f.nombre.trim())    { errCompletarCliente.value = 'El nombre es obligatorio.';    return }
  if (!f.cedula.trim())    { errCompletarCliente.value = 'La cédula es obligatoria.';    return }
  if (!f.telefono.trim())  { errCompletarCliente.value = 'El teléfono es obligatorio.';  return }
  if (!f.direccion.trim()) { errCompletarCliente.value = 'La dirección es obligatoria.'; return }

  guardandoCompletarCliente.value = true
  try {
    const payload = {
      tipo:      'oficial',
      nombre:    f.nombre.trim(),
      cedula:    f.cedula.trim(),
      telefono:  f.telefono.trim(),
      email:     f.email.trim() || null,
      direccion: f.direccion.trim(),
    }
    await updateCliente(clienteSeleccionado.value.id, payload)
    clienteSeleccionado.value = { ...clienteSeleccionado.value, ...payload }
  } catch (e) {
    errCompletarCliente.value = e.response?.data?.message ?? 'Error al actualizar el cliente'
  } finally {
    guardandoCompletarCliente.value = false
  }
}

let _clienteDebounce = null
async function buscarCliente() {
  if (!clienteQuery.value.trim()) { clienteResultados.value = []; return }
  buscandoCliente.value = true
  try {
    const { data } = await api.get('/clientes', { params: { search: clienteQuery.value } })
    clienteResultados.value = data.data ?? []
  } finally {
    buscandoCliente.value = false
  }
}

function onClienteInput() {
  clienteResultados.value = []
  clearTimeout(_clienteDebounce)
  if (clienteQuery.value.trim().length < 2) return
  _clienteDebounce = setTimeout(buscarCliente, 300)
}

function seleccionarCliente(c) {
  clienteSeleccionado.value = c
  clienteResultados.value = []
  clienteQuery.value = c.nombre
}

function nuevoClienteValido() {
  const c = nuevoCliente.value
  if (c.tipo === 'interesado') return true  // todo opcional para interesado
  // Oficial: todos los campos requeridos
  return c.nombre.trim() && c.cedula.trim() && c.telefono.trim() && c.direccion.trim()
}

async function crearCliente() {
  errCliente.value = ''
  const c = nuevoCliente.value
  if (c.tipo === 'oficial') {
    if (!c.nombre.trim())    { errCliente.value = 'El nombre es obligatorio.';    return }
    if (!c.cedula.trim())    { errCliente.value = 'La cédula es obligatoria.';    return }
    if (!c.telefono.trim())  { errCliente.value = 'El teléfono es obligatorio.';  return }
    if (!c.direccion.trim()) { errCliente.value = 'La dirección es obligatoria.'; return }
  }
  creandoCliente.value = true
  try {
    const { data } = await api.post('/clientes', nuevoCliente.value)
    seleccionarCliente(data)
    modoNuevoCliente.value = false
    nuevoCliente.value = { nombre: '', cedula: '', telefono: '', email: '', direccion: '', tipo: 'oficial', categorias_interes: [], notas_interes: '' }
  } catch (e) {
    errCliente.value = e.response?.data?.message ?? 'Error al crear cliente'
  } finally {
    creandoCliente.value = false
  }
}


// ── Paso 1: Tienda + Canal ────────────────────────────────────────────────────
const tiendaId = ref(auth.usuario?.tienda_default_id ?? '')
const canal = ref('fisica')

const canalesopts = [
  { value: 'fisica',     label: 'Física' },
  { value: 'whatsapp',   label: 'WhatsApp' },
  { value: 'instagram',  label: 'Instagram' },
  { value: 'facebook',   label: 'Facebook' },
  { value: 'pagina',     label: 'Página web' },
  { value: 'otro',       label: 'Otro' },
]

const tiendaVirtualId = computed(() => tiendas.value.find(t => t.nombre === 'Tienda Virtual')?.id ?? null)

// Al cambiar canal: si es virtual → asignar Tienda Virtual automáticamente
watch(canal, (nuevoCanal) => {
  const virtualId = tiendaVirtualId.value
  if (nuevoCanal !== 'fisica' && virtualId) {
    tiendaId.value = virtualId
  } else if (nuevoCanal === 'fisica') {
    tiendaId.value = auth.usuario?.tienda_default_id ?? (tiendas.value.find(t => !t.es_fabrica && t.nombre !== 'Tienda Virtual')?.id ?? '')
  }
})

// Si las tiendas cargan después de que el usuario ya cambió el canal
watch(tiendaVirtualId, (id) => {
  if (id && canal.value !== 'fisica') tiendaId.value = id
})

function paso1Valido() {
  return clienteSeleccionado.value && tiendaId.value && canal.value && !clienteRequiereCompletar.value
}

// ── Tipo de orden ─────────────────────────────────────────────────────────────
const tipoOrden = ref('venta')

function cambiarTipo(tipo) {
  tipoOrden.value = tipo
  items.value = []
}

// ── Paso 2: Productos / Carrito ───────────────────────────────────────────────
const productoQuery = ref('')
const productoResultados = ref([])
const buscandoProducto = ref(false)
const items = ref([])
const tiendaBusqueda = ref(auth.usuario?.tienda_default_id ?? '')

// Formulario para restauraciones
const restauracionItem = ref({ nombre_mueble: '', descripcion_trabajo: '', cantidad: 1, precio_unitario: 0, foto_blob: null, foto_preview: null, _retapizar: false, _telaSelections: {} })
const restauracionCalc = ref({ calculando: false, resultado: null, mostrar: false })

function onFotoRestauracionForm(event) {
  const file = event.target.files[0]
  if (!file) return
  if (restauracionItem.value.foto_preview) URL.revokeObjectURL(restauracionItem.value.foto_preview)
  restauracionItem.value.foto_blob    = file
  restauracionItem.value.foto_preview = URL.createObjectURL(file)
}

function limpiarTelaRestauracion() {
  restauracionItem.value._telaSelections = {}
}

function quitarFotoRestauracionForm() {
  if (restauracionItem.value.foto_preview) URL.revokeObjectURL(restauracionItem.value.foto_preview)
  restauracionItem.value.foto_blob    = null
  restauracionItem.value.foto_preview = null
}

async function calcularRestauracionForm() {
  const f = restauracionItem.value
  if (!f.nombre_mueble.trim()) return
  restauracionCalc.value.calculando = true
  restauracionCalc.value.resultado  = null
  try {
    // Subir foto si hay una seleccionada
    let boceto_url = null
    if (f.foto_blob) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(f.foto_blob), 'restauracion.jpg')
      fd.append('folder', 'bocetos')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      boceto_url = up.url
    }
    const { data } = await api.post('/calcular-precio-item', {
      es_restauracion: true,
      nombre:     f.nombre_mueble.trim(),
      trabajo:    f.descripcion_trabajo.trim() || undefined,
      cantidad:   f.cantidad,
      boceto_url: boceto_url || undefined,
    })
    restauracionCalc.value.resultado = data
  } catch {
    toast.error('No se pudo calcular el precio. Intenta de nuevo.')
  } finally {
    restauracionCalc.value.calculando = false
  }
}

function aplicarPrecioRestauracion(precio) {
  restauracionItem.value.precio_unitario = precio
  restauracionCalc.value.mostrar = false
  restauracionCalc.value.resultado = null
}

function agregarItemRestauracion() {
  const f = restauracionItem.value
  if (!f.nombre_mueble.trim()) return
  restauracionCalc.value = { calculando: false, resultado: null, mostrar: false }
  const specsBase = f.descripcion_trabajo.trim() ? { descripcion_trabajo: f.descripcion_trabajo.trim() } : {}
  if (f._retapizar) specsBase.retapizar = true
  items.value.push({
    producto_id: null,
    variante_id: null,
    tienda_origen_id: null,
    nombre: f.nombre_mueble.trim(),
    nombre_custom: f.nombre_mueble.trim(),
    categoria: 'Restauración',
    categoria_custom: 'Restauración',
    variante_label: null,
    stock_libre: null,
    personalizable: false,
    cantidad: f.cantidad,
    precio_unitario: f.precio_unitario,
    es_personalizado: true,
    specs: specsBase,
    specs_notas: '',
    tienda_origen: null,
    fecha_entrega_prometida: null,
    boceto_blobs:    f.foto_blob    ? [f.foto_blob]    : [],
    boceto_urls:     f.foto_blob    ? ['']             : [],
    boceto_previews: f.foto_preview ? [f.foto_preview] : [],
    _cotizarPrecio:      true,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     f._retapizar ? { ...f._telaSelections } : {},
  })
  restauracionItem.value = { nombre_mueble: '', descripcion_trabajo: '', cantidad: 1, precio_unitario: 0, foto_blob: null, foto_preview: null, _retapizar: false, _telaSelections: {} }
}

// Producto no catalogado
const modoProductoCustom = ref(false)
const productoCustomForm = ref({ nombre: '', categoria: '', precio_unitario: 0, cantidad: 1 })

// ── Crear producto nuevo desde la orden ───────────────────────────────────────
const busquedaHecha        = ref(false)
const mostrarCrearProducto = ref(false)
const crearProductoForm    = ref({
  nombre: '', categoria: '', precio_base: '',
  descripcion: '', medidas: '', material: '',
  personalizable: false, es_tapizado: false,
})
const creandoProducto    = ref(false)
const crearProductoError = ref('')
const subiendoFotoNuevo  = ref(false)
const fotoNuevoFile      = ref(null)
const fotoNuevoPreview   = ref('')
const fotoNuevoInput     = ref(null)
const categoriasNuevo    = ref([])
const categoriaSelNuevo  = ref('')

const CATS_TAPIZADO = /sofa|sofá|silla|modular/i

function esTapizadoPorCat(texto) {
  return CATS_TAPIZADO.test(texto ?? '')
}

function onNombreNuevoInput() {
  if (esTapizadoPorCat(crearProductoForm.value.nombre)) crearProductoForm.value.es_tapizado = true
}

function onCategoriaNuevoSelect(val) {
  categoriaSelNuevo.value = val
  if (val !== '__nueva__') {
    crearProductoForm.value.categoria = val
    if (esTapizadoPorCat(val)) crearProductoForm.value.es_tapizado = true
  } else {
    crearProductoForm.value.categoria = ''
  }
}

function onFotoNuevoChange(e) {
  const file = e.target.files[0]
  if (!file) return
  if (fotoNuevoPreview.value) URL.revokeObjectURL(fotoNuevoPreview.value)
  fotoNuevoFile.value = file
  fotoNuevoPreview.value = URL.createObjectURL(file)
}

function quitarFotoNuevo() {
  if (fotoNuevoPreview.value) URL.revokeObjectURL(fotoNuevoPreview.value)
  fotoNuevoFile.value = null
  fotoNuevoPreview.value = ''
  if (fotoNuevoInput.value) fotoNuevoInput.value.value = ''
}

async function abrirCrearProducto() {
  crearProductoForm.value = {
    nombre: productoQuery.value.trim(), categoria: '', precio_base: '',
    descripcion: '', medidas: '', material: '',
    personalizable: false, es_tapizado: false,
  }
  categoriaSelNuevo.value = ''
  crearProductoError.value = ''
  quitarFotoNuevo()
  mostrarCrearProducto.value = true
  try {
    const { data } = await api.get('/productos/categorias')
    categoriasNuevo.value = data
  } catch {}
}

async function crearYAgregarProducto() {
  const f = crearProductoForm.value
  if (!f.nombre.trim() || !f.precio_base) {
    crearProductoError.value = 'Nombre y precio base son requeridos.'
    return
  }
  creandoProducto.value = true
  crearProductoError.value = ''
  try {
    let foto_url = undefined
    if (fotoNuevoFile.value) {
      subiendoFotoNuevo.value = true
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(fotoNuevoFile.value), 'producto.jpg')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      foto_url = up.url
      subiendoFotoNuevo.value = false
    }
    const { data: prod } = await api.post('/productos', {
      nombre:         f.nombre.trim(),
      categoria:      f.categoria.trim() || null,
      precio_base:    Number(f.precio_base),
      descripcion:    f.descripcion.trim() || null,
      medidas:        f.medidas.trim() || null,
      material:       f.material.trim() || null,
      personalizable: f.personalizable,
      es_tapizado:    f.es_tapizado,
      tiendas:        tiendas.value.map(t => t.id),
      ...(foto_url ? { foto_url } : {}),
    })
    fabricarBajoPedido(prod)
    mostrarCrearProducto.value = false
    busquedaHecha.value = false
    quitarFotoNuevo()
    toast.success(`"${prod.nombre}" creado y registrado en inventario.`)
  } catch (e) {
    subiendoFotoNuevo.value = false
    crearProductoError.value = e.response?.data?.message ?? 'Error al crear el producto.'
  } finally {
    creandoProducto.value = false
  }
}

function agregarProductoCustom() {
  const f = productoCustomForm.value
  if (!f.nombre.trim() || f.cantidad < 1) return
  items.value.push({
    producto_id: null,
    variante_id: null,
    tienda_origen_id: null,
    nombre: f.nombre.trim(),
    nombre_custom: f.nombre.trim(),
    categoria: f.categoria.trim() || null,
    categoria_custom: f.categoria.trim() || null,
    variante_label: null,
    stock_libre: null,
    personalizable: false,
    cantidad: f.cantidad,
    precio_unitario: f.precio_unitario,
    es_personalizado: true,
    specs: {},
    specs_notas: '',
    tienda_origen: null,
    fecha_entrega_prometida: null,
    boceto_blobs: [],
    boceto_urls: [],
    boceto_previews: [],
    _cotizarPrecio:      true,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  })
  productoCustomForm.value = { nombre: '', categoria: '', precio_unitario: 0, cantidad: 1 }
  modoProductoCustom.value = false
}

// ── Fábrica / Reserva ────────────────────────────────────────────────────────
const fabricaId    = ref(null)
const fabricaStock = ref({})   // { producto_id: stock_libre }

onMounted(async () => {
  try {
    const { data } = await getReservaInfo()
    fabricaId.value = data.id
  } catch {}
})

async function buscarProducto() {
  if (!productoQuery.value.trim()) return
  buscandoProducto.value = true
  busquedaHecha.value = false
  mostrarCrearProducto.value = false
  try {
    const { data } = await api.get('/productos', {
      params: { search: productoQuery.value, tienda_id: tiendaBusqueda.value || tiendaId.value },
    })
    productoResultados.value = data
    busquedaHecha.value = true
    // Cargar badge de fábrica solo cuando NO se está buscando ya en fábrica
    if (fabricaId.value && data.length && tiendaBusqueda.value != fabricaId.value) {
      const ids = data.map(p => p.id)
      const { data: stocks } = await getReservaStockLote(ids)
      fabricaStock.value = stocks
    } else {
      fabricaStock.value = {}
    }
  } finally {
    buscandoProducto.value = false
  }
}

function stockLibre(p) {
  return (p.stock_disponible ?? 0) - (p.stock_reservado ?? 0)
}

function nombreTiendaBusqueda() {
  return tiendas.value.find(t => t.id == tiendaBusqueda.value)?.nombre ?? ''
}

// ── Picker variante para fábrica (tapizado) ───────────────────────────────────
const mostrarFabricaVariantePicker = ref(false)
const fabricaVariantesProd         = ref(null)
const fabricaVariantesDisponibles  = ref([])
const fabricaVarianteSeleccionada  = ref(null)
const cargandoFabricaVariantes     = ref(false)

// ── Picker variantes personalizadas (custom) ──────────────────────────────────
const mostrarVCPicker   = ref(false)
const vcPickerProd      = ref(null)
const vcPickerGrupos    = ref([])
const vcPickerCargando  = ref(false)
const vcPickerSelec     = ref({})     // { tipo_variante_id: { config_id, opcion_nombre, tipo_nombre, precio_adicional, stock } }
const vcPickerEsFabrica = ref(false)

const vcPickerValido = computed(() =>
  vcPickerGrupos.value.length > 0 &&
  vcPickerGrupos.value.every(g => vcPickerSelec.value[g.tipo_variante_id])
)

function confirmarVCPickerOrden() {
  const prod = vcPickerProd.value
  const selecciones = Object.values(vcPickerSelec.value)
  if (selecciones.length === 0) return
  const label = selecciones.map(s => `${s.tipo_nombre}: ${s.opcion_nombre}`).join(' / ')
  const precioAdicional = selecciones.reduce((sum, s) => sum + Number(s.precio_adicional ?? 0), 0)
  if (vcPickerEsFabrica.value) {
    _pushItemFabricaVC(prod, label, precioAdicional)
  } else {
    _pushItemVC(prod, label, precioAdicional)
  }
  mostrarVCPicker.value = false
}

async function tomarDeFabrica(producto) {
  if (producto.es_tapizado || producto.tiene_tallas) {
    fabricaVariantesProd.value        = producto
    fabricaVarianteSeleccionada.value = null
    cargandoFabricaVariantes.value    = true
    mostrarFabricaVariantePicker.value = true
    try {
      const { data } = await getVariantes(producto.id, fabricaId.value)
      fabricaVariantesDisponibles.value = data.filter(v => v.stock_libre > 0)
      // Sin variantes tapizado → buscar variantes personalizadas en fábrica
      if (fabricaVariantesDisponibles.value.length === 0 && fabricaId.value) {
        const { data: vcData } = await api.get(`/productos/${producto.id}/variante-configs`, { params: { tienda_id: fabricaId.value } }).catch(() => ({ data: [] }))
        const gruposConStock = vcData.filter(g => g.items.some(i => (i.stock_disponible ?? 0) > 0))
        if (gruposConStock.length > 0) {
          mostrarFabricaVariantePicker.value = false
          vcPickerProd.value      = producto
          vcPickerSelec.value     = {}
          vcPickerGrupos.value    = gruposConStock
          vcPickerEsFabrica.value = true
          mostrarVCPicker.value   = true
        }
      }
    } finally {
      cargandoFabricaVariantes.value = false
    }
    return
  }

  if (fabricaId.value) {
    vcPickerProd.value    = producto
    vcPickerSelec.value   = {}
    vcPickerGrupos.value  = []
    vcPickerEsFabrica.value = true
    vcPickerCargando.value  = true
    mostrarVCPicker.value   = true
    try {
      const { data } = await api.get(`/productos/${producto.id}/variante-configs`, { params: { tienda_id: fabricaId.value } })
      const gruposConStock = data.filter(g => g.items.some(i => (i.stock_disponible ?? 0) > 0))
      if (gruposConStock.length === 0) {
        mostrarVCPicker.value = false
      } else {
        vcPickerGrupos.value = gruposConStock
        return
      }
    } catch {
      mostrarVCPicker.value = false
    } finally {
      vcPickerCargando.value = false
    }
  }

  _pushItemFabrica(producto, null)
}

function confirmarFabricaVariante() {
  if (!fabricaVarianteSeleccionada.value) return
  _pushItemFabrica(fabricaVariantesProd.value, fabricaVarianteSeleccionada.value)
  mostrarFabricaVariantePicker.value = false
}

function _pushItemFabrica(producto, variante) {
  const varianteLabel = variante
    ? (variante.medida
        ? variante.medida
        : [variante.marca, variante.marca_tela, variante.nombre_color, variante._config_label].filter(Boolean).join(' · '))
    : null

  const comboKey = variante?._combo_id ?? null
  const existe = items.value.find(i =>
    i.producto_id === producto.id &&
    i.variante_id === (variante?.id ?? null) &&
    i._combo_id   === comboKey &&
    i.tienda_origen_id === fabricaId.value &&
    !i._fabricar_pedido
  )
  if (existe) { existe.cantidad++; return }

  items.value.push({
    producto_id: producto.id,
    variante_id: variante?.id ?? null,
    _combo_id:   variante?._combo_id ?? null,
    _config_id:  variante?._config_id ?? null,
    tienda_origen_id: fabricaId.value,
    nombre: producto.nombre,
    categoria: producto.categoria,
    variante_label: varianteLabel,
    stock_libre: variante ? (variante.stock_libre ?? 0) : (fabricaStock.value[producto.id] ?? 0),
    personalizable: producto.personalizable ?? false,
    cantidad: 1,
    precio_unitario: variante?.precio_variante != null ? Number(variante.precio_variante) : Number(producto.precio_base ?? 0),
    es_personalizado: false,
    specs: {},
    specs_notas: '',
    tienda_origen: 'Fábrica',
    fecha_entrega_prometida: null,
    boceto_blobs: [],
    boceto_urls: [],
    boceto_previews: [],
    _fabricar_pedido:    false,
    _cotizarPrecio:      false,
    _descuento_pct:      0,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  })
  productoResultados.value = []
  productoQuery.value = ''
  fabricaStock.value = {}
}

function _pushItemVC(producto, varianteLabel, precioAdicional) {
  const esOtraTienda = tiendaBusqueda.value && tiendaBusqueda.value != tiendaId.value
  const existe = items.value.find(i => i.producto_id === producto.id && i.variante_label === varianteLabel && !i._fabricar_pedido)
  if (existe) { existe.cantidad++; return }
  items.value.push({
    producto_id: producto.id, variante_id: null,
    tienda_origen_id: esOtraTienda ? (tiendaBusqueda.value ?? null) : null,
    nombre: producto.nombre, categoria: producto.categoria,
    variante_label: varianteLabel,
    stock_libre: stockLibre(producto),
    personalizable: producto.personalizable ?? false, cantidad: 1,
    precio_unitario: precioAdicional > 0 ? precioAdicional : Number(producto.precio_base ?? 0),
    es_personalizado: false, specs: {}, specs_notas: '',
    tienda_origen: esOtraTienda ? nombreTiendaBusqueda() : null,
    fecha_entrega_prometida: null, boceto_blobs: [], boceto_urls: [], boceto_previews: [],
    _fabricar_pedido: false, _cotizarPrecio: false, _descuento_pct: 0,
    _mostrarCalculadora: false, _calculandoPrecio: false, _precioCalc: null, _precioReferencia: null, _telaSelections: {},
  })
  productoResultados.value = []
  productoQuery.value = ''
}

function _pushItemFabricaVC(producto, varianteLabel, precioAdicional) {
  const existe = items.value.find(i => i.producto_id === producto.id && i.variante_label === varianteLabel && i.tienda_origen_id === fabricaId.value && !i._fabricar_pedido)
  if (existe) { existe.cantidad++; return }
  items.value.push({
    producto_id: producto.id, variante_id: null,
    tienda_origen_id: fabricaId.value,
    nombre: producto.nombre, categoria: producto.categoria,
    variante_label: varianteLabel,
    stock_libre: fabricaStock.value[producto.id] ?? 0,
    personalizable: producto.personalizable ?? false, cantidad: 1,
    precio_unitario: precioAdicional > 0 ? precioAdicional : Number(producto.precio_base ?? 0),
    es_personalizado: false, specs: {}, specs_notas: '',
    tienda_origen: 'Fábrica',
    fecha_entrega_prometida: null, boceto_blobs: [], boceto_urls: [], boceto_previews: [],
    _fabricar_pedido: false, _cotizarPrecio: false, _descuento_pct: 0,
    _mostrarCalculadora: false, _calculandoPrecio: false, _precioCalc: null, _precioReferencia: null, _telaSelections: {},
  })
  productoResultados.value = []
  productoQuery.value = ''
  fabricaStock.value = {}
}

// ── Selector de variante ──────────────────────────────────────────────────────
const mostrarVariantePicker = ref(false)
const productoParaVariante = ref(null)
const variantesDisponibles = ref([])
const cargandoVariantes = ref(false)
const varianteSeleccionada = ref(null)

async function agregarItem(producto) {
  const tiendaConsulta = tiendaBusqueda.value || tiendaId.value
  if (producto.variantes?.length > 0 || producto.tiene_tallas || producto.es_tapizado) {
    productoParaVariante.value = producto
    varianteSeleccionada.value = null
    cargandoVariantes.value = true
    mostrarVariantePicker.value = true
    try {
      const { data } = await getVariantes(producto.id, tiendaConsulta)
      variantesDisponibles.value = data
      // Si no hay variantes tapizado registradas, buscar variantes personalizadas
      if (data.length === 0 && tiendaConsulta) {
        const { data: vcData } = await api.get(`/productos/${producto.id}/variante-configs`, { params: { tienda_id: tiendaConsulta } }).catch(() => ({ data: [] }))
        const gruposConStock = vcData.filter(g => g.items.some(i => (i.stock_disponible ?? 0) > 0))
        if (gruposConStock.length > 0) {
          mostrarVariantePicker.value = false
          vcPickerProd.value      = producto
          vcPickerSelec.value     = {}
          vcPickerGrupos.value    = gruposConStock
          vcPickerEsFabrica.value = false
          mostrarVCPicker.value   = true
        }
      }
    } finally {
      cargandoVariantes.value = false
    }
    return
  }

  // Verificar variantes personalizadas
  if (tiendaConsulta) {
    vcPickerProd.value     = producto
    vcPickerSelec.value    = {}
    vcPickerGrupos.value   = []
    vcPickerEsFabrica.value = false
    vcPickerCargando.value  = true
    mostrarVCPicker.value   = true
    try {
      const { data } = await api.get(`/productos/${producto.id}/variante-configs`, { params: { tienda_id: tiendaConsulta } })
      const gruposConStock = data.filter(g => g.items.some(i => (i.stock_disponible ?? 0) > 0))
      if (gruposConStock.length === 0) {
        mostrarVCPicker.value = false
      } else {
        vcPickerGrupos.value = gruposConStock
        return
      }
    } catch {
      mostrarVCPicker.value = false
    } finally {
      vcPickerCargando.value = false
    }
  }

  _pushItem(producto, null)
}

function confirmarVariante() {
  if (productoParaVariante.value?.tiene_tallas && !varianteSeleccionada.value) return
  _pushItem(productoParaVariante.value, varianteSeleccionada.value)
  mostrarVariantePicker.value = false
}

function _pushItem(producto, variante) {
  const esOtraTienda = tiendaBusqueda.value && tiendaBusqueda.value != tiendaId.value
  const stockL = variante
    ? (variante.stock_libre ?? 0)
    : stockLibre(producto)

  const varianteLabel = variante
    ? (variante.medida
        ? variante.medida
        : [variante.marca, variante.marca_tela, variante.nombre_color, variante._config_label].filter(Boolean).join(' · '))
    : null

  const comboKey = variante?._combo_id ?? null
  const existe = items.value.find((i) =>
    i.producto_id === producto.id && i.variante_id === (variante?.id ?? null) && i._combo_id === comboKey && !i._fabricar_pedido
  )
  if (existe) { existe.cantidad++; return }

  items.value.push({
    producto_id: producto.id,
    variante_id: variante?.id ?? null,
    _combo_id:   variante?._combo_id ?? null,
    _config_id:  variante?._config_id ?? null,
    tienda_origen_id: esOtraTienda ? (tiendaBusqueda.value ?? null) : null,
    nombre: producto.nombre,
    categoria: producto.categoria,
    variante_label: varianteLabel,
    stock_libre: stockL,
    personalizable: producto.personalizable ?? false,
    cantidad: 1,
    precio_unitario: variante?.precio_variante != null ? Number(variante.precio_variante) : Number(producto.precio_base ?? 0),
    es_personalizado: false,
    specs: {},
    specs_notas: '',
    tienda_origen: esOtraTienda ? nombreTiendaBusqueda() : null,
    fecha_entrega_prometida: null,
    boceto_blobs: [],
    boceto_urls: [],
    boceto_previews: [],
    _fabricar_pedido:    false,
    _cotizarPrecio:      false,
    _descuento_pct:      0,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  })
  productoResultados.value = []
  productoQuery.value = ''
}

// Mandar a fabricar un producto del catálogo que no tiene stock
function fabricarBajoPedido(producto) {
  const existe = items.value.find(i => i.producto_id === producto.id && i._fabricar_pedido)
  if (existe) { existe.cantidad++; return }

  items.value.push({
    producto_id: producto.id,
    variante_id: null,
    tienda_origen_id: null,
    nombre: producto.nombre,
    categoria: producto.categoria,
    variante_label: null,
    stock_libre: 0,
    personalizable: false,
    cantidad: 1,
    precio_unitario: producto.precio_base ?? 0,
    es_personalizado: true,   // backend crea Produccion y omite reserva de inventario
    specs: {},
    specs_notas: '',
    tienda_origen: null,
    fecha_entrega_prometida: null,
    boceto_blobs: [],
    boceto_urls: [],
    boceto_previews: [],
    _fabricar_pedido:    true,
    _esTapizado:         producto.es_tapizado ?? false,
    _cotizarPrecio:      false,
    _mostrarCalculadora: false,
    _calculandoPrecio: false,
    _precioCalc: null,
    _precioReferencia: null,
    _telaSelections: {},
  })
  productoResultados.value = []
  productoQuery.value = ''
}

// Agregar un producto del catálogo en modo personalizado (sin stock, opción "Personalizar")
function agregarPersonalizado(producto) {
  const existe = items.value.find(i => i.producto_id === producto.id && !i._fabricar_pedido)
  if (existe) { existe.cantidad++; return }

  items.value.push({
    producto_id:         producto.id,
    variante_id:         null,
    tienda_origen_id:    null,
    nombre:              producto.nombre,
    categoria:           producto.categoria,
    variante_label:      null,
    stock_libre:         0,
    personalizable:      true,
    cantidad:            1,
    precio_unitario:     producto.precio_base ?? 0,
    es_personalizado:    true,
    specs:               {},
    specs_notas:         '',
    tienda_origen:       null,
    fecha_entrega_prometida: null,
    boceto_blobs:        [],
    boceto_urls:         [],
    boceto_previews:     [],
    _fabricar_pedido:    false,
    _cotizarPrecio:      true,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  })
  productoResultados.value = []
  productoQuery.value = ''
}

function quitarItem(idx) {
  const item = items.value[idx]
  item.boceto_previews.forEach(p => { if (p) URL.revokeObjectURL(p) })
  items.value.splice(idx, 1)
}

function togglePersonalizado(item) {
  const nuevo = !item.es_personalizado
  item.es_personalizado = nuevo
  if (nuevo) {
    // Al convertir a personalizado: liberar la variante de stock para no descontarla
    item.variante_id      = null
    item._combo_id        = null
    item._config_id       = null
    item.tienda_origen_id = null
    item._cotizarPrecio   = true
    item._telaSelections  = {}
  }
}

function onBocetoUpdate(item, blob) {
  if (item.boceto_previews[0]) URL.revokeObjectURL(item.boceto_previews[0])
  if (blob) {
    item.boceto_blobs[0]    = blob
    item.boceto_urls[0]     = ''
    item.boceto_previews[0] = URL.createObjectURL(blob)
  } else {
    item.boceto_blobs.splice(0, 1)
    item.boceto_urls.splice(0, 1)
    item.boceto_previews.splice(0, 1)
  }
}

function onAgregarFotosItem(item, event) {
  for (const file of event.target.files) {
    item.boceto_blobs.push(file)
    item.boceto_urls.push('')
    item.boceto_previews.push(URL.createObjectURL(file))
  }
  event.target.value = ''
}

function onQuitarFotoItem(item, idx) {
  if (item.boceto_previews[idx]) URL.revokeObjectURL(item.boceto_previews[idx])
  item.boceto_blobs.splice(idx, 1)
  item.boceto_urls.splice(idx, 1)
  item.boceto_previews.splice(idx, 1)
}

// ── Picker de tela cascada: Marca → Tipo → Color (igual que en Inventario) ────
function getTelaSelection(item, key) {
  if (!item._telaSelections[key]) {
    item._telaSelections[key] = { marca: '', marcaManual: '', tipo: '', telaManual: '', color: '', colorManual: '' }
  }
  return item._telaSelections[key]
}

function telaResumidaCampo(item, key) {
  const s = item._telaSelections?.[key]
  if (!s?.marca) return ''
  const marca = s.marca === 'Otro' ? (s.marcaManual?.trim() || '') : s.marca
  const tipo  = s.marca === 'Otro' ? (s.telaManual?.trim() || '')
    : s.tipo === 'Otro' ? (s.telaManual?.trim() || '') : s.tipo
  const color = (s.marca === 'Otro' || s.tipo === 'Otro')
    ? (s.colorManual?.trim() || '')
    : s.color === 'Otro' ? (s.colorManual?.trim() || '') : s.color
  return [marca, tipo, color].filter(Boolean).join(' · ')
}


// ── Cotizador de precio con IA ────────────────────────────────────────────────
function getTemplate(cat) {
  const key = resolverCategoria(cat)
  return SPECS_TEMPLATES[key] ?? SPECS_TEMPLATES['generico']
}

async function calcularPrecioIA(item) {
  item._calculandoPrecio = true
  item._precioCalc = null
  try {
    // Subir primera foto ahora si aún no tiene URL (para que la IA lo vea)
    if (item.boceto_blobs[0] && !item.boceto_urls[0]) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(item.boceto_blobs[0]), 'boceto.jpg')
      fd.append('folder', 'bocetos')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      item.boceto_urls[0] = up.url
    }

    // Restauración: parámetros específicos del servicio
    if (tipoOrden.value === 'restauracion') {
      const { data } = await api.post('/calcular-precio-item', {
        es_restauracion: true,
        nombre:    item.nombre,
        trabajo:   item.specs?.descripcion_trabajo || '',
        cantidad:  item.cantidad,
        boceto_url: item.boceto_urls[0] || null,
      })
      item._precioCalc = data
    } else {
      // Producto personalizado — lógica estándar
      const template = getTemplate(item.categoria)
      const specsResueltos = { ...item.specs }
      for (const key of Object.keys(item._telaSelections ?? {})) {
        const tela = telaResumidaCampo(item, key)
        if (tela) specsResueltos[key] = tela
      }
      const specDesc = specsToDescripcion(specsResueltos, template)
      const desc = [specDesc, item.specs_notas].filter(Boolean).join('. ')
      const dims = extraerDimensiones(specsResueltos)
      const { data } = await api.post('/calcular-precio-item', {
        producto_id:       item.producto_id ?? null,
        nombre:            item.nombre,
        categoria:         resolverCategoria(item.categoria) || item.categoria || '',
        descripcion:       specDesc,
        notas_adicionales: item.specs_notas || null,
        precio_referencia: item._precioReferencia ? Number(item._precioReferencia) : null,
        precio_base:       item.producto_id && item.precio_unitario ? item.precio_unitario : null,
        ...dims,
        boceto_url:        item.boceto_urls[0] || null,
      })
      item._precioCalc = data
    }
  } catch {
    toast.error('No se pudo calcular el precio. Intenta de nuevo.')
  } finally {
    item._calculandoPrecio = false
  }
}

function aplicarPrecio(item, precio) {
  item.precio_unitario = precio
  item._mostrarCalculadora = false
}

// ── Paso 3: Pago ──────────────────────────────────────────────────────────────
const anticipo_pct         = ref(50)
const anticipo_monto       = ref(0)
const anticipo_metodo      = ref('efectivo')
const anticipo_referencia  = ref('')
const notas                = ref('')
const submitting           = ref(false)
const modoGuardarBorrador  = ref(false)
const cooldown             = ref(0)   // segundos restantes antes de poder reintentar
let   cooldownTimer        = null

const facturaFotoFile      = ref(null)
const facturaFotoUrl       = ref('')
const facturaFotoPreview   = ref('')
const subiendoFactura      = ref(false)

const firmaBlob            = ref(null)
const firmaUrl             = ref('')
watch(firmaBlob, () => { firmaUrl.value = '' })

// Foto del anexo firmado (solo presencial)
const anexoFotoFile      = ref(null)
const anexoFotoUrl       = ref('')
const anexoFotoPreview   = ref('')
const subiendoAnexo      = ref(false)
watch(anexoFotoFile, (file) => {
  if (anexoFotoPreview.value) URL.revokeObjectURL(anexoFotoPreview.value)
  anexoFotoPreview.value = file ? URL.createObjectURL(file) : ''
  anexoFotoUrl.value = ''
})

watch(facturaFotoFile, (file, oldFile) => {
  if (facturaFotoPreview.value) URL.revokeObjectURL(facturaFotoPreview.value)
  facturaFotoPreview.value = file ? URL.createObjectURL(file) : ''
})

const departamentoEnvio    = ref('')
const ciudadEnvio          = ref('')
const direccionEnvio       = ref('')

// Fecha mínima = hoy (para el date-picker de los ítems)
const hoy = new Date().toISOString().split('T')[0]

const fotoModal    = ref(false)
const fotoProducto = ref(null)

function verFoto(p) {
  fotoProducto.value = p
  fotoModal.value = true
}

const metodosOpts = [
  { value: 'efectivo',      label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'tarjeta',       label: 'Tarjeta' },
  { value: 'otro',          label: 'Otro' },
]

function precioEfectivo(item) {
  const base = item.precio_unitario ?? 0
  const pct  = item._descuento_pct ?? 0
  if (!pct) return base
  return Math.round(base * (1 - pct / 100))
}

const valorTotal = computed(() =>
  items.value.reduce((s, i) => s + i.cantidad * precioEfectivo(i), 0)
)

const minimoAnticipo = computed(() =>
  Math.ceil(valorTotal.value * anticipo_pct.value / 100)
)

// Si hay ítems con cotización pendiente, el anticipo mínimo es 0
// (no se puede cobrar anticipo de un precio desconocido)
const minimoAnticipofEfectivo = computed(() =>
  hayItemsCotizar.value ? 0 : minimoAnticipo.value
)

// ── Cotización de costo durante la creación ───────────────────────────────────
const cotizarReceptorId   = ref(null)
const cotizarNotas        = ref('')
const receptoresCotizar   = ref([])
const cargandoReceptores  = ref(false)

const hayItemsCotizar = computed(() =>
  items.value.some(i => i._cotizarPrecio)
)

async function irAPaso3() {
  anticipo_monto.value = minimoAnticipo.value
  step.value = 3
  if (hayItemsCotizar.value && !receptoresCotizar.value.length) {
    cargandoReceptores.value = true
    try {
      const { data } = await getReceptores()
      receptoresCotizar.value = data
    } catch { receptoresCotizar.value = [] }
    finally { cargandoReceptores.value = false }
  }
}

async function submit() {
  if (submitting.value || subiendoFactura.value || cooldown.value > 0) return

  const sinPrecio = items.value.filter(i => i.es_personalizado && !i.precio_unitario && !i._cotizarPrecio)
  if (sinPrecio.length) {
    toast.error(`${sinPrecio.length} producto(s) sin precio. Usa el cotizador IA, ingresa el precio manualmente, o activa "Consultar precio".`)
    return
  }

  if (hayItemsCotizar.value && !cotizarReceptorId.value) {
    toast.error('Selecciona a quién enviar la consulta de costo antes de continuar.')
    return
  }

  // Validar disponibilidad de tela para items a fabricar bajo pedido
  for (const item of items.value) {
    if (!item._fabricar_pedido || !item._esTapizado) continue
    const sel = item._telaSelections?.tela
    if (!sel || !sel.tipo || sel.tipo === 'Otro' || sel.marca === 'Otro' || !sel.color) continue
    try {
      const { data: tv } = await api.get('/inventario-telas/validar', {
        params: { marca: sel.marca, tipo: sel.tipo, color: sel.color },
      })
      if (!tv.disponible) {
        toast.error(`No hay metros disponibles de "${sel.tipo} – ${sel.color}". Elige otra tela o contacta al encargado.`)
        return
      }
    } catch {
      // No bloquear si falla la validación por error de red
    }
  }

  submitting.value = true
  try {
    // Subir foto de factura si se seleccionó (no aplica para borrador)
    if (!modoGuardarBorrador.value && facturaFotoFile.value && !facturaFotoUrl.value) {
      subiendoFactura.value = true
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(facturaFotoFile.value), 'factura.jpg')
      fd.append('folder', 'facturas')
      const { data: uploadData } = await api.post('/upload/foto', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      facturaFotoUrl.value = uploadData.url
      subiendoFactura.value = false
    }

    // Bocetos/fotos de ítems personalizados: subir los que tengan blob pendiente
    for (const item of items.value) {
      if (item.es_personalizado) {
        for (let fi = 0; fi < item.boceto_blobs.length; fi++) {
          if (item.boceto_blobs[fi] && !item.boceto_urls[fi]) {
            const fd = new FormData()
            fd.append('foto', await comprimirImagen(item.boceto_blobs[fi]), fi === 0 ? 'boceto.jpg' : `boceto_${fi}.jpg`)
            fd.append('folder', 'bocetos')
            const { data: uploadData } = await api.post('/upload/foto', fd, {
              headers: { 'Content-Type': 'multipart/form-data' },
            })
            item.boceto_urls[fi] = uploadData.url
          }
        }
      }
    }

    // Foto del anexo firmado (no aplica para borrador)
    if (!modoGuardarBorrador.value && anexoFotoFile.value && !anexoFotoUrl.value) {
      subiendoAnexo.value = true
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(anexoFotoFile.value), 'anexo.jpg')
      fd.append('folder', 'facturas')
      const { data: uploadData } = await api.post('/upload/foto', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      anexoFotoUrl.value  = uploadData.url
      subiendoAnexo.value = false
    }

    // Firma del cliente: subir el blob dibujado en el canvas (no aplica para borrador)
    if (!modoGuardarBorrador.value && firmaBlob.value && !firmaUrl.value) {
      const fd = new FormData()
      fd.append('foto', firmaBlob.value, 'firma.png')
      fd.append('folder', 'firmas')
      const { data: uploadData } = await api.post('/upload/foto', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      firmaUrl.value = uploadData.url
    }

    const payload = {
      cliente_id:           clienteSeleccionado.value.id,
      tienda_id:            tiendaId.value,
      canal:                canal.value,
      tipo:                 tipoOrden.value,
      anticipo_pct:         anticipo_pct.value,
      anticipo_monto:       (modoGuardarBorrador.value || hayItemsCotizar.value) ? 0 : anticipo_monto.value,
      anticipo_metodo:      anticipo_metodo.value,
      anticipo_referencia:  anticipo_referencia.value || undefined,
      guardar_borrador:     modoGuardarBorrador.value || undefined,
      notas:                notas.value || undefined,
      factura_foto_url:     facturaFotoUrl.value  || undefined,
      firma_url:            firmaUrl.value        || undefined,
      anexo_foto_url:       anexoFotoUrl.value    || undefined,
      departamento_envio:   departamentoEnvio.value || undefined,
      ciudad_envio:         ciudadEnvio.value || undefined,
      direccion_envio:      direccionEnvio.value || undefined,
      items: items.value.map((i) => ({
        producto_id:             i.producto_id || undefined,
        nombre_custom:           i.nombre_custom || undefined,
        categoria_custom:        i.categoria_custom || undefined,
        variante_id:             i.variante_id || undefined,
        combo_config_id:         i._config_id  || undefined,
        tienda_origen_id:        i.tienda_origen_id || undefined,
        cantidad:                i.cantidad,
        precio_unitario:         i._cotizarPrecio ? 0 : precioEfectivo(i),
        es_personalizado:        i.es_personalizado,
        fecha_entrega_prometida: i.fecha_entrega_prometida || undefined,
        specs_personalizacion:   i.es_personalizado
          ? (() => {
              const s = { ...i.specs }
              for (const key of Object.keys(i._telaSelections ?? {})) {
                const tela = telaResumidaCampo(i, key)
                if (tela) s[key] = tela
              }
              if (i.specs_notas) s.notas = i.specs_notas
              return Object.keys(s).length ? s : undefined
            })()
          : undefined,
        boceto_urls:             i.es_personalizado && i.boceto_urls.some(Boolean)
          ? i.boceto_urls.filter(Boolean)
          : undefined,
      })),
    }

    const { data } = await api.post('/ordenes', payload)

    // Crear consulta de costo si hay ítems marcados para cotizar
    if (hayItemsCotizar.value && cotizarReceptorId.value && data?.id) {
      try {
        await crearConsulta({
          orden_id:          data.id,
          asignado_a_id:     cotizarReceptorId.value,
          notas_adicionales: cotizarNotas.value.trim() || null,
        })
      } catch { /* La orden se creó bien — la consulta puede reintentarse desde el detalle */ }
    }

    // Si el backend detectó duplicado (409), redirigir a la orden existente
    if (data?.orden_id) {
      router.push({ name: 'orden-detalle', params: { id: data.orden_id } })
    } else if (modoGuardarBorrador.value && data?.id) {
      toast.success('Borrador guardado. Los productos quedan reservados.')
      router.push({ name: 'orden-detalle', params: { id: data.id } })
    } else {
      router.push({ name: 'ordenes' })
    }
  } catch (e) {
    const status = e.response?.status
    if (status === 409 && e.response?.data?.orden_id) {
      // Orden ya creada — ir a ella en vez de mostrar error
      router.push({ name: 'orden-detalle', params: { id: e.response.data.orden_id } })
      return
    }
    const errores = e.response?.data?.errors
    const detalle = errores ? ' · ' + Object.entries(errores).map(([k, v]) => `${k}: ${v[0]}`).join(', ') : ''
    toast.error((e.response?.data?.message ?? 'Error al crear la orden') + detalle)
    console.error('422 payload:', e.response?.data)
    // Cooldown de 4 segundos para evitar doble envío accidental
    cooldown.value = 4
    clearInterval(cooldownTimer)
    cooldownTimer = setInterval(() => {
      cooldown.value--
      if (cooldown.value <= 0) clearInterval(cooldownTimer)
    }, 1000)
  } finally {
    submitting.value = false
    subiendoFactura.value = false
    modoGuardarBorrador.value = false
  }
}

async function submitBorrador() {
  if (submitting.value) return
  if (!items.value.length) {
    toast.error('Agrega al menos un producto antes de guardar el borrador.')
    return
  }
  modoGuardarBorrador.value = true
  await submit()
}

function onFacturaFotoChange(e) {
  const file = e.target.files[0]
  if (file) {
    facturaFotoFile.value = file
    facturaFotoUrl.value = '' // Reset URL para forzar nueva subida
  }
}

function removeFacturaFoto() {
  facturaFotoFile.value = null
  facturaFotoUrl.value = ''
}
</script>

<template>
  <div>
    <div class="p-4 max-w-lg mx-auto space-y-4 pb-8">

    <!-- Cabecera + progreso -->
    <div class="flex items-center gap-3">
      <button
        v-if="step > 1"
        @click="step--"
        class="text-blue-600 text-sm font-medium"
      >← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">Nueva Orden</h2>
      <span class="text-xs text-gray-400">{{ step }}/3</span>
    </div>

    <!-- Barra de pasos -->
    <div class="flex gap-1">
      <div v-for="n in 3" :key="n"
        :class="['h-1 flex-1 rounded-full transition-colors',
          n <= step ? 'bg-blue-600' : 'bg-gray-200']"
      />
    </div>

    <!-- Firma del vendedor requerida -->
    <div
      v-if="!auth.usuario?.firma_url"
      class="bg-amber-50 border border-amber-300 rounded-xl p-4 flex flex-col gap-3"
    >
      <div class="flex items-start gap-3">
        <ExclamationTriangleIcon class="w-6 h-6 text-amber-500 flex-shrink-0" />
        <div>
          <p class="font-semibold text-amber-800 text-sm">Registra tu firma antes de crear órdenes</p>
          <p class="text-xs text-amber-700 mt-0.5">Tu firma aparece en la cotización del cliente. Es obligatoria para poder generar órdenes.</p>
        </div>
      </div>
      <button
        @click="router.push({ name: 'perfil' })"
        class="w-full bg-amber-500 hover:bg-amber-600 text-white rounded-lg py-2.5 text-sm font-semibold transition-colors"
      >
        Ir a Mi Perfil → Registrar firma
      </button>
    </div>

    <!-- ═══════════════════════════════════════════════════════ PASO 1 ══ -->
    <template v-if="step === 1">

      <!-- Tipo de orden -->
      <div>
        <label class="label">Tipo de orden</label>
        <div class="flex gap-2">
          <button
            @click="cambiarTipo('venta')"
            :class="['flex-1 py-2 rounded-xl text-sm font-medium border transition-colors',
              tipoOrden === 'venta'
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >Venta</button>
          <button
            @click="cambiarTipo('restauracion')"
            :class="['flex-1 py-2 rounded-xl text-sm font-medium border transition-colors',
              tipoOrden === 'restauracion'
                ? 'bg-indigo-600 text-white border-indigo-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >Restauración</button>
        </div>
        <p v-if="tipoOrden === 'restauracion'" class="text-xs text-indigo-600 mt-1.5">
          Para muebles que trae el cliente (tapizado, laca, lijado…). No descuenta inventario y va directo a producción.
        </p>
      </div>

      <!-- Tienda -->
      <div>
        <label class="label">Tienda</label>
        <select v-if="auth.isSupervisor || auth.isEbanista" v-model="tiendaId" class="input">
          <option value="">Seleccionar...</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
        <div v-else class="input bg-gray-50 text-gray-700 cursor-default select-none">
          {{ tiendas.find(t => t.id == tiendaId)?.nombre ?? 'Cargando...' }}
        </div>
      </div>

      <!-- Canal -->
      <div>
        <label class="label">Canal de venta</label>
        <div class="flex gap-2 flex-wrap">
          <button
            v-for="c in canalesopts"
            :key="c.value"
            @click="canal = c.value"
            :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
              canal === c.value
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >{{ c.label }}</button>
        </div>
      </div>

      <!-- Búsqueda de cliente -->
      <div>
        <label class="label">Cliente</label>
        <div class="flex gap-2">
          <input
            v-model="clienteQuery"
            @keyup.enter="buscarCliente"
            @input="onClienteInput"
            placeholder="Nombre, cédula o teléfono..."
            class="input flex-1"
            :disabled="!!clienteSeleccionado"
          />
          <button
            v-if="!clienteSeleccionado"
            @click="buscarCliente"
            :disabled="buscandoCliente"
            class="btn-primary px-3"
          >Buscar</button>
          <button
            v-else
            @click="clienteSeleccionado = null; clienteQuery = ''"
            class="text-xs text-red-500 font-medium px-2"
          >Cambiar</button>
        </div>

        <!-- Resultados -->
        <ul v-if="clienteResultados.length" class="mt-1 bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
          <li
            v-for="c in clienteResultados"
            :key="c.id"
            @click="seleccionarCliente(c)"
            class="px-4 py-3 hover:bg-blue-50 cursor-pointer flex items-center justify-between gap-2"
          >
            <div class="flex items-center gap-2 min-w-0 flex-1">
              <span class="font-medium text-sm text-gray-800 truncate">{{ c.nombre }}</span>
              <span
                v-if="c.tipo === 'interesado'"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 flex-shrink-0"
              >
                <UserGroupIcon class="w-3 h-3" />
                Interesado
              </span>
            </div>
            <span class="text-xs text-gray-400 flex-shrink-0">{{ c.telefono }}</span>
          </li>
          <!-- Siempre ofrecer crear nuevo al fondo, aunque haya resultados -->
          <li
            @click="modoNuevoCliente = true; nuevoCliente.nombre = clienteQuery; clienteResultados = []"
            class="px-4 py-2.5 border-t border-gray-100 hover:bg-green-50 cursor-pointer flex items-center gap-2 text-green-700"
          >
            <PlusIcon class="w-4 h-4 flex-shrink-0" />
            <span class="text-sm font-medium">Crear "{{ clienteQuery }}"</span>
          </li>
        </ul>

        <!-- Sin resultados -->
        <div
          v-else-if="clienteQuery && !buscandoCliente && !clienteSeleccionado && clienteResultados.length === 0"
          class="mt-2 text-sm text-gray-500"
        >
          No encontrado.
          <button @click="modoNuevoCliente = true" class="text-blue-600 font-medium ml-1">Crear nuevo</button>
        </div>

        <!-- Cliente seleccionado -->
        <div v-if="clienteSeleccionado" class="mt-2 space-y-2">
          <!-- Chip resumen del cliente -->
          <div :class="['rounded-lg px-3 py-2 text-sm flex items-center gap-2 flex-wrap', clienteRequiereCompletar ? 'bg-amber-50 border border-amber-200' : 'bg-blue-50']">
            <span class="font-semibold" :class="clienteRequiereCompletar ? 'text-amber-800' : 'text-blue-700'">{{ clienteSeleccionado.nombre }}</span>
            <span v-if="clienteSeleccionado.telefono" :class="clienteRequiereCompletar ? 'text-amber-600' : 'text-blue-500'">{{ clienteSeleccionado.telefono }}</span>
            <span
              v-if="clienteSeleccionado.tipo === 'interesado'"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"
            >
              <UserGroupIcon class="w-3 h-3" />
              Interesado
            </span>
          </div>

          <!-- Formulario inline para completar datos (interesado → oficial) -->
          <div v-if="clienteRequiereCompletar" class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-3">
            <p class="text-xs font-semibold text-amber-800 flex items-center gap-1.5">
              <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0" />
              Completa todos los datos del cliente para crear la orden
            </p>

            <div class="space-y-2">
              <div>
                <label class="text-xs text-gray-500 mb-1 block">Nombre completo <span class="text-red-500">*</span></label>
                <input v-model="formCompletarCliente.nombre" type="text" placeholder="Nombre y apellido" class="input" />
              </div>
              <div>
                <label class="text-xs text-gray-500 mb-1 block">Cédula / NIT <span class="text-red-500">*</span></label>
                <input v-model="formCompletarCliente.cedula" type="text" inputmode="numeric" placeholder="Ej: 1012345678" class="input" />
              </div>
              <div>
                <label class="text-xs text-gray-500 mb-1 block">Teléfono <span class="text-red-500">*</span></label>
                <input v-model="formCompletarCliente.telefono" type="tel" placeholder="Ej: 3001234567" class="input" />
              </div>
              <div>
                <label class="text-xs text-gray-500 mb-1 block">Email <span class="text-gray-400 font-normal">(opcional)</span></label>
                <input v-model="formCompletarCliente.email" type="email" placeholder="correo@ejemplo.com" class="input" />
              </div>
              <div>
                <label class="text-xs text-gray-500 mb-1 block">Dirección <span class="text-red-500">*</span></label>
                <input v-model="formCompletarCliente.direccion" type="text" placeholder="Dirección de entrega" class="input" />
              </div>
            </div>

            <p v-if="errCompletarCliente" class="text-xs text-red-600">{{ errCompletarCliente }}</p>

            <button
              @click="completarYConvertirCliente"
              :disabled="guardandoCompletarCliente"
              class="w-full py-2 bg-amber-500 text-white text-xs font-semibold rounded-lg hover:bg-amber-600 disabled:opacity-50 transition-colors flex items-center justify-center gap-1.5"
            >
              <ArrowPathIcon v-if="guardandoCompletarCliente" class="w-3.5 h-3.5 animate-spin" />
              {{ guardandoCompletarCliente ? 'Guardando...' : 'Guardar y convertir a cliente oficial' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Formulario nuevo cliente -->
      <div v-if="modoNuevoCliente" class="bg-gray-50 rounded-xl p-4 space-y-3">
        <p class="text-sm font-semibold text-gray-700">Nuevo cliente</p>

        <!-- Tipo -->
        <div>
          <label class="text-xs text-gray-500 mb-1">Tipo</label>
          <div class="flex gap-2">
            <button
              type="button"
              @click="nuevoCliente.tipo = 'oficial'"
              :class="[
                'flex-1 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                nuevoCliente.tipo === 'oficial'
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white text-gray-700 border-gray-300'
              ]"
            >Oficial</button>
            <button
              type="button"
              @click="nuevoCliente.tipo = 'interesado'"
              :class="[
                'flex-1 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                nuevoCliente.tipo === 'interesado'
                  ? 'bg-amber-500 text-white border-amber-500'
                  : 'bg-white text-gray-700 border-gray-300'
              ]"
            >Interesado</button>
          </div>
        </div>

        <!-- Para oficial todos los campos son requeridos; para interesado todos opcionales -->
        <div v-if="nuevoCliente.tipo === 'oficial'" class="text-xs text-gray-400">
          Todos los campos marcados con <span class="text-red-500">*</span> son obligatorios.
        </div>
        <input
          v-model="nuevoCliente.nombre"
          class="input"
          :placeholder="nuevoCliente.tipo === 'oficial' ? 'Nombre completo *' : 'Nombre completo (opcional)'"
        />
        <input
          v-model="nuevoCliente.cedula"
          class="input"
          inputmode="numeric"
          :placeholder="nuevoCliente.tipo === 'oficial' ? 'Cédula / NIT *' : 'Cédula / NIT (opcional)'"
        />
        <input
          v-model="nuevoCliente.telefono"
          class="input"
          type="tel"
          :placeholder="nuevoCliente.tipo === 'oficial' ? 'Teléfono *' : 'Teléfono (opcional)'"
        />
        <input
          v-model="nuevoCliente.email"
          class="input"
          type="email"
          placeholder="Email (opcional)"
        />
        <input
          v-model="nuevoCliente.direccion"
          class="input"
          :placeholder="nuevoCliente.tipo === 'oficial' ? 'Dirección *' : 'Dirección (opcional)'"
        />

        <!-- Campos para interesado -->
        <template v-if="nuevoCliente.tipo === 'interesado'">
          <div>
            <label class="text-xs text-gray-500 mb-1">
              Categorías de interés
              <span class="text-gray-400 font-normal">(mantén presionado para varias)</span>
            </label>
            <select
              v-model="nuevoCliente.categorias_interes"
              multiple
              size="5"
              class="input text-sm"
            >
              <option v-for="cat in CATEGORIAS_DISPONIBLES" :key="cat" :value="cat">{{ cat }}</option>
            </select>
            <div v-if="nuevoCliente.categorias_interes.length > 0" class="flex flex-wrap gap-1 mt-1.5">
              <span
                v-for="cat in nuevoCliente.categorias_interes"
                :key="cat"
                class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"
              >{{ cat }}</span>
            </div>
          </div>
          <div>
            <label class="text-xs text-gray-500 mb-1">Notas de interés</label>
            <textarea v-model="nuevoCliente.notas_interes" rows="2" class="input text-sm resize-none" placeholder="¿En qué está interesado?"></textarea>
          </div>
        </template>

        <p v-if="errCliente" class="text-xs text-red-600">{{ errCliente }}</p>
        <div class="flex gap-2">
          <button @click="modoNuevoCliente = false" class="btn-secondary flex-1">Cancelar</button>
          <button @click="crearCliente" :disabled="creandoCliente || !nuevoClienteValido()" class="btn-primary flex-1">
            {{ creandoCliente ? 'Guardando...' : 'Guardar' }}
          </button>
        </div>
      </div>

      <button
        @click="step = 2"
        :disabled="!paso1Valido() || !auth.usuario?.firma_url"
        class="btn-primary w-full mt-2"
      >Continuar → Productos</button>
    </template>

    <!-- ═══════════════════════════════════════════════════════ PASO 2 ══ -->
    <template v-else-if="step === 2">

      <!-- ── Modo venta: búsqueda de catálogo ── -->
      <template v-if="tipoOrden === 'venta'">

      <!-- Selector tienda de búsqueda -->
      <div>
        <label class="label">Buscar en</label>
        <select v-model="tiendaBusqueda" @change="productoResultados = []; fabricaStock = {}" class="input text-sm">
          <option v-for="t in tiendas" :key="t.id" :value="t.id">
            {{ t.nombre }}{{ t.id == tiendaId ? ' (tu tienda)' : '' }}
          </option>
          <option v-if="fabricaId" :value="fabricaId">Bodega Fábrica (Reserva)</option>
        </select>
        <p v-if="tiendaBusqueda == fabricaId" class="mt-1 text-xs text-purple-600 font-medium">
          Consultando reserva de fábrica — los productos se toman directamente de fábrica al cliente
        </p>
        <p v-else-if="tiendaBusqueda && tiendaBusqueda != tiendaId" class="mt-1 text-xs text-amber-600 font-medium">
          Consultando stock de otra tienda — la orden se registra en {{ tiendas.find(t => t.id == tiendaId)?.nombre }}
        </p>
      </div>

      <!-- Buscador de productos -->
      <div class="flex gap-2">
        <input
          v-model="productoQuery"
          @keyup.enter="buscarProducto"
          placeholder="Buscar producto..."
          class="input flex-1"
        />
        <button @click="buscarProducto" :disabled="buscandoProducto" class="btn-primary px-3">
          Buscar
        </button>
      </div>

      <!-- Resultados de productos -->
      <ul v-if="productoResultados.length" class="space-y-2">
        <li
          v-for="p in productoResultados"
          :key="p.id"
          class="bg-white rounded-xl shadow-sm p-3 space-y-2"
        >
          <!-- Fila superior: thumbnail + info + precio -->
          <div class="flex items-center gap-3">
            <button
              @click="p.foto_url && verFoto(p)"
              :class="[
                'flex-shrink-0 w-11 h-11 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center',
                p.foto_url ? 'cursor-pointer hover:opacity-75 transition-opacity' : 'cursor-default'
              ]"
            >
              <img v-if="p.foto_url" :src="cloudinaryOpt(p.foto_url, 88)" :alt="p.nombre" class="w-full h-full object-cover" />
              <PhotoIcon v-else class="w-5 h-5 text-gray-300" />
            </button>

            <div class="flex-1 min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">{{ p.nombre }}</p>
              <p class="text-xs text-gray-400 truncate">
                {{ p.categoria }}
                <span v-if="tiendaBusqueda && tiendaBusqueda != tiendaId"
                  class="ml-1 bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-medium">
                  {{ nombreTiendaBusqueda() }}
                </span>
              </p>
            </div>

            <span class="text-sm font-bold text-gray-800 flex-shrink-0">
              ${{ Number(p.precio_base).toLocaleString('es-CO') }}
            </span>
          </div>

          <!-- Fila inferior: stock + botones -->
          <div class="flex items-center justify-between gap-2 pt-0.5">
            <!-- Badges stock -->
            <div class="flex items-center gap-1.5 flex-wrap">
              <span :class="[
                'text-xs font-medium px-2 py-0.5 rounded-full',
                stockLibre(p) > 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600'
              ]">
                {{ stockLibre(p) > 0 ? `${stockLibre(p)} en stock` : 'Sin stock' }}
              </span>
              <span v-if="fabricaStock[p.id] > 0"
                class="text-xs font-medium px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">
                Fábrica: {{ fabricaStock[p.id] }}
              </span>
            </div>

            <!-- Botones de acción -->
            <div class="flex items-center gap-1.5 flex-wrap">
              <!-- Agregar: solo si hay stock en tienda O (tapizado con stock en reserva de fábrica) -->
              <button
                v-if="stockLibre(p) > 0 || (p.es_tapizado && fabricaStock[p.id] > 0)"
                @click="agregarItem(p)"
                class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors"
              >{{ p.tiene_tallas ? 'Seleccionar talla' : '+ Agregar' }}</button>

              <!-- Sin stock en tienda ni en fábrica → Fabricar y Personalizar -->
              <template v-if="stockLibre(p) <= 0 && !(p.es_tapizado && fabricaStock[p.id] > 0)">
                <button
                  @click="fabricarBajoPedido(p)"
                  class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-orange-500 text-white hover:bg-orange-600 transition-colors"
                >Fabricar</button>
                <button
                  v-if="p.personalizable"
                  @click="agregarPersonalizado(p)"
                  class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-purple-100 text-purple-700 hover:bg-purple-200 transition-colors"
                >Personalizar</button>
              </template>
            </div>
          </div>
        </li>
      </ul>

      <!-- Sin resultados: ofrecer crear producto nuevo -->
      <div
        v-if="busquedaHecha && !productoResultados.length && !buscandoProducto && !mostrarCrearProducto"
        class="bg-gray-50 border border-dashed border-gray-300 rounded-xl p-4 text-center space-y-2.5"
      >
        <p class="text-sm text-gray-500">No se encontró <strong class="text-gray-700">"{{ productoQuery }}"</strong> en el catálogo.</p>
        <button
          @click="abrirCrearProducto"
          class="text-sm font-semibold text-green-700 border border-green-300 bg-green-50 px-4 py-2 rounded-lg hover:bg-green-100 transition-colors"
        >+ Registrar como nuevo producto</button>
      </div>

      <!-- Link para crear cuando SÍ hay resultados pero no es lo que busca -->
      <div v-if="busquedaHecha && productoResultados.length && !mostrarCrearProducto" class="text-center">
        <button
          @click="abrirCrearProducto"
          class="text-xs text-gray-400 hover:text-green-700 transition-colors underline underline-offset-2"
        >¿No encuentras lo que buscas? Registrar nuevo producto</button>
      </div>

      <!-- Formulario crear producto nuevo -->
      <div v-if="mostrarCrearProducto" class="bg-green-50 border border-green-200 rounded-xl p-4 space-y-3">
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-green-800">Registrar nuevo producto</p>
          <button @click="mostrarCrearProducto = false" class="text-green-400 hover:text-green-600">
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>
        <p class="text-xs text-gray-500">El producto quedará guardado en el inventario para que otros vendedores puedan encontrarlo.</p>

        <!-- Nombre -->
        <input
          v-model="crearProductoForm.nombre"
          @input="onNombreNuevoInput"
          class="input text-sm"
          placeholder="Nombre del producto *"
        />

        <!-- Categoría + Precio -->
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="text-xs text-gray-600 mb-1 block">Categoría</label>
            <select
              :value="categoriaSelNuevo"
              @change="onCategoriaNuevoSelect($event.target.value)"
              class="input text-sm bg-white"
            >
              <option value="">Sin categoría</option>
              <option v-for="cat in categoriasNuevo" :key="cat" :value="cat">{{ cat }}</option>
              <option value="__nueva__">＋ Nueva…</option>
            </select>
            <input
              v-if="categoriaSelNuevo === '__nueva__'"
              v-model="crearProductoForm.categoria"
              class="input text-sm mt-1"
              placeholder="Escribe la categoría"
              autofocus
            />
          </div>
          <div>
            <label class="text-xs text-gray-600 mb-1 block">Precio base *</label>
            <input
              v-model.number="crearProductoForm.precio_base"
              type="number" min="0"
              class="input text-sm"
              placeholder="0"
            />
          </div>
        </div>

        <!-- Medidas + Material -->
        <div class="grid grid-cols-2 gap-2">
          <input v-model="crearProductoForm.medidas" class="input text-sm" placeholder="Medidas (ej: 200x90)" />
          <input v-model="crearProductoForm.material" class="input text-sm" placeholder="Material (ej: Cuero)" />
        </div>

        <!-- Foto -->
        <div>
          <label class="text-xs text-gray-600 mb-1.5 block">Foto del producto</label>
          <input ref="fotoNuevoInput" type="file" accept="image/*" class="hidden" @change="onFotoNuevoChange" />
          <div v-if="fotoNuevoPreview" class="space-y-1.5">
            <div class="relative rounded-xl overflow-hidden border-2 border-green-300 bg-white">
              <img :src="fotoNuevoPreview" alt="Vista previa" class="w-full object-contain" style="max-height:180px" />
              <button type="button" @click="quitarFotoNuevo" class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow">
                <XMarkIcon class="w-3.5 h-3.5" />
              </button>
            </div>
            <button type="button" @click="fotoNuevoInput.click()" class="text-xs text-green-700 font-medium hover:underline">Cambiar foto</button>
          </div>
          <button
            v-else
            type="button"
            @click="fotoNuevoInput.click()"
            class="w-full flex flex-col items-center gap-1.5 border-2 border-dashed border-green-300 rounded-xl p-4 hover:bg-green-100 transition-colors"
          >
            <PhotoIcon class="w-6 h-6 text-green-400" />
            <span class="text-xs text-gray-500">Toca para agregar foto</span>
          </button>
        </div>

        <!-- Descripción -->
        <textarea
          v-model="crearProductoForm.descripcion"
          rows="2"
          class="input text-sm resize-none"
          placeholder="Descripción (opcional)"
        />

        <!-- Es tapizado -->
        <label class="flex items-center gap-2 cursor-pointer select-none">
          <input type="checkbox" v-model="crearProductoForm.es_tapizado" class="rounded w-4 h-4 text-amber-600" />
          <span class="text-sm text-gray-700">Lleva tapizado <span class="text-gray-400">(activa selección de tela)</span></span>
        </label>

        <p v-if="crearProductoError" class="text-xs text-red-600">{{ crearProductoError }}</p>
        <p class="text-xs text-amber-600">Se creará con stock 0 y se agregará como fabricación bajo pedido.</p>
        <div class="flex gap-2">
          <button @click="mostrarCrearProducto = false" class="btn-secondary flex-1 text-sm">Cancelar</button>
          <button
            @click="crearYAgregarProducto"
            :disabled="creandoProducto || subiendoFotoNuevo || !crearProductoForm.nombre.trim() || !crearProductoForm.precio_base"
            class="btn-primary flex-1 text-sm disabled:opacity-40"
          >{{ subiendoFotoNuevo ? 'Subiendo foto…' : creandoProducto ? 'Creando…' : 'Crear y agregar' }}</button>
        </div>
      </div>

      <!-- Producto no catalogado -->
      <div>
        <button
          v-if="!modoProductoCustom"
          @click="modoProductoCustom = true"
          class="w-full text-sm text-purple-600 border border-dashed border-purple-300 rounded-xl py-2.5 hover:bg-purple-50 transition-colors font-medium"
        >
          + Producto no está en el catálogo
        </button>

        <div v-else class="bg-purple-50 border border-purple-200 rounded-xl p-4 space-y-3">
          <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-purple-800">Producto personalizado</p>
            <button @click="modoProductoCustom = false" class="text-purple-400 hover:text-purple-600">
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>
          <input
            v-model="productoCustomForm.nombre"
            class="input text-sm"
            placeholder="Nombre del producto *"
          />
          <input
            v-model="productoCustomForm.categoria"
            class="input text-sm"
            placeholder="Categoría (ej: comedor, silla, sofá...)"
          />
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500">Cantidad *</label>
              <input v-model.number="productoCustomForm.cantidad" type="number" min="1" class="input text-sm" />
            </div>
          </div>
          <p class="text-xs text-amber-600">El precio se define después con el cotizador IA o manualmente.</p>
          <div class="flex gap-2">
            <button @click="modoProductoCustom = false" class="btn-secondary flex-1 text-sm">Cancelar</button>
            <button
              @click="agregarProductoCustom"
              :disabled="!productoCustomForm.nombre.trim() || productoCustomForm.cantidad < 1"
              class="btn-primary flex-1 text-sm disabled:opacity-40"
            >Agregar al carrito</button>
          </div>
        </div>
      </div>

      </template><!-- fin modo venta -->

      <!-- ── Modo restauración: agregar mueble simple ── -->
      <template v-else>
        <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 space-y-3">
          <p class="text-sm font-semibold text-indigo-800">Agregar mueble</p>
          <input
            v-model="restauracionItem.nombre_mueble"
            class="input text-sm"
            placeholder="Mueble (ej: Sofá 3 puestos, Silla comedor...)"
          />
          <input
            v-model="restauracionItem.descripcion_trabajo"
            class="input text-sm"
            placeholder="Trabajo a realizar (ej: Tapizado + laca)"
          />

          <!-- Foto del mueble -->
          <div>
            <p class="text-xs text-gray-500 mb-1.5">Foto del mueble <span class="text-gray-400">(opcional — mejora el cálculo de la IA)</span></p>
            <div v-if="restauracionItem.foto_preview" class="relative">
              <img
                :src="restauracionItem.foto_preview"
                alt="Foto mueble"
                class="w-full rounded-xl object-cover border border-indigo-200 max-h-40"
              />
              <button
                type="button"
                @click="quitarFotoRestauracionForm"
                class="absolute top-2 right-2 bg-white rounded-full p-1 shadow text-red-400 hover:text-red-600"
              >
                <XMarkIcon class="w-4 h-4" />
              </button>
            </div>
            <label
              v-else
              class="flex items-center justify-center gap-2 border-2 border-dashed border-indigo-200 rounded-xl py-4 text-sm text-gray-400 cursor-pointer hover:border-indigo-400 hover:text-indigo-500 transition-colors"
            >
              <PhotoIcon class="w-5 h-5" />
              Subir foto
              <input type="file" accept="image/*" class="hidden" @change="onFotoRestauracionForm" />
            </label>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500">Cantidad</label>
              <input v-model.number="restauracionItem.cantidad" type="number" min="1" class="input text-sm" />
            </div>
            <div>
              <label class="text-xs text-gray-500">Precio</label>
              <input v-model.number="restauracionItem.precio_unitario" type="number" min="0" placeholder="0" class="input text-sm" />
            </div>
          </div>

          <!-- Retapizar -->
          <label class="flex items-center gap-2 cursor-pointer select-none">
            <input
              type="checkbox"
              v-model="restauracionItem._retapizar"
              class="w-4 h-4 rounded accent-indigo-600"
            />
            <span class="text-sm text-gray-700 font-medium">Retapizar</span>
          </label>

          <div v-if="restauracionItem._retapizar" class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-2">
            <p class="text-xs font-semibold text-amber-800">Selecciona la tela <span class="text-red-500">*</span></p>

            <select
              v-model="getTelaSelection(restauracionItem, 'tela').marca"
              @change="getTelaSelection(restauracionItem, 'tela').tipo = ''; getTelaSelection(restauracionItem, 'tela').color = ''"
              class="input text-sm"
            >
              <option value="">— elige la marca —</option>
              <option v-for="m in marcasConStock()" :key="m" :value="m">{{ m }}</option>
            </select>

            <template v-if="getTelaSelection(restauracionItem, 'tela').marca">
              <select
                v-model="getTelaSelection(restauracionItem, 'tela').tipo"
                @change="getTelaSelection(restauracionItem, 'tela').color = ''"
                class="input text-sm"
              >
                <option value="">— tipo de tela —</option>
                <option v-for="t in tiposConStock(getTelaSelection(restauracionItem, 'tela').marca)" :key="t" :value="t">{{ t }}</option>
              </select>

              <template v-if="getTelaSelection(restauracionItem, 'tela').tipo">
                <select v-model="getTelaSelection(restauracionItem, 'tela').color" class="input text-sm">
                  <option value="">— color —</option>
                  <option v-for="c in coloresConStock(getTelaSelection(restauracionItem, 'tela').marca, getTelaSelection(restauracionItem, 'tela').tipo)" :key="c" :value="c">{{ c }}</option>
                </select>
              </template>
            </template>

            <p v-if="telaResumidaCampo(restauracionItem, 'tela')" class="text-xs font-semibold text-amber-700">
              ✓ {{ telaResumidaCampo(restauracionItem, 'tela') }}
            </p>
            <p v-else class="text-xs text-amber-600 italic">Selecciona qué tela usará el tapicero</p>
          </div>

          <!-- Cotizador IA (en el formulario, antes de agregar) -->
          <div>
            <button
              type="button"
              @click="restauracionCalc.mostrar = !restauracionCalc.mostrar"
              :disabled="!restauracionItem.nombre_mueble.trim()"
              class="flex items-center gap-1.5 text-xs text-indigo-600 font-medium hover:text-indigo-800 transition-colors disabled:opacity-40"
            >
              <SparklesIcon class="w-3.5 h-3.5" />
              {{ restauracionCalc.mostrar ? 'Ocultar cotizador' : 'Calcular precio con IA' }}
            </button>

            <div v-if="restauracionCalc.mostrar" class="mt-2 bg-indigo-50 border border-indigo-200 rounded-xl p-3 space-y-3">
              <p class="text-xs text-gray-500">La IA usará el mueble, el trabajo y las tarifas de costo configuradas.</p>
              <button
                type="button"
                @click="calcularRestauracionForm"
                :disabled="restauracionCalc.calculando || !restauracionItem.nombre_mueble.trim()"
                class="w-full btn-primary text-xs py-2 disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <ArrowPathIcon v-if="restauracionCalc.calculando" class="w-3.5 h-3.5 animate-spin" />
                <SparklesIcon  v-else class="w-3.5 h-3.5" />
                {{ restauracionCalc.calculando ? 'Calculando...' : 'Calcular precio' }}
              </button>

              <div v-if="restauracionCalc.resultado" class="bg-white rounded-lg border border-indigo-100 p-3 space-y-2">
                <div class="flex justify-between items-center text-sm">
                  <span class="text-gray-500">Costo del servicio</span>
                  <span class="font-semibold text-gray-800">${{ (restauracionCalc.resultado.precio_fabricacion ?? 0).toLocaleString('es-CO') }}</span>
                </div>
                <div class="flex justify-between items-center text-sm">
                  <span class="text-gray-500">Precio sugerido al cliente</span>
                  <span class="font-bold text-green-700">${{ (restauracionCalc.resultado.precio_sugerido_venta ?? 0).toLocaleString('es-CO') }}</span>
                </div>

                <details class="text-xs text-gray-500">
                  <summary class="cursor-pointer hover:text-gray-700 select-none">Ver desglose</summary>
                  <div class="mt-2 space-y-2">
                    <div v-if="restauracionCalc.resultado.desglose_materiales?.length">
                      <p class="font-medium text-gray-600 mb-1">Materiales</p>
                      <div v-for="m in restauracionCalc.resultado.desglose_materiales" :key="m.descripcion" class="flex justify-between">
                        <span>{{ m.descripcion }}</span>
                        <span>${{ (m.subtotal ?? 0).toLocaleString('es-CO') }}</span>
                      </div>
                    </div>
                    <div v-if="restauracionCalc.resultado.desglose_mano_obra?.length">
                      <p class="font-medium text-gray-600 mb-1">Mano de obra</p>
                      <div v-for="m in restauracionCalc.resultado.desglose_mano_obra" :key="m.descripcion" class="flex justify-between">
                        <span>{{ m.descripcion }}</span>
                        <span>${{ (m.subtotal ?? 0).toLocaleString('es-CO') }}</span>
                      </div>
                    </div>
                  </div>
                </details>

                <p v-if="restauracionCalc.resultado.notas && !restauracionCalc.resultado.notas.includes('⚠️')" class="text-xs text-amber-600 italic">
                  {{ restauracionCalc.resultado.notas }}
                </p>
                <div v-if="restauracionCalc.resultado.notas?.includes('⚠️')" class="bg-amber-50 border border-amber-300 rounded-lg p-2.5">
                  <p class="text-xs font-semibold text-amber-800 mb-1">Consultar antes de confirmar:</p>
                  <p v-for="l in restauracionCalc.resultado.notas.split('\n').filter(x => x.trim())" :key="l"
                     :class="['text-xs', l.includes('⚠️') ? 'text-amber-700' : 'text-amber-600 italic']">{{ l }}</p>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                  <button type="button" @click="aplicarPrecioRestauracion(restauracionCalc.resultado.precio_fabricacion)" class="btn-secondary text-xs py-1.5">
                    Usar costo
                  </button>
                  <button type="button" @click="aplicarPrecioRestauracion(restauracionCalc.resultado.precio_sugerido_venta)" class="btn-primary text-xs py-1.5">
                    Usar sugerido
                  </button>
                </div>
              </div>
            </div>
          </div>

          <button
            @click="agregarItemRestauracion"
            :disabled="!restauracionItem.nombre_mueble.trim()"
            class="btn-primary w-full text-sm disabled:opacity-40"
          >+ Agregar al carrito</button>
        </div>
      </template>

      <!-- Carrito -->
      <div v-if="items.length" class="space-y-3">
        <p class="text-sm font-semibold text-gray-600">Carrito ({{ items.length }} ítem{{ items.length > 1 ? 's' : '' }})</p>

        <div
          v-for="(item, idx) in items"
          :key="idx"
          class="bg-white rounded-xl shadow-sm p-3 space-y-2"
        >
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <!-- Número de orden de compra -->
              <p class="text-[10px] font-bold text-blue-500 tracking-wide mb-0.5">ÍTEM #{{ idx + 1 }}</p>
              <p class="font-medium text-sm text-gray-800 truncate">{{ item.nombre }}</p>
              <div class="flex flex-wrap items-center gap-1 mt-0.5">
                <span v-if="item._fabricar_pedido"
                  class="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full text-xs font-semibold">
                  🔨 Bajo pedido
                </span>
                <span v-if="item.variante_label"
                  class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded-full text-xs font-medium">
                  <SwatchIcon class="w-3 h-3 inline-block mr-0.5 -mt-0.5" />{{ item.variante_label }}
                </span>
                <span v-if="item.tienda_origen"
                  class="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-medium text-xs">
                  <MapPinIcon class="w-3.5 h-3.5 inline-block mr-0.5 -mt-0.5" />{{ item.tienda_origen }}
                </span>
                <span v-if="!item._fabricar_pedido && !item.variante_label && !item.tienda_origen" class="text-xs text-gray-400">
                  {{ item.categoria }}
                </span>
              </div>
            </div>
            <button @click="quitarItem(idx)" class="text-red-400 hover:text-red-600 ml-2"><XMarkIcon class="w-5 h-5" /></button>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500">Cantidad</label>
              <input
                v-model.number="item.cantidad"
                type="number" min="1"
                :max="item.es_personalizado ? undefined : item.stock_libre"
                class="input text-sm"
              />
            </div>
            <div>
              <label class="text-xs text-gray-500">Precio unitario</label>
              <div v-if="item.es_personalizado && item._cotizarPrecio" class="flex items-center gap-2 h-9 px-3 bg-violet-50 border border-violet-300 rounded-lg">
                <CurrencyDollarIcon class="w-4 h-4 text-violet-500 flex-shrink-0" />
                <span class="text-xs text-violet-700 font-medium">Por definir</span>
              </div>
              <input
                v-else
                v-model.number="item.precio_unitario"
                type="number" min="0"
                :class="['input text-sm', item.es_personalizado && !item.precio_unitario ? 'border-amber-400 bg-amber-50' : '']"
              />
            </div>
          </div>

          <!-- Descuento — disponible para cualquier ítem con precio definido -->
          <div v-if="!item._cotizarPrecio" class="flex items-center gap-2">
            <label class="text-xs text-gray-500 flex-shrink-0">Descuento</label>
            <div class="flex items-center gap-1 flex-1">
              <input
                v-model.number="item._descuento_pct"
                type="number"
                min="0"
                max="99"
                step="1"
                placeholder="0"
                class="w-20 input text-sm text-center"
              />
              <span class="text-xs text-gray-400">%</span>
            </div>
            <div v-if="item._descuento_pct > 0" class="text-xs text-green-700 bg-green-50 px-2 py-1 rounded-lg font-medium flex-shrink-0">
              {{ new Intl.NumberFormat('es-CO').format(precioEfectivo(item)) }} c/u
            </div>
          </div>

          <!-- Toggle consultar precio — para cualquier ítem personalizado -->
          <label
            v-if="item.es_personalizado"
            class="flex items-center gap-2.5 cursor-pointer select-none mt-0.5"
          >
            <div
              @click="item._cotizarPrecio = !item._cotizarPrecio"
              :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0', item._cotizarPrecio ? 'bg-violet-600' : 'bg-gray-300']"
            >
              <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', item._cotizarPrecio ? 'translate-x-5' : 'translate-x-0.5']" />
            </div>
            <span class="text-xs text-gray-600">
              {{ item._cotizarPrecio ? 'Consultar precio al enviar la orden' : 'Ingresar precio manualmente' }}
            </span>
          </label>

          <!-- Advertencia precio vacío — solo si no está en modo cotizar -->
          <p v-if="item.es_personalizado && !item._fabricar_pedido && !item.precio_unitario && !item._cotizarPrecio" class="text-xs text-amber-600 mt-0.5">
            Sin precio — usa el cotizador IA o ingrésalo manualmente
          </p>

          <!-- Personalizado flag — oculto para fabricar bajo pedido -->
          <label v-if="tipoOrden !== 'restauracion' && !item._fabricar_pedido" :class="['flex items-center gap-2 text-sm text-gray-600', item.producto_id === null ? 'opacity-60 cursor-default' : 'cursor-pointer']">
            <input
              type="checkbox"
              :checked="item.es_personalizado"
              @change="togglePersonalizado(item)"
              :disabled="item.producto_id === null"
              class="rounded"
            />
            {{ item.producto_id === null ? 'Producto personalizado (sin catálogo)' : 'Ítem personalizado' }}
          </label>

          <!-- ── Tapizado para fabricar bajo pedido ── -->
          <template v-if="item._fabricar_pedido && item._esTapizado">
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-2">
              <p class="text-xs font-semibold text-amber-800">Selecciona el tapizado <span class="text-red-500">*</span></p>

              <!-- Cascada Marca → Tipo → Color (solo muestra telas con metros disponibles) -->
              <select
                v-model="getTelaSelection(item, 'tela').marca"
                @change="getTelaSelection(item, 'tela').tipo = ''; getTelaSelection(item, 'tela').color = ''"
                class="input text-sm"
              >
                <option value="">— elige la marca de tela —</option>
                <option v-for="m in marcasConStock()" :key="m" :value="m">{{ m }}</option>
              </select>

              <template v-if="getTelaSelection(item, 'tela').marca && getTelaSelection(item, 'tela').marca !== 'Otro'">
                <select
                  v-model="getTelaSelection(item, 'tela').tipo"
                  @change="getTelaSelection(item, 'tela').color = ''"
                  class="input text-sm"
                >
                  <option value="">— tipo de tela —</option>
                  <option v-for="t in tiposConStock(getTelaSelection(item, 'tela').marca)" :key="t" :value="t">{{ t }}</option>
                </select>

                <template v-if="getTelaSelection(item, 'tela').tipo">
                  <select v-model="getTelaSelection(item, 'tela').color" class="input text-sm">
                    <option value="">— color —</option>
                    <option v-for="c in coloresConStock(getTelaSelection(item, 'tela').marca, getTelaSelection(item, 'tela').tipo)" :key="c" :value="c">{{ c }}</option>
                  </select>
                </template>
              </template>

              <p v-if="telaResumidaCampo(item, 'tela')" class="text-xs font-semibold text-amber-700">
                ✓ Tela: {{ telaResumidaCampo(item, 'tela') }}
              </p>
              <p v-else-if="Object.keys(telaMetrosMap.value).length && !marcasConStock().length" class="text-xs text-red-600 italic">
                No hay telas con metros disponibles en este momento.
              </p>
              <p v-else class="text-xs text-amber-600 italic">Selecciona la tela para que producción sepa cuál usar</p>
            </div>
          </template>

          <!-- ── Personalización de producto del CATÁLOGO: solo tela + tamaño ── -->
          <template v-if="item.es_personalizado && item.producto_id && !item._fabricar_pedido">
            <div class="bg-purple-50 border border-purple-200 rounded-xl p-3 space-y-3">
              <div>
                <p class="text-xs font-semibold text-purple-700">¿Qué deseas cambiar?</p>
                <p v-if="item.variante_label" class="text-xs text-gray-400 mt-0.5">
                  Variante actual: <span class="font-medium text-gray-600">{{ item.variante_label }}</span>
                </p>
              </div>

              <!-- Tela — cascada Marca → Tipo → Color -->
              <div class="space-y-1">
                <label class="text-xs text-gray-500">Nueva tela <span class="text-gray-300">— opcional</span></label>

                <!-- 1. Marca -->
                <select
                  v-model="getTelaSelection(item, 'tela').marca"
                  @change="getTelaSelection(item, 'tela').tipo = ''; getTelaSelection(item, 'tela').color = ''"
                  class="input text-sm"
                >
                  <option value="">— sin cambio de tela —</option>
                  <option v-for="m in marcasConStock()" :key="m" :value="m">{{ m }}</option>
                </select>

                <!-- 2. Tipo de tela (cuando hay marca) -->
                <template v-if="getTelaSelection(item, 'tela').marca">
                  <select
                    v-model="getTelaSelection(item, 'tela').tipo"
                    @change="getTelaSelection(item, 'tela').color = ''"
                    class="input text-sm"
                  >
                    <option value="">— tipo de tela —</option>
                    <option v-for="t in tiposConStock(getTelaSelection(item, 'tela').marca)" :key="t" :value="t">{{ t }}</option>
                  </select>

                  <!-- 3. Color (cuando hay tipo) -->
                  <template v-if="getTelaSelection(item, 'tela').tipo">
                    <select v-model="getTelaSelection(item, 'tela').color" class="input text-sm">
                      <option value="">— color —</option>
                      <option v-for="c in coloresConStock(getTelaSelection(item, 'tela').marca, getTelaSelection(item, 'tela').tipo)" :key="c" :value="c">{{ c }}</option>
                    </select>
                  </template>
                </template>

                <!-- Preview tela elegida -->
                <p v-if="telaResumidaCampo(item, 'tela')" class="text-xs text-purple-700 font-semibold">
                  Tela: {{ telaResumidaCampo(item, 'tela') }}
                </p>
              </div>

              <!-- Tamaño -->
              <div>
                <label class="text-xs text-gray-500">Nuevo tamaño <span class="text-gray-300">— opcional</span></label>
                <div class="grid grid-cols-3 gap-2 mt-0.5">
                  <div>
                    <label class="text-xs text-gray-400">Largo (cm)</label>
                    <input v-model.number="item.specs.largo_cm" type="number" min="1" placeholder="ej: 220" class="input text-sm" />
                  </div>
                  <div>
                    <label class="text-xs text-gray-400">Ancho (cm)</label>
                    <input v-model.number="item.specs.ancho_cm" type="number" min="1" placeholder="ej: 95" class="input text-sm" />
                  </div>
                  <div>
                    <label class="text-xs text-gray-400">Alto (cm)</label>
                    <input v-model.number="item.specs.alto_cm" type="number" min="1" placeholder="ej: 88" class="input text-sm" />
                  </div>
                </div>
              </div>

              <!-- Notas -->
              <textarea
                v-model="item.specs_notas"
                placeholder="Notas adicionales (opcional)"
                rows="2"
                class="input text-sm resize-none"
              />
            </div>
          </template>

          <!-- ── Personalización de producto NUEVO (sin catálogo): form completo ── -->
          <template v-else-if="item.es_personalizado && !item.producto_id && tipoOrden !== 'restauracion'">
            <div class="space-y-2">
              <p class="text-xs font-semibold text-purple-700">
                Especificaciones — {{ getTemplate(item.categoria).titulo }}
              </p>
              <div class="grid grid-cols-2 gap-2">
                <template v-for="campo in getTemplate(item.categoria).campos" :key="campo.key">
                  <div :class="campo.type === 'text' || campo.useVariantes ? 'col-span-2' : ''">
                    <label class="text-xs text-gray-500">
                      {{ campo.label }}{{ campo.unit ? ' (' + campo.unit + ')' : '' }}
                    </label>
                    <!-- Tela: cascada Marca → Tipo → Color (solo telas con metros disponibles) -->
                    <template v-if="campo.useVariantes">
                      <select
                        v-model="getTelaSelection(item, campo.key).marca"
                        @change="getTelaSelection(item, campo.key).tipo = ''; getTelaSelection(item, campo.key).color = ''"
                        class="input text-sm"
                      >
                        <option value="">— seleccionar marca —</option>
                        <option v-for="m in marcasConStock()" :key="m" :value="m">{{ m }}</option>
                      </select>
                      <template v-if="getTelaSelection(item, campo.key).marca">
                        <select
                          v-model="getTelaSelection(item, campo.key).tipo"
                          @change="getTelaSelection(item, campo.key).color = ''"
                          class="input text-sm mt-1"
                        >
                          <option value="">— tipo de tela —</option>
                          <option v-for="t in tiposConStock(getTelaSelection(item, campo.key).marca)" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <template v-if="getTelaSelection(item, campo.key).tipo">
                          <select v-model="getTelaSelection(item, campo.key).color" class="input text-sm mt-1">
                            <option value="">— color —</option>
                            <option v-for="c in coloresConStock(getTelaSelection(item, campo.key).marca, getTelaSelection(item, campo.key).tipo)" :key="c" :value="c">{{ c }}</option>
                          </select>
                        </template>
                      </template>
                      <p v-if="telaResumidaCampo(item, campo.key)" class="text-xs text-purple-600 font-medium mt-1">
                        {{ campo.label }}: {{ telaResumidaCampo(item, campo.key) }}
                      </p>
                    </template>
                    <!-- Select normal -->
                    <select v-else-if="campo.type === 'select'" v-model="item.specs[campo.key]" class="input text-sm">
                      <option value="">— seleccionar —</option>
                      <option v-for="opt in campo.options" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                    <!-- Text / Number -->
                    <input
                      v-else
                      v-model="item.specs[campo.key]"
                      :type="campo.type"
                      :placeholder="campo.placeholder"
                      :min="campo.type === 'number' ? 1 : undefined"
                      class="input text-sm"
                    />
                  </div>
                </template>
              </div>
              <textarea v-model="item.specs_notas" placeholder="Notas adicionales (opcional)" rows="2" class="input text-sm resize-none" />
            </div>
          </template>

          <!-- Boceto (venta) / Fotos (restauración) -->
          <template v-if="item.es_personalizado">

            <!-- Modo venta: boceto + fotos adicionales -->
            <div v-if="tipoOrden !== 'restauracion'" class="space-y-1.5">
              <p class="text-xs font-medium text-purple-700">
                Boceto / Fotos
                <span class="text-gray-400 font-normal">(opcional)</span>
              </p>

              <!-- Grid de fotos cuando hay al menos una -->
              <div v-if="item.boceto_previews.length" class="grid grid-cols-3 gap-1.5">
                <div
                  v-for="(preview, fi) in item.boceto_previews"
                  :key="fi"
                  class="relative aspect-square"
                >
                  <img
                    :src="preview"
                    class="w-full h-full rounded-lg border-2 border-purple-200 object-cover bg-white"
                  />
                  <button
                    type="button"
                    @click="onQuitarFotoItem(item, fi)"
                    class="absolute top-1 right-1 bg-white rounded-full p-0.5 shadow text-red-400 hover:text-red-600"
                  ><XMarkIcon class="w-3.5 h-3.5" /></button>
                </div>
              </div>

              <!-- Canvas de boceto cuando no hay foto en el slot 0 -->
              <BocetoCanvas
                v-if="!item.boceto_previews[0]"
                :modelValue="item.boceto_blobs[0] ?? null"
                @update:modelValue="onBocetoUpdate(item, $event)"
              />

              <!-- Agregar fotos -->
              <label class="flex items-center justify-center gap-1.5 border border-dashed border-purple-200 rounded-lg py-2 text-xs text-purple-500 cursor-pointer hover:border-purple-400 hover:text-purple-700 transition-colors">
                <PhotoIcon class="w-4 h-4" />
                {{ item.boceto_previews.length ? 'Agregar otra foto' : 'Subir foto' }}
                <input type="file" accept="image/*" multiple class="hidden" @change="onAgregarFotosItem(item, $event)" />
              </label>
            </div>

            <!-- Modo restauración: fotos múltiples -->
            <div v-else class="space-y-1.5">
              <div
                v-if="item.specs?.descripcion_trabajo"
                class="text-xs text-indigo-700 font-medium bg-indigo-50 rounded-lg px-3 py-2"
              >
                Trabajo: {{ item.specs.descripcion_trabajo }}
              </div>
              <p class="text-xs font-medium text-gray-600">
                Fotos del mueble <span class="font-normal text-gray-400">(opcional)</span>
              </p>

              <!-- Grid con fotos + botón agregar inline -->
              <div v-if="item.boceto_previews.length" class="grid grid-cols-3 gap-1.5">
                <div
                  v-for="(preview, fi) in item.boceto_previews"
                  :key="fi"
                  class="relative aspect-square"
                >
                  <img :src="preview" class="w-full h-full rounded-xl object-cover border border-gray-200" />
                  <button
                    type="button"
                    @click="onQuitarFotoItem(item, fi)"
                    class="absolute top-1 right-1 bg-white rounded-full p-0.5 shadow text-red-400"
                  ><XMarkIcon class="w-3.5 h-3.5" /></button>
                </div>
                <label class="flex flex-col items-center justify-center aspect-square border-2 border-dashed border-gray-200 rounded-xl text-gray-400 cursor-pointer hover:border-indigo-300 hover:text-indigo-500 transition-colors">
                  <PhotoIcon class="w-5 h-5" />
                  <span class="text-xs mt-0.5">Agregar</span>
                  <input type="file" accept="image/*" multiple class="hidden" @change="onAgregarFotosItem(item, $event)" />
                </label>
              </div>

              <!-- Estado vacío -->
              <label
                v-else
                class="flex items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-xl py-5 text-sm text-gray-400 cursor-pointer hover:border-indigo-300 hover:text-indigo-500 transition-colors"
              >
                <PhotoIcon class="w-5 h-5" />
                Seleccionar fotos
                <input type="file" accept="image/*" multiple class="hidden" @change="onAgregarFotosItem(item, $event)" />
              </label>
            </div>

          </template>

          <!-- Cotizador de precio con IA -->
          <div v-if="item.es_personalizado">
            <button
              type="button"
              @click="item._mostrarCalculadora = !item._mostrarCalculadora"
              :class="['flex items-center gap-1.5 text-xs font-medium transition-colors',
                tipoOrden === 'restauracion'
                  ? 'text-indigo-600 hover:text-indigo-800'
                  : 'text-purple-600 hover:text-purple-800']"
            >
              <SparklesIcon class="w-3.5 h-3.5" />
              {{ item._mostrarCalculadora ? 'Ocultar cotizador' : 'Calcular precio con IA' }}
            </button>

            <div v-if="item._mostrarCalculadora"
              :class="['mt-2 rounded-xl p-3 space-y-3 border',
                tipoOrden === 'restauracion'
                  ? 'bg-indigo-50 border-indigo-200'
                  : 'bg-purple-50 border-purple-200']"
            >
              <p class="text-xs text-gray-500">
                {{ tipoOrden === 'restauracion'
                  ? 'La IA estima el costo de restauración basada en el trabajo, la foto y las tarifas configuradas.'
                  : 'El cotizador usa las especificaciones y medidas que ingresaste arriba.' }}
              </p>

              <!-- Precio de referencia — para productos únicos o complejos -->
              <div v-if="tipoOrden !== 'restauracion'">
                <label class="block text-xs font-medium text-gray-500 mb-1">
                  Precio de referencia <span class="font-normal text-gray-400">(opcional — si sabes cuánto costó uno similar)</span>
                </label>
                <input
                  v-model.number="item._precioReferencia"
                  type="number"
                  min="0"
                  placeholder="ej: 11000000"
                  class="w-full rounded-lg border border-purple-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-400"
                />
              </div>

              <button
                type="button"
                @click="calcularPrecioIA(item)"
                :disabled="item._calculandoPrecio"
                class="w-full btn-primary text-xs py-2 disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <ArrowPathIcon v-if="item._calculandoPrecio" class="w-3.5 h-3.5 animate-spin" />
                <SparklesIcon  v-else class="w-3.5 h-3.5" />
                {{ item._calculandoPrecio ? 'Calculando...' : 'Calcular precio' }}
              </button>

              <!-- Error del cotizador -->
              <div v-if="item._precioCalc?.ok === false" class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs text-red-700">
                {{ item._precioCalc.error || 'No se pudo calcular. Agrega más detalles del trabajo.' }}
              </div>

              <!-- Resultado -->
              <div v-else-if="item._precioCalc?.precio_fabricacion" class="bg-white rounded-lg border border-purple-100 p-3 space-y-2">
                <div class="flex justify-between items-center text-sm">
                  <span class="text-gray-500">Costo fabricación</span>
                  <span class="font-semibold text-gray-800">${{ (item._precioCalc.precio_fabricacion ?? 0).toLocaleString('es-CO') }}</span>
                </div>
                <div class="flex justify-between items-center text-sm">
                  <span class="text-gray-500">Precio venta sugerido</span>
                  <span class="font-bold text-green-700">${{ (item._precioCalc.precio_sugerido_venta ?? 0).toLocaleString('es-CO') }}</span>
                </div>

                <details class="text-xs text-gray-500">
                  <summary class="cursor-pointer hover:text-gray-700 select-none">Ver desglose</summary>
                  <div class="mt-2 space-y-2">
                    <div v-if="item._precioCalc.desglose_materiales?.length">
                      <p class="font-medium text-gray-600 mb-1">Materiales</p>
                      <div v-for="m in item._precioCalc.desglose_materiales" :key="m.descripcion" class="flex justify-between">
                        <span>{{ m.descripcion }}</span>
                        <span>${{ (m.subtotal ?? 0).toLocaleString('es-CO') }}</span>
                      </div>
                    </div>
                    <div v-if="item._precioCalc.desglose_mano_obra?.length">
                      <p class="font-medium text-gray-600 mb-1">Mano de obra</p>
                      <div v-for="m in item._precioCalc.desglose_mano_obra" :key="m.descripcion" class="flex justify-between">
                        <span>{{ m.descripcion }}</span>
                        <span>${{ (m.subtotal ?? 0).toLocaleString('es-CO') }}</span>
                      </div>
                    </div>
                  </div>
                </details>

                <!-- Notas normales -->
                <p v-if="item._precioCalc.notas && !item._precioCalc.notas.includes('⚠️')" class="text-xs text-amber-600 italic">
                  {{ item._precioCalc.notas }}
                </p>
                <!-- Notas con consultas pendientes: más prominente -->
                <div v-if="item._precioCalc.notas && item._precioCalc.notas.includes('⚠️')" class="bg-amber-50 border border-amber-300 rounded-lg p-2.5 space-y-1">
                  <p class="text-xs font-semibold text-amber-800">Consultar antes de confirmar precio:</p>
                  <template v-for="linea in item._precioCalc.notas.split('\n').filter(l => l.trim())" :key="linea">
                    <p v-if="linea.includes('⚠️')" class="text-xs text-amber-700">{{ linea }}</p>
                    <p v-else class="text-xs text-amber-600 italic">{{ linea }}</p>
                  </template>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                  <button type="button" @click="aplicarPrecio(item, item._precioCalc.precio_fabricacion)" class="btn-secondary text-xs py-1.5">
                    {{ tipoOrden === 'restauracion' ? 'Usar costo' : 'Usar fabricación' }}
                  </button>
                  <button type="button" @click="aplicarPrecio(item, item._precioCalc.precio_sugerido_venta)" class="btn-primary text-xs py-1.5">
                    Usar sugerido
                  </button>
                </div>
              </div>
            </div>
          </div>

          <p class="text-xs text-right text-gray-500">
            Subtotal: <strong class="text-gray-800">
              ${{ (item.cantidad * item.precio_unitario).toLocaleString('es-CO') }}
            </strong>
          </p>
        </div>

        <!-- Total -->
        <div class="bg-blue-50 rounded-xl px-4 py-3 flex justify-between items-center">
          <span class="font-semibold text-gray-700">Total</span>
          <span class="text-lg font-bold text-blue-700">${{ valorTotal.toLocaleString('es-CO') }}</span>
        </div>
      </div>

      <div v-else class="text-center py-6 text-gray-400 text-sm">
        {{ tipoOrden === 'restauracion' ? 'Agrega los muebles arriba.' : 'Busca y agrega productos al carrito.' }}
      </div>

      <div class="flex gap-2">
        <button
          @click="submitBorrador"
          :disabled="items.length === 0 || submitting"
          class="btn-secondary flex-1"
        >{{ submitting && modoGuardarBorrador ? 'Guardando...' : 'Guardar borrador' }}</button>
        <button
          @click="irAPaso3"
          :disabled="items.length === 0"
          class="btn-primary flex-1"
        >Continuar → Pago</button>
      </div>
    </template>

    <!-- ═══════════════════════════════════════════════════════ PASO 3 ══ -->
    <template v-else-if="step === 3">


      <!-- Resumen de orden -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-1">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Resumen</p>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Cliente</span>
          <span class="font-medium text-gray-800">{{ clienteSeleccionado.nombre }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Tienda</span>
          <span class="font-medium text-gray-800">{{ tiendas.find(t => t.id == tiendaId)?.nombre }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Ítems</span>
          <span class="font-medium text-gray-800">{{ items.length }}</span>
        </div>
        <div class="flex justify-between text-sm font-bold border-t border-gray-100 pt-2 mt-2">
          <span>Total</span>
          <span class="text-blue-700">${{ valorTotal.toLocaleString('es-CO') }}</span>
        </div>
      </div>

      <!-- Anticipo — oculto cuando hay ítems con cotización pendiente -->
      <template v-if="!hayItemsCotizar">
        <div>
          <label class="label">Porcentaje mínimo anticipo</label>
          <div class="flex gap-2">
            <button v-for="pct in [30, 50, 70, 100]" :key="pct"
              @click="anticipo_pct = pct; anticipo_monto = minimoAnticipo"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                anticipo_pct === pct
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white text-gray-700 border-gray-300']"
            >{{ pct }}%</button>
          </div>
        </div>

        <div>
          <label class="label">
            Monto anticipo
            <span class="text-gray-400 font-normal ml-1">(mínimo ${{ minimoAnticipo.toLocaleString('es-CO') }})</span>
          </label>
          <input
            v-model.number="anticipo_monto"
            type="number"
            :min="minimoAnticipo"
            class="input"
          />
        </div>

        <div>
          <label class="label">Método de pago</label>
          <div class="flex gap-2 flex-wrap">
            <button
              v-for="m in metodosOpts"
              :key="m.value"
              @click="anticipo_metodo = m.value"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                anticipo_metodo === m.value
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white text-gray-700 border-gray-300']"
            >{{ m.label }}</button>
          </div>
        </div>

        <div v-if="anticipo_metodo !== 'efectivo'">
          <label class="label">Referencia / número transacción</label>
          <input v-model="anticipo_referencia" class="input" placeholder="Opcional" />
        </div>
      </template>

      <!-- Aviso anticipo cuando hay cotización pendiente -->
      <div v-else class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex items-start gap-3">
        <ExclamationTriangleIcon class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" />
        <div class="text-sm text-amber-800 space-y-1">
          <p class="font-semibold">Anticipo pendiente</p>
          <p class="text-xs text-amber-700">
            La orden quedará guardada en <strong>pendiente</strong> hasta que se confirme el precio.
            Podrás registrar el anticipo desde el detalle de la orden cuando el cliente acepte.
          </p>
        </div>
      </div>

      <!-- Notas -->
      <div>
        <label class="label">Notas (opcional)</label>
        <textarea v-model="notas" rows="2" class="input resize-none" placeholder="Observaciones de la orden..." />
      </div>

      <!-- Dirección de envío -->
      <DireccionColombia
        v-model:departamento="departamentoEnvio"
        v-model:ciudad="ciudadEnvio"
        v-model:direccion="direccionEnvio"
      />

      <!-- Foto del anexo firmado — solo cuando la compra es presencial -->
      <div v-if="canal === 'fisica'">
        <label class="label">
          Foto del anexo firmado
          <span class="text-xs font-normal text-gray-400 ml-1">(opcional)</span>
        </label>
        <p class="text-xs text-gray-400 mb-2">Sube la foto del documento firmado por el cliente en la tienda.</p>

        <div v-if="anexoFotoFile" class="space-y-2">
          <div class="relative">
            <img
              :src="anexoFotoUrl || anexoFotoPreview"
              alt="Vista previa anexo"
              class="w-full rounded-xl border-2 border-gray-200 object-contain bg-gray-50"
              style="max-height: 200px;"
            />
            <button
              @click="anexoFotoFile = null; anexoFotoUrl = ''; anexoFotoPreview = ''"
              class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow-lg"
            >
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>
          <p class="text-xs text-gray-400 truncate">{{ anexoFotoFile.name }}</p>
          <p v-if="subiendoAnexo" class="text-xs text-blue-600">Subiendo imagen...</p>
        </div>
        <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-gray-300 rounded-xl p-5 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
          <PhotoIcon class="w-7 h-7 text-gray-300" />
          <span class="text-sm text-gray-500">Toca para adjuntar foto del anexo</span>
          <span class="text-xs text-gray-400">JPG, PNG — máx 5 MB</span>
          <input
            type="file"
            accept="image/*"
            @change="e => { anexoFotoFile = e.target.files[0] }"
            class="hidden"
          />
        </label>
      </div>

      <!-- Foto del comprobante -->
      <div>
        <label class="label">
          Foto del comprobante
          <span class="text-red-500 ml-0.5">*</span>
        </label>
        <div v-if="facturaFotoFile" class="space-y-2">
          <div class="relative">
            <img
              :src="facturaFotoUrl || facturaFotoPreview"
              alt="Vista previa comprobante"
              class="w-full rounded-xl border-2 border-gray-200 object-contain bg-gray-50"
              style="max-height: 240px;"
            />
            <button
              @click="removeFacturaFoto"
              class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow-lg"
            >
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>
          <p class="text-xs text-gray-400 truncate">{{ facturaFotoFile.name }}</p>
          <p v-if="subiendoFactura" class="text-xs text-blue-600">Subiendo imagen...</p>
        </div>
        <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-amber-300 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
          <PhotoIcon class="w-8 h-8 text-amber-300" />
          <span class="text-sm text-gray-500">Toca para adjuntar foto del comprobante</span>
          <span class="text-xs text-gray-400">JPG, PNG — máx 5 MB</span>
          <input
            type="file"
            accept="image/*"
            @change="onFacturaFotoChange"
            class="hidden"
          />
        </label>
        <p v-if="!facturaFotoFile" class="text-xs text-amber-600 flex items-center gap-1 mt-1">
          <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 inline-block" />
          Se requiere foto del comprobante para crear la orden
        </p>
      </div>

      <!-- Cotización de costo — visible solo si hay ítems sin precio -->
      <div v-if="hayItemsCotizar" class="bg-violet-50 border border-violet-200 rounded-xl p-4 space-y-3">
        <div class="flex items-center gap-2">
          <CurrencyDollarIcon class="w-4 h-4 text-violet-600" />
          <p class="text-sm font-semibold text-violet-800">Consulta de costo pendiente</p>
        </div>

        <div class="space-y-1">
          <p class="text-xs text-violet-600">Ítems sin precio que serán cotizados:</p>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="item in items.filter(i => i._cotizarPrecio)"
              :key="item.nombre"
              class="inline-flex items-center gap-1 text-xs bg-white text-violet-700 px-2 py-0.5 rounded-full border border-violet-200 font-medium"
            >
              {{ item.nombre }}
            </span>
          </div>
        </div>

        <div class="space-y-1.5">
          <label class="block text-xs font-semibold text-gray-700">Enviar consulta a <span class="text-red-500">*</span></label>
          <div v-if="cargandoReceptores" class="text-xs text-gray-400">Cargando...</div>
          <div v-else class="space-y-2">
            <label
              v-for="r in receptoresCotizar"
              :key="r.id"
              :class="[
                'flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-colors bg-white',
                cotizarReceptorId === r.id ? 'border-violet-500 bg-violet-50' : 'border-gray-200 hover:border-violet-300'
              ]"
            >
              <input type="radio" :value="r.id" v-model="cotizarReceptorId" class="accent-violet-600" />
              <div>
                <p class="text-sm font-semibold text-gray-800">{{ r.nombre }}</p>
                <p class="text-xs text-gray-400 capitalize">{{ r.rol }}</p>
              </div>
            </label>
            <p v-if="!receptoresCotizar.length" class="text-xs text-gray-400">No hay supervisores ni ebanistas activos.</p>
          </div>
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-gray-700">Notas para el cotizador (opcional)</label>
          <textarea
            v-model="cotizarNotas"
            rows="2"
            placeholder="Materiales específicos, urgencia, referencias..."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-violet-400 resize-none"
          />
        </div>
      </div>

      <!-- Firma del cliente — solo cuando ya se conoce el precio -->
      <div v-if="!hayItemsCotizar">
        <label class="label">
          Firma del cliente
          <span class="text-red-500 ml-0.5">*</span>
        </label>
        <FirmaCanvas v-model="firmaBlob" />
        <p v-if="!firmaBlob" class="text-xs text-amber-600 flex items-center gap-1 mt-1">
          <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 inline-block mr-1" />Se requiere la firma del cliente para confirmar la orden
        </p>
      </div>

      <!-- Aviso firma cuando hay cotización pendiente -->
      <div v-else class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 flex items-start gap-3">
        <ExclamationTriangleIcon class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5" />
        <p class="text-xs text-gray-500">
          La firma se recogerá cuando el cliente confirme el precio definitivo.
        </p>
      </div>

       <button
         @click="submit"
         :disabled="submitting || subiendoFactura || cooldown > 0 || anticipo_monto < minimoAnticipofEfectivo || (!hayItemsCotizar && !firmaBlob) || !facturaFotoFile"
         class="btn-primary w-full text-base py-3 flex items-center justify-center gap-2"
       >
         <ArrowPathOutlineIcon v-if="submitting && !modoGuardarBorrador" class="w-5 h-5 animate-spin" />
         {{ subiendoFactura ? 'Subiendo foto...' : (submitting && !modoGuardarBorrador) ? 'Guardando...' : cooldown > 0 ? `Reintentar en ${cooldown}s...` : 'Crear orden' }}
       </button>

       <button
         @click="submitBorrador"
         :disabled="submitting || cooldown > 0"
         class="btn-secondary w-full py-3 flex items-center justify-center gap-2"
       >
         <ArrowPathOutlineIcon v-if="submitting && modoGuardarBorrador" class="w-5 h-5 animate-spin" />
         {{ (submitting && modoGuardarBorrador) ? 'Guardando borrador...' : 'Guardar como borrador' }}
       </button>
    </template>

  </div>

  <!-- Modal picker de variante -->
  <Transition name="fade">
    <div v-if="mostrarVariantePicker" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="mostrarVariantePicker = false">
      <div class="absolute inset-0 bg-black/50" @click="mostrarVariantePicker = false" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-base font-bold text-gray-800">Seleccionar variante</h3>
            <p class="text-xs text-gray-500 mt-0.5 truncate">{{ productoParaVariante?.nombre }}</p>
          </div>
          <button @click="mostrarVariantePicker = false" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="cargandoVariantes" class="text-center py-6 text-gray-400 text-sm">Cargando variantes...</div>

        <div v-else class="space-y-2">
          <!-- Opción sin variante (solo para tapizado, no para tallas) -->
          <button
            v-if="!productoParaVariante?.tiene_tallas"
            @click="varianteSeleccionada = null"
            :class="['w-full text-left px-3 py-2.5 rounded-xl border text-sm transition-colors',
              varianteSeleccionada === null
                ? 'border-blue-500 bg-blue-50 text-blue-700 font-medium'
                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50']"
          >
            Sin especificar tela
            <span class="text-xs text-gray-400 ml-1">(stock base: {{ stockLibre(productoParaVariante) }})</span>
          </button>

          <!-- Variantes disponibles -->
          <button
            v-for="v in variantesDisponibles"
            :key="v._config_id ? 'c' + v._config_id + '-v' + v.id : 'var-' + v.id"
            @click="varianteSeleccionada = v"
            :disabled="!productoParaVariante?.tiene_tallas && !v.personalizable && v.stock_libre <= 0"
            :class="['w-full text-left px-3 py-2.5 rounded-xl border text-sm transition-colors',
              (varianteSeleccionada?._config_id ? (varianteSeleccionada._config_id === v._config_id && varianteSeleccionada.id === v.id) : varianteSeleccionada?.id === v.id && !v._config_id)
                ? 'border-blue-500 bg-blue-50 text-blue-700 font-medium'
                : (productoParaVariante?.tiene_tallas || v.stock_libre > 0)
                  ? 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                  : 'border-gray-100 bg-gray-50 text-gray-300 cursor-not-allowed']"
          >
            <template v-if="v.medida">
              <span class="font-medium">{{ v.medida }}</span>
              <span v-if="v.precio_variante" class="text-xs ml-2 font-semibold text-blue-600">
                ${{ Number(v.precio_variante).toLocaleString('es-CO') }}
              </span>
            </template>
            <template v-else>
              <template v-if="v.marca">
                <span class="text-xs text-gray-400">{{ v.marca }}</span>
                <span class="text-gray-300 mx-1">·</span>
              </template>
              <span class="font-medium">{{ v.marca_tela }}</span>
              <span class="text-gray-400 mx-1">·</span>
              {{ v.nombre_color }}
              <template v-if="v._config_label">
                <span class="text-indigo-400 mx-1">·</span>
                <span class="text-indigo-600 font-semibold">{{ v._config_label }}</span>
              </template>
              <span v-if="v.precio_variante" class="text-xs ml-2 font-semibold text-blue-600">
                ${{ Number(v.precio_variante).toLocaleString('es-CO') }}
              </span>
            </template>
            <span :class="['text-xs ml-2 font-semibold', v.stock_libre > 0 ? 'text-green-600' : productoParaVariante?.tiene_tallas ? 'text-gray-400' : 'text-red-400']">
              {{ v.stock_libre > 0 ? `${v.stock_libre} disponible${v.stock_libre > 1 ? 's' : ''}` : 'Sin stock' }}
            </span>
          </button>

          <p v-if="!variantesDisponibles.length" class="text-xs text-gray-400 text-center py-2">
            No hay variantes registradas para esta tienda.
          </p>
        </div>

        <button
          @click="confirmarVariante"
          :disabled="!!(productoParaVariante?.tiene_tallas && !varianteSeleccionada)"
          class="w-full bg-blue-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
        >
          Agregar al carrito
        </button>
      </div>
    </div>
  </Transition>

  <!-- Modal picker variante FÁBRICA (tapizado) -->
  <Transition name="fade">
    <!-- ── Picker variantes personalizadas (custom) ── -->
    <div v-if="mostrarVCPicker" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="mostrarVCPicker = false">
      <div class="absolute inset-0 bg-black/50" @click="mostrarVCPicker = false" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[80vh] overflow-y-auto">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-base font-bold text-gray-800">Selecciona variantes</h3>
            <p class="text-xs text-indigo-600 mt-0.5 truncate">{{ vcPickerProd?.nombre }}</p>
          </div>
          <button @click="mostrarVCPicker = false" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="vcPickerCargando" class="text-center py-6 text-gray-400 text-sm">Cargando variantes...</div>

        <template v-else>
          <div v-for="grupo in vcPickerGrupos" :key="grupo.tipo_variante_id" class="space-y-2">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">{{ grupo.tipo.nombre }}</p>
            <div class="space-y-1.5">
              <button
                v-for="opt in grupo.items"
                :key="opt.id"
                :disabled="(opt.stock_disponible ?? 0) === 0"
                @click="vcPickerSelec = { ...vcPickerSelec, [grupo.tipo_variante_id]: { config_id: opt.id, opcion_nombre: opt.opcion_nombre, tipo_nombre: grupo.tipo.nombre, precio_adicional: opt.precio_adicional ?? 0, stock: opt.stock_disponible ?? 0 } }"
                :class="['w-full text-left px-3 py-2.5 rounded-xl border text-sm transition-colors',
                  vcPickerSelec[grupo.tipo_variante_id]?.config_id === opt.id
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700 font-medium'
                    : (opt.stock_disponible ?? 0) > 0
                      ? 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                      : 'border-gray-100 bg-gray-50 text-gray-300 cursor-not-allowed']"
              >
                <span class="font-medium">{{ opt.opcion_nombre }}</span>
                <span v-if="(opt.precio_adicional ?? 0) > 0" class="text-xs ml-2 text-indigo-600 font-semibold">
                  ${{ Number(opt.precio_adicional).toLocaleString('es-CO') }}
                </span>
                <span class="text-xs ml-2 font-semibold" :class="(opt.stock_disponible ?? 0) > 0 ? 'text-green-600' : 'text-red-400'">
                  {{ opt.stock_disponible ?? 0 }} disp.
                </span>
              </button>
            </div>
          </div>
        </template>

        <button
          @click="confirmarVCPickerOrden"
          :disabled="!vcPickerValido"
          class="w-full bg-indigo-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-indigo-700 disabled:opacity-40"
        >
          Agregar al pedido
        </button>
      </div>
    </div>
  </Transition>

  <Transition name="fade">
    <div v-if="mostrarFabricaVariantePicker" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="mostrarFabricaVariantePicker = false">
      <div class="absolute inset-0 bg-black/50" @click="mostrarFabricaVariantePicker = false" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-base font-bold text-gray-800">Variante de fábrica</h3>
            <p class="text-xs text-purple-600 mt-0.5 truncate">{{ fabricaVariantesProd?.nombre }}</p>
          </div>
          <button @click="mostrarFabricaVariantePicker = false" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="cargandoFabricaVariantes" class="text-center py-6 text-gray-400 text-sm">Cargando variantes...</div>

        <div v-else class="space-y-2">
          <button
            v-for="v in fabricaVariantesDisponibles"
            :key="v._config_id ? 'c' + v._config_id + '-v' + v.id : 'var-' + v.id"
            @click="fabricaVarianteSeleccionada = v"
            :class="['w-full text-left px-3 py-2.5 rounded-xl border text-sm transition-colors',
              (fabricaVarianteSeleccionada?._config_id ? (fabricaVarianteSeleccionada._config_id === v._config_id && fabricaVarianteSeleccionada.id === v.id) : fabricaVarianteSeleccionada?.id === v.id && !v._config_id)
                ? 'border-purple-500 bg-purple-50 text-purple-700 font-medium'
                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50']"
          >
            <template v-if="v.medida">
              <span class="font-medium">{{ v.medida }}</span>
              <span v-if="v.precio_variante" class="text-xs ml-2 font-semibold text-blue-600">
                ${{ Number(v.precio_variante).toLocaleString('es-CO') }}
              </span>
            </template>
            <template v-else>
              <template v-if="v.marca">
                <span class="text-xs text-gray-400">{{ v.marca }}</span>
                <span class="text-gray-300 mx-1">·</span>
              </template>
              <span class="font-medium">{{ v.marca_tela }}</span>
              <span class="text-gray-400 mx-1">·</span>
              {{ v.nombre_color }}
              <template v-if="v._config_label">
                <span class="text-indigo-400 mx-1">·</span>
                <span class="text-indigo-600 font-semibold">{{ v._config_label }}</span>
              </template>
              <span v-if="v.precio_variante" class="text-xs ml-2 font-semibold text-blue-600">
                ${{ Number(v.precio_variante).toLocaleString('es-CO') }}
              </span>
            </template>
            <span :class="['text-xs ml-2 font-semibold', v.stock_libre > 0 ? 'text-green-600' : 'text-red-400']">
              {{ v.stock_libre > 0 ? `${v.stock_libre} en fábrica` : 'Sin stock' }}
            </span>
          </button>

          <p v-if="!fabricaVariantesDisponibles.length" class="text-xs text-gray-400 text-center py-2">
            No hay variantes con stock en fábrica para este producto.
          </p>
        </div>

        <button
          @click="confirmarFabricaVariante"
          :disabled="!fabricaVarianteSeleccionada"
          class="w-full bg-purple-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-purple-700 disabled:opacity-40"
        >
          Tomar de fábrica
        </button>
      </div>
    </div>
  </Transition>

  <!-- Lightbox foto producto -->
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
            :src="fotoProducto?.foto_url"
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
  </div>
</template>

<style scoped>
.label {
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
  margin-bottom: 0.25rem;
}
.input {
  width: 100%;
  border-radius: 0.5rem;
  border: 1px solid #d1d5db;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  background: white;
}
.input:focus {
  outline: none;
  --tw-ring-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
  box-shadow: var(--tw-ring-shadow);
}
.btn-primary {
  background: #2563eb;
  color: white;
  border-radius: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 600;
  transition: background-color 0.15s;
}
.btn-primary:hover {
  background: #1d4ed8;
}
.btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.btn-secondary {
  background: #f3f4f6;
  color: #374151;
  border-radius: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 600;
  transition: background-color 0.15s;
}
.btn-secondary:hover {
  background: #e5e7eb;
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
