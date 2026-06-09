<script setup>
import { ref, computed, watch, onMounted, markRaw } from 'vue'
import { useAuthStore } from '@/stores/auth'
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
  getRecomendaciones,
} from '@/api/surtidos'
import { getStockTienda, crearTraslado, getTraslados } from '@/api/traslados'
import { getTiendas } from '@/api/ordenes'
import { getReservaInfo, getReservaStockLote } from '@/api/reserva'
import { getVariantes } from '@/api/inventario'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import ComboInput from '@/components/common/ComboInput.vue'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'

const toast = useToast()
const auth  = useAuthStore()

function thumbUrl(url, size = 80) {
  if (!url || !url.includes('cloudinary.com')) return url
  return url.replace('/upload/', `/upload/w_${size},h_${size},c_fill,q_auto,f_auto/`)
}

// ── Recomendaciones ───────────────────────────────────────────────────────────
const recomendaciones    = ref([])
const cargandoRecom      = ref(false)
const recomAbiertas      = ref({})    // { tienda_id: bool }
const recomPaginas       = ref({})    // { tienda_id: pageNumber }
const recomCargandoPag   = ref({})    // { tienda_id: bool }
const recomVisible       = ref(false)
const PER_PAGE           = 10

async function cargarRecomendaciones() {
  cargandoRecom.value = true
  try {
    const { data } = await getRecomendaciones({ per_page: PER_PAGE, page: 1 })
    recomendaciones.value = data.map(t => markRaw(t))
    data.forEach(t => {
      recomPaginas.value[t.tienda_id] = 1
    })
  } catch {} finally {
    cargandoRecom.value = false
  }
}

async function cambiarPaginaRecom(tiendaId, nuevaPag) {
  recomCargandoPag.value[tiendaId] = true
  try {
    const { data } = await getRecomendaciones({ per_page: PER_PAGE, page: nuevaPag })
    const tienda = data.find(t => t.tienda_id === tiendaId)
    if (tienda) {
      const idx = recomendaciones.value.findIndex(t => t.tienda_id === tiendaId)
      if (idx !== -1) recomendaciones.value[idx] = markRaw(tienda)
      recomPaginas.value[tiendaId] = nuevaPag
    }
  } catch {} finally {
    recomCargandoPag.value[tiendaId] = false
  }
}

function agregarDesdeRecom(prod) {
  const yaEsta = productosAgr.value.some(p => p.producto.id === prod.producto_id)
  if (!yaEsta) {
    productosAgr.value.push({
      producto: {
        id:        prod.producto_id,
        nombre:    prod.producto_nombre,
        categoria: prod.categoria,
        foto_url:  prod.foto_url,
      },
      cantidad: 1,
      especificaciones: { marca: '', tela: '', color: '', medidas: '', acabado: '', medida: '' },
    })
  }
}

// ── Telas — solo para productos tapizados ────────────────────────────────────
function necesitaTela(prod) {
  return !!prod?.es_tapizado
}

function necesitaTalla(prod) {
  return !!prod?.tiene_tallas
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

// ── Desde fábrica ─────────────────────────────────────────────────────────────
const desdeFabrica   = ref(false)
const fabricaId      = ref(null)
const fabricaStockMap = ref({})   // { producto_id: stock_libre }

onMounted(async () => {
  try { const { data } = await getReservaInfo(); fabricaId.value = data.id } catch {}
})

// ── Paso 1 — Productos ────────────────────────────────────────────────────────
const busquedaProd  = ref('')
const resultados    = ref([])   // máx 10 resultados del servidor
const buscandoProd  = ref(false)
const productosAgr  = ref([])   // [{producto, cantidad, especificaciones}]
const especAbiertos = ref({})   // { index: bool }
let _busqTimer      = null

watch(busquedaProd, (val) => {
  clearTimeout(_busqTimer)
  const term = val.trim()
  if (term.length < 2) { resultados.value = []; return }
  _busqTimer = setTimeout(() => buscarProductos(term), 220)
})

async function buscarProductos(term) {
  buscandoProd.value = true
  try {
    const params = { search: term, limit: 10 }
    if (desdeFabrica.value && fabricaId.value) params.tienda_id = fabricaId.value
    const { data } = await api.get('/productos', { params })
    const yaAgregados = new Set(productosAgr.value.map(p => p.producto.id))
    let lista = (Array.isArray(data) ? data : (data.data ?? []))
      .filter(p => !yaAgregados.has(p.id))
      .map(p => markRaw(p))

    if (desdeFabrica.value) {
      // Solo mostrar los que tienen stock libre en fábrica
      lista = lista.filter(p => (p.stock_disponible ?? 0) - (p.stock_reservado ?? 0) > 0)
      lista.forEach(p => { fabricaStockMap.value[p.id] = (p.stock_disponible ?? 0) - (p.stock_reservado ?? 0) })
    } else if (fabricaId.value && lista.length) {
      // Mostrar badge de fábrica aunque se busque en otra fuente
      const ids = lista.map(p => p.id)
      const { data: stocks } = await getReservaStockLote(ids)
      fabricaStockMap.value = stocks
    }

    resultados.value = lista
  } catch {
    resultados.value = []
  } finally {
    buscandoProd.value = false
  }
}

// ── Picker variantes fábrica (tapizado) ──────────────────────────────────────
const mostrarVariantesFabrica  = ref(false)
const prodParaVariantes        = ref(null)
const variantesFabrica         = ref([])      // variantes con stock en fábrica
const cargandoVariantesFab     = ref(false)
const selecVariantesFab        = ref({})      // { variante_id: cantidad }

async function agregarProducto(prod) {
  if (desdeFabrica.value && (prod.es_tapizado || prod.tiene_tallas)) {
    // Tapizado / talla desde fábrica → mostrar picker de variantes
    prodParaVariantes.value    = prod
    selecVariantesFab.value    = {}
    cargandoVariantesFab.value = true
    mostrarVariantesFabrica.value = true
    busquedaProd.value = ''
    try {
      const { data } = await getVariantes(prod.id, fabricaId.value)
      variantesFabrica.value = data.filter(v => v.stock_libre > 0)
    } finally {
      cargandoVariantesFab.value = false
    }
    return
  }
  // Producto sin variante o no es fábrica — flujo normal
  if (productosAgr.value.some(p => p.producto.id === prod.id && !p._variante_id)) return
  productosAgr.value.push({ producto: prod, cantidad: 1, especificaciones: { marca: '', tela: '', color: '', medidas: '', acabado: '', medida: '' } })
  busquedaProd.value = ''
}

function toggleVarianteFab(v) {
  if (selecVariantesFab.value[v.id] !== undefined) {
    const { [v.id]: _, ...rest } = selecVariantesFab.value
    selecVariantesFab.value = rest
  } else {
    selecVariantesFab.value = { ...selecVariantesFab.value, [v.id]: v.stock_libre }
  }
}

function seleccionarTodasVariantesFab() {
  const todas = {}
  variantesFabrica.value.forEach(v => { todas[v.id] = v.stock_libre })
  selecVariantesFab.value = todas
}

function confirmarVariantesFab() {
  const prod = prodParaVariantes.value
  Object.entries(selecVariantesFab.value).forEach(([vidStr, cant]) => {
    const vid = parseInt(vidStr)
    const v   = variantesFabrica.value.find(x => x.id === vid)
    if (!v) return
    const esTalla = !!v.medida
    const yaEsta = productosAgr.value.some(p => p.producto.id === prod.id && p._variante_id === vid)
    if (!yaEsta) {
      productosAgr.value.push({
        producto: prod,
        _variante_id: vid,
        _variante_label: esTalla
          ? v.medida
          : [v.marca, v.marca_tela, v.nombre_color].filter(Boolean).join(' · '),
        cantidad: Math.max(1, cant),
        especificaciones: esTalla
          ? { marca: '', tela: '', color: '', medidas: '', acabado: '', medida: v.medida }
          : { marca: v.marca ?? '', tela: v.marca_tela ?? '', color: v.nombre_color ?? '', medidas: '', acabado: '', medida: '' },
      })
    }
  })
  mostrarVariantesFabrica.value = false
}

function quitarProducto(idx) {
  productosAgr.value.splice(idx, 1)
}

function toggleEspec(idx) {
  especAbiertos.value[idx] = !especAbiertos.value[idx]
}

const paso1Valido    = computed(() => productosAgr.value.length > 0 && productosAgr.value.every(p => p.cantidad >= 1))
const productosAgrIds = computed(() => new Set(productosAgr.value.map(p => p._variante_id ? `${p.producto.id}-${p._variante_id}` : p.producto.id)))

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
const notasSurtido   = ref('')
const enviando       = ref(false)
const errEnvio       = ref('')
const programarActivo = ref(false)
const programadoPara  = ref('')

const minDatetime = computed(() => {
  const d = new Date(Date.now() + 5 * 60 * 1000)
  return d.toISOString().slice(0, 16)
})

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
      fuente_fabrica: desdeFabrica.value,
      programado_para: (programarActivo.value && programadoPara.value) ? programadoPara.value : null,
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
    if (programarActivo.value && programadoPara.value) {
      const fecha = new Date(programadoPara.value).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
      toast.success(`Surtido programado para el ${fecha}. Los vendedores serán notificados en ese momento.`)
    } else {
      toast.success('Surtido enviado correctamente. Los vendedores han sido notificados.')
    }
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
  tiendasSelec.value        = []
  mismasCantidades.value    = true
  cantidadesPorTienda.value = {}
  vendedoresPorTienda.value = {}
  validadoresPorTienda.value = {}
  notasSurtido.value        = ''
  errEnvio.value            = ''
  busquedaProd.value        = ''
  programarActivo.value     = false
  programadoPara.value      = ''
  desdeFabrica.value        = false
  fabricaStockMap.value     = {}
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
    historial.value = (data.data ?? data).map(s => markRaw(s))
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
    programado:        'bg-purple-100 text-purple-700',
    enviado:           'bg-amber-100 text-amber-700',
    completado:        'bg-green-100 text-green-700',
    rechazado_parcial: 'bg-red-100 text-red-700',
  }
  return map[estado] ?? 'bg-gray-100 text-gray-600'
}

function labelEstado(estado) {
  return {
    programado:        'Programado',
    enviado:           'Enviado',
    completado:        'Completado',
    rechazado_parcial: 'Rechazado parcial',
  }[estado] ?? estado
}

function badgeEstadoTraslado(estado) {
  const map = {
    pendiente:  'bg-amber-100 text-amber-700',
    programado: 'bg-purple-100 text-purple-700',
    completado: 'bg-green-100 text-green-700',
    rechazado:  'bg-red-100 text-red-700',
    fallido:    'bg-red-100 text-red-700',
  }
  return map[estado] ?? 'bg-gray-100 text-gray-600'
}

function labelEstadoTraslado(estado) {
  return { pendiente: 'Pendiente', programado: 'Programado', completado: 'Completado', rechazado: 'Rechazado', fallido: 'Fallido' }[estado] ?? estado
}

function badgeEstadoTienda(estado) {
  const map = { pendiente: 'bg-amber-100 text-amber-700', aceptado: 'bg-green-100 text-green-700', rechazado: 'bg-red-100 text-red-700' }
  return map[estado] ?? 'bg-gray-100 text-gray-600'
}

function fmtFecha(iso) {
  return iso ? new Date(iso).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
}

// ── Traslados entre tiendas ───────────────────────────────────────────────────
const tPaso              = ref(1)
const tOrigenId          = ref('')
const tDestinoId         = ref('')
const tStockOrigen       = ref([])
const tCargandoStock     = ref(false)
const tBusqueda          = ref('')
const tItems             = ref([])
const tNotas             = ref('')
const tEnviando          = ref(false)
const tError             = ref('')
const tProgramarActivo   = ref(false)
const tProgramadoPara    = ref('')
const tValidadorId       = ref(null)
const tVendedoresDest    = ref([])
const tCargandoValidador = ref(false)

// Vendedor: número de pasos = 4 (incluye paso 3 de validador)
// Supervisor: 3 pasos
const tEsVendedor = computed(() => auth.usuario?.rol === 'vendedor')

// historial traslados
const tHistorial      = ref([])
const tCargandoHist   = ref(false)
const tDetalleAbierto = ref({})

const tStockFiltrado = computed(() => {
  const term = tBusqueda.value.trim().toLowerCase()
  const yaAgregados = new Set(tItems.value.map(i => i.producto.producto_id))
  return tStockOrigen.value
    .filter(p => !yaAgregados.has(p.producto_id) &&
      (!term || p.nombre.toLowerCase().includes(term) || (p.categoria ?? '').toLowerCase().includes(term)))
    .slice(0, 30)
})

async function tSeleccionarOrigen(tiendaId) {
  tOrigenId.value  = tiendaId
  tDestinoId.value = ''
  tItems.value     = []
  tBusqueda.value  = ''
  if (!tiendaId) { tStockOrigen.value = []; return }
  tCargandoStock.value = true
  try {
    const { data } = await getStockTienda(tiendaId)
    tStockOrigen.value = data.map(p => markRaw(p))
  } catch {} finally {
    tCargandoStock.value = false
  }
}

// Cuando el vendedor elige destino → cargar vendedores de esa tienda para el validador
watch(tDestinoId, async (tid) => {
  if (!tEsVendedor.value || !tid) return
  tValidadorId.value    = null
  tVendedoresDest.value = []
  tCargandoValidador.value = true
  try {
    const { data } = await getVendedoresTienda(tid)
    tVendedoresDest.value = data
  } catch {} finally {
    tCargandoValidador.value = false
  }
})

function tAgregarProducto(prod) {
  if (tItems.value.some(i => i.producto.producto_id === prod.producto_id)) return
  tItems.value.push({ producto: prod, cantidad: 1 })
  tBusqueda.value = ''
}

function tQuitarProducto(idx) {
  tItems.value.splice(idx, 1)
}

const tPaso1Valido = computed(() => tOrigenId.value && tItems.value.length > 0 && tItems.value.every(i => i.cantidad >= 1))
const tPaso2Valido = computed(() => tDestinoId.value && tDestinoId.value !== tOrigenId.value)
const tPaso3Valido = computed(() => !tEsVendedor.value || !!tValidadorId.value)

// Paso de confirmación: 3 para supervisor, 4 para vendedor
const tPasoConfirm = computed(() => tEsVendedor.value ? 4 : 3)

async function tEnviar() {
  tEnviando.value = true
  tError.value    = ''
  try {
    await crearTraslado({
      tienda_origen_id:       tOrigenId.value,
      tienda_destino_id:      tDestinoId.value,
      notas:                  tNotas.value || null,
      programado_para:        (!tEsVendedor.value && tProgramarActivo.value && tProgramadoPara.value) ? tProgramadoPara.value : null,
      vendedor_validador_id:  tValidadorId.value || null,
      items: tItems.value.map(i => ({ producto_id: i.producto.producto_id, cantidad: i.cantidad })),
    })
    if (tEsVendedor.value) {
      toast.success('Solicitud de traslado enviada. El vendedor de destino recibirá la notificación para aceptarla.')
    } else if (tProgramarActivo.value && tProgramadoPara.value) {
      const fecha = new Date(tProgramadoPara.value).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
      toast.success(`Traslado programado para el ${fecha}. El inventario se actualizará en ese momento.`)
    } else {
      toast.success('Traslado realizado correctamente. El inventario fue actualizado.')
    }
    tResetear()
    tabActivo.value = 'historial-traslados'
    await cargarHistorialTraslados()
  } catch (e) {
    tError.value = e.response?.data?.message ?? 'Error al realizar el traslado.'
  } finally {
    tEnviando.value = false
  }
}

function tResetear() {
  tPaso.value              = 1
  tOrigenId.value          = tEsVendedor.value ? (auth.usuario?.tienda_default_id ?? '') : ''
  tDestinoId.value         = ''
  tStockOrigen.value       = []
  tItems.value             = []
  tNotas.value             = ''
  tError.value             = ''
  tBusqueda.value          = ''
  tProgramarActivo.value   = false
  tProgramadoPara.value    = ''
  tValidadorId.value       = null
  tVendedoresDest.value    = []
}

async function cargarHistorialTraslados() {
  tCargandoHist.value = true
  try {
    const { data } = await getTraslados()
    tHistorial.value = (data.data ?? data).map(t => markRaw(t))
  } catch {} finally {
    tCargandoHist.value = false
  }
}

onMounted(async () => {
  const { data } = await getTiendas()
  tiendas.value = data.filter(t => !t.es_fabrica)
  cargarRecomendaciones()
  // Vendedor: auto-seleccionar su tienda como origen
  if (auth.usuario?.rol === 'vendedor' && auth.usuario?.tienda_default_id) {
    await tSeleccionarOrigen(auth.usuario.tienda_default_id)
    tabActivo.value = 'traslado'
  }
})
</script>

<template>
  <div>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-10">

    <!-- Header -->
    <div class="flex items-center gap-2">
      <ArchiveBoxArrowDownIcon class="w-6 h-6 text-blue-600" />
      <h2 class="text-lg font-bold text-gray-800 flex-1">{{ auth.isSupervisor ? 'Surtir tiendas' : 'Traslados' }}</h2>
    </div>

    <!-- Tabs -->
    <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
      <button
        v-for="tab in (auth.isSupervisor
          ? [
              { k: 'nuevo',               label: 'Nuevo surtido' },
              { k: 'traslado',            label: 'Traslado' },
              { k: 'historial',           label: 'Surtidos' },
              { k: 'historial-traslados', label: 'Traslados' },
            ]
          : [
              { k: 'traslado',            label: 'Nuevo traslado' },
              { k: 'historial-traslados', label: 'Mis traslados' },
            ])"
        :key="tab.k"
        @click="tabActivo = tab.k;
          tab.k === 'historial' && cargarHistorial();
          tab.k === 'historial-traslados' && cargarHistorialTraslados()"
        :class="[
          'flex-1 py-2 rounded-lg text-xs font-semibold transition-colors',
          tabActivo === tab.k ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500',
        ]"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- ═══════════════ PANEL: RECOMENDACIONES (solo supervisor) ═══════════════ -->
    <div v-if="auth.isSupervisor" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
      <!-- Header toggle -->
      <button
        @click="recomVisible = !recomVisible"
        class="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition-colors"
      >
        <div class="flex items-center gap-2">
          <span class="text-sm font-semibold text-gray-800">Recomendaciones de reabastecimiento</span>
          <span
            v-if="!cargandoRecom && recomendaciones.length"
            class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-semibold"
          >
            {{ recomendaciones.length }} tienda(s)
          </span>
          <span v-if="cargandoRecom" class="w-3.5 h-3.5 border-2 border-blue-400 border-t-transparent rounded-full animate-spin inline-block" />
        </div>
        <component :is="recomVisible ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-400 flex-shrink-0" />
      </button>

      <Transition name="slide">
        <div v-if="recomVisible" class="border-t border-gray-100">

          <!-- Cargando -->
          <div v-if="cargandoRecom" class="flex justify-center py-8">
            <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
          </div>

          <!-- Sin alertas -->
          <div v-else-if="recomendaciones.length === 0" class="text-center py-8 text-sm text-gray-400">
            Todas las tiendas tienen stock suficiente
          </div>

          <!-- Lista de tiendas -->
          <div v-else class="divide-y divide-gray-100">
            <div v-for="tienda in recomendaciones" :key="tienda.tienda_id">

              <!-- Cabecera de tienda -->
              <button
                @click="recomAbiertas[tienda.tienda_id] = !recomAbiertas[tienda.tienda_id]"
                class="w-full flex items-center justify-between px-4 py-2.5 hover:bg-gray-50 transition-colors"
              >
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-semibold text-gray-700">{{ tienda.tienda_nombre }}</span>
                  <span v-if="tienda.sin_stock"   class="text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-semibold">{{ tienda.sin_stock }} sin stock</span>
                  <span v-if="tienda.bajo_stock"  class="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-semibold">{{ tienda.bajo_stock }} bajo stock</span>
                  <span v-if="tienda.top_ventas"  class="text-[10px] bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full font-semibold">{{ tienda.top_ventas }} alta rotación</span>
                </div>
                <component :is="recomAbiertas[tienda.tienda_id] ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2" />
              </button>

              <!-- Productos de la tienda -->
              <div v-if="recomAbiertas[tienda.tienda_id]" class="px-3 pb-3 bg-gray-50">

                <!-- Cargando página -->
                <div v-if="recomCargandoPag[tienda.tienda_id]" class="flex justify-center py-5">
                  <div class="w-5 h-5 border-2 border-blue-400 border-t-transparent rounded-full animate-spin" />
                </div>

                <div v-else>
                  <!-- Grid de productos -->
                  <div class="grid grid-cols-2 gap-2 pt-2">
                    <div
                      v-for="prod in tienda.productos"
                      :key="prod.producto_id"
                      class="bg-white rounded-lg p-2.5 border border-gray-200 flex flex-col gap-1.5"
                    >
                      <div class="flex items-start gap-1.5">
                        <img v-if="prod.foto_url" :src="thumbUrl(prod.foto_url, 80)" loading="lazy" class="w-8 h-8 rounded object-cover flex-shrink-0" />
                        <div class="flex-1 min-w-0">
                          <p class="text-xs font-medium text-gray-800 leading-snug line-clamp-2">{{ prod.producto_nombre }}</p>
                          <p class="text-[10px] text-gray-400 mt-0.5 truncate">{{ prod.categoria }}</p>
                        </div>
                      </div>

                      <div class="flex items-center justify-between gap-1">
                        <!-- Badge motivo -->
                        <span :class="[
                          'text-[10px] font-semibold px-1.5 py-0.5 rounded-full truncate',
                          prod.motivo === 'sin_stock'   ? 'bg-red-100 text-red-600' :
                          prod.motivo === 'bajo_stock'  ? 'bg-amber-100 text-amber-700' :
                                                          'bg-blue-100 text-blue-600'
                        ]">
                          {{ prod.motivo === 'sin_stock' ? 'Sin stock' : prod.motivo === 'bajo_stock' ? `${prod.stock_actual} en stock` : `${prod.ventas_mes} vtas/mes` }}
                        </span>

                        <!-- Botón agregar -->
                        <button
                          @click="agregarDesdeRecom(prod); tabActivo = 'nuevo'"
                          :disabled="productosAgrIds.has(prod.producto_id)"
                          class="w-6 h-6 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center hover:bg-blue-200 disabled:opacity-40 disabled:cursor-not-allowed flex-shrink-0"
                          title="Agregar al surtido"
                        >
                          <PlusIcon class="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- Paginación -->
                  <div v-if="tienda.last_page > 1" class="flex items-center justify-between mt-3 px-1">
                    <button
                      @click="cambiarPaginaRecom(tienda.tienda_id, recomPaginas[tienda.tienda_id] - 1)"
                      :disabled="recomPaginas[tienda.tienda_id] <= 1"
                      class="p-1.5 rounded-lg hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                    >
                      <ChevronLeftIcon class="w-4 h-4 text-gray-600" />
                    </button>
                    <span class="text-xs text-gray-500 font-medium">
                      Pág. {{ recomPaginas[tienda.tienda_id] }} / {{ tienda.last_page }}
                    </span>
                    <button
                      @click="cambiarPaginaRecom(tienda.tienda_id, recomPaginas[tienda.tienda_id] + 1)"
                      :disabled="recomPaginas[tienda.tienda_id] >= tienda.last_page"
                      class="p-1.5 rounded-lg hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                    >
                      <ChevronRightIcon class="w-4 h-4 text-gray-600" />
                    </button>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div>
      </Transition>
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

        <!-- Toggle Desde fábrica -->
        <label class="flex items-center gap-3 bg-purple-50 rounded-xl px-4 py-3 cursor-pointer select-none border border-purple-100">
          <button
            type="button"
            @click="desdeFabrica = !desdeFabrica; productosAgr = []; resultados = []; busquedaProd = ''; fabricaStockMap = {}"
            :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0',
              desdeFabrica ? 'bg-purple-600' : 'bg-gray-300']"
          >
            <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
              desdeFabrica ? 'translate-x-6' : 'translate-x-1']" />
          </button>
          <div>
            <p class="text-sm font-semibold text-purple-800">Surtir desde Reserva (Fábrica)</p>
            <p class="text-xs text-purple-600">Los productos se toman del stock de fábrica. Se reservan al enviar y se descuentan al aceptar.</p>
          </div>
        </label>

        <!-- Buscador -->
        <div class="relative">
          <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input
            v-model="busquedaProd"
            placeholder="Buscar producto por nombre o categoría..."
            class="w-full rounded-lg border border-gray-300 pl-9 pr-10 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <!-- Spinner búsqueda -->
          <div v-if="buscandoProd" class="absolute right-3 top-1/2 -translate-y-1/2">
            <div class="w-4 h-4 border-2 border-blue-400 border-t-transparent rounded-full animate-spin" />
          </div>
          <!-- X para limpiar -->
          <button
            v-else-if="busquedaProd"
            @click="busquedaProd = ''; resultados = []"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
          >
            <XMarkIcon class="w-4 h-4" />
          </button>

          <!-- Resultados del servidor -->
          <div v-if="resultados.length" class="absolute inset-x-0 top-full mt-1 bg-white rounded-xl shadow-lg border border-gray-200 z-20 max-h-52 overflow-y-auto">
            <button
              v-for="p in resultados"
              :key="p.id"
              @click="agregarProducto(p)"
              class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-blue-50 transition-colors"
            >
              <img v-if="p.foto_url" :src="thumbUrl(p.foto_url, 80)" loading="lazy" class="w-8 h-8 rounded object-cover flex-shrink-0" />
              <div class="w-8 h-8 rounded bg-gray-100 flex-shrink-0" v-else />
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ p.nombre }}</p>
                <p class="text-xs text-gray-400">{{ p.categoria }}</p>
              </div>
              <div class="flex items-center gap-1.5 flex-shrink-0">
                <span v-if="desdeFabrica && (p.stock_disponible - p.stock_reservado) > 0"
                  class="text-xs font-medium px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-700">
                  {{ p.stock_disponible - p.stock_reservado }} fab.
                </span>
                <span v-else-if="!desdeFabrica && fabricaStockMap[p.id] > 0"
                  class="text-xs font-medium px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-700">
                  Fab: {{ fabricaStockMap[p.id] }}
                </span>
                <PlusIcon class="w-4 h-4 text-blue-500" />
              </div>
            </button>
          </div>
          <!-- Pista mínimo 2 letras -->
          <div v-else-if="busquedaProd.length === 1"
            class="absolute inset-x-0 top-full mt-1 bg-white rounded-xl shadow-lg border border-gray-200 z-20 px-4 py-3 text-xs text-gray-400 text-center">
            Escribe al menos 2 letras...
          </div>
          <!-- Sin resultados -->
          <div v-else-if="busquedaProd.length >= 2 && !buscandoProd && resultados.length === 0"
            class="absolute inset-x-0 top-full mt-1 bg-white rounded-xl shadow-lg border border-gray-200 z-20 px-4 py-3 text-xs text-gray-400 text-center">
            Sin resultados para "{{ busquedaProd }}"
          </div>
        </div>

        <!-- Lista de productos agregados -->
        <div v-if="productosAgr.length === 0" class="text-center py-8 text-sm text-gray-400">
          Busca y agrega productos para surtir
        </div>

        <div
          v-for="(item, idx) in productosAgr"
          :key="`${item.producto.id}-${item._variante_id ?? idx}`"
          v-memo="[item.cantidad, !!especAbiertos[idx], item.especificaciones.marca, item.especificaciones.tela, item.especificaciones.color, item.especificaciones.medidas, item.especificaciones.acabado]"
          class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden"
        >
          <!-- Fila principal -->
          <div class="flex items-center gap-3 px-3 py-3">
            <img v-if="item.producto.foto_url" :src="thumbUrl(item.producto.foto_url, 80)" loading="lazy" class="w-10 h-10 rounded-lg object-cover flex-shrink-0" />
            <div class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0" v-else />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ item.producto.nombre }}</p>
              <p class="text-xs text-gray-400">
                {{ item.producto.categoria }}
                <span v-if="item._variante_label" class="ml-1 text-purple-600 font-medium">· {{ item._variante_label }}</span>
              </p>
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

              <!-- Talla — solo para productos con tallas (ej: colchones) -->
              <template v-else-if="necesitaTalla(item.producto)">
                <div>
                  <label class="text-[11px] font-medium text-gray-500">Talla / Medida</label>
                  <input
                    v-model="item.especificaciones.medida"
                    type="text"
                    placeholder="Ej: 1.00 x 1.80, 1.40 x 1.90…"
                    class="mt-0.5 w-full rounded border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                  <p class="text-[10px] text-gray-400 mt-0.5">El precio varía por talla. Verifica la lista de precios.</p>
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

        <div v-if="desdeFabrica" class="flex items-center gap-2 bg-purple-50 rounded-xl px-4 py-2.5 border border-purple-100">
          <span class="text-purple-700 text-sm font-semibold">Origen: Reserva de Fábrica</span>
          <span class="text-xs text-purple-500">— el stock de fábrica se reserva al enviar y se descuenta al aceptar</span>
        </div>

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

        <!-- Toggle programar -->
        <div class="bg-purple-50 rounded-xl border border-purple-100 overflow-hidden">
          <label class="flex items-center gap-3 px-4 py-3 cursor-pointer">
            <div
              @click="programarActivo = !programarActivo"
              :class="['relative w-10 h-6 rounded-full transition-colors flex-shrink-0', programarActivo ? 'bg-purple-600' : 'bg-gray-300']"
            >
              <span :class="['absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform', programarActivo ? 'translate-x-4' : '']" />
            </div>
            <div>
              <p class="text-sm font-semibold text-gray-700">Programar para más tarde</p>
              <p class="text-xs text-gray-500">El mensaje llega al vendedor en el momento que elijas</p>
            </div>
          </label>
          <Transition name="slide">
            <div v-if="programarActivo" class="px-4 pb-3 border-t border-purple-100">
              <label class="block text-xs font-medium text-gray-600 mb-1.5 mt-2">Fecha y hora de envío</label>
              <input
                v-model="programadoPara"
                type="datetime-local"
                :min="minDatetime"
                class="w-full rounded-lg border border-purple-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
              />
            </div>
          </Transition>
        </div>

        <p v-if="errEnvio" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ errEnvio }}</p>

        <div class="flex gap-2">
          <button @click="retroceder" class="flex items-center gap-1 border border-gray-300 text-gray-600 rounded-xl px-4 py-3 text-sm font-semibold hover:bg-gray-50">
            <ChevronLeftIcon class="w-4 h-4" />
            Atrás
          </button>
          <button
            @click="enviarSurtido"
            :disabled="enviando || (programarActivo && !programadoPara)"
            :class="[
              'flex-1 flex items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold disabled:opacity-50 transition-colors',
              programarActivo ? 'bg-purple-600 hover:bg-purple-700 text-white' : 'bg-green-600 hover:bg-green-700 text-white'
            ]"
          >
            <ArchiveBoxArrowDownIcon class="w-4 h-4" />
            {{ enviando ? (programarActivo ? 'Programando...' : 'Enviando...') : (programarActivo ? 'Programar surtido' : 'Enviar surtido') }}
          </button>
        </div>
      </div>
    </template>

    <!-- ═══════════════ TAB: TRASLADO ═══════════════ -->
    <template v-if="tabActivo === 'traslado'">

      <!-- Paso 1: Seleccionar origen + productos -->
      <div v-if="tPaso === 1" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">¿Qué productos vas a trasladar?</h3>

        <!-- Supervisor: selector de tienda origen -->
        <select
          v-if="!tEsVendedor"
          :value="tOrigenId"
          @change="tSeleccionarOrigen($event.target.value)"
          class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">Seleccionar tienda origen...</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>

        <!-- Vendedor: muestra su tienda como origen (auto) -->
        <div v-else class="flex items-center gap-2 bg-blue-50 rounded-xl px-4 py-2.5 border border-blue-100">
          <span class="text-sm text-blue-700 font-semibold">Desde: {{ tiendas.find(t => t.id == tOrigenId)?.nombre ?? '...' }}</span>
        </div>

        <!-- Stock disponible -->
        <template v-if="tOrigenId">
          <div v-if="tCargandoStock" class="flex justify-center py-6">
            <div class="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
          </div>

          <template v-else>
            <div v-if="tStockOrigen.length === 0" class="text-center py-6 text-sm text-gray-400">
              Esta tienda no tiene stock disponible para trasladar.
            </div>

            <template v-else>
              <!-- Buscador -->
              <div class="relative">
                <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input
                  v-model="tBusqueda"
                  placeholder="Buscar producto..."
                  class="w-full rounded-lg border border-gray-300 pl-9 pr-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              <!-- Lista de stock disponible -->
              <div class="bg-white rounded-xl border border-gray-200 shadow-sm divide-y divide-gray-100 max-h-64 overflow-y-auto">
                <button
                  v-for="prod in tStockFiltrado"
                  :key="prod.producto_id"
                  @click="tAgregarProducto(prod)"
                  class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-blue-50 transition-colors"
                >
                  <img v-if="prod.foto_url" :src="thumbUrl(prod.foto_url, 80)" loading="lazy" class="w-9 h-9 rounded-lg object-cover flex-shrink-0" />
                  <div class="w-9 h-9 rounded-lg bg-gray-100 flex-shrink-0" v-else />
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800 truncate">{{ prod.nombre }}</p>
                    <p class="text-xs text-gray-400">{{ prod.categoria }}</p>
                  </div>
                  <span class="text-xs font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded-full flex-shrink-0">
                    {{ prod.stock_libre }} libres
                  </span>
                  <PlusIcon class="w-4 h-4 text-blue-500 flex-shrink-0" />
                </button>
                <p v-if="tBusqueda && !tStockFiltrado.length" class="px-4 py-3 text-xs text-gray-400 text-center">
                  Sin resultados para "{{ tBusqueda }}"
                </p>
              </div>
            </template>
          </template>
        </template>

        <!-- Productos seleccionados -->
        <div v-if="tItems.length" class="space-y-2">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Productos a trasladar</p>
          <div v-for="(item, idx) in tItems" :key="item.producto.producto_id"
            class="bg-white rounded-xl border border-gray-200 shadow-sm flex items-center gap-3 px-3 py-3">
            <img v-if="item.producto.foto_url" :src="thumbUrl(item.producto.foto_url, 80)" loading="lazy" class="w-10 h-10 rounded-lg object-cover flex-shrink-0" />
            <div class="w-10 h-10 rounded-lg bg-gray-100 flex-shrink-0" v-else />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ item.producto.nombre }}</p>
              <p class="text-xs text-gray-400">Máx. {{ item.producto.stock_libre }} unidades</p>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
              <button @click="item.cantidad > 1 && item.cantidad--" class="w-7 h-7 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center hover:bg-gray-200">−</button>
              <input
                v-model.number="item.cantidad"
                type="number"
                :min="1"
                :max="item.producto.stock_libre"
                class="w-12 text-center rounded border border-gray-300 py-1 text-sm font-bold focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              <button
                @click="item.cantidad < item.producto.stock_libre && item.cantidad++"
                class="w-7 h-7 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center hover:bg-gray-200">+</button>
            </div>
            <button @click="tQuitarProducto(idx)" class="text-red-400 hover:text-red-600 flex-shrink-0 ml-1">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>
        </div>

        <button
          @click="tPaso = 2"
          :disabled="!tPaso1Valido"
          class="w-full flex items-center justify-center gap-2 bg-blue-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-blue-700 disabled:opacity-50 transition-colors"
        >
          Siguiente: elegir destino
          <ChevronRightIcon class="w-4 h-4" />
        </button>
      </div>

      <!-- Paso 2: Seleccionar destino -->
      <div v-else-if="tPaso === 2" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">¿A qué tienda se envían los productos?</h3>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm divide-y divide-gray-100">
          <label
            v-for="t in tiendas.filter(t => String(t.id) !== String(tOrigenId))"
            :key="t.id"
            class="flex items-center gap-3 px-4 py-3 cursor-pointer"
          >
            <input type="radio" :value="String(t.id)" v-model="tDestinoId" class="w-4 h-4 text-blue-600" />
            <span class="text-sm font-medium text-gray-800">{{ t.nombre }}</span>
            <span v-if="t.ciudad" class="text-xs text-gray-400">{{ t.ciudad }}</span>
          </label>
        </div>

        <div class="flex gap-2">
          <button @click="tPaso = 1" class="flex items-center gap-1 border border-gray-300 text-gray-600 rounded-xl px-4 py-3 text-sm font-semibold hover:bg-gray-50">
            <ChevronLeftIcon class="w-4 h-4" />Atrás
          </button>
          <button
            @click="tPaso = tEsVendedor ? 3 : tPasoConfirm"
            :disabled="!tPaso2Valido"
            class="flex-1 flex items-center justify-center gap-2 bg-blue-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {{ tEsVendedor ? 'Siguiente: validador' : 'Revisar traslado' }}
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Paso 3 (solo vendedor): Seleccionar validador en tienda destino -->
      <div v-else-if="tPaso === 3 && tEsVendedor" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">¿Quién confirma la llegada en destino?</h3>
        <p class="text-xs text-gray-500">Selecciona el vendedor de la tienda destino que validará que los productos llegaron.</p>

        <div v-if="tCargandoValidador" class="flex justify-center py-6">
          <div class="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
        </div>

        <div v-else class="bg-white rounded-xl border border-gray-200 shadow-sm divide-y divide-gray-100">
          <label
            v-for="v in tVendedoresDest"
            :key="v.id"
            class="flex items-center gap-3 px-4 py-3 cursor-pointer"
          >
            <input type="radio" :value="v.id" v-model="tValidadorId" class="w-4 h-4 text-blue-600" />
            <div>
              <p class="text-sm font-medium text-gray-800">{{ v.nombre }}</p>
              <p v-if="v.tienda_default?.nombre" class="text-xs text-gray-400">{{ v.tienda_default.nombre }}</p>
            </div>
          </label>
          <p v-if="!tCargandoValidador && !tVendedoresDest.length" class="px-4 py-3 text-xs text-gray-400 text-center">
            No hay vendedores activos en la tienda destino.
          </p>
        </div>

        <div class="flex gap-2">
          <button @click="tPaso = 2" class="flex items-center gap-1 border border-gray-300 text-gray-600 rounded-xl px-4 py-3 text-sm font-semibold hover:bg-gray-50">
            <ChevronLeftIcon class="w-4 h-4" />Atrás
          </button>
          <button
            @click="tPaso = 4"
            :disabled="!tPaso3Valido"
            class="flex-1 flex items-center justify-center gap-2 bg-blue-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            Revisar traslado
            <ChevronRightIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Paso de confirmación: 3 para supervisor, 4 para vendedor -->
      <div v-else-if="tPaso === tPasoConfirm" class="space-y-3">
        <h3 class="text-sm font-semibold text-gray-700">Revisa el traslado antes de confirmar</h3>

        <!-- Resumen origen → destino -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 space-y-3">
          <div class="flex items-center justify-between text-sm">
            <div class="text-center flex-1">
              <p class="text-xs text-gray-400 mb-0.5">Origen</p>
              <p class="font-bold text-gray-800">{{ tiendas.find(t => String(t.id) === String(tOrigenId))?.nombre }}</p>
            </div>
            <div class="flex flex-col items-center px-3">
              <ChevronRightIcon class="w-5 h-5 text-blue-500" />
            </div>
            <div class="text-center flex-1">
              <p class="text-xs text-gray-400 mb-0.5">Destino</p>
              <p class="font-bold text-blue-700">{{ tiendas.find(t => String(t.id) === String(tDestinoId))?.nombre }}</p>
            </div>
          </div>

          <!-- Validador (solo vendedor) -->
          <div v-if="tEsVendedor && tValidadorId" class="flex items-center gap-2 border-t border-gray-100 pt-2">
            <span class="text-xs text-gray-400">Valida:</span>
            <span class="text-xs font-semibold text-gray-700">
              {{ tVendedoresDest.find(v => v.id === tValidadorId)?.nombre }}
            </span>
          </div>

          <div class="space-y-1 border-t border-gray-100 pt-3">
            <div v-for="item in tItems" :key="item.producto.producto_id"
              class="flex items-center gap-2 bg-gray-50 rounded-lg px-2.5 py-1.5 text-xs">
              <span class="flex-1 text-gray-700 font-medium truncate">{{ item.producto.nombre }}</span>
              <span class="text-gray-400">{{ item.producto.categoria }}</span>
              <span class="font-bold text-green-700 flex-shrink-0">× {{ item.cantidad }}</span>
            </div>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Notas (opcional)</label>
          <textarea
            v-model="tNotas"
            rows="2"
            placeholder="Motivo del traslado, instrucciones especiales..."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
          />
        </div>

        <!-- Toggle programar (solo supervisor) -->
        <div v-if="!tEsVendedor" class="bg-purple-50 rounded-xl border border-purple-100 overflow-hidden">
          <label class="flex items-center gap-3 px-4 py-3 cursor-pointer">
            <div
              @click="tProgramarActivo = !tProgramarActivo"
              :class="['relative w-10 h-6 rounded-full transition-colors flex-shrink-0', tProgramarActivo ? 'bg-purple-600' : 'bg-gray-300']"
            >
              <span :class="['absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform', tProgramarActivo ? 'translate-x-4' : '']" />
            </div>
            <div>
              <p class="text-sm font-semibold text-gray-700">Programar para más tarde</p>
              <p class="text-xs text-gray-500">El inventario se moverá en el momento que elijas</p>
            </div>
          </label>
          <Transition name="slide">
            <div v-if="tProgramarActivo" class="px-4 pb-3 border-t border-purple-100">
              <label class="block text-xs font-medium text-gray-600 mb-1.5 mt-2">Fecha y hora del traslado</label>
              <input
                v-model="tProgramadoPara"
                type="datetime-local"
                :min="minDatetime"
                class="w-full rounded-lg border border-purple-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
              />
            </div>
          </Transition>
        </div>

        <!-- Aviso para vendedor: traslado quedará pendiente -->
        <div v-else class="flex items-start gap-2 bg-amber-50 rounded-xl px-4 py-3 border border-amber-100">
          <span class="text-amber-600 text-xs leading-relaxed">
            El traslado quedará <strong>pendiente</strong> hasta que el validador lo acepte en la tienda destino. El inventario se moverá en ese momento.
          </span>
        </div>

        <p v-if="tError" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ tError }}</p>

        <div class="flex gap-2">
          <button @click="tPaso--" class="flex items-center gap-1 border border-gray-300 text-gray-600 rounded-xl px-4 py-3 text-sm font-semibold hover:bg-gray-50">
            <ChevronLeftIcon class="w-4 h-4" />Atrás
          </button>
          <button
            @click="tEnviar"
            :disabled="tEnviando || (!tEsVendedor && tProgramarActivo && !tProgramadoPara)"
            class="flex-1 flex items-center justify-center gap-2 bg-green-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-green-700 disabled:opacity-50 transition-colors"
          >
            <ArchiveBoxArrowDownIcon class="w-4 h-4" />
            {{ tEnviando ? 'Enviando...' : (tEsVendedor ? 'Enviar solicitud' : (tProgramarActivo ? 'Programar traslado' : 'Confirmar traslado')) }}
          </button>
        </div>
      </div>
    </template>

    <!-- ═══════════════ TAB: HISTORIAL TRASLADOS ═══════════════ -->
    <template v-else-if="tabActivo === 'historial-traslados'">
      <div v-if="tCargandoHist" class="flex justify-center py-10">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>
      <div v-else-if="tHistorial.length === 0" class="text-center py-10 text-sm text-gray-400">
        No hay traslados registrados.
      </div>
      <div v-else class="space-y-3">
        <div v-for="tr in tHistorial" :key="tr.id" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
          <button
            @click="tDetalleAbierto[tr.id] = !tDetalleAbierto[tr.id]"
            class="w-full flex items-center justify-between px-4 py-3 text-left"
          >
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <p class="text-sm font-semibold text-gray-800">Traslado #{{ tr.id }}</p>
                <span class="text-xs text-gray-500">
                  {{ tr.tienda_origen?.nombre }} → {{ tr.tienda_destino?.nombre }}
                </span>
                <span v-if="tr.estado" :class="['px-2 py-0.5 rounded-full text-xs font-semibold', badgeEstadoTraslado(tr.estado)]">
                  {{ labelEstadoTraslado(tr.estado) }}
                </span>
              </div>
              <p class="text-xs text-gray-400 mt-0.5">
                <template v-if="tr.estado === 'programado' && tr.programado_para">
                  Programado para {{ fmtFecha(tr.programado_para) }} ·
                </template>
                <template v-else>
                  {{ fmtFecha(tr.created_at) }} ·
                </template>
                {{ tr.items?.length ?? 0 }} producto(s)
                <template v-if="tr.vendedor_validador?.nombre"> · Valida: {{ tr.vendedor_validador.nombre }}</template>
                <template v-else-if="tr.supervisor?.nombre"> · {{ tr.supervisor.nombre }}</template>
              </p>
            </div>
            <component :is="tDetalleAbierto[tr.id] ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2" />
          </button>

          <Transition name="slide">
            <div v-if="tDetalleAbierto[tr.id]" class="border-t border-gray-100 px-4 pb-4 pt-3 space-y-2">
              <div v-for="item in tr.items" :key="item.id"
                class="flex items-center gap-2 bg-gray-50 rounded-lg px-2.5 py-1.5 text-xs">
                <span class="flex-1 text-gray-700 font-medium truncate">{{ item.producto?.nombre }}</span>
                <span class="text-gray-400 text-[10px]">{{ item.producto?.categoria }}</span>
                <span class="font-bold text-green-700 flex-shrink-0">× {{ item.cantidad }}</span>
              </div>
              <p v-if="tr.notas" class="text-xs text-gray-500 italic pt-1">Notas: "{{ tr.notas }}"</p>
            </div>
          </Transition>
        </div>
      </div>
    </template>

    <!-- ═══════════════ TAB: HISTORIAL SURTIDOS ═══════════════ -->
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
                <template v-if="s.estado === 'programado' && s.programado_para">
                  Programado para {{ fmtFecha(s.programado_para) }} ·
                </template>
                <template v-else>
                  {{ fmtFecha(s.created_at) }} ·
                </template>
                {{ s.tiendas?.length ?? 0 }} tienda(s)
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
                <p v-if="st.notas_vendedor && st.estado === 'rechazado'" class="px-3 pb-2 text-xs text-red-600 italic">
                  Motivo rechazo: "{{ st.notas_vendedor }}"
                </p>
                <p v-else-if="st.notas_vendedor && st.estado === 'aceptado'" class="px-3 pb-2 text-xs text-green-700 italic">
                  Nota de recepción: "{{ st.notas_vendedor }}"
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

  <!-- ── Modal: picker de variantes fábrica (tapizado) ── -->
  <Transition name="fade">
    <div v-if="mostrarVariantesFabrica" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/40" @click="mostrarVariantesFabrica = false" />

      <!-- Panel -->
      <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[80vh] flex flex-col overflow-hidden">

        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
          <div class="min-w-0">
            <p class="text-sm font-bold text-gray-800">Variantes disponibles en fábrica</p>
            <p class="text-xs text-gray-400 truncate">{{ prodParaVariantes?.nombre }}</p>
          </div>
          <button @click="mostrarVariantesFabrica = false" class="text-gray-400 hover:text-gray-600 ml-3 flex-shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-y-auto px-4 py-3 space-y-2">

          <!-- Cargando -->
          <div v-if="cargandoVariantesFab" class="flex justify-center py-8">
            <div class="w-6 h-6 border-2 border-purple-500 border-t-transparent rounded-full animate-spin" />
          </div>

          <!-- Sin variantes con stock -->
          <div v-else-if="variantesFabrica.length === 0" class="text-center py-8 text-sm text-gray-400">
            No hay variantes con stock disponible en fábrica para este producto.
          </div>

          <!-- Lista de variantes -->
          <template v-else>
            <button
              @click="seleccionarTodasVariantesFab"
              class="text-xs text-purple-600 font-semibold hover:underline"
            >
              Seleccionar todas ({{ variantesFabrica.length }})
            </button>

            <div
              v-for="v in variantesFabrica"
              :key="v.id"
              @click="toggleVarianteFab(v)"
              :class="[
                'flex items-center gap-3 rounded-xl border px-3 py-2.5 cursor-pointer transition-colors',
                selecVariantesFab[v.id] !== undefined
                  ? 'border-purple-400 bg-purple-50'
                  : 'border-gray-200 bg-white hover:bg-gray-50',
              ]"
            >
              <!-- Checkbox visual -->
              <div :class="[
                'w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 transition-colors',
                selecVariantesFab[v.id] !== undefined ? 'border-purple-500 bg-purple-500' : 'border-gray-300',
              ]">
                <CheckIcon v-if="selecVariantesFab[v.id] !== undefined" class="w-3 h-3 text-white" />
              </div>

              <!-- Info variante -->
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">
                  <template v-if="v.medida">
                    {{ v.medida }}
                    <span v-if="v.precio_variante" class="text-blue-600 ml-1">— ${{ (Number(v.precio_variante) / 1000).toFixed(0) }}k</span>
                  </template>
                  <template v-else>
                    {{ [v.marca, v.marca_tela, v.nombre_color].filter(Boolean).join(' · ') || 'Sin especificación' }}
                  </template>
                </p>
                <p class="text-xs text-purple-600 font-semibold">{{ v.stock_libre }} disponibles</p>
              </div>

              <!-- Ajuste de cantidad si está seleccionada -->
              <div v-if="selecVariantesFab[v.id] !== undefined" class="flex items-center gap-1 flex-shrink-0" @click.stop>
                <button
                  @click="selecVariantesFab[v.id] > 1 && selecVariantesFab[v.id]--"
                  class="w-6 h-6 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm hover:bg-gray-300"
                >−</button>
                <input
                  v-model.number="selecVariantesFab[v.id]"
                  type="number"
                  :min="1"
                  :max="v.stock_libre"
                  class="w-12 text-center rounded border border-gray-300 py-0.5 text-xs font-bold focus:outline-none focus:ring-1 focus:ring-purple-500"
                />
                <button
                  @click="selecVariantesFab[v.id] < v.stock_libre && selecVariantesFab[v.id]++"
                  class="w-6 h-6 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-sm hover:bg-gray-300"
                >+</button>
              </div>
              <span v-else class="text-xs text-gray-300 flex-shrink-0">{{ v.stock_libre }} disp.</span>
            </div>
          </template>
        </div>

        <!-- Footer -->
        <div class="px-4 py-3 border-t border-gray-100 flex gap-2">
          <button
            @click="mostrarVariantesFabrica = false"
            class="flex-1 border border-gray-300 text-gray-600 rounded-xl py-2.5 text-sm font-semibold hover:bg-gray-50"
          >
            Cancelar
          </button>
          <button
            @click="confirmarVariantesFab"
            :disabled="Object.keys(selecVariantesFab).length === 0"
            class="flex-1 bg-purple-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-purple-700 disabled:opacity-50 transition-colors"
          >
            Agregar{{ Object.keys(selecVariantesFab).length > 0 ? ` (${Object.keys(selecVariantesFab).length})` : '' }}
          </button>
        </div>

      </div>
    </div>
  </Transition>
  </div>
</template>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: all 0.18s ease; }
.slide-enter-from, .slide-leave-to       { opacity: 0; transform: translateY(-5px); }
.fade-enter-active, .fade-leave-active   { transition: opacity 0.18s ease; }
.fade-enter-from, .fade-leave-to         { opacity: 0; }
</style>
