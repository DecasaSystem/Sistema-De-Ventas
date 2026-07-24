<script setup>
import { ref, computed, watch } from 'vue'
import { editarOrden, editarPago, buscarProductos, getTiendas } from '@/api/ordenes'
import { getVariantes } from '@/api/inventario'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'
import { SPECS_TEMPLATES, resolverCategoria } from '@/constants/specsConfig'
import { XMarkIcon, SparklesIcon, MagnifyingGlassIcon, TrashIcon, PlusIcon, PhotoIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import { comprimirImagen } from '@/utils/comprimirImagen'
import api from '@/api'

const props = defineProps({
  show: Boolean,
  orden: { type: Object, required: true },
})
const emit = defineEmits(['close', 'guardado'])
const toast = useToast()
const auth  = useAuthStore()

const notas          = ref('')
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
const descuentoPctEdit = ref(0)   // descuento global al total, en %
const subtotalEstimado = computed(() => {
  const existentes = items.value
    .filter(i => !itemsEliminar.value.includes(i.id))
    .reduce((s, i) => s + precioEfectivo(i) * (i.cantidad || 1), 0)
  const nuevos = itemsNuevos.value
    .reduce((s, i) => s + (parseFloat(i.precio_unitario) || 0) * (parseInt(i.cantidad) || 1), 0)
  return existentes + nuevos
})
const descuentoTotalEdit = computed(() =>
  Math.round(subtotalEstimado.value * (Number(descuentoPctEdit.value) || 0) / 100)
)
const totalEstimado = computed(() =>
  Math.max(0, subtotalEstimado.value - descuentoTotalEdit.value)
)

// ── Eliminar ítem existente ──────────────────────────────────────────────────
function marcarEliminar(item) {
  const prod = item._produccion
  if (prod && prod.pasos?.some(p => ['en_proceso', 'completado'].includes(p.estado))) {
    toast.error(`"${item.producto_nombre}" ya está en producción y no se puede quitar.`)
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
    modo: 'stock',              // 'stock' | 'personalizado' | 'fabricar' (para productos de catálogo)
    variante_id: null, variante_label: '',
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

function elegirOpcionVC(grupo, item) {
  nuevoVCSelec.value = {
    ...nuevoVCSelec.value,
    [grupo.tipo_variante_id]: {
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
  nuevoItem.value.variante_label = sels.map(s => `${s.tipo_nombre}: ${s.opcion_nombre}`).join(' / ')
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

async function cargarTiendas() {
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

// Consolida la tela elegida por campo del ítem nuevo (fase posterior: picker visual)
function telaResumidaNuevo(key) {
  const s = nuevoItem.value._telaSelections?.[key]
  if (!s?.marca || !s?.tipo || !s?.color) return ''
  return [s.marca, s.tipo, s.color].join(' · ')
}

async function onNuevaFoto(e) {
  const files = Array.from(e.target.files || [])
  if (!files.length) return
  nuevoItem.value._subiendo = true
  try {
    const token = localStorage.getItem('token')
    for (const file of files) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(file), 'boceto.jpg')
      fd.append('folder', 'bocetos')
      const res  = await fetch('/api/upload/foto', { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: fd })
      const data = await res.json()
      if (data.url) nuevoItem.value.boceto_urls.push(data.url)
    }
  } catch { toast.error('No se pudo subir la foto.') }
  finally { nuevoItem.value._subiendo = false; e.target.value = '' }
}
function quitarNuevaFoto(i) {
  nuevoItem.value.boceto_urls.splice(i, 1)
}

function agregarNuevo() {
  const n = nuevoItem.value
  if (n.es_custom) {
    if (!n.nombre_custom.trim()) { toast.error('Ponle un nombre al diseño especial.'); return }
  } else if (!n.producto_id) {
    toast.error('Selecciona un producto.'); return
  }
  if (n.precio_unitario === '' || n.precio_unitario === null || Number(n.precio_unitario) < 0) {
    toast.error('Ingresa el precio del ítem.'); return
  }

  // Consolidar specs (campos del template + telas elegidas + notas)
  const specs = { ...n.specs }
  for (const key of Object.keys(n._telaSelections ?? {})) {
    const tela = telaResumidaNuevo(key)
    if (tela) specs[key] = tela
  }
  if (n.specs_notas) specs.notas = n.specs_notas

  const esPersonalizado = n.es_custom || n.modo === 'personalizado' || n.modo === 'fabricar'

  // Si el producto tiene variantes y es de stock, obliga a elegir una
  if (!esPersonalizado && nuevoVariantes.value.length && !n.variante_id) {
    toast.error('Este producto tiene variantes (tela/color). Elige una antes de agregar.')
    return
  }
  if (!esPersonalizado && nuevoVCGrupos.value.length && !nuevoVCCompleto.value) {
    toast.error('Este producto tiene variantes. Elige una opción de cada grupo.')
    return
  }

  // Validar stock solo para ítems de stock (no producción)
  if (!esPersonalizado && n.stock_libre != null && (parseInt(n.cantidad) || 1) > n.stock_libre) {
    toast.error(`Stock insuficiente: hay ${n.stock_libre} disponible(s) en la tienda seleccionada.`)
    return
  }

  const otraTienda = !esPersonalizado && nuevoTiendaOrigen.value && nuevoTiendaOrigen.value !== props.orden.tienda_id
  itemsNuevos.value.push({
    producto_id:      n.es_custom ? null : n.producto_id,
    variante_id:      !esPersonalizado ? (n.variante_id || null) : null,
    variante_label:   !esPersonalizado ? (n.variante_label || '') : '',
    nombre_custom:    n.es_custom ? n.nombre_custom.trim() : null,
    categoria_custom: n.es_custom ? (n.categoria_custom || null) : null,
    producto_nombre:  n.es_custom ? n.nombre_custom.trim() : n.producto_nombre,
    cantidad:         parseInt(n.cantidad) || 1,
    precio_unitario:  parseFloat(n.precio_unitario),
    es_personalizado: esPersonalizado,
    fabricar_pedido:  !n.es_custom && n.modo === 'fabricar',
    tienda_origen_id: otraTienda ? nuevoTiendaOrigen.value : null,
    tienda_origen_nombre: otraTienda ? (tiendasLista.value.find(t => t.id === nuevoTiendaOrigen.value)?.nombre ?? '') : null,
    specs_personalizacion: Object.keys(specs).length ? specs : null,
    boceto_urls:      [...n.boceto_urls],
    _tipo:            n.es_custom ? 'Diseño especial' : (n.modo === 'fabricar' ? 'Para fabricar' : (n.modo === 'personalizado' ? 'Personalizado' : 'Stock')),
  })
  nuevoItem.value = nuevoItemVacio()
  nuevoQuery.value = ''
}
function quitarNuevo(idx) {
  itemsNuevos.value.splice(idx, 1)
}

function precioEfectivo(item) {
  const base = item.precio_unitario ?? 0
  const pct  = item._descuento_pct ?? 0
  if (!pct) return base
  return Math.round(base * (1 - pct / 100))
}

// ── Especificaciones por categoría (mismos templates que al crear la orden) ──
function getTemplate(item) {
  const nombre = item.producto_nombre || item.nombre_custom
  const cat    = item.producto_categoria || item.categoria_custom
  const key = resolverCategoria(nombre, cat)
  return SPECS_TEMPLATES[key] ?? SPECS_TEMPLATES['generico']
}

// product search per item
const buscando  = ref({})
const resultados = ref({})
const query = ref({})

watch(() => props.show, (v) => {
  if (!v) return
  cargarTelas()
  notas.value          = props.orden.notas ?? ''
  canal.value          = props.orden.canal ?? ''
  direccionEnvio.value = props.orden.direccion_envio ?? ''
  ciudadEnvio.value    = props.orden.ciudad_envio ?? ''
  anticipoPct.value    = props.orden.anticipo_pct ?? ''
  // Derivar el % de descuento desde el monto guardado (subtotal = total + descuento)
  {
    const sub = Number(props.orden.valor_total || 0) + Number(props.orden.descuento_total || 0)
    descuentoPctEdit.value = sub > 0 ? Math.round((Number(props.orden.descuento_total || 0) / sub) * 1000) / 10 : 0
  }

  pagoAnticipo.value       = (props.orden.pagos ?? []).find(p => p.tipo === 'anticipo') ?? null
  anticipoMonto.value      = pagoAnticipo.value?.monto ?? ''
  anticipoMetodo.value     = pagoAnticipo.value?.metodo ?? 'efectivo'
  anticipoReferencia.value = pagoAnticipo.value?.referencia ?? ''

  vendedorId.value   = props.orden.vendedor_id ?? null
  tiendaId.value     = props.orden.tienda_id ?? null
  covendedorId.value = props.orden.covendedor_id ?? null
  esCompartida.value = !!props.orden.es_compartida
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
      producto_nombre: item.producto?.nombre ?? '',
      producto_categoria: item.producto?.categoria ?? null,
      categoria_custom: item.categoria_custom ?? null,
      cantidad: item.cantidad,
      precio_unitario: item.precio_unitario,
      _descuento_pct: 0,
      fecha_entrega_prom: item.fecha_entrega_prom
        ? String(item.fecha_entrega_prom).substring(0, 10)
        : '',
      _produccion: item.produccion ?? null,
      specs: specsRaw,
      specs_notas: notasPrevias,
      _telaSelections: {},
    }
  })
  query.value = {}
  resultados.value = {}
  buscando.value = {}
})

// ── Inventario de telas ──────────────────────────────────────────────────────
const telaMetrosMap = ref({})  // "marca|tipo|color" → metros_libres

async function cargarTelas() {
  try {
    const { data } = await api.get('/inventario-telas')
    const map = {}
    for (const t of data) {
      map[`${t.marca}|${t.tipo}|${t.color}`] = t.metros_libres
    }
    telaMetrosMap.value = map
  } catch {}
}

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

// ── Guardar ──────────────────────────────────────────────────────────────────
async function guardar() {
  if (itemsNuevos.value.some(i => (!i.producto_id && !i.nombre_custom) || i.precio_unitario === '' || i.precio_unitario == null)) {
    toast.error('Completa todos los campos de los ítems nuevos antes de guardar.')
    return
  }
  if (pagoAnticipo.value) {
    const montoNum = parseFloat(anticipoMonto.value)
    if (!montoNum || montoNum <= 0) {
      toast.error('El monto del anticipo debe ser mayor a 0.')
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
      anticipo_pct:    anticipoPct.value !== '' && anticipoPct.value !== null ? Number(anticipoPct.value) : undefined,
      descuento_total: Number(descuentoTotalEdit.value) || 0,
      items: items.value
        .filter(item => !itemsEliminar.value.includes(item.id))
        .map(item => {
          const out = {
            id:               item.id,
            precio_unitario:  precioEfectivo(item),
            fecha_entrega_prom: item.fecha_entrega_prom || null,
          }
          if (item.es_personalizado) {
            const s = { ...item.specs }
            for (const key of Object.keys(item._telaSelections ?? {})) {
              const tela = telaResumidaCampo(item, key)
              if (tela) s[key] = tela
            }
            if (item.specs_notas) s.notas = item.specs_notas
            out.specs_personalizacion = s
          } else {
            out.cantidad    = parseInt(item.cantidad)
            out.producto_id = item.producto_id
          }
          return out
        }),
      items_eliminar: itemsEliminar.value.length ? itemsEliminar.value : undefined,
      items_nuevos: itemsNuevos.value.length
        ? itemsNuevos.value.map(i => ({
            producto_id:      i.producto_id ?? undefined,
            variante_id:      i.variante_id ?? undefined,
            nombre_custom:    i.nombre_custom ?? undefined,
            categoria_custom: i.categoria_custom ?? undefined,
            tienda_origen_id: i.tienda_origen_id ?? undefined,
            cantidad:         parseInt(i.cantidad) || 1,
            precio_unitario:  parseFloat(i.precio_unitario),
            es_personalizado: i.es_personalizado || undefined,
            fabricar_pedido:  i.fabricar_pedido || undefined,
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
              <h3 class="font-bold text-gray-900">Editar orden #{{ orden.numero_orden ?? orden.id }}</h3>
              <p class="text-xs text-gray-500 mt-0.5">Los cambios quedan registrados con tu nombre</p>
            </div>
            <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
              <XMarkIcon class="w-5 h-5 text-gray-500" />
            </button>
          </div>

          <div class="p-5 space-y-5 overflow-y-auto">
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
            <div v-if="pagoAnticipo" class="space-y-3 border border-amber-200 bg-amber-50 rounded-xl p-4">
              <p class="text-xs font-semibold text-amber-700 uppercase">Anticipo</p>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Monto</label>
                  <input
                    v-model="anticipoMonto"
                    type="number"
                    min="0"
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
            <div v-if="esSupervisor" class="space-y-3 border border-blue-200 bg-blue-50 rounded-xl p-4">
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
                  v-if="!itemsEliminar.includes(item.id)"
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

              <!-- Precio + fecha -->
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Precio unitario</label>
                  <input
                    v-model="item.precio_unitario"
                    type="number"
                    min="0"
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

              <!-- Descuento -->
              <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500 flex-shrink-0">Descuento</label>
                <div class="flex items-center gap-1 flex-1">
                  <input
                    v-model.number="item._descuento_pct"
                    type="number"
                    min="0"
                    max="99"
                    step="1"
                    placeholder="0"
                    class="w-20 rounded-lg border border-gray-300 px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  <span class="text-xs text-gray-400">%</span>
                </div>
                <div v-if="item._descuento_pct > 0" class="text-xs text-green-700 bg-green-50 px-2 py-1 rounded-lg font-medium flex-shrink-0">
                  {{ new Intl.NumberFormat('es-CO').format(precioEfectivo(item)) }} c/u
                </div>
              </div>

              <!-- No personalizado: producto + cantidad -->
              <template v-if="!item.es_personalizado">
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
                <div class="space-y-3 pt-1 border-t border-purple-100">
                  <p class="text-xs font-medium text-purple-600">Especificaciones — {{ getTemplate(item).titulo }}</p>

                  <div class="grid grid-cols-2 gap-3">
                    <template v-for="campo in getTemplate(item).campos" :key="campo.key">
                      <div :class="campo.type === 'text' || campo.useVariantes ? 'col-span-2' : ''">
                        <label class="block text-xs font-medium text-gray-600 mb-1">
                          {{ campo.label }}{{ campo.unit ? ' (' + campo.unit + ')' : '' }}
                        </label>

                        <!-- Tela: cascada Marca → Tipo → Color (solo telas con stock) -->
                        <template v-if="campo.useVariantes">
                          <p v-if="item.specs[campo.key]" class="text-xs text-gray-500 mb-1">
                            Actual: <span class="font-medium text-gray-700">{{ item.specs[campo.key] }}</span>
                          </p>
                          <select
                            v-model="getTelaSelection(item, campo.key).marca"
                            @change="getTelaSelection(item, campo.key).tipo = ''; getTelaSelection(item, campo.key).color = ''"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          >
                            <option value="">— sin cambio —</option>
                            <option v-for="m in marcasConStock()" :key="m" :value="m">{{ m }}</option>
                          </select>
                          <select
                            v-if="getTelaSelection(item, campo.key).marca"
                            v-model="getTelaSelection(item, campo.key).tipo"
                            @change="getTelaSelection(item, campo.key).color = ''"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-blue-500"
                          >
                            <option value="">— tipo de tela —</option>
                            <option v-for="t in tiposConStock(getTelaSelection(item, campo.key).marca)" :key="t" :value="t">{{ t }}</option>
                          </select>
                          <select
                            v-if="getTelaSelection(item, campo.key).tipo"
                            v-model="getTelaSelection(item, campo.key).color"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-blue-500"
                          >
                            <option value="">— color —</option>
                            <option v-for="c in coloresConStock(getTelaSelection(item, campo.key).marca, getTelaSelection(item, campo.key).tipo)" :key="c" :value="c">{{ c }}</option>
                          </select>
                          <p v-if="telaResumidaCampo(item, campo.key)" class="text-xs text-purple-600 font-medium mt-1">
                            Nueva selección: {{ telaResumidaCampo(item, campo.key) }}
                          </p>
                        </template>

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

                  <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notas adicionales</label>
                    <textarea
                      v-model="item.specs_notas"
                      rows="2"
                      placeholder="Detalles adicionales de personalización..."
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                    />
                  </div>
                </div>
              </template>

              </template><!-- /v-if !itemsEliminar -->
            </div>

            <!-- Agregar ítem nuevo -->
            <div class="border-2 border-dashed border-blue-200 rounded-xl p-4 space-y-3 bg-blue-50/40">
              <p class="text-xs font-semibold text-blue-700 uppercase flex items-center gap-1.5">
                <PlusIcon class="w-3.5 h-3.5" /> Agregar producto a la orden
              </p>

              <!-- Tienda de la que se busca/saca el stock -->
              <div v-if="tiendasLista.length">
                <label class="block text-[11px] text-gray-500 mb-1">Buscar / sacar stock de</label>
                <select v-model.number="nuevoTiendaOrigen"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option v-for="t in tiendasLista" :key="t.id" :value="t.id">
                    {{ t.id === orden.tienda_id ? t.nombre + ' (tienda de la orden)' : t.nombre }}
                  </option>
                </select>
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
                    × {{ ni.cantidad }} — ${{ Number(ni.precio_unitario).toLocaleString('es-CO') }}
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
              </template>

              <!-- Paso 2: constructor del ítem -->
              <div v-else class="space-y-2.5">
                <div class="flex items-center justify-between">
                  <p class="text-xs font-semibold text-blue-700 truncate">
                    {{ nuevoItem.es_custom ? 'Diseño especial' : nuevoItem.producto_nombre }}
                  </p>
                  <button type="button" @click="nuevoItem = nuevoItemVacio()" class="text-[11px] text-gray-400 underline">Cambiar</button>
                </div>

                <!-- Diseño especial: nombre + categoría -->
                <template v-if="nuevoItem.es_custom">
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
                    Variante (tela/color) <span class="text-red-500">*</span>
                    <span v-if="nuevoCargandoVariantes" class="text-gray-400">· cargando...</span>
                  </label>
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
                      <input v-model="nuevoItem.specs[campo.key]" type="text"
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
                    <input v-model="nuevoItem.precio_unitario" type="number" min="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                </div>

                <button
                  type="button"
                  @click="agregarNuevo"
                  :disabled="nuevoItem._subiendo"
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
                  v-for="p in [5, 10]" :key="p" type="button"
                  @click="descuentoPctEdit = p"
                  class="px-2 py-1 rounded-lg text-xs font-semibold border transition-colors"
                  :class="descuentoPctEdit === p ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600 hover:border-blue-400'"
                >{{ p }}%</button>
                <input
                  v-model.number="descuentoPctEdit"
                  type="number" min="0" max="100" step="0.1"
                  placeholder="0"
                  class="w-16 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <span class="text-xs text-gray-400">%</span>
              </div>
            </div>
            <!-- Total estimado -->
            <div class="flex items-center justify-between text-sm">
              <span class="text-gray-500">Total estimado <span v-if="descuentoTotalEdit > 0" class="text-green-600 font-normal">(− ${{ descuentoTotalEdit.toLocaleString('es-CO') }})</span></span>
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
