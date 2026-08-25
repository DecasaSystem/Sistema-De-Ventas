<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import { useDespachoStore } from '@/stores/despacho'
import { useSurtidosStore } from '@/stores/surtidos'
import { usePasosStore } from '@/stores/pasos'
import { useConsultasStore } from '@/stores/consultas'
import { useNotificacionesStore } from '@/stores/notificaciones'
import RedesHerramientas from '@/components/RedesHerramientas.vue'
import {
  PlusIcon,
  ClipboardDocumentListIcon,
  UserGroupIcon,
  UsersIcon,
  ArchiveBoxIcon,
  ArchiveBoxArrowDownIcon,
  WrenchScrewdriverIcon,
  ClipboardDocumentCheckIcon,
  ChartBarIcon,
  ChartPieIcon,
  PresentationChartLineIcon,
  TruckIcon,
  DocumentCurrencyDollarIcon,
  DocumentTextIcon,
  CalculatorIcon,
  ChatBubbleLeftRightIcon,
  CalendarDaysIcon,
  CurrencyDollarIcon,
  CubeIcon,
  ArrowPathIcon,
  UserCircleIcon,
  BuildingOffice2Icon,
  SwatchIcon,
  BanknotesIcon,
  ReceiptPercentIcon,
  BuildingStorefrontIcon,
  Cog6ToothIcon,
  ShoppingCartIcon,
  BriefcaseIcon,
} from '@heroicons/vue/24/outline'

const auth         = useAuthStore()
const router       = useRouter()
const despacho     = useDespachoStore()
const surtidos     = useSurtidosStore()
const pasos        = usePasosStore()
const consultas    = useConsultasStore()
const notif        = useNotificacionesStore()

// Panel de herramientas (direcciones, horarios, catálogos…). Disponible para TODOS
// los usuarios desde el inicio, no solo los del módulo Redes.
const mostrarHerramientas = ref(false)

const abonosNoLeidos = computed(() =>
  notif.items.filter(n => !n.leida && n.tipo === 'abono_registrado').length
)

// Encargos entra por dos puertas distintas: quien administra el módulo llega a
// la lista de todo el mundo, y quien solo responde por sus herramientas llega
// directo a su propia ficha. Se arma acá porque el acceso aparece igual en el
// Home de todos los cargos.
const accesoEncargos = computed(() => {
  if (auth.puedeEncargos) return [{ label: 'Encargos', icon: BriefcaseIcon, to: { name: 'encargos' } }]
  if (auth.llevaEncargos) {
    return [{ label: 'Mis encargos', icon: BriefcaseIcon, to: { name: 'encargo-trabajador', params: { id: auth.usuario.id } } }]
  }
  return []
})

const accesos = computed(() => {
  if (auth.usuario?.rol === 'conductor') {
    return [
      { label: 'Mis entregas', icon: TruckIcon,                  to: { name: 'mis-entregas' },        badge: despacho.misEntregasPendientes },
      { label: 'Estadísticas', icon: PresentationChartLineIcon,  to: { name: 'mis-stats-conductor' } },
      ...(auth.puedeSurtir ? [{ label: 'Surtir', icon: ArrowPathIcon, to: { name: 'surtir' } }] : []),
      { label: 'Proveedores',  icon: BuildingStorefrontIcon,     to: { name: 'proveedores' } },
      ...(auth.puedeCompras ? [{ label: 'Compras', icon: ShoppingCartIcon, to: { name: 'compras' } }] : []),
      ...accesoEncargos.value,
      // Un conductor puede tener pasos del taller asignados a dedo: sin esto
      // el acceso no le aparecía aunque el permiso ya lo dejara entrar.
      ...(auth.tieneAccesoPasos ? [{ label: 'Mis pasos', icon: ClipboardDocumentCheckIcon, to: { name: 'mis-pasos' }, badge: pasos.pendientesCount }] : []),
    ]
  }
  if (auth.usuario?.rol === 'ebanista') {
    return [
      { label: 'Nueva orden',  icon: PlusIcon,                   to: { name: 'nueva-orden' } },
      { label: 'Órdenes',      icon: ClipboardDocumentListIcon,  to: { name: 'ordenes'     } },
      { label: 'Clientes',     icon: UserGroupIcon,              to: { name: 'clientes'    } },
      { label: 'Mis pasos',    icon: ClipboardDocumentCheckIcon, to: { name: 'mis-pasos'   }, badge: pasos.pendientesCount },
      { label: 'Consultar costo', icon: CurrencyDollarIcon,      to: { name: 'consultas'   }, badge: consultas.pendientesCount },
      { label: 'Costos',       icon: CalculatorIcon,             to: { name: 'costos'      } },
      { label: 'Telas',        icon: SwatchIcon,                 to: { name: 'telas'       } },
      ...(auth.puedeSurtir ? [{ label: 'Surtir', icon: ArrowPathIcon, to: { name: 'surtir' } }] : []),
      { label: 'Caja',         icon: BanknotesIcon,              to: { name: 'caja'        } },
      { label: 'Estadísticas', icon: PresentationChartLineIcon,  to: { name: 'mis-stats'   } },
      { label: 'Proveedores',  icon: BuildingStorefrontIcon,     to: { name: 'proveedores' } },
      ...(auth.puedeCompras ? [{ label: 'Compras', icon: ShoppingCartIcon, to: { name: 'compras' } }] : []),
      ...accesoEncargos.value,
    ]
  }
  if (auth.usuario?.rol === 'despachador') {
    return [
      ...(auth.puedeSurtir ? [{ label: 'Surtir', icon: ArrowPathIcon, to: { name: 'surtir' } }] : []),
      { label: 'Proveedores',         icon: BuildingStorefrontIcon, to: { name: 'proveedores' } },
      ...(auth.puedeCompras ? [{ label: 'Compras', icon: ShoppingCartIcon, to: { name: 'compras' } }] : []),
      ...accesoEncargos.value,
      // Igual que el conductor: el despachador tiene su propia pantalla de
      // despacho, pero puede además trabajar pasos si se le asignan.
      ...(auth.tieneAccesoPasos ? [{ label: 'Mis pasos', icon: ClipboardDocumentCheckIcon, to: { name: 'mis-pasos' }, badge: pasos.pendientesCount }] : []),
    ]
  }

  const items = [
    { label: 'Nueva orden',  icon: PlusIcon,                  to: { name: 'nueva-orden' } },
    { label: 'Órdenes',      icon: ClipboardDocumentListIcon, to: { name: 'ordenes'     } },
    { label: 'Clientes',     icon: UserGroupIcon,             to: { name: 'clientes'    } },
    { label: 'Inventario',   icon: ArchiveBoxIcon,            to: { name: 'inventario'  }, badge: surtidos.pendientesCount },
    ...((auth.puedeRecargarTelas || auth.puedeUsarTelas) ? [{ label: 'Telas', icon: SwatchIcon, to: { name: 'telas' } }] : []),
    // Antes era automática para todo el que no fuera supervisor. Ahora es un
    // permiso activable, igual que Surtir: los vendedores actuales la
    // conservan por el respaldo de la migración.
    ...(!auth.isSupervisor && auth.puedeReserva ? [{ label: 'Fábrica',  icon: BuildingOffice2Icon, to: { name: 'reserva' } }] : []),
    // Antes era "cualquiera que no sea supervisor". Ahora depende del
    // permiso: un vendedor lo trae encendido de por defecto, y a partir de
    // ahí es lo mismo que se le asigne a cualquier otro rol.
    ...(!auth.isSupervisor && auth.puedeSurtir ? [{ label: 'Traslado', icon: ArrowPathIcon, to: { name: 'surtir'  } }] : []),
    ...(auth.tieneAccesoRedes ? [{ label: 'Redes', icon: ChatBubbleLeftRightIcon, to: { name: 'redes' } }] : []),
    ...(!auth.isSupervisor && auth.puedeCostos ? [{ label: 'Costos', icon: CalculatorIcon, to: { name: 'costos' } }] : []),
    // Cualquiera con un perfil de producción asignado —sin importar su rol—
    // puede trabajar sus pasos, no solo ebanista/tapicero/despachador.
    // Lleva pasos del taller: ya no se mira el rol ni un "perfil", sino si
    // de verdad tiene pasos asignados.
    ...(auth.tieneAccesoPasos ? [{ label: 'Mis pasos', icon: ClipboardDocumentCheckIcon, to: { name: 'mis-pasos' }, badge: pasos.pendientesCount }] : []),
    { label: 'Citas', icon: CalendarDaysIcon, to: { name: 'citas' } },
    { label: 'Caja',  icon: BanknotesIcon,    to: { name: 'caja'  } },
  ]

  if (auth.isSupervisor) {
    // Costos/Despacho/Producción/Reserva/Surtir ya no son automáticos por
    // ser supervisor: cada uno necesita su permiso prendido
    // (todo supervisor existente lo trae encendido por el respaldo de la
    // migración; de ahí en adelante es un control fino real).
    if (auth.puedeReserva)    items.push({ label: 'Reserva',     icon: CubeIcon,                    to: { name: 'reserva'    } })

    if (auth.puedeSurtir) {
      items.push({ label: 'Surtir',   icon: ArchiveBoxArrowDownIcon, to: { name: 'surtir' } })
      // Antes solo estaba "Surtir", que aterriza en "Nuevo surtido": un
      // supervisor no tenía cómo llegar directo a Traslado desde el Home,
      // solo buscándolo dentro de esa pantalla.
      items.push({ label: 'Traslado', icon: ArrowPathIcon,           to: { name: 'surtir', query: { tab: 'traslado' } } })
    }
    if (auth.puedeCostos)     items.push({ label: 'Costos',      icon: CalculatorIcon,               to: { name: 'costos'     } })
  }

  // Producción va por permiso, no por cargo: un operario al que se le active
  // entra a mirar en qué va el taller.
  if (auth.puedeProduccion) items.push({ label: 'Producción', icon: WrenchScrewdriverIcon, to: { name: 'produccion' } })
  items.push({ label: 'Cotizaciones', icon: DocumentTextIcon,           to: { name: 'cotizaciones' } })
  items.push({ label: 'Consultar costo', icon: CurrencyDollarIcon,      to: { name: 'consultas'  }, badge: consultas.pendientesCount })
  items.push({ label: auth.isSupervisor ? 'Mis estadísticas' : 'Estadísticas', icon: PresentationChartLineIcon, to: { name: 'mis-stats' } })
  items.push({ label: 'Proveedores', icon: BuildingStorefrontIcon, to: { name: 'proveedores' } })
  if (auth.puedeCompras) items.push({ label: 'Compras', icon: ShoppingCartIcon, to: { name: 'compras' } })
  items.push(...accesoEncargos.value)

  if (auth.isFacturador) {
    items.unshift({ label: 'Facturación', icon: DocumentCurrencyDollarIcon, to: { name: 'facturacion' }, badge: abonosNoLeidos.value })
  }

  return items
})

const accesosAdmin = computed(() => {
  if (!auth.isSupervisor) return []
  const items = [
    { label: 'Trabajadores', icon: UsersIcon,              to: { name: 'usuarios'   } },
    { label: 'Reportes',     icon: ChartBarIcon,           to: { name: 'reportes'   } },
    { label: 'Gestión',      icon: Cog6ToothIcon,          to: { name: 'gestion'    } },
  ]
  if (auth.puedeDespacho) {
    items.push({ label: 'Despacho', icon: TruckIcon, to: { name: 'despacho' }, badge: despacho.ordenesPendientes })
  }
  // Métricas no es un módulo aparte: va junto con Redes, no con un permiso propio.
  if (auth.tieneAccesoRedes) {
    items.push({ label: 'Métricas', icon: ChartPieIcon, to: { name: 'metricas-redes' } })
  }
  if (auth.tieneAccesoComisiones) {
    items.push({ label: 'Comisiones', icon: ReceiptPercentIcon, to: { name: 'comisiones' } })
  }
  if (auth.puedeNomina) {
    items.push({ label: 'Nómina', icon: BanknotesIcon, to: { name: 'nomina' } })
  }
  return items
})
</script>

<template>
  <div class="p-4 space-y-4">
    <div
      class="bg-blue-600 text-white rounded-2xl p-5 relative cursor-pointer active:brightness-90 transition-all"
      @click="router.push({ name: 'perfil' })"
    >
      <p class="text-sm opacity-80">Bienvenido</p>
      <p class="text-xl font-bold pr-10">{{ auth.usuario?.nombre }}</p>
      <p class="text-xs opacity-70 mt-1 capitalize">{{ auth.usuario?.rol }}</p>
      <UserCircleIcon class="w-8 h-8 absolute top-4 right-4 opacity-80" />
    </div>

    <div class="grid grid-cols-2 gap-3">
      <button
        v-for="a in accesos"
        :key="a.label"
        @click="router.push(a.to)"
        class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
      >
        <div class="relative">
          <component :is="a.icon" class="w-8 h-8" />
          <span
            v-if="a.badge > 0"
            class="absolute -top-2 -right-2 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1"
          >
            {{ a.badge > 9 ? '9+' : a.badge }}
          </span>
        </div>
        {{ a.label }}
      </button>

      <template v-if="auth.isSupervisor">
        <button
          v-for="a in accesosAdmin"
          :key="a.label"
          @click="router.push(a.to)"
          class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
        >
          <div class="relative">
            <component :is="a.icon" class="w-8 h-8" />
            <span
              v-if="a.badge > 0"
              class="absolute -top-2 -right-2 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1"
            >
              {{ a.badge > 9 ? '9+' : a.badge }}
            </span>
          </div>
          {{ a.label }}
        </button>
      </template>

      <!-- Herramientas: disponible para TODOS los usuarios -->
      <button
        @click="mostrarHerramientas = true"
        class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
      >
        <WrenchScrewdriverIcon class="w-8 h-8" />
        Herramientas
      </button>
    </div>

    <RedesHerramientas v-if="mostrarHerramientas" @close="mostrarHerramientas = false" />
  </div>
</template>
