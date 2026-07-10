<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import { useDespachoStore } from '@/stores/despacho'
import { useSurtidosStore } from '@/stores/surtidos'
import { usePasosStore } from '@/stores/pasos'
import { useConsultasStore } from '@/stores/consultas'
import { useDespachoProduccionStore } from '@/stores/despachoProduccion'
import { useNotificacionesStore } from '@/stores/notificaciones'
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
  PresentationChartLineIcon,
  TruckIcon,
  DocumentCurrencyDollarIcon,
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
} from '@heroicons/vue/24/outline'

const auth         = useAuthStore()
const router       = useRouter()
const despacho     = useDespachoStore()
const surtidos     = useSurtidosStore()
const pasos        = usePasosStore()
const consultas    = useConsultasStore()
const despachoProd = useDespachoProduccionStore()
const notif        = useNotificacionesStore()

const abonosNoLeidos = computed(() =>
  notif.items.filter(n => !n.leida && n.tipo === 'abono_registrado').length
)

const accesos = computed(() => {
  if (auth.usuario?.rol === 'conductor') {
    return [
      { label: 'Mis entregas', icon: TruckIcon,                  to: { name: 'mis-entregas' },        badge: despacho.misEntregasPendientes },
      { label: 'Estadísticas', icon: PresentationChartLineIcon,  to: { name: 'mis-stats-conductor' } },
    ]
  }
  if (auth.usuario?.rol === 'ebanista') {
    return [
      { label: 'Nueva orden',  icon: PlusIcon,                   to: { name: 'nueva-orden' } },
      { label: 'Órdenes',      icon: ClipboardDocumentListIcon,  to: { name: 'ordenes'     } },
      { label: 'Clientes',     icon: UserGroupIcon,              to: { name: 'clientes'    } },
      { label: 'Mis pasos',    icon: ClipboardDocumentCheckIcon, to: { name: 'mis-pasos'   }, badge: pasos.pendientesCount },
      { label: 'Cotizaciones', icon: CurrencyDollarIcon,         to: { name: 'consultas'   }, badge: consultas.pendientesCount },
      { label: 'Costos',       icon: CalculatorIcon,             to: { name: 'costos'      } },
      { label: 'Telas',        icon: SwatchIcon,                 to: { name: 'telas'       } },
      { label: 'Caja',         icon: BanknotesIcon,              to: { name: 'caja'        } },
      { label: 'Estadísticas', icon: PresentationChartLineIcon,  to: { name: 'mis-stats'   } },
    ]
  }
  if (auth.usuario?.rol === 'despachador') {
    return [
      { label: 'Despacho producción', icon: TruckIcon, to: { name: 'despacho-produccion' }, badge: despachoProd.pendientesCount },
    ]
  }

  const items = [
    { label: 'Nueva orden',  icon: PlusIcon,                  to: { name: 'nueva-orden' } },
    { label: 'Órdenes',      icon: ClipboardDocumentListIcon, to: { name: 'ordenes'     } },
    { label: 'Clientes',     icon: UserGroupIcon,             to: { name: 'clientes'    } },
    { label: 'Inventario',   icon: ArchiveBoxIcon,            to: { name: 'inventario'  }, badge: surtidos.pendientesCount },
    ...((auth.puedeRecargarTelas || auth.isCosturero || auth.usuario?.rol === 'vendedor') ? [{ label: 'Telas', icon: SwatchIcon, to: { name: 'telas' } }] : []),
    ...(!auth.isSupervisor ? [{ label: 'Fábrica',  icon: BuildingOffice2Icon, to: { name: 'reserva' } }] : []),
    ...(!auth.isSupervisor ? [{ label: 'Traslado', icon: ArrowPathIcon,       to: { name: 'surtir'  } }] : []),
    ...(auth.tieneAccesoRedes ? [{ label: 'Redes', icon: ChatBubbleLeftRightIcon, to: { name: 'redes' } }] : []),
    { label: 'Citas', icon: CalendarDaysIcon, to: { name: 'citas' } },
    { label: 'Caja',  icon: BanknotesIcon,    to: { name: 'caja'  } },
  ]

  if (auth.isSupervisor) {
    items.push({ label: 'Reserva',     icon: CubeIcon,                    to: { name: 'reserva'    } })
    items.push({ label: 'Producción',  icon: WrenchScrewdriverIcon,       to: { name: 'produccion' } })
    if (auth.isTapicero) {
      items.push({ label: 'Mis pasos', icon: ClipboardDocumentCheckIcon,  to: { name: 'mis-pasos'  }, badge: pasos.pendientesCount })
      items.push({ label: 'Surtir',    icon: ArchiveBoxArrowDownIcon,     to: { name: 'surtir'     } })
    }
  }

  items.push({ label: 'Cotizaciones', icon: CurrencyDollarIcon,         to: { name: 'consultas'  }, badge: consultas.pendientesCount })
  items.push({ label: auth.isSupervisor ? 'Mis estadísticas' : 'Estadísticas', icon: PresentationChartLineIcon, to: { name: 'mis-stats' } })

  if (auth.isFacturador) {
    items.unshift({ label: 'Facturación', icon: DocumentCurrencyDollarIcon, to: { name: 'facturacion' }, badge: abonosNoLeidos.value })
  }

  return items
})

const accesosAdmin = computed(() => {
  if (!auth.isSupervisor) return []
  const items = [
    { label: 'Despacho',     icon: TruckIcon,             to: { name: 'despacho'   }, badge: despacho.ordenesPendientes },
    { label: 'Trabajadores', icon: UsersIcon,              to: { name: 'usuarios'   } },
    { label: 'Reportes',     icon: ChartBarIcon,           to: { name: 'reportes'   } },
    { label: 'Costos',       icon: CalculatorIcon,         to: { name: 'costos'     } },
  ]
  if (auth.tieneAccesoComisiones) {
    items.push({ label: 'Comisiones', icon: ReceiptPercentIcon, to: { name: 'comisiones' } })
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
    </div>
  </div>
</template>
