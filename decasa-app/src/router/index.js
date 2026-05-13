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
  { path: '/reportes',   name: 'reportes',   component: () => import('@/views/ReportesView.vue'),   meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios', name: 'usuarios', component: () => import('@/views/UsuariosView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios/crear', name: 'usuario-crear', component: () => import('@/views/UsuarioCrearView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios/:id', name: 'usuario-detalle', component: () => import('@/views/UsuarioDetalleView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/perfil', name: 'perfil', component: () => import('@/views/PerfilView.vue'), meta: { requiresAuth: true } },
  { path: '/despacho', name: 'despacho', component: () => import('@/views/DespachoView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/mis-entregas', name: 'mis-entregas', component: () => import('@/views/MisEntregasView.vue'), meta: { requiresAuth: true, requiresConductor: true } },
  { path: '/surtir', name: 'surtir', component: () => import('@/views/SurtirView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  // Nuevas rutas para roles de producción
  { path: '/mis-pasos', name: 'mis-pasos', component: () => import('@/views/EbanistaView.vue'), meta: { requiresAuth: true, requiresProduccionWorker: true } },
  { path: '/despacho-produccion', name: 'despacho-produccion', component: () => import('@/views/DespachadorProduccionView.vue'), meta: { requiresAuth: true, requiresDespachador: true } },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login' }
  if (to.meta.guest && auth.isAuthenticated) return { name: 'dashboard' }
  if (to.meta.requiresSupervisor && !auth.isSupervisor) return { name: 'dashboard' }
  if (to.meta.requiresConductor && auth.usuario?.rol !== 'conductor') return { name: 'dashboard' }
  if (to.meta.requiresDespachador && auth.usuario?.rol !== 'despachador') return { name: 'dashboard' }
  if (to.meta.requiresProduccionWorker && !auth.tieneAccesoPasos) return { name: 'dashboard' }
})

export default router
