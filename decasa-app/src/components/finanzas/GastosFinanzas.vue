<script setup>
// Gastos: lo que hay por pagar, en qué se fue la plata del mes, el
// presupuesto y los gastos fijos (plantillas).
import { ref, computed, watch } from 'vue'
import { PencilSquareIcon, PlusIcon, PaperClipIcon } from '@heroicons/vue/24/outline'
import {
  getGastosPendientes, getGastos, getEstadoResultados, getPresupuesto, guardarPresupuesto,
  getRecurrentes, omitirPeriodo, anularGasto, getCategorias,
} from '@/api/finanzas'
import { pesos, pesosCorto, pct, fechaCorta, nombreMes, sumarMeses, FRECUENCIAS } from '@/utils/finanzas'
import { iconoPorNombre } from '@/constants/iconos'
import GraficaFinanzas from './GraficaFinanzas.vue'
import PlantillaForm from './PlantillaForm.vue'
import CuentasPorPagar from './CuentasPorPagar.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps({ mes: String, version: Number })
const emit = defineEmits(['pagar', 'cambio'])
const toast = useToast()

const cargando = ref(true)
const pendientes = ref([])
const pagados = ref([])
const resumen = ref(null)
const presupuesto = ref(null)
const plantillas = ref([])
const plantillaAbierta = ref(null)     // {} = nueva, objeto = editar
const editandoPresupuesto = ref(false)
const formPresupuesto = ref({})
const filtroCategoria = ref(null)

async function cargar() {
  cargando.value = true
  try {
    const [p, g, er, pr, pl] = await Promise.all([
      getGastosPendientes(45), getGastos({ mes: props.mes }), getEstadoResultados(props.mes, props.mes),
      getPresupuesto(props.mes), getRecurrentes(),
    ])
    pendientes.value = p.data
    pagados.value = g.data
    resumen.value = er.data.meses[0]
    presupuesto.value = pr.data
    plantillas.value = pl.data
  } catch {
    toast.error('No se pudieron cargar los gastos')
  } finally {
    cargando.value = false
  }
}
watch(() => [props.mes, props.version], cargar, { immediate: true })

const grupos = computed(() => {
  const g = [
    { id: 'vencida', titulo: 'Vencidos', clase: 'text-red-600', items: [] },
    { id: 'por_vencer', titulo: 'Esta semana', clase: 'text-amber-600', items: [] },
    { id: 'programada', titulo: 'Más adelante', clase: 'text-gray-500', items: [] },
  ]
  for (const p of pendientes.value) g.find(x => x.id === p.estado)?.items.push(p)
  return g.filter(x => x.items.length)
})

async function omitir(p) {
  if (!confirm(`¿"${p.nombre}" no se cobró en este periodo?\n\nDeja de salir como pendiente. Si después llega el cobro, se registra como gasto suelto.`)) return
  try {
    await omitirPeriodo({ gasto_recurrente_id: p.gasto_recurrente_id, periodo: p.periodo })
    toast.success('Periodo omitido')
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo omitir')
  }
}

async function anular(g) {
  const motivo = prompt(`¿Por qué se anula "${g.concepto}" (${pesos(g.monto)})?\nNo se borra: deja de contar y queda en la bitácora.`)
  if (!motivo?.trim()) return
  try {
    await anularGasto(g.id, motivo.trim())
    toast.success('Gasto anulado')
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo anular')
  }
}

// ── Gráficas ─────────────────────────────────────────────────────────────────
const categorias = computed(() => resumen.value?.gastos?.por_categoria ?? [])
const totalMes = computed(() => (resumen.value?.gastos?.total ?? 0) + (resumen.value?.financieros?.bancarios ?? 0))

function armarDona(c) {
  if (!categorias.value.length) return null
  const top = categorias.value.slice(0, 6)
  const otros = categorias.value.slice(6).reduce((s, x) => s + x.monto, 0)
  const datos = otros > 0 ? [...top, { nombre: 'Otras', monto: otros }] : top
  return {
    type: 'doughnut',
    data: { labels: datos.map(d => d.nombre), datasets: [{ data: datos.map(d => d.monto), backgroundColor: c.serie, borderWidth: 0 }] },
    options: { cutout: '60%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (x) => ` ${x.label}: ${pesos(x.raw)}` } } } },
  }
}

const listaFiltrada = computed(() => filtroCategoria.value
  ? pagados.value.filter(g => g.categoria_gasto_id === filtroCategoria.value)
  : pagados.value)

// ── Gastos fijos ─────────────────────────────────────────────────────────────
const fijosAlMes = computed(() => plantillas.value.reduce((s, p) => s + (p.equivalente_mes ?? 0), 0))
const nombreFrecuencia = (f) => FRECUENCIAS.find(x => x.value === f)?.label ?? f

// Para arrancar en un toque: lo que casi toda empresa paga.
const SUGERIDAS = [
  { nombre: 'Arriendo', categoria: 'Arriendo' },
  { nombre: 'Internet', categoria: 'Internet y telefonía' },
  { nombre: 'Energía', categoria: 'Energía', estimado: true },
  { nombre: 'Agua', categoria: 'Agua', estimado: true },
  { nombre: 'Contador', categoria: 'Honorarios (contador, abogado)' },
  { nombre: 'Servidor (Render)', categoria: 'Servidores y nube' },
  { nombre: 'Base de datos (Aiven)', categoria: 'Servidores y nube' },
  { nombre: 'OpenAI', categoria: 'Software y licencias', estimado: true },
  { nombre: 'Vercel', categoria: 'Servidores y nube' },
]
const sugeridasFaltantes = computed(() => SUGERIDAS.filter(s => !plantillas.value.some(p => p.nombre.toLowerCase().includes(s.nombre.toLowerCase().split(' ')[0]))))

// ── Presupuesto ──────────────────────────────────────────────────────────────
const todasCategorias = ref([])

async function abrirPresupuesto() {
  if (!todasCategorias.value.length) {
    try { todasCategorias.value = (await getCategorias()).data } catch { return toast.error('No se pudieron cargar las categorías') }
  }
  formPresupuesto.value = Object.fromEntries((presupuesto.value?.categorias ?? []).map(c => [c.categoria_gasto_id, c.presupuesto]))
  editandoPresupuesto.value = true
}

async function guardarPres(copiar = false) {
  try {
    const { data } = copiar
      ? await guardarPresupuesto({ mes: props.mes, copiar_de: sumarMeses(props.mes, -1) })
      : await guardarPresupuesto({
          mes: props.mes,
          items: Object.entries(formPresupuesto.value).map(([id, monto]) => ({ categoria_gasto_id: Number(id), monto: monto === '' ? null : monto })),
        })
    presupuesto.value = data
    editandoPresupuesto.value = false
    toast.success('Presupuesto guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar el presupuesto')
  }
}

// Al editar se ven todas las categorías activas, tengan o no presupuesto.
const categoriasPresupuesto = computed(() =>
  todasCategorias.value.map(c => ({ categoria_gasto_id: c.id, nombre: c.nombre })))
</script>

<template>
  <div v-if="cargando && !resumen" class="flex justify-center py-16"><AppSpinner /></div>

  <div v-else class="space-y-4" :class="cargando && 'opacity-60'">
    <!-- Por pagar -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold text-gray-800">Por pagar</p>
        <p v-if="pendientes.length" class="text-xs text-gray-400">{{ pendientes.length }} · {{ pesosCorto(pendientes.reduce((s, p) => s + p.monto, 0)) }}</p>
      </div>
      <p v-if="!pendientes.length" class="text-xs text-gray-400 mt-1">
        {{ plantillas.length ? 'Todo al día en los próximos 45 días.' : 'Cuando registres gastos fijos, aquí aparece lo que toca pagar.' }}
      </p>
      <div v-for="g in grupos" :key="g.id" class="mt-3">
        <p :class="['text-[11px] font-semibold uppercase mb-1', g.clase]">{{ g.titulo }}</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="p in g.items" :key="p.gasto_recurrente_id + p.periodo" class="py-2 flex items-center gap-2.5">
            <component :is="iconoPorNombre(p.icono) ?? 'span'" class="w-5 h-5 text-gray-400 shrink-0" />
            <div class="min-w-0 flex-1">
              <p class="text-sm text-gray-800 truncate">{{ p.nombre }}</p>
              <p class="text-[11px] text-gray-400 truncate">
                {{ fechaCorta(p.vence) }}<template v-if="p.tienda"> · {{ p.tienda }}</template>
                <template v-if="p.dias_para_vencer < 0"> · hace {{ -p.dias_para_vencer }} días</template>
                <template v-else-if="p.dias_para_vencer === 0"> · hoy</template>
              </p>
            </div>
            <div class="text-right shrink-0">
              <p class="text-sm font-semibold text-gray-800">{{ p.monto_estimado ? '~' : '' }}{{ pesosCorto(p.monto) }}</p>
              <div class="flex gap-2 justify-end">
                <button @click="omitir(p)" class="text-[11px] text-gray-400">Omitir</button>
                <button @click="emit('pagar', p)" class="text-[11px] font-semibold text-blue-600">Pagar</button>
              </div>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <!-- Facturas de proveedores a crédito -->
    <CuentasPorPagar @cambio="emit('cambio')" />

    <!-- En qué se fue la plata -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold text-gray-800">Gastos de {{ nombreMes(mes) }}</p>
        <p class="text-sm font-bold text-gray-800">{{ pesosCorto(totalMes) }}</p>
      </div>
      <template v-if="totalMes > 0">
        <!-- Fijos contra variables -->
        <div class="mt-2 flex h-2.5 rounded-full overflow-hidden bg-gray-100">
          <div class="bg-blue-500" :style="{ width: `${(resumen.gastos.fijos / totalMes) * 100}%` }" />
          <div class="bg-amber-400" :style="{ width: `${(resumen.gastos.variables / totalMes) * 100}%` }" />
        </div>
        <div class="flex justify-between text-[11px] mt-1">
          <span class="text-gray-500"><span class="inline-block w-2 h-2 rounded-full bg-blue-500 mr-1" />Fijos {{ pesosCorto(resumen.gastos.fijos) }}</span>
          <span class="text-gray-500"><span class="inline-block w-2 h-2 rounded-full bg-amber-400 mr-1" />Variables {{ pesosCorto(resumen.gastos.variables) }}</span>
        </div>

        <div class="flex items-center gap-4 mt-3">
          <div class="w-32 shrink-0">
            <GraficaFinanzas :armar="armarDona" :datos="categorias" alto="h-32" descripcion="Gastos por categoría" />
          </div>
          <ul class="flex-1 min-w-0 space-y-1">
            <li v-for="c in categorias.slice(0, 6)" :key="c.id">
              <button @click="filtroCategoria = filtroCategoria === c.id ? null : c.id"
                :class="['w-full flex justify-between text-xs gap-2 rounded px-1', filtroCategoria === c.id && 'bg-gray-100']">
                <span class="truncate text-gray-600">{{ c.nombre }}</span>
                <span class="font-semibold text-gray-800 shrink-0">{{ pesosCorto(c.monto) }}</span>
              </button>
            </li>
          </ul>
        </div>
        <p v-if="resumen.ingresos_netos" class="text-[11px] text-gray-400 mt-2">
          Los gastos son el {{ pct(resumen.margenes.gastos) }} de lo vendido sin IVA (sin contar nómina ni comisiones).
        </p>
      </template>
      <p v-else class="text-xs text-gray-400 mt-1">Sin gastos registrados este mes.</p>
    </div>

    <!-- Presupuesto -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold text-gray-800">Presupuesto</p>
        <button @click="abrirPresupuesto" class="text-xs font-semibold text-blue-600 flex items-center gap-1">
          <PencilSquareIcon class="w-4 h-4" /> {{ presupuesto?.presupuesto ? 'Editar' : 'Ponerlo' }}
        </button>
      </div>
      <p v-if="!presupuesto?.presupuesto && !editandoPresupuesto" class="text-xs text-gray-400 mt-1">
        Ponle a cada categoría cuánto piensas gastar al mes y verás si te pasas antes de que pase.
      </p>
      <template v-else-if="!editandoPresupuesto">
        <div v-for="c in presupuesto.categorias.filter(x => x.presupuesto)" :key="c.categoria_gasto_id" class="mt-2.5">
          <div class="flex justify-between text-xs">
            <span class="text-gray-600 truncate">{{ c.nombre }}</span>
            <span :class="['shrink-0 font-semibold', c.avance > 1 ? 'text-red-600' : c.avance > 0.9 ? 'text-amber-600' : 'text-gray-700']">
              {{ pesosCorto(c.gastado) }} / {{ pesosCorto(c.presupuesto) }}
            </span>
          </div>
          <div class="h-1.5 rounded-full bg-gray-100 mt-1 overflow-hidden">
            <div :class="['h-full rounded-full', c.avance > 1 ? 'bg-red-500' : c.avance > 0.9 ? 'bg-amber-400' : 'bg-green-500']"
              :style="{ width: `${Math.min(100, (c.avance ?? 0) * 100)}%` }" />
          </div>
        </div>
      </template>
      <div v-if="editandoPresupuesto" class="mt-3 space-y-2">
        <p class="text-[11px] text-gray-400">Deja vacío lo que no quieras presupuestar.</p>
        <div v-for="c in categoriasPresupuesto" :key="c.categoria_gasto_id" class="flex items-center justify-between gap-2">
          <span class="text-xs text-gray-600 truncate">{{ c.nombre }}</span>
          <input v-model.number="formPresupuesto[c.categoria_gasto_id]" type="number" min="0" step="10000" inputmode="numeric"
            class="w-32 text-right text-sm border border-gray-200 rounded-lg px-2 py-1" />
        </div>
        <div class="flex gap-2 pt-1">
          <button @click="guardarPres(true)" class="flex-1 bg-gray-100 text-gray-700 text-xs font-semibold rounded-lg py-2">Copiar de {{ nombreMes(sumarMeses(mes, -1)) }}</button>
          <button @click="guardarPres()" class="flex-1 bg-blue-600 text-white text-xs font-semibold rounded-lg py-2">Guardar</button>
        </div>
      </div>
    </div>

    <!-- Gastos fijos (plantillas) -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-semibold text-gray-800">Gastos fijos</p>
          <p v-if="plantillas.length" class="text-xs text-gray-400">Abrir las puertas cuesta {{ pesosCorto(fijosAlMes) }} al mes</p>
        </div>
        <button @click="plantillaAbierta = {}" class="bg-blue-600 text-white text-xs font-semibold rounded-lg px-3 py-2 flex items-center gap-1">
          <PlusIcon class="w-4 h-4" /> Nuevo
        </button>
      </div>

      <div v-if="sugeridasFaltantes.length && plantillas.length < 5" class="mt-3">
        <p class="text-[11px] text-gray-400 mb-1.5">Sugeridos (un toque y pones el monto):</p>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="s in sugeridasFaltantes" :key="s.nombre" @click="plantillaAbierta = { sugerida: s }"
            class="rounded-full text-xs font-semibold px-2.5 py-1.5 border border-dashed border-gray-300 text-gray-600">
            + {{ s.nombre }}
          </button>
        </div>
      </div>

      <ul class="divide-y divide-gray-100 mt-2">
        <li v-for="p in plantillas" :key="p.id" class="py-2 flex items-center gap-2.5">
          <component :is="iconoPorNombre(p.icono) ?? 'span'" class="w-5 h-5 text-gray-400 shrink-0" />
          <button @click="plantillaAbierta = p" class="min-w-0 flex-1 text-left">
            <p class="text-sm text-gray-800 truncate">{{ p.nombre }}</p>
            <p class="text-[11px] text-gray-400 truncate">
              {{ nombreFrecuencia(p.frecuencia) }}<template v-if="p.dia_pago"> · día {{ p.dia_pago }}</template>
              <template v-if="p.tienda"> · {{ p.tienda }}</template>
              <template v-if="p.proximo"> · próximo {{ fechaCorta(p.proximo.vence) }}</template>
            </p>
          </button>
          <div class="text-right shrink-0">
            <p class="text-sm font-semibold text-gray-800">{{ p.monto_estimado ? '~' : '' }}{{ pesosCorto(p.monto_sugerido) }}</p>
            <p v-if="p.por_pagar" class="text-[11px] font-semibold text-red-600">{{ p.por_pagar }} por pagar</p>
          </div>
        </li>
      </ul>
    </div>

    <!-- Pagados del mes -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between">
        <p class="text-sm font-semibold text-gray-800">Pagados en {{ nombreMes(mes) }}</p>
        <button v-if="filtroCategoria" @click="filtroCategoria = null" class="text-xs font-semibold text-blue-600">Ver todos</button>
      </div>
      <p v-if="!listaFiltrada.length" class="text-xs text-gray-400 mt-1">Nada pagado todavía.</p>
      <ul class="divide-y divide-gray-100 mt-1">
        <li v-for="g in listaFiltrada" :key="g.id" class="py-2 flex items-center gap-2.5">
          <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-800 truncate">{{ g.concepto }}</p>
            <p class="text-[11px] text-gray-400 truncate">
              {{ fechaCorta(g.fecha_pago) }} · {{ g.categoria }}<template v-if="g.tienda"> · {{ g.tienda }}</template>
              <template v-if="g.cubre_desde !== g.cubre_hasta"> · cubre {{ fechaCorta(g.cubre_desde) }}–{{ fechaCorta(g.cubre_hasta) }}</template>
            </p>
          </div>
          <a v-if="g.comprobante_fotos?.length" :href="g.comprobante_fotos[0]" target="_blank" rel="noopener" class="text-gray-400" aria-label="Ver recibo">
            <PaperClipIcon class="w-4 h-4" />
          </a>
          <div class="text-right shrink-0">
            <p class="text-sm font-semibold text-gray-800">{{ pesosCorto(g.monto) }}</p>
            <button @click="anular(g)" class="text-[11px] text-gray-400 hover:text-red-600">Anular</button>
          </div>
        </li>
      </ul>
    </div>

    <PlantillaForm v-if="plantillaAbierta" :plantilla="plantillaAbierta" @cerrar="plantillaAbierta = null"
      @guardado="plantillaAbierta = null; emit('cambio')" />
  </div>
</template>
