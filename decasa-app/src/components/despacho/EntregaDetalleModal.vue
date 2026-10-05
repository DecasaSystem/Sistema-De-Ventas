<script setup>
import { cloudinaryOpt } from '@/utils/cloudinary'
import { ref, computed, watch, onUnmounted } from 'vue'
import { detalleEntrega, registrarPagoEntrega, marcarEntregado, fijarLineasEntrega } from '@/api/despacho'
import { descargarOrdenEntrega } from '@/api/ordenes'
import { useToast } from '@/composables/useToast'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import { CheckCircleIcon, MapPinIcon, ClockIcon, ExclamationTriangleIcon, CameraIcon, XMarkIcon, PrinterIcon } from '@heroicons/vue/24/outline'
import InputPesos from '@/components/common/InputPesos.vue'
import ComoLlegar from '@/components/despacho/ComoLlegar.vue'
import { comprimirImagen } from '@/utils/comprimirImagen'

// Antes había aquí una copia propia que cargaba la foto ENTERA de la cámara
// en memoria y, si la foto no abría, se quedaba esperando para siempre: en
// celulares de gama media el formulario quedaba en blanco al subir fotos. La
// compartida decodifica la foto ya reducida y, si algo falla, devuelve la
// original en vez de colgarse.
function compressImage(file) {
  return comprimirImagen(file, { maxDim: 1280, quality: 0.75, skipBytes: 350 * 1024 })
}

const props = defineProps({
  despachoItemId: { type: Number, required: true },
})
const emit = defineEmits(['cerrar', 'entregado'])

const toast = useToast()

const item      = ref(null)
const cargando  = ref(true)
const registrando = ref(false)

const esEntregado = computed(() => ['entregado', 'devuelto'].includes(item.value?.estado))
const tieneSaldo  = computed(() => (item.value?.orden?.saldo_pendiente ?? 0) > 0.01)

// ── Qué se entrega hoy ───────────────────────────────────────────────────────
// Se entregan productos, no órdenes: el reloj hoy, el mueble cuando el taller
// lo termine. Cada ítem trae del servidor cuánto le falta por entregar y si
// ya se puede (lo de catálogo siempre; lo fabricado, cuando está listo).
// { [orden_item_id]: cantidad que va en ESTA entrega }
const llevar = ref({})

const itemsOrden = computed(() => item.value?.orden?.items ?? [])

const entregables = computed(() =>
  itemsOrden.value.filter(oi => oi.entregable && (oi.pendiente_entregar ?? 0) > 0)
)
const noEntregables = computed(() =>
  itemsOrden.value.filter(oi => !oi.entregable || (oi.pendiente_entregar ?? 0) <= 0)
)

function alternarLlevar(oi) {
  if (llevar.value[oi.id]) delete llevar.value[oi.id]
  else llevar.value[oi.id] = oi.pendiente_entregar
}

const lineas = computed(() =>
  Object.entries(llevar.value)
    .filter(([, c]) => Number(c) > 0)
    .map(([id, c]) => ({ orden_item_id: Number(id), cantidad: Number(c) }))
)
const hayAlgoQueEntregar = computed(() => lineas.value.length > 0)

// La hoja que se lleva quien entrega, con LO MARCADO y no con todo lo que
// falta. Primero se deja escrito en la entrega qué va (si no, la hoja
// saldría con los 4 productos cuando hoy van 2) y después se imprime.
const imprimiendoHoja = ref(false)
async function imprimirOrdenEntrega() {
  if (imprimiendoHoja.value || !item.value) return
  if (!hayAlgoQueEntregar.value) { toast.error('Marca qué se entrega hoy antes de imprimir.'); return }
  imprimiendoHoja.value = true
  try {
    await fijarLineasEntrega(item.value.id, lineas.value)
    const response = await descargarOrdenEntrega(item.value.orden.id, item.value.id)
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const url  = window.URL.createObjectURL(blob)
    if (!window.open(url, '_blank')) toast.error('El navegador bloqueó la ventana del PDF. Permite las ventanas emergentes.')
    setTimeout(() => window.URL.revokeObjectURL(url), 10000)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo generar la orden de entrega.')
  } finally {
    imprimiendoHoja.value = false
  }
}

// ¿Con esto el cliente queda con todo lo que compró? Es lo que decide si se
// le cobra el saldo hoy o en la próxima entrega.
const esLaUltimaEntrega = computed(() =>
  itemsOrden.value.every(oi =>
    (oi.pendiente_entregar ?? 0) <= (Number(llevar.value[oi.id]) || 0) - (Number(devueltos.value[oi.id]) || 0)
  )
)
const esParcial = computed(() => hayAlgoQueEntregar.value && !esLaUltimaEntrega.value)

// El pago solo es obligatorio en la última entrega con saldo. En una parcial
// se puede abonar, pero no se exige: al cliente aún le falta recibir algo.
const seExigePago = computed(() => tieneSaldo.value && esLaUltimaEntrega.value && !devuelveTodo.value)
const quiereAbonar = ref(false)
const traePago = computed(() => seExigePago.value || (esParcial.value && quiereAbonar.value))

// Formulario de pago
const monto      = ref(0)
const metodo     = ref('efectivo')
const referencia = ref('')
const fotoAnexo           = ref(null)
const fotoAnexoPreview    = ref(null)

// ── Fotos de lo que se entrega, producto por producto ─────────────────────────
// Antes era UNA foto para toda la entrega: con un sofá, dos poltronas y seis
// sillas, si después reclaman una silla no había foto de esa silla. Ahora cada
// producto que se queda el cliente lleva al menos una (hasta 3). En una
// entrega parcial solo se piden las de lo que va hoy.
const MAX_FOTOS_POR_PRODUCTO = 3
const fotosProducto = ref({})   // { [orden_item_id]: [{ blob, preview }] }

function fotosDe(oi) {
  return fotosProducto.value[oi.id] ?? []
}

// ── Recibir fotos: del botón o arrastradas ───────────────────────────────────
// Las dos entradas pasan por aquí. Mientras se procesan se ve "Procesando…"
// en esa casilla: una foto grande tarda un momento y antes no se veía nada.
const procesando = ref({})   // { [zona]: true }
const arrastrando = ref(null) // la zona sobre la que se está arrastrando

/** Lo que llegó, solo las imágenes, ya comprimidas. Avisa si algo no sirve. */
async function prepararFotos(zona, archivos, cupo) {
  const todos = Array.from(archivos ?? [])
  const imagenes = todos.filter(esImagen).slice(0, Math.max(0, cupo))
  if (todos.length && !imagenes.length) {
    toast.error('Eso no es una foto. Arrastra o elige una imagen.')
    return []
  }
  procesando.value = { ...procesando.value, [zona]: true }
  try {
    const listas = []
    for (const file of imagenes) {
      const blob = await compressImage(file)
      listas.push({ blob, preview: _createPreviewUrl(blob) })
    }
    return listas
  } catch {
    toast.error('No se pudo leer la foto. Intenta tomarla otra vez.')
    return []
  } finally {
    const { [zona]: _, ...resto } = procesando.value
    procesando.value = resto
  }
}

function esImagen(file) {
  return !!file && (file.type?.startsWith('image/') || /\.(jpe?g|png|webp|heic|heif)$/i.test(file.name ?? ''))
}

async function agregarFotosProducto(oi, archivos) {
  const nuevas = await prepararFotos(`p-${oi.id}`, archivos, MAX_FOTOS_POR_PRODUCTO - fotosDe(oi).length)
  if (nuevas.length) fotosProducto.value = { ...fotosProducto.value, [oi.id]: [...fotosDe(oi), ...nuevas] }
}

async function onFotoDeProducto(oi, e) {
  const archivos = Array.from(e.target.files ?? [])
  e.target.value = ''
  await agregarFotosProducto(oi, archivos)
}

/** Soltar archivos arrastrados sobre una casilla. */
function alSoltar(e, recibir) {
  arrastrando.value = null
  const archivos = e.dataTransfer?.files
  if (archivos?.length) recibir(archivos)
}

function quitarFotoDeProducto(oi, i) {
  const lista = [...fotosDe(oi)]
  const [quitada] = lista.splice(i, 1)
  // Se suelta de una vez: si no, cada foto quitada seguía ocupando memoria
  // hasta cerrar el formulario.
  if (quitada?.preview) URL.revokeObjectURL(quitada.preview)
  fotosProducto.value = { ...fotosProducto.value, [oi.id]: lista }
}
// Varias fotos del comprobante: dos transferencias, o el pantallazo que no
// cabe en una sola captura.
const fotosPago           = ref([])   // [{ blob, preview }]
const fotoPagoPreview     = computed(() => fotosPago.value[0]?.preview ?? null)

// ── Acta de satisfacción ─────────────────────────────────────────────────────
// Quien recibe firma que el producto llegó y en qué estado. No siempre es el
// cliente: puede ser un familiar, la empleada o el portero, por eso se pide
// nombre y cédula de quien efectivamente está firmando.
const firmaBlob      = ref(null)
const recibidoNombre = ref('')
const recibidoCedula = ref('')
const conforme       = ref(true)
const observaciones  = ref('')
const fotoNovedad        = ref(null)
const fotoNovedadPreview = ref(null)
const noHayQuienFirme = ref(false)
const motivoSinFirma  = ref('')

const actaCompleta = computed(() => {
  if (noHayQuienFirme.value) return motivoSinFirma.value.trim().length > 0
  if (!firmaBlob.value) return false
  if (!recibidoNombre.value.trim()) return false
  // "Con novedad" sin decir cuál no sirve de nada cuando llegue el reclamo
  if (!conforme.value && !observaciones.value.trim()) return false
  return true
})

// ── Lo que se devuelve ───────────────────────────────────────────────────────
// "Con novedad" es que llegó rayado y el cliente se lo quedó igual. Esto es
// otra cosa: el producto se regresa en el camión. Va por pieza porque puede
// llevar la cama y dos mesas y volver solo la cama.
const hayDevolucion   = ref(false)
const motivoDevolucion = ref('')
// Lo que el cliente prefiere que se haga con lo que devuelve. Quien entrega
// le da las opciones y anota la respuesta; decide el supervisor después.
const preferencia      = ref('')
const PREFERENCIAS     = [
  { v: 'arreglar',      t: 'Que lo arreglen',            d: 'Vuelve al taller y se le entrega el mismo, reparado.' },
  { v: 'cambiar_mismo', t: 'Otro igual',                 d: 'Se le cambia por otra unidad del mismo producto.' },
  { v: 'cambiar_otro',  t: 'Otro producto',              d: 'Escoge otro; si vale más paga la diferencia, si vale menos queda a favor.' },
]
const fotoDevolucion        = ref(null)
const fotoDevolucionPreview = ref(null)
// { [orden_item_id]: cantidad que vuelve }
const devueltos = ref({})

// Solo puede volver lo que iba en esta entrega.
const itemsQueVan = computed(() => entregables.value.filter(oi => Number(llevar.value[oi.id]) > 0))

function alternarDevuelto(oi) {
  if (devueltos.value[oi.id]) {
    delete devueltos.value[oi.id]
  } else {
    // Casi siempre vuelve todo lo de esa línea; si vuelve solo una de dos, se
    // baja a mano.
    devueltos.value[oi.id] = Number(llevar.value[oi.id]) || oi.pendiente_entregar
  }
}

function nombreItem(oi) {
  return oi.nombre_custom || oi.producto?.nombre || 'Producto'
}

/**
 * Las fotos de una entrega ya hecha, agrupadas por producto. Las entregas de
 * antes tienen una sola foto, sin producto: van como "Producto".
 */
const fotosEntregadas = computed(() => {
  const fotos = item.value?.fotos_producto ?? []
  if (!fotos.length) {
    return item.value?.foto_producto ? [{ clave: 'unica', nombre: 'Producto', urls: [item.value.foto_producto] }] : []
  }
  const grupos = new Map()
  for (const f of fotos) {
    const clave = f.orden_item_id ?? 'general'
    if (!grupos.has(clave)) {
      const oi = itemsOrden.value.find(x => x.id === f.orden_item_id)
      grupos.set(clave, { clave, nombre: oi ? nombreItem(oi) : 'Producto', urls: [] })
    }
    grupos.get(clave).urls.push(f.url)
  }
  return [...grupos.values()]
})

/** La línea de esta entrega para un producto (modo lectura). */
function lineaDe(oi) {
  return (item.value?.lineas ?? []).find(l => l.orden_item_id === oi.id) ?? null
}

const piezasDevueltas = computed(() =>
  Object.values(devueltos.value).reduce((s, n) => s + (Number(n) || 0), 0)
)

// Si vuelve absolutamente todo lo que iba, no hay nada que cobrarle al cliente.
const devuelveTodo = computed(() => {
  if (!hayDevolucion.value || !itemsQueVan.value.length) return false
  return itemsQueVan.value.every(oi => (Number(devueltos.value[oi.id]) || 0) >= (Number(llevar.value[oi.id]) || 0))
})

// Lo que el cliente se queda hoy: lo que va menos lo que vuelve. Eso es lo que
// necesita foto.
const itemsQueSeQuedan = computed(() =>
  itemsQueVan.value.filter(oi => (Number(llevar.value[oi.id]) || 0) - (Number(devueltos.value[oi.id]) || 0) > 0)
)
const productosSinFoto = computed(() => itemsQueSeQuedan.value.filter(oi => !fotosDe(oi).length))
const fotosCompletas = computed(() => {
  if (itemsQueSeQuedan.value.length) return productosSinFoto.value.length === 0
  // Si vuelve todo, basta una foto: de lo que se llevó o de la devolución.
  return itemsQueVan.value.some(oi => fotosDe(oi).length) || !!fotoDevolucion.value
})

const devolucionCompleta = computed(() => {
  if (!hayDevolucion.value) return true
  if (!piezasDevueltas.value) return false
  return motivoDevolucion.value.trim().length >= 3
})

async function agregarFotoDevolucion(archivos) {
  const [f] = await prepararFotos('devolucion', archivos, 1)
  if (f) { fotoDevolucion.value = f.blob; fotoDevolucionPreview.value = f.preview }
}

async function onFotoDevolucion(e) {
  const archivos = Array.from(e.target.files ?? [])
  e.target.value = ''
  await agregarFotoDevolucion(archivos)
}

// ── Descuento que se pierde al pagar con tarjeta ──────────────────────────────
// Viene del backend cuando la orden tiene descuento por pago en efectivo o
// transferencia. Si el conductor elige tarjeta, el cliente pierde el descuento
// y el monto a cobrar sube: se fija y no se puede bajar.
const descuentoCond = computed(() => item.value?.descuento_condicionado ?? null)

const pierdeDescuento = computed(() => {
  if (!descuentoCond.value) return false
  return !descuentoCond.value.metodos_que_lo_conservan.includes(metodo.value)
})

const montoACobrar = computed(() =>
  pierdeDescuento.value
    ? Number(descuentoCond.value.saldo_sin_descuento)
    : Number(item.value?.orden?.saldo_pendiente ?? 0)
)

// Al cambiar el método el monto se recalcula solo: con tarjeta queda bloqueado
// en el total sin descuento para que no se cobre de menos. En una entrega
// parcial el monto es un abono libre: se deja como lo escriba quien cobra.
watch([metodo, descuentoCond, esLaUltimaEntrega], () => {
  if (esEntregado.value) return
  if (esLaUltimaEntrega.value) monto.value = montoACobrar.value
})

const puedeEntregar = computed(() => {
  if (!hayAlgoQueEntregar.value) return false
  if (!fotosCompletas.value) return false
  if (!actaCompleta.value) return false
  if (!devolucionCompleta.value) return false
  // Si vuelve todo, no se le cobra nada: no se le puede pedir al conductor un
  // comprobante de un pago que no existe.
  if (devuelveTodo.value) return true
  if (traePago.value) return !!fotoPagoPreview.value && monto.value > 0
  return true  // sin saldo, o entrega parcial sin abono: foto y acta bastan
})

const mensajeBoton = computed(() => {
  if (!hayAlgoQueEntregar.value)  return 'Marca qué se entrega hoy'
  if (!fotosCompletas.value) {
    const n = productosSinFoto.value.length
    if (n === 1) return `Falta la foto de: ${nombreItem(productosSinFoto.value[0])}`
    return n ? `Faltan las fotos de ${n} productos` : 'Sube una foto de lo que se llevó'
  }
  if (hayDevolucion.value) {
    if (!piezasDevueltas.value)              return 'Marca qué se devuelve'
    if (motivoDevolucion.value.trim().length < 3) return 'Escribe por qué se devuelve'
  }
  if (traePago.value && !devuelveTodo.value) {
    if (!fotoPagoPreview.value) return 'Sube la foto del comprobante de pago'
    if (!(monto.value > 0))     return 'Ingresa el monto cobrado'
  }
  if (noHayQuienFirme.value && !motivoSinFirma.value.trim()) return 'Explica por qué nadie pudo firmar'
  if (!noHayQuienFirme.value) {
    if (!recibidoNombre.value.trim()) return 'Escribe el nombre de quien recibe'
    if (!firmaBlob.value)             return 'Falta la firma de quien recibe'
    if (!conforme.value && !observaciones.value.trim()) return 'Describe la novedad del producto'
  }
  return null
})

const METODO_LABEL = {
  efectivo: 'Efectivo', transferencia: 'Transferencia',
  tarjeta: 'Tarjeta', addi: 'Addi', otro: 'Otro',
}

function fmtFecha(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('es-CO', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

watch(() => props.despachoItemId, async (id) => {
  if (!id) return
  await cargar(id)
}, { immediate: true })

async function cargar(id) {
  cargando.value = true
  try {
    const { data } = await detalleEntrega(id)
    item.value = data
    if (!esEntregado.value) {
      // Por defecto va lo que se cargó al armar la ruta (si todavía se puede
      // entregar); sin eso, todo lo que se pueda entregar hoy. Se desmarca
      // lo que no.
      const cargado = {}
      for (const l of data.lineas ?? []) {
        if (l.resultado !== 'devuelto') cargado[l.orden_item_id] = (cargado[l.orden_item_id] || 0) + Number(l.cantidad)
      }
      const marcado = {}
      for (const oi of data.orden?.items ?? []) {
        if (!(oi.entregable && (oi.pendiente_entregar ?? 0) > 0)) continue
        if (Object.keys(cargado).length && !cargado[oi.id]) continue
        marcado[oi.id] = Math.min(oi.pendiente_entregar, cargado[oi.id] ?? oi.pendiente_entregar)
      }
      llevar.value = Object.keys(marcado).length ? marcado : Object.fromEntries(
        (data.orden?.items ?? []).filter(oi => oi.entregable && (oi.pendiente_entregar ?? 0) > 0).map(oi => [oi.id, oi.pendiente_entregar])
      )
      monto.value = data.orden?.saldo_pendiente || 0
      // Se precarga el cliente: en la mayoría de entregas recibe él mismo, y si
      // no, el conductor lo cambia por quien esté firmando.
      recibidoNombre.value = data.orden?.cliente?.nombre ?? ''
      recibidoCedula.value = data.orden?.cliente?.cedula ?? ''
    }
  } catch {} finally {
    cargando.value = false
  }
}

const _blobUrls = []
function _createPreviewUrl(blob) {
  const url = URL.createObjectURL(blob)
  _blobUrls.push(url)
  return url
}
onUnmounted(() => _blobUrls.forEach(u => URL.revokeObjectURL(u)))

async function agregarFotosPago(archivos) {
  const nuevas = await prepararFotos('pago', archivos, 6 - fotosPago.value.length)
  fotosPago.value.push(...nuevas)
}

async function onFotoPago(e) {
  const archivos = Array.from(e.target.files ?? [])
  e.target.value = ''
  await agregarFotosPago(archivos)
}

function quitarFotoPago(i) {
  const [quitada] = fotosPago.value.splice(i, 1)
  if (quitada?.preview) URL.revokeObjectURL(quitada.preview)
}

function quitarFotoAnexo() {
  if (fotoAnexoPreview.value) URL.revokeObjectURL(fotoAnexoPreview.value)
  fotoAnexo.value = null
  fotoAnexoPreview.value = null
}

/**
 * Cerrar sin perder lo cargado por accidente.
 *
 * En el teléfono la franja oscura de arriba queda justo donde cae el dedo:
 * un roce cerraba el formulario y se perdían las fotos, la firma y el
 * comprobante, y había que empezar de cero en la puerta del cliente. Si ya
 * hay algo cargado, se pregunta antes.
 */
const hayAlgoCargado = computed(() =>
  Object.values(fotosProducto.value).some(l => l.length)
  || fotosPago.value.length > 0
  || !!fotoAnexo.value || !!fotoNovedad.value || !!fotoDevolucion.value
  || !!firmaBlob.value
)

function intentarCerrar() {
  if (registrando.value) return
  if (!esEntregado.value && hayAlgoCargado.value
      && !confirm('¿Salir sin registrar la entrega? Se pierden las fotos y la firma que ya cargaste.')) {
    return
  }
  emit('cerrar')
}

async function agregarFotoAnexo(archivos) {
  const [f] = await prepararFotos('anexo', archivos, 1)
  if (f) { fotoAnexo.value = f.blob; fotoAnexoPreview.value = f.preview }
}

async function onFotoAnexo(e) {
  const archivos = Array.from(e.target.files ?? [])
  e.target.value = ''
  await agregarFotoAnexo(archivos)
}

async function agregarFotoNovedad(archivos) {
  const [f] = await prepararFotos('novedad', archivos, 1)
  if (f) { fotoNovedad.value = f.blob; fotoNovedadPreview.value = f.preview }
}

async function onFotoNovedad(e) {
  const archivos = Array.from(e.target.files ?? [])
  e.target.value = ''
  await agregarFotoNovedad(archivos)
}

async function guardarPagoYEntregar() {
  if (!puedeEntregar.value) return
  registrando.value = true
  try {
    const fd = new FormData()
    // Las fotos de cada producto que va hoy, con el producto al que son.
    for (const oi of itemsQueVan.value) {
      fotosDe(oi).forEach((f, i) => fd.append(`fotos_producto[${oi.id}][]`, f.blob, `producto_${oi.id}_${i + 1}.jpg`))
    }

    // ── Qué va en esta entrega ──────────────────────────────────────────────
    fd.append('lineas', JSON.stringify(lineas.value))

    // ── Lo que se regresa en el camión ──────────────────────────────────────
    // Va antes del pago porque lo cambia: si vuelve todo, no se cobra nada.
    if (hayDevolucion.value && piezasDevueltas.value) {
      fd.append('devoluciones', JSON.stringify(
        Object.entries(devueltos.value)
          .filter(([, cant]) => Number(cant) > 0)
          .map(([ordenItemId, cant]) => ({
            orden_item_id: Number(ordenItemId),
            cantidad: Number(cant),
            motivo: motivoDevolucion.value.trim(),
            preferencia: preferencia.value || undefined,
          }))
      ))
      if (fotoDevolucion.value) fd.append('foto_devolucion', fotoDevolucion.value, 'foto_devolucion.jpg')
    }

    if (traePago.value && !devuelveTodo.value && monto.value > 0) {
      fd.append('monto', monto.value)
      fd.append('metodo', metodo.value)
      if (referencia.value) fd.append('referencia', referencia.value)
      fotosPago.value.forEach((f, i) => fd.append('fotos_pago[]', f.blob, `foto_pago_${i + 1}.jpg`))
    } else {
      fd.append('monto', '0')
    }
    if (fotoAnexo.value) fd.append('foto_anexo', fotoAnexo.value, 'foto_anexo.jpg')

    // ── Acta de satisfacción ────────────────────────────────────────────────
    if (noHayQuienFirme.value) {
      fd.append('firma_omitida_motivo', motivoSinFirma.value.trim())
    } else {
      fd.append('firma_recibido', firmaBlob.value, 'firma_recibido.png')
      fd.append('recibido_por_nombre', recibidoNombre.value.trim())
      if (recibidoCedula.value.trim()) fd.append('recibido_por_cedula', recibidoCedula.value.trim())
      fd.append('conforme', conforme.value ? '1' : '0')
      if (!conforme.value) {
        fd.append('observaciones_entrega', observaciones.value.trim())
        if (fotoNovedad.value) fd.append('foto_novedad', fotoNovedad.value, 'foto_novedad.jpg')
      }
    }

    await registrarPagoEntrega(props.despachoItemId, fd)
    await marcarEntregado(props.despachoItemId)
    toast.success(esParcial.value
      ? 'Entrega parcial registrada. Lo demás queda pendiente.'
      : 'Entrega completada exitosamente')
    emit('entregado')
    emit('cerrar')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Error al procesar la entrega')
  } finally {
    registrando.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
    <div class="fixed inset-0 bg-black/40" @click="intentarCerrar" />

    <!-- min-w-0: sin esto el panel crecía con lo más ancho de adentro (el
         lienzo de la firma en un teléfono de alta resolución) y se salía de
         la pantalla por la derecha. -->
    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full min-w-0 sm:max-w-lg panel-alto panel-fijo flex flex-col z-10" @scroll="$event.target.scrollTop = 0">
      <!-- Header -->
      <div class="shrink-0 bg-white border-b border-gray-100 pl-5 pr-2 py-2 flex items-center justify-between">
        <div class="flex items-center gap-2 min-w-0">
          <CheckCircleIcon v-if="esEntregado" class="w-5 h-5 text-green-500 shrink-0" />
          <h3 class="text-lg font-bold text-gray-900 truncate">
            {{ esEntregado ? 'Detalle de entrega' : 'Registrar entrega' }}
          </h3>
        </div>
        <button
          @click="intentarCerrar"
          class="w-11 h-11 flex items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 text-2xl leading-none"
          aria-label="Cerrar"
        >&times;</button>
      </div>

      <div class="relative flex-1 min-h-0 overflow-y-auto overscroll-contain">

      <div v-if="cargando" class="p-8 text-center text-sm text-gray-400">Cargando…</div>

      <template v-else-if="item">
        <div class="px-4 py-5 sm:p-5 space-y-6">

          <!-- Info del cliente -->
          <div class="bg-gray-50 rounded-xl p-4 space-y-1.5">
            <p class="font-bold text-gray-900">{{ item.orden?.cliente?.nombre }}</p>
            <p class="text-sm text-gray-500">{{ item.orden?.cliente?.telefono }}</p>

            <div v-if="item.orden?.direccion_envio" class="flex items-start gap-1.5 text-sm text-gray-600 mt-1">
              <MapPinIcon class="w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5" />
              <span>
                {{ item.orden.direccion_envio }}
                <span v-if="item.orden.ciudad_envio">, {{ item.orden.ciudad_envio }}</span>
              </span>
            </div>
            <p v-else class="text-sm text-gray-500 flex items-center gap-1">
              <MapPinIcon class="w-4 h-4 text-gray-400" />
              {{ item.orden?.cliente?.direccion }}
            </p>

            <!-- Cómo llegar: Waze o Google Maps con la dirección completa
                 (ciudad incluida), y llamar si aun así no se encuentra. -->
            <ComoLlegar v-if="item.estado !== 'entregado'" :orden="item.orden" variante="detalle" class="pt-3" />

            <div class="flex items-center gap-4 mt-2 text-sm">
              <span class="text-gray-600">
                Total: <MoneyDisplay :amount="item.orden?.valor_total" :bold="true" />
              </span>
              <span v-if="!esEntregado && tieneSaldo" class="text-orange-600 font-semibold">
                Cobra: <MoneyDisplay :amount="montoACobrar" />
              </span>
              <span v-else-if="!esEntregado" class="text-green-600 text-xs font-semibold">✓ Ya pagado</span>
            </div>

            <div v-if="esEntregado && item.entregado_at" class="flex items-center gap-1.5 text-sm text-green-600 pt-1 border-t border-gray-200 mt-1">
              <ClockIcon class="w-4 h-4" />
              Entregado el {{ fmtFecha(item.entregado_at) }}
            </div>
          </div>

          <!-- Notas del supervisor -->
          <div v-if="item.despacho?.notas" class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3">
            <p class="text-xs font-semibold text-amber-700 mb-1">Notas del despacho</p>
            <p class="text-sm text-amber-800">{{ item.despacho.notas }}</p>
          </div>

          <!-- Productos: en modo lectura, lo que fue en esta entrega -->
          <div v-if="esEntregado && item.orden?.items?.length">
            <h4 class="text-sm font-semibold text-gray-700 mb-2">Productos de esta entrega</h4>
            <div class="space-y-2">
              <div v-for="p in item.orden.items" :key="p.id" class="flex items-center gap-3">
                <img
                  v-if="p.producto?.foto_url"
                  :src="cloudinaryOpt(p.producto.foto_url, 96)"
                  :alt="p.producto.nombre"
                  class="w-12 h-12 rounded-lg object-cover border border-gray-100 flex-shrink-0"
                />
                <div v-else class="w-12 h-12 rounded-lg bg-gray-100 flex-shrink-0" />
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-800 truncate">{{ nombreItem(p) }}</p>
                  <p class="text-xs text-gray-400">
                    <template v-if="lineaDe(p)">{{ lineaDe(p).cantidad }} de {{ p.cantidad }}</template>
                    <template v-else>x{{ p.cantidad }}</template>
                    <span v-if="lineaDe(p)?.resultado === 'devuelto'" class="text-orange-600 font-semibold"> · se devolvió</span>
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- ═══════════ QUÉ SE ENTREGA HOY ═══════════
               Se entregan productos, no órdenes: el reloj hoy, el mueble
               cuando el taller lo termine. Lo que no se pueda entregar se ve,
               pero no se puede marcar. -->
          <div v-else-if="item.orden?.items?.length" class="space-y-2">
            <div class="flex items-baseline justify-between">
              <h4 class="paso-titulo"><span class="paso-num">1</span>¿Qué se entrega hoy?</h4>
              <span v-if="item.orden?.entrega?.entregados" class="text-xs text-gray-500">
                ya entregados: {{ item.orden.entrega.entregados }} de {{ item.orden.entrega.total }}
              </span>
            </div>

            <div v-if="!entregables.length" class="bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 text-xs text-amber-800">
              No hay nada que se pueda entregar hoy: lo de catálogo ya se entregó y lo demás sigue en el taller.
            </div>

            <div class="space-y-1.5">
              <label
                v-for="p in entregables" :key="p.id"
                :class="['relative flex items-center gap-3 rounded-xl px-3 py-2.5 min-h-14 border-2 cursor-pointer transition-colors',
                  llevar[p.id] ? 'bg-emerald-50 border-emerald-400' : 'bg-white border-gray-200']"
              >
                <input
                  type="checkbox"
                  :checked="!!llevar[p.id]"
                  @change="alternarLlevar(p)"
                  class="w-5 h-5 shrink-0 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                />
                <img
                  v-if="p.producto?.foto_url"
                  :src="cloudinaryOpt(p.producto.foto_url, 96)"
                  class="w-10 h-10 rounded-lg object-cover border border-gray-100 flex-shrink-0"
                />
                <div v-else class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-gray-800 leading-snug line-clamp-2">{{ nombreItem(p) }}</p>
                  <p class="text-xs text-gray-600">
                    {{ p.pendiente_entregar < p.cantidad ? `faltan ${p.pendiente_entregar} de ${p.cantidad}` : `x${p.cantidad}` }}
                  </p>
                </div>
                <!-- De dos relojes se puede llevar uno -->
                <input
                  v-if="llevar[p.id] && p.pendiente_entregar > 1"
                  v-model.number="llevar[p.id]"
                  @click.stop
                  type="number" min="1" :max="p.pendiente_entregar"
                  class="w-16 h-10 border border-emerald-300 rounded-lg px-1.5 text-base text-center focus:ring-2 focus:ring-emerald-500 outline-none"
                />
              </label>

              <!-- Lo que hoy no sale -->
              <div
                v-for="p in noEntregables" :key="'no-' + p.id"
                class="flex items-center gap-3 rounded-xl px-3 py-2 border border-dashed border-gray-200 bg-gray-50 opacity-80"
              >
                <span class="w-4 h-4 flex-shrink-0" />
                <img
                  v-if="p.producto?.foto_url"
                  :src="cloudinaryOpt(p.producto.foto_url, 96)"
                  class="w-10 h-10 rounded-lg object-cover border border-gray-100 flex-shrink-0 grayscale"
                />
                <div v-else class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-600 truncate">{{ nombreItem(p) }}</p>
                  <p class="text-xs" :class="p.pendiente_entregar <= 0 ? 'text-emerald-700' : 'text-purple-700'">
                    {{ p.pendiente_entregar <= 0 ? '✓ Ya entregado' : 'En el taller: se entrega cuando esté listo' }}
                  </p>
                </div>
              </div>
            </div>

            <p v-if="esParcial" class="text-xs text-blue-800 bg-blue-50 border border-blue-200 rounded-lg px-3 py-2">
              Entrega parcial: lo demás queda pendiente y se entrega después. El saldo no se exige hoy.
            </p>

            <!-- La hoja impresa sale con lo marcado arriba: si hoy van 2 de 4,
                 lleva esos 2 y abajo dice qué queda pendiente. -->
            <button
              v-if="entregables.length"
              type="button"
              @click="imprimirOrdenEntrega"
              :disabled="imprimiendoHoja || !hayAlgoQueEntregar"
              class="w-full min-h-11 flex items-center justify-center gap-2 border border-gray-300 bg-white text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 disabled:opacity-50 transition-colors"
            >
              <PrinterIcon class="w-5 h-5" aria-hidden="true" /> {{ imprimiendoHoja ? 'Generando…' : `Imprimir hoja de entrega (${lineas.length} producto${lineas.length === 1 ? '' : 's'})` }}
            </button>
          </div>

          <!-- ── MODO LECTURA (entregado) ─────────────────────────────────── -->
          <template v-if="esEntregado">
            <div v-if="item.orden?.pagos?.length" class="space-y-2">
              <h4 class="text-sm font-semibold text-gray-700">Pago registrado</h4>
              <div
                v-for="pago in item.orden.pagos"
                :key="pago.id"
                class="bg-green-50 border border-green-100 rounded-xl px-4 py-3 flex items-center justify-between"
              >
                <div>
                  <p class="text-sm font-semibold text-green-800"><MoneyDisplay :amount="pago.monto" /></p>
                  <p class="text-xs text-green-600">
                    {{ METODO_LABEL[pago.metodo] ?? pago.metodo }}
                    <span v-if="pago.referencia"> · {{ pago.referencia }}</span>
                  </p>
                </div>
                <p class="text-xs text-gray-400">{{ fmtFecha(pago.created_at) }}</p>
              </div>
            </div>

            <div v-if="item.foto_producto || item.foto_pago">
              <h4 class="text-sm font-semibold text-gray-700 mb-2">Fotos de evidencia</h4>
              <!-- Las de cada producto, con su nombre -->
              <div v-for="g in fotosEntregadas" :key="g.clave" class="mb-3">
                <p class="text-xs text-gray-500 mb-1">{{ g.nombre }}</p>
                <div class="grid grid-cols-3 gap-2">
                  <a v-for="url in g.urls" :key="url" :href="url" target="_blank">
                    <img :src="cloudinaryOpt(url, 400)" class="w-full h-24 object-cover rounded-xl border border-gray-100" />
                  </a>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div v-for="(url, i) in (item.fotos_pago?.length ? item.fotos_pago : (item.foto_pago ? [item.foto_pago] : []))" :key="url">
                  <p class="text-xs text-gray-500 mb-1">Comprobante{{ item.fotos_pago?.length > 1 ? ' ' + (i + 1) : '' }}</p>
                  <a :href="url" target="_blank">
                    <img :src="cloudinaryOpt(url, 600)" class="w-full h-28 object-cover rounded-xl border border-gray-100" />
                  </a>
                </div>
              </div>
            </div>

            <button @click="emit('cerrar')" class="w-full py-3 rounded-xl font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors">
              Cerrar
            </button>
          </template>

          <!-- ── MODO ACTIVO (pendiente) ──────────────────────────────────── -->
          <template v-else>

            <!-- Fotos de lo entregado — una casilla por producto que va hoy.
                 Cada uno necesita al menos una: si después reclaman una silla,
                 tiene que haber foto de esa silla. -->
            <div class="space-y-2">
              <h4 class="paso-titulo"><span class="paso-num">2</span>Fotos de lo entregado</h4>
              <p class="paso-ayuda">
                Una foto de cada producto como mínimo, hasta {{ MAX_FOTOS_POR_PRODUCTO }}. Si son varias unidades, que se vean todas.
              </p>
              <p v-if="!itemsQueVan.length" class="text-sm text-gray-500">Marca arriba qué se entrega hoy.</p>
              <div class="space-y-2">
                <!-- Toda la tarjeta recibe fotos arrastradas desde el computador -->
                <div
                  v-for="oi in itemsQueVan" :key="'foto-' + oi.id"
                  @dragover.prevent="arrastrando = `p-${oi.id}`"
                  @dragleave.self="arrastrando = null"
                  @drop.prevent="alSoltar($event, a => agregarFotosProducto(oi, a))"
                  :class="['rounded-xl border p-3 transition-colors',
                    arrastrando === `p-${oi.id}` ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-300'
                    : fotosDe(oi).length ? 'border-emerald-300 bg-emerald-50/50'
                    : (itemsQueSeQuedan.includes(oi) ? 'border-gray-200 bg-white' : 'border-gray-200 bg-gray-50')]"
                >
                  <div class="flex items-start justify-between gap-2 mb-2">
                    <p class="text-sm font-semibold text-gray-800 leading-snug line-clamp-2 min-w-0">
                      {{ nombreItem(oi) }} <span class="font-normal text-gray-500">× {{ llevar[oi.id] }}</span>
                    </p>
                    <span v-if="!itemsQueSeQuedan.includes(oi)" class="chip bg-orange-100 text-orange-800">Se devuelve · opcional</span>
                    <span v-else-if="fotosDe(oi).length" class="chip bg-emerald-100 text-emerald-800">
                      <CheckCircleIcon class="w-3.5 h-3.5" /> {{ fotosDe(oi).length }} foto{{ fotosDe(oi).length === 1 ? '' : 's' }}
                    </span>
                    <span v-else class="chip bg-gray-100 text-gray-600">Falta</span>
                  </div>

                  <!-- Procesando: la foto grande tarda un momento -->
                  <p v-if="procesando[`p-${oi.id}`]" class="flex items-center justify-center gap-2 min-h-12 text-sm text-blue-700" aria-live="polite">
                    <span class="w-4 h-4 border-2 border-blue-300 border-t-blue-700 rounded-full animate-spin" aria-hidden="true" />
                    Procesando foto…
                  </p>

                  <!-- Sin fotos: un botón grande, fácil de atinar con el pulgar -->
                  <label
                    v-else-if="!fotosDe(oi).length"
                    class="relative flex items-center justify-center gap-2 min-h-12 rounded-xl border-2 border-dashed border-blue-300 bg-blue-50/60 text-blue-700 text-sm font-semibold cursor-pointer active:bg-blue-100 focus-within:ring-2 focus-within:ring-blue-500"
                  >
                    <input type="file" accept="image/*" multiple class="sr-only" @change="onFotoDeProducto(oi, $event)" />
                    <CameraIcon class="w-5 h-5" aria-hidden="true" />
                    <span>Tomar o elegir foto <span class="hidden sm:inline font-normal text-blue-600/80">· o arrástrala aquí</span></span>
                  </label>

                  <!-- Con fotos: las miniaturas y un cuadro para agregar otra -->
                  <div v-else class="grid grid-cols-3 gap-2">
                    <div v-for="(f, i) in fotosDe(oi)" :key="f.preview" class="relative aspect-square">
                      <img :src="f.preview" :alt="`Foto ${i + 1} de ${nombreItem(oi)}`" class="w-full h-full object-cover rounded-lg border border-gray-200" />
                      <button
                        type="button"
                        @click="quitarFotoDeProducto(oi, i)"
                        class="absolute top-1 right-1 w-8 h-8 flex items-center justify-center rounded-full bg-black/60 text-white"
                        :aria-label="`Quitar foto ${i + 1}`"
                      ><XMarkIcon class="w-4 h-4" aria-hidden="true" /></button>
                    </div>
                    <label
                      v-if="fotosDe(oi).length < MAX_FOTOS_POR_PRODUCTO"
                      class="relative aspect-square flex flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-gray-300 text-gray-500 text-xs font-medium cursor-pointer active:bg-gray-50 focus-within:ring-2 focus-within:ring-blue-500"
                    >
                      <input type="file" accept="image/*" multiple class="sr-only" @change="onFotoDeProducto(oi, $event)" />
                      <CameraIcon class="w-6 h-6" aria-hidden="true" />
                      Agregar
                    </label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Sección de pago — obligatoria en la última entrega con saldo;
                 en una parcial es un abono opcional -->
            <template v-if="tieneSaldo && !devuelveTodo">
              <div class="border-t border-gray-100 pt-5">
                <h4 class="paso-titulo mb-3">
                  <span class="paso-num">3</span>{{ esParcial ? 'Abono (opcional)' : 'Cobro' }}
                </h4>
                <template v-if="esParcial">
                  <label class="flex items-start gap-3 cursor-pointer mb-3 rounded-xl border border-gray-200 p-3">
                    <input type="checkbox" v-model="quiereAbonar" class="mt-0.5 w-5 h-5 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                    <span>
                      <span class="text-sm font-semibold text-gray-800">El cliente abona algo hoy</span>
                      <span class="block paso-ayuda">
                        Todavía le falta recibir parte del pedido. Debe <MoneyDisplay :amount="item.orden?.saldo_pendiente" />.
                      </span>
                    </span>
                  </label>
                </template>
                <div v-if="traePago" class="space-y-3">
                  <div>
                    <label for="ent-metodo" class="campo-label">Método de pago</label>
                    <select id="ent-metodo" v-model="metodo" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                      <option value="efectivo">Efectivo</option>
                      <option value="transferencia">Transferencia</option>
                      <option value="tarjeta">Tarjeta</option>
                      <option value="addi">Addi</option>
                      <option value="otro">Otro</option>
                    </select>
                  </div>

                  <!-- El descuento no aplica con este medio de pago -->
                  <div v-if="pierdeDescuento" class="bg-amber-50 border-2 border-amber-300 rounded-xl p-3 space-y-2">
                    <p class="text-sm font-bold text-amber-900">
                      Este pedido sube a <MoneyDisplay :amount="descuentoCond.valor_sin_descuento" />
                    </p>
                    <p class="text-sm text-amber-900 leading-snug">
                      {{ descuentoCond.explicacion }}
                    </p>
                    <div class="bg-white rounded-lg px-3 py-2">
                      <p class="text-xs text-gray-500">Debes cobrar</p>
                      <p class="text-lg font-bold text-amber-900">
                        <MoneyDisplay :amount="descuentoCond.saldo_sin_descuento" />
                      </p>
                    </div>
                    <p class="text-xs text-amber-700">
                      Muéstrale esta nota al cliente si pregunta por qué cambió el valor.
                      Si prefiere pagar en efectivo o transferencia, conserva el descuento.
                    </p>
                  </div>

                  <div>
                    <label for="ent-monto" class="campo-label">Monto cobrado</label>
                    <InputPesos
                      id="ent-monto" v-model="monto"
                      :disabled="pierdeDescuento"
                      :class="['w-full border rounded-lg px-3 py-2.5 text-base outline-none',
                        pierdeDescuento
                          ? 'border-amber-300 bg-amber-50 font-bold text-amber-900 cursor-not-allowed'
                          : 'border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500']"
                    />
                    <p v-if="pierdeDescuento" class="text-xs text-amber-700 mt-1">
                      No se puede cobrar menos: el descuento no aplica con este medio de pago.
                    </p>
                  </div>
                  <div>
                    <label for="ent-referencia" class="campo-label">Referencia (opcional)</label>
                    <input id="ent-referencia" v-model="referencia" autocomplete="off" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" />
                  </div>

                  <!-- Fotos del comprobante: una o varias; también arrastradas -->
                  <div
                    @dragover.prevent="arrastrando = 'pago'"
                    @dragleave.self="arrastrando = null"
                    @drop.prevent="alSoltar($event, agregarFotosPago)"
                    :class="['rounded-xl transition-colors', arrastrando === 'pago' ? 'ring-2 ring-blue-300 bg-blue-50 p-2 -m-2' : '']"
                  >
                    <p class="campo-label">
                      Foto del comprobante <span class="font-normal text-gray-500">· puedes subir varias</span>
                    </p>
                    <p v-if="procesando.pago" class="flex items-center justify-center gap-2 min-h-12 text-sm text-blue-700" aria-live="polite">
                      <span class="w-4 h-4 border-2 border-blue-300 border-t-blue-700 rounded-full animate-spin" aria-hidden="true" />
                      Procesando foto…
                    </p>
                    <label
                      v-else-if="!fotosPago.length"
                      class="relative flex items-center justify-center gap-2 min-h-12 rounded-xl border-2 border-dashed border-blue-300 bg-blue-50/60 text-blue-700 text-sm font-semibold cursor-pointer active:bg-blue-100 focus-within:ring-2 focus-within:ring-blue-500"
                    >
                      <input type="file" accept="image/*" multiple class="sr-only" @change="onFotoPago" />
                      <CameraIcon class="w-5 h-5" aria-hidden="true" />
                      <span>Foto o pantallazo del comprobante <span class="hidden sm:inline font-normal text-blue-600/80">· o arrástrala aquí</span></span>
                    </label>
                    <div v-else class="grid grid-cols-3 gap-2">
                      <div v-for="(f, i) in fotosPago" :key="f.preview" class="relative aspect-square">
                        <img :src="f.preview" :alt="`Comprobante ${i + 1}`" class="w-full h-full object-cover rounded-lg border border-gray-200" />
                        <button type="button" @click="quitarFotoPago(i)"
                          class="absolute top-1 right-1 w-8 h-8 flex items-center justify-center rounded-full bg-black/60 text-white"
                          :aria-label="`Quitar comprobante ${i + 1}`"><XMarkIcon class="w-4 h-4" aria-hidden="true" /></button>
                      </div>
                      <label
                        v-if="fotosPago.length < 6"
                        class="relative aspect-square flex flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-gray-300 text-gray-500 text-xs font-medium cursor-pointer active:bg-gray-50 focus-within:ring-2 focus-within:ring-blue-500"
                      >
                        <input type="file" accept="image/*" multiple class="sr-only" @change="onFotoPago" />
                        <CameraIcon class="w-6 h-6" aria-hidden="true" />
                        Agregar
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </template>

            <!-- Sin saldo: mensaje informativo -->
            <div v-else-if="!tieneSaldo" class="flex items-center gap-2 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 text-sm text-emerald-800 font-medium">
              <CheckCircleIcon class="w-5 h-5 shrink-0" />
              Esta orden ya está pagada: no hay nada que cobrar.
            </div>

            <!-- ═══════════ ACTA DE SATISFACCIÓN ═══════════ -->
            <div class="border-t border-gray-100 pt-5 space-y-4">
              <div class="space-y-1">
                <h4 class="paso-titulo">
                  <span class="paso-num">{{ tieneSaldo && !devuelveTodo ? 4 : 3 }}</span>Acta de satisfacción
                </h4>
                <p class="paso-ayuda">
                  Quien recibe firma que le llegó el producto y en qué estado.
                </p>
              </div>

              <template v-if="!noHayQuienFirme">
                <!-- Quién recibe -->
                <div class="space-y-2">
                  <div>
                    <label for="ent-recibe" class="campo-label">Nombre de quien recibe <span class="text-red-500">*</span></label>
                    <input
                      id="ent-recibe" v-model="recibidoNombre" autocomplete="name"
                      placeholder="Nombre completo"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                    />
                    <p class="paso-ayuda mt-1">
                      Si no recibe el cliente sino otra persona, escribe su nombre.
                    </p>
                  </div>
                  <div>
                    <label for="ent-cedula" class="campo-label">Cédula</label>
                    <input
                      id="ent-cedula" v-model="recibidoCedula" autocomplete="off"
                      inputmode="numeric"
                      placeholder="Número de cédula"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                    />
                  </div>
                </div>

                <!-- ¿Cómo llegó? -->
                <div>
                  <p class="campo-label">¿Cómo llegó el producto?</p>
                  <div class="grid grid-cols-2 gap-2">
                    <button
                      type="button" @click="conforme = true" :aria-pressed="conforme"
                      :class="['min-h-12 rounded-xl text-sm font-semibold border-2 transition-colors',
                        conforme ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-600 border-gray-300']"
                    >Llegó bien</button>
                    <button
                      type="button" @click="conforme = false" :aria-pressed="!conforme"
                      :class="['min-h-12 rounded-xl text-sm font-semibold border-2 transition-colors',
                        !conforme ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-gray-600 border-gray-300']"
                    >Con novedad</button>
                  </div>
                </div>

                <!-- Novedad -->
                <div v-if="!conforme" class="bg-amber-50 border border-amber-300 rounded-xl p-3 space-y-2">
                  <p class="text-xs font-semibold text-amber-900 flex items-center gap-1">
                    <ExclamationTriangleIcon class="w-4 h-4" />
                    ¿Qué pasó con el producto? <span class="text-red-500">*</span>
                  </p>
                  <textarea
                    v-model="observaciones"
                    rows="2"
                    placeholder="Ej. la mesa llegó rayada en una esquina"
                    class="w-full border border-amber-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-amber-500 outline-none"
                  />
                  <label
                    @dragover.prevent="arrastrando = 'novedad'" @dragleave.self="arrastrando = null" @drop.prevent="alSoltar($event, agregarFotoNovedad)"
                    :class="['relative flex items-center justify-center min-h-12 border-2 border-dashed rounded-xl p-2 text-center cursor-pointer', arrastrando === 'novedad' ? 'border-blue-500 bg-blue-50' : 'border-amber-300']">
                    <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoNovedad" />
                    <img v-if="fotoNovedadPreview" :src="fotoNovedadPreview" class="w-full h-24 object-cover rounded-lg" />
                    <span v-else class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-800"><CameraIcon class="w-5 h-5" aria-hidden="true" /> Foto de la novedad (opcional)</span>
                  </label>
                  <p class="text-xs text-amber-700">
                    Se avisa a supervisión apenas registres la entrega.
                  </p>
                </div>

                <!-- Firma -->
                <div>
                  <p class="campo-label">
                    Firma de quien recibe <span class="text-red-500">*</span>
                  </p>
                  <FirmaCanvas v-model="firmaBlob" />
                </div>
              </template>

              <!-- Nadie pudo firmar -->
              <div v-else class="bg-gray-50 border border-gray-300 rounded-xl p-3 space-y-2">
                <p class="text-xs font-semibold text-gray-700">¿Por qué no se pudo firmar?</p>
                <textarea
                  v-model="motivoSinFirma"
                  rows="2"
                  placeholder="Ej. se dejó con el vigilante del edificio, el cliente no estaba"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-gray-400 outline-none"
                />
                <p class="text-xs text-gray-500">
                  Queda registrado en la orden. Úsalo solo si de verdad no hay quien firme.
                </p>
              </div>

              <button
                type="button"
                @click="noHayQuienFirme = !noHayQuienFirme"
                class="min-h-11 px-1 text-sm font-medium text-gray-600 underline underline-offset-2"
              >
                {{ noHayQuienFirme ? '← Volver a la firma' : 'No hay quien firme' }}
              </button>
            </div>

            <!-- ── Foto del anexo firmado (opcional) ─────────────────────── -->
            <div v-if="!item.orden?.anexo_foto_url" class="border-t border-gray-100 pt-5 space-y-2">
              <div>
                <h4 class="text-sm font-semibold text-gray-800">
                  Anexo firmado <span class="font-normal text-gray-500">· opcional</span>
                </h4>
                <p class="paso-ayuda">Si el cliente firma el documento en la entrega, súbelo aquí.</p>
              </div>
              <div v-if="fotoAnexoPreview" class="relative">
                <img :src="fotoAnexoPreview" alt="Anexo firmado" class="w-full h-32 object-cover rounded-xl border border-gray-200" />
                <button
                  type="button" @click="quitarFotoAnexo"
                  class="absolute top-2 right-2 w-8 h-8 flex items-center justify-center rounded-full bg-black/60 text-white"
                  aria-label="Quitar anexo"
                ><XMarkIcon class="w-4 h-4" aria-hidden="true" /></button>
              </div>
              <label
                v-else
                @dragover.prevent="arrastrando = 'anexo'" @dragleave.self="arrastrando = null" @drop.prevent="alSoltar($event, agregarFotoAnexo)"
                :class="['relative flex items-center justify-center gap-2 min-h-12 rounded-xl border-2 border-dashed text-sm font-medium cursor-pointer active:bg-gray-50 focus-within:ring-2 focus-within:ring-blue-500', arrastrando === 'anexo' ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-300 text-gray-600']"
              >
                <input type="file" accept="image/*" class="sr-only" @change="onFotoAnexo" />
                <CameraIcon class="w-5 h-5" aria-hidden="true" /> Foto del anexo
              </label>
            </div>
            <div v-else class="border-t border-gray-100 pt-5">
              <h4 class="text-sm font-semibold text-gray-800 mb-2">Anexo firmado</h4>
              <a :href="item.orden.anexo_foto_url" target="_blank">
                <img :src="cloudinaryOpt(item.orden.anexo_foto_url, 600)" alt="Anexo firmado" class="w-full h-32 object-cover rounded-xl border border-gray-100" />
              </a>
            </div>

            <!-- ── Se devuelve en el camión ────────────────────────────────
                 Distinto de "con novedad": ahí el producto se queda en la casa
                 golpeado. Acá el cliente no se lo recibe y vuelve. -->
            <div class="border-t border-gray-200 pt-4 space-y-3">
              <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-gray-200 p-3">
                <input
                  type="checkbox"
                  v-model="hayDevolucion"
                  class="mt-0.5 w-5 h-5 shrink-0 rounded border-gray-300 text-orange-600 focus:ring-orange-500"
                />
                <span>
                  <span class="text-sm font-semibold text-gray-800">El cliente devuelve algo</span>
                  <span class="block text-xs text-gray-500">
                    El producto se regresa en el camión. Distinto de recibirlo con novedad y quedárselo.
                  </span>
                </span>
              </label>

              <div v-if="hayDevolucion" class="bg-orange-50 border border-orange-300 rounded-xl p-3 space-y-3">
                <p class="text-xs font-semibold text-orange-900">¿Qué se devuelve?</p>

                <div class="space-y-1.5">
                  <div
                    v-for="oi in itemsQueVan" :key="oi.id"
                    :class="['flex items-center gap-2 rounded-lg px-2 py-1.5 border transition-colors',
                      devueltos[oi.id] ? 'bg-white border-orange-400' : 'bg-white/60 border-transparent']"
                  >
                    <input
                      type="checkbox"
                      :checked="!!devueltos[oi.id]"
                      @change="alternarDevuelto(oi)"
                      class="rounded border-gray-300 text-orange-600 focus:ring-orange-500"
                    />
                    <span class="flex-1 min-w-0 text-xs text-gray-800 truncate">
                      {{ nombreItem(oi) }}
                      <span class="text-gray-400">({{ llevar[oi.id] }})</span>
                    </span>
                    <!-- De dos mesas puede volver una sola -->
                    <input
                      v-if="devueltos[oi.id] && llevar[oi.id] > 1"
                      v-model.number="devueltos[oi.id]"
                      type="number" min="1" :max="llevar[oi.id]"
                      class="w-16 h-10 border border-orange-300 rounded-lg px-1.5 text-base text-center focus:ring-2 focus:ring-orange-500 outline-none"
                    />
                  </div>
                </div>

                <div>
                  <p class="text-xs font-semibold text-orange-900 mb-1">
                    ¿Por qué se devuelve? <span class="text-red-500">*</span>
                  </p>
                  <textarea
                    v-model="motivoDevolucion"
                    rows="2"
                    placeholder="Ej. la cama llegó con la madera partida en el espaldar"
                    class="w-full border border-orange-300 rounded-lg px-3 py-2.5 text-base focus:ring-2 focus:ring-orange-500 outline-none"
                  />
                </div>

                <!-- Las dos (tres) salidas se le ofrecen al cliente ahí mismo;
                     lo que escoja queda anotado y el supervisor decide. -->
                <div>
                  <p class="text-xs font-semibold text-orange-900 mb-1">¿Qué prefiere el cliente?</p>
                  <div class="space-y-1">
                    <label
                      v-for="op in PREFERENCIAS" :key="op.v"
                      :class="['relative flex items-start gap-2 rounded-lg px-2 py-1.5 border cursor-pointer',
                        preferencia === op.v ? 'bg-white border-orange-400' : 'bg-white/60 border-transparent']"
                    >
                      <input type="radio" :value="op.v" v-model="preferencia" class="mt-0.5 text-orange-600 focus:ring-orange-500" />
                      <span class="min-w-0">
                        <span class="block text-xs font-semibold text-gray-800">{{ op.t }}</span>
                        <span class="block text-xs text-gray-500 leading-snug">{{ op.d }}</span>
                      </span>
                    </label>
                    <label :class="['relative flex items-center gap-2 rounded-lg px-2 py-1.5 border cursor-pointer', preferencia === '' ? 'bg-white border-orange-400' : 'bg-white/60 border-transparent']">
                      <input type="radio" value="" v-model="preferencia" class="text-orange-600 focus:ring-orange-500" />
                      <span class="text-xs text-gray-600">No sabe todavía — que decida producción</span>
                    </label>
                  </div>
                </div>

                <label
                  @dragover.prevent="arrastrando = 'devolucion'" @dragleave.self="arrastrando = null" @drop.prevent="alSoltar($event, agregarFotoDevolucion)"
                  :class="['relative flex items-center justify-center min-h-12 border-2 border-dashed rounded-xl p-2 text-center cursor-pointer', arrastrando === 'devolucion' ? 'border-blue-500 bg-blue-50' : 'border-orange-300']">
                  <input type="file" accept="image/*" capture="environment" class="sr-only" @change="onFotoDevolucion" />
                  <img v-if="fotoDevolucionPreview" :src="fotoDevolucionPreview" alt="Foto del daño" class="w-full h-24 object-cover rounded-lg" />
                  <span v-else class="inline-flex items-center gap-1.5 text-sm font-medium text-orange-800"><CameraIcon class="w-5 h-5" aria-hidden="true" /> Foto del daño (recomendada)</span>
                </label>

                <p v-if="devuelveTodo" class="text-xs text-orange-900 bg-orange-100 rounded-lg px-2 py-1.5">
                  Vuelve todo, así que no se le cobra nada al cliente. La orden queda esperando que
                  producción decida si se arregla o se cancela.
                </p>
                <p v-else class="text-xs text-orange-800">
                  Se cobra solo lo que el cliente se queda. Producción decide después qué se hace con
                  lo que vuelve.
                </p>
              </div>
            </div>

          </template>

        </div>
      </template>
      </div>

      <!-- La acción, siempre a la vista: en el teléfono el formulario es
           largo, y aquí se ve qué falta sin tener que bajar hasta el final. -->
      <div
        v-if="item && !cargando && !esEntregado"
        class="shrink-0 border-t border-gray-100 bg-white px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
      >
        <!-- La zona existe siempre: si se creara junto con el texto, el
             lector de pantalla no anunciaría el cambio. -->
        <div aria-live="polite">
          <p v-if="mensajeBoton && !registrando" class="text-[13px] text-gray-600 text-center mb-2 leading-snug">
            {{ mensajeBoton }}
          </p>
        </div>
        <button
          @click="guardarPagoYEntregar"
          :disabled="!puedeEntregar || registrando"
          class="w-full min-h-12 py-3 rounded-xl text-base font-bold transition-colors"
          :class="puedeEntregar && !registrando
            ? 'bg-emerald-600 text-white hover:bg-emerald-700 active:bg-emerald-800 shadow-md'
            : 'bg-gray-200 text-gray-500 cursor-not-allowed'"
        >
          <template v-if="registrando">Guardando la entrega…</template>
          <template v-else-if="esParcial">Entregar {{ lineas.length }} de {{ itemsOrden.length }} productos</template>
          <template v-else>Marcar como entregado</template>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* La altura del panel: 94% de la pantalla visible. `dvh` descuenta la barra
   del navegador del teléfono; los navegadores de antes de 2022 no la
   entienden y con solo ella el panel se quedaba sin tope de alto. */
.panel-alto {
  max-height: 94vh;
  max-height: 94dvh;
}
/* El panel no se desplaza nunca: lo que se desplaza es la zona de adentro.
   Con `hidden` el navegador igual lo corría para mostrar un campo enfocado
   (el de la foto al volver de la cámara) y el modal quedaba sin título y con
   un hueco blanco abajo. `clip` no deja moverlo; `hidden` queda de respaldo
   para navegadores viejos, que además lo devuelven a su sitio (@scroll). */
.panel-fijo {
  overflow: hidden;
  overflow: clip;
}
@media (min-width: 640px) {
  .panel-alto { max-height: 90vh; }
}
/* Los pasos del formulario: un número y un título que se leen de un vistazo
   en el teléfono, en la puerta del cliente. */
.paso-titulo {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}
.paso-num {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 9999px;
  background: #047857;
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
}
.paso-ayuda {
  font-size: 0.8125rem;
  line-height: 1.35;
  color: #4b5563;
}
.campo-label {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
}
.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  flex-shrink: 0;
  white-space: nowrap;
  border-radius: 9999px;
  padding: 0.125rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
}
</style>
