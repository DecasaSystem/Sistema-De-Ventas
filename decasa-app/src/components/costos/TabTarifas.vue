<script setup>
/**
 * Tarifas de mano de obra. Cada oficio trae sus trabajos adentro: el incentivo
 * por hora del oficio × las horas del trabajo = lo que cuesta ese trabajo en
 * las fichas. El salario es informativo: no entra en el costo.
 *
 * Los cambios de salarios, incentivos y horas se juntan y se guardan de una vez
 * con la barra de abajo; al guardar se recalculan las fichas vinculadas.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { PlusIcon, TrashIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { guardarCostos, guardarFactorVenta, crearCargo, eliminarCargo, crearProceso, eliminarProceso } from '@/api/configuracion'
import { useToast } from '@/composables/useToast'
import AppSpinner from '@/components/common/AppSpinner.vue'
import InputPesos from '@/components/common/InputPesos.vue'
import IconoS from '@/components/common/IconoS.vue'
import { useCostos } from './useCostos'

const emit = defineEmits(['fichas-cambiaron'])

const toast = useToast()
const {
  salarios, procesos, factorVenta, cargandoTarifas, tarifasCargadas, cargarTarifas,
  costoProceso, nombreCargo, nombreProceso, formatPeso,
} = useCostos()

const CARGOS_BASE = ['carpintero', 'tapicero', 'costurera', 'lacador']
const UNIDADES    = { pieza: 'pieza', puesto: 'puesto', m2: 'm²', ml: 'metro lineal', hora: 'hora' }

const hayCambios = ref(false)
const guardando  = ref(false)

onMounted(() => cargarTarifas(true))
// Lo que no se guardó se descarta: las tarifas son compartidas con las otras pestañas
onBeforeUnmount(() => { if (hayCambios.value) cargarTarifas(true) })
defineExpose({ hayCambios })

const sueldoDia  = s => Math.round((Number(s._salario) || 0) / (parseInt(s._dias) || 1))
const sueldoHora = s => Math.round(sueldoDia(s) / 8)

// Oficios con sus trabajos; los trabajos sin oficio conocido van aparte al final
const oficios = computed(() => salarios.value.map(s => ({
  salario:  s,
  trabajos: procesos.value.filter(p => p.cargo === s.cargo),
})))
const huerfanos = computed(() => procesos.value.filter(p => !salarios.value.some(s => s.cargo === p.cargo)))

async function guardar() {
  guardando.value = true
  try {
    await guardarCostos({
      salarios: salarios.value.map(s => ({
        cargo:              s.cargo,
        salario_mensual:    Number(s._salario) || 0,
        dias_laborales_mes: parseInt(s._dias) || 26,
        tarifa_hora:        Number(s._tarifaHora) || 0,
      })),
      procesos: procesos.value.map(p => ({ id: p.id, dias_por_unidad: (parseFloat(p._horas) || 0) / 8 })),
    })
    hayCambios.value = false
    await cargarTarifas(true)
    toast.success('Tarifas guardadas · fichas recalculadas')
    emit('fichas-cambiaron')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudieron guardar las tarifas.')
  } finally {
    guardando.value = false
  }
}

async function descartar() {
  if (!confirm('¿Descartar los cambios sin guardar?')) return
  hayCambios.value = false
  await cargarTarifas(true)
}

// ── Factor de venta ──────────────────────────────────────────────────────────
const factorEditado   = ref(null)
const guardandoFactor = ref(false)
const factor = computed({
  get: () => factorEditado.value ?? String(factorVenta.value),
  set: v  => { factorEditado.value = v },
})

async function guardarFactor() {
  const f = parseFloat(String(factor.value).replace(',', '.'))
  if (!(f >= 1 && f <= 10)) { toast.error('El factor debe estar entre 1 y 10.'); return }
  guardandoFactor.value = true
  try {
    const { data } = await guardarFactorVenta(f)
    factorVenta.value   = Number(data.factor_venta_sugerido)
    factorEditado.value = null
    toast.success('Factor de venta guardado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar el factor.')
  } finally {
    guardandoFactor.value = false
  }
}

// ── Oficio nuevo ─────────────────────────────────────────────────────────────
const formCargo = ref(null)
const guardandoCargo = ref(false)

async function crearOficio() {
  const f = formCargo.value
  if (!f.cargo.trim() || !f.salario_mensual) { toast.error('El oficio necesita nombre y salario.'); return }
  guardandoCargo.value = true
  try {
    await crearCargo({
      cargo:              f.cargo.trim(),
      descripcion:        f.descripcion.trim() || f.cargo.trim(),
      salario_mensual:    Number(f.salario_mensual) || 0,
      dias_laborales_mes: 26,
      tarifa_hora:        Number(f.tarifa_hora) || 0,
    })
    formCargo.value = null
    await cargarTarifas(true)
    toast.success('Oficio creado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo crear el oficio.')
  } finally {
    guardandoCargo.value = false
  }
}

async function borrarOficio(cargo) {
  if (!confirm(`¿Eliminar el oficio "${nombreCargo(cargo)}"?`)) return
  try {
    await eliminarCargo(cargo)
    await cargarTarifas(true)
    toast.success('Oficio eliminado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo eliminar el oficio.')
  }
}

// ── Trabajo nuevo ────────────────────────────────────────────────────────────
const formTrabajo = ref(null)   // { cargo, nombre, unidad, horas }
const guardandoTrabajo = ref(false)

async function crearTrabajo() {
  const f = formTrabajo.value
  if (!f.nombre.trim() || !(parseFloat(f.horas) > 0)) { toast.error('El trabajo necesita nombre y horas.'); return }
  guardandoTrabajo.value = true
  try {
    await crearProceso({ nombre: f.nombre.trim(), unidad: f.unidad, cargo: f.cargo, horas: parseFloat(f.horas) })
    formTrabajo.value = null
    await cargarTarifas(true)
    toast.success('Trabajo creado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo crear el trabajo.')
  } finally {
    guardandoTrabajo.value = false
  }
}

async function borrarTrabajo(p) {
  if (!confirm(`¿Eliminar el trabajo "${nombreProceso(p)}"?`)) return
  try {
    await eliminarProceso(p.id)
    await cargarTarifas(true)
    toast.success('Trabajo eliminado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo eliminar el trabajo.')
  }
}
</script>

<template>
  <AppSpinner v-if="cargandoTarifas && !tarifasCargadas" />

  <div v-else class="space-y-4">
    <!-- Factor de venta -->
    <section class="bg-white rounded-xl shadow-sm p-4">
      <h2 class="text-sm font-semibold text-gray-800">Precio de venta sugerido</h2>
      <p class="text-xs text-gray-500 mt-0.5">
        El cotizador sugiere vender a costo × este factor. También marca en ámbar los productos que se venden por debajo de él.
      </p>
      <div class="flex items-center gap-3 mt-3">
        <div class="flex items-center rounded-lg border border-gray-300 focus-within:ring-2 focus-within:ring-blue-500">
          <span class="pl-3 text-sm text-gray-400">×</span>
          <input v-model="factor" type="text" inputmode="decimal" aria-label="Factor de venta"
            class="w-16 px-2 py-2 text-sm rounded-lg focus:outline-none" />
        </div>
        <p class="flex-1 text-xs text-gray-500">
          Un mueble que cuesta {{ formatPeso(1000000) }} se sugiere a
          <strong class="text-gray-800">{{ formatPeso(1000000 * (parseFloat(String(factor).replace(',', '.')) || 0)) }}</strong>
        </p>
        <button v-if="factorEditado !== null" @click="guardarFactor" :disabled="guardandoFactor"
          class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50">
          {{ guardandoFactor ? 'Guardando…' : 'Guardar' }}
        </button>
      </div>
    </section>

    <div class="flex items-center justify-between px-1">
      <h2 class="text-sm font-semibold text-gray-500">Oficios y sus trabajos</h2>
      <button v-if="!formCargo" @click="formCargo = { cargo: '', descripcion: '', salario_mensual: null, tarifa_hora: null }"
        class="flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800">
        <PlusIcon class="w-3.5 h-3.5" />Nuevo oficio
      </button>
    </div>

    <!-- Oficio nuevo -->
    <section v-if="formCargo" class="bg-white rounded-xl shadow-sm p-4 space-y-3 ring-2 ring-blue-500">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-800">Nuevo oficio</h3>
        <button @click="formCargo = null" class="p-1 text-gray-400 hover:text-gray-600" aria-label="Cancelar"><XMarkIcon class="w-4 h-4" /></button>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2">
          <label for="cargo-nombre" class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
          <input id="cargo-nombre" v-model="formCargo.cargo" type="text" placeholder="Pintor"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Salario mensual</label>
          <InputPesos v-model="formCargo.salario_mensual" permite-vacio placeholder="2.500.000"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Incentivo por hora</label>
          <InputPesos v-model="formCargo.tarifa_hora" permite-vacio placeholder="8.000"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
      </div>
      <button @click="crearOficio" :disabled="guardandoCargo"
        class="w-full py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
        {{ guardandoCargo ? 'Creando…' : 'Crear oficio' }}
      </button>
    </section>

    <!-- Un oficio con sus trabajos -->
    <section v-for="{ salario: s, trabajos } in oficios" :key="s.cargo" class="bg-white rounded-xl shadow-sm overflow-hidden">
      <div class="p-4 space-y-3">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <h3 class="text-base font-semibold text-gray-800">{{ nombreCargo(s.cargo) }}</h3>
            <p class="text-xs text-gray-400">Sueldo {{ formatPeso(sueldoDia(s)) }} al día, {{ formatPeso(sueldoHora(s)) }} la hora</p>
          </div>
          <button v-if="!CARGOS_BASE.includes(s.cargo)" @click="borrarOficio(s.cargo)"
            class="p-1.5 rounded-lg text-gray-300 hover:text-red-600 hover:bg-gray-100" :aria-label="`Eliminar ${nombreCargo(s.cargo)}`">
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Salario mensual</label>
            <InputPesos v-model="s._salario" @update:modelValue="hayCambios = true"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Días al mes</label>
            <input v-model="s._dias" @input="hayCambios = true" type="number" min="1" max="31" step="1"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
        </div>

        <!-- Lo único que entra en el costo de las fichas -->
        <div class="flex items-center justify-between gap-3 rounded-lg border-2 border-orange-300 px-3 py-2">
          <div class="min-w-0">
            <p class="flex items-center gap-1.5 text-sm font-semibold text-gray-800">
              <span class="w-2 h-2 rounded-full bg-orange-400" aria-hidden="true" />Incentivo por hora
            </p>
            <p class="text-xs text-gray-500">Es lo que cuesta cada hora en las fichas</p>
          </div>
          <InputPesos v-model="s._tarifaHora" @update:modelValue="hayCambios = true" aria-label="Incentivo por hora"
            class="w-28 rounded-lg border border-orange-300 bg-white px-3 py-2 text-sm font-semibold text-right focus:outline-none focus:ring-2 focus:ring-orange-400" />
        </div>
      </div>

      <!-- Trabajos -->
      <div class="border-t border-gray-100">
        <div v-for="p in trabajos" :key="p.id" class="px-4 py-2.5 border-b border-gray-100 last:border-0">
          <div class="flex items-start gap-2">
            <p class="flex-1 min-w-0 text-sm text-gray-800 first-letter:uppercase">{{ nombreProceso(p) }}</p>
            <span class="text-sm font-semibold text-gray-800 tabular-nums">{{ formatPeso(costoProceso(p)) }}</span>
            <button v-if="p.aplica_a === 'personalizado'" @click="borrarTrabajo(p)"
              class="-mr-1 p-0.5 rounded text-gray-300 hover:text-red-600" :aria-label="`Eliminar ${nombreProceso(p)}`">
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
          <div class="flex items-center gap-1.5 mt-1 text-xs text-gray-500">
            <input v-model="p._horas" @input="hayCambios = true" type="number" min="0" step="0.5" inputmode="decimal"
              :aria-label="`Horas de ${nombreProceso(p)}`"
              class="w-16 rounded-lg border border-gray-300 px-2 py-1 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <span>horas por {{ UNIDADES[p.unidad] ?? p.unidad ?? 'unidad' }}</span>
          </div>
        </div>

        <!-- Trabajo nuevo -->
        <div v-if="formTrabajo?.cargo === s.cargo" class="px-4 py-3 bg-gray-50 space-y-2">
          <div class="flex gap-2">
            <input v-model="formTrabajo.nombre" type="text" placeholder="Nombre del trabajo" aria-label="Nombre del trabajo"
              class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <input v-model="formTrabajo.horas" type="number" min="0" step="0.5" placeholder="h" aria-label="Horas"
              class="w-16 rounded-lg border border-gray-300 px-2 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div class="flex gap-2">
            <select v-model="formTrabajo.unidad" aria-label="Por cada"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option v-for="(txt, u) in UNIDADES" :key="u" :value="u">Horas por {{ txt }}</option>
            </select>
            <button @click="formTrabajo = null" class="px-3 rounded-lg border border-gray-300 text-xs text-gray-600 hover:bg-white">Cancelar</button>
            <button @click="crearTrabajo" :disabled="guardandoTrabajo"
              class="px-3 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50">
              {{ guardandoTrabajo ? 'Creando…' : 'Crear' }}
            </button>
          </div>
        </div>
        <button v-else @click="formTrabajo = { cargo: s.cargo, nombre: '', unidad: 'pieza', horas: '' }"
          class="w-full flex items-center gap-1 px-4 py-2.5 text-xs font-semibold text-blue-600 hover:bg-gray-50">
          <PlusIcon class="w-3.5 h-3.5" />Nuevo trabajo de {{ nombreCargo(s.cargo).toLowerCase() }}
        </button>
      </div>
    </section>

    <!-- Trabajos de un oficio que ya no existe -->
    <section v-if="huerfanos.length" class="bg-white rounded-xl shadow-sm overflow-hidden">
      <div class="px-4 pt-4 pb-2">
        <h3 class="text-base font-semibold text-gray-800">Sin oficio</h3>
        <p class="text-xs text-gray-400">Su oficio ya no existe, así que no tienen incentivo por hora y cuestan $ 0.</p>
      </div>
      <div v-for="p in huerfanos" :key="p.id" class="flex items-center justify-between gap-2 px-4 py-2.5 border-t border-gray-100">
        <p class="text-sm text-gray-800 truncate first-letter:uppercase">{{ nombreProceso(p) }}</p>
        <button v-if="p.aplica_a === 'personalizado'" @click="borrarTrabajo(p)" class="p-1 rounded text-gray-300 hover:text-red-600"
          :aria-label="`Eliminar ${nombreProceso(p)}`"><TrashIcon class="w-4 h-4" /></button>
      </div>
    </section>

    <!-- Guardar: solo aparece si hay algo que guardar -->
    <!-- mr-16: a la derecha flotan los botones de subir y del asistente -->
    <div v-if="hayCambios" class="sticky bottom-20 z-10 mr-16 bg-blue-600 text-white rounded-xl shadow-lg px-4 py-3 flex items-center gap-3"
      title="Al guardar se recalculan las fichas que usan estas tarifas">
      <p class="flex-1 text-sm font-medium">Cambios sin guardar</p>
      <button @click="descartar" :disabled="guardando" class="text-xs font-medium text-blue-100 hover:text-white">Descartar</button>
      <button @click="guardar" :disabled="guardando"
        class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-white text-blue-700 text-xs font-semibold hover:bg-blue-50 disabled:opacity-50">
        <IconoS v-if="guardando" class="w-3.5 h-3.5" />
        <CheckIcon v-else class="w-3.5 h-3.5" />
        {{ guardando ? 'Guardando…' : 'Guardar' }}
      </button>
    </div>
  </div>
</template>
