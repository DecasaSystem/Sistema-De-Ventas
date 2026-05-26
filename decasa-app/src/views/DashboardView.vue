<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import {
  PlusIcon,
  ClipboardDocumentListIcon,
  UserGroupIcon,
  UsersIcon,
  UserPlusIcon,
  ArchiveBoxIcon,
  ArchiveBoxArrowDownIcon,
  WrenchScrewdriverIcon,
  ChartBarIcon,
  PresentationChartLineIcon,
  TruckIcon,
  DocumentCurrencyDollarIcon,
  CalculatorIcon,
} from '@heroicons/vue/24/outline'

const auth   = useAuthStore()
const router = useRouter()

const accesos = computed(() => {
  if (auth.usuario?.rol === 'conductor') {
    return [
      { label: 'Mis entregas', icon: TruckIcon, to: { name: 'mis-entregas' } },
      { label: 'Estadísticas', icon: PresentationChartLineIcon, to: { name: 'mis-stats-conductor' } },
    ]
  }
  if (auth.usuario?.rol === 'ebanista') {
    return [
      { label: 'Mis pasos', icon: WrenchScrewdriverIcon, to: { name: 'mis-pasos' } },
    ]
  }
  if (auth.usuario?.rol === 'despachador') {
    return [
      { label: 'Despacho producción', icon: TruckIcon, to: { name: 'despacho-produccion' } },
    ]
  }

  const items = [
    { label: 'Nueva orden',  icon: PlusIcon, to: { name: 'nueva-orden' } },
    { label: 'Órdenes',      icon: ClipboardDocumentListIcon, to: { name: 'ordenes' } },
    { label: 'Clientes',     icon: UserGroupIcon, to: { name: 'clientes' } },
    { label: 'Inventario',   icon: ArchiveBoxIcon, to: { name: 'inventario' } },
  ]

  if (auth.isSupervisor) {
    items.push({ label: 'Producción', icon: WrenchScrewdriverIcon, to: { name: 'produccion' } })
    if (auth.isTapicero) {
      items.push({ label: 'Mis pasos', icon: WrenchScrewdriverIcon,     to: { name: 'mis-pasos' } })
      items.push({ label: 'Surtir',    icon: ArchiveBoxArrowDownIcon,   to: { name: 'surtir'    } })
    }
  }

  items.push({ label: auth.isSupervisor ? 'Mis estadísticas' : 'Estadísticas', icon: PresentationChartLineIcon, to: { name: 'mis-stats' } })

  if (auth.isFacturador) {
    items.unshift({ label: 'Facturación', icon: DocumentCurrencyDollarIcon, to: { name: 'facturacion' } })
  }

  return items
})

const accesosAdmin = computed(() => {
  if (!auth.isSupervisor) return []
  return [
    { label: 'Despacho',        icon: TruckIcon,    to: { name: 'despacho'      } },
    { label: 'Trabajadores',    icon: UsersIcon,    to: { name: 'usuarios'      } },
    { label: 'Nuevo trabajador',icon: UserPlusIcon, to: { name: 'usuario-crear' } },
    { label: 'Reportes',        icon: ChartBarIcon, to: { name: 'reportes'      } },
    { label: 'Costos',          icon: CalculatorIcon, to: { name: 'costos' } },
  ]
})
</script>

<template>
  <div class="p-4 space-y-4">
    <div class="bg-blue-600 text-white rounded-2xl p-5">
      <p class="text-sm opacity-80">Bienvenido</p>
      <p class="text-xl font-bold">{{ auth.usuario?.nombre }}</p>
      <p class="text-xs opacity-70 mt-1 capitalize">{{ auth.usuario?.rol }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3">
      <button
        v-for="a in accesos"
        :key="a.label"
        @click="router.push(a.to)"
        class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
      >
        <component :is="a.icon" class="w-8 h-8" />
        {{ a.label }}
      </button>

      <template v-if="auth.isSupervisor">
        <button
          v-for="a in accesosAdmin"
          :key="a.label"
          @click="router.push(a.to)"
          class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
        >
          <component :is="a.icon" class="w-8 h-8" />
          {{ a.label }}
        </button>
      </template>
    </div>
  </div>
</template>
