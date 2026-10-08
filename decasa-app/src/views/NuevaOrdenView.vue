<script setup>
import IconoS from '@/components/common/IconoS.vue'
import InputPesos from '@/components/common/InputPesos.vue'
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import { getVariantes } from '@/api/inventario'
import { getReservaInfo, getReservaStockLote } from '@/api/reserva'
import { updateCliente } from '@/api/clientes'
import { SPECS_TEMPLATES, resolverCategoria, camposParaModo, specsToDescripcion, extraerDimensiones } from '@/constants/specsConfig'
import { useTelas } from '@/composables/useTelas'
import TelaPicker from '@/components/ordenes/TelaPicker.vue'
import ResumenOrdenModal from '@/components/ordenes/ResumenOrdenModal.vue'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { comprimirImagen, comprimirAlTomar } from '@/utils/comprimirImagen'
import { useBorradorLocal } from '@/composables/useBorradorLocal'
import { piezasPorJuego, piezasDeOpcion, enJuegos, precioPieza } from '@/utils/juegos'
import { pctDeMonto, montoDePct, formatPct } from '@/utils/descuentos'
import { ArrowPathIcon, SparklesIcon, XMarkIcon } from '@heroicons/vue/24/solid'
import { ArrowPathIcon as ArrowPathOutlineIcon, PhotoIcon, UserGroupIcon, BuildingStorefrontIcon, ArrowPathIcon as ConvertIcon, ExclamationTriangleIcon, PencilIcon, MapPinIcon, SwatchIcon, CurrencyDollarIcon, PlusIcon, GiftIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { getReceptores, crearConsulta } from '@/api/consultas'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import AnexoFirma from '@/components/anexo/AnexoFirma.vue'
import { crearAnexo, getAnexo, getAnexoPublico, firmarAnexo, enviarAnexoEmail, pdfAnexo } from '@/api/anexos'
import BocetoCanvas from '@/components/BocetoCanvas.vue'
import DireccionColombia from '@/components/DireccionColombia.vue'
import ComboInput from '@/components/common/ComboInput.vue'
import { useTelaFotos } from '@/composables/useTelaFotos'

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()
const toast  = useToast()

// ── Modo cotización ───────────────────────────────────────────────────────────
// El cliente solo pregunta cuánto le sale: no hay firma, anticipo, anexo ni
// comprobante, el cliente es opcional y no se toca inventario.
const modoCotizacion = computed(() => route.query.modo === 'cotizacion')

// Seguir un borrador: esta misma pantalla con lo que se había guardado, y al
// crear se confirma ese borrador por la misma puerta que una orden nueva
// (stock, descuentos, firma, anticipo, producción, avisos). Ver
// cargarBorradorServidor().
const borradorId = Number(route.query.borrador) || null
const cargandoBorradorServidor = ref(!!borradorId)
const borradorServidor = ref(null)   // { id, cliente } para el encabezado
// Ya se le pidió el precio al taller cuando se guardó: no se pide otra vez.
const consultaYaPedida = ref(false)

// ── Orden con descuento especial (serie FV2) ─────────────────────────────────────────────
// Venta a allegados de los dueños: numeración propia FV2-N, no gasta consecutivo
// normal, pero cuenta como venta y genera comisión igual que cualquier otra.
const esFv2       = ref(false)
// FV2 a la que no se le quita el IVA en la comisión. Solo le aparece a quien
// tiene el permiso en Trabajadores.
const fv2SinIva   = ref(false)
const motivoSerie = ref('')

// Contacto suelto cuando no se elige un cliente formal
const contactoNombre   = ref('')
const contactoTelefono = ref('')
const contactoEmail    = ref('')
const diasVigencia     = ref(15)

// ── Pasos ─────────────────────────────────────────────────────────────────────
const step = ref(1)

// ── Tiendas ───────────────────────────────────────────────────────────────────
const tiendas = ref([])

// Telas con metros disponibles (para filtrar el picker en fabricar bajo pedido)
const { cargarTelas, marcasConStock } = useTelas()
const categoriasInteresOpts = ref([])

onMounted(async () => {
  const [{ data: tiendasData }, , { data: catData }] = await Promise.all([
    api.get('/tiendas'),
    cargarTelas(),
    api.get('/productos/categorias').catch(() => ({ data: [] })),
  ])
  categoriasInteresOpts.value = catData.filter(c => c)
  tiendas.value = tiendasData
})

// Las fotos de tela las carga y las muestra TelaPicker; aquí solo se precalienta
// la caché para que el selector abra con las miniaturas ya listas.
const { cargarFotosTela } = useTelaFotos()
cargarFotosTela()

// ── Paso 1: Cliente ───────────────────────────────────────────────────────────
const clienteQuery     = ref('')
const clienteResultados = ref([])
const clienteSeleccionado = ref(null)
const buscandoCliente   = ref(false)
const modoNuevoCliente  = ref(false)
const nuevoCliente = ref({ nombre: '', cedula: '', telefono: '', email: '', direccion: '', tipo: 'oficial', categorias_interes: [], notas_interes: '' })
const creandoCliente = ref(false)
const errCliente = ref('')

// ── Aviso de cliente repetido ────────────────────────────────────────────────
// Mientras se llena el formulario se consulta si ya existe alguien con la misma
// cédula, teléfono, correo o nombre exacto. Sirve para no terminar con el mismo
// cliente dos veces, y sobre todo para no chocar al guardar: la cédula es única
// en la base y el error salía recién al darle a crear.
const posiblesDuplicados = ref([])
let dupTimer = null

watch(
  () => [nuevoCliente.value.nombre, nuevoCliente.value.cedula,
         nuevoCliente.value.telefono, nuevoCliente.value.email],
  ([nombre, cedula, telefono, email]) => {
    clearTimeout(dupTimer)
    const params = {
      nombre:   (nombre   || '').trim(),
      cedula:   (cedula   || '').replace(/\D/g, ''),
      telefono: (telefono || '').replace(/\D/g, ''),
      email:    (email    || '').trim(),
    }
    // Un nombre a medio escribir no dice nada; los identificadores sí
    const vale = params.cedula.length >= 5 || params.telefono.length >= 7 ||
                 params.email.includes('@') || params.nombre.length >= 6
    if (!vale) { posiblesDuplicados.value = []; return }

    dupTimer = setTimeout(async () => {
      try {
        const { data } = await api.get('/clientes/verificar-duplicado', {
          params, silencioso: true,   // no debe encender la S: se escribe mientras tanto
        })
        posiblesDuplicados.value = data ?? []
      } catch { posiblesDuplicados.value = [] }
    }, 400)
  }
)

/** Usar el cliente que ya existía en vez de crear uno repetido. */
function usarClienteExistente(c) {
  seleccionarCliente(c)
  modoNuevoCliente.value  = false
  posiblesDuplicados.value = []
  nuevoCliente.value = { nombre: '', cedula: '', telefono: '', email: '', direccion: '', tipo: 'oficial', categorias_interes: [], notas_interes: '' }
}

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

function toggleCatInteresNuevo(cat) {
  const arr = nuevoCliente.value.categorias_interes
  const idx = arr.indexOf(cat)
  idx === -1 ? arr.push(cat) : arr.splice(idx, 1)
}

/**
 * Abre el formulario de cliente nuevo, aprovechando lo que ya se haya escrito
 * en el buscador. Si son puros números es un teléfono o una cédula, no un
 * nombre: se coloca en el campo que corresponde en vez de dejarlo de nombre.
 */
function abrirNuevoCliente() {
  const escrito = clienteQuery.value.trim()
  const soloDigitos = escrito.replace(/\D/g, '')

  if (escrito && soloDigitos.length === escrito.length && soloDigitos.length >= 6) {
    // Los celulares en Colombia son 10 dígitos y empiezan por 3. Una cédula
    // también puede tener 10, así que el primer dígito es lo que los separa.
    if (soloDigitos.length === 10 && soloDigitos.startsWith('3')) {
      nuevoCliente.value.telefono = soloDigitos
    } else {
      nuevoCliente.value.cedula = soloDigitos
    }
  } else if (escrito) {
    nuevoCliente.value.nombre = escrito
  }

  clienteResultados.value = []
  modoNuevoCliente.value  = true
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

// Al cambiar canal por uno digital (whatsapp/ig/fb/...) se asigna Tienda
// Virtual SOLO si el usuario no tiene una tienda propia asignada — un
// vendedor de una tienda real (ej. Circunvalar) sigue contando para su
// tienda aunque venda por WhatsApp; si no, la venta y su numeración
// quedaban mal atribuidas a Tienda Virtual.
watch(canal, (nuevoCanal) => {
  const virtualId = tiendaVirtualId.value
  const propiaId  = auth.usuario?.tienda_default_id ?? null
  if (nuevoCanal !== 'fisica') {
    tiendaId.value = propiaId ?? virtualId ?? tiendaId.value
  } else {
    tiendaId.value = propiaId ?? (tiendas.value.find(t => !t.es_fabrica && t.nombre !== 'Tienda Virtual')?.id ?? '')
  }
})

// Si las tiendas cargan después de que el usuario ya cambió el canal
watch(tiendaVirtualId, (id) => {
  if (id && canal.value !== 'fisica' && !auth.usuario?.tienda_default_id) tiendaId.value = id
})

function paso1Valido() {
  // En una cotización el cliente es opcional: puede que solo esté preguntando.
  if (modoCotizacion.value) return tiendaId.value && canal.value
  return clienteSeleccionado.value && tiendaId.value && canal.value
}

// ── Tipo de orden ─────────────────────────────────────────────────────────────
// Ya no se elige: se deduce del carrito. Una orden es 'restauracion' solo si
// TODO lo que lleva son muebles del cliente; si además hay algo de catálogo es
// una venta que incluye restauración. El módulo de Restauración no depende de
// esta etiqueta — busca por los ítems marcados.
const mostrarFormRestauracion = ref(false)

const tipoOrden = computed(() =>
  items.value.length && items.value.every(i => i._es_restauracion)
    ? 'restauracion'
    : 'venta'
)

// ── Paso 2: Productos / Carrito ───────────────────────────────────────────────
const productoQuery = ref('')
const productoResultados = ref([])
const sugerencias        = ref([])   // "¿quizás quisiste decir?" cuando no hay resultados

function usarSugerencia(s) {
  productoQuery.value = s.nombre
  buscarProducto()
}
const buscandoProducto = ref(false)
const items = ref([])
// El carrito se muestra con el último agregado arriba: los de antes casi
// siempre ya están acomodados, y con muchos había que bajar hasta el fondo
// cada vez. Solo es la vista: la orden, su PDF y el "ÍTEM #" siguen en el
// orden en que se agregaron, e `idx` es la posición real en `items`.
const carritoAlReves = computed(() =>
  items.value.map((item, idx) => ({ item, idx })).reverse()
)
// De qué tienda sale el producto. Un independiente no tiene stock propio: su
// sede es solo administrativa, así que siempre saca de una tienda real.
const tiendaBusqueda = ref(auth.isIndependiente ? '' : (auth.usuario?.tienda_default_id ?? ''))

/** Tiendas de las que se puede sacar producto (la sede administrativa no). */
const tiendasConStock = computed(() => tiendas.value.filter(t => !t.es_independientes))

// Cuando cargan las tiendas, al independiente se le preselecciona una real
watch(tiendasConStock, (lista) => {
  if (auth.isIndependiente && ! tiendaBusqueda.value && lista.length) {
    tiendaBusqueda.value = lista.find(t => !t.es_fabrica && t.nombre !== 'Tienda Virtual')?.id ?? lista[0].id
  }
}, { immediate: true })

// Formulario para restauraciones
const restauracionItem = ref({ nombre_mueble: '', descripcion_trabajo: '', cantidad: 1, precio_unitario: 0, foto_blob: null, foto_preview: null, _retapizar: false, _telaSelections: {} })
const restauracionCalc = ref({ calculando: false, resultado: null, mostrar: false })

async function onFotoRestauracionForm(event) {
  const original = event.target.files[0]
  if (!original) return
  const file = await comprimirAlTomar(original)
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
    _es_restauracion: true,   // mueble que trae el cliente, no sale de inventario
    specs: specsBase,
    specs_notas: '',
    tienda_origen: null,
    fecha_entrega_prometida: null,
    boceto_blobs:    f.foto_blob    ? [f.foto_blob]    : [],
    boceto_urls:     f.foto_blob    ? ['']             : [],
    boceto_previews: f.foto_preview ? [f.foto_preview] : [],
    _cotizarPrecio:      false,   // se activa a mano si hay que consultarlo
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
// `unico` = el mueble ya está hecho y solo hay uno. No está en el catálogo
// —no paga meterlo, porque no se va a fabricar más— pero tampoco va al taller.
const productoCustomForm = ref({ nombre: '', categoria: '', precio_unitario: 0, cantidad: 1, unico: false, fotos: [] })

// Fotos desde el formulario mismo: el mueble único ya existe y lo natural es
// tomarle la foto al describirlo. Pasan al carrito como las del ítem.
async function onFotosProductoCustom(event) {
  for (const original of Array.from(event.target.files ?? [])) {
    const file = await comprimirAlTomar(original)
    productoCustomForm.value.fotos.push({ blob: file, preview: URL.createObjectURL(file) })
  }
  event.target.value = ''
}

function quitarFotoProductoCustom(idx) {
  const [f] = productoCustomForm.value.fotos.splice(idx, 1)
  if (f?.blob) URL.revokeObjectURL(f.preview)
}

// Muebles únicos que ya se vendieron: de un modelo que no se vuelve a hacer
// pueden quedar varias piezas en distintas tiendas, y al vender otra se
// escoge de la lista en vez de escribirlo de nuevo.
const unicosSugeridos = ref([])
const mostrarUnicos   = ref(false)
let _unicosTimer = null
function buscarUnicos() {
  clearTimeout(_unicosTimer)
  if (!productoCustomForm.value.unico) { unicosSugeridos.value = []; return }
  _unicosTimer = setTimeout(async () => {
    try {
      const { data } = await api.get('/productos/unicos', { params: { q: productoCustomForm.value.nombre.trim() } })
      unicosSugeridos.value = data
    } catch { unicosSugeridos.value = [] }
  }, 250)
}
watch(() => productoCustomForm.value.unico, (si) => { if (si) buscarUnicos(); else unicosSugeridos.value = [] })

function ocultarUnicos() {
  setTimeout(() => { mostrarUnicos.value = false }, 150)
}

function elegirUnico(u) {
  const f = productoCustomForm.value
  f.nombre    = u.nombre
  f.categoria = u.categoria ?? ''
  f.precio_unitario = u.precio_unitario || 0
  // Sus fotos ya están subidas: van por url, sin volver a subirlas.
  if (!f.fotos.length) f.fotos = (u.fotos ?? []).map(url => ({ blob: null, url, preview: url }))
  mostrarUnicos.value = false
}

function cancelarProductoCustom() {
  productoCustomForm.value.fotos.forEach(f => { if (f.blob) URL.revokeObjectURL(f.preview) })
  productoCustomForm.value = { nombre: '', categoria: '', precio_unitario: 0, cantidad: 1, unico: false, fotos: [] }
  modoProductoCustom.value = false
}

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
    // Sigue siendo personalizado —no sale de inventario, porque no hay
    // registro suyo—; lo que cambia es que el único no genera producción.
    es_personalizado: true,
    _producto_unico: f.unico,
    specs: {},
    specs_notas: '',
    tienda_origen: null,
    fecha_entrega_prometida: null,
    boceto_blobs:    f.fotos.map(x => x.blob),
    boceto_urls:     f.fotos.map(x => x.url ?? ''),
    boceto_previews: f.fotos.map(x => x.preview),
    _cotizarPrecio:      false,   // se activa a mano si hay que consultarlo
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  })
  // Las previews pasaron al ítem: no se revocan aquí.
  productoCustomForm.value = { nombre: '', categoria: '', precio_unitario: 0, cantidad: 1, unico: false, fotos: [] }
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

/**
 * Deja la búsqueda como si no se hubiera hecho.
 *
 * Se busca un producto, salen veinte resultados, y entonces uno se acuerda de
 * que lo suyo era un diseño nuevo o una restauración. Sin esto había que bajar
 * por toda la lista hasta dar con esos botones.
 */
function limpiarBusqueda() {
  productoQuery.value      = ''
  productoResultados.value = []
  sugerencias.value        = []
  busquedaHecha.value      = false
  fabricaStock.value       = {}
}

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
    recordarProductos(data)
    busquedaHecha.value = true
    sugerencias.value = []
    if (!data.length) {
      try {
        const { data: sug } = await api.get('/productos/sugerencias', { params: { q: productoQuery.value } })
        sugerencias.value = sug ?? []
      } catch { sugerencias.value = [] }
    }
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

/** ¿Se puede llevar ya, sea de la tienda o de la reserva de fábrica? */
function hayDisponible(p) {
  return stockLibre(p) > 0 || (p.es_tapizado && fabricaStock.value[p.id] > 0)
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
// 'stock': se vende lo que hay (solo opciones con existencias).
// 'personalizar' / 'fabricar': se parte de esa versión del mueble —la cama
// con baúl, la otra medida— y se hace a pedido, así que el stock no importa.
const vcPickerModo = ref('stock')
watch(mostrarVCPicker, (abierto) => { if (!abierto) vcPickerModo.value = 'stock' })

const vcPickerValido = computed(() => {
  if (!vcPickerGrupos.value.length) return false
  // A pedido basta con lo que cambie: "con baúl" sin tocar la medida.
  if (vcPickerModo.value !== 'stock') return Object.keys(vcPickerSelec.value).length > 0
  return vcPickerGrupos.value.every(g => vcPickerSelec.value[g.tipo_variante_id])
})

function elegirOpcionVC(grupo, opt) {
  const k = grupo.tipo_variante_id
  // A pedido se puede dejar un grupo sin elegir: tocar la marcada la quita.
  if (vcPickerModo.value !== 'stock' && vcPickerSelec.value[k]?.config_id === opt.id) {
    const { [k]: _, ...resto } = vcPickerSelec.value
    vcPickerSelec.value = resto
    return
  }
  vcPickerSelec.value = {
    ...vcPickerSelec.value,
    [k]: { config_id: opt.id, opcion_nombre: opt.opcion_nombre, tipo_nombre: grupo.tipo.nombre, precio_adicional: opt.precio_adicional ?? 0, stock: opt.stock_disponible ?? 0 },
  }
}

/** ¿Se puede escoger esta opción? A pedido, todas; de stock, las que hay. */
function opcionVCElegible(opt) {
  return vcPickerModo.value !== 'stock' || (opt.stock_disponible ?? 0) > 0
}

/**
 * "Personalizar" o "Fabricar" desde una versión concreta del producto.
 * Si el producto tiene variantes (medidas, con baúl…) se pregunta de cuál se
 * parte; si no tiene, se agrega como siempre.
 */
async function aPedidoConVariante(producto, modo) {
  const agregar = modo === 'fabricar' ? fabricarBajoPedido : agregarPersonalizado
  const tienda = tiendaBusqueda.value || tiendaId.value
  let grupos = []
  try {
    const { data } = await api.get(`/productos/${producto.id}/variante-configs`, { params: { tienda_id: tienda || undefined } })
    grupos = (data ?? []).filter(g => g.items?.length)
  } catch { grupos = [] }
  if (!grupos.length) { agregar(producto); return }

  vcPickerProd.value      = producto
  vcPickerSelec.value     = {}
  vcPickerGrupos.value    = grupos
  vcPickerEsFabrica.value = false
  vcPickerCargando.value  = false
  mostrarVCPicker.value   = true
  vcPickerModo.value      = modo
}

/** Cambiar de cuál versión se parte en uno a pedido que ya está en el carrito. */
async function cambiarVersionAPedido(idx) {
  const item = items.value[idx]
  abriendoCambio.value = idx
  try {
    const tienda = tiendaBusqueda.value || tiendaId.value
    const [producto, configs] = await Promise.all([
      api.get(`/productos/${item.producto_id}`, { params: { tienda_id: tienda || undefined } }).then(r => r.data),
      api.get(`/productos/${item.producto_id}/variante-configs`, { params: { tienda_id: tienda || undefined } }).then(r => r.data).catch(() => []),
    ])
    const grupos = (configs ?? []).filter(g => g.items?.length)
    if (!grupos.length) { toast.info('Este producto no tiene otras versiones registradas.'); return }

    editandoIdx.value       = idx
    vcPickerProd.value      = producto
    vcPickerGrupos.value    = grupos
    vcPickerEsFabrica.value = false
    vcPickerCargando.value  = false
    // Queda marcada la que tenía, si se reconoce.
    const marcada = grupos.flatMap(g => g.items.map(o => ({ g, o }))).find(({ o }) => o.id === item._config_id)
    vcPickerSelec.value = marcada
      ? { [marcada.g.tipo_variante_id]: { config_id: marcada.o.id, opcion_nombre: marcada.o.opcion_nombre, tipo_nombre: marcada.g.tipo.nombre, precio_adicional: marcada.o.precio_adicional ?? 0, stock: 0 } }
      : {}
    mostrarVCPicker.value = true
    vcPickerModo.value    = item._fabricar_pedido ? 'fabricar' : 'personalizar'
  } catch {
    editandoIdx.value = null
    toast.error('No se pudo abrir el selector.')
  } finally {
    abriendoCambio.value = null
  }
}

/** A pedido: lo que se eligió en el selector (o nada, "la normal"). */
function confirmarVCAPedido(sinVariante = false) {
  const prod = vcPickerProd.value
  const selecciones = sinVariante ? [] : Object.values(vcPickerSelec.value)
  const variante = selecciones.length ? {
    label:    selecciones.map(s => s.opcion_nombre).join(' · '),
    precio:   selecciones.reduce((sum, s) => sum + Number(s.precio_adicional ?? 0), 0),
    configId: selecciones.length === 1 ? (selecciones[0].config_id ?? null) : null,
  } : null

  if (editandoIdx.value !== null) {
    // Corrigiendo la versión de uno que ya está en el carrito.
    const it = items.value[editandoIdx.value]
    const precio = variante?.precio > 0 ? variante.precio : Number(prod.precio_base ?? it.precio_unitario ?? 0)
    it.variante_label = variante?.label ?? null
    it._config_id     = variante?.configId ?? null
    if (Number(it.precio_unitario) !== precio) {
      it.precio_unitario = precio
      toast.info(`Precio actualizado a $${pesos(precio)} por la nueva selección.`)
    }
    editandoIdx.value = null
  } else if (vcPickerModo.value === 'fabricar') {
    fabricarBajoPedido(prod, variante)
  } else {
    agregarPersonalizado(prod, variante)
  }
  mostrarVCPicker.value = false
}

function confirmarVCPickerOrden() {
  if (vcPickerModo.value !== 'stock') return confirmarVCAPedido()
  const prod = vcPickerProd.value
  const selecciones = Object.values(vcPickerSelec.value)
  if (selecciones.length === 0) return
  // Sólo el valor elegido —"1.60"—, no el nombre del tipo: el tipo se llama
  // "Cama Miami medidas" y al lado de CAMA MIAMI en la orden no dice nada.
  const label = selecciones.map(s => s.opcion_nombre).join(' · ')
  const precioAdicional = selecciones.reduce((sum, s) => sum + Number(s.precio_adicional ?? 0), 0)
  // El id de la opción sólo cuando es una: en la orden hay una sola casilla
  // para engancharla, y con dos elegidas guardar la primera sería mentir. El
  // texto sí las lleva todas.
  const configId = selecciones.length === 1 ? (selecciones[0].config_id ?? null) : null
  if (vcPickerEsFabrica.value) {
    _pushItemFabricaVC(prod, label, precioAdicional, configId)
  } else {
    _pushItemVC(prod, label, precioAdicional, configId)
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

  if (! _colocarItem({
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
    _descuento_modo:     'monto',
    _descuento_valor:    0,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  }, existe)) return
  productoResultados.value = []
  productoQuery.value = ''
  fabricaStock.value = {}
}

function _pushItemVC(producto, varianteLabel, precioAdicional, configId = null) {
  const esOtraTienda = tiendaBusqueda.value && tiendaBusqueda.value != tiendaId.value
  const existe = items.value.find(i => i.producto_id === producto.id && i.variante_label === varianteLabel && !i._fabricar_pedido)
  if (! _colocarItem({
    producto_id: producto.id, variante_id: null, _config_id: configId,
    tienda_origen_id: esOtraTienda ? (tiendaBusqueda.value ?? null) : null,
    nombre: producto.nombre, categoria: producto.categoria,
    variante_label: varianteLabel,
    stock_libre: stockLibre(producto),
    personalizable: producto.personalizable ?? false, cantidad: 1,
    precio_unitario: precioAdicional > 0 ? precioAdicional : Number(producto.precio_base ?? 0),
    es_personalizado: false, specs: {}, specs_notas: '',
    tienda_origen: esOtraTienda ? nombreTiendaBusqueda() : null,
    fecha_entrega_prometida: null, boceto_blobs: [], boceto_urls: [], boceto_previews: [],
    _fabricar_pedido: false, _cotizarPrecio: false, _descuento_modo: 'monto', _descuento_valor: 0,
    _mostrarCalculadora: false, _calculandoPrecio: false, _precioCalc: null, _precioReferencia: null, _telaSelections: {},
  }, existe)) return
  productoResultados.value = []
  productoQuery.value = ''
}

function _pushItemFabricaVC(producto, varianteLabel, precioAdicional, configId = null) {
  const existe = items.value.find(i => i.producto_id === producto.id && i.variante_label === varianteLabel && i.tienda_origen_id === fabricaId.value && !i._fabricar_pedido)
  if (! _colocarItem({
    producto_id: producto.id, variante_id: null, _config_id: configId,
    tienda_origen_id: fabricaId.value,
    nombre: producto.nombre, categoria: producto.categoria,
    variante_label: varianteLabel,
    stock_libre: fabricaStock.value[producto.id] ?? 0,
    personalizable: producto.personalizable ?? false, cantidad: 1,
    precio_unitario: precioAdicional > 0 ? precioAdicional : Number(producto.precio_base ?? 0),
    es_personalizado: false, specs: {}, specs_notas: '',
    tienda_origen: 'Fábrica',
    fecha_entrega_prometida: null, boceto_blobs: [], boceto_urls: [], boceto_previews: [],
    _fabricar_pedido: false, _cotizarPrecio: false, _descuento_modo: 'monto', _descuento_valor: 0,
    _mostrarCalculadora: false, _calculandoPrecio: false, _precioCalc: null, _precioReferencia: null, _telaSelections: {},
  }, existe)) return
  productoResultados.value = []
  productoQuery.value = ''
  fabricaStock.value = {}
}

// ── Selector de variante ──────────────────────────────────────────────────────
const mostrarVariantePicker = ref(false)
const productoParaVariante = ref(null)
const variantesDisponibles = ref([])
const cargandoVariantes = ref(false)
// Tres estados, no dos: undefined = todavía no eligió, null = eligió "sin
// especificar tela" a propósito, objeto = eligió una tela concreta.
//
// Antes solo había dos y arrancaba en null, así que "Sin especificar tela"
// salía ya marcado y bastaba con darle a "Agregar al carrito" para que la
// venta quedara sin tela. Por eso ninguna orden tenía variante guardada:
// 0 de 68 ítems, ni siquiera vendiendo un SOFA CAMA ROMA que tiene 4.
const varianteSeleccionada = ref(undefined)
const NO_ELEGIDA = undefined

// ── Corregir un ítem que ya está en el carrito ────────────────────────────────
/**
 * Qué ítem se está corrigiendo, si se entró por "Cambiar" en vez de por la
 * búsqueda. Los selectores de tela y de medida son los mismos; lo único que
 * cambia es que al confirmar el ítem se reemplaza en su sitio en vez de
 * agregarse uno nuevo.
 */
const editandoIdx    = ref(null)
const abriendoCambio = ref(null)   // idx mientras se cargan las variantes

/**
 * Deja el ítem donde va: encima del que se está corrigiendo, o al final.
 *
 * Al corregir se conserva todo lo que el vendedor ya había ajustado —cantidad,
 * descuento, regalo, specs, fotos—: equivocarse de tela no puede costar volver
 * a escribirlo todo. El precio sí es el de la selección nueva, que es a lo que
 * se vino; si cambia, se avisa para que nadie lo descubra en el total.
 */
// ── Productos que se venden en juego ──────────────────────────────────────────
// Unas mesas de noche de a 2. En el carrito se trabaja en la unidad que el
// vendedor elige —juegos completos o piezas sueltas— con su precio; al enviar
// todo pasa a piezas, que es como se cuenta el stock (ver utils/juegos.js).
// Los productos vistos en la búsqueda se guardan para saber, al agregarlos
// por cualquiera de los caminos (tela, medida, fábrica…), si van en juego.
const productosVistos = new Map()
function recordarProductos(lista) {
  for (const p of lista ?? []) if (p?.id) productosVistos.set(p.id, p)
}

function aplicarJuego(item) {
  const producto = productosVistos.get(item?.producto_id)
  // La opción elegida puede traer otro número de piezas (alas de a 4).
  const n = piezasDeOpcion(producto, item?._config_id)
  if (!n || item._piezas_por_juego) return item
  item._piezas_por_juego = n
  item._por_juego        = true
  item._precio_juego     = Number(item.precio_unitario) || 0
  item._precio_pieza     = precioPieza(producto, item._precio_juego, item._config_id)
  return item
}

/**
 * Pasar entre "juego completo" y "piezas sueltas". La cantidad y el precio
 * cambian de unidad con él: 1 juego de 2 son 2 piezas a precio de pieza. Un
 * descuento en pesos es por unidad, así que también se convierte.
 */
function cambiarModoJuego(item, porJuego) {
  const n = item._piezas_por_juego
  if (!n || item._por_juego === porJuego) return
  item.cantidad        = porJuego ? Math.max(1, Math.floor(item.cantidad / n)) : item.cantidad * n
  item.precio_unitario = porJuego ? item._precio_juego : item._precio_pieza
  if ((item._descuento_modo ?? 'monto') === 'monto' && Number(item._descuento_valor)) {
    item._descuento_valor = porJuego ? Number(item._descuento_valor) * n : Math.round(Number(item._descuento_valor) / n)
  }
  item._por_juego = porJuego
}

/** Cuántas unidades de las que se están vendiendo caben en el stock del ítem. */
function stockEnUnidad(item) {
  const libre = Number(item.stock_libre) || 0
  return item._piezas_por_juego && item._por_juego ? Math.floor(libre / item._piezas_por_juego) : libre
}

/**
 * Cantidad y precio como los guarda el servidor: siempre en piezas. Un juego
 * de $900.000 sale como 2 piezas de $450.000. Se redondea hacia abajo al
 * centavo para que N piezas nunca sumen más que el juego.
 */
function lineaParaEnviar(i, precio) {
  const n = i._piezas_por_juego
  if (!n) return { cantidad: i.cantidad, precio_unitario: precio }
  if (i._por_juego) {
    return { cantidad: i.cantidad * n, precio_unitario: Math.floor(precio / n * 100) / 100, venta_juego: 'juego' }
  }
  return { cantidad: i.cantidad, precio_unitario: precio, venta_juego: 'pieza' }
}

/** Para el resumen: de qué se trata la cantidad. */
function textoJuegoItem(i) {
  if (!i._piezas_por_juego) return null
  return i._por_juego
    ? `Juego de ${i._piezas_por_juego} piezas`
    : `Pieza suelta (juego de ${i._piezas_por_juego})`
}

function _colocarItem(nuevo, existente = null) {
  aplicarJuego(nuevo)
  if (editandoIdx.value === null) {
    // Devuelve false cuando solo sumó cantidad a uno que ya estaba: quien
    // llama deja entonces la búsqueda como está, para seguir agregando de la
    // misma lista.
    if (existente) { existente.cantidad++; return false }
    items.value.push(nuevo)
    return true
  }

  const idx   = editandoIdx.value
  const viejo = items.value[idx]

  // Se estaba vendiendo por pieza suelta: corregir la tela no lo cambia.
  if (nuevo._piezas_por_juego && viejo._piezas_por_juego && viejo._por_juego === false) {
    nuevo._por_juego      = false
    nuevo.precio_unitario = nuevo._precio_pieza
  }

  items.value[idx] = {
    ...nuevo,
    // Si venía de un borrador, sigue siendo el mismo ítem.
    id:               viejo.id,
    cantidad:         viejo.cantidad,
    es_personalizado: viejo.es_personalizado,
    specs:            viejo.specs,
    specs_notas:      viejo.specs_notas,
    boceto_blobs:     viejo.boceto_blobs,
    boceto_urls:      viejo.boceto_urls,
    boceto_previews:  viejo.boceto_previews,
    fecha_entrega_prometida: viejo.fecha_entrega_prometida,
    _descuento_modo:  viejo._descuento_modo,
    _descuento_valor: viejo._descuento_valor,
    _regalo:          viejo._regalo,
    _cotizarPrecio:   viejo._cotizarPrecio,
    _telaSelections:  viejo._telaSelections,
    // Corregir de cuál sofá se trata no quita que se vaya a retapizar.
    _retapizar:       viejo._retapizar,
    _trabajoFabrica:  viejo._trabajoFabrica,
    // De qué tienda sale no lo decide este selector: eso se cambia aparte, y
    // tomarlo de la búsqueda mudaría el ítem de tienda sin avisar.
    tienda_origen_id: viejo.tienda_origen_id,
    tienda_origen:    viejo.tienda_origen,
  }

  if (Number(viejo.precio_unitario) !== Number(nuevo.precio_unitario)) {
    toast.info(`Precio actualizado a $${pesos(nuevo.precio_unitario)} por la nueva selección.`)
  }

  editandoIdx.value = null
  return true
}

/**
 * Volver a elegir la tela o la medida de un ítem del carrito.
 *
 * Se pregunta por las variantes en vez de mirar las banderas del producto: lo
 * que importa es qué hay para elegir en esa tienda ahora mismo.
 */
async function cambiarVariante(idx) {
  const item = items.value[idx]
  if (!item.producto_id) return

  const deFabrica = !!fabricaId.value && item.tienda_origen_id === fabricaId.value
  const tienda    = item.tienda_origen_id || tiendaBusqueda.value || tiendaId.value

  abriendoCambio.value = idx
  try {
    const [producto, telas] = await Promise.all([
      api.get(`/productos/${item.producto_id}`, { params: { tienda_id: tienda } }).then(r => r.data),
      getVariantes(item.producto_id, tienda).then(r => r.data).catch(() => []),
    ])

    editandoIdx.value = idx

    if (telas.length) {
      productoParaVariante.value  = producto
      variantesDisponibles.value  = telas
      varianteSeleccionada.value  = NO_ELEGIDA
      cargandoVariantes.value     = false
      mostrarVariantePicker.value = true
      return
    }

    const configs = await api
      .get(`/productos/${item.producto_id}/variante-configs`, { params: { tienda_id: tienda } })
      .then(r => r.data).catch(() => [])
    const conStock = configs.filter(g => g.items.some(i => (i.stock_disponible ?? 0) > 0))

    if (conStock.length) {
      vcPickerProd.value      = producto
      vcPickerSelec.value     = {}
      vcPickerGrupos.value    = conStock
      vcPickerEsFabrica.value = deFabrica
      vcPickerCargando.value  = false
      mostrarVCPicker.value   = true
      return
    }

    editandoIdx.value = null
    toast.info('Este producto no tiene telas ni medidas registradas en esa tienda.')
  } catch {
    editandoIdx.value = null
    toast.error('No se pudo abrir el selector.')
  } finally {
    abriendoCambio.value = null
  }
}

/**
 * De qué tienda sale el producto, ya estando en el carrito.
 *
 * Se vuelve a preguntar el stock: el de la tienda anterior no dice nada de la
 * nueva, y dejarlo puesto haría que la pantalla permitiera vender lo que no
 * hay.
 */
async function cambiarTiendaItem(idx, valor) {
  const item = items.value[idx]
  if (!item.producto_id) return

  const nuevaId       = valor ? Number(valor) : Number(tiendaId.value)
  const esLaDeLaOrden = nuevaId === Number(tiendaId.value)

  abriendoCambio.value = idx
  try {
    const { data: producto } = await api.get(`/productos/${item.producto_id}`, { params: { tienda_id: nuevaId } })

    item.tienda_origen_id = esLaDeLaOrden ? null : nuevaId
    item.tienda_origen    = esLaDeLaOrden ? null : (tiendas.value.find(t => t.id === nuevaId)?.nombre ?? null)
    item.stock_libre      = stockLibre(producto)

    // No se baja la cantidad sola —eso es del vendedor—, pero tampoco se calla:
    // la tienda nueva puede tener menos.
    if (!item.es_personalizado && item.cantidad > stockEnUnidad(item)) {
      toast.error(item._piezas_por_juego
        ? `Ahí solo hay ${enJuegos(item.stock_libre, item._piezas_por_juego)} y llevas ${item.cantidad} ${item._por_juego ? 'juego(s)' : 'pieza(s)'}.`
        : `Ahí solo hay ${item.stock_libre} disponible(s) y llevas ${item.cantidad}.`)
    }

    // El stock de una tela es el de SU tienda: la que estaba elegida puede no
    // existir aquí. Se vuelve a elegir en vez de arrastrar una que quizá no hay.
    if (item.variante_id || item.variante_label) {
      toast.info('Elige de nuevo la tela o la medida: el stock cambia de una tienda a otra.')
      await cambiarVariante(idx)
    }
  } catch {
    toast.error('No se pudo cambiar la tienda.')
  } finally {
    abriendoCambio.value = null
  }
}

/**
 * Cerrar un selector sin elegir deja de estar corrigiendo.
 *
 * Si no, el siguiente producto que se buscara entraría encima del ítem que se
 * abandonó a medias.
 */
watch([mostrarVariantePicker, mostrarVCPicker, mostrarFabricaVariantePicker], ([tela, vc, fab]) => {
  if (!tela && !vc && !fab) editandoIdx.value = null
})

async function agregarItem(producto) {
  const tiendaConsulta = tiendaBusqueda.value || tiendaId.value
  if (producto.variantes?.length > 0 || producto.tiene_tallas || producto.es_tapizado) {
    productoParaVariante.value = producto
    varianteSeleccionada.value = NO_ELEGIDA
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
  // Sin elegir no se agrega: "sin especificar" tiene que ser una decisión,
  // no lo que pasa por darle al botón sin mirar.
  if (varianteSeleccionada.value === undefined) return
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

  if (! _colocarItem({
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
    _descuento_modo:     'monto',
    _descuento_valor:    0,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  }, existe)) return
  productoResultados.value = []
  productoQuery.value = ''
}

// Mandar a fabricar un producto del catálogo que no tiene stock
// `variante` (opcional): la versión de la que se parte —{ label, precio,
// configId }—, elegida en el selector de variantes. Ver aPedidoConVariante.
function fabricarBajoPedido(producto, variante = null) {
  // Se puede mandar a fabricar aunque haya stock (el de la tienda es exhibición,
  // lo quieren en otro acabado...). Se avisa para que nadie se extrañe después
  // de que el inventario no bajó.
  if (hayDisponible(producto)) {
    toast.info('Se manda a fabricar: no se descuenta el stock de la tienda.')
  }

  // Se suma al que ya está solo si todavía no se le dijo nada: el mismo
  // sofá en gris y en azul son dos ítems, no uno con cantidad 2.
  const existe = items.value.find(i =>
    i.producto_id === producto.id && i._fabricar_pedido &&
    (i.variante_label ?? null) === (variante?.label ?? null) &&
    !telaResumidaCampo(i, 'tela') && !(i.specs_notas ?? '').trim() &&
    !Object.values(i.specs ?? {}).some(v => v !== '' && v != null)
  )
  if (existe) { existe.cantidad++; return }

  items.value.push({
    producto_id: producto.id,
    variante_id: null,
    _config_id: variante?.configId ?? null,
    tienda_origen_id: null,
    nombre: producto.nombre,
    categoria: producto.categoria,
    variante_label: variante?.label ?? null,
    stock_libre: 0,
    personalizable: false,
    cantidad: 1,
    precio_unitario: variante?.precio > 0 ? variante.precio : Number(producto.precio_base ?? 0),
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
  aplicarJuego(items.value.at(-1))
  productoResultados.value = []
  productoQuery.value = ''
}

// Agregar un producto del catálogo en modo personalizado (sin stock, opción "Personalizar")
function agregarPersonalizado(producto, variante = null) {
  // La misma cama en otra versión (con baúl, otra medida) es otro ítem.
  const existe = items.value.find(i =>
    i.producto_id === producto.id && !i._fabricar_pedido && i.es_personalizado &&
    (i.variante_label ?? null) === (variante?.label ?? null)
  )
  if (existe) { existe.cantidad++; return }

  items.value.push({
    producto_id:         producto.id,
    variante_id:         null,
    _config_id:          variante?.configId ?? null,
    tienda_origen_id:    null,
    nombre:              producto.nombre,
    categoria:           producto.categoria,
    variante_label:      variante?.label ?? null,
    stock_libre:         0,
    personalizable:      true,
    cantidad:            1,
    precio_unitario:     variante?.precio > 0 ? variante.precio : Number(producto.precio_base ?? 0),
    es_personalizado:    true,
    specs:               {},
    specs_notas:         '',
    tienda_origen:       null,
    fecha_entrega_prometida: null,
    boceto_blobs:        [],
    boceto_urls:         [],
    boceto_previews:     [],
    _fabricar_pedido:    false,
    _cotizarPrecio:      false,   // se activa a mano si hay que consultarlo
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     {},
  })
  aplicarJuego(items.value.at(-1))
  productoResultados.value = []
  productoQuery.value = ''
}

function quitarItem(idx) {
  const item = items.value[idx]
  item.boceto_previews.forEach(p => { if (p) URL.revokeObjectURL(p) })
  items.value.splice(idx, 1)
}

/**
 * Corregir el nombre de un ítem que escribió el vendedor.
 *
 * Va a los dos sitios: `nombre` es lo que se ve en el carrito y
 * `nombre_custom` lo que viaja al servidor. Si solo se cambiara uno, la
 * pantalla diría una cosa y la orden llegaría con otra.
 */
function renombrarItem(item, valor) {
  item.nombre        = valor
  item.nombre_custom = valor
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
    // Apagado por defecto: se olvidaban de quitarlo y la orden salia sin precio
    item._cotizarPrecio   = false
    item._telaSelections  = {}
  } else {
    item._cotizarPrecio = false
  }
}

/**
 * Cambio de tela: el sofá que está en la tienda se vende, pero se manda a la
 * fábrica a retapizar antes de entregarlo.
 *
 * No es lo mismo que "personalizado": ese no toca inventario, y aquí el
 * mueble existe y hay que apartarlo para que nadie más lo venda mientras
 * está en el taller. El ítem sigue siendo de stock —reserva, tienda de
 * origen, cantidad contra lo disponible— y además se le pide una tela nueva.
 */
function toggleRetapizar(item) {
  item._retapizar = !item._retapizar
  if (item._retapizar) {
    // Se va a la fábrica, no con el cliente.
    item._llevar_ahora    = false
    item._cotizarPrecio   = false
    item._trabajoFabrica  = 'tela'
  } else {
    item._cotizarPrecio   = false
    item._telaSelections  = {}
    item._trabajoFabrica  = null
  }
}

/**
 * Qué se le hace en la fábrica. Todos siguen el mismo camino —se aparta,
 * entra a producción, no se entrega hasta que salga del taller—; cambia lo
 * que el taller necesita saber: la tela nueva, o qué color o qué arreglo.
 * El arreglo y el color van con el precio del mueble (el vendedor lo sube si
 * se cobra); solo el cambio de tela se puede mandar a cotizar.
 */
const TRABAJOS_FABRICA = [
  { k: 'tela',    label: 'Cambio de tela',  icono: '🧵' },
  { k: 'color',   label: 'Cambio de color', icono: '🎨' },
  { k: 'arreglo', label: 'Arreglo',         icono: '🔧' },
]
function trabajoDe(item) { return item._trabajoFabrica || 'tela' }
function trabajoInfo(item) { return TRABAJOS_FABRICA.find(t => t.k === trabajoDe(item)) }
function esCambioTela(item) { return !!item._retapizar && trabajoDe(item) === 'tela' }

function elegirTrabajo(item, k) {
  item._trabajoFabrica = k
  if (k !== 'tela') {
    // Ni tela que apartar ni precio que cotizar.
    item._telaSelections = {}
    item._cotizarPrecio  = false
  }
}

/** ¿Es un ítem de stock que se puede mandar a cambiar de tela? */
function sePuedeRetapizar(i) {
  return !!i.producto_id && !i.es_personalizado && !i._fabricar_pedido
      && !i._producto_unico && !i._es_restauracion
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

async function onAgregarFotosItem(item, event) {
  const archivos = Array.from(event.target.files ?? [])
  event.target.value = ''
  // Una por una: comprimir varias a la vez es justo el pico de memoria que se
  // quiere evitar.
  for (const original of archivos) {
    const file = await comprimirAlTomar(original)
    item.boceto_blobs.push(file)
    item.boceto_urls.push('')
    item.boceto_previews.push(URL.createObjectURL(file))
  }
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
function getTemplate(item) {
  // Acepta un string (categoría) o el ítem completo; combina nombre + categoría
  // para reconocer mejor (ej. "Silla de comedor" no se confunde con mesa).
  const nombre    = typeof item === 'string' ? item : (item?.nombre ?? item?.nombre_custom)
  const categoria = typeof item === 'string' ? ''   : (item?.categoria ?? item?.categoria_custom)
  const key = resolverCategoria(nombre, categoria)
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
    if (item._es_restauracion) {
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
      const template = getTemplate(item)
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
        categoria:         resolverCategoria(item.nombre, item.categoria) || item.categoria || '',
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
const anticipo_pct          = ref(50)
const anticipo_monto        = ref(0)
const anticipo_metodo       = ref('efectivo')
const anticipo_referencia   = ref('')
const pagoSplit             = ref(false)
const anticipo_monto1_input = ref(0)
const anticipo_metodo2      = ref('transferencia')
const anticipo_referencia2  = ref('')

function togglePagoSplit() {
  pagoSplit.value = !pagoSplit.value
  if (pagoSplit.value) {
    // Pre-llenar con la mitad del anticipo para que el usuario solo ajuste
    const mitad = Math.floor(anticipo_monto.value / 2)
    anticipo_monto1_input.value = mitad
    anticipo_metodo2.value      = anticipo_metodo.value === 'efectivo' ? 'transferencia' : 'efectivo'
  }
}
const notas                = ref('')
// Fecha que el vendedor le prometió al cliente. Referencia para el supervisor,
// que es quien pone la fecha de entrega real de cada ítem.
const fechaSugeridaVendedor = ref('')
const esCompartida         = ref(false)
const covendedorId         = ref(null)

// Un independiente que cierra una venta con el contacto de un almacen: la
// mitad se le abona a esa tienda para su meta y la otra mitad queda para el.
const tiendaAbonadaId = ref(null)
const tiendasAbonables = computed(() =>
  tiendas.value.filter(t => !t.es_fabrica && !t.es_independientes && t.id !== tiendaId.value)
)
const vendedoresLista      = ref([])
const cargandoVendedores   = ref(false)
const submitting           = ref(false)

// Una clave por formulario, la misma en cada reintento. Si el internet se
// cae después de que el servidor guardó la orden, la respuesta no llega y la
// persona vuelve a darle: con la misma clave el servidor devuelve la que ya
// creó en vez de hacer otra. Se hace nueva solo al abrir otro formulario, y
// al recuperar un borrador se recupera también la suya: si la app se cerró
// justo cuando la orden se estaba creando, reenviar no la duplica.
let claveEnvio = globalThis.crypto?.randomUUID?.()
  ?? `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`
const modoGuardarBorrador  = ref(false)
const entregaInmediata     = ref(false)  // venta directa: el cliente se lleva los productos ya
const enviarPdfAlGuardarBorrador = ref(false) // true solo si se usó "Guardar borrador y enviar PDF"
const cooldown             = ref(0)   // segundos restantes antes de poder reintentar
let   cooldownTimer        = null

const borradorNecesitaContacto = ref(false)
const borradorEmailInput       = ref('')
const borradorTelefonoInput    = ref('')
const guardandoContactoBorrador = ref(false)

// Fotos del comprobante de pago: una o varias (dos transferencias, un
// pantallazo que no cabe en una captura). Cada una lleva su archivo, su
// vista previa y —cuando ya se subió— su url.
const facturaFotos         = ref([])   // [{ file, preview, url }]
const subiendoFactura      = ref(false)
// La primera, para lo que sigue esperando "la foto del comprobante".
const facturaFotoFile      = computed(() => facturaFotos.value[0]?.file ?? null)
const facturaFotoUrl       = computed(() => facturaFotos.value[0]?.url ?? '')

const firmaBlob            = ref(null)
const firmaUrl             = ref('')
watch(firmaBlob, () => { firmaUrl.value = '' })
// Firmó aquí, firmó el anexo, o ya venía firmada del borrador.
const tieneFirma = computed(() => !!firmaBlob.value || !!firmaUrl.value)

// ── Anexo de garantías firmado en el sistema ─────────────────────────────────
// En la tienda: el cliente lo lee y firma aquí mismo. A distancia: se le
// manda un enlace (WhatsApp, correo) y lo firma en su teléfono viendo el
// resumen del pedido; aquí aparece al instante. La firma del anexo sirve
// de firma de la orden: el cliente firma una sola vez.
// `anexo`: { id, modo, estado, url, firma_url, nombre_firmante, firmado_at, lo_que_vio }
const anexo = ref(null)
const anexoContenido     = ref(null)    // texto del anexo para el modal de la tienda
const anexoToken         = ref(null)    // el de la tienda: se firma por la misma ruta que el cliente
const anexoIdAqui        = ref(null)
const mostrarAnexoAqui   = ref(false)
const preparandoAnexo    = ref(false)
const firmandoAnexo      = ref(false)
const enviandoAnexoEmail = ref(false)
const emailAnexo         = ref('')
// La firma de la orden salió del anexo (y no del recuadro de abajo).
const firmaDesdeAnexo = computed(() => !!anexo.value?.firma_url && firmaUrl.value === anexo.value.firma_url && !firmaBlob.value)

/** Las líneas tal como viajan al crear la orden: con ellas se sabe si cambió. */
function lineasDeLaOrden() {
  return items.value.map(i => {
    const { cantidad, precio_unitario } = lineaParaEnviar(i, i._cotizarPrecio ? 0 : precioEfectivo(i))
    return {
      producto_id:   i.producto_id || null,
      nombre_custom: i.producto_id ? null : (i.nombre_custom || null),
      cantidad,
      precio_unitario,
    }
  })
}
function huellaLocal() {
  return JSON.stringify({ l: lineasDeLaOrden(), t: Math.round(valorTotal.value) })
}

/** Lo que el cliente ve de su pedido antes de firmar. */
function resumenParaCliente() {
  return {
    items: items.value.map(i => ({
      nombre:   i.nombre,
      detalle:  [i.variante_label, i._regalo ? 'Obsequio' : null, i._cotizarPrecio ? 'Precio por confirmar' : null].filter(Boolean).join(' · ') || null,
      cantidad: i.cantidad,
      precio:   i._cotizarPrecio ? 0 : precioEfectivo(i),
    })),
    descuentos:    Math.round((Number(descuentoTotal.value) || 0) + (Number(descuentoCondicionado.value) || 0)),
    total:         Math.round(valorTotal.value),
    anticipo:      Math.round(Number(anticipo_monto.value) || 0),
    fecha_entrega: fechaSugeridaVendedor.value || null,
    tienda:        tiendas.value.find(t => t.id == tiendaId.value)?.nombre ?? null,
    lineas:        lineasDeLaOrden(),
  }
}

// Si el cliente firmó a distancia y después se cambió la orden, lo que firmó
// ya no es lo que va. El servidor tampoco la deja crear así.
const anexoDesactualizado = computed(() =>
  anexo.value?.modo === 'remoto' && anexo.value?.lo_que_vio && anexo.value.lo_que_vio !== huellaLocal()
)

async function firmarAnexoAqui() {
  if (!clienteSeleccionado.value?.id || preparandoAnexo.value) return
  preparandoAnexo.value = true
  try {
    const { data } = await crearAnexo({ cliente_id: clienteSeleccionado.value.id, modo: 'presencial' })
    const { data: pub } = await getAnexoPublico(data.token)
    anexoIdAqui.value    = data.id
    anexoToken.value     = data.token
    anexoContenido.value = pub
    mostrarAnexoAqui.value = true
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo abrir el anexo.')
  } finally {
    preparandoAnexo.value = false
  }
}

async function guardarAnexoAqui(payload) {
  firmandoAnexo.value = true
  try {
    await firmarAnexo(anexoToken.value, {
      secciones: payload.secciones, checklist: payload.checklist,
      nombre: payload.nombre, documento: payload.documento, firma: payload.firma,
    })
    const { data } = await getAnexo(anexoIdAqui.value)
    ponerAnexo(data)
    mostrarAnexoAqui.value = false
    toast.success('Anexo firmado.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar el anexo. Vuelve a darle.')
  } finally {
    firmandoAnexo.value = false
  }
}

async function enviarAnexoAlCliente() {
  if (!clienteSeleccionado.value?.id || preparandoAnexo.value) return
  if (!items.value.length) { toast.error('Agrega los productos antes de enviárselo al cliente.'); return }
  preparandoAnexo.value = true
  try {
    const lo_que_vio = huellaLocal()
    const { data } = await crearAnexo({ cliente_id: clienteSeleccionado.value.id, modo: 'remoto', resumen: resumenParaCliente() })
    anexo.value = { ...data, lo_que_vio }
    emailAnexo.value = clienteSeleccionado.value?.email ?? ''
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo preparar el enlace.')
  } finally {
    preparandoAnexo.value = false
  }
}

function ponerAnexo(data) {
  const antes = anexo.value
  anexo.value = { ...data, lo_que_vio: antes?.id === data.id ? antes.lo_que_vio : null }
  // Una sola firma: la del anexo queda como firma de la orden si todavía
  // no se firmó abajo.
  if (data.estado === 'firmado' && data.firma_url && !firmaBlob.value && !firmaUrl.value) {
    firmaUrl.value = data.firma_url
  }
}

function quitarAnexo() {
  if (firmaDesdeAnexo.value) firmaUrl.value = ''
  anexo.value = null
}

function mensajeWhatsAppAnexo() {
  const nombre = clienteSeleccionado.value?.nombre?.split(' ')[0] ?? ''
  return `Hola${nombre ? ` ${nombre}` : ''}, soy ${auth.usuario?.nombre ?? 'tu asesor'} de Decasa. ` +
    `Aquí puedes revisar tu pedido y firmar el documento de garantías: ${anexo.value?.url}`
}
function abrirWhatsAppAnexo() {
  let tel = String(clienteSeleccionado.value?.telefono ?? '').replace(/\D/g, '')
  if (tel.length === 10) tel = `57${tel}`
  const url = `https://wa.me/${tel}?text=${encodeURIComponent(mensajeWhatsAppAnexo())}`
  window.open(url, '_blank', 'noopener')
}
async function enviarCorreoAnexo() {
  if (!anexo.value?.id || enviandoAnexoEmail.value) return
  enviandoAnexoEmail.value = true
  try {
    const { data } = await enviarAnexoEmail(anexo.value.id, emailAnexo.value.trim())
    toast.success(data.message ?? 'Enviado.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo enviar el correo.')
  } finally {
    enviandoAnexoEmail.value = false
  }
}
async function copiarEnlaceAnexo() {
  try {
    await navigator.clipboard.writeText(anexo.value.url)
    toast.success('Enlace copiado.')
  } catch {
    toast.info(anexo.value.url)
  }
}
async function verPdfAnexo() {
  try {
    const res = await pdfAnexo(anexo.value.id)
    window.open(URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' })), '_blank')
  } catch {
    toast.error('No se pudo abrir el PDF del anexo.')
  }
}

// Esperar la firma del cliente: al instante por el canal del anexo y, por si
// la conexión en vivo se cae, preguntando cada pocos segundos.
let _canalAnexo = null
let _sondeoAnexo = null
async function refrescarAnexo() {
  if (!anexo.value?.id) return
  try {
    const { data } = await getAnexo(anexo.value.id)
    const yaEstaba = anexo.value?.estado === 'firmado'
    ponerAnexo(data)
    if (!yaEstaba && data.estado === 'firmado') {
      toast.success(`${data.nombre_firmante ?? 'El cliente'} firmó ✓`)
    }
  } catch { /* se vuelve a intentar en el siguiente sondeo */ }
}
function dejarDeEsperarAnexo() {
  if (_canalAnexo && window.Echo) window.Echo.leave(_canalAnexo)
  _canalAnexo = null
  clearInterval(_sondeoAnexo)
  _sondeoAnexo = null
}
watch(() => [anexo.value?.id, anexo.value?.estado], ([id, estado]) => {
  dejarDeEsperarAnexo()
  if (!id || estado !== 'pendiente') return
  if (window.Echo) {
    _canalAnexo = `anexo.${id}`
    window.Echo.channel(_canalAnexo).listen('.anexo.firmado', refrescarAnexo)
  }
  _sondeoAnexo = setInterval(() => {
    if (document.visibilityState === 'visible') refrescarAnexo()
  }, 5000)
}, { immediate: true })
onBeforeUnmount(dejarDeEsperarAnexo)

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
  { value: 'addi',          label: 'Addi' },
  { value: 'otro',          label: 'Otro' },
]

/**
 * Precio unitario ya con el descuento del ítem aplicado. El descuento se puede
 * escribir en pesos (por unidad) o en porcentaje; el resultado nunca baja de 0.
 */
function precioEfectivo(item) {
  if (item._regalo) return 0
  const base  = item.precio_unitario ?? 0
  const valor = Number(item._descuento_valor) || 0
  if (!valor) return base

  const rebaja = (item._descuento_modo ?? 'monto') === 'pct'
    ? Math.round(base * valor / 100)
    : Math.round(valor)

  return Math.max(0, base - rebaja)
}

/** Cuánto se le rebajó a una unidad de este ítem, en pesos. */
function descuentoItemMonto(item) {
  if (item._regalo) return 0
  return Math.max(0, (item.precio_unitario ?? 0) - precioEfectivo(item))
}

/** Pesos con separador de miles, sin decimales: 1.020.000 */
function pesos(v) {
  return new Intl.NumberFormat('es-CO').format(Math.round(Number(v) || 0))
}

// Marca/desmarca un ítem como regalo (cortesía). Un regalo vale $0 pero igual
// descuenta inventario si es un producto de stock (se entrega una unidad real).
function toggleRegalo(item) {
  item._regalo = !item._regalo
  if (item._regalo) {
    item._cotizarPrecio = false
    item._descuento_valor = 0
  }
}

const subtotalItems = computed(() =>
  items.value.reduce((s, i) => i._cotizarPrecio ? s : s + i.cantidad * precioEfectivo(i), 0)
)
// ── Descuento global al total ────────────────────────────────────────────────
// Se puede escribir en pesos o en porcentaje: el vendedor negocia "te descuento
// cien mil", no "te descuento 7,4%". Lo que se guarda siempre es el monto.
const descuentoModo  = ref('monto')   // 'monto' | 'pct'
const descuentoInput = ref(0)

/**
 * Un porcentaje por encima de 100 no significa nada, y sin tope se cuela un
 * error caro: escribir "90000" pensando en $90.000 mientras el campo está en %
 * daba 90.000% → se recortaba a 100% y la orden quedaba en $0 sin avisar.
 */
function pctValido(v) {
  return Math.min(Math.max(0, Number(v) || 0), 100)
}

const descuentoTotal = computed(() => {
  const v = Number(descuentoInput.value) || 0
  const bruto = descuentoModo.value === 'pct'
    ? montoDePct(pctValido(v), subtotalItems.value)
    : Math.round(Math.max(0, v))
  return Math.min(bruto, subtotalItems.value)
})

const descuentoPct = computed(() => pctDeMonto(descuentoTotal.value, subtotalItems.value))

// Descuento condicionado: solo vale si paga en efectivo o transferencia. Se
// calcula sobre la base ya rebajada por el descuento comercial, igual que en el
// backend, que es quien manda el cálculo final.
const descuentoCondModo  = ref('monto')
const descuentoCondInput = ref(0)

const baseCondicionado = computed(() =>
  Math.max(0, subtotalItems.value - descuentoTotal.value)
)

const descuentoCondicionado = computed(() => {
  const v = Number(descuentoCondInput.value) || 0
  const bruto = descuentoCondModo.value === 'pct'
    ? montoDePct(pctValido(v), baseCondicionado.value)
    : Math.round(Math.max(0, v))
  return Math.min(bruto, baseCondicionado.value)
})

/**
 * Se escribió más descuento del que cabe: el monto se recortó. Antes se
 * recortaba en silencio y el vendedor no se enteraba de que el total quedó en
 * cero hasta ver la orden guardada.
 */
const descuentoRecortado = computed(() => {
  const v = Number(descuentoCondInput.value) || 0
  if (baseCondicionado.value <= 0) return false
  // Se mira el valor CRUDO, antes de toparlo: 90000 en % ya viene recortado a
  // 100 y sin esto pasaría por un descuento normal.
  return descuentoCondModo.value === 'pct'
    ? v > 100
    : Math.round(v) > baseCondicionado.value
})

/** El total quedaría en cero: casi siempre es un error de digitación. */
const totalEnCero = computed(() =>
  subtotalItems.value > 0 && valorTotal.value === 0
)

const descuentoCondicionadoPct = computed(() =>
  pctDeMonto(descuentoCondicionado.value, baseCondicionado.value)
)

const valorTotal = computed(() =>
  Math.max(0, baseCondicionado.value - descuentoCondicionado.value)
)

const minimoAnticipo = computed(() =>
  Math.ceil(valorTotal.value * anticipo_pct.value / 100)
)

// Si hay ítems con cotización pendiente, el anticipo mínimo es 0
// (no se puede cobrar anticipo de un precio desconocido)
const minimoAnticipofEfectivo = computed(() =>
  hayItemsCotizar.value ? 0 : minimoAnticipo.value
)

// Porcentaje que representa el monto actual sobre el total (para mostrar al vendedor)
const anticipoPctActual = computed(() => {
  if (!valorTotal.value || !anticipo_monto.value) return 0
  return Math.round((anticipo_monto.value / valorTotal.value) * 100)
})


// ── Cotización de costo durante la creación ───────────────────────────────────
const cotizarReceptorId   = ref(null)
const cotizarNotas        = ref('')
const receptoresCotizar   = ref([])
const cargandoReceptores  = ref(false)

const hayItemsCotizar = computed(() =>
  items.value.some(i => i._cotizarPrecio)
)

// "Se lo lleva ahora" es por producto: lo que ya existe —de inventario, o el
// mueble único que está en el local— puede salir con el cliente hoy; lo que
// hay que fabricar, no. Antes era de toda la orden y bloqueaba la venta si
// había algo para fabricar (el reloj con el comedor).
function sePuedeLlevar(i) {
  // El que se manda a cambiar de tela existe, pero se va a la fábrica.
  if (i._retapizar) return false
  return !!i._producto_unico || (!i.es_personalizado && !!i.producto_id)
}
const itemsQueSePuedenLlevar = computed(() => items.value.filter(sePuedeLlevar))
const puedeEntregaInmediata  = computed(() => itemsQueSePuedenLlevar.value.length > 0)
const seLlevaTodo = computed(() =>
  items.value.length > 0 && items.value.every(i => sePuedeLlevar(i) && i._llevar_ahora)
)
const seLlevaAlgo = computed(() => items.value.some(i => sePuedeLlevar(i) && i._llevar_ahora))

// El interruptor de arriba marca (o desmarca) todo lo que se pueda llevar.
function marcarTodoParaLlevar(si) {
  entregaInmediata.value = si
  for (const i of items.value) if (sePuedeLlevar(i)) i._llevar_ahora = si
}
// Y si se desmarca uno a mano, el interruptor deja de decir "todo".
watch(() => items.value.map(i => sePuedeLlevar(i) && i._llevar_ahora), () => {
  const marcables = itemsQueSePuedenLlevar.value
  entregaInmediata.value = marcables.length > 0 && marcables.every(i => i._llevar_ahora)
}, { deep: true })

watch(esCompartida, async (val) => {
  if (val && !vendedoresLista.value.length) {
    cargandoVendedores.value = true
    try {
      const { data } = await api.get('/asesores')
      vendedoresLista.value = data
    } catch { vendedoresLista.value = [] }
    finally { cargandoVendedores.value = false }
  }
  if (!val) covendedorId.value = null
})

async function cargarReceptoresCotizar() {
  if (receptoresCotizar.value.length) return
  cargandoReceptores.value = true
  try {
    const { data } = await getReceptores()
    receptoresCotizar.value = data
  } catch { receptoresCotizar.value = [] }
  finally { cargandoReceptores.value = false }
}

async function irAPaso3() {
  anticipo_monto.value = minimoAnticipo.value
  step.value = 3
  if (hayItemsCotizar.value) await cargarReceptoresCotizar()
}

// En una cotización se guarda desde el paso 2 y nunca se pasa al 3, que es
// donde vivía el bloque de consulta de costo. Por eso una cotización no podía
// preguntar el precio: los receptores se cargan aquí en cuanto hacen falta.
watch(hayItemsCotizar, (hay) => {
  if (hay && modoCotizacion.value) cargarReceptoresCotizar()
}, { immediate: true })

/**
 * Lo que tiene que estar bien antes de crear la orden. Aparte de submit()
 * para correrlo también antes de mostrar el resumen: no tiene sentido pedirle
 * al vendedor que revise algo que igual no se va a poder crear.
 * Devuelve false (y avisa por qué) si algo falta.
 */
async function validarParaCrear() {
  if (clienteRequiereCompletar.value && !modoGuardarBorrador.value) {
    toast.error('Completa los datos del cliente antes de crear la orden.')
    return false
  }

  const sinPrecio = items.value.filter(i => (i.es_personalizado || i._retapizar) && !i.precio_unitario && !i._cotizarPrecio && !i._regalo)
  if (sinPrecio.length) {
    toast.error(`${sinPrecio.length} producto(s) sin precio. Usa el cotizador IA, ingresa el precio manualmente, o activa "Consultar precio".`)
    return false
  }

  // Sin tela nueva el taller no sabe qué hacerle al sofá.
  const sinTelaNueva = items.value.filter(i => esCambioTela(i) && !telaResumidaCampo(i, 'tela'))
  if (sinTelaNueva.length) {
    toast.error(`Elige la tela nueva de "${sinTelaNueva[0].nombre}" para mandarlo a retapizar.`)
    return false
  }

  // Lo mismo con el color o el arreglo: sin decir qué, el taller no sabe qué hacerle.
  const sinQueHacer = items.value.filter(i => i._retapizar && !esCambioTela(i) && !(i.specs_notas ?? '').trim())
  if (sinQueHacer.length) {
    toast.error(`Di qué hay que hacerle a "${sinQueHacer[0].nombre}" en la fábrica.`)
    return false
  }

  if (hayItemsCotizar.value && !consultaYaPedida.value && !cotizarReceptorId.value) {
    toast.error('Selecciona a quién enviar la consulta de costo antes de continuar.')
    return false
  }

  if (esCompartida.value && !covendedorId.value) {
    toast.error('Selecciona el co-vendedor para la venta compartida.')
    return false
  }

  if (!modoGuardarBorrador.value && comprobantesRequeridos.value === 2 && faltanComprobantes.value) {
    toast.error('El pago va en dos métodos: sube el comprobante de cada uno.')
    return false
  }

  // Validar disponibilidad de tela para lo que el taller va a tapizar: lo que
  // se fabrica bajo pedido, lo que se personaliza con tela y lo que se manda
  // a cambiar de tela. Con el descuento automático encendido (Telas →
  // Consumo por producto) el servidor además dice cuántos metros necesita
  // el producto y si alcanzan; el mismo control lo repite al crear la orden.
  for (const item of items.value) {
    // Lo que seguro se tapiza en el taller se valida como siempre: que haya
    // algo. Un personalizado con tela elegida solo se frena si el servidor
    // sabe cuánto gasta y no alcanza; sin eso sigue pasando como antes.
    const tapizaSeguro = (item._fabricar_pedido && item._esTapizado) || esCambioTela(item)
    if (!tapizaSeguro && !(item.es_personalizado && item.producto_id)) continue
    const sel = item._telaSelections?.tela
    if (!sel || !sel.tipo || sel.tipo === 'Otro' || sel.marca === 'Otro' || !sel.color) continue
    try {
      const { data: tv } = await api.get('/inventario-telas/validar', {
        params: {
          marca: sel.marca, tipo: sel.tipo, color: sel.color,
          producto_id: item.producto_id || undefined, config_id: item._config_id || undefined, cantidad: item.cantidad,
        },
      })
      if (tapizaSeguro && !tv.disponible) {
        toast.error(`No hay metros disponibles de "${sel.tipo} – ${sel.color}". Elige otra tela o contacta al encargado.`)
        return false
      }
      if (tv.metros_necesarios != null && !tv.suficiente) {
        toast.error(`"${item.nombre}" ×${item.cantidad} necesita ${tv.metros_necesarios} m de "${sel.tipo} – ${sel.color}" y solo hay ${tv.metros} m libres. Elige otra tela o recarga el inventario de telas.`)
        return false
      }
    } catch {
      // No bloquear si falla la validación por error de red
    }
  }

  return true
}

// ── Revisar antes de crear ───────────────────────────────────────────────────
// "Crear orden" no crea de una: muestra todo lo que se va a guardar —cliente,
// productos, precios, descuentos, anticipo, si es FV2…— para que el vendedor
// lo revise con calma. "Volver" deja todo como estaba para corregir; solo
// "Confirmar y crear" la crea.
const mostrarResumen = ref(false)

const METODO_LABEL = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta: 'Tarjeta', addi: 'Addi', otro: 'Otro' }

// Con el pago dividido en dos métodos hay dos comprobantes. Ya se podían subir
// varias fotos, pero la segunda se agregaba con un enlace chiquito que nadie
// veía (y desde la cámara sale una foto a la vez): parecía que solo dejaba
// una. Ahora se pide cada comprobante con el pago al que corresponde.
const pagosDivididos = computed(() => {
  if (!pagoSplit.value) return null
  const monto1 = Number(anticipo_monto1_input.value) || 0
  return [
    { metodo: METODO_LABEL[anticipo_metodo.value]  ?? anticipo_metodo.value,  monto: monto1 },
    { metodo: METODO_LABEL[anticipo_metodo2.value] ?? anticipo_metodo2.value, monto: Math.max(0, (Number(anticipo_monto.value) || 0) - monto1) },
  ]
})

/** El pago cuyo comprobante sigue, mientras falte alguno de los dos. */
const pagoSinComprobante = computed(() => {
  const pagos = pagosDivididos.value
  const n = facturaFotos.value.length
  return pagos && n < pagos.length ? { n: n + 1, ...pagos[n] } : null
})

// Pago dividido de verdad (los dos montos con algo y sin precios por
// consultar, que es cuando se registran los dos abonos): un comprobante por
// pago. Si no, basta uno, como siempre.
const comprobantesRequeridos = computed(() =>
  pagosDivididos.value && !hayItemsCotizar.value && pagosDivididos.value.every(p => p.monto > 0) ? 2 : 1
)
const faltanComprobantes = computed(() => facturaFotos.value.length < comprobantesRequeridos.value)

// Todo sale de lo que ya calcula la pantalla (precioEfectivo, valorTotal…):
// el resumen tiene que decir exactamente lo que se va a guardar.
const resumenOrden = computed(() => {
  const c = clienteSeleccionado.value
  const productos = items.value.map(i => {
    const etiquetas = []
    if (i._regalo)                                 etiquetas.push('Obsequio')
    if (i._cotizarPrecio)                          etiquetas.push('Precio por consultar')
    if (i._producto_unico)                         etiquetas.push('Mueble único')
    else if (i.producto_id === null)               etiquetas.push('Diseño especial')
    else if (i._fabricar_pedido)                   etiquetas.push('Para fabricar')
    else if (i.es_personalizado)                   etiquetas.push('Personalizado')
    if (i._retapizar)                              etiquetas.push(`Llevar a fábrica: ${trabajoInfo(i).label}`)
    if (sePuedeLlevar(i) && i._llevar_ahora)       etiquetas.push('Se lo lleva hoy')
    if (i.tienda_origen)                           etiquetas.push(`Sale de ${i.tienda_origen}`)
    return {
      nombre:    i.nombre,
      variante:  [i.variante_label, textoJuegoItem(i)].filter(Boolean).join(' · ') || null,
      cantidad:  i.cantidad,
      precio:    i._cotizarPrecio ? null : Number(i.precio_unitario || 0),
      final:     i._cotizarPrecio ? null : precioEfectivo(i),
      rebaja:    i._cotizarPrecio ? 0 : descuentoItemMonto(i),
      subtotal:  i._cotizarPrecio ? null : i.cantidad * precioEfectivo(i),
      etiquetas,
      detalle:   [telaResumidaCampo(i, 'tela'), (i.specs_notas ?? '').trim()].filter(Boolean).join(' · ') || null,
    }
  })

  const conAnticipo = !hayItemsCotizar.value && Number(anticipo_monto.value) > 0
  const abonos = !conAnticipo ? [] : pagoSplit.value
    ? [
        { monto: Number(anticipo_monto1_input.value) || 0, metodo: METODO_LABEL[anticipo_metodo.value] ?? anticipo_metodo.value },
        { monto: Math.max(0, Number(anticipo_monto.value) - (Number(anticipo_monto1_input.value) || 0)), metodo: METODO_LABEL[anticipo_metodo2.value] ?? anticipo_metodo2.value },
      ].filter(a => a.monto > 0)
    : [{ monto: Number(anticipo_monto.value), metodo: METODO_LABEL[anticipo_metodo.value] ?? anticipo_metodo.value }]
  const anticipo = abonos.reduce((s, a) => s + a.monto, 0)

  return {
    cliente:     { nombre: c?.nombre ?? '—', telefono: c?.telefono ?? null, cedula: c?.cedula ?? null },
    tienda:      tiendas.value.find(t => t.id == tiendaId.value)?.nombre ?? '—',
    canal:       canalesopts.find(o => o.value === canal.value)?.label ?? canal.value,
    fv2:         esFv2.value ? { motivo: motivoSerie.value.trim() || null, sinIva: !!fv2SinIva.value } : null,
    compartida:  esCompartida.value ? (vendedoresLista.value.find(v => v.id === covendedorId.value)?.nombre ?? 'otro asesor') : null,
    abonadaA:    tiendaAbonadaId.value ? (tiendas.value.find(t => t.id == tiendaAbonadaId.value)?.nombre ?? null) : null,
    productos,
    subtotal:    subtotalItems.value,
    descuento:   Number(descuentoTotal.value) || 0,
    descuentoCondicionado: Number(descuentoCondicionado.value) || 0,
    total:       valorTotal.value,
    hayCotizar:  hayItemsCotizar.value,
    consultaA:   hayItemsCotizar.value ? (receptoresCotizar.value.find(r => r.id === cotizarReceptorId.value)?.nombre ?? null) : null,
    abonos,
    anticipo,
    saldo:       Math.max(0, valorTotal.value - anticipo),
    fechaEntrega: fechaSugeridaVendedor.value || null,
    envio:       [direccionEnvio.value, ciudadEnvio.value, departamentoEnvio.value].map(s => (s ?? '').trim()).filter(Boolean).join(', ') || null,
    notas:       notas.value.trim() || null,
    fotosFactura: facturaFotos.value.length,
    firma:       tieneFirma.value,
    seLlevaTodo: seLlevaTodo.value,
    seLlevaAlgo: seLlevaAlgo.value,
  }
})

async function revisarAntesDeCrear() {
  if (submitting.value || subiendoFactura.value || cooldown.value > 0) return
  modoGuardarBorrador.value = false
  if (!(await validarParaCrear())) return
  mostrarResumen.value = true
}

function confirmarYCrear() {
  mostrarResumen.value = false
  submit()
}

async function submit() {
  if (submitting.value || subiendoFactura.value || cooldown.value > 0) return
  if (!(await validarParaCrear())) return

  submitting.value = true
  try {
    // Subir las fotos del comprobante que falten (no aplica para borrador).
    // Una por una: si se cae la red a mitad, las que ya subieron se quedan.
    if (!modoGuardarBorrador.value && facturaFotos.value.some(f => !f.url)) {
      subiendoFactura.value = true
      for (const f of facturaFotos.value) {
        if (f.url) continue
        const fd = new FormData()
        fd.append('foto', await comprimirImagen(f.file), 'factura.jpg')
        fd.append('folder', 'facturas')
        const { data: uploadData } = await api.post('/upload/foto', fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })
        f.url = uploadData.url
      }
      subiendoFactura.value = false
    }

    // Bocetos/fotos de ítems personalizados (y del que se retapiza): subir
    // los que tengan blob pendiente
    for (const item of items.value) {
      if (item.es_personalizado || item._retapizar) {
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
      clave_envio:          claveEnvio,
      borrador_id:          borradorServidor.value?.id || undefined,
      cliente_id:           clienteSeleccionado.value.id,
      tienda_id:            tiendaId.value,
      canal:                canal.value,
      tipo:                 tipoOrden.value,
      anticipo_pct:         anticipo_pct.value,
      anticipo_monto:       (modoGuardarBorrador.value || hayItemsCotizar.value) ? 0 : anticipo_monto.value,
      anticipo_metodo:      anticipo_metodo.value,
      anticipo_referencia:  pagoSplit.value ? undefined : (anticipo_referencia.value || undefined),
      ...(pagoSplit.value && !(modoGuardarBorrador.value || hayItemsCotizar.value) && anticipo_monto.value > 0 ? {
        anticipo_pagos: [
          { monto: anticipo_monto1_input.value,                                                      metodo: anticipo_metodo.value,  referencia: anticipo_referencia.value  || undefined },
          { monto: Math.max(0, anticipo_monto.value - anticipo_monto1_input.value), metodo: anticipo_metodo2.value, referencia: anticipo_referencia2.value || undefined },
        ].filter(p => p.monto > 0)
      } : {}),
      guardar_borrador:     modoGuardarBorrador.value || undefined,
      es_fv2:               esFv2.value || undefined,
      motivo_serie:         esFv2.value ? (motivoSerie.value.trim() || undefined) : undefined,
      fv2_sin_iva:          (esFv2.value && auth.usuario?.puede_fv2_sin_iva && fv2SinIva.value) || undefined,
      // Se manda el monto, no el %: si el vendedor escribió "100.000" el backend
      // debe guardar esos 100.000 exactos, no recalcularlos desde un % redondeado.
      descuento_condicionado_monto: Number(descuentoCondicionado.value) > 0
        ? Number(descuentoCondicionado.value)
        : undefined,
      // Solo cuando se lo lleva TODO (la marca vieja de toda la orden); lo
      // demás va producto por producto en `llevar_ahora`.
      entrega_inmediata:    seLlevaTodo.value || undefined,
      descuento_total:      Number(descuentoTotal.value) > 0 ? Number(descuentoTotal.value) : undefined,
      notas:                notas.value || undefined,
      fecha_sugerida_vendedor: fechaSugeridaVendedor.value || undefined,
      es_compartida:        esCompartida.value || undefined,
      tienda_abonada_id:    (auth.isIndependiente && tiendaAbonadaId.value) ? tiendaAbonadaId.value : undefined,
      covendedor_id:        (esCompartida.value && covendedorId.value) ? covendedorId.value : undefined,
      factura_foto_url:     facturaFotoUrl.value  || undefined,
      factura_fotos:        facturaFotos.value.map(f => f.url).filter(Boolean),
      firma_url:            firmaUrl.value        || undefined,
      anexo_foto_url:       anexoFotoUrl.value    || undefined,
      anexo_id:             anexo.value?.estado === 'firmado' ? anexo.value.id : undefined,
      departamento_envio:   departamentoEnvio.value || undefined,
      ciudad_envio:         ciudadEnvio.value || undefined,
      direccion_envio:      direccionEnvio.value || undefined,
      items: items.value.map((i) => ({
        // El que ya estaba en el borrador se actualiza en su sitio.
        id:                      (borradorServidor.value && i.id) || undefined,
        producto_id:             i.producto_id || undefined,
        nombre_custom:           i.nombre_custom || undefined,
        categoria_custom:        i.categoria_custom || undefined,
        variante_id:             i.variante_id || undefined,
        combo_config_id:         i._config_id  || undefined,
        // Qué se eligió, escrito: la medida de la cama, la tela. Sin esto la
        // orden y el PDF salían con el nombre del mueble a secas.
        variante_detalle:        i.variante_label || undefined,
        tienda_origen_id:        i.tienda_origen_id || undefined,
        // En piezas si va en juego: así se cuenta el stock (ver lineaParaEnviar).
        ...lineaParaEnviar(i, i._cotizarPrecio ? 0 : precioEfectivo(i)),
        es_personalizado:        i.es_personalizado,
        fabricar_pedido:         i._fabricar_pedido || undefined,
        es_restauracion:         i._es_restauracion || undefined,
        // Ya está hecho: el backend lo guarda sin crearle producción.
        producto_unico:          i._producto_unico || undefined,
        // De stock, pero se va a la fábrica a cambiarle la tela: el backend
        // lo aparta como catálogo y además le crea producción.
        retapizar:               (sePuedeRetapizar(i) && i._retapizar) || undefined,
        // Sale con el cliente hoy. Por producto: el reloj sí, el comedor no.
        llevar_ahora:            (sePuedeLlevar(i) && i._llevar_ahora) || undefined,
        // El obsequio se manda, no se deduce del precio: en $0 tambien esta
        // lo que todavia espera cotizacion, y son cosas distintas.
        es_regalo:               i._regalo || undefined,
        fecha_entrega_prometida: i.fecha_entrega_prometida || undefined,
        specs_personalizacion:   (i.es_personalizado || i._retapizar)
          ? (() => {
              const s = { ...i.specs }
              // Un arreglo o un cambio de color no lleva tela nueva: si se
              // colara una, el servidor le apartaría metros que nadie usa.
              if (!i._retapizar || esCambioTela(i)) {
                for (const key of Object.keys(i._telaSelections ?? {})) {
                  const tela = telaResumidaCampo(i, key)
                  if (tela) s[key] = tela
                }
              }
              if (i.specs_notas) s.notas = i.specs_notas
              if (i._retapizar) s.trabajo = trabajoDe(i)
              return Object.keys(s).length ? s : undefined
            })()
          : undefined,
        boceto_urls:             (i.es_personalizado || i._retapizar) && i.boceto_urls.some(Boolean)
          ? i.boceto_urls.filter(Boolean)
          : undefined,
      })),
    }

    const { data } = await api.post('/ordenes', payload)
    // Ya está en el servidor: el respaldo del teléfono sobra.
    await borradorLocal.borrar()

    // Crear consulta de costo si hay ítems marcados para cotizar (si el
    // borrador ya la tenía pedida, esa sigue: los ítems son los mismos).
    if (hayItemsCotizar.value && !consultaYaPedida.value && cotizarReceptorId.value && data?.id) {
      try {
        await crearConsulta({
          orden_id:          data.id,
          asignado_a_id:     cotizarReceptorId.value,
          notas_adicionales: cotizarNotas.value.trim() || null,
        })
      } catch { /* La orden se creó bien — la consulta puede reintentarse desde el detalle */ }
    }

    // Ya había llegado (un reintento con la misma clave): a la que existe.
    if (data?.orden_id) {
      if (data.ya_existia) toast.success(data.message ?? 'Esta orden ya se había registrado. No se creó otra.')
      router.push({ name: 'orden-detalle', params: { id: data.orden_id } })
    } else if (modoGuardarBorrador.value && data?.id) {
      if (enviarPdfAlGuardarBorrador.value) {
        const emailCliente = clienteSeleccionado.value?.email
        if (emailCliente) {
          try {
            await api.post(`/ordenes/${data.id}/reenviar-cotizacion`, { email: emailCliente })
            toast.success('Borrador guardado y cotización enviada por email.')
          } catch {
            toast.success('Borrador guardado. Comparte el PDF desde el detalle de la orden.')
          }
        } else {
          toast.success('Borrador guardado.')
        }
      } else {
        toast.success('Borrador guardado.')
      }
      router.push({ name: 'orden-detalle', params: { id: data.id } })
    } else if (borradorServidor.value && data?.id) {
      // Se terminó un borrador: a la orden, que es la que se venía siguiendo.
      toast.success('Orden confirmada.')
      router.push({ name: 'orden-detalle', params: { id: data.id } })
    } else {
      router.push({ name: 'ordenes' })
    }
  } catch (e) {
    const status = e.response?.status
    // El borrador ya lo confirmó alguien más (otra pestaña, el covendedor).
    if (status === 422 && borradorServidor.value && e.response?.data?.orden_id) {
      await borradorLocal.borrar()
      toast.error(e.response.data.message)
      router.push({ name: 'orden-detalle', params: { id: e.response.data.orden_id } })
      return
    }
    if (status === 409 && e.response?.data?.orden_id) {
      // Orden ya creada — ir a ella en vez de mostrar error
      await borradorLocal.borrar()
      router.push({ name: 'orden-detalle', params: { id: e.response.data.orden_id } })
      return
    }
    const errores = e.response?.data?.errors
    const detalle = errores ? ' · ' + Object.entries(errores).map(([k, v]) => `${k}: ${v[0]}`).join(', ') : ''
    // Sin respuesta del servidor no es lo mismo que un 422: puede ser que la
    // petición nunca salió (un error de JS armando la orden) o que se cortó
    // por tiempo. Decir cuál es lo que permite saber dónde buscar.
    let mensaje = e.response?.data?.message
    if (!mensaje) {
      if (e.response) {
        mensaje = `Error del servidor (${e.response.status}) al crear la orden`
      } else if (e.isAxiosError) {
        // Reintentar desde esta misma pantalla es seguro: va con la misma
        // clave, así que si la orden sí quedó creada no se duplica.
        mensaje = e.code === 'ECONNABORTED'
          ? 'El servidor tardó demasiado en responder. Vuelve a darle desde aquí: si la orden ya quedó creada, te lleva a ella sin duplicarla.'
          : `Sin conexión con el servidor (${e.code ?? e.message}). Cuando vuelva el internet, dale otra vez desde aquí: si la orden ya quedó creada, te lleva a ella sin duplicarla.`
      } else {
        mensaje = `Error en la pantalla al armar la orden: ${e.message}`
      }
    }
    toast.error(mensaje + detalle)
    console.error('Error al crear la orden:', e, e.response?.data)
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
    enviarPdfAlGuardarBorrador.value = false
  }
}

/**
 * Guardar como cotización: no reserva stock, no pide firma ni anticipo y el
 * cliente puede quedar en blanco. Reusa el mismo carrito y los mismos ítems.
 */
async function submitCotizacion() {
  if (submitting.value) return

  if (!items.value.length) {
    toast.error('Agrega al menos un producto antes de guardar la cotización.')
    return
  }

  const sinPrecio = items.value.filter(i => !precioEfectivo(i) && !i._regalo)
  if (sinPrecio.length) {
    toast.error(`${sinPrecio.length} ítem(s) sin precio. Una cotización necesita todos los precios definidos.`)
    return
  }

  // La cotización no guarda la marca de cambio de tela: al convertirla el
  // sofá saldría como venta de stock normal, sin pasar por la fábrica. Mejor
  // avisar que perderlo callado.
  if (items.value.some(i => i._retapizar)) {
    toast.error('Lo que se lleva a la fábrica solo se puede registrar como orden, no como cotización. Quita esa marca o crea la orden.')
    return
  }

  submitting.value = true
  try {
    // Bocetos pendientes de subir (mismo tratamiento que en una orden)
    for (const item of items.value) {
      if (!item.es_personalizado) continue
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

    const payload = {
      clave_envio:       claveEnvio,
      cliente_id:        clienteSeleccionado.value?.id || undefined,
      contacto_nombre:   clienteSeleccionado.value ? undefined : (contactoNombre.value.trim()   || undefined),
      contacto_telefono: clienteSeleccionado.value ? undefined : (contactoTelefono.value.trim() || undefined),
      contacto_email:    clienteSeleccionado.value ? undefined : (contactoEmail.value.trim()    || undefined),
      tienda_id:         tiendaId.value,
      canal:             canal.value,
      notas:             notas.value || undefined,
      descuento_total:   Number(descuentoTotal.value) > 0 ? Number(descuentoTotal.value) : undefined,
      dias_vigencia:     Number(diasVigencia.value) || undefined,
      items: items.value.map((i) => ({
        producto_id:           i.producto_id || undefined,
        nombre_custom:         i.nombre_custom || undefined,
        categoria_custom:      i.categoria_custom || undefined,
        variante_id:           i.variante_id || undefined,
        combo_config_id:       i._config_id || undefined,
        variante_detalle:      i.variante_label || undefined,
        tienda_origen_id:      i.tienda_origen_id || undefined,
        // En piezas si va en juego (cantidad, precio y venta_juego).
        ...lineaParaEnviar(i, precioEfectivo(i)),
        es_personalizado:      i.es_personalizado,
        fabricar_pedido:       i._fabricar_pedido || undefined,
        producto_unico:        i._producto_unico || undefined,
        specs_personalizacion: i.es_personalizado
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
        boceto_urls: i.es_personalizado && i.boceto_urls.some(Boolean)
          ? i.boceto_urls.filter(Boolean)
          : undefined,
      })),
    }

    const { data } = await api.post('/cotizaciones', payload)
    await borradorLocal.borrar()

    // Ya había llegado (un reintento): se va a la que existe, sin pedir otra
    // vez la consulta de costo.
    if (data?.ya_existia && data?.cotizacion_id) {
      toast.success(data.message ?? 'Esta cotización ya se había registrado.')
      router.push({ name: 'cotizacion-detalle', params: { id: data.cotizacion_id } })
      return
    }

    // Preguntar el costo de lo que va sin precio. En una cotización el precio
    // que responda el taller entra directo al documento: no hay nada que el
    // cliente tenga que aceptar todavía.
    if (hayItemsCotizar.value && cotizarReceptorId.value && data?.id) {
      try {
        await crearConsulta({
          orden_id:          data.id,
          asignado_a_id:     cotizarReceptorId.value,
          notas_adicionales: cotizarNotas.value.trim() || null,
        })
        toast.success(`Cotización ${data.cotizacion_ref ?? ''} creada · consulta de costo enviada.`)
      } catch {
        toast.success(`Cotización ${data.cotizacion_ref ?? ''} creada. La consulta de costo se puede pedir desde el detalle.`)
      }
    } else {
      toast.success(`Cotización ${data.cotizacion_ref ?? ''} creada.`)
    }

    router.push({ name: 'cotizacion-detalle', params: { id: data.id } })
  } catch (e) {
    const errores = e.response?.data?.errors
    const detalle = errores ? ' · ' + Object.entries(errores).map(([k, v]) => `${k}: ${v[0]}`).join(', ') : ''
    toast.error((e.response?.data?.message ?? 'Error al crear la cotización') + detalle)
  } finally {
    submitting.value = false
  }
}

async function submitBorrador(enviarPdf = false) {
  if (submitting.value) return
  if (!items.value.length) {
    toast.error('Agrega al menos un producto antes de guardar el borrador.')
    return
  }
  modoGuardarBorrador.value = true
  enviarPdfAlGuardarBorrador.value = enviarPdf
  await submit()
}

async function iniciarBorradorConEnvio() {
  if (submitting.value) return
  if (!items.value.length) {
    toast.error('Agrega al menos un producto antes de guardar el borrador.')
    return
  }
  const tieneContacto = clienteSeleccionado.value?.email || clienteSeleccionado.value?.telefono
  if (tieneContacto) {
    await submitBorrador(true)
    return
  }
  borradorEmailInput.value = ''
  borradorTelefonoInput.value = ''
  borradorNecesitaContacto.value = true
}

async function guardarContactoYBorrador() {
  const email    = borradorEmailInput.value.trim()
  const telefono = borradorTelefonoInput.value.trim()
  if (!email && !telefono) {
    toast.error('Ingresa el email o teléfono del cliente.')
    return
  }
  guardandoContactoBorrador.value = true
  try {
    const patch = {}
    if (email)    patch.email    = email
    if (telefono) patch.telefono = telefono
    await updateCliente(clienteSeleccionado.value.id, patch)
    clienteSeleccionado.value = { ...clienteSeleccionado.value, ...patch }
    borradorNecesitaContacto.value = false
    await submitBorrador(true)
  } catch {
    toast.error('No se pudo guardar el contacto del cliente.')
  } finally {
    guardandoContactoBorrador.value = false
  }
}

async function onFacturaFotoChange(e) {
  const archivos = Array.from(e.target.files ?? [])
  e.target.value = ''
  for (const original of archivos) {
    if (facturaFotos.value.length >= 10) break
    const file = await comprimirAlTomar(original)
    facturaFotos.value.push({ file, preview: URL.createObjectURL(file), url: '' })
  }
}

async function onAnexoFotoChange(e) {
  const original = e.target.files[0]
  e.target.value = ''
  if (original) anexoFotoFile.value = await comprimirAlTomar(original)
}

function removeFacturaFoto(i = 0) {
  const [quitada] = facturaFotos.value.splice(i, 1)
  if (quitada?.preview) URL.revokeObjectURL(quitada.preview)
}

// ── Respaldo en el teléfono ───────────────────────────────────────────────────
// Si Android cierra la app mientras el vendedor está en WhatsApp o en la cámara,
// o se recarga sin querer, la orden a medio hacer no se pierde: al volver se
// ofrece seguir donde iba. Ver useBorradorLocal.
//
// Todo lo que el vendedor llena. No van resultados de búsqueda, modales
// abiertos ni banderas de "cargando": eso no es parte de la orden.
const camposBorrador = {
  step, esFv2, fv2SinIva, motivoSerie,
  contactoNombre, contactoTelefono, contactoEmail, diasVigencia,
  clienteQuery, clienteSeleccionado, modoNuevoCliente, nuevoCliente, formCompletarCliente,
  tiendaId, canal, tiendaBusqueda,
  items, mostrarFormRestauracion, restauracionItem, modoProductoCustom, productoCustomForm,
  descuentoModo, descuentoInput, descuentoCondModo, descuentoCondInput,
  anticipo_pct, anticipo_monto, anticipo_metodo, anticipo_referencia,
  pagoSplit, anticipo_monto1_input, anticipo_metodo2, anticipo_referencia2,
  notas, fechaSugeridaVendedor, esCompartida, covendedorId, tiendaAbonadaId, entregaInmediata,
  departamentoEnvio, ciudadEnvio, direccionEnvio,
  facturaFotos, firmaBlob, firmaUrl, anexoFotoFile, anexoFotoUrl, anexo,
  cotizarReceptorId, cotizarNotas,
}

const borradorLocal = useBorradorLocal({
  // Seguir un borrador del servidor tiene su propio respaldo: no se mezcla
  // con una orden nueva que se haya dejado a medias en el teléfono.
  clave: auth.usuario?.id
    ? `nueva-orden:${auth.usuario.id}:${modoCotizacion.value ? 'cotizacion' : 'orden'}${borradorId ? `:b${borradorId}` : ''}`
    : null,
  fuentes: Object.values(camposBorrador),
  // Las vistas previas (blob:) no sirven después de recargar: se rehacen
  // desde la foto guardada.
  omitir: ['boceto_previews', 'preview', 'foto_preview', '_calculandoPrecio'],
  capturar: () => {
    const datos = { claveEnvio }
    for (const [k, r] of Object.entries(camposBorrador)) datos[k] = r.value
    return datos
  },
  hayContenido: () => !!(
    clienteSeleccionado.value || items.value.length ||
    nuevoCliente.value.nombre?.trim() || nuevoCliente.value.cedula?.trim() || nuevoCliente.value.telefono?.trim() ||
    contactoNombre.value.trim() || contactoTelefono.value.trim() ||
    restauracionItem.value.nombre_mueble?.trim() || productoCustomForm.value.nombre?.trim()
  ),
  resumir: () => {
    const n = items.value.length
    return {
      titulo:  clienteSeleccionado.value?.nombre || nuevoCliente.value.nombre?.trim()
               || contactoNombre.value.trim() || 'Sin cliente todavía',
      detalle: `${n} ${n === 1 ? 'producto' : 'productos'} · paso ${step.value} de 3`,
    }
  },
  restaurar: restaurarBorrador,
})
const borradorPendiente = borradorLocal.pendiente
const confirmandoDescarte = ref(false)

async function restaurarBorrador(d) {
  if (d.claveEnvio) claveEnvio = d.claveEnvio

  // Las fotos vuelven como Blob/File; sus vistas previas se hacen de nuevo.
  // Una que no se alcanzó a guardar se quita del todo, para que no quede un
  // hueco en la galería.
  for (const it of d.items ?? []) {
    const blobs = it.boceto_blobs ?? [], urls = it.boceto_urls ?? []
    const quedan = blobs.map((b, i) => i).filter(i => blobs[i] || urls[i])
    it.boceto_blobs    = quedan.map(i => blobs[i] ?? null)
    it.boceto_urls     = quedan.map(i => urls[i] ?? '')
    it.boceto_previews = quedan.map(i => blobs[i] ? URL.createObjectURL(blobs[i]) : urls[i])
    it._calculandoPrecio = false
  }
  if (d.restauracionItem) {
    d.restauracionItem.foto_preview = d.restauracionItem.foto_blob
      ? URL.createObjectURL(d.restauracionItem.foto_blob) : null
  }
  if (d.productoCustomForm) {
    d.productoCustomForm.fotos = (d.productoCustomForm.fotos ?? [])
      .filter(f => f.blob || f.url)
      .map(f => ({ blob: f.blob ?? null, url: f.url, preview: f.blob ? URL.createObjectURL(f.blob) : f.url }))
  }
  d.facturaFotos = (d.facturaFotos ?? [])
    .filter(f => f.file || f.url)
    .map(f => ({ ...f, preview: f.file ? URL.createObjectURL(f.file) : f.url }))

  const poner = () => {
    for (const [k, r] of Object.entries(camposBorrador)) if (k in d) r.value = d[k]
  }
  poner()
  // Algunos watchers reaccionan a lo que se acaba de poner y pisan otros
  // campos (el canal elige la tienda, la firma nueva borra su url, el cliente
  // rellena "completar datos"…). Una segunda pasada deja lo que había: lo que
  // no cambió no vuelve a disparar nada.
  await nextTick()
  poner()

  if (step.value === 3 && hayItemsCotizar.value) cargarReceptoresCotizar()
  toast.success('Recuperamos la orden que llevabas.')
}

// ── Seguir un borrador guardado en el servidor ───────────────────────────────

/** "Marca · Tipo · Color" (como lo guarda el servidor) de vuelta al selector de tela. */
function telaDesdeTexto(texto) {
  const partes = String(texto ?? '').split(' · ').map(s => s.trim())
  if (partes.length !== 3 || partes.some(p => !p)) return null
  const [marca, tipo, color] = partes
  return { marca, marcaManual: '', tipo, telaManual: '', color, colorManual: '' }
}

/**
 * Un ítem guardado, como el carrito lo trabaja. Es lo inverso de lo que arma
 * submit(): el precio ya viene con el descuento del ítem aplicado, las
 * cantidades de un juego vienen en piezas y el retapizado trae la tela de
 * antes aparte.
 */
function itemDesdeServidor(i) {
  const specs = { ...(i.specs_personalizacion ?? {}) }
  const notasItem = specs.notas ?? ''
  const trabajo   = specs.trabajo ?? null
  const telaOriginal = specs.tela_original ?? null
  delete specs.notas
  delete specs.trabajo
  delete specs.tela_original
  delete specs.variante_marca
  delete specs.variante_color

  const nombre    = i.producto?.nombre ?? i.nombre_custom ?? 'Producto'
  const categoria = i.producto?.categoria ?? i.categoria_custom ?? null
  const esPersonalizado = !!i.es_personalizado

  // Las telas elegidas vuelven al selector; el texto se queda también en
  // las specs para no perderlo si el selector no la reconoce.
  const telaSelections = {}
  if (esPersonalizado || i.retapizar || specs.retapizar) {
    const plantilla = getTemplate({ nombre, categoria })
    const camposTela = new Set((plantilla.campos ?? []).filter(c => c.useVariantes).map(c => c.key))
    camposTela.add('tela')
    for (const key of camposTela) {
      const sel = specs[key] ? telaDesdeTexto(specs[key]) : null
      if (sel) telaSelections[key] = sel
    }
  }

  const fotos = i.bocetos_list ?? []
  const precio = Number(i.precio_unitario) || 0
  const item = {
    id:               i.id,
    producto_id:      i.producto_id ?? null,
    variante_id:      i.variante_id ?? null,
    _combo_id:        null,
    _config_id:       i.combo_config_id ?? null,
    tienda_origen_id: i.tienda_origen_id ?? null,
    tienda_origen:    i.tienda_origen?.nombre ?? null,
    nombre,
    nombre_custom:    i.producto_id ? undefined : (i.nombre_custom ?? nombre),
    categoria,
    categoria_custom: i.producto_id ? undefined : (i.categoria_custom ?? null),
    // En el retapizado la línea dice "de qué tela a qué tela"; lo que se
    // eligió al vender es la tela de antes.
    variante_label:   i.retapizar ? telaOriginal : (i.variante_detalle ?? null),
    stock_libre:      i.stock_libre ?? null,
    personalizable:   !!i.producto?.personalizable,
    cantidad:         Number(i.cantidad) || 1,
    precio_unitario:  precio,
    es_personalizado: esPersonalizado,
    specs,
    specs_notas:      notasItem,
    fecha_entrega_prometida: null,
    boceto_blobs:     fotos.map(() => null),
    boceto_urls:      [...fotos],
    boceto_previews:  [...fotos],
    _fabricar_pedido: !!i.fabricar_pedido,
    _es_restauracion: !!i.es_restauracion,
    _producto_unico:  !!i.producto_unico,
    _retapizar:       !!i.retapizar,
    _trabajoFabrica:  i.retapizar ? (trabajo ?? 'tela') : undefined,
    _llevar_ahora:    !!i.llevar_ahora,
    _regalo:          !!i.es_regalo,
    _esTapizado:      !!i.producto?.es_tapizado,
    // En $0 y sin ser regalo ni mueble único: se había dejado para que el
    // taller pusiera el precio.
    _cotizarPrecio:   (esPersonalizado || (i.retapizar && (trabajo ?? 'tela') === 'tela'))
                      && precio === 0 && !i.es_regalo && !i.producto_unico,
    _descuento_modo:  'monto',
    _descuento_valor: 0,
    _mostrarCalculadora: false,
    _calculandoPrecio:   false,
    _precioCalc:         null,
    _precioReferencia:   null,
    _telaSelections:     telaSelections,
  }

  // Vendido en juego: el servidor lo guarda en piezas.
  const n = Number(i.piezas_juego) || 0
  if (n > 1) {
    item._piezas_por_juego = n
    if (i.es_pieza_suelta) {
      item._por_juego    = false
      item._precio_pieza = precio
      item._precio_juego = Math.round(precio * n)
    } else {
      item._por_juego      = true
      item.cantidad        = Math.max(1, Math.round(item.cantidad / n))
      item.precio_unitario = Math.round(precio * n)
      item._precio_juego   = item.precio_unitario
      item._precio_pieza   = precio
    }
  }

  return item
}

async function cargarBorradorServidor() {
  if (!borradorId) return
  cargandoBorradorServidor.value = true
  try {
    const { data: o } = await api.get(`/ordenes/${borradorId}/para-continuar`)

    // El canal primero: su watcher escoge la tienda, y la del borrador va
    // encima.
    canal.value = o.canal ?? 'fisica'
    await nextTick()
    tiendaId.value = o.tienda_id

    if (o.cliente) seleccionarCliente(o.cliente)

    esFv2.value       = !!o.serie
    motivoSerie.value = o.motivo_serie ?? ''
    fv2SinIva.value   = !!o.sin_descontar_iva

    items.value = (o.items ?? []).map(itemDesdeServidor)

    descuentoModo.value      = 'monto'
    descuentoInput.value     = Number(o.descuento_total) || 0
    descuentoCondModo.value  = 'monto'
    descuentoCondInput.value = Number(o.descuento_condicionado) || 0
    anticipo_pct.value       = Number(o.anticipo_pct) || 50

    notas.value                 = o.notas ?? ''
    fechaSugeridaVendedor.value = (o.fecha_sugerida_vendedor ?? '').slice(0, 10)
    esCompartida.value          = !!o.es_compartida
    await nextTick()   // su watcher limpia el covendedor al cargar
    covendedorId.value          = o.covendedor_id ?? null
    tiendaAbonadaId.value       = o.tienda_abonada_id ?? null

    departamentoEnvio.value = o.departamento_envio ?? ''
    ciudadEnvio.value       = o.ciudad_envio ?? ''
    direccionEnvio.value    = o.direccion_envio ?? ''

    // Lo que ya se hubiera subido se aprovecha; si falta, se pide en el paso 3.
    facturaFotos.value = (o.factura_fotos?.length ? o.factura_fotos : (o.factura_foto_url ? [o.factura_foto_url] : []))
      .map(url => ({ file: null, preview: url, url }))
    firmaUrl.value     = o.firma_url ?? ''
    anexoFotoUrl.value = o.anexo_foto_url ?? ''

    consultaYaPedida.value = !!o.consulta_pendiente
    // El anexo que se firmó antes de guardar el borrador sigue valiendo (si
    // la orden cambió, el servidor lo dice al crearla).
    if (o.anexo_firmado) anexo.value = { ...o.anexo_firmado, lo_que_vio: null }
    borradorServidor.value = { id: o.id, cliente: o.cliente?.nombre ?? null }
    step.value = 2
  } catch (e) {
    const orden = e.response?.data?.orden_id
    toast.error(e.response?.data?.message ?? 'No se pudo abrir el borrador.')
    // Ya se confirmó (otra pestaña, otra persona): a la orden.
    router.replace(orden ? { name: 'orden-detalle', params: { id: orden } } : { name: 'ordenes' })
  } finally {
    cargandoBorradorServidor.value = false
  }
}
onMounted(cargarBorradorServidor)

// ── Venta que viene de Redes ──────────────────────────────────────────────────
// "Armar orden con este carrito" en la tarjeta de un pedido de WhatsApp/Instagram
// trae aquí el carrito que el cliente armó con el agente (en el state del router,
// sin pasar datos por la URL). NO se agrega nada solo: el vendedor elige cada
// producto con el buscador de siempre, así la variante, el stock y el precio
// salen por el mismo camino que cualquier venta y la orden se crea por la única
// puerta que hay (numeración, descuentos y comisión incluidos).
const desdeRedes = ref(!borradorId ? (window.history.state?.desdeRedes ?? null) : null)

// "CAMA MIAMI (1.60)" → "CAMA MIAMI": la variante se escoge en el selector.
function nombreSinVariante(texto) {
  return String(texto ?? '').replace(/\s*\([^)]*\)\s*$/, '').trim()
}

// El precio que vio el cliente en el chat, para comparar con el que quede en la orden.
function precioDelChat(item) {
  return '$' + pesos(Number(String(item.precio ?? 0).replace(/\D/g, '')) || 0)
}

function buscarDelCarrito(item) {
  productoQuery.value = nombreSinVariante(item.producto)
  buscarProducto()
}

onMounted(() => {
  const r = desdeRedes.value
  if (!r) return
  // Los teléfonos de clientes se guardan sin el +57: se busca por los últimos 10.
  const tel = String(r.telefono ?? '').replace(/\D/g, '').slice(-10)
  nuevoCliente.value = { ...nuevoCliente.value, nombre: r.nombre ?? '', telefono: tel }
  if (tel) {
    clienteQuery.value = tel
    buscarCliente()
  }
})

function haceCuanto(ts) {
  const min = Math.round((Date.now() - ts) / 60000)
  if (min < 1)  return 'hace un momento'
  if (min < 60) return `hace ${min} min`
  const h = Math.round(min / 60)
  if (h < 24)   return `hace ${h} h`
  return 'ayer'
}

// Deslizar hacia abajo en Chrome Android recarga la página: aquí no, que es
// donde más duele. Solo en esta pantalla; las listas lo siguen teniendo.
onMounted(() => {
  document.documentElement.style.overscrollBehaviorY = 'contain'
  document.body.style.overscrollBehaviorY = 'contain'
})
onBeforeUnmount(() => {
  document.documentElement.style.overscrollBehaviorY = ''
  document.body.style.overscrollBehaviorY = ''
})
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
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        {{ modoCotizacion ? 'Nueva cotización' : borradorId ? 'Seguir borrador' : 'Nueva Orden' }}
      </h2>
      <span class="text-xs text-gray-400">{{ step }}/3</span>
    </div>

    <!-- Carrito que el cliente armó con el agente (Redes) -->
    <div v-if="desdeRedes?.carrito?.length" class="bg-white rounded-xl shadow-sm p-3 space-y-2">
      <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-semibold text-gray-800">Carrito del chat</p>
        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-green-100 text-green-700">
          {{ desdeRedes.fuente === 'instagram' ? 'Instagram' : 'WhatsApp' }}
        </span>
      </div>
      <p class="text-xs text-gray-500">
        {{ step === 1 ? 'Elige o crea el cliente; en el paso 2 agregas cada producto.' : 'Toca Buscar y elige el producto como siempre. El precio del chat es solo referencia.' }}
      </p>
      <div v-for="(item, i) in desdeRedes.carrito" :key="i" class="flex items-center justify-between gap-2 text-xs">
        <div class="min-w-0">
          <p class="font-medium text-gray-800 truncate">
            {{ item.producto }}<span v-if="(item.cantidad || 1) > 1" class="text-gray-500"> ×{{ item.cantidad }}</span>
          </p>
          <p class="text-gray-500">{{ precioDelChat(item) }} en el chat</p>
        </div>
        <button
          v-if="step === 2"
          type="button"
          @click="buscarDelCarrito(item)"
          class="shrink-0 bg-blue-600 text-white rounded-lg text-xs font-semibold px-3 py-1.5"
        >Buscar</button>
      </div>
    </div>

    <!-- Siguiendo un borrador guardado -->
    <div v-if="cargandoBorradorServidor" class="flex items-center justify-center gap-2 py-10 text-sm text-gray-500">
      <IconoS class="w-5 h-5" /> Abriendo el borrador...
    </div>
    <div v-else-if="borradorServidor" class="bg-amber-50 border border-amber-200 rounded-xl p-3">
      <p class="text-sm font-semibold text-amber-900">
        Borrador{{ borradorServidor.cliente ? ` de ${borradorServidor.cliente}` : '' }}
      </p>
      <p class="text-xs text-amber-800 mt-0.5">
        Está todo como lo dejaste. Puedes cambiar productos, precios y descuentos; al crear la orden
        se confirma este mismo borrador, sin duplicarlo. "Guardar borrador" lo deja guardado otra vez.
      </p>
    </div>

    <!-- Barra de pasos -->
    <div class="flex gap-1">
      <div v-for="n in 3" :key="n"
        :class="['h-1 flex-1 rounded-full transition-colors',
          n <= step ? (modoCotizacion ? 'bg-violet-600' : 'bg-blue-600') : 'bg-gray-200']"
      />
    </div>

    <!-- Aviso de modo cotización -->
    <div v-if="modoCotizacion" class="bg-violet-50 border border-violet-200 rounded-xl p-3">
      <p class="text-sm font-semibold text-violet-800">Cotización — no compromete inventario</p>
      <p class="text-xs text-violet-600 mt-0.5">
        No se pide firma, anticipo ni comprobante, y los datos del cliente son opcionales.
        Al final descargas el PDF para enviárselo.
      </p>
    </div>

    <!-- Firma del vendedor requerida (no aplica a cotizaciones) -->
    <div
      v-if="!auth.usuario?.firma_url && !modoCotizacion"
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
    <template v-if="cargandoBorradorServidor"></template>
    <template v-else-if="step === 1">

      <!-- Ya no se elige "tipo de orden": en el paso 2 se agrega lo que sea,
           productos del catálogo y muebles del cliente para restaurar, en el
           mismo carrito. Antes había que hacer dos órdenes separadas. -->

      <!-- Tienda -->
      <div>
        <label class="label">Tienda</label>
        <!-- El ebanista trabaja en la fábrica: su sede es fija, como la de
             cualquier vendedor. Antes elegía entre todas las tiendas y una
             equivocación le sumaba la venta a una sede donde no está. -->
        <!-- Y un supervisor que vende por su cuenta tampoco elige: su venta es
             suya (sede Independientes), no de la tienda que escoja. -->
        <select v-if="auth.isSupervisor && !auth.isIndependiente" v-model="tiendaId" class="input">
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
        <label class="label">
          Cliente
          <span v-if="modoCotizacion" class="text-gray-400 font-normal">(opcional)</span>
        </label>
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

        <!-- Crear sin tener que buscar primero. Antes solo salía al final de
             los resultados o en el "No encontrado", así que para un cliente
             nuevo había que buscarlo a propósito sabiendo que no estaba. -->
        <button
          v-if="!clienteSeleccionado && !modoNuevoCliente"
          type="button"
          @click="abrirNuevoCliente"
          class="mt-2 w-full flex items-center justify-center gap-1.5 py-2 rounded-xl border border-green-300 text-green-700 text-sm font-medium hover:bg-green-50 transition-colors"
        >
          <PlusIcon class="w-4 h-4" />
          Nuevo cliente
        </button>

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
            @click="abrirNuevoCliente"
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
          <button @click="abrirNuevoCliente" class="text-blue-600 font-medium ml-1">Crear nuevo</button>
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

          <!-- Aviso suave: datos se completarán en el paso de pago -->
          <div v-if="clienteRequiereCompletar && !modoCotizacion" class="bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 flex items-center gap-2">
            <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 flex-shrink-0" />
            <p class="text-xs text-amber-700">Los datos del cliente se completarán al finalizar la venta.</p>
          </div>
        </div>
      </div>

      <!-- Contacto suelto: solo cotización y solo si no eligió cliente formal -->
      <div v-if="modoCotizacion && !clienteSeleccionado" class="bg-violet-50 border border-violet-200 rounded-xl p-3 space-y-2">
        <p class="text-xs font-semibold text-violet-800">¿A nombre de quién va? (opcional)</p>
        <p class="text-xs text-violet-600">
          Si el cliente no quiere dar sus datos, deja todo en blanco. Si te da el teléfono o el correo,
          anótalo aquí para hacerle seguimiento — no se crea un cliente en el sistema.
        </p>
        <input v-model="contactoNombre"   placeholder="Nombre o referencia (ej. Sr. Pérez)" class="input" />
        <div class="grid grid-cols-2 gap-2">
          <input v-model="contactoTelefono" placeholder="Teléfono" class="input" />
          <input v-model="contactoEmail"    placeholder="Correo" type="email" class="input" />
        </div>
      </div>

      <!-- Vigencia -->
      <div v-if="modoCotizacion">
        <label class="label">Validez de la cotización</label>
        <select v-model.number="diasVigencia" class="input">
          <option :value="8">8 días</option>
          <option :value="15">15 días</option>
          <option :value="30">30 días</option>
          <option :value="60">60 días</option>
        </select>
        <p class="text-xs text-gray-500 mt-1">
          Aparece en el PDF. Después de esa fecha los precios quedan sujetos a cambio.
        </p>
      </div>

      <!-- Formulario nuevo cliente -->
      <div v-if="modoNuevoCliente" class="bg-gray-50 rounded-xl p-4 space-y-3">
        <p class="text-sm font-semibold text-gray-700">Nuevo cliente</p>

        <!-- Ya existe alguien con estos datos -->
        <div v-if="posiblesDuplicados.length" class="bg-amber-50 border border-amber-300 rounded-xl p-3 space-y-2">
          <p class="text-xs font-semibold text-amber-800 flex items-center gap-1.5">
            <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0" />
            {{ posiblesDuplicados.length === 1 ? 'Ya existe un cliente con estos datos' : 'Ya existen clientes con estos datos' }}
          </p>
          <div
            v-for="c in posiblesDuplicados"
            :key="c.id"
            class="bg-white border border-amber-200 rounded-lg px-3 py-2 flex items-center justify-between gap-2"
          >
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-gray-800 truncate">{{ c.nombre }}</p>
              <p class="text-xs text-gray-500 truncate">
                <span v-if="c.cedula">CC {{ c.cedula }}</span>
                <span v-if="c.cedula && c.telefono"> · </span>
                <span v-if="c.telefono">{{ c.telefono }}</span>
              </p>
              <p class="text-[11px] text-amber-700">Tiene {{ c.motivo }}</p>
            </div>
            <button
              type="button"
              @click="usarClienteExistente(c)"
              class="text-xs font-semibold px-2.5 py-1.5 rounded-lg bg-amber-600 text-white hover:bg-amber-700 transition-colors flex-shrink-0"
            >Usar este</button>
          </div>
          <p class="text-[11px] text-amber-700">
            Si de verdad es otra persona, sigue llenando y créalo — solo la cédula no se puede repetir.
          </p>
        </div>

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
            <label class="text-xs text-gray-500 mb-1.5">Categorías de interés</label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="cat in categoriasInteresOpts"
                :key="cat"
                type="button"
                @click="toggleCatInteresNuevo(cat)"
                class="px-2.5 py-1 text-xs rounded-full border transition-colors"
                :class="nuevoCliente.categorias_interes.includes(cat)
                  ? 'bg-amber-100 text-amber-700 border-amber-300 font-medium'
                  : 'bg-white text-gray-500 border-gray-200 hover:border-amber-300 hover:text-amber-600'"
              >{{ cat }}</button>
              <span v-if="!categoriasInteresOpts.length" class="text-xs text-gray-400 italic">Cargando...</span>
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

      <!-- ── Catálogo ── -->

      <!-- Selector tienda de búsqueda -->
      <div>
        <label class="label">Buscar en</label>
        <select v-model="tiendaBusqueda" @change="productoResultados = []; fabricaStock = {}" class="input text-sm">
          <option v-for="t in tiendasConStock" :key="t.id" :value="t.id">
            {{ t.nombre }}{{ t.id == tiendaId ? ' (tu tienda)' : '' }}
          </option>
          <option v-if="fabricaId" :value="fabricaId">Bodega Fábrica (Reserva)</option>
        </select>
        <p v-if="tiendaBusqueda == fabricaId" class="mt-1 text-xs text-purple-600 font-medium">
          Consultando reserva de fábrica — los productos se toman directamente de fábrica al cliente
        </p>
        <p v-else-if="auth.isIndependiente" class="mt-1 text-xs text-amber-600 font-medium">
          El producto sale de esta tienda, pero la venta es tuya: no se le suma a ella.
        </p>
        <p v-else-if="tiendaBusqueda && tiendaBusqueda != tiendaId" class="mt-1 text-xs text-amber-600 font-medium">
          Consultando stock de otra tienda — la orden se registra en {{ tiendas.find(t => t.id == tiendaId)?.nombre }}
        </p>
      </div>

      <!-- Buscador de productos -->
      <div class="flex gap-2">
        <div class="relative flex-1">
          <input
            v-model="productoQuery"
            @keyup.enter="buscarProducto"
            @keyup.esc="limpiarBusqueda"
            placeholder="Buscar producto..."
            class="input w-full pr-9"
          />
          <button
            v-if="productoQuery"
            type="button"
            @click="limpiarBusqueda"
            aria-label="Limpiar búsqueda"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-300 hover:text-gray-500 transition-colors"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>
        <button @click="buscarProducto" :disabled="buscandoProducto" class="btn-primary px-3">
          Buscar
        </button>
      </div>

      <!-- Cerrar los resultados sin tener que bajar hasta el final -->
      <div v-if="productoResultados.length" class="flex items-center justify-between">
        <p class="text-xs text-gray-400">
          {{ productoResultados.length }}
          {{ productoResultados.length === 1 ? 'resultado' : 'resultados' }}
        </p>
        <button
          type="button"
          @click="limpiarBusqueda"
          class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 transition-colors"
        >
          <XMarkIcon class="w-3.5 h-3.5" />
          Cerrar resultados
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
                {{ stockLibre(p) > 0
                  ? (piezasPorJuego(p) ? `${enJuegos(stockLibre(p), piezasPorJuego(p))} en stock` : `${stockLibre(p)} en stock`)
                  : 'Sin stock' }}
              </span>
              <span v-if="fabricaStock[p.id] > 0"
                class="text-xs font-medium px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">
                Fábrica: {{ fabricaStock[p.id] }}
              </span>
            </div>

            <!-- Botones de acción.
                 Fabricar y Personalizar salen SIEMPRE, haya stock o no: a veces
                 se manda a fabricar aunque el producto esté en tienda (el del
                 local es exhibición, lo quieren en otro acabado, etc.).
                 Cuando hay stock van en versión suave para que "+ Agregar"
                 siga siendo lo obvio y nadie mande a producción por error. -->
            <div class="flex items-center gap-1.5 flex-wrap justify-end">
              <button
                v-if="hayDisponible(p)"
                @click="agregarItem(p)"
                class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors"
              >{{ p.tiene_tallas ? 'Seleccionar talla' : '+ Agregar' }}</button>

              <button
                @click="aPedidoConVariante(p, 'fabricar')"
                :class="['text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors',
                  hayDisponible(p)
                    ? 'border border-orange-300 text-orange-600 hover:bg-orange-50'
                    : 'bg-orange-500 text-white hover:bg-orange-600']"
              >Fabricar</button>

              <button
                v-if="p.personalizable"
                @click="aPedidoConVariante(p, 'personalizar')"
                :class="['text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors',
                  hayDisponible(p)
                    ? 'border border-purple-300 text-purple-600 hover:bg-purple-50'
                    : 'bg-purple-100 text-purple-700 hover:bg-purple-200']"
              >Personalizar</button>
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

        <!-- ¿Quizás quisiste decir? -->
        <div v-if="sugerencias.length" class="space-y-1.5 text-left pt-1">
          <p class="text-xs font-medium text-gray-500">¿Quizás quisiste decir?</p>
          <button
            v-for="s in sugerencias" :key="s.id"
            type="button"
            @click="usarSugerencia(s)"
            class="w-full text-left bg-white border border-gray-200 rounded-lg px-3 py-1.5 hover:border-blue-400 hover:bg-blue-50/50 transition-colors"
          >
            <span class="text-sm font-medium text-gray-800">{{ s.nombre }}</span>
            <span v-if="s.categoria" class="text-xs text-gray-400"> · {{ s.categoria }}</span>
          </button>
        </div>

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
            <InputPesos
              v-model="crearProductoForm.precio_base" permite-vacio
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

        <div
          :class="['border rounded-xl p-4 space-y-3',
            productoCustomForm.unico ? 'bg-emerald-50 border-emerald-200' : 'bg-purple-50 border-purple-200']"
          v-else
        >
          <div class="flex items-center justify-between">
            <p :class="['text-sm font-semibold', productoCustomForm.unico ? 'text-emerald-800' : 'text-purple-800']">
              {{ productoCustomForm.unico ? 'Mueble único — ya está hecho' : 'Producto personalizado' }}
            </p>
            <button @click="modoProductoCustom = false" class="text-purple-400 hover:text-purple-600">
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>

          <!-- Modelos que salieron una sola vez: no paga meterlos al catálogo
               ni al inventario, pero tampoco pueden irse al taller, porque el
               mueble ya está terminado y no se va a fabricar otro. -->
          <label class="flex items-center gap-2.5 cursor-pointer select-none">
            <div
              @click="productoCustomForm.unico = !productoCustomForm.unico"
              :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0', productoCustomForm.unico ? 'bg-emerald-500' : 'bg-gray-300']"
            >
              <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', productoCustomForm.unico ? 'translate-x-5' : 'translate-x-0.5']" />
            </div>
            <span class="text-xs text-gray-600">
              Ya lo tenemos hecho — <span class="font-medium">no va a producción</span>
            </span>
          </label>

          <div class="relative">
            <input
              v-model="productoCustomForm.nombre"
              class="input text-sm"
              :placeholder="productoCustomForm.unico ? 'Nombre del mueble * (o escoge uno que ya se vendió)' : 'Nombre del producto *'"
              @input="buscarUnicos(); mostrarUnicos = true"
              @focus="mostrarUnicos = true; buscarUnicos()"
              @blur="ocultarUnicos"
            />
            <!-- Los que ya se vendieron: uno del que salieron varias piezas
                 se escoge aquí en vez de escribirlo otra vez. -->
            <ul
              v-if="productoCustomForm.unico && mostrarUnicos && unicosSugeridos.length"
              class="absolute z-20 left-0 right-0 mt-1 bg-white border border-emerald-200 rounded-xl shadow-lg max-h-64 overflow-y-auto divide-y divide-gray-100"
            >
              <li class="px-3 py-1.5 text-[11px] text-gray-400">Ya vendidos — escoge si es el mismo</li>
              <li
                v-for="u in unicosSugeridos" :key="u.nombre"
                @mousedown.prevent="elegirUnico(u)"
                class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-emerald-50"
              >
                <img v-if="u.fotos?.[0]" :src="u.fotos[0]" alt="" class="w-9 h-9 rounded-md object-cover border border-gray-200 flex-shrink-0" />
                <div v-else class="w-9 h-9 rounded-md bg-gray-100 flex-shrink-0" />
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium text-gray-800 truncate">{{ u.nombre }}</p>
                  <p class="text-[11px] text-gray-500 truncate">
                    {{ u.categoria ? `${u.categoria} · ` : '' }}vendido {{ u.veces }} {{ u.veces === 1 ? 'vez' : 'veces' }}
                    <template v-if="u.precio_unitario"> · último ${{ Number(u.precio_unitario).toLocaleString('es-CO') }}</template>
                  </p>
                </div>
              </li>
            </ul>
          </div>
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
          <!-- Fotos -->
          <div>
            <p class="text-xs text-gray-500 mb-1.5">
              {{ productoCustomForm.unico ? 'Fotos del mueble' : 'Fotos o referencia' }}
              <span class="text-gray-400">(opcional)</span>
            </p>
            <div class="flex flex-wrap gap-2">
              <div v-for="(f, i) in productoCustomForm.fotos" :key="f.preview" class="relative">
                <img :src="f.preview" alt="Foto" class="w-20 h-20 rounded-lg object-cover border border-gray-200" />
                <button
                  type="button"
                  @click="quitarFotoProductoCustom(i)"
                  class="absolute -top-1.5 -right-1.5 bg-white rounded-full p-0.5 shadow text-red-400 hover:text-red-600"
                ><XMarkIcon class="w-3.5 h-3.5" /></button>
              </div>
              <label
                :class="['w-20 h-20 flex flex-col items-center justify-center gap-1 border-2 border-dashed rounded-lg text-[11px] text-gray-400 cursor-pointer transition-colors',
                  productoCustomForm.unico ? 'border-emerald-200 hover:border-emerald-400 hover:text-emerald-600' : 'border-purple-200 hover:border-purple-400 hover:text-purple-600']"
              >
                <PhotoIcon class="w-5 h-5" />
                Subir foto
                <input type="file" accept="image/*" multiple class="hidden" @change="onFotosProductoCustom" />
              </label>
            </div>
          </div>

          <p v-if="productoCustomForm.unico" class="text-xs text-emerald-700">
            No entra al catálogo ni al inventario, y el taller no lo ve. El precio
            se lo pones abajo, en el carrito.
          </p>
          <p v-else class="text-xs text-amber-600">El precio se define después con el cotizador IA o manualmente.</p>
          <div class="flex gap-2">
            <button @click="cancelarProductoCustom" class="btn-secondary flex-1 text-sm">Cancelar</button>
            <button
              @click="agregarProductoCustom"
              :disabled="!productoCustomForm.nombre.trim() || productoCustomForm.cantidad < 1"
              class="btn-primary flex-1 text-sm disabled:opacity-40"
            >Agregar al carrito</button>
          </div>
        </div>
      </div>

      <!-- fin catálogo -->

      <!-- ── Restauración: mueble que trae el cliente ──
           Va plegado. Se abre solo si el cliente además trae algo para
           restaurar, sin sacar del carrito lo que ya se agregó del catálogo. -->
      <button
        type="button"
        @click="mostrarFormRestauracion = !mostrarFormRestauracion"
        :class="['w-full flex items-center justify-between gap-2 rounded-xl border px-4 py-3 text-sm font-medium transition-colors',
          mostrarFormRestauracion
            ? 'bg-indigo-600 text-white border-indigo-600'
            : 'bg-white text-indigo-700 border-indigo-200 hover:border-indigo-400']"
      >
        <span>🛠️ ¿Trae un mueble para restaurar?</span>
        <ChevronDownIcon :class="['w-4 h-4 transition-transform', mostrarFormRestauracion ? 'rotate-180' : '']" />
      </button>

      <template v-if="mostrarFormRestauracion">
        <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 space-y-3">
          <p class="text-sm font-semibold text-indigo-800">Agregar mueble a restaurar</p>
          <p class="text-xs text-indigo-600 -mt-1">
            Mueble que trae el cliente (tapizado, laca, lijado…). No descuenta inventario y va directo a producción.
          </p>
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
              <InputPesos v-model="restauracionItem.precio_unitario" permite-vacio placeholder="0" class="input text-sm"
              />
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

            <TelaPicker :seleccion="getTelaSelection(restauracionItem, 'tela')" etiqueta="Tela" />

            <p v-if="!telaResumidaCampo(restauracionItem, 'tela')" class="text-xs text-amber-600 italic">
              Selecciona qué tela usará el tapicero
            </p>
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
                <IconoS v-if="restauracionCalc.calculando" class="w-3.5 h-3.5" />
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
          v-for="{ item, idx } in carritoAlReves"
          :key="idx"
          class="bg-white rounded-xl shadow-sm p-3 space-y-2"
        >
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <!-- Número de orden de compra -->
              <p class="text-[10px] font-bold text-blue-500 tracking-wide mb-0.5">ÍTEM #{{ idx + 1 }}</p>
              <!-- El nombre se corrige aquí mismo cuando lo escribió el
                   vendedor (restauración, diseño especial, producto sin
                   catálogo). Equivocarse en una letra no puede obligar a
                   borrar el ítem y volverlo a armar entero. El del catálogo
                   no se toca: ese nombre es el del producto. -->
              <input
                v-if="item.producto_id === null"
                :value="item.nombre"
                @input="renombrarItem(item, $event.target.value)"
                type="text"
                placeholder="Nombre del mueble"
                class="w-full font-medium text-sm text-gray-800 bg-transparent border-b border-dashed border-gray-300 focus:border-blue-500 focus:outline-none py-0.5"
              />
              <p v-else class="font-medium text-sm text-gray-800 truncate">{{ item.nombre }}</p>
              <div class="flex flex-wrap items-center gap-1 mt-0.5">
                <span v-if="item._fabricar_pedido"
                  class="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full text-xs font-semibold">
                  🔨 Para fabricar
                </span>
                <span v-else-if="item._producto_unico"
                  class="bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded-full text-xs font-semibold">
                  🏷️ Único — ya hecho
                </span>
                <span v-else-if="item.producto_id === null && item.es_personalizado"
                  class="bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded-full text-xs font-semibold">
                  ✏️ Diseño especial
                </span>
                <span v-else-if="item._retapizar"
                  class="bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded-full text-xs font-semibold">
                  {{ trabajoInfo(item).icono }} {{ trabajoInfo(item).label }}
                </span>
                <span v-if="item.variante_label"
                  class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded-full text-xs font-medium">
                  <SwatchIcon class="w-3 h-3 inline-block mr-0.5 -mt-0.5" />{{ item.variante_label }}
                </span>
                <span v-if="item.tienda_origen"
                  class="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-medium text-xs">
                  <MapPinIcon class="w-3.5 h-3.5 inline-block mr-0.5 -mt-0.5" />{{ item.tienda_origen }}
                </span>
                <span v-if="!item._fabricar_pedido && !(item.producto_id === null && item.es_personalizado) && !item.variante_label && !item.tienda_origen" class="text-xs text-gray-400">
                  {{ item.categoria }}
                </span>
              </div>
            </div>
            <button @click="quitarItem(idx)" class="text-red-400 hover:text-red-600 ml-2"><XMarkIcon class="w-5 h-5" /></button>
          </div>

          <!-- Se lo lleva ahora: solo lo que ya existe. El reloj sí, el comedor no. -->
          <label
            v-if="sePuedeLlevar(item)"
            :class="['flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs cursor-pointer select-none border',
              item._llevar_ahora ? 'bg-green-50 border-green-300 text-green-800' : 'bg-gray-50 border-gray-200 text-gray-600']"
          >
            <input type="checkbox" v-model="item._llevar_ahora" class="rounded border-gray-300 text-green-600 focus:ring-green-500" />
            <span class="font-medium">🛍️ Se lo lleva ahora</span>
            <span class="text-gray-400">— sale de la tienda con el cliente</span>
          </label>

          <!-- A pedido (personalizado o a fabricar) de un producto del
               catálogo: de cuál versión se parte —con baúl, otra medida—. -->
          <button
            v-if="item.producto_id && (item.es_personalizado || item._fabricar_pedido) && !item._producto_unico && !item._es_restauracion"
            type="button"
            @click="cambiarVersionAPedido(idx)"
            :disabled="abriendoCambio === idx"
            class="inline-flex items-center gap-1 text-xs font-medium text-purple-600 hover:text-purple-800 disabled:opacity-40"
          >
            <SwatchIcon class="w-3.5 h-3.5" />
            {{ abriendoCambio === idx ? 'Abriendo...' : (item.variante_label ? `Versión: ${item.variante_label} — cambiar` : 'Elegir versión (medida, con baúl…)') }}
          </button>

          <!-- Corregir la tela/medida y de dónde sale, sin sacarlo del carrito.
               Equivocarse de tela es el error más fácil de cometer vendiendo, y
               antes costaba borrar el ítem y volver a armarlo con su cantidad,
               su descuento y sus fotos. -->
          <div
            v-if="item.producto_id && !item._fabricar_pedido && !item.es_personalizado"
            class="flex flex-wrap items-center gap-2"
          >
            <button
              type="button"
              @click="cambiarVariante(idx)"
              :disabled="abriendoCambio === idx"
              class="inline-flex items-center gap-1 text-xs font-medium text-purple-600 hover:text-purple-800 disabled:opacity-40"
            >
              <SwatchIcon class="w-3.5 h-3.5" />
              {{ abriendoCambio === idx ? 'Abriendo...' : (item.variante_label ? 'Cambiar tela / medida' : 'Elegir tela / medida') }}
            </button>

            <div class="flex items-center gap-1 ml-auto">
              <MapPinIcon class="w-3.5 h-3.5 text-gray-400" />
              <select
                :value="item.tienda_origen_id ?? tiendaId"
                @change="cambiarTiendaItem(idx, $event.target.value)"
                :disabled="abriendoCambio === idx"
                class="text-xs text-gray-600 bg-transparent border border-gray-200 rounded-lg px-1.5 py-1 focus:outline-none focus:border-blue-400 disabled:opacity-40"
              >
                <option v-for="t in tiendasConStock" :key="t.id" :value="t.id">{{ t.nombre }}</option>
              </select>
              <span class="text-xs text-gray-400 whitespace-nowrap">
                {{ item._piezas_por_juego ? enJuegos(item.stock_libre, item._piezas_por_juego) : (item.stock_libre ?? 0) }} disp.
              </span>
            </div>
          </div>

          <!-- Producto que viene en juego: se vende completo o por piezas
               (el cliente quiere una sola, o solo queda media pareja). -->
          <div v-if="item._piezas_por_juego" class="space-y-1">
            <div class="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-medium">
              <button type="button" @click="cambiarModoJuego(item, true)"
                :class="['flex-1 py-1.5 transition-colors', item._por_juego ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
                Juego completo ({{ item._piezas_por_juego }} piezas)
              </button>
              <button type="button" @click="cambiarModoJuego(item, false)"
                :class="['flex-1 py-1.5 transition-colors border-l border-gray-200', !item._por_juego ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
                Piezas sueltas
              </button>
            </div>
            <p v-if="!item.es_personalizado && item.cantidad > stockEnUnidad(item)" class="text-xs text-amber-600">
              Solo hay {{ enJuegos(item.stock_libre, item._piezas_por_juego) }} en esa tienda.
            </p>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500">
                {{ item._piezas_por_juego ? (item._por_juego ? 'Juegos' : 'Piezas') : 'Cantidad' }}
              </label>
              <input
                v-model.number="item.cantidad"
                type="number" min="1"
                :max="item.es_personalizado ? undefined : stockEnUnidad(item)"
                class="input text-sm"
              />
            </div>
            <div>
              <label class="text-xs text-gray-500">
                {{ item._piezas_por_juego ? (item._por_juego ? 'Precio por juego' : 'Precio por pieza') : 'Precio unitario' }}
              </label>
              <div v-if="item._regalo" class="flex items-center gap-2 h-9 px-3 bg-pink-50 border border-pink-300 rounded-lg">
                <GiftIcon class="w-4 h-4 text-pink-500 flex-shrink-0" />
                <span class="text-xs text-pink-700 font-medium">Regalo · $0</span>
              </div>
              <div v-else-if="item.es_personalizado && item._cotizarPrecio" class="flex items-center gap-2 h-9 px-3 bg-violet-50 border border-violet-300 rounded-lg">
                <CurrencyDollarIcon class="w-4 h-4 text-violet-500 flex-shrink-0" />
                <span class="text-xs text-violet-700 font-medium">Por definir</span>
              </div>
              <InputPesos
                v-else
                v-model="item.precio_unitario"
                :class="['input text-sm', item.es_personalizado && !item.precio_unitario ? 'border-amber-400 bg-amber-50' : '']"
              />
            </div>
          </div>

          <!-- Regalo / cortesía — cualquier ítem se puede obsequiar -->
          <label class="flex items-center gap-2.5 cursor-pointer select-none">
            <div
              @click="toggleRegalo(item)"
              :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0', item._regalo ? 'bg-pink-500' : 'bg-gray-300']"
            >
              <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', item._regalo ? 'translate-x-5' : 'translate-x-0.5']" />
            </div>
            <span class="text-xs text-gray-600 flex items-center gap-1">
              <GiftIcon class="w-3.5 h-3.5 text-pink-500" /> Regalo / cortesía (precio $0)
            </span>
          </label>

          <!-- Descuento — en pesos o en %, lo que le resulte natural al vendedor -->
          <div v-if="!item._cotizarPrecio && !item._regalo" class="flex items-center gap-2 flex-wrap">
            <label class="text-xs text-gray-500 flex-shrink-0">Descuento c/u</label>

            <div class="flex items-center gap-1">
              <button
                v-for="m in [{ v: 'monto', t: '$' }, { v: 'pct', t: '%' }]"
                :key="m.v" type="button"
                @click="item._descuento_modo = m.v"
                :class="['w-7 h-7 rounded-lg text-xs font-bold border transition-colors',
                  (item._descuento_modo ?? 'monto') === m.v
                    ? 'bg-gray-700 text-white border-gray-700'
                    : 'bg-white text-gray-500 border-gray-300']"
              >{{ m.t }}</button>
            </div>

            <InputPesos
              v-if="(item._descuento_modo ?? 'monto') !== 'pct'"
              v-model="item._descuento_valor"
              placeholder="0"
              class="w-24 input text-sm text-right"
            />
            <input
              v-else
              v-model.number="item._descuento_valor"
              type="number"
              min="0"
              :max="(item._descuento_modo ?? 'monto') === 'pct' ? 99 : item.precio_unitario"
              step="1"
              placeholder="0"
              class="w-24 input text-sm text-right"
            />

            <!-- En cuánto estaba y en cuánto queda: lo justo para confirmar
                 que el descuento entró bien, sin ir a comprobarlo al total. -->
            <div
              v-if="descuentoItemMonto(item) > 0"
              class="text-xs bg-green-50 px-2 py-1 rounded-lg flex items-baseline gap-1.5 flex-shrink-0"
            >
              <span class="text-gray-400 line-through">${{ pesos(item.precio_unitario) }}</span>
              <span class="text-gray-400">→</span>
              <span class="text-green-700 font-bold">${{ pesos(precioEfectivo(item)) }}</span>
            </div>
          </div>

          <!-- Toggle consultar precio — para cualquier ítem personalizado.
               El mueble único no: no hay nada que cotizar, ya está hecho. -->
          <label
            v-if="(item.es_personalizado || esCambioTela(item)) && !item._regalo && !item._producto_unico"
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
          <p v-if="(item.es_personalizado || item._retapizar) && !item._fabricar_pedido && !item.precio_unitario && !item._cotizarPrecio && !item._regalo" class="text-xs text-amber-600 mt-0.5">
            {{ item._producto_unico ? 'Ponle el precio de venta'
              : esCambioTela(item) ? 'Sin precio — ponle el precio con la tela nueva o activa "Consultar precio"'
              : item._retapizar ? 'Sin precio — ponle el precio del mueble (súmale el arreglo si se cobra)'
              : 'Sin precio — usa el cotizador IA o ingrésalo manualmente' }}
          </p>

          <!-- Llevar a fábrica: el mueble que está en el almacén se aparta y
               se manda a la fábrica (cambio de tela, de color o un arreglo)
               antes de entregarlo. Solo para lo que es de stock: lo
               personalizado y lo que se fabrica ya pasan por el taller. -->
          <label
            v-if="sePuedeRetapizar(item)"
            :class="['flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs cursor-pointer select-none border',
              item._retapizar ? 'bg-orange-50 border-orange-300 text-orange-800' : 'bg-gray-50 border-gray-200 text-gray-600']"
          >
            <input type="checkbox" :checked="item._retapizar" @change="toggleRetapizar(item)" class="rounded border-gray-300 text-orange-600 focus:ring-orange-500" />
            <span class="font-medium">🏭 Llevar a fábrica antes de entregar</span>
            <span class="text-gray-400">— arreglo o cambio; se aparta y entra a producción</span>
          </label>

          <div v-if="item._retapizar" class="flex gap-1.5">
            <button
              v-for="t in TRABAJOS_FABRICA" :key="t.k" type="button"
              @click="elegirTrabajo(item, t.k)"
              :class="['flex-1 rounded-lg border px-2 py-1.5 text-xs font-semibold transition-colors',
                trabajoDe(item) === t.k
                  ? 'bg-orange-600 border-orange-600 text-white'
                  : 'bg-white border-gray-200 text-gray-600 hover:border-orange-300']"
            >{{ t.icono }} {{ t.label }}</button>
          </div>

          <!-- Personalizado flag — oculto para fabricar bajo pedido, para el
               mueble único (que no es algo que se vaya a personalizar) y para
               el que se manda a cambiar de tela, que ya es una cosa o la otra. -->
          <label v-if="!item._es_restauracion && !item._fabricar_pedido && !item._producto_unico && !item._retapizar" :class="['flex items-center gap-2 text-sm text-gray-600', item.producto_id === null ? 'opacity-60 cursor-default' : 'cursor-pointer']">
            <input
              type="checkbox"
              :checked="item.es_personalizado"
              @change="togglePersonalizado(item)"
              :disabled="item.producto_id === null"
              class="rounded"
            />
            {{ item.producto_id === null ? 'Producto personalizado (sin catálogo)' : 'Ítem personalizado' }}
          </label>

          <!-- ── Para fabricar bajo pedido ──
               Es el mismo diseño del catálogo, pero casi nunca sale idéntico:
               otra tela u otro color, otra medida, un detalle. Antes solo se
               podía elegir la tela (y solo si era tapizado), y lo demás había
               que decirlo de palabra. Va todo a producción igual que un
               personalizado. -->
          <template v-if="item._fabricar_pedido">
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 space-y-3">
              <p class="text-xs font-semibold text-amber-800">¿Cómo se fabrica?</p>

              <div class="space-y-1">
                <label class="text-xs text-gray-500">
                  Tela / color
                  <span v-if="item._esTapizado" class="text-red-500">*</span>
                  <span v-else class="text-gray-300">— opcional, si lleva</span>
                </label>
                <TelaPicker
                  :seleccion="getTelaSelection(item, 'tela')"
                  etiqueta="Tela"
                  :producto-id="item.producto_id"
                  :config-id="item._config_id"
                  :cantidad="item.cantidad"
                />
                <p v-if="item._esTapizado && !telaResumidaCampo(item, 'tela') && marcasConStock().length" class="text-xs text-amber-600 italic">
                  Selecciona la tela para que producción sepa cuál usar
                </p>
              </div>

              <div>
                <label class="text-xs text-gray-500">Medidas <span class="text-gray-300">— solo si cambian</span></label>
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

              <div>
                <label class="text-xs text-gray-500">Notas para el taller <span class="text-gray-300">— opcional</span></label>
                <textarea
                  v-model="item.specs_notas"
                  placeholder="Ej: patas en negro mate, sin botones en el espaldar, laca nogal…"
                  rows="2"
                  class="input text-sm resize-none mt-0.5"
                />
              </div>
            </div>
          </template>

          <!-- ── Cambio de color o arreglo: no hay tela, hay que decir qué ── -->
          <div v-if="item._retapizar && !esCambioTela(item)" class="bg-orange-50 border border-orange-200 rounded-xl p-3 space-y-2">
            <div>
              <p class="text-xs font-semibold text-orange-800">
                {{ trabajoDe(item) === 'color' ? '¿Qué color lleva?' : '¿Qué hay que arreglarle?' }}
                <span class="text-red-500">*</span>
              </p>
              <p class="text-xs text-gray-500 mt-0.5">
                Mueble:
                <span class="font-medium text-gray-700">{{ item.variante_label || 'sin especificar' }}</span>
                <span v-if="!item.variante_label"> — elígelo arriba en "Elegir tela / medida" para que la fábrica sepa cuál recoger.</span>
              </p>
            </div>
            <textarea
              v-model="item.specs_notas"
              :placeholder="trabajoDe(item) === 'color'
                ? 'Ej: lacar en nogal oscuro, patas en negro mate…'
                : 'Ej: cambiar pata rota, ajustar cajón, retocar rayón del brazo…'"
              rows="2"
              class="input text-sm resize-none"
            />
            <p v-if="!(item.specs_notas ?? '').trim()" class="text-xs text-orange-600 italic">
              Escríbelo para que producción sepa qué hacerle
            </p>
          </div>

          <!-- ── Cambio de tela a un mueble de stock ── -->
          <template v-if="esCambioTela(item)">
            <div class="bg-orange-50 border border-orange-200 rounded-xl p-3 space-y-2">
              <div>
                <p class="text-xs font-semibold text-orange-800">Tela nueva <span class="text-red-500">*</span></p>
                <p class="text-xs text-gray-500 mt-0.5">
                  Tela actual:
                  <span class="font-medium text-gray-700">{{ item.variante_label || 'sin especificar' }}</span>
                  <span v-if="!item.variante_label"> — elígela arriba en "Elegir tela / medida" para que la fábrica sepa cuál sofá recoger.</span>
                </p>
              </div>

              <TelaPicker
                :seleccion="getTelaSelection(item, 'tela')"
                :actual="item.variante_label || ''"
                etiqueta="Tela"
                :producto-id="item.producto_id"
                :config-id="item._config_id"
                :cantidad="item.cantidad"
              />

              <p v-if="!telaResumidaCampo(item, 'tela')" class="text-xs text-orange-600 italic">
                Selecciona la tela para que producción sepa cuál ponerle
              </p>

              <textarea
                v-model="item.specs_notas"
                placeholder="Notas para el taller (opcional): qué partes se cambian, detalles…"
                rows="2"
                class="input text-sm resize-none"
              />
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

              <!-- Tela — mismo selector en toda la app -->
              <div class="space-y-1">
                <label class="text-xs text-gray-500">Nueva tela <span class="text-gray-300">— opcional</span></label>
                <TelaPicker
                  :seleccion="getTelaSelection(item, 'tela')"
                  :actual="item.variante_label || ''"
                  etiqueta="Tela"
                  :producto-id="item.producto_id"
                  :config-id="item._config_id"
                  :cantidad="item.cantidad"
                />
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

          <!-- ── Personalización de producto NUEVO (sin catálogo): form completo ──
               El mueble único queda fuera: sus especificaciones son las que
               tenga el mueble que ya está en el local, no algo que se le pida
               al taller. -->
          <template v-else-if="item.es_personalizado && !item.producto_id && !item._es_restauracion && !item._producto_unico">
            <div class="space-y-2">
              <p class="text-xs font-semibold text-purple-700">
                Especificaciones — {{ getTemplate(item).titulo }}
              </p>
              <div class="grid grid-cols-2 gap-2">
                <template v-for="campo in getTemplate(item).campos" :key="campo.key">
                  <div :class="campo.type === 'text' || campo.useVariantes ? 'col-span-2' : ''">
                    <label class="text-xs text-gray-500">
                      {{ campo.label }}{{ campo.unit ? ' (' + campo.unit + ')' : '' }}
                    </label>
                    <!-- Tela: mismo selector en toda la app -->
                    <TelaPicker
                      v-if="campo.useVariantes"
                      :seleccion="getTelaSelection(item, campo.key)"
                      :etiqueta="campo.label"
                      :producto-id="campo.key === 'tela' ? item.producto_id : null"
                      :config-id="item._config_id"
                      :cantidad="item.cantidad"
                    />
                    <!-- Select con opción de valor libre (elige o escribe el tuyo) -->
                    <ComboInput
                      v-else-if="campo.type === 'select'"
                      :model-value="item.specs[campo.key] ?? ''"
                      :options="campo.options ?? []"
                      :placeholder="campo.placeholder || 'Elige o escribe...'"
                      @update:model-value="v => item.specs[campo.key] = v"
                    />
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

          <!-- Boceto (venta) / Fotos (restauración). El que se retapiza también
               las lleva: una foto del sofá tal cual está le ahorra al taller
               adivinar cuál es. -->
          <template v-if="item.es_personalizado || item._retapizar">

            <!-- Modo venta: boceto + fotos adicionales -->
            <div v-if="!item._es_restauracion" class="space-y-1.5">
              <p class="text-xs font-medium text-purple-700">
                <!-- Del mueble único no hay foto en ninguna parte, porque no
                     está en el catálogo: la de aquí es la que le dice a quien
                     despacha cuál es, y la que queda en el acta. -->
                {{ item._producto_unico ? 'Fotos del mueble' : 'Boceto / Fotos' }}
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

              <!-- Canvas de boceto cuando no hay foto en el slot 0. No para el
                   mueble único: no hay nada que dibujarle a alguien, existe. -->
              <BocetoCanvas
                v-if="!item.boceto_previews[0] && !item._producto_unico"
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

            <!-- Modo restauración: trabajo, notas y fotos -->
            <div v-else class="space-y-1.5">
              <!-- Antes esto era texto fijo: si el trabajo quedaba mal escrito
                   había que borrar el ítem y volver a armarlo. Es justo lo que
                   más se corrige, porque se escribe con el cliente delante. -->
              <div class="space-y-1.5">
                <label class="text-xs font-medium text-indigo-700">Trabajo a realizar</label>
                <textarea
                  v-model="item.specs.descripcion_trabajo"
                  rows="2"
                  placeholder="Qué hay que hacerle al mueble"
                  class="input text-sm resize-none bg-indigo-50 border-indigo-200"
                />
                <textarea
                  v-model="item.specs_notas"
                  rows="2"
                  placeholder="Notas adicionales (opcional)"
                  class="input text-sm resize-none"
                />
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

          <!-- Cotizador de precio con IA. El mueble único no lo lleva: no se
               estima lo que cuesta fabricar algo que ya está fabricado. -->
          <div v-if="item.es_personalizado && !item._producto_unico">
            <button
              type="button"
              @click="item._mostrarCalculadora = !item._mostrarCalculadora"
              :class="['flex items-center gap-1.5 text-xs font-medium transition-colors',
                item._es_restauracion
                  ? 'text-indigo-600 hover:text-indigo-800'
                  : 'text-purple-600 hover:text-purple-800']"
            >
              <SparklesIcon class="w-3.5 h-3.5" />
              {{ item._mostrarCalculadora ? 'Ocultar cotizador' : 'Calcular precio con IA' }}
            </button>

            <div v-if="item._mostrarCalculadora"
              :class="['mt-2 rounded-xl p-3 space-y-3 border',
                item._es_restauracion
                  ? 'bg-indigo-50 border-indigo-200'
                  : 'bg-purple-50 border-purple-200']"
            >
              <p class="text-xs text-gray-500">
                {{ item._es_restauracion
                  ? 'La IA estima el costo de restauración basada en el trabajo, la foto y las tarifas configuradas.'
                  : 'El cotizador usa las especificaciones y medidas que ingresaste arriba.' }}
              </p>

              <!-- Precio de referencia — para productos únicos o complejos -->
              <div v-if="!item._es_restauracion">
                <label class="block text-xs font-medium text-gray-500 mb-1">
                  Precio de referencia <span class="font-normal text-gray-400">(opcional — si sabes cuánto costó uno similar)</span>
                </label>
                <InputPesos
                  v-model="item._precioReferencia"
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
                <IconoS v-if="item._calculandoPrecio" class="w-3.5 h-3.5" />
                <SparklesIcon  v-else class="w-3.5 h-3.5" />
                {{ item._calculandoPrecio ? 'Calculando...' : 'Calcular precio' }}
              </button>

              <!-- Error del cotizador -->
              <div v-if="item._precioCalc?.ok === false" class="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs text-red-700">
                {{ item._precioCalc.error || 'No se pudo calcular. Agrega más detalles del trabajo.' }}
              </div>

              <!-- Resultado -->
              <div v-else-if="item._precioCalc?.precio_fabricacion" class="bg-white rounded-lg border border-purple-100 p-3 space-y-2">
                <!-- El estimado no es plausible frente al catálogo: no se presenta como precio en firme -->
                <div v-if="item._precioCalc.requiere_revision" class="bg-red-50 border border-red-300 rounded-lg p-2.5 space-y-1">
                  <p class="text-xs font-semibold text-red-800">Estimado poco confiable — requiere revisión de un ebanista</p>
                  <p v-for="m in (item._precioCalc.revision_motivos ?? [])" :key="m" class="text-xs text-red-700">{{ m }}</p>
                  <p class="text-xs text-red-600 italic">Envía una consulta de costo antes de confirmar este precio con el cliente.</p>
                </div>

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
                    {{ item._es_restauracion ? 'Usar costo' : 'Usar fabricación' }}
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

        <!-- Descuento al total de la orden -->
        <div class="space-y-2">
          <div class="flex justify-between items-center text-sm">
            <span class="text-gray-500">Subtotal</span>
            <span class="font-medium text-gray-700">${{ subtotalItems.toLocaleString('es-CO') }}</span>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm text-gray-500 flex-shrink-0">Descuento al total</span>
            <div class="flex items-center gap-1 ml-auto">
              <!-- Unidad: en pesos es como se negocia, el % queda de alternativa -->
              <button
                v-for="m in [{ v: 'monto', t: '$' }, { v: 'pct', t: '%' }]"
                :key="m.v" type="button"
                @click="descuentoModo = m.v; descuentoInput = 0"
                :class="['w-8 h-8 rounded-lg text-sm font-bold border transition-colors',
                  descuentoModo === m.v
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'bg-white text-gray-500 border-gray-300 hover:border-blue-400']"
              >{{ m.t }}</button>

              <template v-if="descuentoModo === 'pct'">
                <button
                  v-for="p in [5, 10]" :key="'p' + p" type="button"
                  @click="descuentoInput = p"
                  class="px-2 py-1 rounded-lg text-xs font-semibold border transition-colors"
                  :class="descuentoInput === p ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600 hover:border-blue-400'"
                >{{ p }}%</button>
              </template>

              <InputPesos
                v-if="descuentoModo !== 'pct'"
                v-model="descuentoInput"
                placeholder="0"
                :class="['input text-sm text-right', descuentoModo === 'pct' ? 'w-16' : 'w-28']"
              />
              <input
                v-else
                v-model.number="descuentoInput"
                type="number" min="0"
                :max="descuentoModo === 'pct' ? 100 : null"
                :step="descuentoModo === 'pct' ? 0.1 : 1000"
                placeholder="0"
                :class="['input text-sm text-right', descuentoModo === 'pct' ? 'w-16' : 'w-28']"
              />
              <span class="text-xs text-gray-400">{{ descuentoModo === 'pct' ? '%' : '$' }}</span>
            </div>
          </div>
          <p v-if="descuentoTotal > 0" class="text-xs text-green-700 text-right">
            − ${{ Number(descuentoTotal).toLocaleString('es-CO') }}
            <span class="text-green-600">({{ formatPct(descuentoPct) }}% del subtotal)</span>
          </p>

          <!-- Descuento condicionado al medio de pago -->
          <div class="border-t border-gray-100 pt-2 mt-1 space-y-1.5">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="text-sm text-gray-500 flex-shrink-0">Descuento por efectivo/transferencia</span>
              <div class="flex items-center gap-1 ml-auto">
                <button
                  v-for="m in [{ v: 'monto', t: '$' }, { v: 'pct', t: '%' }]"
                  :key="'cm' + m.v" type="button"
                  @click="descuentoCondModo = m.v; descuentoCondInput = 0"
                  :class="['w-8 h-8 rounded-lg text-sm font-bold border transition-colors',
                    descuentoCondModo === m.v
                      ? 'bg-amber-600 text-white border-amber-600'
                      : 'bg-white text-gray-500 border-gray-300 hover:border-amber-400']"
                >{{ m.t }}</button>

                <template v-if="descuentoCondModo === 'pct'">
                  <button
                    v-for="p in [5, 10]" :key="'c' + p" type="button"
                    @click="descuentoCondInput = p"
                    class="px-2 py-1 rounded-lg text-xs font-semibold border transition-colors"
                    :class="descuentoCondInput === p ? 'bg-amber-600 text-white border-amber-600' : 'border-gray-300 text-gray-600 hover:border-amber-400'"
                  >{{ p }}%</button>
                </template>

                <InputPesos
                v-if="descuentoCondModo !== 'pct'"
                v-model="descuentoCondInput"
                placeholder="0"
                :class="['input text-sm text-right', descuentoCondModo === 'pct' ? 'w-16' : 'w-28']"
              />
              <input
                v-else
                  v-model.number="descuentoCondInput"
                  type="number" min="0"
                  :max="descuentoCondModo === 'pct' ? 100 : null"
                  :step="descuentoCondModo === 'pct' ? 0.1 : 1000"
                  placeholder="0"
                  :class="['input text-sm text-right', descuentoCondModo === 'pct' ? 'w-16' : 'w-28']"
                />
                <span class="text-xs text-gray-400">{{ descuentoCondModo === 'pct' ? '%' : '$' }}</span>
              </div>
            </div>
            <p v-if="descuentoCondicionado > 0" class="text-xs text-amber-700 text-right">
              − ${{ Number(descuentoCondicionado).toLocaleString('es-CO') }}
              <span class="text-amber-600">({{ formatPct(descuentoCondicionadoPct) }}%)</span>
              · se pierde si paga con tarjeta
            </p>

            <!-- Se escribió más de lo que cabe. Antes se recortaba callado y la
                 orden quedaba en $0 sin que nadie se diera cuenta. -->
            <div v-if="descuentoRecortado || totalEnCero"
              class="bg-red-50 border border-red-300 rounded-lg px-3 py-2 flex items-start gap-2">
              <ExclamationTriangleIcon class="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
              <div class="text-xs text-red-700 leading-snug">
                <p v-if="totalEnCero" class="font-semibold">El total queda en $0.</p>
                <p v-else class="font-semibold">El descuento no cabe y se recortó.</p>
                <p v-if="descuentoCondModo === 'pct'">
                  El campo está en <strong>%</strong>. Si querías descontar pesos,
                  cambia a <strong>$</strong> — escribir 90000 en % es 90.000 por ciento.
                </p>
                <p v-else>
                  Máximo ${{ Number(baseCondicionado).toLocaleString('es-CO') }}.
                </p>
              </div>
            </div>
            <p v-if="descuentoCondicionado > 0" class="text-xs text-gray-500">
              Solo aplica si paga <strong>todo</strong> en efectivo o transferencia. Si al entregar
              paga cualquier parte con tarjeta, el sistema avisa y el total vuelve a subir.
            </p>
          </div>
        </div>

        <!-- Total -->
        <div class="bg-blue-50 rounded-xl px-4 py-3 flex justify-between items-center">
          <span class="font-semibold text-gray-700">Total</span>
          <span class="text-lg font-bold text-blue-700">${{ valorTotal.toLocaleString('es-CO') }}</span>
        </div>
      </div>

      <div v-else class="text-center py-6 text-gray-400 text-sm">
        Busca productos del catálogo, o agrega un mueble para restaurar.
      </div>

      <!-- Cotización: se guarda aquí mismo, no hay paso de pago -->
      <div v-if="modoCotizacion" class="space-y-2">
        <!-- Preguntar el costo de lo que va sin precio. En una cotización el
             precio que responda el taller entra directo al documento. -->
        <div v-if="hayItemsCotizar" class="bg-violet-50 border border-violet-200 rounded-xl p-4 space-y-3 text-left">
          <div class="flex items-center gap-2">
            <CurrencyDollarIcon class="w-4 h-4 text-violet-600" />
            <p class="text-sm font-semibold text-violet-800">¿A quién le preguntas el costo?</p>
          </div>

          <div class="space-y-1">
            <p class="text-xs text-violet-600">Va sin precio:</p>
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
                  <p :class="['text-xs text-gray-400', r.rol_nombre ? '' : 'capitalize']">{{ r.rol_nombre ?? r.rol }}</p>
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

          <p class="text-xs text-violet-700">
            Cuando responda, ese precio queda en la cotización y en el PDF. Se puede ajustar después.
          </p>
        </div>

        <button
          @click="submitCotizacion"
          :disabled="items.length === 0 || submitting || (hayItemsCotizar && !cotizarReceptorId)"
          class="w-full py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-xl font-semibold disabled:opacity-50 transition-colors"
        >{{ submitting ? 'Guardando...' : (hayItemsCotizar ? 'Guardar y preguntar el costo' : 'Guardar cotización') }}</button>
        <p v-if="hayItemsCotizar && !cotizarReceptorId" class="text-xs text-center text-violet-600">
          Elige a quién le preguntas el costo.
        </p>
        <p v-else class="text-xs text-center text-gray-500">
          Después podrás descargar el PDF y enviárselo al cliente.
        </p>
      </div>

      <div v-else class="flex gap-2">
        <button
          @click="submitBorrador(false)"
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
          <span class="font-medium text-gray-800">{{ clienteSeleccionado?.nombre ?? '—' }}</span>
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

      <!-- Orden con descuento especial (FV2) -->
      <div :class="['rounded-xl border p-4 space-y-2', esFv2 ? 'bg-amber-50 border-amber-300' : 'bg-white border-gray-200']">
        <label class="flex items-start gap-2.5 cursor-pointer">
          <input type="checkbox" v-model="esFv2" class="mt-0.5 w-4 h-4 accent-amber-600" />
          <span class="min-w-0">
            <span class="text-sm font-semibold text-gray-800">Orden con descuento especial (FV2)</span>
            <span class="block text-xs text-gray-500 mt-0.5">
              Lleva numeración propia FV2-N en vez de número de orden, pero es una venta normal:
              descuenta inventario, cuenta en las estadísticas y genera comisión igual.
            </span>
          </span>
        </label>

        <div v-if="esFv2" class="space-y-1.5 pl-6">
          <input
            v-model="motivoSerie"
            placeholder="Motivo o a quién se le hace (opcional)"
            class="input text-sm"
          />
          <p class="text-xs text-amber-700">
            Queda registrado que la creaste tú. El descuento se aplica arriba, en el carrito.
          </p>
          <label v-if="auth.usuario?.puede_fv2_sin_iva"
                 class="flex items-start gap-2.5 cursor-pointer pt-1">
            <input type="checkbox" v-model="fv2SinIva" class="mt-0.5 w-4 h-4 accent-amber-600" />
            <span class="min-w-0">
              <span class="text-sm font-semibold text-gray-800">No se resta el IVA</span>
              <span class="block text-xs text-gray-500 mt-0.5">
                Tu comisión de esta orden se saca sobre el valor completo, sin dividir por 1,19.
              </span>
            </span>
          </label>
        </div>
      </div>

      <!-- Bloque interesado: enviar cotización primero o completar datos para orden final -->
      <div v-if="clienteRequiereCompletar" class="bg-amber-50 border border-amber-300 rounded-xl p-4 space-y-3">
        <p class="text-sm font-semibold text-amber-800 flex items-center gap-1.5">
          <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0" />
          {{ clienteSeleccionado.tipo === 'interesado' ? 'Cliente interesado' : 'Datos del cliente incompletos' }} — elige cómo continuar
        </p>

        <!-- Opción A: enviar cotización (borrador) -->
        <div class="bg-white border border-amber-200 rounded-lg p-3 space-y-2">
          <p class="text-xs font-semibold text-gray-700">Opción A — Enviar cotización al cliente</p>
          <p class="text-xs text-gray-500">Guarda el pedido como borrador y comparte el PDF. Cuando el cliente confirme, vuelves y finalizas con los datos que falten.</p>

          <!-- Mini-form si el cliente no tiene email ni teléfono -->
          <template v-if="borradorNecesitaContacto">
            <p class="text-xs font-semibold text-blue-700 mt-1">¿Cómo le enviamos la cotización?</p>
            <p class="text-xs text-gray-500">El cliente no tiene email ni teléfono registrado. Ingresa al menos uno.</p>
            <input v-model="borradorEmailInput" type="email" placeholder="Email del cliente" class="input text-sm" />
            <input v-model="borradorTelefonoInput" type="tel" placeholder="Teléfono / WhatsApp" class="input text-sm" />
            <div class="flex gap-2 mt-1">
              <button @click="borradorNecesitaContacto = false" class="btn-secondary flex-1 text-xs py-1.5">Cancelar</button>
              <button
                @click="guardarContactoYBorrador"
                :disabled="guardandoContactoBorrador || (!borradorEmailInput.trim() && !borradorTelefonoInput.trim())"
                class="btn-primary flex-1 text-xs py-1.5 disabled:opacity-50"
              >{{ guardandoContactoBorrador ? 'Guardando...' : 'Guardar y enviar' }}</button>
            </div>
          </template>

          <button
            v-else
            @click="iniciarBorradorConEnvio"
            :disabled="submitting"
            class="mt-1 w-full py-2 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors flex items-center justify-center gap-1.5"
          >
            <IconoS v-if="submitting && modoGuardarBorrador" class="w-4 h-4" />
            {{ submitting && modoGuardarBorrador ? 'Guardando...' : 'Guardar borrador y enviar PDF' }}
          </button>
        </div>

        <!-- Opción B: completar datos que faltan y crear orden ahora -->
        <div class="bg-white border border-amber-200 rounded-lg p-3 space-y-2">
          <p class="text-xs font-semibold text-gray-700">Opción B — Completar información y crear la orden ahora</p>
          <div v-if="!clienteSeleccionado.nombre">
            <label class="text-xs text-gray-500 mb-1 block">Nombre completo <span class="text-red-500">*</span></label>
            <input v-model="formCompletarCliente.nombre" type="text" placeholder="Nombre y apellido" class="input" />
          </div>
          <div v-if="!clienteSeleccionado.cedula">
            <label class="text-xs text-gray-500 mb-1 block">Cédula / NIT <span class="text-red-500">*</span></label>
            <input v-model="formCompletarCliente.cedula" type="text" inputmode="numeric" placeholder="Ej: 1012345678" class="input" />
          </div>
          <div v-if="!clienteSeleccionado.telefono">
            <label class="text-xs text-gray-500 mb-1 block">Teléfono <span class="text-red-500">*</span></label>
            <input v-model="formCompletarCliente.telefono" type="tel" placeholder="Ej: 3001234567" class="input" />
          </div>
          <div>
            <label class="text-xs text-gray-500 mb-1 block">Email <span class="text-gray-400 font-normal">(opcional)</span></label>
            <input v-model="formCompletarCliente.email" type="email" placeholder="correo@ejemplo.com" class="input" />
          </div>
          <div v-if="!clienteSeleccionado.direccion">
            <label class="text-xs text-gray-500 mb-1 block">Dirección <span class="text-red-500">*</span></label>
            <input v-model="formCompletarCliente.direccion" type="text" placeholder="Dirección de entrega" class="input" />
          </div>
          <p v-if="errCompletarCliente" class="text-xs text-red-600">{{ errCompletarCliente }}</p>
          <button
            @click="completarYConvertirCliente"
            :disabled="guardandoCompletarCliente"
            class="w-full py-2 bg-amber-500 text-white text-xs font-semibold rounded-lg hover:bg-amber-600 disabled:opacity-50 transition-colors flex items-center justify-center gap-1.5"
          >
            <IconoS v-if="guardandoCompletarCliente" class="w-3.5 h-3.5" />
            {{ guardandoCompletarCliente ? 'Guardando...' : 'Guardar y continuar' }}
          </button>
        </div>
      </div>

      <!-- "Se lo lleva ahora": por producto. El interruptor marca todo lo que
           está en la tienda; cada ítem del carrito tiene su propia casilla. -->
      <div v-if="puedeEntregaInmediata" class="rounded-xl border p-3"
        :class="seLlevaAlgo ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-white'">
        <label class="flex items-center gap-2.5 cursor-pointer select-none">
          <div
            @click="marcarTodoParaLlevar(!entregaInmediata)"
            :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0', entregaInmediata ? 'bg-green-600' : 'bg-gray-300']"
          >
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', entregaInmediata ? 'translate-x-5' : 'translate-x-0.5']" />
          </div>
          <span class="text-sm font-medium text-gray-700 flex items-center gap-1">
            🛍️ Se lleva ahora todo lo que está en la tienda
            <span class="text-xs text-gray-400 font-normal">({{ itemsQueSePuedenLlevar.length }} de {{ items.length }})</span>
          </span>
        </label>
        <p v-if="seLlevaTodo" class="text-xs text-green-700 mt-1.5 ml-[52px]">
          La orden quedará <strong>entregada</strong> y el inventario se descuenta de una. Si el cliente no paga todo, el saldo queda pendiente y lo cobras luego desde el detalle.
        </p>
        <p v-else-if="seLlevaAlgo" class="text-xs text-green-700 mt-1.5 ml-[52px]">
          Lo marcado sale hoy; el resto sigue su camino (taller o despacho) y la orden mostrará <strong>entrega parcial</strong>. El saldo se cobra en la última entrega.
        </p>
        <p v-else class="text-xs text-gray-400 mt-1.5 ml-[52px]">
          También puedes marcarlo producto por producto en el carrito: "Se lo lleva ahora".
        </p>
      </div>

      <!-- Anticipo — oculto cuando hay ítems con cotización pendiente -->
      <template v-if="!hayItemsCotizar">
        <div v-if="!pagoSplit">
          <label class="label">Anticipo rápido</label>
          <div class="flex gap-2 flex-wrap">
            <button
              @click="anticipo_pct = 0; anticipo_monto = 0"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                anticipo_monto === 0
                  ? 'bg-gray-700 text-white border-gray-700'
                  : 'bg-white text-gray-700 border-gray-300']"
            >$0</button>
            <button v-for="pct in [30, 50, 70, 100]" :key="pct"
              @click="anticipo_pct = pct; anticipo_monto = minimoAnticipo"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                anticipo_monto > 0 && anticipoPctActual === pct
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white text-gray-700 border-gray-300']"
            >{{ pct }}%</button>
          </div>
        </div>

        <!-- Modo pago simple (un solo método) -->
        <template v-if="!pagoSplit">
          <div>
            <label class="label">Monto anticipo</label>
            <InputPesos v-model="anticipo_monto" class="input"
              />
            <p v-if="valorTotal > 0 && anticipo_monto > 0" class="mt-1 text-xs text-gray-400">
              = {{ anticipoPctActual }}% del total
            </p>
            <p v-else-if="anticipo_monto === 0" class="mt-1 text-xs text-amber-500">
              Sin anticipo — la orden queda pendiente de pago
            </p>
          </div>
          <div>
            <label class="label">Método de pago</label>
            <div class="flex gap-2 flex-wrap">
              <button v-for="m in metodosOpts" :key="m.value" @click="anticipo_metodo = m.value"
                :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                  anticipo_metodo === m.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300']">
                {{ m.label }}
              </button>
            </div>
          </div>
          <div v-if="anticipo_metodo !== 'efectivo'">
            <label class="label">Referencia / número transacción</label>
            <input v-model="anticipo_referencia" class="input" placeholder="Opcional" />
          </div>
        </template>

        <!-- Toggle pago en dos métodos -->
        <div v-if="anticipo_monto > 0 || pagoSplit" class="flex items-center justify-between pt-1">
          <span class="text-sm text-gray-600">Pago en dos métodos</span>
          <button type="button" @click="togglePagoSplit"
            :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', pagoSplit ? 'bg-blue-600' : 'bg-gray-200']">
            <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', pagoSplit ? 'translate-x-6' : 'translate-x-1']" />
          </button>
        </div>

        <!-- Modo pago dividido: divide el anticipo entre dos métodos -->
        <template v-if="pagoSplit">
          <!-- Encabezado: total fijo a dividir -->
          <div class="bg-gray-50 rounded-xl px-3 py-2.5 flex items-center justify-between">
            <span class="text-xs text-gray-500">Total anticipo a dividir</span>
            <span class="text-sm font-bold text-gray-800">${{ anticipo_monto.toLocaleString('es-CO') }}</span>
          </div>

          <!-- Primer pago: monto editable -->
          <div class="border border-blue-200 rounded-xl p-3 space-y-2.5 bg-blue-50/40">
            <p class="text-xs font-semibold text-blue-700">Primer pago</p>
            <div>
              <label class="label">Monto</label>
              <InputPesos v-model="anticipo_monto1_input" class="input" placeholder="0"
              />
              <p v-if="anticipo_monto1_input > anticipo_monto" class="text-xs text-red-500 mt-1">No puede superar el total</p>
            </div>
            <div>
              <label class="label">Método</label>
              <div class="flex gap-2 flex-wrap">
                <button v-for="m in metodosOpts" :key="m.value" @click="anticipo_metodo = m.value"
                  :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                    anticipo_metodo === m.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300']">
                  {{ m.label }}
                </button>
              </div>
            </div>
            <div v-if="anticipo_metodo !== 'efectivo'">
              <label class="label">Referencia</label>
              <input v-model="anticipo_referencia" class="input" placeholder="Opcional" />
            </div>
          </div>

          <!-- Segundo pago: monto calculado automáticamente -->
          <div class="border border-gray-200 rounded-xl p-3 space-y-2.5">
            <div class="flex items-center justify-between">
              <p class="text-xs font-semibold text-gray-600">Segundo pago</p>
              <span class="text-sm font-bold text-gray-700">${{ Math.max(0, anticipo_monto - anticipo_monto1_input).toLocaleString('es-CO') }}</span>
            </div>
            <div>
              <label class="label">Método</label>
              <div class="flex gap-2 flex-wrap">
                <button v-for="m in metodosOpts" :key="m.value" @click="anticipo_metodo2 = m.value"
                  :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
                    anticipo_metodo2 === m.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300']">
                  {{ m.label }}
                </button>
              </div>
            </div>
            <div v-if="anticipo_metodo2 !== 'efectivo'">
              <label class="label">Referencia</label>
              <input v-model="anticipo_referencia2" class="input" placeholder="Opcional" />
            </div>
          </div>
        </template>
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

      <!-- Media venta abonada a una tienda (solo independientes) -->
      <div v-if="auth.isIndependiente" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <div class="flex items-center gap-2">
          <BuildingStorefrontIcon class="w-5 h-5 text-emerald-500" />
          <span class="text-sm font-semibold text-gray-700">Compartir la venta con un almacén</span>
        </div>
        <p class="text-xs text-gray-500 -mt-1">
          Si el almacén te pasó el contacto, la venta se reparte: la mitad
          cuenta para ellos y la otra mitad para ti.
        </p>

        <div class="grid grid-cols-2 gap-2">
          <button
            type="button"
            @click="tiendaAbonadaId = null"
            :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors',
              !tiendaAbonadaId
                ? 'border-emerald-500 bg-emerald-50 text-emerald-800'
                : 'border-gray-200 bg-white text-gray-600 hover:border-emerald-300']"
          >Sin compartir</button>
          <button
            v-for="t in tiendasAbonables"
            :key="t.id"
            type="button"
            @click="tiendaAbonadaId = t.id"
            :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors',
              tiendaAbonadaId === t.id
                ? 'border-emerald-500 bg-emerald-50 text-emerald-800'
                : 'border-gray-200 bg-white text-gray-600 hover:border-emerald-300']"
          >{{ t.nombre }}</button>
        </div>

      </div>

      <!-- Venta compartida -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <UserGroupIcon class="w-5 h-5 text-indigo-500" />
            <span class="text-sm font-semibold text-gray-700">Venta compartida</span>
          </div>
          <button
            type="button"
            @click="esCompartida = !esCompartida"
            :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
              esCompartida ? 'bg-indigo-600' : 'bg-gray-200']"
          >
            <span
              :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                esCompartida ? 'translate-x-6' : 'translate-x-1']"
            />
          </button>
        </div>

        <p v-if="!esCompartida" class="text-xs text-gray-400">
          Activa si otro asesor de diferente tienda participó en esta venta. El valor se divide 50/50 para ambas metas.
        </p>

        <template v-if="esCompartida">
          <p class="text-xs text-indigo-700 bg-indigo-50 rounded-lg px-3 py-2">
            Cada vendedor suma <strong>${{ Math.round(valorTotal / 2).toLocaleString('es-CO') }}</strong> a su meta mensual.
          </p>

          <div v-if="cargandoVendedores" class="text-xs text-gray-400 text-center py-2">Cargando asesores...</div>

          <template v-else>
            <label class="label">Co-vendedor <span class="text-red-500">*</span></label>
            <div class="grid gap-2 max-h-48 overflow-y-auto">
              <button
                v-for="v in vendedoresLista"
                :key="v.id"
                type="button"
                @click="covendedorId = v.id"
                :class="['flex items-center justify-between px-3 py-2 rounded-lg border text-sm transition-colors',
                  covendedorId === v.id
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-800 font-semibold'
                    : 'border-gray-200 bg-white text-gray-700 hover:border-indigo-300']"
              >
                <span>{{ v.nombre }}</span>
                <span class="text-xs text-gray-400">{{ v.tienda }}</span>
              </button>
              <p v-if="!vendedoresLista.length" class="text-xs text-gray-400 text-center py-2">No hay otros asesores activos.</p>
            </div>
          </template>
        </template>
      </div>

      <!-- La fecha que se acuerda acá ES la fecha de entrega: entra sola en la
           orden y en el taller. Antes era solo una referencia y alguien tenía
           que ir orden por orden poniendo la de verdad. -->
      <div>
        <label class="label">¿Para qué fecha se la prometiste al cliente?</label>
        <input
          v-model="fechaSugeridaVendedor"
          type="date"
          :min="hoy"
          :class="['input', !fechaSugeridaVendedor ? 'border-amber-300 bg-amber-50' : '']"
        />
        <p v-if="fechaSugeridaVendedor" class="text-xs text-gray-500 mt-1">
          Esta queda como la fecha de entrega de la orden. Producción trabaja con ella.
        </p>
        <p v-else class="text-xs text-amber-700 mt-1">
          Sin esto la orden queda sin fecha de entrega y alguien tiene que ponérsela después.
        </p>
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
        :sugerencia="clienteSeleccionado?.direccion || ''"
      />

      <!-- ── Anexo de garantías ──
           En la tienda se firma aquí mismo; a distancia se le manda el
           enlace al cliente y su firma aparece aquí al instante. -->
      <div class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
        <div class="flex items-center justify-between gap-2">
          <p class="text-sm font-semibold text-gray-800">Anexo de garantías</p>
          <span v-if="anexo?.estado === 'firmado'" class="text-[11px] font-semibold text-green-700 bg-green-50 border border-green-200 rounded-full px-2 py-0.5">Firmado ✓</span>
          <span v-else-if="anexo?.estado === 'pendiente'" class="text-[11px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5">Esperando firma</span>
        </div>

        <!-- Firmado -->
        <template v-if="anexo?.estado === 'firmado'">
          <p class="text-xs text-gray-600">
            Lo firmó <strong>{{ anexo.nombre_firmante }}</strong>
            {{ anexo.modo === 'remoto' ? 'desde su teléfono' : 'en la tienda' }}.
            Su firma queda también como firma de la orden.
          </p>
          <div v-if="anexoDesactualizado" class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2 space-y-2">
            <p>Cambiaste la orden después de que el cliente la firmó: lo que firmó ya no es lo que va. Envíasela de nuevo.</p>
            <button type="button" @click="quitarAnexo(); enviarAnexoAlCliente()" class="font-semibold underline">Enviar de nuevo</button>
          </div>
          <div class="flex gap-2">
            <button type="button" @click="verPdfAnexo" class="flex-1 text-xs font-semibold border border-gray-300 rounded-lg py-2 hover:bg-gray-50">Ver anexo (PDF)</button>
            <button type="button" @click="quitarAnexo" class="text-xs font-medium text-gray-500 px-3 hover:text-red-600">Quitar</button>
          </div>
        </template>

        <!-- Enviado al cliente, esperando -->
        <template v-else-if="anexo?.estado === 'pendiente'">
          <p class="text-xs text-gray-600 flex items-center gap-2">
            <IconoS class="w-4 h-4" />
            Esperando a que {{ clienteSeleccionado?.nombre?.split(' ')[0] ?? 'el cliente' }} revise y firme. Aparece aquí solo, sin recargar.
          </p>
          <div v-if="anexoDesactualizado" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Cambiaste la orden después de enviarla: el cliente está viendo la versión anterior.
            <button type="button" @click="quitarAnexo(); enviarAnexoAlCliente()" class="font-semibold underline">Enviar la nueva</button>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <button type="button" @click="abrirWhatsAppAnexo" class="text-xs font-semibold rounded-lg py-2 bg-green-600 text-white hover:bg-green-700">WhatsApp</button>
            <button type="button" @click="copiarEnlaceAnexo" class="text-xs font-semibold rounded-lg py-2 border border-gray-300 hover:bg-gray-50">Copiar enlace</button>
          </div>
          <div class="flex gap-2">
            <input v-model="emailAnexo" type="email" placeholder="Correo del cliente" class="input text-sm flex-1" />
            <button type="button" @click="enviarCorreoAnexo" :disabled="enviandoAnexoEmail || !emailAnexo.trim()" class="text-xs font-semibold rounded-lg px-3 border border-gray-300 hover:bg-gray-50 disabled:opacity-40">
              {{ enviandoAnexoEmail ? '…' : 'Enviar' }}
            </button>
          </div>
          <div class="flex items-center justify-between">
            <button type="button" @click="refrescarAnexo" class="text-xs text-blue-600 font-medium">Ya firmó, revisar</button>
            <button type="button" @click="quitarAnexo" class="text-xs text-gray-500 hover:text-red-600">Cancelar envío</button>
          </div>
        </template>

        <!-- Vencido -->
        <template v-else-if="anexo?.estado === 'vencido'">
          <p class="text-xs text-gray-600">El enlace venció sin que el cliente firmara.</p>
          <button type="button" @click="quitarAnexo(); enviarAnexoAlCliente()" class="text-xs font-semibold text-blue-600">Enviar uno nuevo</button>
        </template>

        <!-- Sin anexo todavía -->
        <template v-else>
          <p class="text-xs text-gray-500">
            El cliente lo lee, marca cada parte, responde el check list y firma con el dedo.
            {{ canal === 'fisica' ? 'Hazlo aquí en la tienda, o mándaselo si se fue.' : 'Mándaselo: ve el resumen de su pedido antes de firmar.' }}
          </p>
          <div :class="['grid gap-2', canal === 'fisica' ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1']">
            <button
              v-if="canal === 'fisica'"
              type="button"
              @click="firmarAnexoAqui"
              :disabled="preparandoAnexo || !clienteSeleccionado"
              class="rounded-lg py-2.5 text-sm font-semibold bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-40"
            >{{ preparandoAnexo ? 'Abriendo…' : 'Llenar y firmar aquí' }}</button>
            <button
              type="button"
              @click="enviarAnexoAlCliente"
              :disabled="preparandoAnexo || !clienteSeleccionado"
              :class="['rounded-lg py-2.5 text-sm font-semibold disabled:opacity-40',
                canal === 'fisica' ? 'border border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-gray-900 text-white hover:bg-gray-800']"
            >{{ preparandoAnexo && canal !== 'fisica' ? 'Preparando…' : 'Enviar al cliente para firmar' }}</button>
          </div>
        </template>
      </div>

      <!-- Foto del anexo en papel — sigue siendo posible en la tienda -->
      <div v-if="canal === 'fisica' && anexo?.estado !== 'firmado'">
        <label class="label">
          ¿Lo firmó en papel? Foto del anexo
          <span class="text-xs font-normal text-gray-400 ml-1">(opcional)</span>
        </label>
        <p class="text-xs text-gray-400 mb-2">Si el cliente firmó el documento impreso, súbele la foto.</p>

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
            @change="onAnexoFotoChange"
            class="hidden"
          />
        </label>
      </div>

      <!-- Foto del comprobante -->
      <div>
        <label class="label">
          {{ pagosDivididos ? 'Fotos de los comprobantes (una por cada pago)' : 'Foto del comprobante' }}
          <span class="text-red-500 ml-0.5">*</span>
        </label>
        <div v-if="facturaFotos.length" class="space-y-2">
          <div :class="facturaFotos.length > 1 ? 'grid grid-cols-2 gap-2' : ''">
            <div v-for="(f, i) in facturaFotos" :key="f.preview" class="relative">
              <img
                :src="f.url || f.preview"
                :alt="`Comprobante ${i + 1}`"
                :class="['w-full rounded-xl border-2 border-gray-200 bg-gray-50', facturaFotos.length > 1 ? 'h-32 object-cover' : 'object-contain']"
                :style="facturaFotos.length > 1 ? '' : 'max-height: 240px;'"
              />
              <p v-if="pagosDivididos?.[i]" class="text-[11px] text-gray-500 mt-1 truncate">
                Pago {{ i + 1 }} · {{ pagosDivididos[i].metodo }} · ${{ pesos(pagosDivididos[i].monto) }}
              </p>
              <button
                @click="removeFacturaFoto(i)"
                class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow-lg"
              >
                <XMarkIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
          <p v-if="subiendoFactura" class="text-xs text-blue-600">Subiendo imágenes...</p>
          <!-- Falta el del segundo pago: se pide en grande, igual que el primero -->
          <label v-if="pagoSinComprobante" class="flex flex-col items-center gap-1 border-2 border-dashed border-amber-300 rounded-xl p-5 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
            <PhotoIcon class="w-7 h-7 text-amber-300" />
            <span class="text-sm font-medium text-gray-600">Toca para adjuntar el comprobante del pago {{ pagoSinComprobante.n }}</span>
            <span class="text-xs text-gray-400">{{ pagoSinComprobante.metodo }} · ${{ pesos(pagoSinComprobante.monto) }}</span>
            <input type="file" accept="image/*" multiple @change="onFacturaFotoChange" class="hidden" />
          </label>
          <label v-else-if="facturaFotos.length < 10" class="flex items-center justify-center gap-1.5 border border-dashed border-gray-300 rounded-lg py-2 cursor-pointer text-xs text-gray-500 hover:border-blue-400">
            <PhotoIcon class="w-4 h-4 text-gray-400" /> Agregar otra foto del comprobante
            <input type="file" accept="image/*" multiple @change="onFacturaFotoChange" class="hidden" />
          </label>
        </div>
        <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-amber-300 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
          <PhotoIcon class="w-8 h-8 text-amber-300" />
          <template v-if="pagoSinComprobante">
            <span class="text-sm text-gray-500">Toca para adjuntar el comprobante del pago 1</span>
            <span class="text-xs text-gray-400">{{ pagoSinComprobante.metodo }} · ${{ pesos(pagoSinComprobante.monto) }}</span>
          </template>
          <template v-else>
            <span class="text-sm text-gray-500">Toca para adjuntar foto del comprobante</span>
          </template>
          <span class="text-xs text-gray-400">JPG, PNG — puedes subir varias</span>
          <input
            type="file"
            accept="image/*"
            multiple
            @change="onFacturaFotoChange"
            class="hidden"
          />
        </label>
        <p v-if="!facturaFotoFile || faltanComprobantes" class="text-xs text-amber-600 flex items-center gap-1 mt-1">
          <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 inline-block" />
          {{ comprobantesRequeridos === 2
            ? `Se requiere el comprobante de los dos pagos para crear la orden (llevas ${facturaFotos.length} de 2)`
            : 'Se requiere foto del comprobante para crear la orden' }}
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

        <p v-if="consultaYaPedida" class="text-xs text-violet-700 bg-white border border-violet-200 rounded-lg px-3 py-2">
          Ya se pidió el precio al guardar el borrador. Esa consulta sigue abierta: no hace falta pedirla otra vez.
        </p>
        <div v-else class="space-y-1.5">
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
                <p :class="['text-xs text-gray-400', r.rol_nombre ? '' : 'capitalize']">{{ r.rol_nombre ?? r.rol }}</p>
              </div>
            </label>
            <p v-if="!receptoresCotizar.length" class="text-xs text-gray-400">No hay supervisores ni ebanistas activos.</p>
          </div>
        </div>

        <div v-if="!consultaYaPedida" class="space-y-1">
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
        <!-- Ya firmó: en el anexo, o al guardar el borrador. No se le pide otra vez. -->
        <div v-if="firmaUrl && !firmaBlob" class="rounded-xl border border-green-200 bg-green-50 p-3 space-y-2">
          <img :src="firmaUrl" alt="Firma del cliente" class="h-20 mx-auto object-contain bg-white rounded-lg border border-green-100" />
          <p class="text-xs text-green-800 text-center">
            {{ firmaDesdeAnexo ? 'Es la firma que puso en el anexo de garantías.' : 'Ya había firmado.' }}
          </p>
          <button type="button" @click="firmaUrl = ''" class="w-full text-xs font-medium text-gray-600 hover:text-gray-900">Firmar de nuevo aquí</button>
        </div>
        <FirmaCanvas v-else v-model="firmaBlob" />
        <p v-if="!tieneFirma" class="text-xs text-amber-600 flex items-center gap-1 mt-1">
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

       <!-- No crea de una: primero el resumen para revisar (ver revisarAntesDeCrear). -->
       <button
         @click="revisarAntesDeCrear"
         :disabled="submitting || subiendoFactura || cooldown > 0 || clienteRequiereCompletar || (!hayItemsCotizar && !tieneFirma) || !facturaFotos.length || faltanComprobantes || anexoDesactualizado"
         class="btn-primary w-full text-base py-3 flex items-center justify-center gap-2"
       >
         <IconoS v-if="submitting && !modoGuardarBorrador" class="w-5 h-5" />
         {{ subiendoFactura ? 'Subiendo foto...' : (submitting && !modoGuardarBorrador) ? 'Guardando...' : cooldown > 0 ? `Reintentar en ${cooldown}s...` : seLlevaTodo ? 'Registrar venta directa (entregada)' : seLlevaAlgo ? 'Crear orden y entregar lo marcado' : 'Crear orden' }}
       </button>

       <button
         v-if="!clienteRequiereCompletar"
         @click="submitBorrador(false)"
         :disabled="submitting || cooldown > 0"
         class="btn-secondary w-full py-3 flex items-center justify-center gap-2"
       >
         <IconoS v-if="submitting && modoGuardarBorrador" class="w-5 h-5" />
         {{ (submitting && modoGuardarBorrador) ? 'Guardando borrador...' : 'Guardar como borrador' }}
       </button>
    </template>

  </div>

  <!-- Revisar todo antes de crear la orden -->
  <ResumenOrdenModal
    :show="mostrarResumen"
    :resumen="resumenOrden"
    :texto-confirmar="seLlevaTodo ? 'Confirmar venta directa' : seLlevaAlgo ? 'Confirmar y entregar lo marcado' : 'Confirmar y crear'"
    @volver="mostrarResumen = false"
    @confirmar="confirmarYCrear"
  />

  <!-- Anexo de garantías en la tienda: pantalla completa para que el
       cliente lo lea con calma en el teléfono o la tableta. -->
  <Transition name="fade">
    <div v-if="mostrarAnexoAqui && anexoContenido" class="fixed inset-0 z-[75] bg-white overflow-y-auto">
      <div class="max-w-lg mx-auto px-4 pb-10">
        <div class="flex items-center justify-between py-3">
          <div>
            <p class="text-base font-bold text-gray-800">Anexo de garantías</p>
            <p class="text-xs text-gray-500">Para que {{ clienteSeleccionado?.nombre?.split(' ')[0] ?? 'el cliente' }} lo lea y firme</p>
          </div>
          <button type="button" @click="mostrarAnexoAqui = false" class="text-gray-400 text-2xl leading-none" aria-label="Cerrar">&times;</button>
        </div>
        <AnexoFirma
          :contenido="anexoContenido.contenido"
          :nombre="anexoContenido.cliente?.nombre ?? clienteSeleccionado?.nombre ?? ''"
          :documento="anexoContenido.cliente?.cedula ?? clienteSeleccionado?.cedula ?? ''"
          :enviando="firmandoAnexo"
          @firmar="guardarAnexoAqui"
        />
      </div>
    </div>
  </Transition>

  <!-- Modal picker de variante -->
  <Transition name="fade">
    <!-- Orden sin terminar guardada en el teléfono. No se cierra tocando
         afuera: si se escribe encima sin decidir, lo nuevo pisaría lo que
         había y justo eso es lo que se quiere evitar. -->
    <div v-if="borradorPendiente && !cargandoBorradorServidor" class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center">
      <div class="absolute inset-0 bg-black/50" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 flex flex-col gap-4">
        <div>
          <h3 class="text-base font-bold text-gray-800">
            Tienes {{ modoCotizacion ? 'una cotización' : 'una orden' }} sin terminar
          </h3>
          <p class="text-xs text-gray-500 mt-0.5">
            Se guardó en este teléfono {{ haceCuanto(borradorPendiente.guardadoEn) }}.
          </p>
        </div>
        <div v-if="borradorPendiente.resumen" class="bg-gray-50 border border-gray-200 rounded-xl p-3">
          <p class="text-sm font-semibold text-gray-800 truncate">{{ borradorPendiente.resumen.titulo }}</p>
          <p class="text-xs text-gray-500 mt-0.5">{{ borradorPendiente.resumen.detalle }}</p>
        </div>
        <!-- Borrar es lo único que no tiene vuelta atrás: va discreto, lejos
             del botón principal, y pide confirmar. Un dedo apurado que tocaba
             debajo de "Continuar" se llevaba la orden. -->
        <div v-if="!confirmandoDescarte" class="flex flex-col gap-3">
          <button @click="borradorLocal.continuar()" class="btn-primary w-full py-3 text-sm font-semibold">
            Continuar donde iba
          </button>
          <button @click="confirmandoDescarte = true" class="self-center text-xs text-gray-400 underline py-2 px-3">
            Empezar de cero
          </button>
        </div>
        <div v-else class="flex flex-col gap-2">
          <p class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-3">
            ¿Seguro? Se borra
            <strong>{{ borradorPendiente.resumen?.titulo ?? (modoCotizacion ? 'la cotización' : 'la orden') }}</strong><template v-if="borradorPendiente.resumen"> ({{ borradorPendiente.resumen.detalle }})</template>
            y no se puede recuperar.
          </p>
          <button @click="confirmandoDescarte = false" class="btn-primary w-full py-3 text-sm font-semibold">
            No, volver
          </button>
          <button
            @click="confirmandoDescarte = false; borradorLocal.descartar()"
            class="w-full py-2 text-sm text-red-600 border border-red-200 rounded-lg hover:bg-red-50"
          >
            Sí, borrarla y empezar de cero
          </button>
        </div>
      </div>
    </div>

    <div v-if="mostrarVariantePicker" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="mostrarVariantePicker = false">
      <div class="absolute inset-0 bg-black/50" @click="mostrarVariantePicker = false" />
      <!-- Se topa en la pantalla y rueda la LISTA, no el modal entero: un
           producto con muchas telas crecía hasta salirse, y como en el celular
           el modal se ancla abajo, lo que quedaba fuera era lo de arriba --
           justo donde está "Sin especificar tela". Sin poder alcanzarla, y con
           el botón de agregar bloqueado hasta elegir algo, no había forma de
           vender sin decir la tela. El botón queda siempre a la vista. -->
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 flex flex-col gap-4 max-h-[85vh]">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-base font-bold text-gray-800">Seleccionar variante</h3>
            <p class="text-xs text-gray-500 mt-0.5 truncate">{{ productoParaVariante?.nombre }}</p>
          </div>
          <button @click="mostrarVariantePicker = false" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="cargandoVariantes" class="text-center py-6 text-gray-400 text-sm">Cargando variantes...</div>

        <div v-else class="space-y-2 flex-1 min-h-0 overflow-y-auto -mx-1 px-1">
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
            <span class="block text-[11px] text-gray-400 mt-0.5">
              Solo si de verdad da igual cuál se lleve: la orden saldrá sin decir la tela.
            </span>
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

        <p v-if="varianteSeleccionada === undefined && !cargandoVariantes" class="text-xs text-amber-600 font-medium text-center">
          Elige la tela antes de agregar. Queda escrita en la orden y en el acta
          de entrega, para que no se despache la que no es.
        </p>

        <button
          @click="confirmarVariante"
          :disabled="varianteSeleccionada === undefined"
          class="w-full bg-blue-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
        >
          {{ editandoIdx !== null ? 'Guardar cambio' : 'Agregar al carrito' }}
        </button>
      </div>
    </div>
  </Transition>

  <!-- Modal picker variante FÁBRICA (tapizado) -->
  <Transition name="fade">
    <!-- ── Picker variantes personalizadas (custom) ── -->
    <div v-if="mostrarVCPicker" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="mostrarVCPicker = false">
      <div class="absolute inset-0 bg-black/50" @click="mostrarVCPicker = false" />
      <!-- Tenía scroll, pero del modal entero: con varios grupos el botón de
           agregar se iba hasta el fondo. Ahora rueda solo la lista. -->
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 flex flex-col gap-4 max-h-[85vh]">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-base font-bold text-gray-800">
              {{ vcPickerModo === 'stock' ? 'Selecciona variantes' : '¿De cuál versión se parte?' }}
            </h3>
            <p class="text-xs text-indigo-600 mt-0.5 truncate">{{ vcPickerProd?.nombre }}</p>
            <p v-if="vcPickerModo !== 'stock'" class="text-[11px] text-gray-500 mt-1">
              {{ vcPickerModo === 'fabricar' ? 'Se manda a fabricar' : 'Se personaliza' }} a partir de esta versión:
              no importa si hay en stock. Elige solo lo que cambia.
            </p>
          </div>
          <button @click="mostrarVCPicker = false" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="vcPickerCargando" class="text-center py-6 text-gray-400 text-sm">Cargando variantes...</div>

        <div v-else class="space-y-4 flex-1 min-h-0 overflow-y-auto -mx-1 px-1">
          <div v-for="grupo in vcPickerGrupos" :key="grupo.tipo_variante_id" class="space-y-2">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">{{ grupo.tipo.nombre }}</p>
            <div class="space-y-1.5">
              <button
                v-for="opt in grupo.items"
                :key="opt.id"
                :disabled="!opcionVCElegible(opt)"
                @click="elegirOpcionVC(grupo, opt)"
                :class="['w-full text-left px-3 py-2.5 rounded-xl border text-sm transition-colors',
                  vcPickerSelec[grupo.tipo_variante_id]?.config_id === opt.id
                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700 font-medium'
                    : opcionVCElegible(opt)
                      ? 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                      : 'border-gray-100 bg-gray-50 text-gray-300 cursor-not-allowed']"
              >
                <span class="font-medium">{{ opt.opcion_nombre }}</span>
                <span v-if="(opt.precio_adicional ?? 0) > 0" class="text-xs ml-2 text-indigo-600 font-semibold">
                  ${{ Number(opt.precio_adicional).toLocaleString('es-CO') }}
                </span>
                <span v-if="vcPickerModo === 'stock'" class="text-xs ml-2 font-semibold" :class="(opt.stock_disponible ?? 0) > 0 ? 'text-green-600' : 'text-red-400'">
                  <!-- En juego el stock está en piezas; cada opción con su juego -->
                  <template v-if="piezasDeOpcion(vcPickerProd, opt.id)">
                    juego de {{ piezasDeOpcion(vcPickerProd, opt.id) }} ·
                    {{ enJuegos(opt.stock_disponible, piezasDeOpcion(vcPickerProd, opt.id)) }} disp.
                  </template>
                  <template v-else>{{ opt.stock_disponible ?? 0 }} disp.</template>
                </span>
              </button>
            </div>
          </div>
        </div>

        <button
          @click="confirmarVCPickerOrden"
          :disabled="!vcPickerValido"
          class="w-full bg-indigo-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-indigo-700 disabled:opacity-40"
        >
          {{ editandoIdx !== null ? 'Guardar cambio' : 'Agregar al pedido' }}
        </button>
        <button
          v-if="vcPickerModo !== 'stock'"
          type="button"
          @click="confirmarVCAPedido(true)"
          class="w-full -mt-2 text-xs font-medium text-gray-500 hover:text-gray-700 py-1"
        >Sin variante — la versión normal</button>
      </div>
    </div>
  </Transition>

  <Transition name="fade">
    <div v-if="mostrarFabricaVariantePicker" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="mostrarFabricaVariantePicker = false">
      <div class="absolute inset-0 bg-black/50" @click="mostrarFabricaVariantePicker = false" />
      <!-- Mismo tope y misma lista que ruedan que en el selector de tienda:
           la reserva de fábrica también tiene productos con muchas variantes. -->
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 flex flex-col gap-4 max-h-[85vh]">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-base font-bold text-gray-800">Variante de fábrica</h3>
            <p class="text-xs text-purple-600 mt-0.5 truncate">{{ fabricaVariantesProd?.nombre }}</p>
          </div>
          <button @click="mostrarFabricaVariantePicker = false" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="cargandoFabricaVariantes" class="text-center py-6 text-gray-400 text-sm">Cargando variantes...</div>

        <div v-else class="space-y-2 flex-1 min-h-0 overflow-y-auto -mx-1 px-1">
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
          {{ editandoIdx !== null ? 'Guardar cambio' : 'Tomar de fábrica' }}
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
  background-color: white;
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
