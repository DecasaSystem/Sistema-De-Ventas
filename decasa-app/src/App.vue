<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import api from '@/api'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAppearanceStore } from '@/stores/appearance'
import { useNotificacionesStore } from '@/stores/notificaciones'
import { useDespachoStore } from '@/stores/despacho'
import { useSurtidosStore } from '@/stores/surtidos'
import { usePasosStore } from '@/stores/pasos'
import { useConsultasStore } from '@/stores/consultas'
import { useModulosStore } from '@/stores/modulos'
import { useSurtidosSocket } from '@/composables/useSurtidosSocket'
import { useCargaGlobal } from '@/composables/useCargaGlobal'
import { useToast } from '@/composables/useToast'
import { registrarPush, cancelarPush } from '@/composables/usePushNotifications'
import { cargarCatalogoDB } from '@/data/telasCatalogo'
import CargandoS from '@/components/common/CargandoS.vue'
import ScrollToTop from '@/components/common/ScrollToTop.vue'
import ToastContainer from '@/components/common/ToastContainer.vue'
import AppInstallPrompt from '@/components/common/AppInstallPrompt.vue'
import AgentChat from '@/components/AgentChat.vue'
import {
  HomeIcon,
  ClipboardDocumentListIcon,
  UserGroupIcon,
  UsersIcon,
  ArchiveBoxIcon,
  WrenchScrewdriverIcon,
  ChartBarIcon,
  PresentationChartLineIcon,
  ArrowRightStartOnRectangleIcon,
  BellIcon,
  UserCircleIcon,
  EllipsisHorizontalIcon,
  ShoppingCartIcon,
  BuildingStorefrontIcon,
  ClipboardDocumentCheckIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  ClockIcon,
  CubeIcon,
  XCircleIcon,
  CalendarIcon,
  CalendarDaysIcon,
  TruckIcon,
  ArchiveBoxArrowDownIcon,
  TrashIcon,
  PencilSquareIcon,
  CalculatorIcon,
  BanknotesIcon,
  DocumentCurrencyDollarIcon,
  DocumentTextIcon,
  ChatBubbleLeftRightIcon,
  ArrowPathIcon,
  CurrencyDollarIcon,
  ReceiptPercentIcon,
  BuildingOffice2Icon,
  SwatchIcon,
  Cog6ToothIcon,
  ArrowsRightLeftIcon,
  AdjustmentsHorizontalIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const auth       = useAuthStore()
const toast      = useToast()
useAppearanceStore() // inicializa y aplica tema/fuente guardados
const notif  = useNotificacionesStore()
const despacho = useDespachoStore()
const surtidos = useSurtidosStore()
const pasos    = usePasosStore()
const consultasStore = useConsultasStore()
// Cómo llama esta empresa a cada módulo. Se pide una vez y queda guardado.
const modulos = useModulosStore()
const { conectar: conectarSurtidos } = useSurtidosSocket()

// El catálogo público es una página para el cliente: no lleva el menú del
// programa aunque quien la abra tenga sesión iniciada.
const SIN_MENU = ['login', 'catalogo-publico', 'catalogos-portada', 'catalogo-visor']
const showNav       = computed(() => auth.isAuthenticated && !SIN_MENU.includes(route.name))
const abrirNotif    = ref(false)
const abrirMas      = ref(false)
const navCargando   = ref(false)

router.beforeEach(() => { navCargando.value = true })
router.afterEach(()  => { navCargando.value = false })

// La S centrada mientras haya peticiones en vuelo. Cubre los botones que no
// avisan por su cuenta. `cargandoRed` ya viene en false si la pantalla está
// mostrando su propia S, así que nunca se ven dos.
const { cargando: cargandoRed, hayCargaLocal } = useCargaGlobal()
const mostrarCarga = computed(() =>
  (navCargando.value || cargandoRed.value) && !hayCargaLocal.value
)

/**
 * Vuelve a pedir los permisos cuando el usuario regresa a la pestaña.
 *
 * Se pedían una sola vez, al abrir la app, así que un cambio hecho desde otro
 * computador —asignarle un paso, activarle un módulo— no le llegaba nunca a
 * quien ya tenía la sesión abierta: seguía sin ver "Mis pasos" hasta cerrar
 * sesión o recargar, y desde afuera parecía que la asignación no se guardó.
 *
 * Se limita a una vez por minuto: volver a la pestaña es algo que pasa todo el
 * tiempo y no hay por qué pedirlo en cada vistazo.
 */
let ultimoRefresco = 0
function refrescarPermisos() {
  if (document.visibilityState !== 'visible' || !auth.isAuthenticated) return
  const ahora = Date.now()
  if (ahora - ultimoRefresco < 60_000) return
  ultimoRefresco = ahora
  auth.fetchMe()
}

function onSwMessage(e) {
  if (e.data?.type === 'push-click') {
    const destino = destinoNotificacion(e.data.tipo, e.data.datos)
    router.push(destino ?? { name: 'dashboard' })
  }
}

/**
 * La app se abrió DESDE una notificación con la app cerrada.
 *
 * En ese caso no hay ventana a la que avisarle, así que el service worker abre
 * la app con el aviso en la dirección y acá se lee para ir al mismo sitio al
 * que habría ido con la app abierta.
 */
function abrirDesdeNotificacionInicial() {
  const crudo = new URLSearchParams(window.location.search).get('notif')
  if (!crudo) return
  // Se limpia la dirección para que al recargar no vuelva a saltar sola.
  window.history.replaceState({}, '', window.location.pathname)
  try {
    const info = JSON.parse(crudo)
    const destino = destinoNotificacion(info.tipo, info.datos)
    if (destino) router.push(destino)
  } catch { /* dirección manipulada: se queda en el inicio */ }
}

onMounted(() => {
  if (auth.isAuthenticated) modulos.cargar()
  auth.fetchMe().finally(abrirDesdeNotificacionInicial)
  document.addEventListener('visibilitychange', refrescarPermisos)
  window.addEventListener('focus', refrescarPermisos)
  navigator.serviceWorker?.addEventListener('message', onSwMessage)
})
onUnmounted(() => {
  navigator.serviceWorker?.removeEventListener('message', onSwMessage)
  document.removeEventListener('visibilitychange', refrescarPermisos)
  window.removeEventListener('focus', refrescarPermisos)
})

watch(() => auth.isAuthenticated, (isAuth) => {
  if (!isAuth) return
  modulos.cargar()
  notif.cargar()
  registrarPush()
  cargarCatalogoDB(api)
  if (auth.isSupervisor) {
    despacho.refrescar()
  }
  if (auth.usuario?.rol === 'conductor') {
    despacho.cargarMisEntregas()
  }
  // Validar un surtido no depende del rol: el supervisor elige a quién se lo
  // manda, y puede ser un facturador o él mismo. El endpoint ya devuelve solo
  // lo que a uno le toca validar, así que se carga para todos. Antes solo se
  // pedía para el rol 'vendedor' y los demás no veían ni el contador: se
  // enteraban únicamente por la notificación.
  surtidos.cargarPendientes()
  if (auth.tieneAccesoPasos) {
    pasos.cargar()
  }
  if (auth.isSupervisor || auth.usuario?.rol === 'vendedor') {
    consultasStore.cargar()
  }
}, { immediate: true })


// WebSockets — espera a que usuario esté cargado (fetchMe) para tener id y rol
// immediate: true para que conecte aunque el ID ya esté en localStorage al cargar la página
watch(() => auth.usuario?.id, (id, oldId) => {
  if (window.Echo && oldId && oldId !== id) {
    window.Echo.leaveChannel(`notificaciones.${oldId}`)
  }
  if (!id || !window.Echo) return
  window.Echo.channel(`notificaciones.${id}`)
    .stopListening('.nueva.notificacion')
    .listen('.nueva.notificacion', n => {
      notif.agregarNueva(n)
      // Recargar badge de pasos cuando llega una notificación de producción
      if (n.tipo === 'paso_produccion' && auth.tieneAccesoPasos) {
        pasos.cargar()
      }
      if (['consulta_costo_nueva', 'consulta_costo_respondida', 'consulta_costo_mensaje'].includes(n.tipo)) {
        if (auth.isSupervisor || auth.usuario?.rol === 'vendedor') consultasStore.cargar()
      }
      if (n.tipo === 'despacho_asignado' && auth.usuario?.rol === 'conductor') {
        despacho.cargarMisEntregas()
      }
    })
  conectarSurtidos()
}, { immediate: true })

// Cerrar menú "Más" al cambiar de ruta
watch(() => route.name, () => { abrirMas.value = false })

const abonosNoLeidos = computed(() =>
  notif.items.filter(n => !n.leida && n.tipo === 'abono_registrado').length
)

// Las urgentes son cambios de plata: van primero y en rojo, para que no queden
// enterradas debajo de veinte avisos de "asignar fecha de entrega".
const urgentesNoLeidas = computed(() =>
  notif.items.filter(n => !n.leida && n.urgente).length
)
const notificacionesOrdenadas = computed(() => {
  const pendientes = notif.items.filter(n => n.urgente && !n.leida)
  const resto      = notif.items.filter(n => !(n.urgente && !n.leida))
  return [...pendientes, ...resto]
})

// Badge de conversaciones WA pendientes (carga inicial + actualización por WebSocket)
const redesPendientes = ref(0)
function cargarRedesPendientes() {
  if (!auth.isAuthenticated || !auth.tieneAccesoRedes) return
  // Refresco de badge en segundo plano: no debe encender la barra de carga.
  api.get('/redes/conversaciones?estado=pendiente', { silencioso: true }).then(r => {
    redesPendientes.value = r.data.length
  }).catch(() => {})
}
watch(() => auth.usuario?.id, (id, oldId) => {
  if (window.Echo && oldId && oldId !== id) {
    window.Echo.leaveChannel('redes')
  }
  if (!id) return
  cargarRedesPendientes()
  if (!window.Echo || !auth.tieneAccesoRedes) return
  window.Echo.channel('redes').listen('.conversacion.actualizada', cargarRedesPendientes)
}, { immediate: true })

/**
 * La barra de abajo.
 *
 * El `label` y el `icon` que van escritos son el repuesto: si esta empresa le
 * cambió el nombre al módulo, `soloVisibles` los reemplaza por los suyos, y si
 * lo apagó, el acceso no aparece. Lo que NO cambia es quién ve qué: eso lo
 * siguen decidiendo los permisos, no la personalización.
 */
const navItems = computed(() => {
  if (auth.isSupervisor) {
    const items = [
      { name: 'dashboard',  modulo: 'dashboard',    label: 'Inicio',       icon: HomeIcon },
      { name: 'ordenes',    modulo: 'ordenes',      label: 'Órdenes',      icon: ClipboardDocumentListIcon },
      { name: 'produccion', modulo: 'produccion',   label: 'Producción',   icon: WrenchScrewdriverIcon },
      { name: 'despacho',   modulo: 'despacho',     label: 'Despacho',     icon: TruckIcon, badge: despacho.ordenesPendientes },
      // Lleva el contador igual que los demás: el supervisor también puede
      // quedar como validador de un surtido y necesita verlo sin abrir el menú.
      { name: 'inventario', modulo: 'inventario',   label: 'Inventario',   icon: ArchiveBoxIcon, badge: surtidos.pendientesCount },
      { name: 'reportes',   modulo: 'reportes',     label: 'Reportes',     icon: ChartBarIcon },
      { name: 'cotizaciones', modulo: 'cotizaciones', label: 'Cotizaciones',   icon: DocumentTextIcon },
      { name: 'consultas',  modulo: 'consultas',    label: 'Consultar costo', icon: CurrencyDollarIcon, badge: consultasStore.pendientesCount },
    ]
    // "Mis pasos" sale si de verdad lleva pasos, sin importar el cargo.
    if (auth.tieneAccesoPasos) {
      items.unshift({ name: 'mis-pasos', modulo: 'mis-pasos', label: 'Mis pasos', icon: ClipboardDocumentCheckIcon, badge: pasos.pendientesCount })
    }
    if (auth.tieneAccesoRedes) {
      items.push({ name: 'redes', modulo: 'redes', label: 'Redes', icon: ChatBubbleLeftRightIcon, badge: redesPendientes.value })
    }
    if (auth.tieneAccesoComisiones) {
      items.push({ name: 'comisiones', modulo: 'comisiones', label: 'Comisiones', icon: ReceiptPercentIcon })
    }
    // Telas y Métricas se movieron a módulos del home (Dashboard) para aligerar el nav.
    return modulos.soloVisibles(items)
  }
  if (auth.puedeUsarTelas && !auth.isSupervisor) {
    return modulos.soloVisibles([
      { name: 'telas', modulo: 'telas', label: 'Telas', icon: SwatchIcon },
    ])
  }
  if (auth.usuario?.rol === 'conductor') {
    return modulos.soloVisibles([
      { name: 'mis-entregas',        modulo: 'mis-entregas', label: 'Entregas',  icon: TruckIcon, badge: despacho.misEntregasPendientes },
      { name: 'mis-stats-conductor', modulo: 'mis-stats',    label: 'Estadíst.', icon: PresentationChartLineIcon },
    ])
  }
  if (auth.isFacturador) {
    return modulos.soloVisibles([
      { name: 'dashboard',    modulo: 'dashboard',    label: 'Inicio',       icon: HomeIcon },
      { name: 'facturacion',  modulo: 'facturacion',  label: 'Facturación',  icon: DocumentCurrencyDollarIcon, badge: abonosNoLeidos.value },
      { name: 'ordenes',      modulo: 'ordenes',      label: 'Órdenes',      icon: ClipboardDocumentListIcon },
      { name: 'clientes',     modulo: 'clientes',     label: 'Clientes',     icon: UserGroupIcon },
      { name: 'cotizaciones', modulo: 'cotizaciones', label: 'Cotizaciones', icon: DocumentTextIcon },
      { name: 'consultas',    modulo: 'consultas',    label: 'Consultar costo', icon: CurrencyDollarIcon, badge: consultasStore.pendientesCount },
      { name: 'inventario',   modulo: 'inventario',   label: 'Inventario',   icon: ArchiveBoxIcon, badge: surtidos.pendientesCount },
      { name: 'reserva',      modulo: 'fabrica',      label: 'Fábrica',      icon: BuildingOffice2Icon },
      ...(auth.tieneAccesoRedes ? [{ name: 'redes', modulo: 'redes', label: 'Redes', icon: ChatBubbleLeftRightIcon, badge: redesPendientes.value }] : []),
      ...(auth.puedeRecargarTelas ? [{ name: 'telas', modulo: 'telas', label: 'Telas', icon: SwatchIcon }] : []),
      { name: 'mis-stats',    modulo: 'mis-stats',    label: 'Estadíst.',    icon: PresentationChartLineIcon },
    ])
  }
  // Vendedor regular — primeros 4 siempre visibles, resto en "Más"
  return modulos.soloVisibles([
    { name: 'dashboard',  modulo: 'dashboard',    label: 'Inicio',       icon: HomeIcon },
    { name: 'ordenes',    modulo: 'ordenes',      label: 'Órdenes',      icon: ClipboardDocumentListIcon },
    { name: 'clientes',   modulo: 'clientes',     label: 'Clientes',     icon: UserGroupIcon },
    { name: 'cotizaciones', modulo: 'cotizaciones', label: 'Cotizaciones', icon: DocumentTextIcon },
    { name: 'consultas',  modulo: 'consultas',    label: 'Consultar costo', icon: CurrencyDollarIcon, badge: consultasStore.pendientesCount },
    { name: 'inventario', modulo: 'inventario',   label: 'Inventario',   icon: ArchiveBoxIcon, badge: surtidos.pendientesCount },
    { name: 'reserva',    modulo: 'fabrica',      label: 'Fábrica',      icon: BuildingOffice2Icon },
    { name: 'surtir',     modulo: 'traslado',     label: 'Traslado',     icon: ArrowPathIcon },
    ...(auth.tieneAccesoRedes ? [{ name: 'redes', modulo: 'redes', label: 'Redes', icon: ChatBubbleLeftRightIcon, badge: redesPendientes.value }] : []),
    ...(auth.puedeRecargarTelas ? [{ name: 'telas', modulo: 'telas', label: 'Telas', icon: SwatchIcon }] : []),
    { name: 'mis-stats',  modulo: 'mis-stats',    label: 'Estadíst.',    icon: PresentationChartLineIcon },
  ])
})

// Cuántos accesos caben en la fila de abajo además de "Más". Con cinco el
// texto de "Consultar costo" ya no cabe en un celular angosto.
const NAV_MAX_FIJOS = 4

// La barra se puede personalizar solo cuando hay más módulos de los que
// caben: al conductor, con dos, no hay nada que elegir.
const navPersonalizable = computed(() => navItems.value.length > NAV_MAX_FIJOS)

/**
 * Los que van fijos en la barra. Si la persona eligió los suyos, van esos en
 * su orden —descartando lo que ya no le corresponda ver, porque los permisos
 * pueden haber cambiado desde que los eligió—; si no, los primeros de la
 * lista del rol, como siempre.
 */
const navPrimarios = computed(() => {
  const items = navItems.value
  if (!navPersonalizable.value) return items

  const elegidos = Array.isArray(auth.usuario?.nav_favoritos) ? auth.usuario.nav_favoritos : null
  if (elegidos?.length) {
    const propios = elegidos
      .map(name => items.find(i => i.name === name))
      .filter(Boolean)
      .slice(0, NAV_MAX_FIJOS)
    if (propios.length) return propios
  }
  return items.slice(0, NAV_MAX_FIJOS)
})
const navSecundarios = computed(() => {
  if (!navPersonalizable.value) return []
  const fijos = new Set(navPrimarios.value.map(i => i.name))
  return navItems.value.filter(i => !fijos.has(i.name))
})

// ── Personalizar la barra ────────────────────────────────────────────────────
const abrirPersonalizarNav = ref(false)
const navSeleccion         = ref([])   // nombres de ruta, en el orden elegido
const guardandoNav         = ref(false)

function abrirPersonalizar() {
  navSeleccion.value = navPrimarios.value.map(i => i.name)
  abrirMas.value = false
  abrirPersonalizarNav.value = true
}

function toggleNavItem(name) {
  const i = navSeleccion.value.indexOf(name)
  if (i !== -1) {
    navSeleccion.value.splice(i, 1)
  } else if (navSeleccion.value.length < NAV_MAX_FIJOS) {
    navSeleccion.value.push(name)
  }
}
const posicionNav = (name) => {
  const i = navSeleccion.value.indexOf(name)
  return i === -1 ? null : i + 1
}

async function guardarNav(restablecer = false) {
  guardandoNav.value = true
  try {
    const lista = restablecer ? null : navSeleccion.value
    await api.patch('/auth/mi-nav', { nav_favoritos: lista })
    auth.setNavFavoritos(lista)
    abrirPersonalizarNav.value = false
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar la barra.')
  } finally {
    guardandoNav.value = false
  }
}
const masActivo      = computed(() => navSecundarios.value.some(i => i.name === route.name))

// Pendientes que quedaron escondidos dentro de "Más". Sin esto, un badge de
// consultas o de redes no se veía hasta abrir el menú.
const pendientesEnMas = computed(() =>
  navSecundarios.value.reduce((s, i) => s + (Number(i.badge) || 0), 0)
)

function irA(name) {
  abrirMas.value = false
  router.push({ name })
}

async function doLogout() {
  await cancelarPush()
  await auth.logout()
  notif.limpiar()
  router.push({ name: 'login' })
}

/**
 * A dónde lleva una notificación.
 *
 * Una sola función para los dos caminos: la campana de adentro y el clic en la
 * notificación del celular. Antes el push tenía su propio mapeo de cuatro
 * casos —y ni siquiera recibía el tipo—, así que casi todas terminaban
 * tirándote al inicio en vez de a lo que te estaban avisando.
 *
 * Devuelve un destino de vue-router, o null si no hay a dónde ir.
 */
function destinoNotificacion(tipo, datos) {
  datos = datos ?? {}

  // Si el precio ya quedó puesto en una cotización, lo que sigue es mandársela
  // al cliente: se abre la cotización, no el detalle de la consulta.
  if (datos.es_cotizacion && datos.orden_id) {
    return { name: 'cotizacion-detalle', params: { id: datos.orden_id } }
  }
  if (datos.consulta_id)      return { name: 'consulta-detalle', params: { id: datos.consulta_id } }
  if (tipo === 'ruta_atrasada') return { name: 'despacho' }
  // Conductor: cualquier aviso con orden_id lo lleva a sus entregas.
  if (tipo === 'despacho_asignado' || (auth.usuario?.rol === 'conductor' && datos.orden_id)) {
    return { name: 'mis-entregas' }
  }
  if (datos.orden_id) {
    if (tipo === 'venta_otra_tienda') {
      const ids = datos.productos
      return { name: 'inventario', query: ids?.length ? { abrir: ids.join(',') } : {} }
    }
    if (tipo === 'paso_produccion' && auth.tieneAccesoPasos) return { name: 'mis-pasos' }
    return { name: 'orden-detalle', params: { id: datos.orden_id } }
  }
  // Un surtido por validar se acepta en Inventario, sea quien sea.
  if (datos.surtido_id)       return { name: 'inventario' }
  if (tipo === 'stock_agotado') return { name: auth.isSupervisor ? 'surtir' : 'inventario' }
  // Al que valida (traslado_pendiente) y a la tienda que lo recibe
  // (traslado_recibido) les sirve el inventario, abierto en lo que llegó;
  // al que lo inició, surtir.
  if (datos.traslado_id) {
    if (tipo !== 'traslado_pendiente' && tipo !== 'traslado_recibido') return { name: 'surtir' }
    const ids = datos.productos
    return { name: 'inventario', query: ids?.length ? { abrir: ids.join(',') } : {} }
  }
  if (datos.compra_id)        return { name: 'compras' }
  if (datos.cita_id)          return { name: 'citas' }
  if (datos.conversacion_id || tipo === 'redes')     return { name: 'redes' }
  if (datos.comision_id || tipo === 'comisiones')    return { name: 'comisiones' }
  if (datos.produccion_id)    return { name: auth.tieneAccesoPasos ? 'mis-pasos' : 'produccion' }
  // Encargos: el descuento le llega al trabajador y lo lleva a su propia
  // ficha, donde puede ver qué se contó ese día; el aviso de "toca revisar"
  // le llega a quien administra y lo lleva a la lista.
  if (tipo === 'encargo_descuento') return { name: 'encargo-trabajador', params: { id: datos.usuario_id } }
  if (tipo === 'encargo_revision')  return { name: 'encargos' }

  return null
}

async function abrirNotificacion(n) {
  notif.leer(n.id)
  abrirNotif.value = false
  const destino = destinoNotificacion(n.tipo, n.datos ?? {})
  if (destino) router.push(destino)
}

function tipoIcono(tipo) {
  const icons = {
    venta_nueva:        ShoppingCartIcon,
    venta_otra_tienda:  BuildingStorefrontIcon,
    en_produccion:      WrenchScrewdriverIcon,
    entregado:          CheckCircleIcon,
    retrasado:          ExclamationTriangleIcon,
    por_vencer:         ClockIcon,
    entrega_hoy:        CubeIcon,
    cancelado:          XCircleIcon,
    asignar_fecha:      CalendarIcon,
    fecha_asignada:     CalendarDaysIcon,
    surtido_enviado:    ArchiveBoxArrowDownIcon,
    surtido_aceptado:   CheckCircleIcon,
    surtido_rechazado:  XCircleIcon,
    traslado_pendiente: ArrowPathIcon,
    traslado_recibido:  ArchiveBoxArrowDownIcon,
    traslado_aceptado:  CheckCircleIcon,
    traslado_rechazado: XCircleIcon,
    facturar:           ClipboardDocumentListIcon,
    paso_produccion:    WrenchScrewdriverIcon,
    orden_editada:      PencilSquareIcon,
    abono_registrado:   BanknotesIcon,
    cambio_dinero:      BanknotesIcon,
    descuento_revertido: ReceiptPercentIcon,
    stock_agotado:      ArchiveBoxArrowDownIcon,
    redes:              ChatBubbleLeftRightIcon,
    comisiones:         ReceiptPercentIcon,
    cita_recordatorio:          CalendarDaysIcon,
    despacho_asignado:          TruckIcon,
    ruta_atrasada:              ExclamationTriangleIcon,
    consulta_costo_nueva:       CurrencyDollarIcon,
    consulta_costo_respondida:  CurrencyDollarIcon,
    consulta_costo_mensaje:     ChatBubbleLeftRightIcon,
    orden_mensaje:              ChatBubbleLeftRightIcon,
  }
  return icons[tipo] ?? BellIcon
}

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const diffMin = Math.floor((Date.now() - d) / 60000)
  if (diffMin < 1)  return 'Ahora'
  if (diffMin < 60) return `Hace ${diffMin} min`
  const h = Math.floor(diffMin / 60)
  if (h < 24) return `Hace ${h} h`
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}
</script>

<template>
  <div class="flex flex-col min-h-screen bg-gray-50">
    <!-- Cargando: cambio de pantalla o petición al backend en curso -->
    <CargandoS v-if="mostrarCarga" />

    <!-- Top bar -->
    <header v-if="showNav" class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
      <!-- En pantallas angostas el chip de perfil crecia hasta comerse el
           nombre. El logo solo ya identifica: el texto vuelve desde `sm`. -->
      <div class="flex items-center gap-2 min-w-0">
        <img src="/sodege_logo.png" alt="SODEGE" class="h-8 w-auto object-contain shrink-0" />
        <span class="hidden sm:inline font-bold text-blue-600 text-lg">SODEGE</span>
      </div>
      <div class="flex items-center gap-2 min-w-0 shrink-0">
        <!-- Chip de perfil activo / cambio rápido -->
        <button
          v-if="auth.tienePerfilAlternativo"
          @click="auth.cambiarPerfil()"
          class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-blue-50 border border-blue-200 hover:bg-blue-100 transition-colors"
          title="Cambiar perfil"
        >
          <span class="text-xs font-semibold text-blue-700 max-w-[64px] sm:max-w-[80px] truncate">{{ auth.usuario?.nombre }}</span>
          <ArrowsRightLeftIcon class="w-3.5 h-3.5 text-blue-500 flex-shrink-0" />
          <span class="hidden sm:inline text-xs text-gray-400 max-w-[64px] sm:max-w-[80px] truncate">{{ auth.perfilAlternativo?.nombre }}</span>
        </button>
        <!-- Sin perfil alternativo: solo nombre -->
        <button
          v-else
          @click="router.push({ name: 'perfil' })"
          class="hidden sm:flex items-center gap-1.5 text-xs text-gray-500 hover:text-blue-600 transition-colors"
        >
          <UserCircleIcon class="w-5 h-5" />
          {{ auth.usuario?.nombre }}
        </button>
        <button
          v-if="auth.isAuthenticated"
          @click="router.push({ name: 'perfil' })"
          class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-gray-100 transition-colors"
          title="Configuración"
        >
          <Cog6ToothIcon class="w-5 h-5" />
        </button>

        <!-- Campana de notificaciones -->
        <div v-if="auth.isAuthenticated" class="relative">
          <button @click="abrirNotif = !abrirNotif" class="relative p-1">
            <!-- Con algo urgente sin leer la campana misma se pone roja y late -->
            <BellIcon :class="['w-6 h-6', urgentesNoLeidas > 0 ? 'text-red-600 animate-pulse' : 'text-gray-600']" />
            <span
              v-if="notif.noLeidas > 0"
              class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5"
            >
              {{ notif.noLeidas > 9 ? '9+' : notif.noLeidas }}
            </span>
          </button>

          <!-- Dropdown y backdrop teleportados a body para evitar problemas de stacking context -->
          <Teleport to="body">
            <div v-if="abrirNotif" class="fixed inset-0 z-[100] bg-black/20 sm:bg-transparent" @click="abrirNotif = false" />
            <div
              v-if="abrirNotif"
              class="fixed inset-x-2 top-14 z-[110] max-h-[70vh] overflow-y-auto overflow-x-hidden bg-white rounded-xl shadow-xl border border-gray-200 sm:inset-x-auto sm:left-auto sm:right-4 sm:w-80 sm:max-h-[28rem]"
            >
              <div class="flex items-center justify-between gap-2 px-4 py-2 border-b border-gray-100 sticky top-0 bg-white">
                <span class="font-semibold text-sm text-gray-700 shrink-0">Notificaciones</span>
                <div class="flex items-center gap-2 min-w-0">
                  <button
                    v-if="notif.noLeidas > 0"
                    @click="notif.leerTodas()"
                    class="text-xs text-blue-600 hover:underline whitespace-nowrap"
                  >
                    Leer todas
                  </button>
                  <button
                    v-if="notif.items.length > 0"
                    @click="notif.eliminarTodas()"
                    class="text-xs text-red-500 hover:text-red-700 flex items-center gap-0.5 shrink-0"
                    title="Eliminar todas"
                  >
                    <TrashIcon class="w-3.5 h-3.5" />
                    Limpiar
                  </button>
                </div>
              </div>

              <div v-if="notif.items.length === 0" class="py-10 text-center text-gray-400 text-sm">
                Sin notificaciones
              </div>

              <div
                v-for="n in notificacionesOrdenadas"
                :key="n.id"
                :class="[
                  'flex items-start border-b transition-colors',
                  n.urgente && !n.leida
                    ? 'bg-red-50 border-red-100'
                    : (!n.leida ? 'bg-blue-50 border-gray-50' : 'border-gray-50 hover:bg-gray-50'),
                ]"
              >
                <button
                  @click="abrirNotificacion(n)"
                  class="flex-1 text-left px-4 py-3"
                >
                  <div class="flex gap-2 items-start">
                    <component
                      :is="tipoIcono(n.tipo)"
                      :class="['w-4 h-4 mt-0.5 flex-shrink-0', n.urgente && !n.leida ? 'text-red-600' : 'text-gray-600']"
                    />
                    <div class="flex-1 min-w-0 overflow-hidden">
                      <p class="text-sm font-medium text-gray-800 leading-tight break-words">
                        <span
                          v-if="n.urgente && !n.leida"
                          class="inline-block bg-red-600 text-white text-[9px] font-bold px-1.5 py-0.5 rounded mr-1 align-middle"
                        >URGENTE</span>
                        {{ n.titulo }}
                      </p>
                      <p class="text-xs text-gray-500 leading-snug mt-0.5 break-words">{{ n.mensaje }}</p>
                      <p class="text-[11px] text-gray-400 mt-1">{{ formatFecha(n.created_at) }}</p>
                    </div>
                    <span
                      v-if="!n.leida"
                      :class="['w-2 h-2 rounded-full mt-1 flex-shrink-0', n.urgente ? 'bg-red-500' : 'bg-blue-500']"
                    />
                  </div>
                </button>
                <button
                  @click.stop="notif.eliminar(n.id)"
                  class="p-3 text-gray-300 hover:text-red-500 transition-colors flex-shrink-0"
                  title="Eliminar notificación"
                >
                  <TrashIcon class="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          </Teleport>
        </div>

        <button
          @click="doLogout"
          class="flex items-center gap-1.5 bg-red-500 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-600 transition-colors"
        >
          <ArrowRightStartOnRectangleIcon class="w-4 h-4" />
          Cerrar sesión
        </button>
      </div>
    </header>

    <!-- Page content -->
    <main class="flex-1 pb-20 relative">
      <RouterView v-slot="{ Component, route }">
        <Transition name="page">
          <component :is="Component" :key="route.fullPath" />
        </Transition>
      </RouterView>
    </main>

    <!-- ── Bottom tab bar ───────────────────────────────────────────────────── -->
    <nav v-if="showNav" class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 z-10">

      <!-- Menú "Más" (panel sobre el nav).
           En rejilla de 4 por fila: antes iban todos en una sola fila y con
           seis o siete ítems quedaban a 50px, donde "Consultar costo" no cabe. -->
      <Transition name="slide-up">
        <div
          v-if="abrirMas && navSecundarios.length"
          class="grid grid-cols-4 sm:grid-cols-6 border-b border-gray-100 bg-white"
        >
          <button
            v-for="item in navSecundarios"
            :key="item.name"
            @click="irA(item.name)"
            :class="[
              'min-w-0 flex flex-col items-center justify-start py-3 px-1 gap-1 transition-colors',
              route.name === item.name ? 'text-blue-600 font-semibold' : 'text-gray-500',
            ]"
          >
            <div class="relative flex-shrink-0">
              <component :is="item.icon" class="w-6 h-6" />
              <span
                v-if="item.badge > 0"
                class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5"
              >
                {{ item.badge > 9 ? '9+' : item.badge }}
              </span>
            </div>
            <span class="w-full text-[10px] leading-tight text-center line-clamp-2">{{ item.label }}</span>
          </button>

          <!-- Elegir qué va fijo abajo. Va dentro de "Más" porque es desde
               donde uno se cansa de buscar el módulo que usa a diario. -->
          <button
            @click="abrirPersonalizar"
            class="min-w-0 flex flex-col items-center justify-start py-3 px-1 gap-1 text-gray-400 hover:text-blue-600 transition-colors"
          >
            <AdjustmentsHorizontalIcon class="w-6 h-6" />
            <span class="w-full text-[10px] leading-tight text-center line-clamp-2">Personalizar barra</span>
          </button>
        </div>
      </Transition>

      <!-- Fila principal. Los botones se reparten el ancho por igual y la
           etiqueta se parte en dos líneas antes que desbordar el botón. -->
      <div class="flex items-stretch">
        <!-- Ítems primarios -->
        <button
          v-for="item in navPrimarios"
          :key="item.name"
          @click="irA(item.name)"
          :class="[
            'flex-1 min-w-0 flex flex-col items-center justify-start py-2 px-0.5 gap-1 transition-colors',
            route.name === item.name ? 'text-blue-600 font-semibold' : 'text-gray-500',
          ]"
        >
          <div class="relative flex-shrink-0">
            <component :is="item.icon" class="w-6 h-6" />
            <span
              v-if="item.badge > 0"
              class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5"
            >
              {{ item.badge > 9 ? '9+' : item.badge }}
            </span>
          </div>
          <span class="w-full text-[10px] leading-tight text-center line-clamp-2">{{ item.label }}</span>
        </button>

        <!-- Botón "Más" -->
        <button
          v-if="navSecundarios.length"
          @click="abrirMas = !abrirMas"
          :class="[
            'flex-1 min-w-0 flex flex-col items-center justify-start py-2 px-0.5 gap-1 transition-colors',
            masActivo || abrirMas ? 'text-blue-600 font-semibold' : 'text-gray-500',
          ]"
        >
          <div class="relative flex-shrink-0">
            <EllipsisHorizontalIcon class="w-6 h-6" />
            <!-- Que se note si algo pendiente quedó escondido aquí dentro -->
            <span
              v-if="pendientesEnMas > 0"
              class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5"
            >
              {{ pendientesEnMas > 9 ? '9+' : pendientesEnMas }}
            </span>
          </div>
          <span class="w-full text-[10px] leading-tight text-center">Más</span>
        </button>
      </div>
    </nav>

    <!-- Backdrop "Más" -->
    <div
      v-if="abrirMas"
      class="fixed inset-0 z-[9]"
      @click="abrirMas = false"
    />

    <!-- Personalizar la barra de abajo -->
    <Transition name="fade">
      <div v-if="abrirPersonalizarNav" class="fixed inset-0 z-[120] flex items-end sm:items-center justify-center" @click.self="abrirPersonalizarNav = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4 max-h-[88vh] overflow-y-auto pb-8">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-lg font-bold text-gray-800">Personalizar barra</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                Toca hasta <strong>{{ NAV_MAX_FIJOS }}</strong> módulos en el orden en que los quieres abajo. El resto queda en "Más".
              </p>
            </div>
            <button @click="abrirPersonalizarNav = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <!-- Vista previa de cómo queda -->
          <div class="rounded-xl border border-gray-200 bg-gray-50 px-2 py-2">
            <div class="flex items-stretch">
              <div
                v-for="name in navSeleccion" :key="name"
                class="flex-1 min-w-0 flex flex-col items-center gap-1 py-1 text-blue-600"
              >
                <component :is="navItems.find(i => i.name === name)?.icon" class="w-5 h-5" />
                <span class="w-full text-[10px] leading-tight text-center line-clamp-2">{{ navItems.find(i => i.name === name)?.label }}</span>
              </div>
              <div v-for="n in (NAV_MAX_FIJOS - navSeleccion.length)" :key="'vacio-' + n" class="flex-1 min-w-0 flex flex-col items-center gap-1 py-1 text-gray-300">
                <div class="w-5 h-5 rounded-full border-2 border-dashed border-gray-300" />
                <span class="text-[10px]">libre</span>
              </div>
              <div class="flex-1 min-w-0 flex flex-col items-center gap-1 py-1 text-gray-400">
                <EllipsisHorizontalIcon class="w-5 h-5" />
                <span class="text-[10px]">Más</span>
              </div>
            </div>
          </div>

          <div class="space-y-1.5">
            <button
              v-for="item in navItems" :key="item.name" type="button"
              @click="toggleNavItem(item.name)"
              :disabled="!posicionNav(item.name) && navSeleccion.length >= NAV_MAX_FIJOS"
              :class="['w-full flex items-center gap-3 px-3 py-2.5 rounded-xl border-2 text-left transition-all disabled:opacity-40',
                posicionNav(item.name) ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300']"
            >
              <span :class="['w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0',
                posicionNav(item.name) ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-400']">
                {{ posicionNav(item.name) ?? '+' }}
              </span>
              <component :is="item.icon" class="w-5 h-5 text-gray-500 flex-shrink-0" />
              <span class="text-sm font-medium text-gray-800">{{ item.label }}</span>
            </button>
          </div>

          <div class="flex gap-3">
            <button @click="guardarNav(true)" :disabled="guardandoNav"
              class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold disabled:opacity-50">
              Volver a la de siempre
            </button>
            <button @click="guardarNav(false)" :disabled="guardandoNav || !navSeleccion.length"
              class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
              {{ guardandoNav ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Scroll to top -->
    <ScrollToTop />

    <!-- PWA install prompt -->
    <AppInstallPrompt />

    <!-- Toasts globales -->
    <ToastContainer />

    <!-- Agente de IA — solo supervisor, vendedor y ebanista -->
    <AgentChat v-if="auth.isAuthenticated && (auth.isSupervisor || auth.usuario?.rol === 'vendedor')" />
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }

.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.18s ease, opacity 0.18s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(8px);
  opacity: 0;
}

.page-enter-active {
  transition: opacity 0.15s ease;
}
.page-leave-active {
  transition: opacity 0.1s ease;
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.page-enter-from,
.page-leave-to {
  opacity: 0;
}
</style>
