<script setup>
import { ref, computed, watch } from 'vue'
import { editarOrden, editarPago, buscarProductos, getTiendas } from '@/api/ordenes'
import { getVariantes } from '@/api/inventario'
import { getReservaInfo } from '@/api/reserva'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { SPECS_TEMPLATES, resolverCategoria } from '@/constants/specsConfig'
import { useTelas } from '@/composables/useTelas'
import TelaPicker from '@/components/ordenes/TelaPicker.vue'
import InputPesos from '@/components/common/InputPesos.vue'
import { pctDeMonto, montoDePct, formatPct } from '@/utils/descuentos'
import { PencilSquareIcon, XMarkIcon, SparklesIcon, MagnifyingGlassIcon, TrashIcon, PlusIcon, PhotoIcon, WrenchScrewdriverIcon, GiftIcon, BuildingStorefrontIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { cloudinaryOpt } from '@/utils/cloudinary'
import IconoS from '@/components/common/IconoS.vue'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import api from '@/api'

const props = defineProps({
  show: Boolean,
  orden: { type: Object, required: true },
  /**
   * La orden ya salió: sólo se corrigen los papeles.
   *
   * Se puede cambiar la foto de la factura, el anexo, las notas, la dirección
   * y lo que describe cada pieza; no el precio, la cantidad, los descuentos ni
   * qué productos lleva, porque eso ya se cobró, ya descontó bodega y ya
   * calculó comisión. El servidor lo rechaza igual: esto es para no ofrecer
   * campos que no se van a poder guardar.
   */
  soloPapeles: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'guardado'])
const toast = useToast()
const auth  = useAuthStore()

const notas          = ref('')
// Fecha que se le prometio al cliente; referencia para quien asigna la entrega
const fechaSugeridaVendedor = ref('')
const canal          = ref('')
const direccionEnvio = ref('')
const ciudadEnvio    = ref('')
const anticipoPct    = ref('')
const items          = ref([])
const itemsEliminar  = ref([])   // IDs de ítems existentes a borrar
const itemsNuevos    = ref([])   // Ítems nuevos a agregar
const guardando      = ref(false)

// ── Anticipo ─────────────────────────────────────────────────────────────────
const pagoAnticipo       = ref(null)   // pago tipo='anticipo' de la orden, si existe
const anticipoMonto      = ref('')
const anticipoMetodo     = ref('efectivo')
const anticipoReferencia = ref('')

// ── Reasignación (solo supervisor) ──────────────────────────────────────────
const esSupervisor  = computed(() => auth.usuario?.rol === 'supervisor')
const vendedorId    = ref(null)
const tiendaId      = ref(null)
const covendedorId  = ref(null)
const esCompartida  = ref(false)
const tiendaAbonadaId = ref(null)   // media venta abonada a una tienda
const tiendasLista    = ref([])
const vendedoresLista = ref([])

const opcionesVendedor = computed(() => {
  const list = [...vendedoresLista.value]
  const actual = props.orden.vendedor
  if (actual && !list.some(v => v.id === actual.id)) list.unshift({ id: actual.id, nombre: actual.nombre })
  return list
})

async function cargarListasSupervisor() {
  if (tiendasLista.value.length) return
  try {
    const [tiendasRes, asesoresRes] = await Promise.all([getTiendas(), api.get('/asesores')])
    tiendasLista.value    = tiendasRes.data
    vendedoresLista.value = asesoresRes.data
  } catch {}
}

// ── Totales en tiempo real ───────────────────────────────────────────────────
// El descuento se guarda en pesos, así que aquí se edita en pesos. Antes se
// derivaba el % del monto, se redondeaba a un decimal y se recalculaba el monto
// desde ese %: un descuento de $100.000 sobre $1.350.000 se convertía en
// $99.900 con solo abrir el modal y guardar.
const descuentoModoEdit  = ref('monto')   // 'monto' | 'pct'
const descuentoInputEdit = ref(0)

const subtotalEstimado = computed(() => {
  const existentes = items.value
    .filter(i => !itemsEliminar.value.includes(i.id))
    .reduce((s, i) => s + precioEfectivo(i) * (i.cantidad || 1), 0)
  const nuevos = itemsNuevos.value
    .reduce((s, i) => s + (parseFloat(i.precio_unitario) || 0) * (parseInt(i.cantidad) || 1), 0)
  return existentes + nuevos
})

/** Un porcentaje por encima de 100 no significa nada. Sin tope, escribir 90000
 *  pensando en pesos mientras el campo está en % dejaba el total en $0. */
function pctValido(v) {
  return Math.min(Math.max(0, Number(v) || 0), 100)
}

const descuentoTotalEdit = computed(() => {
  const v = Number(descuentoInputEdit.value) || 0
  const bruto = descuentoModoEdit.value === 'pct'
    ? montoDePct(pctValido(v), subtotalEstimado.value)
    : Math.round(Math.max(0, v))
  return Math.min(bruto, subtotalEstimado.value)
})

const descuentoPctEdit = computed(() => pctDeMonto(descuentoTotalEdit.value, subtotalEstimado.value))

// ── Descuento por efectivo/transferencia ────────────────────────────────────
// No se podía editar, y peor: al guardar cualquier cambio el backend rehacía el
// total sin tenerlo en cuenta, así que se perdía solo y el precio le subía al
// cliente. Ya no.
const descCondModoEdit  = ref('monto')
const descCondInputEdit = ref(0)

const baseCondEdit = computed(() =>
  Math.max(0, subtotalEstimado.value - descuentoTotalEdit.value)
)

const descCondEdit = computed(() => {
  const v = Number(descCondInputEdit.value) || 0
  const bruto = descCondModoEdit.value === 'pct'
    ? montoDePct(pctValido(v), baseCondEdit.value)
    : Math.round(Math.max(0, v))
  return Math.min(bruto, baseCondEdit.value)
})

const descCondPctEdit = computed(() => pctDeMonto(descCondEdit.value, baseCondEdit.value))

/** Ya se perdió por haber pagado con tarjeta: no se vuelve a ofrecer. */
const condicionadoRevertido = computed(() =>
  !!props.orden?.descuento_condicionado_revertido_at
)

const totalEstimado = computed(() =>
  Math.max(0, baseCondEdit.value - descCondEdit.value)
)

// ── Consulta de costo dejada activada por error ──────────────────────────────
// La orden está trabada esperando al supervisor. El backend la destraba solo
// cuando NINGÚN ítem personalizado sigue en cero, así que aquí se cuenta lo
// que falta para poder decirlo antes de guardar.
const itemsEsperandoCosto = computed(() =>
  items.value.filter(i => i._esperaCosto && !itemsEliminar.value.includes(i.id))
)
const faltanPreciosPorPoner = computed(() =>
  itemsEsperandoCosto.value.filter(i => !(Number(i.precio_unitario) > 0))
)
/** Se le puso precio a todo lo que faltaba: al guardar, la orden se destraba. */
const seDestrabaAlGuardar = computed(() =>
  props.orden?.estado === 'pendiente_cotizacion'
  && itemsEsperandoCosto.value.length > 0
  && faltanPreciosPorPoner.value.length === 0
)

/** El total quedaría en cero: casi siempre es una digitación mal puesta. */
const totalEditEnCero = computed(() =>
  subtotalEstimado.value > 0 && totalEstimado.value === 0
)

// ── Eliminar ítem existente ──────────────────────────────────────────────────
function marcarEliminar(item) {
  const prod = item._produccion
  // Una pieza cancelada ya no está en el taller, y quitarla de la orden es
  // justo lo que uno va a hacer después de cancelarla: se mandó a fabricar
  // algo que no había que fabricar. Lo que sigue bloqueado es el trabajo de
  // verdad en curso.
  const enCurso = prod
    && prod.estado !== 'cancelado'
    && prod.pasos?.some(p => ['en_proceso', 'completado'].includes(p.estado))
  if (enCurso) {
    toast.error(`"${item.producto_nombre || item.nombre_custom || 'Este ítem'}" ya está en producción. `
      + 'Si fue un error, cancélalo primero desde Producción.')
    return
  }
  itemsEliminar.value.push(item.id)
}
function desmarcarEliminar(itemId) {
  itemsEliminar.value = itemsEliminar.value.filter(id => id !== itemId)
}

// ── Nuevo ítem ───────────────────────────────────────────────────────────────
const nuevoQuery      = ref('')
const nuevoResultados = ref([])
const nuevoBuscando   = ref(false)
function nuevoItemVacio() {
  return {
    producto_id: null, producto_nombre: '', producto_categoria: null, personalizable: false,
    es_custom: false, nombre_custom: '', categoria_custom: '',
    // Mueble que trae el cliente: es un ítem fuera de catálogo (es_custom) más
    // esta marca, que lo separa de un "diseño especial" y lo hace aparecer en
    // el módulo de Restauración.
    es_restauracion: false, descripcion_trabajo: '',
    // Obsequio: el ítem vale $0 pero igual sale del inventario
    _regalo: false,
    modo: 'stock',              // 'stock' | 'personalizado' | 'fabricar' (para productos de catálogo)
    variante_id: null, variante_label: '', combo_config_id: null,
    cantidad: 1, precio_unitario: '', stock_libre: null,
    specs: {}, specs_notas: '', _telaSelections: {},
    boceto_urls: [], _subiendo: false,
  }
}

// Variantes (tela/color) del producto seleccionado para el ítem nuevo
const nuevoVariantes        = ref([])
const nuevoCargandoVariantes = ref(false)

// Variantes configurables (combos): grupos de opciones con precio y stock propio
const nuevoVCGrupos = ref([])
const nuevoVCSelec  = ref({})   // { tipo_variante_id: { opcion_nombre, tipo_nombre, precio_adicional, stock } }

async function cargarVariantesNuevo() {
  nuevoVariantes.value = []
  nuevoVCGrupos.value  = []
  nuevoVCSelec.value   = {}
  nuevoItem.value.variante_id = null
  nuevoItem.value.variante_label = ''
  if (!nuevoItem.value.producto_id) return
  nuevoCargandoVariantes.value = true
  try {
    const { data } = await getVariantes(nuevoItem.value.producto_id, nuevoTiendaOrigen.value)
    // Solo cuentan como variantes simples las de tela/color (no tallas ni configs)
    nuevoVariantes.value = (data ?? []).filter(v => !v.medida && (v.marca_tela || v.nombre_color))
    // Si no hay variantes simples, buscar variantes configurables (combos)
    if (!nuevoVariantes.value.length) {
      const { data: vc } = await api.get(`/productos/${nuevoItem.value.producto_id}/variante-configs`, {
        params: { tienda_id: nuevoTiendaOrigen.value },
      }).catch(() => ({ data: [] }))
      // Mostrar todos los grupos con opciones (el stock puede estar a nivel base)
      nuevoVCGrupos.value = (vc ?? []).filter(g => g.items?.length)
    }
  } catch {
    nuevoVariantes.value = []
    nuevoVCGrupos.value  = []
  } finally {
    nuevoCargandoVariantes.value = false
  }
}

// ¿Ya se eligió una opción en cada grupo de combo?
const nuevoVCCompleto = computed(() =>
  nuevoVCGrupos.value.length > 0 && nuevoVCGrupos.value.every(g => nuevoVCSelec.value[g.tipo_variante_id])
)

/**
 * ¿Hay que obligar a elegir variante?
 *
 * Solo si alguna variante tiene existencias. Hay muebles que llegaron a la
 * bodega sin que se supiera de qué tela eran, así que su stock quedó a nivel
 * del producto y no dentro de ninguna variante: todas marcan 0 mientras el
 * producto muestra 8 disponibles. Exigir una variante ahí vuelve el mueble
 * imposible de vender — no existe una que se pueda elegir.
 */
const nuevoVarianteObligatoria = computed(() =>
  nuevoVariantes.value.some(v => (v.stock_libre ?? 0) > 0)
)

// No se puede agregar un producto de stock sin stock disponible (usar "Para fabricar").
const nuevoSinStock = computed(() =>
  !nuevoItem.value.es_custom &&
  nuevoItem.value.modo === 'stock' &&
  !!nuevoItem.value.producto_id &&
  nuevoItem.value.stock_libre != null &&
  nuevoItem.value.stock_libre <= 0
)

function elegirOpcionVC(grupo, item) {
  nuevoVCSelec.value = {
    ...nuevoVCSelec.value,
    [grupo.tipo_variante_id]: {
      config_id: item.id,
      opcion_nombre: item.opcion_nombre,
      tipo_nombre: grupo.tipo?.nombre ?? '',
      precio_adicional: Number(item.precio_adicional ?? 0),
      stock: Number(item.stock_disponible ?? 0),
    },
  }
  aplicarVCaItem()
}

// Vuelca la selección de combos al ítem nuevo (label y precio). El stock del
// ítem queda en el stock base del producto (igual que Nueva Orden).
function aplicarVCaItem() {
  const sels = Object.values(nuevoVCSelec.value)
  if (!sels.length) return
  // Sólo el valor ("1.60"): el tipo se llama "Cama Miami medidas" y repetirlo
  // al lado del producto estorba. Igual que en Nueva Orden.
  nuevoItem.value.variante_label = sels.map(s => s.opcion_nombre).join(' · ')
  // La casilla de la orden admite una sola opción; con dos elegidas se guarda
  // sólo el texto, que sí las lleva todas.
  nuevoItem.value.combo_config_id = sels.length === 1 ? (sels[0].config_id ?? null) : null
  const adic = sels.reduce((sum, s) => sum + (s.precio_adicional || 0), 0)
  if (adic > 0) nuevoItem.value.precio_unitario = adic
}

function elegirVarianteNuevo(v) {
  if (!v) {
    nuevoItem.value.variante_id = null
    nuevoItem.value.variante_label = ''
    return
  }
  nuevoItem.value.variante_id = v.id
  nuevoItem.value.variante_label = [v.marca, v.marca_tela, v.nombre_color].filter(Boolean).join(' · ')
  nuevoItem.value.stock_libre = v.stock_libre ?? 0
  if (v.precio_variante != null) nuevoItem.value.precio_unitario = v.precio_variante
}
const nuevoItem        = ref(nuevoItemVacio())
const nuevoTiendaOrigen = ref(null)   // tienda de la que sale el stock del ítem nuevo

// La Bodega Fábrica no viene en /tiendas: ese endpoint filtra es_fabrica. Se
// pide aparte y se agrega a mano al selector, igual que en Nueva Orden, para
// poder sacar de la reserva un producto que en la tienda ya no hay.
const fabricaId = ref(null)

// "Independientes" agrupa a los vendedores sin tienda: no es una bodega y no
// tiene stock del que sacar. Mismo criterio que en Nueva Orden.
/** El vendedor de la orden (o el que se esta poniendo) es independiente? */
const vendedorEsIndependiente = computed(() => {
  const id = vendedorId.value ?? props.orden?.vendedor_id
  // Si sigue siendo el vendedor de la orden, se cree al dato que vino con
  // ella: la lista de asesores no trae ebanistas, y Henry es uno.
  if (id === props.orden?.vendedor_id) return !!props.orden?.vendedor?.independiente
  return !!vendedoresLista.value.find(v => v.id === id)?.independiente
})
const tiendasAbonables = computed(() =>
  tiendasLista.value.filter(t => !t.es_fabrica && !t.es_independientes && t.id !== tiendaId.value)
)

const tiendasOrigen = computed(() =>
  tiendasLista.value.filter(t => !t.es_independientes)
)

async function cargarTiendas() {
  if (!fabricaId.value) {
    try { const { data } = await getReservaInfo(); fabricaId.value = data.id } catch {}
  }
  if (tiendasLista.value.length) return
  try { const { data } = await getTiendas(); tiendasLista.value = data } catch {}
}

// Al cambiar la tienda origen, refrescar el stock del producto seleccionado.
async function refrescarStockNuevo() {
  if (!nuevoItem.value.producto_id || !nuevoTiendaOrigen.value) return
  try {
    const { data } = await api.get(`/productos/${nuevoItem.value.producto_id}`, { params: { tienda_id: nuevoTiendaOrigen.value } })
    nuevoItem.value.stock_libre = (data.stock_disponible ?? 0) - (data.stock_reservado ?? 0)
  } catch {}
  // Las variantes y su stock dependen de la tienda → recargar
  await cargarVariantesNuevo()
}
watch(nuevoTiendaOrigen, refrescarStockNuevo)

// Template de specs según la categoría del ítem nuevo (mismo criterio que al crear).
const nuevoTemplate = computed(() => {
  const nombre = nuevoItem.value.es_custom ? nuevoItem.value.nombre_custom : nuevoItem.value.producto_nombre
  const cat    = nuevoItem.value.es_custom ? nuevoItem.value.categoria_custom : nuevoItem.value.producto_categoria
  return SPECS_TEMPLATES[resolverCategoria(nombre, cat)] ?? SPECS_TEMPLATES['generico']
})
// ¿El ítem nuevo va a producción? (personalizado, para fabricar o diseño especial)
const nuevoEsProduccion = computed(() =>
  nuevoItem.value.es_custom || nuevoItem.value.modo === 'personalizado' || nuevoItem.value.modo === 'fabricar'
)

let nuevoDebounce = null
async function onBuscarNuevo(term) {
  nuevoQuery.value = term
  clearTimeout(nuevoDebounce)
  if (!term || term.length < 2) { nuevoResultados.value = []; return }
  nuevoDebounce = setTimeout(async () => {
    nuevoBuscando.value = true
    try {
      const { data } = await buscarProductos(term, nuevoTiendaOrigen.value)
      nuevoResultados.value = Array.isArray(data) ? data : (data.data ?? [])
    } catch { nuevoResultados.value = [] }
    finally { nuevoBuscando.value = false }
  }, 300)
}
function seleccionarNuevo(prod) {
  nuevoItem.value.producto_id        = prod.id
  nuevoItem.value.producto_nombre    = prod.nombre
  nuevoItem.value.producto_categoria = prod.categoria ?? null
  nuevoItem.value.personalizable     = !!prod.personalizable
  nuevoItem.value.precio_unitario    = prod.precio_base ?? ''
  nuevoItem.value.stock_libre        = (prod.stock_disponible ?? 0) - (prod.stock_reservado ?? 0)
  nuevoItem.value.es_custom          = false
  nuevoItem.value.modo               = 'stock'
  nuevoItem.value.variante_id        = null
  nuevoItem.value.variante_label     = ''
  nuevoQuery.value     = ''
  nuevoResultados.value = []
  cargarVariantesNuevo()
}
function iniciarDisenoEspecial() {
  nuevoItem.value = nuevoItemVacio()
  nuevoItem.value.es_custom = true
  nuevoQuery.value = ''
  nuevoResultados.value = []
}

/**
 * Obsequiar el ítem que se está agregando: queda en $0.
 *
 * Se guarda el precio que tenía para devolvérselo si lo desmarcan; si no,
 * marcar y desmarcar por error dejaba el producto en cero sin que se notara.
 */
function toggleRegaloNuevo() {
  const n = nuevoItem.value
  n._regalo = !n._regalo
  if (n._regalo) {
    n._precioAntesRegalo = n.precio_unitario
    n.precio_unitario    = 0
  } else {
    n.precio_unitario = n._precioAntesRegalo ?? ''
  }
}

/** Obsequiar (o dejar de obsequiar) un ítem que ya está en la orden. */
function toggleRegaloItem(item) {
  item._regalo = !item._regalo
  // Un obsequio ya vale $0: un descuento encima no significa nada
  if (item._regalo) item._descuento_valor = 0
}

function iniciarRestauracion() {
  nuevoItem.value = nuevoItemVacio()
  nuevoItem.value.es_custom        = true
  nuevoItem.value.es_restauracion  = true
  nuevoItem.value.categoria_custom = 'Restauración'
  nuevoQuery.value = ''
  nuevoResultados.value = []
}

// Consolida la tela elegida por campo del ítem nuevo (fase posterior: picker visual)
function telaResumidaNuevo(key) {
  const s = nuevoItem.value._telaSelections?.[key]
  if (!s?.marca || !s?.tipo || !s?.color) return ''
  return [s.marca, s.tipo, s.color].join(' · ')
}

/** Sube un archivo y devuelve su URL, o null si falló. */
async function subirFoto(file, carpeta) {
  const token = localStorage.getItem('token')
  const fd = new FormData()
  fd.append('foto', await comprimirImagen(file), 'foto.jpg')
  fd.append('folder', carpeta)
  const res  = await fetch('/api/upload/foto', {
    method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: fd,
  })
  const data = await res.json()
  return data.url ?? null
}

async function onNuevaFoto(e) {
  const files = Array.from(e.target.files || [])
  if (!files.length) return
  nuevoItem.value._subiendo = true
  try {
    for (const file of files) {
      const url = await subirFoto(file, 'bocetos')
      if (url) nuevoItem.value.boceto_urls.push(url)
    }
  } catch { toast.error('No se pudo subir la foto.') }
  finally { nuevoItem.value._subiendo = false; e.target.value = '' }
}

// ── Bocetos de un ítem que ya existe ────────────────────────────────────────
async function onBocetoItem(e, item) {
  const files = Array.from(e.target.files || [])
  if (!files.length) return
  if (item.boceto_urls.length + files.length > 10) {
    toast.error('Máximo 10 fotos por ítem.')
    e.target.value = ''
    return
  }
  item._subiendoBoceto = true
  try {
    for (const file of files) {
      const url = await subirFoto(file, 'bocetos')
      if (url) item.boceto_urls.push(url)
    }
  } catch { toast.error('No se pudo subir la foto.') }
  finally { item._subiendoBoceto = false; e.target.value = '' }
}

function quitarBocetoItem(item, i) {
  item.boceto_urls.splice(i, 1)
}

// ── Fotos de la orden (factura y anexo) ─────────────────────────────────────
const facturaFotoUrl = ref('')
const anexoFotoUrl   = ref('')
const subiendoOrden  = ref('')   // 'factura' | 'anexo' | ''

// ── Firma del cliente ───────────────────────────────────────────────────────
// Se vuelve a tomar en el momento, como al crear la orden; no se sube un
// archivo. Es la constancia de que el cliente aceptó, así que solo se
// reemplaza cuando quedó mal — nunca se borra.
const cambiandoFirma = ref(false)
const firmaBlob      = ref(null)
const firmaNuevaUrl  = ref('')

function abrirCambioFirma() {
  cambiandoFirma.value = true
  firmaBlob.value      = null
  firmaNuevaUrl.value  = ''
}

function cancelarCambioFirma() {
  cambiandoFirma.value = false
  firmaBlob.value      = null
  firmaNuevaUrl.value  = ''
}

async function onFotoOrden(e, cual) {
  const file = (e.target.files || [])[0]
  if (!file) return
  subiendoOrden.value = cual
  try {
    const url = await subirFoto(file, cual === 'factura' ? 'facturas' : 'anexos')
    if (url) {
      if (cual === 'factura') facturaFotoUrl.value = url
      else                    anexoFotoUrl.value   = url
    }
  } catch { toast.error('No se pudo subir la foto.') }
  finally { subiendoOrden.value = ''; e.target.value = '' }
}
function quitarNuevaFoto(i) {
  nuevoItem.value.boceto_urls.splice(i, 1)
}

/**
 * ¿Hay un producto elegido en "Agregar producto" que todavía no se sumó a la
 * lista? Es fácil llenarlo y guardar sin presionar "Agregar": el ítem se
 * perdía en silencio, y si además se estaba reemplazando el único ítem de la
 * orden, el backend respondía "La orden debe conservar al menos un ítem" sin
 * que se entendiera por qué.
 */
function hayNuevoSinAgregar() {
  const n = nuevoItem.value
  return !!n && (!!n.producto_id || !!(n.nombre_custom ?? '').trim())
}

/** Devuelve true si el ítem quedó agregado; false si algo faltaba (ya avisado). */
function agregarNuevo() {
  const n = nuevoItem.value
  if (n.es_custom) {
    if (!n.nombre_custom.trim()) { toast.error('Ponle un nombre al diseño especial.'); return false }
  } else if (!n.producto_id) {
    toast.error('Selecciona un producto.'); return false
  }
  if (n.precio_unitario === '' || n.precio_unitario === null || Number(n.precio_unitario) < 0) {
    toast.error('Ingresa el precio del ítem.'); return false
  }

  // Consolidar specs (campos del template + telas elegidas + notas)
  const specs = { ...n.specs }
  for (const key of Object.keys(n._telaSelections ?? {})) {
    const tela = telaResumidaNuevo(key)
    if (tela) specs[key] = tela
  }
  if (n.specs_notas) specs.notas = n.specs_notas
  // El trabajo a realizar es lo que el taller necesita leer de una restauración
  if (n.es_restauracion && n.descripcion_trabajo.trim()) {
    specs.descripcion_trabajo = n.descripcion_trabajo.trim()
  }

  const esPersonalizado = n.es_custom || n.modo === 'personalizado' || n.modo === 'fabricar'

  // Solo se exige variante si alguna tiene existencias: si el stock está a
  // nivel del producto, no hay variante que elegir y se agrega tal cual.
  if (!esPersonalizado && nuevoVarianteObligatoria.value && !n.variante_id) {
    toast.error('Este producto tiene variantes con stock. Elige una antes de agregar.')
    return false
  }
  if (!esPersonalizado && nuevoVCGrupos.value.length && !nuevoVCCompleto.value) {
    toast.error('Este producto tiene variantes. Elige una opción de cada grupo.')
    return false
  }

  // Validar stock solo para ítems de stock (no producción)
  if (!esPersonalizado && n.stock_libre != null && (parseInt(n.cantidad) || 1) > n.stock_libre) {
    toast.error(`Stock insuficiente: hay ${n.stock_libre} disponible(s) en la tienda seleccionada.`)
    return false
  }

  const otraTienda = !esPersonalizado && nuevoTiendaOrigen.value && nuevoTiendaOrigen.value !== props.orden.tienda_id
  itemsNuevos.value.push({
    producto_id:      n.es_custom ? null : n.producto_id,
    variante_id:      !esPersonalizado ? (n.variante_id || null) : null,
    variante_label:   !esPersonalizado ? (n.variante_label || '') : '',
    combo_config_id:  !esPersonalizado ? (n.combo_config_id || null) : null,
    nombre_custom:    n.es_custom ? n.nombre_custom.trim() : null,
    categoria_custom: n.es_custom ? (n.categoria_custom || null) : null,
    producto_nombre:  n.es_custom ? n.nombre_custom.trim() : n.producto_nombre,
    cantidad:         parseInt(n.cantidad) || 1,
    precio_unitario:  parseFloat(n.precio_unitario),
    es_personalizado: esPersonalizado,
    fabricar_pedido:  !n.es_custom && n.modo === 'fabricar',
    es_restauracion:  !!n.es_restauracion,
    tienda_origen_id: otraTienda ? nuevoTiendaOrigen.value : null,
    // La fábrica no está en tiendasLista (/tiendas la filtra), así que su
    // nombre no sale de ahí: sin esto el ítem quedaría sin origen visible.
    tienda_origen_nombre: otraTienda
      ? (nuevoTiendaOrigen.value === fabricaId.value
          ? 'Bodega Fábrica'
          : (tiendasLista.value.find(t => t.id === nuevoTiendaOrigen.value)?.nombre ?? ''))
      : null,
    specs_personalizacion: Object.keys(specs).length ? specs : null,
    boceto_urls:      [...n.boceto_urls],
    _regalo:          !!n._regalo,
    _tipo:            n.es_restauracion ? 'Restauración'
                      : n.es_custom ? 'Diseño especial'
                      : (n.modo === 'fabricar' ? 'Para fabricar'
                      : (n.modo === 'personalizado' ? 'Personalizado' : 'Stock')),
  })
  nuevoItem.value = nuevoItemVacio()
  nuevoQuery.value = ''
  return true
}
function quitarNuevo(idx) {
  itemsNuevos.value.splice(idx, 1)
}

/** Precio unitario con el descuento del ítem, en pesos o en %. */
function precioEfectivo(item) {
  // Un obsequio vale $0 pase lo que pase con el descuento
  if (item._regalo) return 0

  const base  = item.precio_unitario ?? 0
  const valor = Number(item._descuento_valor) || 0
  if (!valor) return base

  const rebaja = (item._descuento_modo ?? 'monto') === 'pct'
    ? Math.round(base * valor / 100)
    : Math.round(valor)

  return Math.max(0, base - rebaja)
}

function descuentoItemMonto(item) {
  return Math.max(0, (item.precio_unitario ?? 0) - precioEfectivo(item))
}

/** Pesos con separador de miles, sin decimales: 1.020.000 */
function pesos(v) {
  return new Intl.NumberFormat('es-CO').format(Math.round(Number(v) || 0))
}

// ── Especificaciones por categoría (mismos templates que al crear la orden) ──
function getTemplate(item) {
  const nombre = item.producto_nombre || item.nombre_custom
  const cat    = item.producto_categoria || item.categoria_custom
  const key = resolverCategoria(nombre, cat)
  return SPECS_TEMPLATES[key] ?? SPECS_TEMPLATES['generico']
}

// Nombres legibles de campos que se guardan pero que ninguna plantilla declara
const ETIQUETAS_SUELTAS = {
  descripcion_trabajo: 'Trabajo a realizar',
  variante_marca:      'Marca de la tela',
  variante_color:      'Color de la tela',
  descripcion:         'Descripción',
  trabajo:             'Trabajo',
}

/**
 * Lo que el ítem tiene guardado y su plantilla no dibuja.
 *
 * La plantilla sale de adivinar la categoría por el nombre, así que puede no
 * coincidir con la de quien lo creó: una restauración de un "MODULAR TORONTO"
 * cae en la plantilla de modulares, que no tiene "Trabajo a realizar", y el
 * texto que escribió el vendedor quedaba guardado pero invisible — no había
 * forma de corregir ni una letra.
 *
 * Se guardaban bien y se mandaban de vuelta al guardar; solo faltaba pintarlos.
 */
function specsSueltas(item) {
  const conocidas = new Set((getTemplate(item).campos ?? []).map(c => c.key))
  return Object.keys(item.specs ?? {})
    .filter(k => ! conocidas.has(k))
    .map(k => ({
      key:   k,
      label: ETIQUETAS_SUELTAS[k] ?? (k.charAt(0).toUpperCase() + k.slice(1).replace(/_/g, ' ')),
    }))
}

// product search per item
const buscando  = ref({})
const resultados = ref({})
const query = ref({})

watch(() => props.show, (v) => {
  if (!v) return
  cargarTelas()
  notas.value          = props.orden.notas ?? ''
  facturaFotoUrl.value = props.orden.factura_foto_url ?? ''
  anexoFotoUrl.value   = props.orden.anexo_foto_url ?? ''
  fechaSugeridaVendedor.value = props.orden.fecha_sugerida_vendedor
    ? String(props.orden.fecha_sugerida_vendedor).substring(0, 10)
    : ''
  canal.value          = props.orden.canal ?? ''
  direccionEnvio.value = props.orden.direccion_envio ?? ''
  ciudadEnvio.value    = props.orden.ciudad_envio ?? ''
  // Number() a propósito: si llega "50.00" —como lo mandaba el backend antes—
  // el campo mostraba "50,00", y nadie escribe medio punto de anticipo.
  anticipoPct.value    = props.orden.anticipo_pct != null ? Number(props.orden.anticipo_pct) : ''
  // El descuento se carga en pesos, tal como está guardado. Derivarlo a % y
  // recalcularlo desde ahí perdía dinero en cada edición.
  descuentoModoEdit.value  = 'monto'
  descuentoInputEdit.value = Number(props.orden.descuento_total || 0)

  descCondModoEdit.value  = 'monto'
  descCondInputEdit.value = Number(props.orden.descuento_condicionado || 0)

  pagoAnticipo.value       = (props.orden.pagos ?? []).find(p => p.tipo === 'anticipo') ?? null
  anticipoMonto.value      = pagoAnticipo.value?.monto ?? ''
  anticipoMetodo.value     = pagoAnticipo.value?.metodo ?? 'efectivo'
  anticipoReferencia.value = pagoAnticipo.value?.referencia ?? ''

  vendedorId.value   = props.orden.vendedor_id ?? null
  tiendaId.value     = props.orden.tienda_id ?? null
  covendedorId.value = props.orden.covendedor_id ?? null
  esCompartida.value = !!props.orden.es_compartida
  tiendaAbonadaId.value = props.orden.tienda_abonada_id ?? null
  if (esSupervisor.value) cargarListasSupervisor()

  itemsEliminar.value  = []
  itemsNuevos.value    = []
  nuevoItem.value      = nuevoItemVacio()
  nuevoTiendaOrigen.value = props.orden.tienda_id ?? null
  cargarTiendas()
  nuevoQuery.value     = ''
  items.value = (props.orden.items ?? []).map(item => {
    // Se preserva TAL CUAL el objeto de specs original (sea cual sea su
    // categoría/esquema) para no perder campos que este formulario no conoce.
    const specsRaw = { ...(item.specs_personalizacion || {}) }
    const notasPrevias = specsRaw.notas || ''
    delete specsRaw.notas
    return {
      id: item.id,
      es_personalizado: item.es_personalizado,
      producto_id: item.producto?.id ?? item.producto_id,
      // Un diseño especial no tiene producto de catálogo: su nombre vive en
      // nombre_custom. Sin esto se veía en blanco en toda la pantalla y la
      // plantilla de specs se resolvía con el nombre vacío.
      producto_nombre: item.producto?.nombre ?? item.nombre_custom ?? '',
      nombre_custom: item.nombre_custom ?? '',
      producto_categoria: item.producto?.categoria ?? null,
      categoria_custom: item.categoria_custom ?? null,
      // catalogo | personalizado | fabricar | diseno_especial | restauracion
      _tipo_item: item.tipo_item,
      cantidad: item.cantidad,
      precio_unitario: item.precio_unitario,
      _descuento_modo: 'monto',
      _descuento_valor: 0,
      // "Obsequio" no se guarda en la base: es un ítem en $0. Al abrir se
      // deduce del precio, que es la única señal que hay. Se excluyen las
      // órdenes que esperan precio, donde el 0 significa "todavía sin cotizar",
      // no cortesía.
      // Ya viene guardado: antes se adivinaba por el precio, y en $0 esta
      // tanto el obsequio como lo que espera cotizacion.
      _regalo: item.es_regalo ?? (Number(item.precio_unitario) === 0
               && props.orden.estado !== 'pendiente_cotizacion'),      // Se dejó activado "consultar costo" y la orden quedó esperando al
      // supervisor. Se marca al abrir, no sobre el precio que se está
      // escribiendo, para que el aviso no desaparezca al teclear el primer dígito.
      _esperaCosto: props.orden.estado === 'pendiente_cotizacion'
                    && item.es_personalizado
                    && Number(item.precio_unitario) === 0,
      fecha_entrega_prom: item.fecha_entrega_prom
        ? String(item.fecha_entrega_prom).substring(0, 10)
        : '',
      _produccion: item.produccion ?? null,
      specs: specsRaw,
      specs_notas: notasPrevias,
      _telaSelections: {},
      // Copia propia: se edita aquí y solo se manda al guardar
      boceto_urls: [...(item.bocetos_list ?? [])],
      _bocetosOriginales: [...(item.bocetos_list ?? [])],
      _subiendoBoceto: false,
    }
  })
  query.value = {}
  resultados.value = {}
  buscando.value = {}
})

// ── Inventario de telas (compartido con nueva orden y completar borrador) ────
const { cargarTelas } = useTelas()

// ── Selección de tela nueva por campo (marca → tipo → color) ────────────────
function getTelaSelection(item, key) {
  if (!item._telaSelections[key]) item._telaSelections[key] = { marca: '', tipo: '', color: '' }
  return item._telaSelections[key]
}
function telaResumidaCampo(item, key) {
  const s = item._telaSelections?.[key]
  if (!s?.marca || !s?.tipo || !s?.color) return ''
  return [s.marca, s.tipo, s.color].join(' · ')
}

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

// Reemplazar un ítem de stock por su versión personalizada / diseño especial:
// marca el viejo para eliminar y precarga el constructor de ítem nuevo con el
// mismo producto en modo personalizado (o como diseño especial si no es de catálogo).
function reemplazarPorPersonalizado(item) {
  marcarEliminar(item)
  if (!itemsEliminar.value.includes(item.id)) return  // no se pudo (ya en producción)
  nuevoItem.value = nuevoItemVacio()
  if (item.producto_id) {
    nuevoItem.value.producto_id        = item.producto_id
    nuevoItem.value.producto_nombre    = item.producto_nombre
    nuevoItem.value.producto_categoria = item.producto_categoria
    nuevoItem.value.personalizable     = true
    nuevoItem.value.modo               = 'personalizado'
    nuevoItem.value.precio_unitario    = item.precio_unitario
  } else {
    nuevoItem.value.es_custom       = true
    nuevoItem.value.nombre_custom   = item.producto_nombre
    nuevoItem.value.categoria_custom = item.categoria_custom || ''
    nuevoItem.value.precio_unitario = item.precio_unitario
  }
  toast.success('Ítem marcado para reemplazo. Ajusta la personalización en "Agregar producto" y agrégalo.')
}

// El camino inverso, y el error más común del vendedor: no vio el producto en
// el inventario y lo mandó como "para fabricar" o personalizado cuando sí
// había en stock. Esto lo devuelve a ítem de catálogo.
//
// Marcarlo para eliminar es lo que lo saca de producción: al guardar, el
// backend le borra el registro de producción y sus pasos, así que la fábrica
// deja de verlo y nadie lo construye. Si el taller ya lo empezó, marcarEliminar
// no deja seguir — ahí ya hay trabajo hecho y esto se arregla hablando.
async function reemplazarPorStock(item) {
  marcarEliminar(item)
  if (!itemsEliminar.value.includes(item.id)) return  // ya está en producción

  nuevoItem.value = nuevoItemVacio()
  nuevoItem.value.modo     = 'stock'
  nuevoItem.value.cantidad = item.cantidad
  // Se conserva el precio pactado: el cliente ya aceptó ese valor, y esto es
  // corregir de dónde sale el mueble, no volver a negociar.
  nuevoItem.value.precio_unitario = item.precio_unitario

  if (item.producto_id) {
    nuevoItem.value.producto_id        = item.producto_id
    nuevoItem.value.producto_nombre    = item.producto_nombre
    nuevoItem.value.producto_categoria = item.producto_categoria
    // Trae el stock real y las variantes de la tienda: sin esto no se vería
    // si de verdad hay disponible, que es justo lo que hay que comprobar.
    await refrescarStockNuevo()
    toast.success('Listo: ahora sale de inventario y se quita de producción. Revisa el stock abajo y agrégalo.')
  } else {
    // Un diseño especial no tiene producto de catálogo al que volver.
    toast.success('Marcado. Busca abajo el producto del catálogo y agrégalo.')
  }
}

// ── Guardar ──────────────────────────────────────────────────────────────────
async function guardar() {
  // Si quedó un producto elegido sin presionar "Agregar", se agrega solo:
  // antes se perdía en silencio y, al estar reemplazando el único ítem de la
  // orden, el guardado fallaba con "debe conservar al menos un ítem" sin que
  // se entendiera que el reemplazo nunca se había sumado.
  if (hayNuevoSinAgregar() && !agregarNuevo()) return

  if (itemsNuevos.value.some(i => (!i.producto_id && !i.nombre_custom) || i.precio_unitario === '' || i.precio_unitario == null)) {
    toast.error('Completa todos los campos de los ítems nuevos antes de guardar.')
    return
  }

  // Se avisa acá, con el porqué, en vez de dejar que el backend responda un
  // mensaje que no dice qué falta hacer.
  const quedan = items.value.filter(i => !itemsEliminar.value.includes(i.id)).length
  if (quedan === 0 && itemsNuevos.value.length === 0) {
    toast.error('Quitaste todos los ítems. Agrega el reemplazo antes de guardar.')
    return
  }
  if (pagoAnticipo.value) {
    const montoNum = parseFloat(anticipoMonto.value)
    if (!montoNum || montoNum <= 0) {
      toast.error('El monto del anticipo debe ser mayor a 0.')
      return
    }
  }
  if (cambiandoFirma.value && !firmaBlob.value) {
    toast.error('Falta que el cliente firme, o cancela el cambio de firma.')
    return
  }

  // La firma se dibuja en el momento y se sube recién aquí, al guardar.
  // Va sin comprimir: es un PNG con fondo transparente y pasarlo por el
  // compresor a JPEG le mete fondo negro y le come el trazo.
  if (firmaBlob.value) {
    try {
      const token = localStorage.getItem('token')
      const fd = new FormData()
      fd.append('foto', firmaBlob.value, 'firma.png')
      fd.append('folder', 'firmas')
      const res  = await fetch('/api/upload/foto', {
        method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: fd,
      })
      const data = await res.json()
      firmaNuevaUrl.value = data.url ?? ''
    } catch {
      toast.error('No se pudo guardar la firma.')
      return
    }
    if (!firmaNuevaUrl.value) {
      toast.error('No se pudo guardar la firma.')
      return
    }
  }

  guardando.value = true
  try {
    // Si el anticipo cambió, se corrige primero para que la orden quede
    // con los pagos ya actualizados al recargarse.
    if (pagoAnticipo.value) {
      const montoNum = parseFloat(anticipoMonto.value)
      const cambioAnticipo =
        montoNum !== parseFloat(pagoAnticipo.value.monto) ||
        anticipoMetodo.value !== pagoAnticipo.value.metodo ||
        (anticipoReferencia.value || null) !== (pagoAnticipo.value.referencia || null)

      if (cambioAnticipo) {
        await editarPago(pagoAnticipo.value.id, {
          monto:      montoNum,
          metodo:     anticipoMetodo.value,
          referencia: anticipoReferencia.value || null,
        })
      }
    }

    const payload = {
      notas:           notas.value,
      canal:           canal.value,
      direccion_envio: direccionEnvio.value || null,
      ciudad_envio:    ciudadEnvio.value    || null,
      // Con la orden ya entregada esto no viaja: no es que el servidor lo
      // ignore, es que lo rechaza entero, así que mandarlo sin querer dejaría
      // sin guardar también la foto que sí se venía a cambiar.
      ...(props.soloPapeles ? {} : {
        anticipo_pct:    anticipoPct.value !== '' && anticipoPct.value !== null ? Number(anticipoPct.value) : undefined,
        descuento_total: Number(descuentoTotalEdit.value) || 0,
        descuento_condicionado_monto: Number(descCondEdit.value) || 0,
      }),
      fecha_sugerida_vendedor: fechaSugeridaVendedor.value || null,
      // null explícito para poder QUITAR una foto, no solo reemplazarla
      ...(facturaFotoUrl.value !== (props.orden.factura_foto_url ?? '')
          ? { factura_foto_url: facturaFotoUrl.value || null } : {}),
      ...(anexoFotoUrl.value !== (props.orden.anexo_foto_url ?? '')
          ? { anexo_foto_url: anexoFotoUrl.value || null } : {}),
      ...(firmaNuevaUrl.value ? { firma_url: firmaNuevaUrl.value } : {}),
      items: items.value
        .filter(item => !itemsEliminar.value.includes(item.id))
        .map(item => {
          // De una orden entregada sólo viaja lo que describe la pieza: las
          // specs y los bocetos que ve el taller. El precio y la cantidad ya
          // están cobrados.
          const out = props.soloPapeles
            ? { id: item.id }
            : {
                id:               item.id,
                precio_unitario:  precioEfectivo(item),
                fecha_entrega_prom: item.fecha_entrega_prom || null,
                es_regalo:        !!item._regalo,
              }
          if (item.es_personalizado) {
            const s = { ...item.specs }
            for (const key of Object.keys(item._telaSelections ?? {})) {
              const tela = telaResumidaCampo(item, key)
              if (tela) s[key] = tela
            }
            if (item.specs_notas) s.notas = item.specs_notas
            out.specs_personalizacion = s

            // Solo si de verdad cambiaron: mandarlos siempre ensuciaría el
            // historial de ediciones con un "bocetos" en cada guardado.
            const antes = item._bocetosOriginales ?? []
            const ahora = item.boceto_urls ?? []
            if (JSON.stringify(antes) !== JSON.stringify(ahora)) {
              out.boceto_urls = ahora
            }
          } else if (! props.soloPapeles) {
            out.cantidad    = parseInt(item.cantidad)
            out.producto_id = item.producto_id
          }
          return out
        }),
      items_eliminar: (! props.soloPapeles && itemsEliminar.value.length) ? itemsEliminar.value : undefined,
      items_nuevos: (! props.soloPapeles && itemsNuevos.value.length)
        ? itemsNuevos.value.map(i => ({
            producto_id:      i.producto_id ?? undefined,
            variante_id:      i.variante_id ?? undefined,
            // La variante elegida, igual que al crear la orden: agregando un
            // ítem desde aquí se perdía qué medida o qué tela era.
            combo_config_id:  i.combo_config_id ?? undefined,
            variante_detalle: i.variante_label || undefined,
            nombre_custom:    i.nombre_custom ?? undefined,
            categoria_custom: i.categoria_custom ?? undefined,
            tienda_origen_id: i.tienda_origen_id ?? undefined,
            cantidad:         parseInt(i.cantidad) || 1,
            precio_unitario:  parseFloat(i.precio_unitario),
            es_personalizado: i.es_personalizado || undefined,
            fabricar_pedido:  i.fabricar_pedido || undefined,
            es_restauracion:  i.es_restauracion || undefined,
            es_regalo:        i._regalo || undefined,
            specs_personalizacion: i.specs_personalizacion ?? undefined,
            boceto_urls:      i.boceto_urls?.length ? i.boceto_urls : undefined,
          }))
        : undefined,
    }

    if (esSupervisor.value) {
      if (vendedorId.value !== (props.orden.vendedor_id ?? null)) payload.vendedor_id = vendedorId.value
      if (tiendaId.value !== (props.orden.tienda_id ?? null)) payload.tienda_id = tiendaId.value
      if (covendedorId.value !== (props.orden.covendedor_id ?? null)) payload.covendedor_id = covendedorId.value
      if (esCompartida.value !== !!props.orden.es_compartida) payload.es_compartida = esCompartida.value
      if ((tiendaAbonadaId.value ?? null) !== (props.orden.tienda_abonada_id ?? null))
        payload.tienda_abonada_id = tiendaAbonadaId.value || null
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
              <h3 class="font-bold text-gray-900">Editar orden {{ orden.referencia ?? `#${orden.numero_orden ?? orden.id}` }}</h3>
              <p class="text-xs text-gray-500 mt-0.5">Los cambios quedan registrados con tu nombre</p>
            </div>
            <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
              <XMarkIcon class="w-5 h-5 text-gray-500" />
            </button>
          </div>

          <div class="p-5 space-y-5 overflow-y-auto">
            <!-- Por qué esta orden se edita a medias -->
            <div v-if="soloPapeles" class="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
              <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5" />
              <div class="min-w-0">
                <p class="text-xs font-semibold text-amber-800">Esta orden ya salió</p>
                <p class="text-xs text-amber-700 mt-0.5 leading-snug">
                  Se pueden corregir las fotos, las notas, la dirección y lo que describe
                  cada pieza. El precio, la cantidad y los productos no: eso ya se cobró y
                  ya descontó bodega. Para cambiar un producto entregado hay un botón
                  aparte en el detalle de la orden.
                </p>
              </div>
            </div>

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
                <label class="block text-xs font-medium text-gray-600 mb-1">Fecha prometida al cliente</label>
                <input
                  v-model="fechaSugeridaVendedor"
                  type="date"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <p class="text-[11px] text-gray-500 mt-1">
                  Lo que se le dijo al cliente. No es la fecha de entrega — esa se asigna por ítem.
                </p>
              </div>
              <!-- Fotos de la orden -->
              <p class="text-xs font-semibold text-gray-500 uppercase pt-1">Comprobante, anexo y firma</p>
              <p class="text-[11px] text-gray-500 -mt-1.5">
                Se pueden cambiar todas. Toca la foto para verla en grande.
              </p>
              <div class="grid grid-cols-2 gap-3">
                <div v-for="f in [
                    { k: 'factura', url: facturaFotoUrl, label: 'Foto de la factura' },
                    { k: 'anexo',   url: anexoFotoUrl,   label: 'Foto anexa' },
                  ]" :key="f.k">
                  <label class="block text-xs font-medium text-gray-600 mb-1">{{ f.label }}</label>

                  <div v-if="f.url" class="relative">
                    <a :href="f.url" target="_blank" rel="noopener">
                      <img :src="cloudinaryOpt(f.url, 400)" class="w-full h-24 object-cover rounded-lg border border-gray-200" />
                    </a>
                    <button
                      type="button"
                      @click="f.k === 'factura' ? facturaFotoUrl = '' : anexoFotoUrl = ''"
                      class="absolute -top-1.5 -right-1.5 bg-white rounded-full shadow p-0.5 text-red-500 hover:text-red-700"
                      title="Quitar"
                    >
                      <XMarkIcon class="w-3.5 h-3.5" />
                    </button>
                    <!-- Botón de verdad, no un enlace de 11px: en el móvil el
                         anterior era casi imposible de acertar y parecía que no
                         se podía cambiar. -->
                    <label class="mt-1.5 flex items-center justify-center gap-1.5 py-2 rounded-lg border border-blue-200 bg-blue-50 text-xs font-semibold text-blue-700 cursor-pointer hover:bg-blue-100 transition-colors">
                      <PhotoIcon class="w-4 h-4" /> Cambiar foto
                      <input type="file" accept="image/*" class="hidden" @change="onFotoOrden($event, f.k)" />
                    </label>
                  </div>

                  <label
                    v-else
                    class="h-24 flex flex-col items-center justify-center gap-1 border-2 border-dashed border-gray-300 rounded-lg text-gray-400 cursor-pointer hover:border-blue-400 hover:text-blue-500 transition-colors"
                  >
                    <PhotoIcon class="w-5 h-5" />
                    <span class="text-[11px] font-semibold">Subir foto</span>
                    <input type="file" accept="image/*" class="hidden" @change="onFotoOrden($event, f.k)" />
                  </label>

                  <p v-if="subiendoOrden === f.k" class="text-[11px] text-gray-400 mt-1 flex items-center gap-1.5">
                    <IconoS class="w-3 h-3" /> Subiendo...
                  </p>
                </div>
              </div>

              <!-- Firma del cliente -->
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Firma del cliente</label>

                <template v-if="!cambiandoFirma">
                  <div v-if="props.orden.firma_url" class="flex items-center gap-3">
                    <a :href="props.orden.firma_url" target="_blank" rel="noopener" class="flex-shrink-0">
                      <img
                        :src="cloudinaryOpt(props.orden.firma_url, 300)"
                        class="h-16 w-32 object-contain rounded-lg border border-gray-200 bg-white"
                      />
                    </a>
                    <button
                      type="button"
                      @click="abrirCambioFirma"
                      class="flex items-center gap-1.5 px-3 py-2 rounded-lg border border-blue-200 bg-blue-50 text-xs font-semibold text-blue-700 hover:bg-blue-100 transition-colors"
                    ><PencilSquareIcon class="w-4 h-4" /> Volver a firmar</button>
                  </div>
                  <div v-else class="flex items-center gap-3">
                    <p class="text-xs text-amber-600">Esta orden no tiene firma.</p>
                    <button
                      type="button"
                      @click="abrirCambioFirma"
                      class="text-xs font-semibold text-blue-600 hover:underline"
                    >Tomar firma</button>
                  </div>
                </template>

                <div v-else class="space-y-2">
                  <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5">
                    La firma es la constancia de que el cliente aceptó la orden.
                    Debe firmar él, no se puede dejar en blanco, y el cambio queda
                    registrado con tu nombre.
                  </p>
                  <FirmaCanvas v-model="firmaBlob" />
                  <button
                    type="button"
                    @click="cancelarCambioFirma"
                    class="text-xs text-gray-500 hover:underline"
                  >Cancelar y dejar la firma anterior</button>
                </div>
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
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">% de anticipo sugerido</label>
                <input
                  v-model="anticipoPct"
                  type="number"
                  min="1"
                  max="100"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
            </div>

            <!-- Anticipo -->
            <div v-if="pagoAnticipo && !soloPapeles" class="space-y-3 border border-amber-200 bg-amber-50 rounded-xl p-4">
              <p class="text-xs font-semibold text-amber-700 uppercase">Anticipo</p>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Monto</label>
                  <InputPesos
                    v-model="anticipoMonto"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Método</label>
                  <select
                    v-model="anticipoMetodo"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="efectivo">Efectivo</option>
                    <option value="transferencia">Transferencia</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="otro">Otro</option>
                  </select>
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Referencia</label>
                <input
                  v-model="anticipoReferencia"
                  type="text"
                  placeholder="N.° de referencia o comprobante..."
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <p class="text-[11px] text-amber-700">Corrige aquí si el anticipo se registró mal. El cambio queda en el historial de la orden.</p>
            </div>

            <!-- Reasignación (solo supervisor) -->
            <div v-if="esSupervisor && !soloPapeles" class="space-y-3 border border-blue-200 bg-blue-50 rounded-xl p-4">
              <p class="text-xs font-semibold text-blue-700 uppercase">Reasignar</p>
              <p class="text-[11px] text-blue-700">Cambiar el vendedor o la tienda afecta el cálculo de comisiones de esta orden.</p>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Vendedor</label>
                <select
                  v-model.number="vendedorId"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option v-for="v in opcionesVendedor" :key="v.id" :value="v.id">{{ v.nombre }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Tienda</label>
                <select
                  v-model.number="tiendaId"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option v-for="t in tiendasLista" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                </select>
              </div>
              <label class="flex items-center gap-2 text-xs text-gray-600">
                <input type="checkbox" v-model="esCompartida" />
                Venta compartida
              </label>

              <!-- Media venta abonada a una tienda: solo si el vendedor es
                   independiente, que es quien cierra con contactos de almacen -->
              <div v-if="vendedorEsIndependiente">
                <label class="block text-xs font-medium text-gray-600 mb-1">
                  Venta compartida con un almacén
                </label>
                <select
                  v-model.number="tiendaAbonadaId"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                  <option :value="null">Sin compartir — la venta entera es del vendedor</option>
                  <option v-for="t in tiendasAbonables" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                </select>
              </div>
              <div v-if="esCompartida">
                <label class="block text-xs font-medium text-gray-600 mb-1">Co-vendedor</label>
                <select
                  v-model.number="covendedorId"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option :value="null">Sin co-vendedor</option>
                  <option v-for="v in opcionesVendedor" :key="v.id" :value="v.id">{{ v.nombre }}</option>
                </select>
              </div>
            </div>

            <!-- Ítems -->
            <div
              v-for="item in items"
              :key="item.id"
              :class="['border rounded-xl p-4 space-y-3 transition-all', itemsEliminar.includes(item.id) ? 'border-red-300 bg-red-50 opacity-60' : 'border-gray-200']"
            >
              <div class="flex items-center gap-2">
                <SparklesIcon v-if="item.es_personalizado" class="w-4 h-4 text-purple-500 flex-shrink-0" />
                <p class="font-medium text-sm text-gray-800 truncate flex-1">{{ item.producto_nombre }}</p>
                <!-- Botón quitar ítem -->
                <button
                  v-if="!itemsEliminar.includes(item.id) && !soloPapeles"
                  type="button"
                  @click="marcarEliminar(item)"
                  class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors flex-shrink-0"
                  title="Quitar ítem de la orden"
                >
                  <TrashIcon class="w-4 h-4" />
                </button>
                <button
                  v-else
                  type="button"
                  @click="desmarcarEliminar(item.id)"
                  class="text-xs text-red-600 font-semibold hover:underline flex-shrink-0"
                >
                  Deshacer
                </button>
              </div>
              <!-- Aviso si marcado para eliminar -->
              <p v-if="itemsEliminar.includes(item.id)" class="text-xs text-red-600 font-medium">
                Este ítem se eliminará al guardar
              </p>

              <!-- Campos de edición (ocultos si marcado para eliminar) -->
              <template v-if="!itemsEliminar.includes(item.id)">

              <!-- Se dejó "consultar costo" activado y la orden quedó esperando -->
              <div
                v-if="item._esperaCosto"
                class="rounded-lg p-2.5 text-xs border"
                :class="Number(item.precio_unitario) > 0
                  ? 'bg-green-50 border-green-200 text-green-800'
                  : 'bg-amber-50 border-amber-200 text-amber-800'"
              >
                <template v-if="Number(item.precio_unitario) > 0">
                  <p class="font-semibold">Ya no hay que consultarlo</p>
                  <p class="mt-0.5">
                    Al guardar se le quita de la lista al supervisor.
                  </p>
                </template>
                <template v-else>
                  <p class="font-semibold">Esperando el costo del supervisor</p>
                  <p class="mt-0.5">
                    Si ya sabes cuánto vale, escríbelo aquí abajo y la orden sigue
                    su curso sin esperar.
                  </p>
                </template>
              </div>

              <!-- Precio + fecha -->
              <div v-if="!soloPapeles" class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Precio unitario</label>
                  <InputPesos
                    v-model="item.precio_unitario"
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

              <!-- Obsequiar este ítem. Queda en $0 pero se entrega igual, así
                   que sigue descontando del inventario. -->
              <label v-if="!soloPapeles" class="flex items-center gap-2 cursor-pointer select-none">
                <button
                  type="button"
                  @click="toggleRegaloItem(item)"
                  :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0', item._regalo ? 'bg-pink-500' : 'bg-gray-300']"
                >
                  <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', item._regalo ? 'translate-x-5' : 'translate-x-0.5']" />
                </button>
                <span class="text-sm text-gray-600 flex items-center gap-1">
                  <GiftIcon class="w-4 h-4 text-pink-500" /> Obsequiar
                </span>
                <span v-if="item._regalo" class="text-xs font-semibold text-pink-700 bg-pink-50 border border-pink-200 rounded-full px-2 py-0.5">
                  $0 · se entrega igual
                </span>
              </label>

              <!-- Descuento — en pesos o en %. No aplica a un obsequio. -->
              <div v-if="!item._regalo && !soloPapeles" class="flex items-center gap-2 flex-wrap">
                <label class="text-xs text-gray-500 flex-shrink-0">Descuento c/u</label>

                <div class="flex items-center gap-1">
                  <button
                    v-for="m in [{ v: 'monto', t: '$' }, { v: 'pct', t: '%' }]"
                    :key="'im' + m.v" type="button"
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
                  class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <input
                  v-else
                  v-model.number="item._descuento_valor"
                  type="number"
                  min="0"
                  :max="(item._descuento_modo ?? 'monto') === 'pct' ? 99 : item.precio_unitario"
                  step="1"
                  placeholder="0"
                  class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                />

                <!-- En cuánto estaba y en cuánto queda: lo justo para confirmar
                     que el descuento entró bien -->
                <div
                  v-if="descuentoItemMonto(item) > 0"
                  class="text-xs bg-green-50 px-2 py-1 rounded-lg flex items-baseline gap-1.5 flex-shrink-0"
                >
                  <span class="text-gray-400 line-through">${{ pesos(item.precio_unitario) }}</span>
                  <span class="text-gray-400">→</span>
                  <span class="text-green-700 font-bold">${{ pesos(precioEfectivo(item)) }}</span>
                </div>
              </div>

              <!-- No personalizado: producto + cantidad -->
              <template v-if="!item.es_personalizado && !soloPapeles">
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

                <!-- Reemplazar por personalizado / diseño especial -->
                <button
                  type="button"
                  @click="reemplazarPorPersonalizado(item)"
                  class="w-full text-xs text-purple-600 font-medium flex items-center justify-center gap-1 py-1.5 border border-purple-200 rounded-lg hover:bg-purple-50"
                >
                  <SparklesIcon class="w-3.5 h-3.5" /> Reemplazar por personalizado
                </button>
              </template>

              <!-- Personalizado: specs (según categoría del producto) -->
              <template v-else>
                <!-- Volver a inventario. El caso típico: el vendedor no vio el
                     producto en stock y lo mandó a fabricar sin necesidad. No
                     se ofrece en restauraciones, que son un mueble del cliente
                     y no tienen equivalente en catálogo. -->
                <div
                  v-if="!['restauracion', 'producto_unico'].includes(item._tipo_item) && !itemsEliminar.includes(item.id)"
                  class="bg-emerald-50 border border-emerald-200 rounded-lg p-2.5 space-y-1.5"
                >
                  <p class="text-[11px] text-emerald-800 leading-snug">
                    <span class="font-semibold">¿Sí había en inventario?</span>
                    Pásalo a producto de catálogo: se saca de producción para que en la fábrica no lo hagan,
                    y se reserva del stock.
                  </p>
                  <button
                    type="button"
                    @click="reemplazarPorStock(item)"
                    class="w-full text-xs text-emerald-700 font-semibold flex items-center justify-center gap-1 py-1.5 bg-white border border-emerald-300 rounded-lg hover:bg-emerald-100 transition-colors"
                  >
                    <BuildingStorefrontIcon class="w-3.5 h-3.5" /> Pasarlo a inventario
                  </button>
                </div>

                <!-- El mueble único no lleva especificaciones: son las que
                     tiene el mueble que está en el local, no algo que se le
                     pida a nadie. Pedirlas invita a llenar campos que no
                     va a leer ningún taller. -->
                <div v-if="item._tipo_item !== 'producto_unico'" class="space-y-3 pt-1 border-t border-purple-100">
                  <p class="text-xs font-medium text-purple-600">Especificaciones — {{ getTemplate(item).titulo }}</p>

                  <div class="grid grid-cols-2 gap-3">
                    <template v-for="campo in getTemplate(item).campos" :key="campo.key">
                      <div :class="campo.type === 'text' || campo.useVariantes ? 'col-span-2' : ''">
                        <label class="block text-xs font-medium text-gray-600 mb-1">
                          {{ campo.label }}{{ campo.unit ? ' (' + campo.unit + ')' : '' }}
                        </label>

                        <!-- Tela: mismo selector que en nueva orden y al completar -->
                        <TelaPicker
                          v-if="campo.useVariantes"
                          :seleccion="getTelaSelection(item, campo.key)"
                          :actual="item.specs[campo.key] || ''"
                          etiqueta="Nueva selección"
                        />

                        <!-- Select normal -->
                        <select
                          v-else-if="campo.type === 'select'"
                          v-model="item.specs[campo.key]"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
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
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                      </div>
                    </template>
                  </div>

                  <!-- Lo que se guardó pero la plantilla de esta categoría no
                       dibuja: sin esto quedaba escrito e imposible de corregir -->
                  <div v-for="extra in specsSueltas(item)" :key="extra.key">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ extra.label }}</label>
                    <textarea
                      v-model="item.specs[extra.key]"
                      rows="2"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                    />
                  </div>

                  <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notas adicionales</label>
                    <textarea
                      v-model="item.specs_notas"
                      rows="2"
                      placeholder="Detalles adicionales de personalización..."
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                    />
                  </div>

                  <!-- Bocetos del ítem: los que ve el taller -->
                  <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">
                      Bocetos y fotos
                      <span class="font-normal text-gray-400">({{ item.boceto_urls.length }}/10)</span>
                    </label>
                    <div class="flex flex-wrap gap-2">
                      <div v-for="(url, bi) in item.boceto_urls" :key="bi" class="relative w-16 h-16">
                        <a :href="url" target="_blank" rel="noopener">
                          <img :src="cloudinaryOpt(url, 200)" class="w-16 h-16 object-cover rounded-lg border border-gray-200" />
                        </a>
                        <button
                          type="button"
                          @click="quitarBocetoItem(item, bi)"
                          class="absolute -top-1.5 -right-1.5 bg-white rounded-full shadow p-0.5 text-red-500 hover:text-red-700"
                          title="Quitar"
                        >
                          <XMarkIcon class="w-3.5 h-3.5" />
                        </button>
                      </div>

                      <label
                        v-if="item.boceto_urls.length < 10"
                        class="w-16 h-16 flex flex-col items-center justify-center gap-0.5 border-2 border-dashed border-gray-300 rounded-lg text-gray-400 cursor-pointer hover:border-blue-400 hover:text-blue-500 transition-colors"
                      >
                        <PhotoIcon class="w-5 h-5" />
                        <span class="text-[10px]">Agregar</span>
                        <input type="file" accept="image/*" multiple class="hidden" @change="onBocetoItem($event, item)" />
                      </label>
                    </div>
                    <p v-if="item._subiendoBoceto" class="text-[11px] text-gray-400 mt-1 flex items-center gap-1.5">
                      <IconoS class="w-3 h-3" /> Subiendo...
                    </p>
                    <p v-else-if="!item.boceto_urls.length" class="text-[11px] text-gray-400 mt-1">
                      Sin bocetos. El taller trabaja con lo que haya aquí y con las especificaciones.
                    </p>
                  </div>
                </div>
              </template>

              </template><!-- /v-if !itemsEliminar -->
            </div>

            <!-- Agregar ítem nuevo -->
            <div v-if="!soloPapeles" class="border-2 border-dashed border-blue-200 rounded-xl p-4 space-y-3 bg-blue-50/40">
              <p class="text-xs font-semibold text-blue-700 uppercase flex items-center gap-1.5">
                <PlusIcon class="w-3.5 h-3.5" /> Agregar producto a la orden
              </p>

              <!-- Tienda de la que se busca/saca el stock -->
              <div v-if="tiendasLista.length">
                <label class="block text-[11px] text-gray-500 mb-1">Buscar / sacar stock de</label>
                <select v-model.number="nuevoTiendaOrigen"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option v-for="t in tiendasOrigen" :key="t.id" :value="t.id">
                    {{ t.id === orden.tienda_id ? t.nombre + ' (tienda de la orden)' : t.nombre }}
                  </option>
                  <option v-if="fabricaId" :value="fabricaId">Bodega Fábrica (Reserva)</option>
                </select>
                <p v-if="fabricaId && nuevoTiendaOrigen === fabricaId" class="mt-1 text-xs text-purple-600 font-medium">
                  Consultando la reserva de fábrica — el producto sale de allá directo al cliente.
                </p>
              </div>

              <!-- Ítems nuevos ya agregados -->
              <div v-for="(ni, idx) in itemsNuevos" :key="idx" class="flex items-center gap-2 bg-white border border-blue-200 rounded-lg px-3 py-2">
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 truncate">
                    {{ ni.producto_nombre }}
                    <span v-if="ni._tipo && ni._tipo !== 'Stock'" class="ml-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full"
                      :class="{
                        'bg-purple-100 text-purple-700': ni._tipo === 'Personalizado',
                        'bg-indigo-100 text-indigo-700': ni._tipo === 'Diseño especial',
                        'bg-amber-100 text-amber-700':   ni._tipo === 'Para fabricar',
                      }">{{ ni._tipo }}</span>
                  </p>
                  <p v-if="ni.variante_label" class="text-[11px] text-purple-600">{{ ni.variante_label }}</p>
                  <p class="text-xs text-gray-500">
                    × {{ ni.cantidad }} —
                    <span v-if="ni._regalo" class="text-pink-600 font-semibold">Obsequio</span>
                    <span v-else>${{ Number(ni.precio_unitario).toLocaleString('es-CO') }}</span>
                    <span v-if="ni.tienda_origen_nombre" class="text-amber-600"> · desde {{ ni.tienda_origen_nombre }}</span>
                    <span v-if="ni.boceto_urls?.length" class="text-gray-400"> · {{ ni.boceto_urls.length }} foto(s)</span>
                  </p>
                </div>
                <button type="button" @click="quitarNuevo(idx)" class="p-1 text-red-400 hover:text-red-600 flex-shrink-0">
                  <XMarkIcon class="w-4 h-4" />
                </button>
              </div>

              <!-- Paso 1: buscar producto o crear diseño especial -->
              <template v-if="!nuevoItem.producto_id && !nuevoItem.es_custom">
                <div class="relative">
                  <div class="flex items-center gap-2 bg-white border border-gray-300 rounded-lg px-3 py-2">
                    <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 flex-shrink-0" />
                    <input
                      :value="nuevoQuery"
                      @input="onBuscarNuevo($event.target.value)"
                      type="text"
                      placeholder="Buscar producto del catálogo..."
                      class="flex-1 text-sm outline-none bg-transparent"
                    />
                    <span v-if="nuevoBuscando" class="text-xs text-gray-400">Buscando...</span>
                  </div>
                  <div
                    v-if="nuevoResultados.length"
                    class="absolute z-20 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg max-h-44 overflow-y-auto"
                  >
                    <button
                      v-for="prod in nuevoResultados"
                      :key="prod.id"
                      type="button"
                      @mousedown.prevent="seleccionarNuevo(prod)"
                      class="w-full text-left px-4 py-2.5 hover:bg-blue-50 transition-colors border-b border-gray-50 last:border-0"
                    >
                      <p class="text-sm font-medium text-gray-800">{{ prod.nombre }}</p>
                      <p class="text-xs text-gray-400">{{ prod.categoria }}</p>
                    </button>
                  </div>
                </div>
                <button type="button" @click="iniciarDisenoEspecial"
                  class="w-full text-xs text-indigo-600 font-medium flex items-center justify-center gap-1 py-1.5 border border-indigo-200 rounded-lg hover:bg-indigo-50">
                  <SparklesIcon class="w-3.5 h-3.5" /> Crear diseño especial (fuera de catálogo)
                </button>
                <button type="button" @click="iniciarRestauracion"
                  class="w-full text-xs text-indigo-700 font-medium flex items-center justify-center gap-1 py-1.5 border border-indigo-200 rounded-lg hover:bg-indigo-50">
                  🛠️ Mueble del cliente para restaurar
                </button>
              </template>

              <!-- Paso 2: constructor del ítem -->
              <div v-else class="space-y-2.5">
                <div class="flex items-center justify-between">
                  <p class="text-xs font-semibold text-blue-700 truncate">
                    {{ nuevoItem.es_restauracion ? '🛠️ Mueble a restaurar'
                       : nuevoItem.es_custom ? 'Diseño especial'
                       : nuevoItem.producto_nombre }}
                  </p>
                  <button type="button" @click="nuevoItem = nuevoItemVacio()" class="text-[11px] text-gray-400 underline">Cambiar</button>
                </div>

                <!-- Mueble a restaurar: nombre + trabajo a realizar -->
                <template v-if="nuevoItem.es_restauracion">
                  <p class="text-[11px] text-indigo-600 -mb-1">
                    Lo trae el cliente. No descuenta inventario y va directo a producción.
                  </p>
                  <input v-model="nuevoItem.nombre_custom" type="text" placeholder="Mueble (ej: Sofá 3 puestos, Silla comedor...)"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                  <input v-model="nuevoItem.descripcion_trabajo" type="text" placeholder="Trabajo a realizar (ej: Tapizado + laca)"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                </template>

                <!-- Diseño especial: nombre + categoría -->
                <template v-else-if="nuevoItem.es_custom">
                  <input v-model="nuevoItem.nombre_custom" type="text" placeholder="Nombre del producto especial"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  <input v-model="nuevoItem.categoria_custom" type="text" placeholder="Categoría (ej: sofá, mesa, cama...)"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </template>

                <!-- Catálogo: modo stock / personalizar / fabricar -->
                <div v-else class="flex gap-1.5 flex-wrap">
                  <button type="button" @click="nuevoItem.modo = 'stock'"
                    :class="['px-2.5 py-1.5 rounded-lg text-xs font-semibold border', nuevoItem.modo === 'stock' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300']">
                    Stock
                  </button>
                  <button v-if="nuevoItem.personalizable" type="button" @click="nuevoItem.modo = 'personalizado'"
                    :class="['px-2.5 py-1.5 rounded-lg text-xs font-semibold border flex items-center gap-1', nuevoItem.modo === 'personalizado' ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-purple-600 border-purple-300']">
                    <SparklesIcon class="w-3 h-3" /> Personalizar
                  </button>
                  <button type="button" @click="nuevoItem.modo = 'fabricar'"
                    :class="['px-2.5 py-1.5 rounded-lg text-xs font-semibold border flex items-center gap-1', nuevoItem.modo === 'fabricar' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-amber-600 border-amber-300']">
                    <WrenchScrewdriverIcon class="w-3 h-3" /> Para fabricar
                  </button>
                </div>

                <!-- Variantes (tela/color) del producto — solo modo stock -->
                <div v-if="!nuevoItem.es_custom && nuevoItem.modo === 'stock' && (nuevoVariantes.length || nuevoCargandoVariantes)">
                  <label class="block text-[11px] text-gray-500 mb-1">
                    Variante (tela/color) <span v-if="nuevoVarianteObligatoria" class="text-red-500">*</span>
                    <span v-if="nuevoCargandoVariantes" class="text-gray-400">· cargando...</span>
                  </label>
                  <p v-if="!nuevoCargandoVariantes && !nuevoVarianteObligatoria && nuevoItem.stock_libre > 0"
                    class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1 mb-1.5">
                    Ninguna variante tiene existencias: el stock de este producto no está
                    asignado a una tela/color. Se puede agregar sin elegir variante.
                  </p>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="v in nuevoVariantes" :key="v.id" type="button"
                      @click="elegirVarianteNuevo(v)"
                      :class="['px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
                        nuevoItem.variante_id === v.id
                          ? 'bg-purple-600 text-white border-purple-600'
                          : (v.stock_libre > 0 ? 'bg-white text-gray-700 border-gray-300 hover:border-purple-400' : 'bg-gray-50 text-gray-400 border-gray-200')]"
                    >
                      {{ [v.marca_tela, v.nombre_color].filter(Boolean).join(' · ') }}
                      <span class="ml-1 font-bold">{{ v.stock_libre ?? 0 }}</span>
                    </button>
                  </div>
                </div>

                <!-- Variantes configurables (combos) — grupos de opciones -->
                <div v-if="!nuevoItem.es_custom && nuevoItem.modo === 'stock' && nuevoVCGrupos.length" class="space-y-2">
                  <div v-for="g in nuevoVCGrupos" :key="g.tipo_variante_id">
                    <label class="block text-[11px] text-gray-500 mb-1">
                      {{ g.tipo?.nombre }} <span class="text-red-500">*</span>
                    </label>
                    <div class="flex flex-wrap gap-1.5">
                      <button
                        v-for="op in g.items" :key="op.id" type="button"
                        @click="elegirOpcionVC(g, op)"
                        :class="['px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
                          nuevoVCSelec[g.tipo_variante_id]?.opcion_nombre === op.opcion_nombre
                            ? 'bg-purple-600 text-white border-purple-600'
                            : ((op.stock_disponible ?? 0) > 0 ? 'bg-white text-gray-700 border-gray-300 hover:border-purple-400' : 'bg-gray-50 text-gray-400 border-gray-200')]"
                      >
                        {{ op.opcion_nombre }}
                        <span v-if="op.precio_adicional > 0" class="text-emerald-600">+${{ Number(op.precio_adicional).toLocaleString('es-CO') }}</span>
                        <span class="ml-1 font-bold">{{ op.stock_disponible ?? 0 }}</span>
                      </button>
                    </div>
                  </div>
                </div>

                <!-- Stock disponible (solo modo stock) -->
                <p v-if="!nuevoItem.es_custom && nuevoItem.modo === 'stock' && nuevoItem.stock_libre != null && !(nuevoVCGrupos.length && !nuevoVCCompleto)"
                  class="text-xs" :class="nuevoItem.stock_libre > 0 ? 'text-green-700' : 'text-red-600'">
                  {{ (nuevoItem.variante_id || nuevoVCGrupos.length) ? 'Stock de la variante' : 'Stock disponible' }}: <strong>{{ nuevoItem.stock_libre }}</strong>
                  <span v-if="nuevoItem.stock_libre <= 0"> — sin stock aquí; usa "Para fabricar" o elige otra tienda arriba.</span>
                </p>

                <!-- Specs + fotos (si va a producción) -->
                <template v-if="nuevoEsProduccion">
                  <div class="space-y-1.5 bg-white rounded-lg border border-gray-200 p-2.5">
                    <p class="text-[11px] font-semibold text-gray-500 uppercase">Especificaciones</p>
                    <div v-for="campo in nuevoTemplate.campos" :key="campo.key">
                      <label class="block text-[11px] text-gray-500 mb-0.5">{{ campo.label }}</label>
                      <TelaPicker
                        v-if="campo.useVariantes"
                        :seleccion="getTelaSelection(nuevoItem, campo.key)"
                        :etiqueta="campo.label"
                      />
                      <input v-else v-model="nuevoItem.specs[campo.key]" type="text"
                        class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    </div>
                    <div>
                      <label class="block text-[11px] text-gray-500 mb-0.5">Notas / detalles</label>
                      <textarea v-model="nuevoItem.specs_notas" rows="2"
                        class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                  </div>

                  <!-- Fotos / bocetos -->
                  <div class="space-y-1.5">
                    <div class="flex flex-wrap gap-1.5">
                      <div v-for="(url, fi) in nuevoItem.boceto_urls" :key="fi" class="relative w-14 h-14">
                        <img :src="url" class="w-full h-full rounded-lg object-cover border border-gray-200" />
                        <button type="button" @click="quitarNuevaFoto(fi)"
                          class="absolute -top-1.5 -right-1.5 bg-white rounded-full shadow p-0.5 text-red-500">
                          <XMarkIcon class="w-3.5 h-3.5" />
                        </button>
                      </div>
                      <label class="w-14 h-14 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center cursor-pointer hover:border-blue-400 text-gray-400">
                        <PhotoIcon class="w-5 h-5" />
                        <input type="file" accept="image/*" multiple class="hidden" @change="onNuevaFoto" />
                      </label>
                    </div>
                    <p v-if="nuevoItem._subiendo" class="text-[11px] text-gray-400">Subiendo foto...</p>
                  </div>
                </template>

                <!-- Cantidad + precio -->
                <div class="flex gap-2">
                  <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Cantidad</label>
                    <input v-model="nuevoItem.cantidad" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Precio unitario</label>
                    <div v-if="nuevoItem._regalo" class="flex items-center gap-1.5 h-[38px] px-3 bg-pink-50 border border-pink-300 rounded-lg">
                      <GiftIcon class="w-4 h-4 text-pink-600 flex-shrink-0" />
                      <span class="text-sm font-semibold text-pink-700">Obsequio · $0</span>
                    </div>
                    <InputPesos
                      v-else
                      v-model="nuevoItem.precio_unitario" permite-vacio
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
                  </div>
                </div>

                <!-- Obsequio. Vale $0 pero descuenta inventario igual: se
                     entrega una unidad de verdad. -->
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <button
                    type="button"
                    @click="toggleRegaloNuevo"
                    :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0', nuevoItem._regalo ? 'bg-pink-500' : 'bg-gray-300']"
                  >
                    <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', nuevoItem._regalo ? 'translate-x-5' : 'translate-x-0.5']" />
                  </button>
                  <span class="text-sm text-gray-600 flex items-center gap-1">
                    <GiftIcon class="w-4 h-4 text-pink-500" /> Obsequiar
                  </span>
                </label>

                <p v-if="nuevoSinStock" class="text-xs text-red-600 text-center">
                  Sin stock disponible — no se puede agregar. Usa "Para fabricar" o elige otra tienda.
                </p>
                <button
                  type="button"
                  @click="agregarNuevo"
                  :disabled="nuevoItem._subiendo || nuevoSinStock"
                  class="w-full py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-40 transition-colors flex items-center justify-center gap-1.5"
                >
                  <PlusIcon class="w-4 h-4" /> Agregar ítem
                </button>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="sticky bottom-0 bg-white border-t border-gray-100 px-5 py-4 space-y-3">
            <!-- Descuento al total -->
            <div class="flex items-center gap-2 text-sm">
              <span class="text-gray-500 flex-shrink-0">Descuento al total</span>
              <div class="flex items-center gap-1 ml-auto">
                <button
                  v-for="m in [{ v: 'monto', t: '$' }, { v: 'pct', t: '%' }]"
                  :key="'em' + m.v" type="button"
                  @click="descuentoModoEdit = m.v; descuentoInputEdit = 0"
                  :class="['w-8 h-8 rounded-lg text-sm font-bold border transition-colors',
                    descuentoModoEdit === m.v
                      ? 'bg-blue-600 text-white border-blue-600'
                      : 'bg-white text-gray-500 border-gray-300']"
                >{{ m.t }}</button>

                <template v-if="descuentoModoEdit === 'pct'">
                  <button
                    v-for="p in [5, 10]" :key="'ep' + p" type="button"
                    @click="descuentoInputEdit = p"
                    class="px-2 py-1 rounded-lg text-xs font-semibold border transition-colors"
                    :class="descuentoInputEdit === p ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600 hover:border-blue-400'"
                  >{{ p }}%</button>
                </template>

                <InputPesos
                v-if="descuentoModoEdit !== 'pct'"
                v-model="descuentoInputEdit"
                placeholder="0"
                :class="['rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500',
                    descuentoModoEdit === 'pct' ? 'w-16' : 'w-28']"
              />
              <input
                v-else
                  v-model.number="descuentoInputEdit"
                  type="number" min="0"
                  :max="descuentoModoEdit === 'pct' ? 100 : null"
                  :step="descuentoModoEdit === 'pct' ? 0.1 : 1000"
                  placeholder="0"
                  :class="['rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500',
                    descuentoModoEdit === 'pct' ? 'w-16' : 'w-28']"
                />
                <span class="text-xs text-gray-400">{{ descuentoModoEdit === 'pct' ? '%' : '$' }}</span>
              </div>
            </div>

            <!-- Descuento por efectivo/transferencia -->
            <div v-if="!condicionadoRevertido" class="flex items-center gap-2 text-sm">
              <span class="text-gray-500 flex-shrink-0">Desc. efectivo/transferencia</span>
              <div class="flex items-center gap-1 ml-auto">
                <button
                  v-for="m in [{ v: 'monto', t: '$' }, { v: 'pct', t: '%' }]"
                  :key="'ec' + m.v" type="button"
                  @click="descCondModoEdit = m.v; descCondInputEdit = 0"
                  :class="['w-8 h-8 rounded-lg text-sm font-bold border transition-colors',
                    descCondModoEdit === m.v
                      ? 'bg-amber-600 text-white border-amber-600'
                      : 'bg-white text-gray-500 border-gray-300']"
                >{{ m.t }}</button>
                <!-- En pesos va con puntos; en % sigue siendo un número suelto.
                     Es el mismo patrón que ya usan los otros dos descuentos de
                     este formulario: acá había un solo campo para los dos modos
                     y en pesos se escribía sin un separador. -->
                <InputPesos
                  v-if="descCondModoEdit !== 'pct'"
                  v-model="descCondInputEdit"
                  placeholder="0"
                  class="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-amber-500"
                />
                <input
                  v-else
                  v-model.number="descCondInputEdit"
                  type="number" min="0" max="100" step="0.1"
                  placeholder="0"
                  class="w-16 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-amber-500"
                />
                <span class="text-xs text-gray-400">{{ descCondModoEdit === 'pct' ? '%' : '$' }}</span>
              </div>
            </div>
            <p v-else class="text-xs text-gray-400">
              El descuento por efectivo/transferencia ya se perdió (se pagó con tarjeta).
            </p>

            <div v-if="totalEditEnCero" class="bg-red-50 border border-red-300 rounded-lg px-3 py-2 text-xs text-red-700 leading-snug">
              <p class="font-semibold">El total queda en $0.</p>
              <p v-if="descCondModoEdit === 'pct' || descuentoModoEdit === 'pct'">
                Revisa si el campo está en <strong>%</strong>: escribir 90000 ahí son 90.000 por ciento.
              </p>
            </div>
            <!-- Qué va a pasar con la consulta de costo al guardar -->
            <div
              v-if="itemsEsperandoCosto.length"
              class="rounded-lg px-3 py-2 text-xs leading-snug border"
              :class="seDestrabaAlGuardar
                ? 'bg-green-50 border-green-300 text-green-800'
                : 'bg-amber-50 border-amber-300 text-amber-800'"
            >
              <template v-if="seDestrabaAlGuardar">
                <p class="font-semibold">Al guardar, la orden deja de esperar el costo.</p>
                <p>
                  Pasa a espera de anticipo, se le asigna el número y se le quita
                  al supervisor de sus consultas pendientes.
                </p>
              </template>
              <template v-else>
                <p class="font-semibold">
                  {{ faltanPreciosPorPoner.length === 1
                     ? 'Falta el precio de 1 ítem.'
                     : `Faltan los precios de ${faltanPreciosPorPoner.length} ítems.` }}
                </p>
                <p>
                  Mientras alguno siga en $0, la orden sigue esperando al supervisor.
                </p>
              </template>
            </div>

            <!-- Total estimado -->
            <div class="flex items-center justify-between text-sm">
              <span class="text-gray-500">
                Total estimado
                <span v-if="descuentoTotalEdit > 0" class="text-green-600 font-normal">(− ${{ descuentoTotalEdit.toLocaleString('es-CO') }} · {{ formatPct(descuentoPctEdit) }}%)</span>
                <span v-if="descCondEdit > 0" class="text-amber-600 font-normal">(− ${{ descCondEdit.toLocaleString('es-CO') }} · {{ formatPct(descCondPctEdit) }}% efectivo)</span>
              </span>
              <span class="font-bold text-gray-900">${{ totalEstimado.toLocaleString('es-CO') }}</span>
            </div>
            <div class="flex gap-3">
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
      </div>
    </Transition>
  </Teleport>
</template>
