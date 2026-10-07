<script setup>
/**
 * Inventario por cantidad: las telas por metros, y todo lo que la empresa
 * cree a partir de este módulo (espumas por láminas, hilos por conos...).
 *
 * Con `clave = 'telas'` es el módulo de siempre: va contra /inventario-telas,
 * tiene la pestaña de consumo por producto y lo que se aparta desde las
 * órdenes. Con cualquier otra clave es una copia: mismo aspecto, mismos
 * botones, pero sus ítems viven aparte (/modulos/{clave}/items), se miden en
 * la unidad que la empresa le puso y nadie los aparta desde una venta.
 */
import { cloudinaryOpt } from '@/utils/cloudinary'
import { ref, computed, onMounted, watch } from 'vue'
import { MagnifyingGlassIcon, PlusIcon, MinusIcon, ArrowDownTrayIcon, PhotoIcon, XMarkIcon, TrashIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { useModulosStore } from '@/stores/modulos'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { TELAS_CATALOGO, asegurarCatalogoDB } from '@/data/telasCatalogo'
import { exportarExcel } from '@/utils/exportarExcel'
import ConsumoTelasPanel from '@/components/inventario/ConsumoTelasPanel.vue'
// Lee TELAS_CATALOGO directo: las telas de la base se piden al entrar.
asegurarCatalogoDB()

const props = defineProps({
  /** 'telas' es el módulo de siempre; otra clave, uno creado a partir de él. */
  clave: { type: String, default: 'telas' },
})

const auth    = useAuthStore()
const modulos = useModulosStore()
const toast   = useToast()

// ── Qué módulo es y cómo habla ───────────────────────────────────────────────
const esBase = computed(() => props.clave === 'telas')
const nombre = computed(() => modulos.nombre(props.clave, 'Telas'))
const cfg    = computed(() => {
  const c = esBase.value ? {} : modulos.config(props.clave)
  return {
    unidad:    c.unidad    || 'm',
    singular:  c.singular  || 'tela',
    decimales: Number.isInteger(c.decimales) ? c.decimales : 2,
  }
})
// Sólo la tela de verdad se mide en metros con centímetros; una copia que
// también use metros recibe la misma ayuda.
const enMetros = computed(() => cfg.value.unidad === 'm' && cfg.value.decimales === 2)

// Las telas y las copias tienen la misma pantalla pero distinta puerta.
const rutas = computed(() => esBase.value
  ? {
      lista:       '/inventario-telas',
      proveedores: '/inventario-telas/proveedores',
      crear:       '/catalogo-telas',
      editar:      id => `/catalogo-telas/${id}`,
      eliminar:    id => `/catalogo-telas/${id}`,
      recargar:    '/inventario-telas/recargar',
      descontar:   '/inventario-telas/descontar',
      campo:       'metros',
      campoInicial: 'metros_iniciales',
      carpetaFoto: 'telas',
    }
  : {
      lista:       `/modulos/${props.clave}/items`,
      proveedores: `/modulos/${props.clave}/items/proveedores`,
      crear:       `/modulos/${props.clave}/items`,
      editar:      id => `/modulos/${props.clave}/items/${id}`,
      eliminar:    id => `/modulos/${props.clave}/items/${id}`,
      recargar:    `/modulos/${props.clave}/items/recargar`,
      descontar:   `/modulos/${props.clave}/items/descontar`,
      campo:       'cantidad',
      campoInicial: 'cantidad_inicial',
      carpetaFoto: 'modulos',
    })

/**
 * Lo que devuelve cada puerta, con un solo nombre para la pantalla: las
 * telas hablan de metros y las copias de cantidad, pero el badge y los
 * botones son los mismos.
 */
function adaptar(t) {
  return {
    ...t,
    disponible: Number(t.metros_disponibles ?? t.cantidad_disponible ?? 0),
    reservado:  Number(t.metros_reservados  ?? t.cantidad_reservada  ?? 0),
    libre:      Number(t.metros_libres      ?? t.cantidad_libre      ?? 0),
  }
}

// 'inventario' = los rollos y sus metros; 'consumo' = cuánta tela lleva cada
// producto tapizado y el interruptor del descuento automático. Sólo las
// telas de verdad tienen consumo: es lo que las amarra a las órdenes.
const pestana = ref('inventario')

// ── Foto ─────────────────────────────────────────────────────────────────────
const subiendoFotoCrear = ref(false)
const fotoModal = ref('')   // url de la foto ampliada

async function subirFoto(file) {
  const fd = new FormData()
  fd.append('foto', await comprimirImagen(file), 'foto.jpg')
  fd.append('folder', rutas.value.carpetaFoto)
  const { data } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  return data.url
}

async function onFotoCrear(e) {
  const file = e.target.files[0]
  if (!file) return
  subiendoFotoCrear.value = true
  try {
    crearForm.value.foto_url = await subirFoto(file)
  } catch {
    toast.error('No se pudo subir la foto.')
  } finally {
    subiendoFotoCrear.value = false
    e.target.value = ''
  }
}

const subiendoFotoDe = ref(null)   // id del ítem cuya foto se está subiendo
async function onFotoExistente(item, e) {
  const file = e.target.files[0]
  if (!file) return
  subiendoFotoDe.value = item.id
  try {
    const url = await subirFoto(file)
    await api.patch(rutas.value.editar(item.id), { foto_url: url })
    item.foto_url = url
    toast.success('Foto actualizada.')
  } catch {
    toast.error('No se pudo guardar la foto.')
  } finally {
    subiendoFotoDe.value = null
    e.target.value = ''
  }
}

const items          = ref([])
const proveedores    = ref([])
const busqueda       = ref('')
const proveedorFiltro = ref('')
const cargando       = ref(true)
const showModal      = ref(false)
const modalTipo      = ref('recargar')
const itemActivo     = ref(null)
const cantidad       = ref('')
const nota           = ref('')
const guardando      = ref(false)
const modalError     = ref('')

// Modal creación
const showCrear  = ref(false)
const creando    = ref(false)
const crearError = ref('')
const crearForm  = ref({ marca: '', marcaNueva: '', tipo: '', color: '', referencia: '', textura: '', cantidad: '', foto_url: '' })

const puedeRecargar  = computed(() => auth.puedeRecargarTelas)
const puedeDescontar = computed(() => auth.puedeUsarTelas)

// Metros con centímetros: "20.45" = 20 m 45 cm. Acepta coma o punto, y tantos
// decimales como tenga la unidad del módulo (ninguno si se cuenta por unidades).
function normalizarCantidad(valor) {
  let v = String(valor ?? '').replace(',', '.').replace(/[^\d.]/g, '')
  const i = v.indexOf('.')
  if (i !== -1) {
    v = cfg.value.decimales === 0
      ? v.slice(0, i)
      : v.slice(0, i + 1) + v.slice(i + 1).replace(/\./g, '').slice(0, cfg.value.decimales)
  }
  return v
}

function redondear(n) {
  const f = 10 ** cfg.value.decimales
  return Math.round((parseFloat(n) || 0) * f) / f
}

const itemsFiltrados = computed(() => {
  let lista = items.value
  if (proveedorFiltro.value) {
    lista = lista.filter(t => t.marca === proveedorFiltro.value)
  }
  if (busqueda.value.trim()) {
    const q = busqueda.value.toLowerCase()
    lista = lista.filter(t =>
      t.referencia?.toLowerCase().includes(q) ||
      t.tipo?.toLowerCase().includes(q) ||
      t.color?.toLowerCase().includes(q) ||
      t.marca?.toLowerCase().includes(q) ||
      t.textura?.toLowerCase().includes(q)
    )
  }
  return lista
})

async function cargar() {
  cargando.value = true
  try {
    const [{ data: lista }, { data: provData }] = await Promise.all([
      api.get(rutas.value.lista),
      api.get(rutas.value.proveedores),
    ])
    items.value       = lista.map(adaptar)
    proveedores.value = provData
  } catch {
    toast.error(`Error al cargar el inventario de ${nombre.value.toLowerCase()}.`)
  } finally {
    cargando.value = false
  }
}

// Una tela (nombre + referencia) se crea con todos sus colores de una vez,
// cada uno con lo que llegó de él. Otra referencia es otra tela: otro formulario.
const colorVacio = () => ({ color: '', cantidad: '' })
const formVacio  = () => ({ marca: '', marcaNueva: '', tipo: '', referencia: '', textura: '', foto_url: '', colores: [colorVacio()] })

function abrirCrear() {
  crearForm.value = formVacio()
  crearError.value = ''
  showCrear.value = true
}

function agregarColor() {
  crearForm.value.colores.push(colorVacio())
}
function quitarColor(i) {
  crearForm.value.colores.splice(i, 1)
  if (!crearForm.value.colores.length) crearForm.value.colores.push(colorVacio())
}

/**
 * Otro color de una tela que ya está: el formulario llega con proveedor,
 * nombre, referencia y textura puestos, y solo falta el color. Antes había
 * que reescribirlos a mano, y una letra distinta la separaba de sus hermanas.
 */
function abrirOtroColor(item) {
  crearForm.value = {
    ...formVacio(),
    marca: item.marca, tipo: item.tipo ?? '',
    referencia: item.referencia ?? '', textura: item.textura ?? '',
  }
  crearError.value = ''
  showCrear.value = true
}

// La referencia se muestra al lado del nombre solo si dice algo más: en las
// telas del Excel suele ser el mismo texto, y repetirlo es ruido.
function refDistinta(item) {
  const ref = (item.referencia ?? '').trim()
  return ref !== '' && !!item.tipo && ref.toLowerCase() !== item.tipo.trim().toLowerCase()
}
// Una cantidad para mostrar: con sus decimales completos si los tiene. En
// metros, "6.3" se leía como 6 m 3 cm cuando son 6 m 30 cm; sale "6.30".
// Lo entero queda entero ("28", no "28.00").
function cant(n) {
  const v = Number(n ?? 0)
  if (!cfg.value.decimales || Number.isInteger(v)) return String(v)
  return v.toFixed(cfg.value.decimales)
}

// El nombre con que la tela se elige en las órdenes ("LAYLA 01 CRUDO"). Lo
// manda el servidor; si no viniera, la misma regla que CatalogoTela::nombreVenta:
// el nombre, más la referencia si no está ya en él.
function nombreVenta(item) {
  if (item?.nombre_venta) return item.nombre_venta
  const tipo = (item?.tipo ?? '').trim()
  const ref  = (item?.referencia ?? '').trim()
  if (!ref || tipo.toLowerCase().includes(ref.toLowerCase())) return tipo
  return `${tipo} ${ref}`
}

// "Terciopelo · Ref. Hielo": como se nombra en avisos y confirmaciones.
function nombreDe(item) {
  if (!item) return ''
  const base = item.tipo || item.referencia || ''
  return refDistinta(item) ? `${base} · Ref. ${item.referencia}` : base
}

// ── Eliminar ─────────────────────────────────────────────────────────────────
// Para las que se crearon mal. No se borra de la base: queda inactiva, y si
// se vuelve a crear igual (proveedor, nombre y color) reaparece. Lo que una
// orden tiene apartado no se deja quitar: el servidor lo frena y lo explica.
const itemEliminar = ref(null)
const eliminando   = ref(false)
const eliminarError = ref('')

function pedirEliminar(item) {
  itemEliminar.value  = item
  eliminarError.value = ''
}

async function confirmarEliminar() {
  const item = itemEliminar.value
  if (!item) return
  eliminando.value    = true
  eliminarError.value = ''
  try {
    await api.delete(rutas.value.eliminar(item.id))
    items.value = items.value.filter(t => t.id !== item.id)
    // Que las órdenes tampoco la ofrezcan más sin tener que recargar.
    if (esBase.value) {
      const colores = TELAS_CATALOGO[item.marca]?.[nombreVenta(item)]
      if (colores) {
        const i = colores.indexOf(item.color)
        if (i !== -1) colores.splice(i, 1)
      }
    }
    toast.success(`"${nombreDe(item)} (${item.color})" se eliminó.`)
    itemEliminar.value = null
  } catch (e) {
    eliminarError.value = e.response?.data?.message ?? 'No se pudo eliminar.'
  } finally {
    eliminando.value = false
  }
}

// ── Lo que ya existe, para no reescribirlo ───────────────────────────────────
// Se compara sin mayúsculas ni espacios de más: "Alpes gris" y "ALPES GRIS "
// son la misma tela.
const mismo = (a, b) => String(a ?? '').trim().toLowerCase() === String(b ?? '').trim().toLowerCase()
const marcaElegida = computed(() =>
  crearForm.value.marca === '__nueva__' ? crearForm.value.marcaNueva : crearForm.value.marca)

const delProveedor = computed(() => items.value.filter(t => mismo(t.marca, marcaElegida.value)))
const unicos = (lista) => [...new Map(lista.filter(Boolean).map(v => [v.trim().toLowerCase(), v.trim()])).values()].sort()

// Sugerencias al escribir el nombre y la referencia: las del proveedor elegido.
const tiposSugeridos = computed(() => unicos(delProveedor.value.map(t => t.tipo)))
const referenciasSugeridas = computed(() => unicos(
  delProveedor.value.filter(t => !crearForm.value.tipo.trim() || mismo(t.tipo, crearForm.value.tipo)).map(t => t.referencia)
))

// La misma tela en otros colores: mismo proveedor, nombre y referencia (sin
// referencia = sin referencia). Es lo que deja claro que se está agregando un
// color más. LAYLA 01 CRUDO y LAYLA 02 PERLA son telas distintas: los colores
// de una no son los de la otra.
const hermanas = computed(() => {
  if (!marcaElegida.value?.trim() || !crearForm.value.tipo.trim()) return []
  return delProveedor.value.filter(t =>
    mismo(t.tipo, crearForm.value.tipo) && mismo(t.referencia, crearForm.value.referencia))
})
// Repetida con la misma regla del servidor: proveedor + nombre + referencia +
// color. Si pasara, el servidor no crea otra: le suma los metros a la que ya
// está, y eso se hace a propósito con "Recargar".
function yaExiste(color) {
  if (!color?.trim()) return null
  return hermanas.value.find(t => mismo(t.color, color)) ?? null
}
// Y el mismo color escrito dos veces en la lista.
function repetidoEnLista(i) {
  const c = crearForm.value.colores[i]?.color
  return !!c?.trim() && crearForm.value.colores.some((o, j) => j < i && mismo(o.color, c))
}
const hayColorMalo = computed(() =>
  crearForm.value.colores.some((f, i) => yaExiste(f.color) || repetidoEnLista(i)))
const coloresEscritos = computed(() => crearForm.value.colores.filter(f => f.color.trim()))
// La foto es de un color: con varios, cada una se sube después en su tarjeta.
const unSoloColor = computed(() => crearForm.value.colores.length === 1)

// Al elegir una referencia que ya existe, se trae su textura si no se puso.
watch(() => crearForm.value.referencia, (ref) => {
  if (crearForm.value.textura.trim() || !ref?.trim()) return
  const igual = delProveedor.value.find(t => mismo(t.referencia, ref) && t.textura)
  if (igual) crearForm.value.textura = igual.textura
})

async function crearItem() {
  crearError.value = ''
  const marcaFinal = crearForm.value.marca === '__nueva__'
    ? crearForm.value.marcaNueva.trim()
    : crearForm.value.marca.trim()
  if (!marcaFinal)                       { crearError.value = 'Selecciona o ingresa la marca/proveedor.'; return }
  if (!crearForm.value.tipo.trim())      { crearError.value = 'Ingresa el tipo o nombre.'; return }
  if (!coloresEscritos.value.length)     { crearError.value = 'Ingresa al menos un color.'; return }
  if (hayColorMalo.value) {
    crearError.value = `Hay un color repetido (marcado en naranja). Si llegaron más metros de uno que ya existe, usa "Recargar" en la lista.`
    return
  }

  creando.value = true
  const creadas = []
  try {
    // Uno por uno, en orden: si uno falla se sabe cuáles quedaron y cuál no,
    // en vez de dejar la mitad creada sin decir nada.
    for (const fila of coloresEscritos.value) {
      const payload = {
        marca:      marcaFinal,
        tipo:       crearForm.value.tipo.trim(),
        color:      fila.color.trim(),
        referencia: crearForm.value.referencia.trim() || undefined,
        textura:    crearForm.value.textura.trim() || undefined,
        foto_url:   (unSoloColor.value && crearForm.value.foto_url) || undefined,
        [rutas.value.campoInicial]: redondear(normalizarCantidad(fila.cantidad)),
      }
      const { data } = await api.post(rutas.value.crear, payload)
      creadas.push(agregarALaLista(adaptar(data)))
    }
    showCrear.value = false
    toast.success(creadas.length === 1
      ? `"${nombreDe(creadas[0])} (${creadas[0].color})" quedó en el inventario.`
      : `"${nombreDe(creadas[0])}" quedó en el inventario con ${creadas.length} colores.`)
  } catch (e) {
    const motivo = e.response?.data?.message ?? `Error al crear la ${cfg.value.singular}.`
    if (creadas.length) {
      // Las que ya quedaron se quitan del formulario para que al reintentar
      // no se vuelvan a mandar.
      const hechas = new Set(creadas.map(c => c.color.trim().toLowerCase()))
      crearForm.value.colores = crearForm.value.colores.filter(f => !hechas.has(f.color.trim().toLowerCase()))
      if (!crearForm.value.colores.length) crearForm.value.colores.push(colorVacio())
      crearError.value = `Se crearon ${creadas.map(c => c.color).join(', ')}, pero no el resto: ${motivo}`
    } else {
      crearError.value = motivo
    }
  } finally {
    creando.value = false
  }
}

/** Pone en la lista (y en el catálogo de las órdenes) una que acaba de llegar del servidor. */
function agregarALaLista(nuevo) {
  // Si el servidor devolvió una que ya estaba, se reemplaza: agregarla
  // otra vez la dejaba repetida en la lista hasta recargar.
  const ya = items.value.findIndex(t => t.id === nuevo.id)
  if (ya !== -1) items.value[ya] = nuevo
  else items.value.unshift(nuevo)
  if (!proveedores.value.includes(nuevo.marca)) {
    proveedores.value = [...proveedores.value, nuevo.marca].sort()
  }
  // Sólo las telas de verdad están en el catálogo que usan las órdenes: se
  // sincroniza para que InventarioView vea la nueva de una.
  // Por el nombre de venta: es lo que se elige en la orden.
  if (esBase.value) {
    const nombre = nombreVenta(nuevo)
    if (!TELAS_CATALOGO[nuevo.marca]) TELAS_CATALOGO[nuevo.marca] = {}
    if (!TELAS_CATALOGO[nuevo.marca][nombre]) TELAS_CATALOGO[nuevo.marca][nombre] = []
    if (!TELAS_CATALOGO[nuevo.marca][nombre].includes(nuevo.color)) {
      TELAS_CATALOGO[nuevo.marca][nombre].push(nuevo.color)
    }
  }
  return nuevo
}

// ── Editar ───────────────────────────────────────────────────────────────────
// Para corregir cómo se creó: el nombre con la referencia pegada, un color
// mal escrito. El proveedor, el nombre y el color no se dejan cambiar si hay
// metros apartados para órdenes (el servidor lo frena y lo explica).
const itemEditar  = ref(null)
const editarForm  = ref({ marca: '', tipo: '', referencia: '', color: '', textura: '' })
const guardandoEd = ref(false)
const editarError = ref('')

function abrirEditar(item) {
  itemEditar.value  = item
  editarForm.value  = {
    marca: item.marca ?? '', tipo: item.tipo ?? '', referencia: item.referencia ?? '',
    color: item.color ?? '', textura: item.textura ?? '',
  }
  editarError.value = ''
}

// Proveedor, nombre, referencia y color son lo que la identifica: con metros apartados no se tocan.
const bloqueaNombre = computed(() => (itemEditar.value?.reservado ?? 0) > 0)

async function guardarEdicion() {
  const item = itemEditar.value
  if (!item) return
  const f = editarForm.value
  if (!f.marca.trim() || !f.tipo.trim() || !f.color.trim()) {
    editarError.value = 'Proveedor, nombre y color no pueden quedar vacíos.'
    return
  }
  // Solo lo que cambió: mandar el nombre igual con metros apartados lo
  // frenaría el servidor aunque no se hubiera tocado.
  const payload = {}
  for (const campo of ['marca', 'tipo', 'color', 'referencia', 'textura']) {
    if ((f[campo] ?? '').trim() !== (item[campo] ?? '').trim()) payload[campo] = f[campo].trim()
  }
  if (!Object.keys(payload).length) { itemEditar.value = null; return }

  guardandoEd.value = true
  editarError.value = ''
  try {
    const { data } = await api.patch(rutas.value.editar(item.id), payload)
    const actualizado = adaptar({ ...item, ...data })
    // Las órdenes eligen la tela por proveedor → nombre de venta → color: se
    // mueve en el catálogo si cambió alguno (la referencia es parte del nombre).
    if (esBase.value && ('marca' in payload || 'tipo' in payload || 'color' in payload || 'referencia' in payload)) {
      const viejos = TELAS_CATALOGO[item.marca]?.[nombreVenta(item)]
      const i = viejos?.indexOf(item.color) ?? -1
      if (i !== -1) viejos.splice(i, 1)
    }
    const idx = items.value.findIndex(t => t.id === item.id)
    if (idx !== -1) items.value[idx] = actualizado
    agregarALaLista(actualizado)
    toast.success(`"${nombreDe(actualizado)} (${actualizado.color})" quedó corregida.`)
    itemEditar.value = null
  } catch (e) {
    editarError.value = e.response?.data?.message ?? 'No se pudo guardar.'
  } finally {
    guardandoEd.value = false
  }
}

function abrirRecargar(item) {
  itemActivo.value = item
  modalTipo.value  = 'recargar'
  cantidad.value   = ''
  nota.value       = ''
  modalError.value = ''
  showModal.value  = true
}

function abrirDescontar(item) {
  itemActivo.value = item
  modalTipo.value  = 'descontar'
  cantidad.value   = ''
  nota.value       = ''
  modalError.value = ''
  showModal.value  = true
}

async function confirmar() {
  modalError.value = ''
  const m = redondear(normalizarCantidad(cantidad.value))
  if (!m || m <= 0) { modalError.value = 'Ingresa una cantidad válida.'; return }

  guardando.value = true
  try {
    const endpoint = modalTipo.value === 'recargar' ? rutas.value.recargar : rutas.value.descontar
    const { data } = await api.post(endpoint, {
      id:                 itemActivo.value.id,
      [rutas.value.campo]: m,
      nota:               nota.value || undefined,
    })

    const actualizado = adaptar(data)
    const idx = items.value.findIndex(t => t.id === actualizado.id)
    if (idx !== -1) {
      items.value[idx] = actualizado
    }
    showModal.value = false
    const etiqueta = `${nombreDe(actualizado)} (${actualizado.color})`
    toast.success(
      modalTipo.value === 'recargar'
        ? `+${m} ${cfg.value.unidad} agregados a ${etiqueta}`
        : `-${m} ${cfg.value.unidad} descontados de ${etiqueta}`
    )
  } catch (e) {
    modalError.value = e.response?.data?.message ?? 'Error al actualizar.'
  } finally {
    guardando.value = false
  }
}

function colorBadge(libre) {
  if (libre <= 0) return 'bg-red-100 text-red-700'
  if (libre <= 3)  return 'bg-amber-100 text-amber-700'
  return 'bg-green-100 text-green-700'
}

function exportarExcelItems() {
  const filas = itemsFiltrados.value.map(t => ({
    'Marca / Proveedor': t.marca ?? '',
    'Tipo':              t.tipo ?? '',
    'Referencia':        t.referencia ?? '',
    'Color':             t.color ?? '',
    'Textura':           t.textura ?? '',
    [esBase.value ? 'Metros disponibles' : `Disponible (${cfg.value.unidad})`]: Number(t.libre ?? 0),
  }))
  exportarExcel(filas, { nombreArchivo: `${props.clave}_decasa`, hoja: nombre.value })
}

onMounted(cargar)
// Al volver del panel de consumo los apartados pueden haber cambiado.
watch(pestana, v => { if (v === 'inventario') cargar() })
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-bold text-gray-800">Inventario de {{ nombre.toLowerCase() }}</h2>
      <div v-if="pestana === 'inventario'" class="flex items-center gap-3">
        <span class="text-xs text-gray-400">{{ itemsFiltrados.length }} / {{ items.length }}</span>
        <button
          v-if="itemsFiltrados.length"
          @click="exportarExcelItems"
          title="Descargar Excel"
          class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs font-semibold hover:bg-green-700 transition-colors"
        >
          <ArrowDownTrayIcon class="w-3.5 h-3.5" />
          Excel
        </button>
        <button
          v-if="puedeRecargar"
          @click="abrirCrear"
          class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 transition-colors"
        >
          <PlusIcon class="w-3.5 h-3.5" />
          Agregar {{ cfg.singular }}
        </button>
      </div>
    </div>

    <!-- Pestañas: sólo las telas de verdad tienen consumo por producto -->
    <div v-if="esBase" class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        @click="pestana = 'inventario'"
        :class="['flex-1 py-1.5 rounded-lg text-xs font-semibold transition-colors', pestana === 'inventario' ? 'bg-white text-gray-800 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
      >
        Inventario
      </button>
      <button
        @click="pestana = 'consumo'"
        :class="['flex-1 py-1.5 rounded-lg text-xs font-semibold transition-colors', pestana === 'consumo' ? 'bg-white text-gray-800 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
      >
        Consumo por producto
      </button>
    </div>

    <ConsumoTelasPanel v-if="esBase && pestana === 'consumo'" />

    <template v-if="pestana === 'inventario'">
    <!-- Search -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
      <input
        v-model="busqueda"
        type="search"
        placeholder="Buscar por tipo, color, marca..."
        class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtro por marca/proveedor -->
    <div v-if="proveedores.length" class="flex flex-wrap gap-2">
      <button
        @click="proveedorFiltro = ''"
        :class="[
          'px-3 py-1 rounded-full text-xs font-semibold transition-colors',
          proveedorFiltro === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        ]"
      >
        Todas
      </button>
      <button
        v-for="prov in proveedores"
        :key="prov"
        @click="proveedorFiltro = prov"
        :class="[
          'px-3 py-1 rounded-full text-xs font-semibold transition-colors',
          proveedorFiltro === prov ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
        ]"
      >
        {{ prov }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="cargando" class="flex justify-center py-12">
      <AppSpinner />
    </div>

    <!-- Empty -->
    <div v-else-if="!itemsFiltrados.length" class="text-center py-12 text-sm text-gray-400">
      {{ busqueda || proveedorFiltro ? 'Sin resultados para el filtro actual.' : `No hay ${nombre.toLowerCase()} en el inventario.` }}
    </div>

    <!-- List -->
    <div v-else class="space-y-2">
      <div
        v-for="item in itemsFiltrados"
        :key="item.id"
        class="bg-white rounded-xl shadow-sm p-4"
      >
        <!-- Title row -->
        <div class="flex items-start justify-between gap-2">
          <img
            v-if="item.foto_url"
            :src="cloudinaryOpt(item.foto_url, 96)"
            @click="fotoModal = item.foto_url"
            class="w-12 h-12 rounded-lg object-cover border border-gray-200 flex-shrink-0 cursor-pointer"
          />
          <div class="flex-1 min-w-0">
            <!-- El nombre siempre de título, y la referencia al lado. Antes la
                 referencia, si había, reemplazaba al nombre —así venían las
                 del Excel, donde era lo mismo—, y en las creadas a mano el
                 nombre no salía por ningún lado. -->
            <div class="flex items-baseline gap-1.5 min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">{{ item.tipo || item.referencia }}</p>
              <span v-if="refDistinta(item)"
                class="text-[11px] font-medium text-gray-500 bg-gray-100 rounded px-1.5 py-0.5 whitespace-nowrap flex-shrink-0">
                Ref. {{ item.referencia }}
              </span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">
              <span v-if="item.color">{{ item.color }}</span>
              <span v-if="item.textura"> · {{ item.textura }}</span>
              <span class="text-gray-400"> · {{ item.marca }}</span>
            </p>
          </div>
          <div class="flex flex-col items-end gap-0.5">
            <span :class="['text-xs font-bold px-2.5 py-1 rounded-full whitespace-nowrap', colorBadge(item.libre)]">
              {{ cant(item.libre) }} {{ cfg.unidad }}
            </span>
            <!-- Lo que las ventas tienen apartado: libres = disponibles − apartados. -->
            <span v-if="item.reservado > 0" class="text-[10px] text-gray-400 whitespace-nowrap">
              {{ cant(item.reservado) }} {{ cfg.unidad }} apartados
            </span>
          </div>
        </div>

        <!-- Actions -->
        <div v-if="puedeRecargar || puedeDescontar || auth.isSupervisor" class="flex flex-wrap gap-2 mt-3">
          <label
            v-if="auth.isSupervisor"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 text-xs font-semibold hover:bg-blue-100 transition-colors cursor-pointer"
          >
            <PhotoIcon class="w-3.5 h-3.5" />
            {{ subiendoFotoDe === item.id ? 'Subiendo...' : (item.foto_url ? 'Cambiar foto' : 'Agregar foto') }}
            <input type="file" accept="image/*" class="hidden" @change="e => onFotoExistente(item, e)" />
          </label>
          <button
            v-if="puedeRecargar"
            @click="abrirRecargar(item)"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-green-50 text-green-700 text-xs font-semibold hover:bg-green-100 transition-colors"
          >
            <PlusIcon class="w-3.5 h-3.5" />
            Recargar
          </button>
          <button
            v-if="puedeDescontar"
            @click="abrirDescontar(item)"
            :disabled="item.libre <= 0"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 text-red-700 text-xs font-semibold hover:bg-red-100 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <MinusIcon class="w-3.5 h-3.5" />
            Descontar
          </button>
          <!-- La misma tela en otro color: abre el formulario con todo puesto
               menos el color. -->
          <button
            v-if="puedeRecargar"
            @click="abrirOtroColor(item)"
            class="ml-auto flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-50 transition-colors"
          >
            <PlusIcon class="w-3.5 h-3.5" />
            Otro color
          </button>
          <button
            v-if="auth.isSupervisor"
            @click="abrirEditar(item)"
            :class="['flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-gray-500 text-xs font-semibold hover:bg-gray-100 hover:text-gray-800 transition-colors',
              puedeRecargar ? '' : 'ml-auto']"
            :aria-label="`Editar ${nombreDe(item)} ${item.color}`"
            title="Editar"
          >
            <PencilSquareIcon class="w-4 h-4" />
          </button>
          <button
            v-if="auth.isSupervisor"
            @click="pedirEliminar(item)"
            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-gray-400 text-xs font-semibold hover:bg-red-50 hover:text-red-600 transition-colors"
            :aria-label="`Eliminar ${nombreDe(item)} ${item.color}`"
            title="Eliminar"
          >
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
    </template>

    <!-- Modal: Agregar -->
    <Transition name="fade">
      <div v-if="showCrear" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showCrear = false">
        <div class="absolute inset-0 bg-black/40" />
        <!-- Con tope de alto: el título y los botones quedan fijos y lo del
             medio se desplaza. Antes en un portátil el formulario se salía
             por abajo y no se alcanzaba el botón de crear. -->
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92dvh] flex flex-col">
          <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <h3 class="text-base font-bold text-gray-800">Agregar {{ cfg.singular }}</h3>
            <button @click="showCrear = false" aria-label="Cerrar" class="text-gray-400 text-2xl leading-none w-9 h-9 -mr-2">&times;</button>
          </div>

          <div class="space-y-3 overflow-y-auto px-5 py-4 flex-1 min-h-0">
            <!-- Proveedor/Marca -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Proveedor / Marca <span class="text-red-500">*</span></label>
              <select
                v-model="crearForm.marca"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Seleccionar...</option>
                <option v-for="p in proveedores" :key="p" :value="p">{{ p }}</option>
                <option value="__nueva__">Otro (nuevo proveedor)</option>
              </select>
              <input
                v-if="crearForm.marca === '__nueva__'"
                v-model="crearForm.marcaNueva"
                class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Nombre del proveedor..."
              />
            </div>

            <!-- Tipo -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipo / Nombre <span class="text-red-500">*</span></label>
              <input
                v-model="crearForm.tipo"
                list="tipos-existentes"
                autocomplete="off"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :placeholder="esBase ? 'Ej: Antifaz, Terciopelo, ALPES GRIS...' : `Ej: el tipo o nombre de la ${cfg.singular}`"
              />
              <datalist id="tipos-existentes">
                <option v-for="t in tiposSugeridos" :key="t" :value="t" />
              </datalist>
              <p v-if="tiposSugeridos.length && !crearForm.tipo" class="mt-1 text-xs text-gray-400">
                Escribe o elige uno de los que ya tiene este proveedor.
              </p>
            </div>

            <!-- Referencia -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Referencia (opcional)</label>
              <input
                v-model="crearForm.referencia"
                list="referencias-existentes"
                autocomplete="off"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :placeholder="esBase ? 'Ej: ADARA 10 HUMO, ALPES GRIS...' : 'Ej: el código del proveedor'"
              />
              <datalist id="referencias-existentes">
                <option v-for="r in referenciasSugeridas" :key="r" :value="r" />
              </datalist>
            </div>

            <!-- La misma tela en otros colores: deja claro que lo que se está
                 haciendo es agregarle un color más, y cuáles ya tiene. -->
            <div v-if="hermanas.length" class="rounded-xl bg-blue-50 border border-blue-100 p-3 space-y-2">
              <p class="text-xs font-semibold text-blue-800">
                Esta {{ cfg.singular }} ya existe en {{ hermanas.length }} color{{ hermanas.length === 1 ? '' : 'es' }}.
                Escribe abajo los colores nuevos.
              </p>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="h in hermanas" :key="h.id"
                  :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium border',
                    crearForm.colores.some(f => mismo(f.color, h.color)) ? 'bg-amber-100 border-amber-300 text-amber-900' : 'bg-white border-blue-200 text-gray-700']">
                  {{ h.color }}
                  <span class="text-gray-400">· {{ cant(h.libre) }} {{ cfg.unidad }}</span>
                </span>
              </div>
            </div>

            <!-- Colores: todos los de esta tela de una vez, cada uno con lo que llegó. -->
            <div>
              <div class="flex items-baseline justify-between mb-1">
                <label class="text-sm font-medium text-gray-700">
                  Colores <span class="text-red-500">*</span>
                </label>
                <span class="text-xs text-gray-400">{{ enMetros ? 'Metros iniciales' : `Cantidad (${cfg.unidad})` }}</span>
              </div>
              <div class="space-y-2">
                <div v-for="(fila, i) in crearForm.colores" :key="i">
                  <div class="flex gap-2">
                    <input
                      v-model="fila.color"
                      :aria-label="`Color ${i + 1}`"
                      :class="['flex-1 min-w-0 rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2',
                        yaExiste(fila.color) || repetidoEnLista(i) ? 'border-amber-400 focus:ring-amber-400' : 'border-gray-300 focus:ring-blue-500']"
                      :placeholder="i === 0 ? 'Ej: Gris, Beige, Azul...' : 'Otro color'"
                    />
                    <input
                      :value="fila.cantidad"
                      @input="fila.cantidad = $event.target.value = normalizarCantidad($event.target.value)"
                      :aria-label="`${enMetros ? 'Metros' : 'Cantidad'} del color ${i + 1}`"
                      type="text"
                      inputmode="decimal"
                      class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                      :placeholder="cfg.decimales ? '0.' + '0'.repeat(cfg.decimales) : '0'"
                    />
                    <button
                      v-if="crearForm.colores.length > 1"
                      type="button" @click="quitarColor(i)"
                      :aria-label="`Quitar el color ${i + 1}`"
                      class="w-10 flex items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600"
                    >
                      <XMarkIcon class="w-4 h-4" />
                    </button>
                  </div>
                  <p v-if="yaExiste(fila.color)" class="mt-1 text-xs text-amber-700">
                    "{{ yaExiste(fila.color).color }}" ya está registrado ({{ cant(yaExiste(fila.color).libre) }} {{ cfg.unidad }}).
                    Si llegaron más, usa "Recargar" en la lista.
                  </p>
                  <p v-else-if="repetidoEnLista(i)" class="mt-1 text-xs text-amber-700">Este color ya está arriba en la lista.</p>
                </div>
              </div>
              <button type="button" @click="agregarColor"
                class="mt-2 flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:border-blue-400 hover:text-blue-600 w-full justify-center">
                <PlusIcon class="w-4 h-4" />
                Agregar otro color
              </button>
              <p class="mt-1.5 text-xs text-gray-400">
                <template v-if="enMetros">Ej: 20.45 = 20 metros 45 centímetros. </template>
                Otra referencia es otra {{ cfg.singular }}: créala aparte.
              </p>
            </div>

            <!-- Textura -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Textura (opcional)</label>
              <input
                v-model="crearForm.textura"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Ej: Lisa, Bordada..."
              />
            </div>

            <!-- Foto: es de un color. Con varios, cada una se sube después en su tarjeta. -->
            <p v-if="!unSoloColor" class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
              La foto de cada color se agrega después, desde su tarjeta.
            </p>
            <div v-else>
              <label class="block text-sm font-medium text-gray-700 mb-1">Foto (opcional)</label>
              <div class="flex items-center gap-3">
                <div v-if="crearForm.foto_url" class="relative w-16 h-16 flex-shrink-0">
                  <img :src="cloudinaryOpt(crearForm.foto_url, 800)" class="w-full h-full rounded-lg object-cover border border-gray-200" />
                  <button type="button" @click="crearForm.foto_url = ''"
                    class="absolute -top-1.5 -right-1.5 bg-white rounded-full shadow p-0.5 text-red-500">
                    <XMarkIcon class="w-3.5 h-3.5" />
                  </button>
                </div>
                <label class="flex items-center gap-1.5 px-3 py-2 rounded-lg border border-dashed border-gray-300 text-sm text-gray-500 cursor-pointer hover:border-blue-400 hover:text-blue-600">
                  <PhotoIcon class="w-4 h-4" />
                  {{ subiendoFotoCrear ? 'Subiendo...' : (crearForm.foto_url ? 'Cambiar foto' : 'Subir foto') }}
                  <input type="file" accept="image/*" class="hidden" @change="onFotoCrear" />
                </label>
              </div>
            </div>

          </div>

          <!-- Siempre a la vista, por largo que sea el formulario. -->
          <div class="px-5 pt-3 pb-5 border-t border-gray-100 space-y-3 flex-shrink-0">
            <p v-if="crearError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ crearError }}</p>
            <div class="flex gap-3">
              <button @click="showCrear = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
              <button
                @click="crearItem"
                :disabled="creando || hayColorMalo"
                class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
              >
                {{ creando ? 'Guardando...'
                  : coloresEscritos.length > 1 ? `Crear con ${coloresEscritos.length} colores`
                  : `Crear ${cfg.singular}` }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Editar -->
    <Transition name="fade">
      <div v-if="itemEditar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="itemEditar = null">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92dvh] flex flex-col">
          <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <h3 class="text-base font-bold text-gray-800">Editar {{ cfg.singular }}</h3>
            <button @click="itemEditar = null" aria-label="Cerrar" class="text-gray-400 text-2xl leading-none w-9 h-9 -mr-2">&times;</button>
          </div>

          <div class="space-y-3 overflow-y-auto px-5 py-4 flex-1 min-h-0">
            <p v-if="bloqueaNombre" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              Tiene {{ cant(itemEditar.reservado) }} {{ cfg.unidad }} apartados para órdenes: el proveedor, el nombre,
              la referencia y el color no se pueden cambiar hasta que se entreguen, porque las órdenes la
              encuentran por ellos. La textura sí.
            </p>

            <div>
              <label for="ed-marca" class="block text-sm font-medium text-gray-700 mb-1">Proveedor / Marca</label>
              <input id="ed-marca" v-model="editarForm.marca" list="ed-proveedores" :disabled="bloqueaNombre"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-400" />
              <datalist id="ed-proveedores">
                <option v-for="p in proveedores" :key="p" :value="p" />
              </datalist>
            </div>
            <div>
              <label for="ed-tipo" class="block text-sm font-medium text-gray-700 mb-1">Tipo / Nombre</label>
              <input id="ed-tipo" v-model="editarForm.tipo" :disabled="bloqueaNombre"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-400" />
            </div>
            <div>
              <label for="ed-ref" class="block text-sm font-medium text-gray-700 mb-1">Referencia (opcional)</label>
              <input id="ed-ref" v-model="editarForm.referencia" :disabled="bloqueaNombre"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-400" />
            </div>
            <div>
              <label for="ed-color" class="block text-sm font-medium text-gray-700 mb-1">Color</label>
              <input id="ed-color" v-model="editarForm.color" :disabled="bloqueaNombre"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-400" />
            </div>
            <div>
              <label for="ed-textura" class="block text-sm font-medium text-gray-700 mb-1">Textura (opcional)</label>
              <input id="ed-textura" v-model="editarForm.textura"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <p class="text-xs text-gray-400">
              Los {{ enMetros ? 'metros' : 'cantidades' }} no se cambian aquí: para eso están "Recargar" y "Descontar".
            </p>
          </div>

          <div class="px-5 pt-3 pb-5 border-t border-gray-100 space-y-3 flex-shrink-0">
            <p v-if="editarError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ editarError }}</p>
            <div class="flex gap-3">
              <button @click="itemEditar = null" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
              <button
                @click="guardarEdicion"
                :disabled="guardandoEd"
                class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
              >
                {{ guardandoEd ? 'Guardando...' : 'Guardar cambios' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Eliminar -->
    <Transition name="fade">
      <div v-if="itemEliminar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="itemEliminar = null">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0">
              <TrashIcon class="w-5 h-5" />
            </div>
            <div class="min-w-0">
              <h3 class="text-base font-bold text-gray-800">¿Eliminar esta {{ cfg.singular }}?</h3>
              <p class="text-sm text-gray-700 mt-1">
                <span class="font-semibold">{{ nombreDe(itemEliminar) }}</span>
                · {{ itemEliminar.color }}
                <span class="text-gray-400">· {{ itemEliminar.marca }}</span>
              </p>
            </div>
          </div>

          <!-- Lo apartado lo frena el servidor; se dice antes para no hacer
               el intento en vano. -->
          <p v-if="itemEliminar.reservado > 0" class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Tiene {{ cant(itemEliminar.reservado) }} {{ cfg.unidad }} apartados para órdenes, así que no se puede eliminar.
            Primero hay que cambiarles la {{ cfg.singular }} o esperar a que se entreguen.
          </p>
          <template v-else>
            <p v-if="itemEliminar.disponible > 0" class="text-sm text-gray-700 bg-gray-50 rounded-lg px-3 py-2">
              Tiene <span class="font-semibold">{{ cant(itemEliminar.disponible) }} {{ cfg.unidad }}</span> en inventario:
              dejarán de contarse. Si la creaste mal, pásale {{ enMetros ? 'esos metros' : 'esa cantidad' }} a la correcta con "Recargar".
            </p>
            <p class="text-xs text-gray-500">
              Deja de aparecer en el inventario y al elegir {{ esBase ? 'telas en las órdenes' : 'ítems' }}.
              Las órdenes que ya la usaron siguen diciendo cuál era.
            </p>
          </template>

          <p v-if="eliminarError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ eliminarError }}</p>

          <div class="flex gap-3">
            <button @click="itemEliminar = null" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button
              @click="confirmarEliminar"
              :disabled="eliminando || itemEliminar.reservado > 0"
              class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-red-700 disabled:opacity-50"
            >
              {{ eliminando ? 'Eliminando...' : 'Sí, eliminar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Recargar / Descontar -->
    <Transition name="fade">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">
              {{ modalTipo === 'recargar' ? 'Agregar' : 'Descontar' }} {{ enMetros ? 'metros' : 'cantidad' }}
            </h3>
            <button @click="showModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <div class="bg-gray-50 rounded-lg px-3 py-2">
            <p class="text-sm font-semibold text-gray-800">
              {{ nombreDe(itemActivo) }}
              <span class="text-gray-500 font-normal">({{ itemActivo?.color }})</span>
            </p>
            <p class="text-xs text-gray-500 mt-0.5">
              Disponible: <strong>{{ cant(itemActivo?.libre) }} {{ cfg.unidad }}</strong>
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              {{ enMetros ? 'Metros' : `Cantidad (${cfg.unidad})` }} a {{ modalTipo === 'recargar' ? 'agregar' : 'descontar' }}
            </label>
            <input
              :value="cantidad"
              @input="cantidad = $event.target.value = normalizarCantidad($event.target.value)"
              type="text"
              inputmode="decimal"
              :placeholder="cfg.decimales ? '0.' + '0'.repeat(cfg.decimales) : '0'"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p v-if="enMetros" class="mt-1 text-xs text-gray-400">Ej: 20.45 = 20 metros 45 centímetros</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nota (opcional)</label>
            <input
              v-model="nota"
              type="text"
              placeholder="Motivo o referencia de compra..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <p v-if="modalError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ modalError }}</p>

          <div class="flex gap-3">
            <button @click="showModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button
              @click="confirmar"
              :disabled="guardando"
              :class="[
                'flex-1 rounded-lg py-2.5 text-sm font-semibold text-white disabled:opacity-50',
                modalTipo === 'recargar' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'
              ]"
            >
              {{ guardando ? 'Guardando...' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Visor de foto -->
    <Transition name="fade">
      <div v-if="fotoModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4" @click="fotoModal = ''">
        <div class="absolute inset-0 bg-black/80" />
        <img :src="fotoModal" class="relative max-w-full max-h-[85vh] rounded-lg object-contain" />
        <button @click="fotoModal = ''" class="absolute top-4 right-4 text-white/80 hover:text-white">
          <XMarkIcon class="w-7 h-7" />
        </button>
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
</style>
