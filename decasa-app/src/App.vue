<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import api from '@/api'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificacionesStore } from '@/stores/notificaciones'
import { useDespachoStore } from '@/stores/despacho'
import { useSurtidosStore } from '@/stores/surtidos'
import { usePasosStore } from '@/stores/pasos'
import { useDespachoProduccionStore } from '@/stores/despachoProduccion'
import { useConsultasStore } from '@/stores/consultas'
import { useSurtidosSocket } from '@/composables/useSurtidosSocket'
import { registrarPush, cancelarPush } from '@/composables/usePushNotifications'
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
  ChatBubbleLeftRightIcon,
  ArrowPathIcon,
  CurrencyDollarIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const notif  = useNotificacionesStore()
const despacho = useDespachoStore()
const surtidos = useSurtidosStore()
const pasos    = usePasosStore()
const despachoProd = useDespachoProduccionStore()
const consultasStore = useConsultasStore()
const { conectar: conectarSurtidos } = useSurtidosSocket()

const showNav    = computed(() => auth.isAuthenticated && route.name !== 'login')
const abrirNotif = ref(false)
const abrirMas   = ref(false)

function onSwMessage(e) {
  if (e.data?.type === 'push-click') {
    const datos = e.data.datos ?? {}
    if (datos.cita_id)          router.push({ name: 'citas' })
    else if (datos.conversacion_id) router.push({ name: 'redes' })
    else if (datos.orden_id)    router.push({ name: 'orden-detalle', params: { id: datos.orden_id } })
    else                         router.push({ name: 'dashboard' })
  }
}

onMounted(() => {
  auth.fetchMe()
  navigator.serviceWorker?.addEventListener('message', onSwMessage)
})
onUnmounted(() => {
  navigator.serviceWorker?.removeEventListener('message', onSwMessage)
})

watch(() => auth.isAuthenticated, (isAuth) => {
  if (!isAuth) return
  notif.cargar()
  registrarPush()
  if (auth.isSupervisor) {
    despacho.refrescar()
  }
  if (auth.usuario?.rol === 'conductor') {
    despacho.cargarMisEntregas()
  }
  if (auth.usuario?.rol === 'vendedor') {
    surtidos.cargarPendientes()
  }
  if (auth.usuario?.rol === 'ebanista' || (auth.usuario?.rol === 'supervisor' && auth.usuario?.es_tapicero)) {
    pasos.cargar()
  }
  if (auth.isSupervisor || auth.isEbanista || auth.usuario?.rol === 'vendedor') {
    consultasStore.cargar()
  }
  if (auth.usuario?.rol === 'despachador') {
    despachoProd.cargar()
  }
}, { immediate: true })

// Reconectar al supervisor tapicero también como trabajador de producción
// (solo necesita las notificaciones normales — ya las recibe por su canal)

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
      if (n.tipo === 'paso_produccion' && (auth.isEbanista || auth.isTapicero)) {
        pasos.cargar()
      }
      if (n.tipo === 'paso_produccion' && auth.isDespachador) {
        despachoProd.cargar()
      }
      if (['consulta_costo_nueva', 'consulta_costo_respondida', 'consulta_costo_mensaje'].includes(n.tipo)) {
        if (auth.isSupervisor || auth.isEbanista || auth.usuario?.rol === 'vendedor') consultasStore.cargar()
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

// Badge de conversaciones WA pendientes (carga inicial + actualización por WebSocket)
const redesPendientes = ref(0)
function cargarRedesPendientes() {
  if (!auth.isAuthenticated || !auth.tieneAccesoRedes) return
  api.get('/redes/conversaciones?estado=pendiente').then(r => {
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

const navItems = computed(() => {
  if (auth.isSupervisor) {
    const items = [
      { name: 'dashboard',  label: 'Inicio',       icon: HomeIcon },
      { name: 'ordenes',    label: 'Órdenes',      icon: ClipboardDocumentListIcon },
      { name: 'produccion', label: 'Producción',   icon: WrenchScrewdriverIcon },
      { name: 'despacho',   label: 'Despacho',     icon: TruckIcon, badge: despacho.ordenesPendientes },
      { name: 'inventario', label: 'Inventario',   icon: ArchiveBoxIcon },
      { name: 'reportes',   label: 'Reportes',     icon: ChartBarIcon },
      { name: 'consultas',  label: 'Cotizaciones', icon: CurrencyDollarIcon, badge: consultasStore.pendientesCount },
    ]
    if (auth.isTapicero) {
      items.unshift({ name: 'mis-pasos', label: 'Mis pasos', icon: ClipboardDocumentCheckIcon, badge: pasos.pendientesCount })
    }
    if (auth.tieneAccesoRedes) {
      items.push({ name: 'redes', label: 'Redes', icon: ChatBubbleLeftRightIcon, badge: redesPendientes.value })
    }
    return items
  }
  if (auth.usuario?.rol === 'conductor') {
    return [
      { name: 'mis-entregas',        label: 'Entregas',  icon: TruckIcon, badge: despacho.misEntregasPendientes },
      { name: 'mis-stats-conductor', label: 'Estadíst.', icon: PresentationChartLineIcon },
    ]
  }
  if (auth.usuario?.rol === 'ebanista' || (auth.usuario?.rol === 'supervisor' && auth.usuario?.es_tapicero)) {
    return [
      { name: 'mis-pasos',  label: 'Mis pasos',   icon: WrenchScrewdriverIcon, badge: pasos.pendientesCount },
      { name: 'consultas',  label: 'Cotizaciones', icon: CurrencyDollarIcon, badge: consultasStore.pendientesCount },
      { name: 'costos',     label: 'Costos',       icon: CalculatorIcon },
      { name: 'perfil',     label: 'Perfil',       icon: UserCircleIcon },
    ]
  }
  if (auth.usuario?.rol === 'despachador') {
    return [
      { name: 'despacho-produccion', label: 'Despacho prod.', icon: ArchiveBoxArrowDownIcon, badge: despachoProd.pendientesCount },
      { name: 'perfil',              label: 'Perfil',          icon: UserCircleIcon },
    ]
  }
  if (auth.isFacturador) {
    return [
      { name: 'dashboard',    label: 'Inicio',       icon: HomeIcon },
      { name: 'facturacion',  label: 'Facturación',  icon: DocumentCurrencyDollarIcon, badge: abonosNoLeidos.value },
      { name: 'ordenes',      label: 'Órdenes',      icon: ClipboardDocumentListIcon },
      { name: 'clientes',     label: 'Clientes',     icon: UserGroupIcon },
      { name: 'consultas',    label: 'Cotizaciones', icon: CurrencyDollarIcon, badge: consultasStore.pendientesCount },
      { name: 'inventario',   label: 'Inventario',   icon: ArchiveBoxIcon, badge: surtidos.pendientesCount },
      ...(auth.tieneAccesoRedes ? [{ name: 'redes', label: 'Redes', icon: ChatBubbleLeftRightIcon, badge: redesPendientes.value }] : []),
      { name: 'citas',        label: 'Citas',        icon: CalendarDaysIcon },
      { name: 'mis-stats',    label: 'Estadíst.',    icon: PresentationChartLineIcon },
      { name: 'perfil',       label: 'Perfil',       icon: UserCircleIcon },
    ]
  }
  // Vendedor regular — primeros 4 siempre visibles, resto en "Más"
  return [
    { name: 'dashboard',  label: 'Inicio',       icon: HomeIcon },
    { name: 'ordenes',    label: 'Órdenes',      icon: ClipboardDocumentListIcon },
    { name: 'clientes',   label: 'Clientes',     icon: UserGroupIcon },
    { name: 'consultas',  label: 'Cotizaciones', icon: CurrencyDollarIcon, badge: consultasStore.pendientesCount },
    { name: 'inventario', label: 'Inventario',   icon: ArchiveBoxIcon, badge: surtidos.pendientesCount },
    { name: 'surtir',     label: 'Traslado',     icon: ArrowPathIcon },
    ...(auth.tieneAccesoRedes ? [{ name: 'redes', label: 'Redes', icon: ChatBubbleLeftRightIcon, badge: redesPendientes.value }] : []),
    { name: 'citas',      label: 'Citas',        icon: CalendarDaysIcon },
    { name: 'mis-stats',  label: 'Estadíst.',    icon: PresentationChartLineIcon },
    { name: 'perfil',     label: 'Perfil',       icon: UserCircleIcon },
  ]
})

// Conductor y roles con ≤4 items: todos visibles. Supervisor/facturador/vendedor: 4 + "Más"
const navPrimarios   = computed(() => {
  const items = navItems.value
  if (auth.usuario?.rol === 'conductor') return items
  if (auth.isSupervisor || auth.isFacturador || auth.usuario?.rol === 'vendedor') return items.slice(0, 4)
  return items
})
const navSecundarios = computed(() => {
  if (auth.usuario?.rol === 'conductor') return []
  if (auth.isSupervisor || auth.isFacturador || auth.usuario?.rol === 'vendedor') return navItems.value.slice(4)
  return []
})
const masActivo      = computed(() => navSecundarios.value.some(i => i.name === route.name))

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

async function abrirNotificacion(n) {
  notif.leer(n.id)
  abrirNotif.value = false
  const datos = n.datos ?? {}
  // Notificaciones de consultas de costo — van al detalle de la consulta
  if (datos.consulta_id) {
    router.push({ name: 'consulta-detalle', params: { id: datos.consulta_id } })
  } else if (n.tipo === 'ruta_atrasada') {
    router.push({ name: 'despacho' })
  } else if (n.tipo === 'despacho_asignado' || (auth.usuario?.rol === 'conductor' && datos.orden_id)) {
    // Conductor: cualquier notificación con orden_id o despacho asignado va a sus entregas
    router.push({ name: 'mis-entregas' })
  } else if (datos.orden_id) {
    if (n.tipo === 'venta_otra_tienda') {
      const ids = datos.productos
      router.push({ name: 'inventario', query: ids?.length ? { abrir: ids.join(',') } : {} })
    } else if (n.tipo === 'paso_produccion' && auth.tieneAccesoPasos) {
      router.push({ name: 'mis-pasos' })
    } else {
      router.push({ name: 'orden-detalle', params: { id: datos.orden_id } })
    }
  } else if (datos.surtido_id) {
    router.push({ name: auth.isSupervisor ? 'surtir' : 'inventario' })
  } else if (datos.traslado_id) {
    // Validador (traslado_pendiente) → inventario; iniciador (aceptado/rechazado) → surtir
    router.push({ name: n.tipo === 'traslado_pendiente' ? 'inventario' : 'surtir' })
  } else if (datos.cita_id) {
    router.push({ name: 'citas' })
  } else if (datos.conversacion_id || n.tipo === 'redes') {
    router.push({ name: 'redes' })
  }
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
    traslado_aceptado:  CheckCircleIcon,
    traslado_rechazado: XCircleIcon,
    facturar:           ClipboardDocumentListIcon,
    paso_produccion:    WrenchScrewdriverIcon,
    orden_editada:      PencilSquareIcon,
    abono_registrado:   BanknotesIcon,
    redes:              ChatBubbleLeftRightIcon,
    cita_recordatorio:          CalendarDaysIcon,
    despacho_asignado:          TruckIcon,
    ruta_atrasada:              ExclamationTriangleIcon,
    consulta_costo_nueva:       CurrencyDollarIcon,
    consulta_costo_respondida:  CurrencyDollarIcon,
    consulta_costo_mensaje:     ChatBubbleLeftRightIcon,
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
    <!-- Top bar -->
    <header v-if="showNav" class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
      <span class="font-bold text-blue-600 text-lg">Decasa</span>
      <div class="flex items-center gap-3">
        <button
          @click="router.push({ name: 'perfil' })"
          class="hidden sm:flex items-center gap-1.5 text-xs text-gray-500 hover:text-blue-600 transition-colors"
        >
          <UserCircleIcon class="w-5 h-5" />
          {{ auth.usuario?.nombre }}
        </button>

        <!-- Campana de notificaciones -->
        <div v-if="auth.isAuthenticated" class="relative">
          <button @click="abrirNotif = !abrirNotif" class="relative p-1">
            <BellIcon class="w-6 h-6 text-gray-600" />
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
              class="fixed inset-x-2 top-14 z-[110] max-h-[70vh] overflow-y-auto bg-white rounded-xl shadow-xl border border-gray-200 sm:inset-x-auto sm:left-auto sm:right-4 sm:w-80 sm:max-h-[28rem]"
            >
              <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100 sticky top-0 bg-white">
                <span class="font-semibold text-sm text-gray-700">Notificaciones</span>
                <div class="flex items-center gap-3">
                  <button
                    v-if="notif.noLeidas > 0"
                    @click="notif.leerTodas()"
                    class="text-xs text-blue-600 hover:underline"
                  >
                    Marcar todas como leídas
                  </button>
                  <button
                    v-if="notif.items.length > 0"
                    @click="notif.eliminarTodas()"
                    class="text-xs text-red-500 hover:text-red-700 flex items-center gap-0.5"
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
                v-for="n in notif.items"
                :key="n.id"
                :class="[
                  'flex items-start border-b border-gray-50 transition-colors',
                  !n.leida ? 'bg-blue-50' : 'hover:bg-gray-50',
                ]"
              >
                <button
                  @click="abrirNotificacion(n)"
                  class="flex-1 text-left px-4 py-3"
                >
                  <div class="flex gap-2 items-start">
                    <component :is="tipoIcono(n.tipo)" class="w-4 h-4 mt-0.5 text-gray-600 flex-shrink-0" />
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-gray-800 leading-tight">{{ n.titulo }}</p>
                      <p class="text-xs text-gray-500 leading-snug mt-0.5">{{ n.mensaje }}</p>
                      <p class="text-[11px] text-gray-400 mt-1">{{ formatFecha(n.created_at) }}</p>
                    </div>
                    <span v-if="!n.leida" class="w-2 h-2 bg-blue-500 rounded-full mt-1 flex-shrink-0" />
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
    <main class="flex-1 pb-20">
      <RouterView v-slot="{ Component, route }">
        <Transition name="page" mode="out-in">
          <component :is="Component" :key="route.fullPath" />
        </Transition>
      </RouterView>
    </main>

    <!-- ── Bottom tab bar ───────────────────────────────────────────────────── -->
    <nav v-if="showNav" class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 z-10">

      <!-- Menú "Más" para supervisor (panel sobre el nav) -->
      <Transition name="slide-up">
        <div
          v-if="abrirMas && navSecundarios.length"
          class="flex border-b border-gray-100 bg-white"
        >
          <button
            v-for="item in navSecundarios"
            :key="item.name"
            @click="irA(item.name)"
            :class="[
              'flex-1 flex flex-col items-center py-3 text-xs gap-0.5 transition-colors',
              route.name === item.name ? 'text-blue-600 font-semibold' : 'text-gray-500',
            ]"
          >
          <div class="relative">
            <component :is="item.icon" class="w-6 h-6" />
            <span
              v-if="item.badge > 0"
              class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5"
            >
              {{ item.badge > 9 ? '9+' : item.badge }}
            </span>
          </div>
          {{ item.label }}
          </button>
        </div>
      </Transition>

      <!-- Fila principal -->
      <div class="flex">
        <!-- Ítems primarios -->
        <button
          v-for="item in navPrimarios"
          :key="item.name"
          @click="irA(item.name)"
          :class="[
            'flex-1 flex flex-col items-center py-2 text-xs gap-0.5 transition-colors',
            route.name === item.name ? 'text-blue-600 font-semibold' : 'text-gray-500',
          ]"
        >
          <div class="relative">
            <component :is="item.icon" class="w-6 h-6" />
            <span
              v-if="item.badge > 0"
              class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5"
            >
              {{ item.badge > 9 ? '9+' : item.badge }}
            </span>
          </div>
          {{ item.label }}
        </button>

        <!-- Botón "Más" — solo supervisor -->
        <button
          v-if="navSecundarios.length"
          @click="abrirMas = !abrirMas"
          :class="[
            'flex-1 flex flex-col items-center py-2 text-xs gap-0.5 transition-colors',
            masActivo || abrirMas ? 'text-blue-600 font-semibold' : 'text-gray-500',
          ]"
        >
          <div class="relative">
            <EllipsisHorizontalIcon class="w-6 h-6" />
          </div>
          Más
        </button>
      </div>
    </nav>

    <!-- Backdrop "Más" -->
    <div
      v-if="abrirMas"
      class="fixed inset-0 z-[9]"
      @click="abrirMas = false"
    />

    <!-- Scroll to top -->
    <ScrollToTop />

    <!-- PWA install prompt -->
    <AppInstallPrompt />

    <!-- Toasts globales -->
    <ToastContainer />

    <!-- Agente de IA — solo supervisor, vendedor y ebanista -->
    <AgentChat v-if="auth.isAuthenticated && (auth.isSupervisor || auth.usuario?.rol === 'vendedor' || auth.usuario?.rol === 'ebanista')" />
  </div>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.18s ease, opacity 0.18s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(8px);
  opacity: 0;
}

.page-enter-active,
.page-leave-active {
  transition: opacity 0.12s ease;
}
.page-enter-from,
.page-leave-to {
  opacity: 0;
}
</style>
