import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
  { path: '/',        name: 'dashboard',  component: () => import('@/views/DashboardView.vue'),  meta: { requiresAuth: true } },
  { path: '/ordenes', name: 'ordenes',    component: () => import('@/views/OrdenesView.vue'),    meta: { requiresAuth: true } },
  { path: '/ordenes/:id', name: 'orden-detalle', component: () => import('@/views/OrdenDetalleView.vue'), meta: { requiresAuth: true } },
  { path: '/ordenes/nueva', name: 'nueva-orden', component: () => import('@/views/NuevaOrdenView.vue'), meta: { requiresAuth: true } },
  { path: '/clientes', name: 'clientes',  component: () => import('@/views/ClientesView.vue'),   meta: { requiresAuth: true } },
  { path: '/clientes/:id', name: 'cliente-detalle', component: () => import('@/views/ClienteDetalleView.vue'), meta: { requiresAuth: true } },
  { path: '/inventario', name: 'inventario', component: () => import('@/views/InventarioView.vue'), meta: { requiresAuth: true } },
  { path: '/produccion', name: 'produccion', component: () => import('@/views/ProduccionView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/mis-stats',  name: 'mis-stats',  component: () => import('@/views/StatsVendedorView.vue'), meta: { requiresAuth: true } },
  { path: '/mis-stats-conductor', name: 'mis-stats-conductor', component: () => import('@/views/StatsConductorView.vue'), meta: { requiresAuth: true, requiresConductor: true } },
  { path: '/reportes',   name: 'reportes',   component: () => import('@/views/ReportesView.vue'),   meta: { requiresAuth: true, requiresReportes: true } },
  { path: '/usuarios', name: 'usuarios', component: () => import('@/views/UsuariosView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios/crear', name: 'usuario-crear', component: () => import('@/views/UsuarioCrearView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios/:id', name: 'usuario-detalle', component: () => import('@/views/UsuarioDetalleView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/perfil', name: 'perfil', component: () => import('@/views/PerfilView.vue'), meta: { requiresAuth: true } },
  { path: '/despacho', name: 'despacho', component: () => import('@/views/DespachoView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/mis-entregas', name: 'mis-entregas', component: () => import('@/views/MisEntregasView.vue'), meta: { requiresAuth: true, requiresConductor: true } },
  { path: '/surtir', name: 'surtir', component: () => import('@/views/SurtirView.vue'), meta: { requiresAuth: true, requiresSurtir: true } },
  // Nuevas rutas para roles de producción
  { path: '/mis-pasos', name: 'mis-pasos', component: () => import('@/views/EbanistaView.vue'), meta: { requiresAuth: true, requiresProduccionWorker: true } },
  { path: '/despacho-produccion', name: 'despacho-produccion', component: () => import('@/views/DespachadorProduccionView.vue'), meta: { requiresAuth: true, requiresDespachador: true } },
  { path: '/stats/vendedor/:id', name: 'stats-vendedor', component: () => import('@/views/StatsVendedorDetalleView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/costos', name: 'costos', component: () => import('@/views/CostosView.vue'), meta: { requiresAuth: true, requiresCostos: true } },
  { path: '/facturacion', name: 'facturacion', component: () => import('@/views/FacturacionView.vue'), meta: { requiresAuth: true, requiresFacturador: true } },
  { path: '/reserva', name: 'reserva', component: () => import('@/views/ReservaView.vue'), meta: { requiresAuth: true, requiresReserva: true } },
  { path: '/redes',  name: 'redes',  component: () => import('@/views/RedesView.vue'),  meta: { requiresAuth: true, requiresRedes: true } },
  { path: '/citas',  name: 'citas',  component: () => import('@/views/CitasView.vue'),  meta: { requiresAuth: true } },
  { path: '/consultas-costo', name: 'consultas', component: () => import('@/views/ConsultasView.vue'), meta: { requiresAuth: true, requiresConsultas: true } },
  { path: '/consultas-costo/:id', name: 'consulta-detalle', component: () => import('@/views/ConsultaDetalleView.vue'), meta: { requiresAuth: true, requiresConsultas: true } },
  { path: '/telas', name: 'telas', component: () => import('@/views/TelasView.vue'), meta: { requiresAuth: true, requiresTelas: true } },
  { path: '/caja',  name: 'caja',  component: () => import('@/views/CajaView.vue'),  meta: { requiresAuth: true, requiresCaja: true } },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

// Cuando un chunk lazy falla por cache stale (deployment nuevo), navegar directamente
router.onError((err, to) => {
  const isChunkError = err?.message && (
    err.message.includes('Failed to fetch dynamically imported module') ||
    err.message.includes('Importing a module script failed') ||
    err.message.includes('error loading dynamically imported module') ||
    err.message.includes('MIME type')
  )
  if (isChunkError) {
    window.location.href = to?.fullPath ?? window.location.pathname
  }
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login' }
  if (to.meta.guest && auth.isAuthenticated) return { name: 'dashboard' }
  if (to.meta.requiresSupervisor && !auth.isSupervisor) return { name: 'dashboard' }
  if (to.meta.requiresReportes && !auth.isSupervisor && !auth.isEbanista) return { name: 'dashboard' }
  if (to.meta.requiresSurtir && !auth.isSupervisor && auth.usuario?.rol !== 'vendedor') return { name: 'dashboard' }
  if (to.meta.requiresCostos && !auth.isSupervisor && auth.usuario?.rol !== 'ebanista') return { name: 'dashboard' }
  if (to.meta.requiresConductor && auth.usuario?.rol !== 'conductor') return { name: 'dashboard' }
  if (to.meta.requiresDespachador && auth.usuario?.rol !== 'despachador') return { name: 'dashboard' }
  if (to.meta.requiresProduccionWorker && !auth.tieneAccesoPasos) return { name: 'dashboard' }
  if (to.meta.requiresFacturador && !auth.isFacturador) return { name: 'dashboard' }
  if (to.meta.requiresRedes && !auth.tieneAccesoRedes) return { name: 'dashboard' }
  if (to.meta.requiresConsultas && !auth.isSupervisor && auth.usuario?.rol !== 'ebanista' && auth.usuario?.rol !== 'vendedor') return { name: 'dashboard' }
  if (to.meta.requiresReserva && !auth.isSupervisor && auth.usuario?.rol !== 'vendedor') return { name: 'dashboard' }
  if (to.meta.requiresTelas && !auth.isCosturero && !auth.puedeRecargarTelas && auth.usuario?.rol !== 'vendedor' && !auth.isEbanista) return { name: 'dashboard' }
  if (to.meta.requiresCaja && !auth.isSupervisor && auth.usuario?.rol !== 'vendedor' && !auth.isEbanista) return { name: 'dashboard' }
})

export default router
