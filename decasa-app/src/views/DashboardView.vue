<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import { useDespachoStore } from '@/stores/despacho'
import { useSurtidosStore } from '@/stores/surtidos'
import { usePasosStore } from '@/stores/pasos'
import { useConsultasStore } from '@/stores/consultas'
import { useNotificacionesStore } from '@/stores/notificaciones'
import { useModulosStore } from '@/stores/modulos'
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
  DocumentCheckIcon,
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
  PresentationChartBarIcon,
  ReceiptPercentIcon,
  BuildingStorefrontIcon,
  Cog6ToothIcon,
  ShoppingCartIcon,
  BriefcaseIcon,
  QueueListIcon,
} from '@heroicons/vue/24/outline'

const auth         = useAuthStore()
const router       = useRouter()
const despacho     = useDespachoStore()
const surtidos     = useSurtidosStore()
const pasos        = usePasosStore()
const consultas    = useConsultasStore()
const notif        = useNotificacionesStore()
// Los nombres y los iconos los pone la empresa; lo escrito aquí es el repuesto.
const modulos      = useModulosStore()

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
  if (auth.puedeEncargos) return [{ modulo: 'encargos', label: 'Encargos', icon: BriefcaseIcon, to: { name: 'encargos' } }]
  if (auth.llevaEncargos) {
    return [{ modulo: 'mis-encargos', label: 'Mis encargos', icon: BriefcaseIcon, to: { name: 'encargo-trabajador', params: { id: auth.usuario.id } } }]
  }
  return []
})

// Los módulos que la empresa creó a partir de Telas (Espumas, Hilos...) van
// al lado de Telas, con su mismo permiso. El nombre y el icono de cada uno
// los pone `soloVisibles`, que los conoce por la clave.
const accesosCopiasDeTelas = computed(() => modulos.instancias('telas').map(m => ({
  modulo: m.clave, label: m.nombre, icon: SwatchIcon, to: { name: 'modulo', params: { clave: m.clave } },
})))

// El Inicio sale de los permisos de cada persona, no de su rol
// (docs/metodologia-de-trabajo-por-rol.md, fase 4). Antes había una lista a
// mano para conductor, otra para "ebanista", otra para despachador y una
// general, y un conductor que vendía o un vendedor con Nómina no veían su
// acceso aunque tuvieran el permiso. Ahora es un solo catálogo, en el orden en
// que salen, y cada acceso dice cuándo se ve. Lo único que sigue mirando el
// rango es lo de administrar (Trabajadores, Reportes, Gestión: supervisor) y
// dos nombres que el supervisor ve distinto (Reserva / Fábrica, Surtir).
// Cada `ve` es la misma regla del guard de la ruta (router/index.js).
const sup = () => auth.isSupervisor
const CATALOGO = () => [
  { modulo: 'nueva-orden',  label: 'Nueva orden',  icon: PlusIcon,                  to: { name: 'nueva-orden' },  ve: () => auth.puedeNuevaOrden },
  { modulo: 'ordenes',      label: 'Órdenes',      icon: ClipboardDocumentListIcon, to: { name: 'ordenes' },      ve: () => auth.puedeOrdenes },
  { modulo: 'clientes',     label: 'Clientes',     icon: UserGroupIcon,             to: { name: 'clientes' },     ve: () => auth.puedeClientes },
  // Las entregas de la ruta son de quien maneja (la ruta se le asigna a un conductor).
  { modulo: 'mis-entregas', label: 'Mis entregas', icon: TruckIcon,                 to: { name: 'mis-entregas' }, ve: () => auth.usuario?.rol === 'conductor', badge: despacho.misEntregasPendientes },
  // Ver el inventario es de todos (usuario, 2026-11-19).
  { modulo: 'inventario',   label: 'Inventario',   icon: ArchiveBoxIcon,            to: { name: 'inventario' },   ve: () => true, badge: surtidos.pendientesCount },
  { modulo: 'telas',        label: 'Telas',        icon: SwatchIcon,                to: { name: 'telas' },        ve: () => auth.puedeRecargarTelas || auth.puedeUsarTelas },
  ...accesosCopiasDeTelas.value.map(a => ({ ...a, ve: () => auth.puedeRecargarTelas || auth.puedeUsarTelas })),
  { modulo: 'fabrica',      label: 'Fábrica',      icon: BuildingOffice2Icon,       to: { name: 'reserva' },      ve: () => !sup() && auth.puedeReserva },
  { modulo: 'traslado',     label: 'Traslado',     icon: ArrowPathIcon,             to: { name: 'surtir', query: { tab: 'traslado' } }, ve: () => !sup() && auth.puedeTrasladar },
  // Surtir desde la fábrica, aparte de Traslado (fase 7): a quien no es
  // supervisor también, si se le da (el de la fábrica).
  { modulo: 'surtir',       label: 'Surtir',       icon: ArchiveBoxArrowDownIcon,   to: { name: 'surtir', query: { tab: 'nuevo' } }, ve: () => !sup() && auth.puedeSurtir },
  { modulo: 'redes',        label: 'Redes',        icon: ChatBubbleLeftRightIcon,   to: { name: 'redes' },        ve: () => auth.tieneAccesoRedes },
  { modulo: 'costos',       label: 'Costos',       icon: CalculatorIcon,            to: { name: 'costos' },       ve: () => !sup() && auth.puedeCostos },
  { modulo: 'mis-pasos',    label: 'Mis pasos',    icon: ClipboardDocumentCheckIcon, to: { name: 'mis-pasos' },   ve: () => auth.tieneAccesoPasos, badge: pasos.pendientesCount },
  { modulo: 'citas',        label: 'Citas',        icon: CalendarDaysIcon,          to: { name: 'citas' },        ve: () => true },
  { modulo: 'caja',         label: 'Caja',         icon: BanknotesIcon,             to: { name: 'caja' },         ve: () => auth.puedeCaja },
  // El supervisor los tiene en este orden desde antes (y Surtir y Traslado
  // por separado: Surtir aterriza en "Nuevo surtido").
  { modulo: 'reserva',      label: 'Reserva',      icon: CubeIcon,                  to: { name: 'reserva' },      ve: () => sup() && auth.puedeReserva },
  { modulo: 'surtir',       label: 'Surtir',       icon: ArchiveBoxArrowDownIcon,   to: { name: 'surtir' },       ve: () => sup() && auth.puedeSurtir },
  { modulo: 'traslado',     label: 'Traslado',     icon: ArrowPathIcon,             to: { name: 'surtir', query: { tab: 'traslado' } }, ve: () => sup() && auth.puedeTrasladar },
  { modulo: 'costos',       label: 'Costos',       icon: CalculatorIcon,            to: { name: 'costos' },       ve: () => sup() && auth.puedeCostos },
  { modulo: 'produccion',   label: 'Producción',   icon: WrenchScrewdriverIcon,     to: { name: 'produccion' },   ve: () => auth.puedeProduccion },
  // Sin clave de módulo a propósito: si apagaran Producción, el supervisor
  // igual necesita la cuenta.
  { label: 'Carga de trabajo',                     icon: QueueListIcon,             to: { name: 'carga-trabajo' }, ve: () => sup() || auth.puedeProduccion },
  { modulo: 'cotizaciones', label: 'Cotizaciones', icon: DocumentTextIcon,          to: { name: 'cotizaciones' }, ve: () => auth.puedeCotizaciones },
  // Facturación electrónica: justo después de Cotizaciones, con el aviso de
  // abonos que tenía la "Facturación" vieja (quitada el 2026-11-14).
  { label: 'Fact. electrónica',                    icon: DocumentCheckIcon,         to: { name: 'facturacion-electronica' }, ve: () => auth.puedeFacturarElectronica, badge: auth.isFacturador ? abonosNoLeidos.value : 0 },
  // Pide quien tiene el módulo; responde quien tiene Costos.
  { modulo: 'consultas',    label: 'Consultar costo', icon: CurrencyDollarIcon,     to: { name: 'consultas' },    ve: () => auth.puedeConsultarCosto || auth.puedeCostos, badge: consultas.pendientesCount },
  { modulo: 'mis-stats',    label: 'Estadísticas', icon: PresentationChartLineIcon, to: { name: 'mis-stats-conductor' }, ve: () => auth.usuario?.rol === 'conductor' },
  { modulo: 'mis-stats',    label: sup() ? 'Mis estadísticas' : 'Estadísticas', icon: PresentationChartLineIcon, to: { name: 'mis-stats' }, ve: () => auth.usuario?.rol !== 'conductor' && auth.puedeEstadisticas },
  { modulo: 'proveedores',  label: 'Proveedores',  icon: BuildingStorefrontIcon,    to: { name: 'proveedores' },  ve: () => true },
  { modulo: 'compras',      label: 'Compras',      icon: ShoppingCartIcon,          to: { name: 'compras' },      ve: () => auth.puedeCompras },
  ...accesoEncargos.value.map(a => ({ ...a, ve: () => true })),
]

const sinRegla = ({ ve, ...a }) => a
const accesos = computed(() => modulos.soloVisibles(CATALOGO().filter(a => a.ve()).map(sinRegla)))

// Lo de administrar y lo delicado. Trabajadores, Reportes y Gestión son del
// supervisor; lo demás, de quien tenga el permiso (antes solo le salía a un
// supervisor, aunque un vendedor tuviera Nómina o Despacho).
const accesosAdmin = computed(() => modulos.soloVisibles([
  { modulo: 'usuarios',       label: 'Trabajadores',   icon: UsersIcon,                to: { name: 'usuarios' },       ve: sup },
  { modulo: 'reportes',       label: 'Reportes',       icon: ChartBarIcon,             to: { name: 'reportes' },       ve: sup },
  // Gestión no se puede apagar: es de donde se encienden los demás.
  { label: 'Gestión',                                  icon: Cog6ToothIcon,            to: { name: 'gestion' },        ve: sup },
  { modulo: 'despacho',       label: 'Despacho',       icon: TruckIcon,                to: { name: 'despacho' },       ve: () => auth.puedeDespacho, badge: despacho.ordenesPendientes },
  // Métricas no es un módulo aparte: va junto con Redes.
  { modulo: 'metricas-redes', label: 'Métricas',       icon: ChartPieIcon,             to: { name: 'metricas-redes' }, ve: () => auth.tieneAccesoRedes && sup() },
  { modulo: 'comisiones',     label: 'Bonificaciones', icon: ReceiptPercentIcon,       to: { name: 'comisiones' },     ve: () => auth.tieneAccesoComisiones },
  { modulo: 'nomina',         label: 'Nómina',         icon: BanknotesIcon,            to: { name: 'nomina' },         ve: () => auth.puedeNomina },
  { modulo: 'finanzas',       label: 'Finanzas',       icon: PresentationChartBarIcon, to: { name: 'finanzas' },       ve: () => auth.puedeFinanzas },
].filter(a => a.ve()).map(sinRegla)))
</script>

<template>
  <div class="p-4 space-y-4">
    <div
      class="bg-blue-600 text-white rounded-2xl p-5 relative cursor-pointer active:brightness-90 transition-all"
      @click="router.push({ name: 'perfil' })"
    >
      <p class="text-sm opacity-80">Bienvenido</p>
      <p class="text-xl font-bold pr-10">{{ auth.usuario?.nombre }}</p>
      <!-- Cómo se llama su puesto en esta empresa, no la clave con la que el
           código lo reconoce: si el rol se renombró en Gestión, aquí se lee el
           nombre nuevo. El capitalize sólo aplica al repuesto, que viene en
           minúscula; un nombre puesto a mano se respeta tal como se escribió. -->
      <p :class="['text-xs opacity-70 mt-1', auth.usuario?.rol_nombre ? '' : 'capitalize']">
        {{ auth.usuario?.rol_nombre ?? auth.usuario?.rol }}
      </p>
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

      <template v-if="accesosAdmin.length">
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
        v-if="modulos.visible('herramientas')"
        @click="mostrarHerramientas = true"
        class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
      >
        <component :is="modulos.icono('herramientas', WrenchScrewdriverIcon)" class="w-8 h-8" />
        {{ modulos.nombre('herramientas', 'Herramientas') }}
      </button>
    </div>

    <RedesHerramientas v-if="mostrarHerramientas" @close="mostrarHerramientas = false" />
  </div>
</template>
