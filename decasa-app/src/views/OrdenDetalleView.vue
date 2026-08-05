<script setup>
import IconoS from '@/components/common/IconoS.vue'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { getOrden, updateEstado, revertirEntrega, descargarPdfOrden, descargarActaEntrega, reenviarCotizacion, asignarFechasEntrega, confirmarCotizacion, editarPago, completarBorrador as completarBorradorApi, eliminarBorrador as eliminarBorradorApi } from '@/api/ordenes'
import { updateCliente } from '@/api/clientes'
import { despachoPorOrden } from '@/api/despacho'
import { tomarFacturacion, marcarFacturada } from '@/api/pagos'
import { getReceptores, crearConsulta, getConsultas, ajustarPrecio as ajustarPrecioApi } from '@/api/consultas'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import RegistroPagoModal from '@/components/ordenes/RegistroPagoModal.vue'
import EditarOrdenModal from '@/components/ordenes/EditarOrdenModal.vue'
import ChatOrden from '@/components/ordenes/ChatOrden.vue'
// Se usaba sin importar: mientras cargaba la orden no se veía nada
import AppSpinner from '@/components/common/AppSpinner.vue'
import { SparklesIcon, XMarkIcon } from '@heroicons/vue/24/solid'
import { DocumentIcon, EnvelopeIcon, ChatBubbleLeftEllipsisIcon, ArrowDownTrayIcon, CalendarIcon, BuildingOffice2Icon, TruckIcon, PencilSquareIcon, ClockIcon, CheckBadgeIcon, LockClosedIcon, WrenchScrewdriverIcon, CheckCircleIcon, UserGroupIcon, CurrencyDollarIcon, BanknotesIcon, ExclamationTriangleIcon, SwatchIcon } from '@heroicons/vue/24/outline'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import DireccionColombia from '@/components/DireccionColombia.vue'
import TelaPicker from '@/components/ordenes/TelaPicker.vue'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { pctDeMonto, formatPct } from '@/utils/descuentos'
import { PhotoIcon } from '@heroicons/vue/24/outline'
import { SPECS_TEMPLATES, resolverCategoria } from '@/constants/specsConfig'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const orden = ref(null)
const loading = ref(true)
const verFactura = ref(false)
const bocetoModal = ref('')
const error = ref('')
const showPagoModal   = ref(false)
const showEditarModal = ref(false)
const changingEstado  = ref(false)
const estadoError = ref('')
const enviandoEmail = ref(false)
const emailManual = ref('')
const mostrarEmailManual = ref(false)

const fechasEdicion = ref({})

/** Copia la fecha que el vendedor prometió a todos los ítems de la orden. */
function usarFechaSugerida() {
  const f = String(orden.value?.fecha_sugerida_vendedor ?? '').substring(0, 10)
  if (!f) return
  for (const item of orden.value?.items ?? []) {
    fechasEdicion.value[item.id] = f
  }
}
const guardandoFechas = ref(false)

const despachoEntrega = ref(null)
const cargandoDespacho = ref(false)

const pruebaEntregaVisible = computed(() =>
  orden.value?.estado === 'entregado' && despachoEntrega.value
)

const puedeEditar = computed(() => {
  if (!orden.value) return false
  if (['entregado', 'cancelado', 'listo_entrega', 'en_camino'].includes(orden.value.estado)) return false
  if ((auth.usuario?.rol === 'vendedor' || auth.isEbanista) && Number(orden.value.vendedor_id) !== Number(auth.usuario.id)) return false
  return true
})

const todasFechasAsignadas = computed(() =>
  (orden.value?.items?.length ?? 0) > 0 && (orden.value?.items?.every(i => i.fecha_entrega_prom) ?? false)
)

// 'entregado' directo: el cliente se llevó el producto de la tienda al pagar y
// no se marcó entrega inmediata al hacer la orden. No pasa por despacho porque
// nadie lo transportó, pero descuenta inventario igual.
const transicionesValidas = {
  borrador: ['cancelado'],
  pendiente_cotizacion: ['cancelado'],
  pendiente_anticipo: ['en_produccion', 'listo_entrega', 'entregado', 'cancelado'],
  en_produccion: ['listo_entrega', 'entregado', 'cancelado'],
  listo_entrega: [],
  en_camino: [],
  entregado: [],
  cancelado: [],
}

const nuevoEstado = ref('')

const estadosLabel = {
  pendiente_cotizacion: 'Pendiente costo',
  pendiente_anticipo: 'En espera',
  en_produccion: 'En producción',
  listo_entrega: 'Listo entrega',
  entregado: 'Entregado',
  cancelado: 'Cancelado',
}

const porcentajePagado = computed(() => {
  if (!orden.value || !orden.value.valor_total) return 0
  return Math.min(100, Math.round((orden.value.total_pagado / orden.value.valor_total) * 100))
})

// ── Corregir el medio de un pago ─────────────────────────────────────────────
// Si un pago quedó como efectivo cuando fue transferencia, la caja de la tienda
// muestra plata que no está. Se puede arreglar en cualquier estado de la orden
// porque cambiar el medio no mueve montos ni saldos.
// El vendedor puede corregir sus propios pagos: equivocarse al elegir el medio
// es normal y no debe depender de que un supervisor esté disponible. Lo que no
// se puede es negarlo después: cada cambio queda firmado con nombre y hora.
const puedeCorregirMedio = computed(() => {
  if (!orden.value) return false
  if (auth.isSupervisor || auth.isFacturador) return true
  return Number(orden.value.vendedor_id) === Number(auth.usuario?.id)
})

/** Si este pago ya fue corregido, quién lo hizo y cuándo. */
function correccionDe(pago) {
  const ediciones = orden.value?.ediciones ?? []
  for (const e of ediciones) {
    const cambio = (e.cambios ?? []).find(c => c.campo === `pago_${pago.id}_metodo`)
    if (cambio) {
      return { usuario: e.usuario?.nombre ?? 'alguien', fecha: e.created_at, ...cambio }
    }
  }
  return null
}

const pagoCorrigiendo = ref(null)
const medioNuevo      = ref('efectivo')
const referenciaNueva = ref('')
const guardandoMedio  = ref(false)

function abrirCorregirMedio(pago) {
  pagoCorrigiendo.value = pago
  medioNuevo.value      = pago.metodo ?? 'efectivo'
  referenciaNueva.value = pago.referencia ?? ''
}

async function guardarMedio() {
  if (!pagoCorrigiendo.value) return
  guardandoMedio.value = true
  try {
    await editarPago(pagoCorrigiendo.value.id, {
      monto:      Number(pagoCorrigiendo.value.monto),   // igual: solo cambia el medio
      metodo:     medioNuevo.value,
      referencia: referenciaNueva.value || null,
    })
    toast.success('Medio de pago corregido. La caja se ajusta sola.')
    pagoCorrigiendo.value = null
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo corregir el medio de pago.')
  } finally {
    guardandoMedio.value = false
  }
}

// Subtotal antes de descuentos: se reconstruye sumándolos de vuelta, porque lo
// que se guarda es el monto rebajado.
const subtotalBruto = computed(() => {
  if (!orden.value) return 0
  return Number(orden.value.valor_total || 0)
    + Number(orden.value.descuento_total || 0)
    + Number(orden.value.descuento_condicionado || 0)
})

const puedeCambiarEstado = computed(() => {
  if (!orden.value) return false
  if (!auth.isSupervisor) return false
  if (['entregado', 'cancelado', 'listo_entrega', 'en_camino'].includes(orden.value.estado)) return false
  if (tienePersonalizados.value) return false
  return true
})

const tienePersonalizados = computed(() =>
  orden.value?.items?.some(i => i.es_personalizado) ?? false
)

const esBorrador = computed(() => orden.value?.estado === 'borrador')

/** Borrador que se canceló: sin consecutivo, sin serie y sin un peso cobrado. */
const esBorradorCancelado = computed(() =>
  orden.value?.estado === 'cancelado'
  && !orden.value?.numero_orden
  && !orden.value?.serie
  && !orden.value?.cotizacion_numero
  && !(orden.value?.pagos ?? []).length
)

// ── Completar borrador ────────────────────────────────────────────────────────
const showCompletarBorradorModal = ref(false)
const completandoBorrador        = ref(false)
const borradorFirmaBlob          = ref(null)
const borradorFirmaUrl           = ref('')
const borradorAnticipoPct        = computed(() => orden.value?.anticipo_pct ?? 50)
const borradorAnticipoMinimo     = computed(() =>
  Math.ceil((orden.value?.valor_total ?? 0) * borradorAnticipoPct.value / 100)
)
const borradorTieneItemsCotiz    = computed(() =>
  orden.value?.items?.some(i => i.es_personalizado && i.precio_unitario == 0) ?? false
)
const borradorForm = ref({
  anticipo_monto:      0,
  anticipo_metodo:     'efectivo',
  anticipo_referencia: '',
  notas:               '',
  departamento_envio:  '',
  ciudad_envio:        '',
  direccion_envio:     '',
})
const borradorPagoSplit    = ref(false)
const borradorMonto1Input  = ref(0)
const borradorMetodo2      = ref('transferencia')
const borradorRef2         = ref('')

function toggleBorradorPagoSplit() {
  borradorPagoSplit.value = !borradorPagoSplit.value
  if (borradorPagoSplit.value) {
    borradorMonto1Input.value = Math.floor(borradorForm.value.anticipo_monto / 2)
    borradorMetodo2.value     = borradorForm.value.anticipo_metodo === 'efectivo' ? 'transferencia' : 'efectivo'
  }
}

// ── Especificaciones que faltan en los personalizados ────────────────────────
// Se pueden empezar a vender sin medidas, pero al completar hay que darlas: si
// no, el ebanista recibe una orden de producción de un mueble que no sabe hacer.
const borradorSpecs = ref({})   // { [itemId]: { campo: valor } }
const borradorTelas = ref({})   // { [itemId]: { campo: { marca, tipo, color } } }

/** Selección de tela de un campo (se preparan todas al abrir el modal). */
function telaDe(itemId, key) {
  return borradorTelas.value[itemId]?.[key] ?? { marca: '', tipo: '', color: '' }
}

function telaResumida(itemId, key) {
  const s = borradorTelas.value[itemId]?.[key]
  return s?.marca && s?.tipo && s?.color ? [s.marca, s.tipo, s.color].join(' · ') : ''
}

function templateDeItem(item) {
  const key = resolverCategoria(
    item.producto?.nombre ?? item.nombre_custom,
    item.producto?.categoria ?? item.categoria_custom,
  )
  return SPECS_TEMPLATES[key] ?? SPECS_TEMPLATES['generico']
}

function specsVacias(item) {
  const s = item.specs_personalizacion
  if (!s) return true
  return !Object.values(s).some(v => v !== null && v !== '' && !(Array.isArray(v) && !v.length))
}

/** Personalizados del borrador que todavía no tienen ninguna especificación. */
const borradorItemsSinSpecs = computed(() =>
  (orden.value?.items ?? []).filter(i => i.es_personalizado && specsVacias(i))
)

/** Specs de un ítem ya consolidadas: los campos escritos más las telas elegidas. */
function specsDeItem(itemId) {
  const crudo = borradorSpecs.value[itemId] ?? {}
  const specs = {}
  for (const [k, v] of Object.entries(crudo)) {
    if (k === 'notas') continue
    if (v !== null && String(v).trim() !== '') specs[k] = String(v).trim()
  }
  for (const key of Object.keys(borradorTelas.value[itemId] ?? {})) {
    const tela = telaResumida(itemId, key)
    if (tela) specs[key] = tela
  }
  return specs
}

/** Al menos un campo lleno por ítem: es lo mínimo que el backend acepta. */
const borradorSpecsCompletas = computed(() =>
  borradorItemsSinSpecs.value.every(i => Object.keys(specsDeItem(i.id)).length > 0)
)

const borradorEspecificacionesPayload = computed(() =>
  borradorItemsSinSpecs.value.map(i => ({
    item_id: i.id,
    specs:   specsDeItem(i.id),
    notas:   (borradorSpecs.value[i.id]?.notas || '').trim() || undefined,
  }))
)

// Archivos del modal completar
const borradorComprobanteFile    = ref(null)
const borradorComprobanteUrl     = ref('')
const borradorComprobantePreview = ref('')
const borradorAnexoFile          = ref(null)
const borradorAnexoUrl           = ref('')
const borradorAnexoPreview       = ref('')
const subiendoComprobante        = ref(false)
const subiendoAnexo              = ref(false)

// ── Datos cliente interesado dentro del modal ─────────────────────────────────
const borradorClienteRequiereCompletar = computed(() => {
  const c = orden.value?.cliente
  if (!c) return false
  return c.tipo === 'interesado' || !c.cedula || !c.telefono || !c.direccion
})
const borradorFormCliente    = ref({ nombre: '', cedula: '', telefono: '', email: '', direccion: '' })
const borradorClienteGuardando = ref(false)
const borradorClienteErr       = ref('')

async function completarClienteBorrador() {
  borradorClienteErr.value = ''
  const f = borradorFormCliente.value
  if (!f.nombre.trim())    { borradorClienteErr.value = 'El nombre es obligatorio.';    return }
  if (!f.cedula.trim())    { borradorClienteErr.value = 'La cédula es obligatoria.';    return }
  if (!f.telefono.trim())  { borradorClienteErr.value = 'El teléfono es obligatorio.';  return }
  if (!f.direccion.trim()) { borradorClienteErr.value = 'La dirección es obligatoria.'; return }
  borradorClienteGuardando.value = true
  try {
    const payload = {
      tipo:      'oficial',
      nombre:    f.nombre.trim(),
      cedula:    f.cedula.trim(),
      telefono:  f.telefono.trim(),
      email:     f.email.trim() || null,
      direccion: f.direccion.trim(),
    }
    await updateCliente(orden.value.cliente.id, payload)
    orden.value.cliente = { ...orden.value.cliente, ...payload }
  } catch (e) {
    borradorClienteErr.value = e.response?.data?.message ?? 'Error al actualizar el cliente'
  } finally {
    borradorClienteGuardando.value = false
  }
}

watch(showCompletarBorradorModal, (open) => {
  if (open) {
    // Pre-llenar con lo que ya tiene la orden guardada en el borrador
    borradorFirmaBlob.value          = null
    borradorFirmaUrl.value           = orden.value?.firma_url          ?? ''
    borradorComprobanteFile.value    = null
    borradorComprobanteUrl.value     = orden.value?.factura_foto_url   ?? ''
    borradorComprobantePreview.value = ''
    borradorAnexoFile.value          = null
    borradorAnexoUrl.value           = orden.value?.anexo_foto_url     ?? ''
    borradorAnexoPreview.value       = ''
    borradorClienteErr.value         = ''
    borradorSpecs.value = Object.fromEntries(
      borradorItemsSinSpecs.value.map(i => [i.id, {}])
    )
    // Las selecciones de tela se crean aquí, no durante el render: crearlas al
    // vuelo dentro del template obliga a Vue a re-renderizar en mitad del pintado.
    borradorTelas.value = Object.fromEntries(
      borradorItemsSinSpecs.value.map(i => [
        i.id,
        Object.fromEntries(
          templateDeItem(i).campos
            .filter(c => c.useVariantes)
            .map(c => [c.key, { marca: '', tipo: '', color: '' }])
        ),
      ])
    )
    borradorFormCliente.value = {
      nombre:    orden.value?.cliente?.nombre    || '',
      cedula:    orden.value?.cliente?.cedula    || '',
      telefono:  orden.value?.cliente?.telefono  || '',
      email:     orden.value?.cliente?.email     || '',
      direccion: orden.value?.cliente?.direccion || '',
    }
    borradorForm.value = {
      anticipo_monto:      borradorTieneItemsCotiz.value ? 0 : borradorAnticipoMinimo.value,
      anticipo_metodo:     'efectivo',
      anticipo_referencia: '',
      notas:               orden.value?.notas ?? '',
      departamento_envio:  orden.value?.departamento_envio ?? '',
      ciudad_envio:        orden.value?.ciudad_envio ?? '',
      direccion_envio:     orden.value?.direccion_envio ?? '',
    }
  }
})

function onBorradorComprobanteChange(e) {
  const file = e.target.files[0]
  if (!file) return
  borradorComprobanteFile.value    = file
  borradorComprobanteUrl.value     = ''
  borradorComprobantePreview.value = URL.createObjectURL(file)
}

function onBorradorAnexoChange(e) {
  const file = e.target.files[0]
  if (!file) return
  borradorAnexoFile.value    = file
  borradorAnexoUrl.value     = ''
  borradorAnexoPreview.value = URL.createObjectURL(file)
}

async function completarBorrador() {
  completandoBorrador.value = true
  try {
    if (borradorFirmaBlob.value && !borradorFirmaUrl.value) {
      const fd = new FormData()
      fd.append('foto', borradorFirmaBlob.value, 'firma.png')
      fd.append('folder', 'firmas')
      const token = localStorage.getItem('token')
      const res = await fetch('/api/upload/foto', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: fd,
      })
      const uploadData = await res.json()
      borradorFirmaUrl.value = uploadData.url
    }
    // Subir comprobante de pago
    if (borradorComprobanteFile.value && !borradorComprobanteUrl.value) {
      subiendoComprobante.value = true
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(borradorComprobanteFile.value), 'comprobante.jpg')
      fd.append('folder', 'facturas')
      const token = localStorage.getItem('token')
      const res = await fetch('/api/upload/foto', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: fd,
      })
      const uploadData = await res.json()
      borradorComprobanteUrl.value = uploadData.url
      subiendoComprobante.value = false
    }

    // Subir foto del anexo firmado
    if (borradorAnexoFile.value && !borradorAnexoUrl.value) {
      subiendoAnexo.value = true
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(borradorAnexoFile.value), 'anexo.jpg')
      fd.append('folder', 'facturas')
      const token = localStorage.getItem('token')
      const res = await fetch('/api/upload/foto', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: fd,
      })
      const uploadData = await res.json()
      borradorAnexoUrl.value = uploadData.url
      subiendoAnexo.value = false
    }

    const { data } = await completarBorradorApi(orden.value.id, {
      firma_url:           borradorFirmaUrl.value || undefined,
      anticipo_monto:      borradorForm.value.anticipo_monto,
      anticipo_metodo:     borradorForm.value.anticipo_metodo,
      anticipo_referencia: borradorPagoSplit.value ? undefined : (borradorForm.value.anticipo_referencia || undefined),
      ...(borradorPagoSplit.value && borradorForm.value.anticipo_monto > 0 ? {
        anticipo_pagos: [
          { monto: borradorMonto1Input.value,                                                                   metodo: borradorForm.value.anticipo_metodo, referencia: borradorForm.value.anticipo_referencia || undefined },
          { monto: Math.max(0, borradorForm.value.anticipo_monto - borradorMonto1Input.value), metodo: borradorMetodo2.value,              referencia: borradorRef2.value || undefined },
        ].filter(p => p.monto > 0)
      } : {}),
      notas:               borradorForm.value.notas || undefined,
      factura_foto_url:    borradorComprobanteUrl.value || undefined,
      anexo_foto_url:      borradorAnexoUrl.value || undefined,
      departamento_envio:  borradorForm.value.departamento_envio || undefined,
      ciudad_envio:        borradorForm.value.ciudad_envio || undefined,
      direccion_envio:     borradorForm.value.direccion_envio || undefined,
      especificaciones:    borradorEspecificacionesPayload.value.length
        ? borradorEspecificacionesPayload.value
        : undefined,
    })
    orden.value = data
    showCompletarBorradorModal.value = false
    toast.success('Orden confirmada correctamente.')
  } catch (e) {
    const errores = e.response?.data?.errors
    const detalle = errores ? ' · ' + Object.values(errores).flat().join(', ') : ''
    toast.error((e.response?.data?.message ?? 'Error al confirmar la orden') + detalle)
  } finally {
    completandoBorrador.value    = false
    subiendoComprobante.value    = false
    subiendoAnexo.value          = false
  }
}

const puedeRegistrarPago = computed(() => {
  if (!orden.value) return false
  // 'entregado' se permite para cobrar el saldo residual de una venta directa.
  if (['cancelado', 'borrador'].includes(orden.value.estado)) return false
  if (orden.value.saldo_pendiente <= 0) return false
  if (auth.isEbanista && Number(orden.value.vendedor_id) !== Number(auth.usuario?.id)) return false
  return true
})

const opcionesNuevoEstado = computed(() => {
  if (!orden.value) return []
  return (transicionesValidas[orden.value.estado] ?? [])
    .filter((e) => {
      if (e === 'en_produccion' && !tienePersonalizados.value) return false
      if (e === 'listo_entrega' && tienePersonalizados.value && orden.value.estado === 'pendiente_anticipo') return false
      // Una pieza que todavía se está fabricando no se pudo haber llevado
      if (e === 'entregado' && tienePersonalizados.value) return false
      return true
    })
    .map((e) => ({
      value: e,
      // Se aclara porque no es la entrega normal: aquí no hubo transporte
      label: e === 'entregado' ? 'Entregado — se lo llevó de la tienda' : (estadosLabel[e] ?? e),
    }))
})

async function cargarOrden() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getOrden(route.params.id)
    orden.value = data
    nuevoEstado.value = ''
    estadoError.value = ''
    const edicion = {}
    for (const item of data.items ?? []) {
      edicion[item.id] = item.fecha_entrega_prom
        ? String(item.fecha_entrega_prom).substring(0, 10)
        : ''
    }
    fechasEdicion.value = edicion

    if (data.estado === 'entregado') {
      cargarDespachoEntrega(data.id)
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar la orden.'
  } finally {
    loading.value = false
  }

  // Cargar consulta activa después de mostrar la orden (no bloquea el spinner)
  if (orden.value?.items?.some(i => i.es_personalizado)) {
    cargandoConsulta.value = true
    try {
      const r = await getConsultas()
      consultaActiva.value = (r.data ?? []).find(c => c.orden_id === orden.value.id) ?? null
    } catch {
      consultaActiva.value = null
    } finally {
      cargandoConsulta.value = false
    }
  }
}

async function cargarDespachoEntrega(ordenId) {
  try {
    cargandoDespacho.value = true
    const { data } = await despachoPorOrden(ordenId)
    despachoEntrega.value = data
  } catch {
    despachoEntrega.value = null
  } finally {
    cargandoDespacho.value = false
  }
}

// ── Revertir una entrega marcada por error ──────────────────────────────────
// Devuelve el producto al inventario. No aplica a las que entregó un conductor:
// esas tienen acta firmada y se corrigen desde Despacho.
const revirtiendo   = ref(false)
const motivoRevertir = ref('')
const showRevertir  = ref(false)

async function doRevertirEntrega() {
  if (motivoRevertir.value.trim().length < 3) {
    toast.error('Escribe por qué se revierte.')
    return
  }
  revirtiendo.value = true
  try {
    await revertirEntrega(orden.value.id, motivoRevertir.value.trim())
    showRevertir.value = false
    motivoRevertir.value = ''
    await cargarOrden()
    toast.success('Entrega revertida. El producto volvió al inventario.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo revertir la entrega.')
  } finally {
    revirtiendo.value = false
  }
}

async function cambiarEstado() {
  if (!nuevoEstado.value) return

  // Marcar entregado descuenta inventario y cierra la orden. Si además queda
  // saldo, significa que se lo llevaron debiendo: vale confirmarlo.
  if (nuevoEstado.value === 'entregado') {
    const saldo = Number(orden.value?.saldo_pendiente) || 0
    const aviso = saldo > 0
      ? `Esta orden queda con un saldo de $${saldo.toLocaleString('es-CO')}.\n\n`
        + '¿El cliente ya se llevó el producto? Se descuenta del inventario y la orden se cierra.'
      : '¿El cliente ya se llevó el producto? Se descuenta del inventario y la orden se cierra.'
    if (!confirm(aviso)) return
  }

  changingEstado.value = true
  try {
    await updateEstado(orden.value.id, nuevoEstado.value)
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al cambiar el estado.')
  } finally {
    changingEstado.value = false
  }
}

// Un borrador no se cancela: se descarta. Nunca fue una venta, así que dejarlo
// como "cancelado" en la lista solo ensucia. El backend libera lo reservado.
const descartandoBorrador = ref(false)
const showDescartarBorrador = ref(false)

async function descartarBorrador() {
  descartandoBorrador.value = true
  try {
    await eliminarBorradorApi(orden.value.id)
    showDescartarBorrador.value = false
    toast.success('Borrador descartado. Los productos volvieron al inventario.')
    router.push({ name: 'ordenes' })
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo descartar el borrador')
  } finally {
    descartandoBorrador.value = false
  }
}

function onPagoRegistrado() {
  cargarOrden()
}

function onOrdenEditada(ordenActualizada) {
  orden.value = ordenActualizada
}

// Etiquetas legibles para claves de specs_personalizacion que no vienen de un
// campo con `label` propio (ej. objetos guardados antes de tener template).
const ETIQUETAS_SPEC = {
  marca: 'Marca', tela: 'Tela', color: 'Color', medidas: 'Medidas',
  acabado: 'Acabado', descripcion: 'Descripción', notas: 'Notas',
  material: 'Material', color_material: 'Color/acabado',
  largo_cm: 'Largo', ancho_cm: 'Ancho', alto_cm: 'Alto',
  variante_marca: 'Marca', variante_color: 'Color',
}

function formatCambioVal(val) {
  if (val === null || val === undefined || val === '') return '—'
  if (typeof val === 'object') {
    const parts = Object.entries(val)
      .filter(([, v]) => v !== null && v !== undefined && v !== '')
      .map(([k, v]) => {
        const label = ETIQUETAS_SPEC[k] ?? k
        return `${label}: ${v}`
      })
    return parts.length ? parts.join(' · ') : '—'
  }
  if (typeof val === 'number') return new Intl.NumberFormat('es-CO').format(val)
  return String(val)
}

/** ¿El cambio es entre dos objetos de especificaciones? */
function esCambioDeObjeto(cambio) {
  const esObj = v => v && typeof v === 'object' && !Array.isArray(v)
  return esObj(cambio.antes) || esObj(cambio.despues)
}

/**
 * De un cambio de especificaciones saca SOLO los campos que cambiaron.
 *
 * Antes se pintaba el objeto completo de los dos lados: corregir una palabra
 * en las notas mostraba el párrafo entero repetido, y no había forma de ver
 * qué se había tocado.
 */
function detalleCambio(cambio) {
  if (!esCambioDeObjeto(cambio)) return []

  const antes   = cambio.antes   ?? {}
  const despues = cambio.despues ?? {}
  const vacio   = v => v === null || v === undefined || v === ''
  const texto   = v => (vacio(v) ? '—' : String(v))

  return [...new Set([...Object.keys(antes), ...Object.keys(despues)])]
    .filter(k => texto(antes[k]) !== texto(despues[k]))
    .map(k => {
      const a = texto(antes[k])
      const d = texto(despues[k])
      return {
        label: ETIQUETAS_SPEC[k] ?? k,
        antes: a,
        despues: d,
        // Los textos largos (notas) van uno debajo del otro; en la misma línea
        // no se alcanza a leer dónde está la diferencia.
        largo: a.length > 45 || d.length > 45,
      }
    })
}

// Lista de specs a mostrar en la tarjeta del ítem, según el template de su
// categoría (mismo que usa NuevaOrdenView al crear) — no un esquema fijo.
function specsResumen(item) {
  const specs = item?.specs_personalizacion
  if (!specs) return []
  const nombre   = item.producto?.nombre || item.nombre_custom
  const cat      = item.producto?.categoria || item.categoria_custom
  const template = SPECS_TEMPLATES[resolverCategoria(nombre, cat)] ?? SPECS_TEMPLATES['generico']
  const vistos   = new Set()
  const partes   = []

  for (const campo of template.campos) {
    const val = specs[campo.key]
    if (val === null || val === undefined || val === '') continue
    vistos.add(campo.key)
    // Sin unidad: el valor guardado no siempre está en la unidad del template
    // (a veces se digita en metros aunque el campo se llame "_cm").
    partes.push({ label: campo.label, value: val })
  }
  // Campos guardados que no están en el template de esta categoría
  // (ej. specs de una variante distinta, o guardadas antes de tener template).
  for (const [key, val] of Object.entries(specs)) {
    if (vistos.has(key) || key === 'notas') continue
    if (val === null || val === undefined || val === '') continue
    partes.push({ label: ETIQUETAS_SPEC[key] ?? key, value: val })
  }
  if (specs.notas) partes.push({ label: 'Notas', value: specs.notas })
  return partes
}

// Nombre de la tienda de la que se descuenta el stock de un ítem de inventario.
// Se muestra como "Inventario {tienda}" (ej. "Inventario Decasa Vía Edén").
// Aplica a productos de catálogo y a personalizados que se llevan una unidad
// física de la tienda (usa_stock_tienda). Los personalizados a producción no.
function origenInventario(item) {
  if (item.es_personalizado && !item.usa_stock_tienda) return null
  return item.tienda_origen?.nombre ?? orden.value?.tienda?.nombre ?? null
}

// El PDF lo arma el servidor y puede tardar varios segundos. Sin aviso, el
// usuario vuelve a tocar y se generan dos.
const descargandoPdf  = ref(false)
const descargandoActa = ref(false)

async function descargarPdf() {
  if (descargandoPdf.value) return
  descargandoPdf.value = true
  try {
    const response = await descargarPdfOrden(orden.value.id)
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const url = window.URL.createObjectURL(blob)
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 5000)
  } catch (e) {
    error.value = 'Error al descargar el PDF.'
  } finally {
    descargandoPdf.value = false
  }
}

async function descargarActa() {
  if (descargandoActa.value) return
  descargandoActa.value = true
  try {
    const response = await descargarActaEntrega(orden.value.id)
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const url = window.URL.createObjectURL(blob)
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 5000)
  } catch (e) {
    toast.error('No se pudo descargar el acta de entrega.')
  } finally {
    descargandoActa.value = false
  }
}

function formatFecha(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(String(dateStr).substring(0, 10) + 'T00:00:00')
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatDateTime(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const tipoPagoLabel = {
  anticipo: 'Anticipo',
  abono: 'Abono',
  saldo_final: 'Saldo final',
}

// ── Compartir ─────────────────────────────────────────────────────────────────

function whatsappLink() {
  const telefono = orden.value?.cliente?.telefono ?? ''
  // Limpiar dígitos y formatear para Colombia (+57)
  const digits = telefono.replace(/\D/g, '')
  const numero = digits.startsWith('57') ? digits : `57${digits}`

  const o = orden.value
  const total     = new Intl.NumberFormat('es-CO').format(o.valor_total)
  const anticipo  = new Intl.NumberFormat('es-CO').format(o.total_pagado)
  const saldo     = new Intl.NumberFormat('es-CO').format(o.saldo_pendiente)

  const productos = (o.items ?? [])
    .map(i => `  • ${i.producto?.nombre ?? i.nombre_custom ?? 'Producto personalizado'} x${i.cantidad}`)
    .join('\n')

  const mensaje = [
    `Hola ${o.cliente?.nombre} 👋`,
    ``,
    `Aquí tienes el resumen de tu pedido en *SODEGE* (Orden ${o.referencia ?? '#' + (o.numero_orden ?? o.id)}):`,
    ``,
    `🛋️ *Productos:*`,
    productos,
    ``,
    `💰 *Total:* $${total} COP`,
    `✅ *Anticipo pagado:* $${anticipo} COP`,
    o.saldo_pendiente > 0 ? `💳 *Saldo pendiente:* $${saldo} COP` : `🎉 *¡Pedido totalmente pagado!*`,
    ``,
    `Adjunto encontrarás la cotización en PDF con todos los detalles.`,
    `¡Gracias por tu compra! 🛋️`,
  ].filter(l => l !== null).join('\n')

  return `https://wa.me/${numero}?text=${encodeURIComponent(mensaje)}`
}

async function abrirWhatsApp() {
  // Primero descargar/abrir el PDF para que el vendedor lo tenga disponible
  descargarPdf()
  // Abrir WhatsApp con mensaje pre-llenado
  window.open(whatsappLink(), '_blank')
}

async function enviarEmail(emailDestino = null) {
  enviandoEmail.value = true
  try {
    const { data } = await reenviarCotizacion(orden.value.id, emailDestino)
    toast.success(data.message)
    mostrarEmailManual.value = false
    emailManual.value = ''
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al enviar el email.')
  } finally {
    enviandoEmail.value = false
  }
}

async function descargarBoceto(url) {
  try {
    const resp = await fetch(url)
    const blob = await resp.blob()
    const ext = blob.type.includes('png') ? 'png' : blob.type.includes('jpg') || blob.type.includes('jpeg') ? 'jpg' : 'png'
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `boceto.${ext}`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 5000)
  } catch {
    // noop
  }
}

async function guardarFechas() {
  guardandoFechas.value = true
  try {
    const items = Object.entries(fechasEdicion.value)
      .filter(([, fecha]) => fecha)
      .map(([id, fecha]) => ({ id: Number(id), fecha }))
    if (items.length === 0) {
      toast.error('Debes ingresar al menos una fecha.')
      guardandoFechas.value = false
      return
    }
    const { data } = await asignarFechasEntrega(orden.value.id, items)
    toast.success(data.message)
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al guardar las fechas.')
  } finally {
    guardandoFechas.value = false
  }
}

// ── Facturación por pago ──────────────────────────────────────────────────────
const facturacionLoading = ref({}) // { [pagoId]: true/false }

async function doTomarFacturacion(pagoId) {
  facturacionLoading.value[pagoId] = true
  try {
    const { data } = await tomarFacturacion(pagoId)
    if (!data.tomado) {
      toast.error('Ya fue tomado por ' + (data.pago?.facturacion_tomada_por?.nombre ?? 'otro facturador'))
    }
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al tomar la facturación')
  } finally {
    facturacionLoading.value[pagoId] = false
  }
}

async function doMarcarFacturada(pagoId) {
  facturacionLoading.value[pagoId] = true
  try {
    await marcarFacturada(pagoId)
    toast.success('Factura marcada como hecha')
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al marcar la factura')
  } finally {
    facturacionLoading.value[pagoId] = false
  }
}

// ── Trazabilidad de producción ────────────────────────────────────────────────

const itemsConProduccion = computed(() =>
  (orden.value?.items ?? []).filter(i => i.es_personalizado && i.produccion?.pasos?.length)
)

function labelProceso(tipo) {
  return { ebanisteria: 'Ebanistería', tapizado: 'Tapizado', laca: 'Laca', esqueleteria: 'Esqueletería', pintura: 'Pintura', costura: 'Costura' }[tipo] ?? tipo
}

function colorPaso(estado) {
  if (estado === 'completado') return 'bg-green-100 text-green-700'
  if (estado === 'en_proceso') return 'bg-blue-100 text-blue-700'
  return 'bg-gray-100 text-gray-400'
}

// ── Confirmar cotización (firma + anticipo cuando cliente acepta) ─────────────
const showModalConfirmar      = ref(false)
const firmaConfirmarBlob      = ref(null)
const firmaConfirmarUrl       = ref('')
const anexoConfirmarFile      = ref(null)
const anexoConfirmarUrl       = ref('')
const anexoConfirmarPreview   = ref('')
const anticipoPctConfirmar    = ref(50)
const anticipoConfirmar       = ref(0)
const metodoPagoConfirmar     = ref('efectivo')
const refPagoConfirmar        = ref('')
const confirmando             = ref(false)
const confirmarPagoSplit      = ref(false)
const confirmarMonto1Input    = ref(0)
const confirmarMetodo2        = ref('transferencia')
const confirmarRef2           = ref('')

function toggleConfirmarPagoSplit() {
  confirmarPagoSplit.value = !confirmarPagoSplit.value
  if (confirmarPagoSplit.value) {
    confirmarMonto1Input.value = Math.floor(anticipoConfirmar.value / 2)
    confirmarMetodo2.value     = metodoPagoConfirmar.value === 'efectivo' ? 'transferencia' : 'efectivo'
  }
}

watch(firmaConfirmarBlob, () => { firmaConfirmarUrl.value = '' })

watch(anexoConfirmarFile, (file) => {
  if (anexoConfirmarPreview.value) URL.revokeObjectURL(anexoConfirmarPreview.value)
  anexoConfirmarPreview.value = file ? URL.createObjectURL(file) : ''
})

function onAnexoConfirmarChange(e) {
  const file = e.target.files[0]
  if (file) { anexoConfirmarFile.value = file; anexoConfirmarUrl.value = '' }
}

function quitarAnexoConfirmar() {
  anexoConfirmarFile.value    = null
  anexoConfirmarUrl.value     = ''
  if (anexoConfirmarPreview.value) URL.revokeObjectURL(anexoConfirmarPreview.value)
  anexoConfirmarPreview.value = ''
}

const totalAcordado = computed(() => Number(orden.value?.valor_total ?? 0))

const minimoAnticipoc = computed(() =>
  Math.ceil(totalAcordado.value * anticipoPctConfirmar.value / 100)
)

function seleccionarPctAnticipo(pct) {
  anticipoPctConfirmar.value = pct
  anticipoConfirmar.value    = minimoAnticipoc.value
}

const metodosOpts = [
  { value: 'efectivo',      label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'tarjeta',       label: 'Tarjeta' },
  { value: 'otro',          label: 'Otro' },
]

async function doConfirmarCotizacion() {
  if (!firmaConfirmarBlob.value && !firmaConfirmarUrl.value) return
  const esPresencial = orden.value?.canal === 'fisica'
  if (esPresencial && !anexoConfirmarFile.value && !anexoConfirmarUrl.value) {
    toast.error('Adjunta la foto del anexo firmado antes de confirmar.')
    return
  }
  confirmando.value = true
  try {
    const api = (await import('@/api')).default

    // Subir firma
    if (firmaConfirmarBlob.value && !firmaConfirmarUrl.value) {
      const fd = new FormData()
      fd.append('foto', firmaConfirmarBlob.value, 'firma.png')
      fd.append('folder', 'firmas')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      firmaConfirmarUrl.value = up.url
    }

    // Subir foto del anexo (solo si canal es física)
    if (esPresencial && anexoConfirmarFile.value && !anexoConfirmarUrl.value) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(anexoConfirmarFile.value), 'anexo.jpg')
      fd.append('folder', 'anexos')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      anexoConfirmarUrl.value = up.url
    }

    await confirmarCotizacion(orden.value.id, {
      firma_url:           firmaConfirmarUrl.value,
      anexo_foto_url:      anexoConfirmarUrl.value || undefined,
      anticipo_monto:      anticipoConfirmar.value,
      anticipo_metodo:     metodoPagoConfirmar.value,
      anticipo_referencia: confirmarPagoSplit.value ? undefined : (refPagoConfirmar.value || undefined),
      ...(confirmarPagoSplit.value && anticipoConfirmar.value > 0 ? {
        anticipo_pagos: [
          { monto: confirmarMonto1Input.value,                                                                metodo: metodoPagoConfirmar.value, referencia: refPagoConfirmar.value || undefined },
          { monto: Math.max(0, anticipoConfirmar.value - confirmarMonto1Input.value), metodo: confirmarMetodo2.value,    referencia: confirmarRef2.value    || undefined },
        ].filter(p => p.monto > 0)
      } : {}),
    })

    toast.success('Cotización confirmada. Orden en pendiente de anticipo.')
    showModalConfirmar.value = false
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al confirmar.')
  } finally {
    confirmando.value = false
  }
}

// ── Consulta de costo ────────────────────────────────────────────────────────

const consultaActiva        = ref(null)
const cargandoConsulta      = ref(false)

// Ajuste de precio sobre cotización respondida
const descuentoCotizPct  = ref(null)   // null = sin selección, number = % seleccionado
const descuentoCustom    = ref('')     // campo manual si no usa preset
const aplicandoAjuste    = ref(false)
const modoAjustePorItem  = ref(false)  // false = % global, true = precio exacto por ítem
const preciosAjustados   = ref({})     // { [consulta_item_id]: number }

const PRESETS_DESCUENTO = [1, 2, 3, 5]

function entrarModoAjustePorItem() {
  preciosAjustados.value = {}
  for (const item of consultaActiva.value?.items ?? []) {
    preciosAjustados.value[item.id] = item.precio_final
  }
  modoAjustePorItem.value = true
}

function salirModoAjustePorItem() {
  modoAjustePorItem.value = false
  preciosAjustados.value = {}
}

async function aplicarAjustePorItem() {
  if (!consultaActiva.value) return
  aplicandoAjuste.value = true
  try {
    const items = consultaActiva.value.items.map(ci => ({
      consulta_item_id: ci.id,
      precio_ajustado:  Number(preciosAjustados.value[ci.id] ?? ci.precio_final),
    }))
    const { data } = await ajustarPrecioApi(consultaActiva.value.id, { items })
    consultaActiva.value = data.consulta
    if (orden.value) orden.value.valor_total = data.orden_valor_total
    toast.success('Precios actualizados correctamente.')
    modoAjustePorItem.value = false
    preciosAjustados.value = {}
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al ajustar precios.')
  } finally {
    aplicandoAjuste.value = false
  }
}

function precioAjustado(precioFinal) {
  const pct = descuentoCotizPct.value ?? 0
  return Math.round(precioFinal * (1 - pct / 100))
}

function seleccionarDescuento(pct) {
  descuentoCotizPct.value = descuentoCotizPct.value === pct ? null : pct
  descuentoCustom.value   = ''
}

function onCustomDescuento(e) {
  const v = parseFloat(e.target.value)
  descuentoCotizPct.value = (!isNaN(v) && v > 0) ? v : null
}

async function aplicarAjuste() {
  if (descuentoCotizPct.value === null || !consultaActiva.value) return
  aplicandoAjuste.value = true
  try {
    const { data } = await ajustarPrecioApi(consultaActiva.value.id, {
      descuento_pct: descuentoCotizPct.value,
    })
    consultaActiva.value = data.consulta
    if (orden.value) orden.value.valor_total = data.orden_valor_total
    toast.success(`Descuento del ${descuentoCotizPct.value}% aplicado.`)
    descuentoCotizPct.value = null
    descuentoCustom.value   = ''
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al aplicar el ajuste.')
  } finally {
    aplicandoAjuste.value = false
  }
}

const showModalCotizar   = ref(false)
const receptores         = ref([])
const cotizarReceptorId  = ref(null)
const cotizarNotas       = ref('')
const enviandoCotizacion = ref(false)

const puedesolicitarCotizacion = computed(() => {
  if (!orden.value) return false
  if (!tienePersonalizados.value) return false
  if (consultaActiva.value?.estado === 'pendiente') return false
  if (!['vendedor', 'supervisor'].includes(auth.usuario?.rol)) return false
  return true
})

async function abrirModalCotizar() {
  if (!receptores.value.length) {
    try {
      const { data } = await getReceptores()
      receptores.value = data
    } catch { receptores.value = [] }
  }
  cotizarReceptorId.value = null
  cotizarNotas.value      = ''
  showModalCotizar.value  = true
}

async function enviarSolicitudCotizacion() {
  if (!cotizarReceptorId.value) return
  enviandoCotizacion.value = true
  try {
    await crearConsulta({
      orden_id:          orden.value.id,
      asignado_a_id:     cotizarReceptorId.value,
      notas_adicionales: cotizarNotas.value.trim() || null,
    })
    toast.success('Consulta enviada.')
    showModalCotizar.value = false
    await cargarOrden()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al enviar la consulta.')
  } finally {
    enviandoCotizacion.value = false
  }
}

onMounted(cargarOrden)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex flex-wrap items-center gap-3">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        Orden {{ orden?.referencia ?? '...' }}
      </h2>
      <button
        v-if="orden && puedeEditar"
        @click="showEditarModal = true"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-amber-700 bg-amber-50 rounded-lg hover:bg-amber-100 transition-colors"
        title="Editar orden"
      >
        <PencilSquareIcon class="w-4 h-4" />
        Editar
      </button>
      <button
        v-if="orden"
        @click="descargarPdf"
        :disabled="descargandoPdf"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 disabled:opacity-60 transition-colors"
        title="Descargar PDF"
      >
        <IconoS v-if="descargandoPdf" class="w-4 h-4" />
        <DocumentIcon v-else class="w-4 h-4" />
        {{ descargandoPdf ? 'Generando...' : 'PDF' }}
      </button>
      <BadgeEstado v-if="orden" :estado="orden.estado" />
      <span
        v-if="orden?.atrasado"
        class="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700"
      >⚠ Atrasado</span>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">
      {{ error }}
    </div>

    <template v-else-if="orden">
      <!-- Info general -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-2 text-sm">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Información general</p>
        <div class="flex justify-between">
          <span class="text-gray-500">Cliente</span>
          <span class="font-medium text-gray-800">{{ orden.cliente?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Tienda</span>
          <span class="font-medium text-gray-800">{{ orden.tienda?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Vendedor</span>
          <span class="font-medium text-gray-800">{{ orden.vendedor?.nombre }}</span>
        </div>
        <div v-if="orden.es_compartida && orden.covendedor" class="flex justify-between">
          <span class="text-gray-500">Co-vendedor</span>
          <span class="font-medium text-indigo-700 flex items-center gap-1">
            <span class="inline-block w-2 h-2 rounded-full bg-indigo-400"></span>
            {{ orden.covendedor.nombre }} <span class="text-xs font-normal text-gray-400">(compartida)</span>
          </span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Canal</span>
          <span class="font-medium text-gray-800 capitalize">{{ orden.canal }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Fecha</span>
          <span class="font-medium text-gray-800">{{ formatDateTime(orden.created_at) }}</span>
        </div>
        <div v-if="orden.notas" class="flex justify-between">
          <span class="text-gray-500">Notas</span>
          <span class="font-medium text-gray-800 text-right max-w-[60%]">{{ orden.notas }}</span>
        </div>
        <div v-if="orden.ciudad_envio || orden.departamento_envio" class="pt-1">
          <p class="text-xs font-semibold text-gray-500 uppercase mb-1.5">Dirección de envío</p>
          <div class="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
            <svg class="w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
            </svg>
            <div class="text-xs text-blue-700 space-y-0.5">
              <p class="font-medium">
                {{ orden.ciudad_envio }}{{ orden.departamento_envio ? ', ' + orden.departamento_envio : '' }}
              </p>
              <p v-if="orden.direccion_envio" class="text-blue-600">{{ orden.direccion_envio }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Consulta de costo -->
      <div v-if="tienePersonalizados" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
          <CurrencyDollarIcon class="w-3.5 h-3.5" />
          Consulta de costo
        </p>

        <!-- Cargando consulta -->
        <div v-if="cargandoConsulta" class="flex items-center gap-2 py-1">
          <div class="w-8 h-8 rounded-full bg-gray-100 animate-pulse flex-shrink-0" />
          <div class="flex-1 space-y-1.5">
            <div class="h-3 bg-gray-100 rounded animate-pulse w-32" />
            <div class="h-2.5 bg-gray-100 rounded animate-pulse w-24" />
          </div>
        </div>

        <!-- Estado de la consulta activa -->
        <div v-else-if="consultaActiva" class="flex items-start gap-3">
          <div
            :class="[
              'flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center',
              consultaActiva.estado === 'pendiente' ? 'bg-amber-100' : 'bg-green-100'
            ]"
          >
            <ClockIcon v-if="consultaActiva.estado === 'pendiente'" class="w-4 h-4 text-amber-600" />
            <CheckCircleIcon v-else class="w-4 h-4 text-green-600" />
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">
              {{ consultaActiva.estado === 'pendiente' ? 'Consulta pendiente' : 'Precio recibido' }}
            </p>
            <p class="text-xs text-gray-400">
              Asignada a {{ consultaActiva.asignado_a?.nombre ?? '—' }}
            </p>
            <div v-if="consultaActiva.estado === 'respondida'" class="space-y-3 mt-1.5">

              <!-- Precios por ítem -->
              <div class="flex flex-wrap gap-1.5">
                <span
                  v-for="item in consultaActiva.items"
                  :key="item.id"
                  class="text-xs bg-green-50 text-green-700 px-2 py-0.5 rounded-full font-medium"
                >
                  {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem' }}:
                  {{ new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(item.precio_final) }}
                </span>
              </div>

              <!-- Panel de ajuste de precio -->
              <div
                v-if="orden && orden.estado === 'pendiente_cotizacion'"
                class="border border-amber-200 bg-amber-50 rounded-xl p-3 space-y-2.5"
              >
                <!-- Cabecera con toggle de modo -->
                <div class="flex items-center justify-between">
                  <p class="text-xs font-semibold text-amber-800">Ajustar precio al cliente</p>
                  <button
                    @click="modoAjustePorItem ? salirModoAjustePorItem() : entrarModoAjustePorItem()"
                    class="text-[11px] font-medium underline underline-offset-2 text-amber-700 hover:text-amber-900"
                  >
                    {{ modoAjustePorItem ? '← Volver a % descuento' : 'Ingresar precio exacto' }}
                  </button>
                </div>

                <!-- MODO % descuento global -->
                <template v-if="!modoAjustePorItem">
                  <!-- Botones de descuento rápido -->
                  <div class="flex gap-1.5 flex-wrap">
                    <button
                      v-for="pct in PRESETS_DESCUENTO"
                      :key="pct"
                      @click="seleccionarDescuento(pct)"
                      :class="['px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors',
                        descuentoCotizPct === pct
                          ? 'bg-amber-600 text-white border-amber-600'
                          : 'bg-white text-amber-700 border-amber-300 hover:border-amber-500']"
                    >
                      {{ pct }}%
                    </button>
                    <div class="flex items-center gap-1 bg-white border border-amber-300 rounded-lg px-2 py-1">
                      <input
                        :value="descuentoCustom"
                        @input="onCustomDescuento"
                        type="number" min="0.1" max="99" step="0.5" placeholder="Otro"
                        class="w-14 text-xs text-center focus:outline-none bg-transparent"
                      />
                      <span class="text-xs text-amber-600 font-bold">%</span>
                    </div>
                  </div>

                  <!-- Preview -->
                  <div v-if="descuentoCotizPct !== null && descuentoCotizPct > 0" class="space-y-1">
                    <p class="text-[11px] text-amber-700 font-medium">Con {{ descuentoCotizPct }}% de descuento:</p>
                    <div class="flex flex-wrap gap-1.5">
                      <span
                        v-for="item in consultaActiva.items"
                        :key="item.id"
                        class="text-xs bg-white border border-amber-300 text-amber-900 px-2 py-0.5 rounded-full font-medium"
                      >
                        {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem' }}:
                        {{ new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(precioAjustado(item.precio_final)) }}
                      </span>
                    </div>
                  </div>

                  <button
                    @click="aplicarAjuste"
                    :disabled="aplicandoAjuste || descuentoCotizPct === null || descuentoCotizPct <= 0"
                    class="w-full bg-amber-600 text-white rounded-lg py-2 text-xs font-bold hover:bg-amber-700 disabled:opacity-40 transition-colors"
                  >
                    {{ aplicandoAjuste ? 'Aplicando...' : descuentoCotizPct ? `Aplicar descuento del ${descuentoCotizPct}%` : 'Selecciona un descuento' }}
                  </button>
                </template>

                <!-- MODO precio exacto por ítem -->
                <template v-else>
                  <p class="text-[11px] text-amber-700">Ingresa el precio acordado con el cotizador para cada ítem:</p>
                  <div class="space-y-2">
                    <div
                      v-for="item in consultaActiva.items"
                      :key="item.id"
                      class="flex items-center gap-2 bg-white rounded-lg border border-amber-200 px-3 py-2"
                    >
                      <span class="flex-1 text-xs text-gray-700 font-medium truncate">
                        {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem' }}
                      </span>
                      <span class="text-[11px] text-gray-400 line-through flex-shrink-0">
                        {{ new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(item.precio_final) }}
                      </span>
                      <div class="flex items-center gap-0.5 bg-amber-50 border border-amber-300 rounded-lg px-2 py-1 flex-shrink-0">
                        <span class="text-xs text-amber-700 font-bold">$</span>
                        <input
                          v-model.number="preciosAjustados[item.id]"
                          type="number" min="0"
                          class="w-24 text-xs text-right focus:outline-none bg-transparent font-semibold text-amber-900"
                        />
                      </div>
                    </div>
                  </div>
                  <button
                    @click="aplicarAjustePorItem"
                    :disabled="aplicandoAjuste"
                    class="w-full bg-amber-600 text-white rounded-lg py-2 text-xs font-bold hover:bg-amber-700 disabled:opacity-40 transition-colors"
                  >
                    {{ aplicandoAjuste ? 'Guardando...' : 'Guardar precios acordados' }}
                  </button>
                </template>
              </div>

              <!-- Confirmar venta -->
              <button
                v-if="orden.estado === 'pendiente_cotizacion'"
                @click="anticipoPctConfirmar = 50; anticipoConfirmar = Math.ceil(totalAcordado * 0.5); showModalConfirmar = true"
                class="w-full bg-green-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-green-700 transition-colors flex items-center justify-center gap-2"
              >
                <CheckCircleIcon class="w-4 h-4" />
                El cliente aceptó el precio — confirmar
              </button>
            </div>
          </div>
          <button
            @click="router.push({ name: 'consulta-detalle', params: { id: consultaActiva.id } })"
            class="text-xs text-blue-600 font-medium hover:underline flex-shrink-0"
          >
            Ver →
          </button>
        </div>

        <!-- Sin consulta -->
        <div v-else-if="!cargandoConsulta">
          <p class="text-xs text-gray-400">No se solicitó consulta de costo para esta orden.</p>
        </div>
      </div>

      <!-- Foto de factura -->
      <div v-if="orden.factura_foto_url" class="bg-white rounded-xl shadow-sm p-4 space-y-2">
        <div class="flex items-center justify-between mb-2">
          <p class="text-xs font-semibold text-gray-500 uppercase">Comprobante</p>
          <a
            :href="orden.factura_foto_url"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-700 font-medium"
          >
            <ArrowDownTrayIcon class="w-3.5 h-3.5" />
            Abrir
          </a>
        </div>
        <img
          :src="cloudinaryOpt(orden.factura_foto_url, 800)"
          alt="Comprobante"
          class="w-full rounded-lg border border-gray-200 object-contain max-h-72 cursor-pointer"
          @click="verFactura = orden.factura_foto_url"
        />
      </div>

      <!-- Foto del anexo firmado -->
      <div v-if="orden.anexo_foto_url" class="bg-white rounded-xl shadow-sm p-4 space-y-2">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-1.5">
            <p class="text-xs font-semibold text-gray-500 uppercase">Anexo firmado</p>
            <span class="text-xs bg-green-100 text-green-700 font-semibold px-2 py-0.5 rounded-full">✓ Firmado</span>
          </div>
          <a
            :href="orden.anexo_foto_url"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-700 font-medium"
          >
            <ArrowDownTrayIcon class="w-3.5 h-3.5" />
            Abrir
          </a>
        </div>
        <img
          :src="cloudinaryOpt(orden.anexo_foto_url, 800)"
          alt="Anexo firmado"
          class="w-full rounded-lg border border-gray-200 object-contain max-h-72 cursor-pointer"
          @click="verFactura = orden.anexo_foto_url"
        />
      </div>

      <!-- Progreso de pago -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Pago</p>
        <div v-if="Number(orden.descuento_total) > 0" class="flex justify-between text-sm">
          <span class="text-gray-500">
            Descuento al total
            <span class="text-gray-400">({{ formatPct(pctDeMonto(orden.descuento_total, subtotalBruto)) }}%)</span>
          </span>
          <span class="font-medium text-green-600">− <MoneyDisplay :amount="orden.descuento_total" /></span>
        </div>
        <div
          v-if="Number(orden.descuento_condicionado) > 0 && !orden.descuento_condicionado_revertido_at"
          class="flex justify-between text-sm"
        >
          <span class="text-gray-500">
            Descuento por efectivo/transferencia
            <span class="text-gray-400">({{ formatPct(pctDeMonto(orden.descuento_condicionado, subtotalBruto)) }}%)</span>
          </span>
          <span class="font-medium text-green-600">− <MoneyDisplay :amount="orden.descuento_condicionado" /></span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Total</span>
          <MoneyDisplay :amount="orden.valor_total" bold />
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Pagado</span>
          <span class="font-medium text-green-600"><MoneyDisplay :amount="orden.total_pagado" /></span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Saldo</span>
          <span class="font-bold text-red-600"><MoneyDisplay :amount="orden.saldo_pendiente" /></span>
        </div>
        <!-- Barra progreso -->
        <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
          <div
            class="h-full rounded-full transition-all duration-500"
            :class="porcentajePagado >= 100 ? 'bg-green-500' : 'bg-blue-500'"
            :style="{ width: porcentajePagado + '%' }"
          />
        </div>
        <p class="text-xs text-gray-400 text-right">{{ porcentajePagado }}% pagado</p>

        <!-- Descuento que depende del medio de pago -->
        <div
          v-if="Number(orden.descuento_condicionado) > 0 && !orden.descuento_condicionado_revertido_at"
          class="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"
        >
          <p class="text-xs font-semibold text-amber-900">
            Incluye {{ orden.descuento_condicionado_pct }}% de descuento por pago en efectivo o transferencia
          </p>
          <p class="text-xs text-amber-700 mt-0.5">
            Si el cliente paga cualquier parte con tarjeta se pierden
            <strong><MoneyDisplay :amount="orden.descuento_condicionado" /></strong>
            y el total sube. El sistema avisa al momento de cobrar.
          </p>
        </div>

        <div
          v-else-if="orden.descuento_condicionado_revertido_at"
          class="bg-gray-100 border border-gray-200 rounded-lg px-3 py-2"
        >
          <p class="text-xs text-gray-600">
            El descuento por pago en efectivo se perdió al cobrar con otro medio de pago.
            El total ya está actualizado.
          </p>
        </div>
      </div>

      <!-- Ítems -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Ítems ({{ orden.items?.length ?? 0 }})</p>
        <div
          v-for="(item, idx) in orden.items"
          :key="idx"
          class="border-b border-gray-100 last:border-0 pb-3 last:pb-0"
        >
          <div class="flex justify-between items-start gap-3">
            <!-- Foto del producto -->
            <img
              v-if="item.producto?.foto_url"
              :src="cloudinaryOpt(item.producto.foto_url, 200)"
              :alt="item.producto?.nombre ?? 'Producto'"
              class="w-16 h-16 rounded-lg object-cover flex-shrink-0 border border-gray-100 cursor-pointer"
              @click="bocetoModal = item.producto.foto_url"
            />
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800">{{ item.producto?.nombre ?? item.nombre_custom ?? 'Producto personalizado' }}</p>
              <p class="text-xs text-gray-400">{{ item.producto?.categoria ?? item.categoria_custom ?? 'personalizado' }}</p>
              <p class="text-xs text-gray-500 mt-0.5">Cantidad: {{ item.cantidad }}</p>
              <p v-if="origenInventario(item)" class="text-xs text-emerald-600 mt-1 flex items-center gap-1">
                <BuildingOffice2Icon class="w-3.5 h-3.5" /> Inventario {{ origenInventario(item) }}
              </p>
              <p v-if="item.tipo_item === 'personalizado'" class="text-xs text-purple-600 mt-1 flex items-center gap-1">
                <SparklesIcon class="w-3.5 h-3.5" /> Personalizado
              </p>
              <p v-else-if="item.tipo_item === 'diseno_especial'" class="text-xs text-indigo-600 mt-1 flex items-center gap-1">
                <SwatchIcon class="w-3.5 h-3.5" /> Diseño especial
              </p>
              <p v-else-if="item.tipo_item === 'fabricar'" class="text-xs text-amber-600 mt-1 flex items-center gap-1">
                <WrenchScrewdriverIcon class="w-3.5 h-3.5" /> Para fabricar
              </p>
              <div
                v-if="specsResumen(item).length"
                :class="['mt-1 rounded-lg px-2 py-1.5 text-xs text-gray-600 space-y-0.5', item.es_personalizado ? 'bg-purple-50' : 'bg-gray-50']"
              >
                <p v-for="(s, si) in specsResumen(item)" :key="si" class="whitespace-pre-wrap">
                  <span class="text-gray-400">{{ s.label }}:</span> {{ s.value }}
                </p>
              </div>
              <div v-if="item.boceto_url || item.boceto_fotos?.length" class="mt-2 space-y-1.5">
                <p class="text-xs text-gray-400">Boceto / Fotos</p>
                <div class="grid grid-cols-3 gap-1.5">
                  <div
                    v-for="(url, fi) in (item.boceto_fotos?.length ? item.boceto_fotos : [item.boceto_url])"
                    :key="fi"
                    class="relative aspect-square"
                  >
                    <img
                      :src="url"
                      :alt="`Boceto ${fi + 1}`"
                      class="w-full h-full rounded-lg border border-purple-200 object-cover bg-white cursor-pointer"
                      @click="bocetoModal = url"
                    />
                    <button
                      @click.stop="descargarBoceto(url)"
                      class="absolute bottom-1 right-1 bg-white rounded-md p-0.5 shadow text-blue-500 hover:text-blue-700"
                      title="Descargar"
                    ><ArrowDownTrayIcon class="w-3.5 h-3.5" /></button>
                  </div>
                </div>
              </div>
              <p v-if="item.fecha_entrega_prom" class="text-xs text-gray-500 mt-0.5">
                Entrega estimada: {{ formatFecha(item.fecha_entrega_prom) }}
                <span
                  v-if="orden.fecha_sugerida_vendedor && String(item.fecha_entrega_prom).substring(0,10) !== String(orden.fecha_sugerida_vendedor).substring(0,10)"
                  class="text-amber-600"
                >· se le prometió el {{ formatFecha(orden.fecha_sugerida_vendedor) }}</span>
              </p>
            </div>
            <div class="text-right ml-3">
              <p class="text-xs text-gray-500"><MoneyDisplay :amount="item.precio_unitario" /></p>
              <p class="text-sm font-semibold text-gray-700"><MoneyDisplay :amount="item.cantidad * item.precio_unitario" /></p>
            </div>
          </div>
        </div>
      </div>

      <!-- Trazabilidad de producción (solo supervisor) -->
      <div v-if="auth.isSupervisor && itemsConProduccion.length > 0" class="bg-white rounded-xl shadow-sm p-4 space-y-4">
        <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
          <WrenchScrewdriverIcon class="w-3.5 h-3.5" />
          Trazabilidad de producción
        </p>

        <div
          v-for="item in itemsConProduccion"
          :key="item.id"
          class="space-y-2"
          :class="itemsConProduccion.length > 1 ? 'border-b border-gray-100 last:border-0 pb-3 last:pb-0' : ''"
        >
          <p class="text-sm font-semibold text-gray-700">
            {{ item.producto?.nombre ?? item.nombre_custom ?? 'Ítem personalizado' }}
          </p>

          <!-- Pasos -->
          <div class="space-y-2 ml-1">
            <div
              v-for="paso in item.produccion.pasos"
              :key="paso.id"
              class="flex items-start gap-2.5"
            >
              <!-- Indicador de estado -->
              <div :class="['w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center', colorPaso(paso.estado)]">
                <CheckCircleIcon v-if="paso.estado === 'completado'" class="w-4 h-4" />
                <span v-else class="text-[10px] font-bold">{{ paso.orden }}</span>
              </div>

              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <p class="text-sm font-medium text-gray-700">{{ labelProceso(paso.tipo_proceso) }}</p>
                  <span v-if="paso.estado === 'en_proceso'" class="text-[10px] bg-blue-100 text-blue-700 font-semibold px-1.5 py-0.5 rounded-full">En proceso</span>
                  <span v-else-if="paso.estado === 'pendiente'" class="text-[10px] bg-gray-100 text-gray-500 font-semibold px-1.5 py-0.5 rounded-full">Pendiente</span>
                </div>

                <template v-if="paso.estado === 'completado'">
                  <p class="text-xs text-gray-400 mt-0.5">{{ formatDateTime(paso.completado_at) }}</p>
                  <div v-if="paso.completado_por" class="flex items-center gap-1 mt-1 text-xs text-gray-600">
                    <UserGroupIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
                    <span class="font-medium">{{ paso.completado_por.nombre }}</span>
                    <span v-if="paso.trabajadores?.length" class="text-gray-400">
                      · {{ paso.trabajadores.join(', ') }}
                    </span>
                  </div>
                  <div v-else-if="paso.trabajadores?.length" class="flex items-center gap-1 mt-1 text-xs text-gray-600">
                    <UserGroupIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
                    {{ paso.trabajadores.join(', ') }}
                  </div>
                </template>
              </div>
            </div>

            <!-- Despacho de producción -->
            <div v-if="item.produccion.despachador" class="flex items-start gap-2.5">
              <div class="w-6 h-6 rounded-full flex-shrink-0 bg-purple-100 text-purple-700 flex items-center justify-center">
                <TruckIcon class="w-3.5 h-3.5" />
              </div>
              <div>
                <p class="text-sm font-medium text-gray-700">Despacho de producción</p>
                <p class="text-xs text-gray-600 mt-0.5">
                  {{ item.produccion.despachador.nombre }}
                  <span v-if="item.produccion.fecha_real" class="text-gray-400 ml-1">· {{ formatFecha(item.produccion.fecha_real) }}</span>
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pruebas de Entrega (despacho) -->
      <div v-if="pruebaEntregaVisible" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Pruebas de Entrega</p>

        <div v-if="cargandoDespacho" class="text-sm text-gray-400">Cargando...</div>

        <template v-else-if="despachoEntrega">
          <div class="grid grid-cols-2 gap-3">
            <div v-if="despachoEntrega.foto_producto">
              <p class="text-xs text-gray-500 mb-1">Producto entregado</p>
              <img
                :src="cloudinaryOpt(despachoEntrega.foto_producto, 600)"
                class="w-full h-32 object-cover rounded-lg border border-gray-200 cursor-pointer"
                @click="verFactura = despachoEntrega.foto_producto"
              />
            </div>
            <div v-if="despachoEntrega.foto_pago">
              <p class="text-xs text-gray-500 mb-1">Comprobante de pago</p>
              <img
                :src="cloudinaryOpt(despachoEntrega.foto_pago, 600)"
                class="w-full h-32 object-cover rounded-lg border border-gray-200 cursor-pointer"
                @click="verFactura = despachoEntrega.foto_pago"
              />
            </div>
          </div>

          <div class="flex items-center justify-between text-sm pt-2 border-t border-gray-100">
            <span class="text-gray-500">Conductor</span>
            <span class="font-medium text-gray-800">{{ despachoEntrega.despacho?.conductor?.nombre }}</span>
          </div>
          <div v-if="despachoEntrega.entregado_at" class="flex items-center justify-between text-sm">
            <span class="text-gray-500">Entregado el</span>
            <span class="font-medium text-gray-800">{{ formatDateTime(despachoEntrega.entregado_at) }}</span>
          </div>

          <!-- ═══════════ ACTA DE SATISFACCIÓN ═══════════ -->
          <div
            v-if="despachoEntrega.firma_recibido_url || despachoEntrega.firma_omitida_motivo"
            class="pt-3 border-t border-gray-100 space-y-2"
          >
            <div class="flex items-center justify-between">
              <p class="text-xs font-semibold text-gray-500 uppercase">Acta de satisfacción</p>
              <button
                v-if="despachoEntrega.firma_recibido_url"
                @click="descargarActa"
                :disabled="descargandoActa"
                class="text-xs text-blue-600 font-medium flex items-center gap-1 disabled:opacity-60"
              >
                <IconoS v-if="descargandoActa" class="w-3.5 h-3.5" />
                <ArrowDownTrayIcon v-else class="w-3.5 h-3.5" />
                {{ descargandoActa ? 'Generando...' : 'PDF' }}
              </button>
            </div>

            <!-- Cómo llegó -->
            <div
              v-if="despachoEntrega.conforme === false"
              class="bg-amber-50 border border-amber-300 rounded-lg p-3 space-y-1.5"
            >
              <p class="text-sm font-semibold text-amber-900 flex items-center gap-1.5">
                <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0" />
                Recibido con novedad
              </p>
              <p class="text-sm text-amber-800">{{ despachoEntrega.observaciones_entrega }}</p>
              <img
                v-if="despachoEntrega.foto_novedad_url"
                :src="cloudinaryOpt(despachoEntrega.foto_novedad_url, 600)"
                class="w-full h-32 object-cover rounded-lg border border-amber-200 cursor-pointer"
                @click="verFactura = despachoEntrega.foto_novedad_url"
              />
            </div>
            <p v-else-if="despachoEntrega.conforme" class="text-sm text-green-700 flex items-center gap-1.5">
              <CheckCircleIcon class="w-4 h-4 flex-shrink-0" />
              El cliente recibió conforme
            </p>

            <!-- Quién firmó -->
            <div v-if="despachoEntrega.firma_recibido_url" class="flex items-start gap-3">
              <img
                :src="cloudinaryOpt(despachoEntrega.firma_recibido_url, 200)"
                class="h-16 w-32 object-contain bg-white rounded-lg border border-gray-200 cursor-pointer flex-shrink-0"
                @click="verFactura = despachoEntrega.firma_recibido_url"
              />
              <div class="min-w-0 text-sm">
                <p class="font-medium text-gray-800 truncate">{{ despachoEntrega.recibido_por_nombre }}</p>
                <p v-if="despachoEntrega.recibido_por_cedula" class="text-xs text-gray-500">
                  C.C. {{ despachoEntrega.recibido_por_cedula }}
                </p>
                <p class="text-xs text-gray-400">Recibió y firmó</p>
              </div>
            </div>

            <!-- No hubo quien firmara -->
            <div v-else class="bg-gray-100 border border-gray-200 rounded-lg p-2.5">
              <p class="text-xs font-semibold text-gray-700">Se entregó sin firma</p>
              <p class="text-xs text-gray-600 mt-0.5">{{ despachoEntrega.firma_omitida_motivo }}</p>
            </div>
          </div>
        </template>
      </div>

      <!-- Historial de pagos -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Historial de pagos ({{ orden.pagos?.length ?? 0 }})</p>
        <div v-if="orden.pagos?.length === 0" class="text-sm text-gray-400 text-center py-4">
          No hay pagos registrados.
        </div>
        <div
          v-for="pago in orden.pagos"
          :key="pago.id"
          class="border-b border-gray-100 last:border-0 pb-3 last:pb-0"
        >
          <div class="flex justify-between items-start">
            <div>
              <p class="text-sm font-medium text-gray-800">
                {{ tipoPagoLabel[pago.tipo] ?? pago.tipo }}
              </p>
              <p class="text-xs text-gray-400 capitalize">
                {{ pago.metodo }}
                <span v-if="pago.referencia">· {{ pago.referencia }}</span>
                <button
                  v-if="puedeCorregirMedio"
                  @click="abrirCorregirMedio(pago)"
                  class="ml-1.5 text-blue-600 normal-case hover:underline"
                >corregir medio</button>
              </p>

              <!-- Sello del cambio: va pegado al pago, no escondido al final -->
              <p
                v-if="correccionDe(pago)"
                class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.5 mt-1 inline-block"
              >
                Corregido de <strong>{{ correccionDe(pago).antes }}</strong> a
                <strong>{{ correccionDe(pago).despues }}</strong>
                por {{ correccionDe(pago).usuario }} · {{ formatDateTime(correccionDe(pago).fecha) }}
              </p>
              <p v-if="pago.notas" class="text-xs text-gray-400">{{ pago.notas }}</p>
              <a
                v-if="pago.comprobante_url"
                :href="pago.comprobante_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline mt-0.5"
              >
                <ArrowDownTrayIcon class="w-3 h-3" />
                Ver comprobante
              </a>
            </div>
            <div class="text-right">
              <p class="text-sm font-semibold text-green-600"><MoneyDisplay :amount="pago.monto" /></p>
              <p class="text-xs text-gray-400">{{ formatDateTime(pago.created_at) }}</p>
            </div>
          </div>

          <!-- Indicador de facturación: visible para todos -->
          <div v-if="pago.facturacion_hecha_at" class="mt-2 flex items-center gap-1.5 text-xs text-green-700 bg-green-50 rounded-lg px-2.5 py-1.5 w-fit">
            <CheckBadgeIcon class="w-3.5 h-3.5" />
            Facturado · {{ formatDateTime(pago.facturacion_hecha_at) }}
          </div>

          <!-- Acciones de facturación: solo para el facturador -->
          <div v-else-if="auth.isFacturador" class="mt-2">
            <!-- Tomado por mí → marcar hecha -->
            <button
              v-if="pago.facturacion_tomada_por?.id === auth.usuario?.id"
              @click="doMarcarFacturada(pago.id)"
              :disabled="facturacionLoading[pago.id]"
              class="flex items-center gap-1.5 text-xs font-semibold text-white bg-green-600 hover:bg-green-700 disabled:opacity-50 rounded-lg px-3 py-1.5 transition-colors"
            >
              <CheckBadgeIcon class="w-3.5 h-3.5" />
              {{ facturacionLoading[pago.id] ? 'Guardando...' : 'Marcar factura hecha' }}
            </button>
            <!-- Tomado por otro -->
            <div v-else-if="pago.facturacion_tomada_por" class="flex items-center gap-1.5 text-xs text-amber-700 bg-amber-50 rounded-lg px-2.5 py-1.5 w-fit">
              <LockClosedIcon class="w-3.5 h-3.5" />
              Tomado por {{ pago.facturacion_tomada_por.nombre }}
            </div>
            <!-- Sin tomar → tomar -->
            <button
              v-else
              @click="doTomarFacturacion(pago.id)"
              :disabled="facturacionLoading[pago.id]"
              class="flex items-center gap-1.5 text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 disabled:opacity-50 rounded-lg px-3 py-1.5 transition-colors"
            >
              {{ facturacionLoading[pago.id] ? 'Procesando...' : 'Tomar facturación' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Asignar fechas de entrega (solo después de que el cliente acepte el precio) -->
      <div v-if="auth.isSupervisor && !todasFechasAsignadas && orden.estado !== 'pendiente_cotizacion'" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Asignar fechas de entrega</p>

        <!-- Lo que el vendedor le prometió al cliente. Sin esto la fecha se
             ponía a ciegas y a veces quedaba un mes más lejos de lo hablado. -->
        <div v-if="orden.fecha_sugerida_vendedor" class="bg-blue-50 border border-blue-200 rounded-lg p-3 space-y-2">
          <p class="text-xs text-blue-900">
            <span class="font-semibold">{{ orden.vendedor?.nombre ?? 'El vendedor' }}</span>
            le prometió al cliente el
            <span class="font-semibold">{{ formatFecha(orden.fecha_sugerida_vendedor) }}</span>.
          </p>
          <button
            type="button"
            @click="usarFechaSugerida"
            class="w-full text-xs font-semibold py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors"
          >
            Usar esa fecha {{ orden.items.length > 1 ? 'en todos los ítems' : '' }}
          </button>
        </div>

        <div
          v-for="item in orden.items"
          :key="item.id"
          class="space-y-1"
        >
          <label class="text-xs font-medium text-gray-600">{{ item.producto?.nombre ?? item.nombre_custom ?? 'Producto personalizado' }}</label>
          <input
            v-model="fechasEdicion[item.id]"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <button
          @click="guardarFechas"
          :disabled="guardandoFechas"
          class="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          {{ guardandoFechas ? 'Guardando...' : 'Guardar fechas' }}
        </button>
      </div>

      <!-- Chat de dudas de la orden. Solo para quien participa: el vendedor,
           su covendedor y los supervisores. -->
      <ChatOrden
        v-if="auth.isSupervisor || orden.vendedor_id === auth.usuario?.id || orden.covendedor_id === auth.usuario?.id"
        :orden-id="orden.id"
        :estado="orden.estado"
      />

      <!-- Historial de ediciones -->
      <div v-if="orden.ediciones?.length" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
          <ClockIcon class="w-3.5 h-3.5" />
          Historial de ediciones ({{ orden.ediciones.length }})
        </p>
        <div class="max-h-72 overflow-y-auto space-y-3 pr-1 -mr-1">
          <div
            v-for="edicion in orden.ediciones"
            :key="edicion.id"
            class="border-b border-gray-100 last:border-0 pb-3 last:pb-0"
          >
            <div class="flex justify-between items-center mb-1.5">
              <span class="text-xs font-semibold text-gray-700">{{ edicion.usuario?.nombre }}</span>
              <span class="text-[11px] text-gray-400">{{ formatDateTime(edicion.created_at) }}</span>
            </div>
            <ul class="space-y-1.5">
              <li v-for="cambio in edicion.cambios" :key="cambio.campo" class="text-xs text-gray-600 leading-snug">
                <span class="font-medium">{{ cambio.label }}</span>

                <!-- Specs: solo los campos que de verdad cambiaron. Antes se
                     volcaba el objeto entero de los dos lados, así que por
                     corregir una palabra salía un párrafo repetido dos veces. -->
                <ul v-if="detalleCambio(cambio).length" class="mt-0.5 ml-2 space-y-1">
                  <li v-for="d in detalleCambio(cambio)" :key="d.label">
                    <span class="text-gray-500">{{ d.label }}:</span>
                    <template v-if="d.largo">
                      <p class="text-red-500 line-through break-words">{{ d.antes }}</p>
                      <p class="text-green-700 break-words">{{ d.despues }}</p>
                    </template>
                    <template v-else>
                      <span class="text-red-500 line-through ml-1">{{ d.antes }}</span>
                      <span class="mx-1 text-gray-400">→</span>
                      <span class="text-green-700">{{ d.despues }}</span>
                    </template>
                  </li>
                </ul>

                <!-- Valor suelto (precio, notas, fecha…) -->
                <template v-else-if="!esCambioDeObjeto(cambio)">
                  <span class="text-red-500 line-through ml-1">{{ formatCambioVal(cambio.antes) }}</span>
                  <span class="mx-1 text-gray-400">→</span>
                  <span class="text-green-700">{{ formatCambioVal(cambio.despues) }}</span>
                </template>

                <span v-else class="text-gray-400 ml-1">— sin cambios visibles</span>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Compartir cotización -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Compartir cotización</p>

        <!-- Aviso informativo: fechas pendientes (ya no bloquea el envío) -->
        <div
          v-if="!todasFechasAsignadas && orden.estado !== 'pendiente_cotizacion'"
          class="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5"
        >
          <CalendarIcon class="w-4 h-4 mt-0.5 text-amber-500 flex-shrink-0" />
          <p class="text-xs text-amber-700 leading-snug">
            Aún no se han asignado las fechas de entrega. Puedes enviar la cotización igual; el PDF mostrará las fechas pendientes.
          </p>
        </div>

        <template v-if="!['pendiente_cotizacion', 'borrador'].includes(orden.estado)">
          <div class="flex gap-2">
            <!-- WhatsApp -->
            <button
              v-if="orden.cliente?.telefono"
              @click="abrirWhatsApp"
              class="flex-1 flex items-center justify-center gap-2 bg-green-500 hover:bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold transition-colors"
            >
              <ChatBubbleLeftEllipsisIcon class="w-4 h-4" />
              WhatsApp
            </button>

            <!-- Email -->
            <button
              v-if="orden.cliente?.email"
              @click="enviarEmail()"
              :disabled="enviandoEmail"
              class="flex-1 flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold transition-colors"
            >
              <EnvelopeIcon class="w-4 h-4" />
              {{ enviandoEmail ? 'Enviando...' : 'Enviar email' }}
            </button>

            <!-- Si no hay email registrado: opción de ingresar uno -->
            <button
              v-else
              @click="mostrarEmailManual = !mostrarEmailManual"
              class="flex-1 flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg py-2.5 text-sm font-semibold transition-colors"
            >
              <EnvelopeIcon class="w-4 h-4" />
              Email manual
            </button>
          </div>

          <!-- Email manual (si el cliente no tiene email guardado) -->
          <div v-if="mostrarEmailManual" class="flex gap-2">
            <input
              v-model="emailManual"
              type="email"
              placeholder="correo@ejemplo.com"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <button
              @click="enviarEmail(emailManual)"
              :disabled="enviandoEmail || !emailManual"
              class="bg-blue-600 text-white px-4 rounded-lg text-sm font-semibold disabled:opacity-50 hover:bg-blue-700 transition-colors"
            >
              {{ enviandoEmail ? '...' : 'Enviar' }}
            </button>
          </div>

          <!-- Sin teléfono ni email -->
          <p
            v-if="!orden.cliente?.telefono && !orden.cliente?.email"
            class="text-xs text-gray-400 text-center py-1"
          >
            El cliente no tiene teléfono ni email registrado.
          </p>
        </template>
      </div>

      <!-- Banner y acciones: orden en borrador -->
      <template v-if="esBorrador">
        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex items-start gap-3">
          <ClockIcon class="w-5 h-5 mt-0.5 text-amber-600 flex-shrink-0" />
          <div>
            <p class="text-sm font-semibold text-amber-800">Orden en borrador</p>
            <p class="text-xs text-amber-700 mt-0.5">Los productos están reservados. Cuando el cliente regrese, completa la orden con su firma y anticipo.</p>
          </div>
        </div>

        <div class="space-y-2">
          <button
            v-if="auth.isSupervisor || Number(orden.vendedor_id) === Number(auth.usuario?.id)"
            @click="showCompletarBorradorModal = true"
            class="w-full bg-green-600 text-white rounded-xl py-3 text-sm font-semibold hover:bg-green-700 transition-colors flex items-center justify-center gap-2"
          >
            <CheckCircleIcon class="w-4 h-4" />
            Completar orden
          </button>
          <button
            v-if="auth.isSupervisor || Number(orden.vendedor_id) === Number(auth.usuario?.id)"
            @click="showDescartarBorrador = true"
            :disabled="descartandoBorrador"
            class="w-full border border-red-300 text-red-600 rounded-xl py-2.5 text-sm font-medium hover:bg-red-50 disabled:opacity-50 transition-colors"
          >
            Descartar borrador
          </button>
        </div>
      </template>

      <!-- Borrador que quedó cancelado: tampoco fue una venta, se puede descartar -->
      <div
        v-if="esBorradorCancelado && (auth.isSupervisor || Number(orden.vendedor_id) === Number(auth.usuario?.id))"
        class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 space-y-2"
      >
        <p class="text-xs text-gray-600">
          Este borrador se canceló sin llegar a ser una venta: no tiene número de orden ni pagos.
        </p>
        <button
          @click="showDescartarBorrador = true"
          :disabled="descartandoBorrador"
          class="w-full border border-red-300 text-red-600 rounded-lg py-2 text-sm font-medium hover:bg-red-50 disabled:opacity-50 transition-colors"
        >
          Descartar de la lista
        </button>
      </div>

      <!-- Aviso: orden en despacho -->
      <div
        v-if="orden.estado === 'listo_entrega'"
        class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 flex items-start gap-3"
      >
        <TruckIcon class="w-5 h-5 mt-0.5 text-purple-600 flex-shrink-0" />
        <div>
          <p class="text-sm font-semibold text-purple-800">Orden en cola de despacho</p>
          <p class="text-xs text-purple-600 mt-0.5">Esta orden está lista para entregar. El supervisor debe asignarla a un conductor desde el módulo de Despacho.</p>
        </div>
      </div>

      <div
        v-if="orden.estado === 'en_camino'"
        class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 flex items-start gap-3"
      >
        <TruckIcon class="w-5 h-5 mt-0.5 text-purple-600 flex-shrink-0" />
        <div>
          <p class="text-sm font-semibold text-purple-800">Orden en ruta de despacho</p>
          <p class="text-xs text-purple-600 mt-0.5">Esta orden fue asignada a un conductor para entrega. El estado se actualizará cuando el conductor la marque como entregada.</p>
        </div>
      </div>

      <!-- Acciones -->
      <div v-if="puedeCambiarEstado || puedeRegistrarPago || (tienePersonalizados && !['entregado','cancelado'].includes(orden.estado))" class="space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Acciones</p>

        <!-- Registrar pago -->
        <button
          v-if="puedeRegistrarPago"
          @click="showPagoModal = true"
          class="w-full bg-blue-600 text-white rounded-xl py-3 text-sm font-semibold hover:bg-blue-700 transition-colors"
        >
          Registrar pago
        </button>

        <!-- Aviso: estado controlado por Producción -->
        <div
          v-if="tienePersonalizados && !['entregado','cancelado'].includes(orden.estado)"
          class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 flex items-start gap-3"
        >
          <BuildingOffice2Icon class="w-5 h-5 mt-0.5 text-purple-600 flex-shrink-0" />
          <div>
            <p class="text-sm font-semibold text-purple-800">Estado gestionado desde Producción</p>
            <p class="text-xs text-purple-600 mt-0.5">Esta orden tiene ítems personalizados. El estado se actualiza automáticamente al cambiar el avance en el módulo de Producción.</p>
          </div>
        </div>

        <!-- Deshacer una entrega marcada por error -->
        <div v-if="auth.isSupervisor && orden.estado === 'entregado'" class="space-y-2">
          <button
            v-if="!showRevertir"
            @click="showRevertir = true"
            class="text-xs text-gray-500 hover:text-red-600 hover:underline transition-colors"
          >¿Se marcó entregada por error? Revertir</button>

          <div v-else class="bg-red-50 border border-red-200 rounded-xl p-3 space-y-2">
            <p class="text-xs text-red-800 font-semibold">Revertir la entrega</p>
            <p class="text-[11px] text-red-700 leading-snug">
              El producto vuelve al inventario, reservado para esta orden, y la orden
              regresa a «en espera». Queda registrado con tu nombre.
            </p>
            <input
              v-model="motivoRevertir"
              type="text"
              placeholder="¿Por qué se revierte?"
              class="w-full rounded-lg border border-red-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
            />
            <div class="flex gap-2">
              <button
                @click="showRevertir = false; motivoRevertir = ''"
                class="flex-1 py-2 rounded-lg border border-gray-300 text-xs font-semibold text-gray-600"
              >Cancelar</button>
              <button
                @click="doRevertirEntrega"
                :disabled="revirtiendo || motivoRevertir.trim().length < 3"
                class="flex-1 py-2 rounded-lg bg-red-600 text-white text-xs font-semibold hover:bg-red-700 disabled:opacity-40 flex items-center justify-center gap-1.5"
              >
                <IconoS v-if="revirtiendo" class="w-3.5 h-3.5" />
                Revertir entrega
              </button>
            </div>
          </div>
        </div>

        <!-- Cambiar estado (solo órdenes sin personalizados) -->
        <div v-if="puedeCambiarEstado" class="space-y-2">
          <div class="flex gap-2">
            <select
              v-model="nuevoEstado"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Cambiar estado...</option>
              <option v-for="opt in opcionesNuevoEstado" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
            <button
              @click="cambiarEstado"
              :disabled="!nuevoEstado || changingEstado"
              class="bg-gray-800 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-gray-900 disabled:opacity-50 transition-colors"
            >Aplicar</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Modal: corregir el medio de un pago -->
    <Transition name="fade">
      <div v-if="pagoCorrigiendo" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="pagoCorrigiendo = null">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-3">
          <h3 class="text-lg font-bold text-gray-800">Corregir medio de pago</h3>
          <p class="text-sm text-gray-500">
            {{ tipoPagoLabel[pagoCorrigiendo.tipo] ?? pagoCorrigiendo.tipo }} de
            <strong><MoneyDisplay :amount="pagoCorrigiendo.monto" /></strong>.
            El monto no cambia, solo cómo se pagó.
          </p>

          <div>
            <label class="text-xs text-gray-500">Se pagó con</label>
            <select v-model="medioNuevo" class="input">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="tarjeta">Tarjeta / datáfono</option>
              <option value="otro">Otro</option>
            </select>
          </div>

          <div v-if="medioNuevo !== 'efectivo'">
            <label class="text-xs text-gray-500">Referencia (opcional)</label>
            <input v-model="referenciaNueva" class="input" placeholder="Número de transacción" />
          </div>

          <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Al guardar, la caja de la tienda se ajusta sola: si deja de ser efectivo, esa
            plata sale del saldo de caja. No necesitas registrar ningún egreso.
          </p>

          <p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
            El cambio queda firmado con tu nombre y la hora, visible en la orden.
            No se puede borrar.
          </p>

          <div class="flex gap-2">
            <button @click="pagoCorrigiendo = null" class="btn-secondary flex-1">Cancelar</button>
            <button
              @click="guardarMedio"
              :disabled="guardandoMedio"
              class="btn-primary flex-1 disabled:opacity-50"
            >{{ guardandoMedio ? 'Guardando...' : 'Guardar' }}</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal de pago -->
    <RegistroPagoModal
      v-if="orden"
      :show="showPagoModal"
      :orden-id="orden.id"
      :valor-total="Number(orden.valor_total)"
      :saldo-pendiente="Number(orden.saldo_pendiente)"
      :tienda-orden-id="orden.tienda_id"
      :tienda-orden-nombre="orden.tienda?.nombre ?? ''"
      @close="showPagoModal = false"
      @pago-registrado="onPagoRegistrado"
    />

    <!-- Modal de edición -->
    <EditarOrdenModal
      v-if="orden"
      :show="showEditarModal"
      :orden="orden"
      @close="showEditarModal = false"
      @guardado="onOrdenEditada"
    />

    <!-- Modal confirmar cotización (firma + anticipo) -->
    <Transition name="fade">
      <div v-if="showModalConfirmar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showModalConfirmar = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[90vh] overflow-y-auto">
          <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
            <CheckCircleIcon class="w-5 h-5 text-green-600" />
            Confirmar precio aceptado
          </h3>

          <!-- Foto del anexo firmado (solo si canal físico) -->
          <div v-if="orden?.canal === 'fisica'" class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">
              Foto del anexo firmado <span class="text-red-500">*</span>
            </label>
            <div v-if="anexoConfirmarFile" class="space-y-1.5">
              <div class="relative">
                <img
                  :src="anexoConfirmarPreview"
                  class="w-full rounded-xl border-2 border-gray-200 object-contain bg-gray-50 max-h-40"
                />
                <button
                  @click="quitarAnexoConfirmar"
                  class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow"
                >
                  <XMarkIcon class="w-3.5 h-3.5" />
                </button>
              </div>
              <p class="text-xs text-gray-400 truncate">{{ anexoConfirmarFile.name }}</p>
            </div>
            <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-gray-300 rounded-xl p-4 cursor-pointer hover:border-green-400 hover:bg-green-50 transition-colors">
              <DocumentIcon class="w-7 h-7 text-gray-300" />
              <span class="text-sm text-gray-500">Toca para adjuntar el anexo</span>
              <input type="file" accept="image/*" @change="onAnexoConfirmarChange" class="hidden" />
            </label>
          </div>
          <p v-else class="text-xs text-gray-400 bg-gray-50 rounded-lg px-3 py-2">
            El comprobante de pago será subido por el conductor al momento de la entrega.
          </p>

          <!-- Firma -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">
              Firma del cliente <span class="text-red-500">*</span>
            </label>
            <FirmaCanvas v-model="firmaConfirmarBlob" />
            <p v-if="!firmaConfirmarBlob" class="text-xs text-amber-600">Se requiere la firma para confirmar.</p>
          </div>

          <!-- Anticipo -->
          <div class="space-y-2">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Anticipo</label>

            <!-- Total acordado -->
            <div class="flex justify-between text-sm bg-gray-50 rounded-lg px-3 py-2">
              <span class="text-gray-500">Total acordado</span>
              <span class="font-bold text-gray-800">
                {{ new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(totalAcordado) }}
              </span>
            </div>

            <!-- Porcentaje -->
            <div>
              <label class="block text-xs text-gray-500 mb-1">Porcentaje mínimo anticipo</label>
              <div class="flex gap-2">
                <button
                  v-for="pct in [30, 50, 70, 100]"
                  :key="pct"
                  @click="seleccionarPctAnticipo(pct)"
                  :class="['flex-1 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                    anticipoPctConfirmar === pct ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300']"
                >{{ pct }}%</button>
              </div>
            </div>

            <!-- Monto -->
            <div>
              <label class="block text-xs text-gray-500 mb-1">
                Monto anticipo
                <span class="text-gray-400">(mínimo {{ new Intl.NumberFormat('es-CO').format(minimoAnticipoc) }})</span>
              </label>
              <input
                v-model.number="anticipoConfirmar"
                type="number"
                min="0"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
              />
            </div>

            <!-- Método -->
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="m in metodosOpts"
                :key="m.value"
                @click="metodoPagoConfirmar = m.value"
                :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                  metodoPagoConfirmar === m.value ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300']"
              >{{ m.label }}</button>
            </div>
            <input
              v-if="metodoPagoConfirmar !== 'efectivo' && !confirmarPagoSplit"
              v-model="refPagoConfirmar"
              type="text"
              placeholder="Referencia / número transacción"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
            />
            <!-- Toggle pago en dos métodos -->
            <div v-if="anticipoConfirmar > 0 || confirmarPagoSplit" class="flex items-center justify-between mt-1">
              <span class="text-xs text-gray-500">Pago en dos métodos</span>
              <button type="button" @click="toggleConfirmarPagoSplit"
                :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', confirmarPagoSplit ? 'bg-green-600' : 'bg-gray-200']">
                <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', confirmarPagoSplit ? 'translate-x-6' : 'translate-x-1']" />
              </button>
            </div>
            <template v-if="confirmarPagoSplit">
              <!-- Total a dividir -->
              <div class="bg-gray-50 rounded-xl px-3 py-2 flex items-center justify-between">
                <span class="text-xs text-gray-500">Total anticipo a dividir</span>
                <span class="text-sm font-bold text-gray-800">${{ anticipoConfirmar.toLocaleString('es-CO') }}</span>
              </div>
              <!-- Primer pago: monto editable -->
              <div class="border border-green-200 rounded-xl p-3 space-y-2 bg-green-50/40">
                <p class="text-xs font-semibold text-green-700">Primer pago</p>
                <input v-model.number="confirmarMonto1Input" type="number" min="0" :max="anticipoConfirmar"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400" placeholder="0" />
                <div class="flex gap-2 flex-wrap">
                  <button v-for="m in metodosOpts" :key="m.value" @click="metodoPagoConfirmar = m.value"
                    :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                      metodoPagoConfirmar === m.value ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300']">
                    {{ m.label }}
                  </button>
                </div>
                <input v-if="metodoPagoConfirmar !== 'efectivo'" v-model="refPagoConfirmar" type="text" placeholder="Referencia (opcional)"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400" />
              </div>
              <!-- Segundo pago: monto calculado -->
              <div class="border border-gray-200 rounded-xl p-3 space-y-2">
                <div class="flex items-center justify-between">
                  <p class="text-xs font-semibold text-gray-600">Segundo pago</p>
                  <span class="text-sm font-bold text-gray-700">${{ Math.max(0, anticipoConfirmar - confirmarMonto1Input).toLocaleString('es-CO') }}</span>
                </div>
                <div class="flex gap-2 flex-wrap">
                  <button v-for="m in metodosOpts" :key="m.value" @click="confirmarMetodo2 = m.value"
                    :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                      confirmarMetodo2 === m.value ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300']">
                    {{ m.label }}
                  </button>
                </div>
                <input v-if="confirmarMetodo2 !== 'efectivo'" v-model="confirmarRef2" type="text" placeholder="Referencia (opcional)"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400" />
              </div>
            </template>
          </div>

          <div class="flex gap-3">
            <button @click="showModalConfirmar = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="doConfirmarCotizacion"
              :disabled="!firmaConfirmarBlob || (orden?.canal === 'fisica' && !anexoConfirmarFile && !anexoConfirmarUrl) || confirmando"
              class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-40 transition-colors"
            >
              {{ confirmando ? 'Confirmando...' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal solicitar cotización -->
    <Transition name="fade">
      <div v-if="showModalCotizar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showModalCotizar = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
            <CurrencyDollarIcon class="w-5 h-5 text-violet-600" />
            Solicitar consulta de costo
          </h3>
          <p class="text-sm text-gray-600">
            Selecciona a quién le envías la consulta de costo para los ítems personalizados de esta orden.
          </p>

          <div class="space-y-2">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Enviar a</label>
            <div class="space-y-2">
              <label
                v-for="r in receptores"
                :key="r.id"
                :class="[
                  'flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-colors',
                  cotizarReceptorId === r.id ? 'border-violet-400 bg-violet-50' : 'border-gray-200 hover:border-gray-300'
                ]"
              >
                <input type="radio" :value="r.id" v-model="cotizarReceptorId" class="accent-violet-600" />
                <div>
                  <p class="text-sm font-semibold text-gray-800">{{ r.nombre }}</p>
                  <p class="text-xs text-gray-400 capitalize">{{ r.rol }}</p>
                </div>
              </label>
              <p v-if="!receptores.length" class="text-xs text-gray-400 text-center py-2">
                No hay supervisores ni ebanistas activos.
              </p>
            </div>
          </div>

          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Notas adicionales (opcional)</label>
            <textarea
              v-model="cotizarNotas"
              rows="3"
              placeholder="Instrucciones especiales, materiales sugeridos, urgencia..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 resize-none"
            />
          </div>

          <div class="flex gap-3">
            <button @click="showModalCotizar = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="enviarSolicitudCotizacion"
              :disabled="!cotizarReceptorId || enviandoCotizacion"
              class="flex-1 bg-violet-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-violet-700 disabled:opacity-40 transition-colors"
            >
              {{ enviandoCotizacion ? 'Enviando...' : 'Enviar consulta' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: completar orden borrador -->
    <Transition name="fade">
      <div v-if="showCompletarBorradorModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showCompletarBorradorModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[92vh] overflow-y-auto">
          <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
            <CheckCircleIcon class="w-5 h-5 text-green-600" />
            Completar orden
          </h3>

          <!-- Datos del cliente faltantes (interesado o campos vacíos) -->
          <div v-if="borradorClienteRequiereCompletar" class="bg-amber-50 border border-amber-300 rounded-xl p-4 space-y-3">
            <p class="text-sm font-semibold text-amber-800 flex items-center gap-1.5">
              <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0" />
              {{ orden.cliente?.tipo === 'interesado' ? 'Datos del cliente interesado' : 'Información del cliente incompleta' }}
            </p>
            <div class="space-y-2">
              <div v-if="!orden.cliente?.nombre">
                <label class="text-xs text-gray-500 mb-1 block">Nombre completo <span class="text-red-500">*</span></label>
                <input v-model="borradorFormCliente.nombre" type="text" placeholder="Nombre y apellido" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
              </div>
              <div v-if="!orden.cliente?.cedula">
                <label class="text-xs text-gray-500 mb-1 block">Cédula / NIT <span class="text-red-500">*</span></label>
                <input v-model="borradorFormCliente.cedula" type="text" inputmode="numeric" placeholder="Ej: 1012345678" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
              </div>
              <div v-if="!orden.cliente?.telefono">
                <label class="text-xs text-gray-500 mb-1 block">Teléfono <span class="text-red-500">*</span></label>
                <input v-model="borradorFormCliente.telefono" type="tel" placeholder="Ej: 3001234567" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
              </div>
              <div>
                <label class="text-xs text-gray-500 mb-1 block">Email <span class="text-gray-400 font-normal">(opcional)</span></label>
                <input v-model="borradorFormCliente.email" type="email" placeholder="correo@ejemplo.com" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
              </div>
              <div v-if="!orden.cliente?.direccion">
                <label class="text-xs text-gray-500 mb-1 block">Dirección <span class="text-red-500">*</span></label>
                <input v-model="borradorFormCliente.direccion" type="text" placeholder="Dirección de entrega" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400" />
              </div>
            </div>
            <p v-if="borradorClienteErr" class="text-xs text-red-600">{{ borradorClienteErr }}</p>
            <button
              @click="completarClienteBorrador"
              :disabled="borradorClienteGuardando"
              class="w-full py-2 bg-amber-500 text-white text-xs font-semibold rounded-lg hover:bg-amber-600 disabled:opacity-50 transition-colors flex items-center justify-center gap-1.5"
            >
              {{ borradorClienteGuardando ? 'Guardando...' : 'Guardar datos del cliente' }}
            </button>
          </div>

          <!-- Especificaciones que quedaron sin llenar al guardar el borrador -->
          <div v-if="borradorItemsSinSpecs.length" class="bg-purple-50 border border-purple-300 rounded-xl p-4 space-y-4">
            <div class="flex items-start gap-2">
              <WrenchScrewdriverIcon class="w-4 h-4 text-purple-600 mt-0.5 shrink-0" />
              <div>
                <p class="text-sm font-semibold text-purple-800">
                  {{ borradorItemsSinSpecs.length === 1 ? 'Falta especificar un producto' : `Faltan especificar ${borradorItemsSinSpecs.length} productos` }}
                </p>
                <p class="text-xs text-purple-700">
                  Sin medidas ni acabado el ebanista no puede fabricarlo. Llena al menos lo que ya sepas.
                </p>
              </div>
            </div>

            <div v-for="item in borradorItemsSinSpecs" :key="item.id" class="bg-white rounded-lg border border-purple-200 p-3 space-y-3">
              <p class="text-xs font-semibold text-gray-800">
                {{ item.producto?.nombre ?? item.nombre_custom }}
                <span class="text-gray-400 font-normal">· {{ templateDeItem(item).titulo }}</span>
              </p>

              <div class="grid grid-cols-2 gap-3">
                <div
                  v-for="campo in templateDeItem(item).campos"
                  :key="campo.key"
                  :class="campo.type === 'text' ? 'col-span-2' : ''"
                >
                  <label class="block text-[11px] font-medium text-gray-500 mb-0.5">
                    {{ campo.label }}{{ campo.unit ? ' (' + campo.unit + ')' : '' }}
                  </label>
                  <TelaPicker
                    v-if="campo.useVariantes"
                    :seleccion="telaDe(item.id, campo.key)"
                    :etiqueta="campo.label"
                  />
                  <select
                    v-else-if="campo.type === 'select'"
                    v-model="borradorSpecs[item.id][campo.key]"
                    class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-400"
                  >
                    <option value="">— elegir —</option>
                    <option v-for="opt in campo.options" :key="opt" :value="opt">{{ opt }}</option>
                  </select>
                  <input
                    v-else
                    v-model="borradorSpecs[item.id][campo.key]"
                    :type="campo.type"
                    :placeholder="campo.placeholder"
                    class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-400"
                  />
                </div>
              </div>

              <div>
                <label class="block text-[11px] font-medium text-gray-500 mb-0.5">Notas para el taller</label>
                <textarea
                  v-model="borradorSpecs[item.id].notas"
                  rows="2"
                  placeholder="ej: esquinas redondeadas, sin botones en el respaldo"
                  class="w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-purple-400"
                />
              </div>
            </div>

            <p v-if="!borradorSpecsCompletas" class="text-xs text-purple-700 font-medium">
              Llena al menos un dato de cada producto para poder confirmar.
            </p>
          </div>

          <!-- Firma del cliente -->
          <div class="space-y-2">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Firma del cliente <span class="text-red-500">*</span></label>
            <FirmaCanvas
              v-if="!borradorFirmaUrl"
              v-model="borradorFirmaBlob"
              class="rounded-xl border border-gray-200"
            />
            <div v-else class="relative">
              <img :src="borradorFirmaUrl" class="w-full rounded-xl border border-gray-200 bg-gray-50" />
              <button @click="borradorFirmaUrl = ''; borradorFirmaBlob = null" class="absolute top-1 right-1 bg-white rounded-full p-1 shadow">
                <XMarkIcon class="w-4 h-4 text-gray-500" />
              </button>
            </div>
          </div>

          <!-- Anticipo (solo si no hay cotización pendiente) -->
          <template v-if="!borradorTieneItemsCotiz">
            <div class="space-y-1">
              <label class="block text-xs font-semibold text-gray-600 uppercase">Anticipo</label>
              <input
                v-model.number="borradorForm.anticipo_monto"
                type="number"
                min="0"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
              />
            </div>
            <div class="space-y-1">
              <label class="block text-xs font-semibold text-gray-600 uppercase">Método de pago</label>
              <select v-model="borradorForm.anticipo_metodo" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                <option value="efectivo">Efectivo</option>
                <option value="transferencia">Transferencia</option>
                <option value="tarjeta">Tarjeta</option>
                <option value="otro">Otro</option>
              </select>
            </div>
            <div v-if="borradorForm.anticipo_metodo !== 'efectivo' && !borradorPagoSplit" class="space-y-1">
              <label class="block text-xs font-semibold text-gray-600 uppercase">Referencia (opcional)</label>
              <input
                v-model="borradorForm.anticipo_referencia"
                type="text"
                placeholder="Nro. transacción"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
              />
            </div>
            <!-- Toggle pago en dos métodos -->
            <div v-if="borradorForm.anticipo_monto > 0 || borradorPagoSplit" class="flex items-center justify-between">
              <span class="text-xs font-semibold text-gray-600 uppercase">Pago en dos métodos</span>
              <button type="button" @click="toggleBorradorPagoSplit"
                :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', borradorPagoSplit ? 'bg-blue-600' : 'bg-gray-200']">
                <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', borradorPagoSplit ? 'translate-x-6' : 'translate-x-1']" />
              </button>
            </div>
            <template v-if="borradorPagoSplit">
              <!-- Total a dividir -->
              <div class="bg-gray-50 rounded-xl px-3 py-2 flex items-center justify-between">
                <span class="text-xs text-gray-500">Total anticipo a dividir</span>
                <span class="text-sm font-bold text-gray-800">${{ borradorForm.anticipo_monto.toLocaleString('es-CO') }}</span>
              </div>
              <!-- Primer pago: monto editable -->
              <div class="border border-blue-200 rounded-xl p-3 space-y-2 bg-blue-50/40">
                <p class="text-xs font-semibold text-blue-700">Primer pago</p>
                <input v-model.number="borradorMonto1Input" type="number" min="0" :max="borradorForm.anticipo_monto" placeholder="0"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400" />
                <select v-model="borradorForm.anticipo_metodo"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                  <option value="efectivo">Efectivo</option>
                  <option value="transferencia">Transferencia</option>
                  <option value="tarjeta">Tarjeta</option>
                  <option value="otro">Otro</option>
                </select>
                <input v-if="borradorForm.anticipo_metodo !== 'efectivo'" v-model="borradorForm.anticipo_referencia"
                  type="text" placeholder="Referencia (opcional)"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400" />
              </div>
              <!-- Segundo pago: monto calculado -->
              <div class="border border-gray-200 rounded-xl p-3 space-y-2">
                <div class="flex items-center justify-between">
                  <p class="text-xs font-semibold text-gray-600">Segundo pago</p>
                  <span class="text-sm font-bold text-gray-700">${{ Math.max(0, borradorForm.anticipo_monto - borradorMonto1Input).toLocaleString('es-CO') }}</span>
                </div>
                <select v-model="borradorMetodo2"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400">
                  <option value="efectivo">Efectivo</option>
                  <option value="transferencia">Transferencia</option>
                  <option value="tarjeta">Tarjeta</option>
                  <option value="otro">Otro</option>
                </select>
                <input v-if="borradorMetodo2 !== 'efectivo'" v-model="borradorRef2" type="text" placeholder="Referencia (opcional)"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400" />
              </div>
            </template>
          </template>

          <div v-if="borradorTieneItemsCotiz" class="text-xs text-gray-500 bg-gray-50 rounded-lg p-3">
            Esta orden tiene ítems sin precio. El anticipo se registrará cuando el cliente confirme los precios.
          </div>

          <!-- Comprobante de pago -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Foto del comprobante <span class="text-red-500">*</span></label>
            <div v-if="borradorComprobanteFile || borradorComprobanteUrl" class="relative">
              <img :src="borradorComprobanteUrl || borradorComprobantePreview" class="w-full rounded-xl border border-gray-200 object-contain bg-gray-50 max-h-40" />
              <div v-if="borradorComprobanteUrl && !borradorComprobanteFile" class="absolute top-1 left-1 bg-green-600 text-white text-[10px] font-semibold px-2 py-0.5 rounded-full">Ya subido</div>
              <button @click="borradorComprobanteFile = null; borradorComprobanteUrl = ''; borradorComprobantePreview = ''" class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 shadow">
                <XMarkIcon class="w-4 h-4" />
              </button>
              <p v-if="subiendoComprobante" class="text-xs text-blue-600 mt-1">Subiendo...</p>
            </div>
            <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-amber-300 rounded-xl p-4 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
              <PhotoIcon class="w-7 h-7 text-amber-300" />
              <span class="text-xs text-gray-500">Adjuntar foto del comprobante</span>
              <input type="file" accept="image/*" capture="environment" @change="onBorradorComprobanteChange" class="hidden" />
            </label>
          </div>

          <!-- Dirección de envío -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Dirección de envío <span class="text-red-500">*</span></label>
            <DireccionColombia
              v-model:departamento="borradorForm.departamento_envio"
              v-model:ciudad="borradorForm.ciudad_envio"
              v-model:direccion="borradorForm.direccion_envio"
            />
          </div>

          <!-- Anexo firmado (solo si canal es física) -->
          <div v-if="orden?.canal === 'fisica'" class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Foto del anexo firmado <span class="text-red-500">*</span></label>
            <div v-if="borradorAnexoFile || borradorAnexoUrl" class="relative">
              <img :src="borradorAnexoUrl || borradorAnexoPreview" class="w-full rounded-xl border border-gray-200 object-contain bg-gray-50 max-h-40" />
              <div v-if="borradorAnexoUrl && !borradorAnexoFile" class="absolute top-1 left-1 bg-green-600 text-white text-[10px] font-semibold px-2 py-0.5 rounded-full">Ya subido</div>
              <button @click="borradorAnexoFile = null; borradorAnexoUrl = ''; borradorAnexoPreview = ''" class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 shadow">
                <XMarkIcon class="w-4 h-4" />
              </button>
              <p v-if="subiendoAnexo" class="text-xs text-blue-600 mt-1">Subiendo...</p>
            </div>
            <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-gray-300 rounded-xl p-4 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
              <PhotoIcon class="w-7 h-7 text-gray-300" />
              <span class="text-xs text-gray-500">Adjuntar foto del anexo firmado</span>
              <input type="file" accept="image/*" @change="onBorradorAnexoChange" class="hidden" />
            </label>
          </div>

          <!-- Notas -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase">Notas (opcional)</label>
            <textarea v-model="borradorForm.notas" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 resize-none" />
          </div>

          <div class="flex gap-3">
            <button @click="showCompletarBorradorModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="completarBorrador"
              :disabled="completandoBorrador || subiendoComprobante || subiendoAnexo ||
                borradorClienteRequiereCompletar ||
                !borradorSpecsCompletas ||
                (!borradorFirmaBlob && !borradorFirmaUrl) ||
                (!borradorTieneItemsCotiz && !borradorComprobanteFile && !borradorComprobanteUrl) ||
                !borradorForm.departamento_envio ||
                !borradorForm.ciudad_envio ||
                !borradorForm.direccion_envio ||
                (orden?.canal === 'fisica' && !borradorAnexoFile && !borradorAnexoUrl)"
              class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-40 transition-colors"
            >
              {{ completandoBorrador ? 'Confirmando...' : subiendoComprobante || subiendoAnexo ? 'Subiendo...' : 'Confirmar orden' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Lightbox boceto -->
    <Transition name="fade">
      <div
        v-if="bocetoModal"
        class="fixed inset-0 z-[60] flex items-center justify-center p-6"
        @click.self="bocetoModal = ''"
      >
        <div class="absolute inset-0 bg-black/85" @click="bocetoModal = ''" />
        <div class="relative w-full max-w-lg">
          <button
            @click="bocetoModal = ''"
            class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg"
          >
            <XMarkIcon class="w-5 h-5 text-gray-700" />
          </button>
          <div class="bg-white rounded-2xl overflow-hidden shadow-2xl p-2">
            <img :src="cloudinaryOpt(bocetoModal, 1200)" alt="Boceto" class="w-full object-contain max-h-[70vh] rounded-xl" />
            <button
              @click="descargarBoceto(bocetoModal)"
              class="mt-2 w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2 text-sm font-semibold transition-colors"
            >
              <ArrowDownTrayIcon class="w-4 h-4" />
              Descargar boceto
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Lightbox foto factura -->
    <Transition name="fade">
      <div
        v-if="verFactura"
        class="fixed inset-0 z-[60] flex items-center justify-center p-6"
        @click.self="verFactura = false"
      >
        <div class="absolute inset-0 bg-black/85" @click="verFactura = false" />
        <div class="relative w-full max-w-lg">
          <button
            @click="verFactura = false"
            class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg"
          >
            <XMarkIcon class="w-5 h-5 text-gray-700" />
          </button>
          <div class="bg-white rounded-2xl overflow-hidden shadow-2xl">
            <img
              :src="verFactura"
              alt="Foto"
              class="w-full object-contain max-h-96"
            />
          </div>
        </div>
      </div>
    </Transition>

    <!-- Confirmación: descartar borrador -->
    <Transition name="fade">
      <div v-if="showDescartarBorrador" class="fixed inset-0 z-[70] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50" @click="showDescartarBorrador = false" />
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-5 space-y-4">
          <div class="flex items-start gap-3">
            <ExclamationTriangleIcon class="w-6 h-6 text-red-500 shrink-0" />
            <div>
              <p class="text-sm font-bold text-gray-900">¿Descartar este borrador?</p>
              <p class="text-xs text-gray-600 mt-1">
                Se borra por completo y los productos apartados vuelven al inventario. No se puede recuperar.
              </p>
            </div>
          </div>
          <div class="flex gap-3">
            <button @click="showDescartarBorrador = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              No, dejarlo
            </button>
            <button
              @click="descartarBorrador"
              :disabled="descartandoBorrador"
              class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-red-700 disabled:opacity-50 transition-colors"
            >
              {{ descartandoBorrador ? 'Descartando...' : 'Sí, descartar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
