<script setup>
// Finanzas: lo que entra y lo que sale de la empresa (docs/plan-gestion-financiera.md).
// Cada pestaña carga lo suyo al entrar; el mes de arriba manda en todas.
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronLeftIcon, ChevronRightIcon, Cog6ToothIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { mesActual, sumarMeses, nombreMes } from '@/utils/finanzas'
import ResumenFinanzas from '@/components/finanzas/ResumenFinanzas.vue'
import GastosFinanzas from '@/components/finanzas/GastosFinanzas.vue'
import ResultadosFinanzas from '@/components/finanzas/ResultadosFinanzas.vue'
import FlujoFinanzas from '@/components/finanzas/FlujoFinanzas.vue'
import ProyeccionFinanzas from '@/components/finanzas/ProyeccionFinanzas.vue'
import TiendasFinanzas from '@/components/finanzas/TiendasFinanzas.vue'
import GastoForm from '@/components/finanzas/GastoForm.vue'
import AjustesFinanzas from '@/components/finanzas/AjustesFinanzas.vue'

const route = useRoute()
const router = useRouter()

const PESTANAS = [
  { id: 'resumen',    label: 'Resumen' },
  { id: 'gastos',     label: 'Gastos' },
  { id: 'resultados', label: 'Resultados' },
  { id: 'flujo',      label: 'Flujo de caja' },
  { id: 'proyeccion', label: 'Proyección' },
  { id: 'tiendas',    label: 'Tiendas y canales' },
]

const pestana = ref(PESTANAS.some(p => p.id === route.query.pestana) ? route.query.pestana : 'resumen')
const mes = ref(mesActual())
const esMesActual = computed(() => mes.value === mesActual())
// Cambia cuando se registra algo: las pestañas vuelven a pedir sus datos.
const version = ref(0)

const mostrarGasto = ref(false)
const obligacion = ref(null)   // si se abre el formulario para pagar un periodo de plantilla
const mostrarAjustes = ref(false)

watch(pestana, (p) => router.replace({ query: { ...route.query, pestana: p } }))

// La proyección y el flujo miran hacia adelante: el mes de arriba no aplica.
const usaMes = computed(() => ['resumen', 'gastos', 'tiendas'].includes(pestana.value))

function nuevoGasto(o = null) {
  obligacion.value = o
  mostrarGasto.value = true
}

function guardado() {
  mostrarGasto.value = false
  obligacion.value = null
  version.value++
}
</script>

<template>
  <div class="p-4 max-w-2xl lg:max-w-4xl mx-auto space-y-4 pb-24">
    <!-- "+ Gasto" va arriba y no flotando: abajo a la derecha ya está el
         asistente de IA, y un botón flotante tapaba las cifras de las tarjetas. -->
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-bold text-gray-800">Finanzas</h2>
      <div class="flex items-center gap-1">
        <button @click="nuevoGasto()" class="flex items-center gap-1 bg-blue-600 text-white text-xs font-semibold rounded-lg px-3 py-2 hover:bg-blue-700">
          <PlusIcon class="w-4 h-4" /> Gasto
        </button>
        <button @click="mostrarAjustes = true" class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100" aria-label="Ajustes de Finanzas">
          <Cog6ToothIcon class="w-5 h-5" />
        </button>
      </div>
    </div>

    <!-- Selector de mes -->
    <div v-if="usaMes" class="flex items-center justify-between bg-white rounded-xl shadow-sm px-2 py-1.5">
      <button @click="mes = sumarMeses(mes, -1)" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Mes anterior">
        <ChevronLeftIcon class="w-5 h-5" />
      </button>
      <div class="text-center">
        <p class="text-sm font-semibold text-gray-800">{{ nombreMes(mes) }}</p>
        <p v-if="esMesActual" class="text-[11px] text-gray-400">en curso · cifras parciales</p>
      </div>
      <button @click="mes = sumarMeses(mes, 1)" :disabled="esMesActual"
        class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-30" aria-label="Mes siguiente">
        <ChevronRightIcon class="w-5 h-5" />
      </button>
    </div>

    <!-- Pestañas en pastilla; en el celular se deslizan de lado. -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1 overflow-x-auto no-scrollbar" role="tablist">
      <button v-for="p in PESTANAS" :key="p.id" @click="pestana = p.id" role="tab" :aria-selected="pestana === p.id"
        :class="['shrink-0 text-xs font-semibold rounded-lg px-3 py-2 transition-colors whitespace-nowrap',
          pestana === p.id ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">
        {{ p.label }}
      </button>
    </div>

    <ResumenFinanzas v-if="pestana === 'resumen'" :mes="mes" :version="version"
      @ir="pestana = $event" @pagar="nuevoGasto" />
    <GastosFinanzas v-else-if="pestana === 'gastos'" :mes="mes" :version="version"
      @pagar="nuevoGasto" @cambio="version++" />
    <ResultadosFinanzas v-else-if="pestana === 'resultados'" :version="version" />
    <FlujoFinanzas v-else-if="pestana === 'flujo'" :version="version" @ajustes="mostrarAjustes = true" />
    <ProyeccionFinanzas v-else-if="pestana === 'proyeccion'" :version="version" />
    <TiendasFinanzas v-else-if="pestana === 'tiendas'" :mes="mes" :version="version" @ajustes="mostrarAjustes = true" />

    <GastoForm v-if="mostrarGasto" :obligacion="obligacion" @cerrar="mostrarGasto = false" @guardado="guardado" />
    <AjustesFinanzas v-if="mostrarAjustes" @cerrar="mostrarAjustes = false" @guardado="version++" />
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
