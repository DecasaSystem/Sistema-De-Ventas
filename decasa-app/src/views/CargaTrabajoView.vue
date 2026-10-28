<script setup>
/**
 * Carga de trabajo: cuánto trabajo hay y cuánto viene.
 *
 * Pedido del dueño (2026-10-28): "pendientes para todo para saber cuánto
 * trabajo tenemos". Junta en una pantalla lo que antes había que deducir
 * pasando por Órdenes, Producción y Mis pasos: qué hay por entregar, qué
 * espera al taller sin arrancar, en qué paso va cada pieza y cuántas le
 * esperan a cada proceso. Cada número lleva a la lista que lo explica.
 *
 * Solo lee (GET /carga-trabajo). Las cuentas las hace el servidor con las
 * mismas reglas de las listas; aquí solo se pintan.
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useRealtime } from '@/composables/useRealtime'
import { useTiposProceso } from '@/composables/useTiposProceso'
import { getCargaTrabajo } from '@/api/cargaTrabajo'
import { getTiendas } from '@/api/tiendas'
import AppSpinner from '@/components/common/AppSpinner.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import {
  ArrowPathIcon, ClipboardDocumentListIcon, WrenchScrewdriverIcon, TruckIcon,
  ExclamationTriangleIcon, ClockIcon, UserGroupIcon, ChevronRightIcon,
  InformationCircleIcon, FireIcon, PauseCircleIcon, QuestionMarkCircleIcon,
} from '@heroicons/vue/24/outline'

const auth   = useAuthStore()
const router = useRouter()
const toast  = useToast()
const { clasesDeColor } = useTiposProceso()

const datos     = ref(null)
const cargando  = ref(true)
const refrescando = ref(false)
const tiendas   = ref([])
const tiendaId  = ref('')

// La pestaña se recuerda mientras la app siga abierta: al volver de una orden
// se sigue donde se estaba.
const TABS = [
  { id: 'resumen', label: 'Resumen' },
  { id: 'ordenes', label: 'Órdenes' },
  { id: 'taller',  label: 'Taller'  },
  { id: 'pasos',   label: 'Pasos'   },
]
let tabGuardada = null
try { tabGuardada = sessionStorage.getItem('carga-trabajo:tab') } catch {}
const tab = ref(TABS.some(t => t.id === tabGuardada) ? tabGuardada : 'resumen')
function elegirTab(id) {
  tab.value = id
  try { sessionStorage.setItem('carga-trabajo:tab', id) } catch {}
}

async function cargar({ silencioso = false } = {}) {
  if (silencioso) refrescando.value = true
  else cargando.value = true
  try {
    const params = tiendaId.value ? { tienda_id: tiendaId.value } : {}
    const { data } = await getCargaTrabajo(params, { silencioso })
    datos.value = data
  } catch {
    if (!silencioso) toast.error('No se pudo cargar la carga de trabajo. Intenta de nuevo.')
  } finally {
    cargando.value = false
    refrescando.value = false
  }
}

const o = computed(() => datos.value?.ordenes)
const p = computed(() => datos.value?.produccion)

const horaActualizado = computed(() => {
  if (!datos.value?.generado_at) return ''
  return new Date(datos.value.generado_at).toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' })
})

// ── Textos ───────────────────────────────────────────────────────────────

const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`

const CUBETAS = [
  { id: 'vencidas',       label: 'Vencidas',        bar: 'bg-red-500'     },
  { id: 'esta_semana',    label: 'Próximos 7 días', bar: 'bg-amber-500'   },
  { id: 'proxima_semana', label: 'La otra semana',  bar: 'bg-blue-500'    },
  { id: 'este_mes',       label: 'Este mes',        bar: 'bg-blue-300'    },
  { id: 'mas_adelante',   label: 'Más adelante',    bar: 'bg-gray-300'    },
  { id: 'sin_fecha',      label: 'Sin fecha',       bar: 'bg-gray-200'    },
]

const TIPOS_ORDEN = [
  { id: 'fabricar',        label: 'De catálogo, a fabricar', desc: 'No había en stock y se mandó a hacer' },
  { id: 'personalizado',   label: 'Personalizados',          desc: 'Producto del catálogo con cambios' },
  { id: 'diseno_especial', label: 'Diseños nuevos',          desc: 'No existe en el catálogo: se diseña y fabrica' },
  { id: 'retapizar',       label: 'Cambio de tela / arreglo', desc: 'Mueble de stock que pasa por la fábrica' },
  { id: 'restauracion',    label: 'Restauraciones',          desc: 'Muebles del cliente' },
]

const TIPOS_PIEZA = {
  fabricar: 'A fabricar', personalizado: 'Personalizado', diseno_especial: 'Diseño nuevo',
  retapizar: 'Cambio de tela', restauracion: 'Restauración', reserva: 'Para Reserva',
  garantia: 'Garantía', catalogo: 'Catálogo', producto_unico: 'Producto único',
}

const ESTADOS_ORDEN = [
  { id: 'pendiente_cotizacion', label: 'Esperando precio',  desc: 'El taller tiene que cotizar' },
  { id: 'pendiente_anticipo',   label: 'En espera',         desc: 'Sin anticipo o por arrancar' },
  { id: 'en_produccion',        label: 'En producción',     desc: 'Algo se está fabricando' },
  { id: 'listo_entrega',        label: 'Listas para entregar', desc: 'Todo hecho, falta llevarlo' },
  { id: 'en_camino',            label: 'En camino',         desc: 'Ya salieron en el camión' },
  { id: 'devuelto',             label: 'Devueltas',         desc: 'Volvió algo: hay que decidir' },
]

function textoDias(dias) {
  if (dias === null || dias === undefined) return 'Sin fecha'
  if (dias < 0)  return `Venció hace ${plural(-dias, 'día', 'días')}`
  if (dias === 0) return 'Es hoy'
  if (dias === 1) return 'Mañana'
  return `En ${dias} días`
}
function claseDias(dias) {
  if (dias === null || dias === undefined) return 'bg-gray-100 text-gray-500'
  if (dias < 0)  return 'bg-red-100 text-red-700'
  if (dias <= 3) return 'bg-amber-100 text-amber-800'
  return 'bg-gray-100 text-gray-600'
}
function fechaCorta(f) {
  if (!f) return ''
  const [y, m, d] = f.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}
const dias1 = (n) => (n === null || n === undefined) ? '—' : `${String(n).replace('.', ',')} d`

// ── Resumen ──────────────────────────────────────────────────────────────

const maxCubeta = computed(() => {
  if (!o.value || !p.value) return 1
  return Math.max(1, ...CUBETAS.map(c => Math.max(o.value.calendario[c.id], p.value.calendario[c.id])))
})

/**
 * El proceso con más días de trabajo encima al ritmo de las últimas semanas.
 * Solo se señala si de verdad pasa de una semana: decir "cuello de botella"
 * por dos días de cola sería alarmar por nada.
 *
 * Despacho no compite: lo tiene puesto de último cada pieza del taller, así
 * que su cola es todo lo que viene atrás y siempre "ganaría" sin ser él quien
 * frena.
 */
const cuelloDeBotella = computed(() => {
  const pasos = (p.value?.pasos ?? []).filter(x => x.dias_para_ponerse_al_dia && x.clave !== 'despacho')
  if (!pasos.length) return null
  const peor = pasos.reduce((a, b) => (b.dias_para_ponerse_al_dia > a.dias_para_ponerse_al_dia ? b : a))
  return peor.dias_para_ponerse_al_dia >= 7 ? peor : null
})

const flujo = computed(() => p.value?.flujo ?? [])
const maxFlujo = computed(() => Math.max(1, ...flujo.value.flatMap(s => [s.entraron, s.salieron])))
const balance = computed(() => {
  const ult = flujo.value.slice(-4)
  const entraron = ult.reduce((s, x) => s + x.entraron, 0)
  const salieron = ult.reduce((s, x) => s + x.salieron, 0)
  return { entraron, salieron, diferencia: entraron - salieron }
})

const alertas = computed(() => {
  if (!o.value || !p.value) return []
  const lista = []
  const viejas = p.value.piezas.filter(x => x.etapa === 'sin_arrancar' && (x.dias_esperando ?? 0) >= 7).length
  if (viejas)                  lista.push({ txt: `${plural(viejas, 'pieza lleva', 'piezas llevan')} más de una semana sin arrancar`, ir: () => verPiezas('sin_arrancar') })
  if (p.value.vencidas)        lista.push({ txt: `${plural(p.value.vencidas, 'pieza del taller pasó', 'piezas del taller pasaron')} su fecha de compromiso`, ir: () => verPiezas('vencidas') })
  if (o.value.atrasadas)       lista.push({ txt: `${plural(o.value.atrasadas, 'orden tiene', 'órdenes tienen')} la entrega vencida`, ir: () => irAOrdenes({ retrasadas: 1 }) })
  if (p.value.sin_responsable) lista.push({ txt: `${plural(p.value.sin_responsable, 'pieza está', 'piezas están')} en un paso sin nadie apuntado`, ir: () => elegirTab('pasos') })
  if (o.value.por_estado.devuelto) lista.push({ txt: `${plural(o.value.por_estado.devuelto, 'orden devuelta espera', 'órdenes devueltas esperan')} decisión`, ir: () => irAOrdenes({ estado: 'devuelto' }) })
  if (o.value.por_estado.pendiente_cotizacion) lista.push({ txt: `${plural(o.value.por_estado.pendiente_cotizacion, 'orden espera', 'órdenes esperan')} que el taller ponga precio`, ir: () => irAOrdenes({ estado: 'pendiente_cotizacion' }) })
  return lista
})

// ── Taller ───────────────────────────────────────────────────────────────

const FILTROS_PIEZA = [
  { id: 'todas',        label: 'Todas' },
  { id: 'sin_arrancar', label: 'Sin arrancar' },
  { id: 'empezadas',    label: 'Empezadas' },
  { id: 'vencidas',     label: 'Vencidas' },
]
const filtroPieza = ref('todas')
const pasoFiltro  = ref('')

const piezasFiltradas = computed(() => {
  let lista = p.value?.piezas ?? []
  if (filtroPieza.value === 'sin_arrancar') lista = lista.filter(x => x.etapa === 'sin_arrancar')
  if (filtroPieza.value === 'empezadas')    lista = lista.filter(x => x.etapa !== 'sin_arrancar')
  if (filtroPieza.value === 'vencidas')     lista = lista.filter(x => x.dias_restantes !== null && x.dias_restantes < 0)
  if (pasoFiltro.value) lista = lista.filter(x => x.paso_actual?.clave === pasoFiltro.value)
  return lista
})

function cuentaFiltro(id) {
  const l = p.value?.piezas ?? []
  if (id === 'sin_arrancar') return l.filter(x => x.etapa === 'sin_arrancar').length
  if (id === 'empezadas')    return l.filter(x => x.etapa !== 'sin_arrancar').length
  if (id === 'vencidas')     return l.filter(x => x.dias_restantes !== null && x.dias_restantes < 0).length
  return l.length
}

function verPiezas(filtro, paso = '') {
  filtroPieza.value = filtro
  pasoFiltro.value = paso
  elegirTab('taller')
}

function colorPaso(clave) {
  return p.value?.pasos.find(x => x.clave === clave)?.color
}
function nombrePaso(clave) {
  return p.value?.pasos.find(x => x.clave === clave)?.nombre ?? clave
}
function clasePuntoPaso(estado) {
  if (estado === 'completado') return 'bg-green-500'
  if (estado === 'en_proceso') return 'bg-blue-500'
  return 'bg-gray-200'
}

// ── Pasos ────────────────────────────────────────────────────────────────

const maxCargaPaso = computed(() =>
  Math.max(1, ...(p.value?.pasos ?? []).map(x => x.en_proceso + x.en_cola)))

// ── Navegación ───────────────────────────────────────────────────────────

/** Lleva a Órdenes con el filtro puesto (la lista lo lee de la dirección). */
function irAOrdenes(query) {
  router.push({ name: 'ordenes', query: { ...query, ...(tiendaId.value ? { tienda_id: tiendaId.value } : {}) } })
}
function irAOrden(id) {
  router.push({ name: 'orden-detalle', params: { id } })
}
function irAProduccion(estado) {
  router.push({ name: 'produccion', query: { estado, ...(tiendaId.value ? { tienda_id: tiendaId.value } : {}) } })
}
const puedeVerProduccion = computed(() => auth.puedeProduccion || auth.usuario?.rol === 'vendedor')

const { listen } = useRealtime()

onMounted(async () => {
  cargar()
  if (auth.isSupervisor) {
    try { tiendas.value = (await getTiendas()).data ?? [] } catch {}
  }
  // Si el taller mueve una pieza mientras se mira, se actualiza por debajo.
  listen('produccion', 'produccion.actualizada', () => cargar({ silencioso: true }))
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Encabezado -->
    <div class="flex items-center gap-2">
      <div class="flex-1 min-w-0">
        <h2 class="text-lg font-bold text-gray-800">Carga de trabajo</h2>
        <p class="text-xs text-gray-400">
          <template v-if="horaActualizado">Cifras de las {{ horaActualizado }}</template>
          <template v-if="refrescando"> · actualizando…</template>
        </p>
      </div>
      <select
        v-if="auth.isSupervisor && tiendas.length"
        v-model="tiendaId"
        @change="cargar()"
        class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm max-w-[9.5rem] focus:outline-none focus:ring-2 focus:ring-blue-500"
        aria-label="Tienda"
      >
        <option value="">Todas las tiendas</option>
        <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
      </select>
      <button
        @click="router.push({ name: 'carga-trabajo-ayuda' })"
        class="p-2 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50"
        title="Cómo funciona esta pantalla"
        aria-label="Ayuda"
      >
        <QuestionMarkCircleIcon class="w-4 h-4" />
      </button>
      <button
        @click="cargar({ silencioso: true })"
        :disabled="refrescando || cargando"
        class="p-2 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 disabled:opacity-50"
        title="Actualizar"
      >
        <ArrowPathIcon :class="['w-4 h-4', refrescando ? 'animate-spin' : '']" />
      </button>
    </div>

    <!-- Pestañas -->
    <div class="flex bg-gray-100 rounded-xl p-1">
      <button
        v-for="t in TABS" :key="t.id"
        @click="elegirTab(t.id)"
        :class="['flex-1 py-1.5 text-sm font-medium rounded-lg transition-colors',
          tab === t.id ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
      >{{ t.label }}</button>
    </div>

    <AppSpinner v-if="cargando && !datos" />

    <template v-else-if="datos">
      <!-- ══════════════════ RESUMEN ══════════════════ -->
      <template v-if="tab === 'resumen'">
        <!-- Las cifras grandes -->
        <div class="grid grid-cols-2 gap-3">
          <button @click="elegirTab('ordenes')" class="bg-white rounded-xl shadow-sm p-3 text-left">
            <div class="flex items-center gap-1.5 text-xs text-gray-500"><ClipboardDocumentListIcon class="w-4 h-4" /> Órdenes abiertas</div>
            <p class="text-2xl font-bold text-gray-800 mt-1">{{ o.abiertas }}</p>
            <p class="text-[11px] text-gray-400">{{ plural(o.con_taller, 'con algo por fabricar', 'con algo por fabricar') }}</p>
          </button>
          <button @click="elegirTab('ordenes')" class="bg-white rounded-xl shadow-sm p-3 text-left">
            <div class="flex items-center gap-1.5 text-xs text-gray-500"><TruckIcon class="w-4 h-4" /> Para entregar</div>
            <p class="text-2xl font-bold text-emerald-600 mt-1">{{ o.para_entregar.con_algo_listo }}</p>
            <p class="text-[11px] text-gray-400">{{ o.para_entregar.todo_listo }} completas · {{ o.para_entregar.en_camino }} en camino</p>
          </button>
          <button @click="verPiezas('todas')" class="bg-white rounded-xl shadow-sm p-3 text-left">
            <div class="flex items-center gap-1.5 text-xs text-gray-500"><WrenchScrewdriverIcon class="w-4 h-4" /> En el taller</div>
            <p class="text-2xl font-bold text-blue-600 mt-1">{{ p.en_taller_total.piezas }}</p>
            <p class="text-[11px] text-gray-400">{{ plural(p.en_taller_total.unidades, 'unidad', 'unidades') }} · {{ p.empezadas.piezas }} empezadas</p>
          </button>
          <button @click="verPiezas('sin_arrancar')" class="bg-white rounded-xl shadow-sm p-3 text-left">
            <div class="flex items-center gap-1.5 text-xs text-gray-500"><PauseCircleIcon class="w-4 h-4" /> Sin arrancar</div>
            <p :class="['text-2xl font-bold mt-1', p.sin_arrancar.piezas ? 'text-amber-600' : 'text-gray-800']">{{ p.sin_arrancar.piezas }}</p>
            <p class="text-[11px] text-gray-400">Esperan que les pongan pasos</p>
          </button>
        </div>

        <!-- Lo que hay que mirar -->
        <div v-if="alertas.length" class="bg-white rounded-xl shadow-sm divide-y divide-gray-100">
          <p class="px-4 pt-3 pb-2 text-sm font-semibold text-gray-700 flex items-center gap-1.5">
            <ExclamationTriangleIcon class="w-4 h-4 text-amber-500" /> Para atender
          </p>
          <button
            v-for="(a, i) in alertas" :key="i" @click="a.ir()"
            class="w-full flex items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50"
          >
            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"></span>
            <span class="flex-1">{{ a.txt }}</span>
            <ChevronRightIcon class="w-4 h-4 text-gray-300" />
          </button>
        </div>

        <!-- Cuello de botella -->
        <div v-if="cuelloDeBotella" class="bg-white rounded-xl shadow-sm p-4 border-l-4 border-red-400">
          <p class="text-sm font-semibold text-gray-800 flex items-center gap-1.5">
            <FireIcon class="w-4 h-4 text-red-500" /> {{ cuelloDeBotella.nombre }} es el paso más cargado
          </p>
          <p class="text-xs text-gray-500 mt-1">
            Tiene {{ cuelloDeBotella.en_proceso + cuelloDeBotella.en_cola }} piezas encima (en proceso y por llegar) y saca
            unas {{ String(cuelloDeBotella.ritmo_semana).replace('.', ',') }} por semana:
            son ≈ {{ Math.round(cuelloDeBotella.dias_para_ponerse_al_dia) }} días de trabajo
            <template v-if="cuelloDeBotella.personas"> con {{ plural(cuelloDeBotella.personas, 'persona', 'personas') }}</template>.
          </p>
        </div>

        <!-- Lo que viene -->
        <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
          <div>
            <p class="text-sm font-semibold text-gray-700">Lo que viene</p>
            <p class="text-[11px] text-gray-400">Por fecha prometida al cliente (órdenes) y fecha de compromiso (piezas del taller)</p>
          </div>
          <div class="flex gap-3 text-[11px] text-gray-500">
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-gray-700"></span> Órdenes</span>
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-blue-400"></span> Piezas del taller</span>
          </div>
          <div v-for="c in CUBETAS" :key="c.id" class="space-y-1">
            <div class="flex justify-between text-xs">
              <span :class="c.id === 'vencidas' ? 'text-red-600 font-semibold' : 'text-gray-600'">{{ c.label }}</span>
              <span class="text-gray-500 tabular-nums">{{ o.calendario[c.id] }} · {{ p.calendario[c.id] }}</span>
            </div>
            <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
              <div :class="['h-full rounded-full', c.id === 'vencidas' ? 'bg-red-500' : 'bg-gray-700']"
                   :style="{ width: (o.calendario[c.id] / maxCubeta * 100) + '%' }"></div>
            </div>
            <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
              <div :class="['h-full rounded-full', c.id === 'vencidas' ? 'bg-red-300' : 'bg-blue-400']"
                   :style="{ width: (p.calendario[c.id] / maxCubeta * 100) + '%' }"></div>
            </div>
          </div>
        </div>

        <!-- Entra vs sale -->
        <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
          <div>
            <p class="text-sm font-semibold text-gray-700">¿Se acumula o se despeja?</p>
            <p class="text-[11px] text-gray-400">Piezas que entraron al taller y que salieron, semana por semana</p>
          </div>
          <div class="flex items-end gap-1.5 h-24">
            <div v-for="s in flujo" :key="s.desde" class="flex-1 flex flex-col items-center gap-1 h-full justify-end">
              <div class="w-full flex items-end gap-0.5 h-full">
                <div class="flex-1 bg-amber-400 rounded-t" :style="{ height: (s.entraron / maxFlujo * 100) + '%' }" :title="`Entraron ${s.entraron}`"></div>
                <div class="flex-1 bg-emerald-500 rounded-t" :style="{ height: (s.salieron / maxFlujo * 100) + '%' }" :title="`Salieron ${s.salieron}`"></div>
              </div>
              <span class="text-[9px] text-gray-400">{{ fechaCorta(s.desde) }}</span>
            </div>
          </div>
          <div class="flex gap-3 text-[11px] text-gray-500">
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-amber-400"></span> Entraron</span>
            <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-emerald-500"></span> Salieron</span>
          </div>
          <p class="text-xs" :class="balance.diferencia > 0 ? 'text-amber-700' : 'text-emerald-700'">
            Últimas 4 semanas: entraron {{ balance.entraron }} y salieron {{ balance.salieron }}.
            <template v-if="balance.diferencia > 0">El trabajo se está acumulando ({{ balance.diferencia }} de más).</template>
            <template v-else-if="balance.diferencia < 0">El taller va sacando más de lo que entra.</template>
            <template v-else>Va parejo.</template>
          </p>
        </div>
      </template>

      <!-- ══════════════════ ÓRDENES ══════════════════ -->
      <template v-else-if="tab === 'ordenes'">
        <!-- Por estado -->
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="e in ESTADOS_ORDEN" :key="e.id"
            @click="irAOrdenes({ estado: e.id })"
            class="bg-white rounded-xl shadow-sm p-3 text-left"
          >
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs font-medium text-gray-600">{{ e.label }}</span>
              <span class="text-xl font-bold text-gray-800 tabular-nums">{{ o.por_estado[e.id] }}</span>
            </div>
            <p class="text-[11px] text-gray-400 mt-0.5">{{ e.desc }}</p>
          </button>
        </div>

        <button
          v-if="o.atrasadas"
          @click="irAOrdenes({ retrasadas: 1 })"
          class="w-full bg-white rounded-xl shadow-sm px-4 py-3 flex items-center gap-2 text-left border-l-4 border-red-400"
        >
          <ClockIcon class="w-5 h-5 text-red-500" />
          <span class="flex-1 text-sm text-gray-700"><b>{{ o.atrasadas }}</b> {{ o.atrasadas === 1 ? 'orden tiene' : 'órdenes tienen' }} la entrega vencida</span>
          <ChevronRightIcon class="w-4 h-4 text-gray-300" />
        </button>

        <!-- Para fabricar -->
        <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
          <div>
            <p class="text-sm font-semibold text-gray-700">Órdenes con algo por fabricar: {{ o.con_taller }}</p>
            <p class="text-[11px] text-gray-400">
              {{ o.con_taller_sin_arrancar }} todavía tienen piezas sin arrancar en el taller. Una orden puede tener varios tipos.
            </p>
          </div>
          <div v-for="t in TIPOS_ORDEN" :key="t.id" class="flex items-center gap-3">
            <div class="flex-1 min-w-0">
              <p class="text-sm text-gray-700">{{ t.label }}</p>
              <p class="text-[11px] text-gray-400">{{ t.desc }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm font-bold text-gray-800 tabular-nums">{{ o.por_tipo[t.id].ordenes }}</p>
              <p class="text-[10px] text-gray-400">{{ plural(o.por_tipo[t.id].unidades, 'unidad', 'unidades') }}</p>
            </div>
          </div>
        </div>

        <!-- Para entregar -->
        <div class="bg-white rounded-xl shadow-sm">
          <div class="px-4 pt-3 pb-2">
            <p class="text-sm font-semibold text-gray-700">Se pueden entregar hoy: {{ o.para_entregar.con_algo_listo }}</p>
            <p class="text-[11px] text-gray-400">
              {{ o.para_entregar.todo_listo }} con todo listo · las demás, solo una parte (lo de stock o lo que ya salió del taller).
              {{ o.para_entregar.parciales }} ya llevan entrega parcial.
            </p>
          </div>
          <p v-if="!o.para_entregar.lista.length" class="px-4 pb-4 text-sm text-gray-400">Nada listo para llevar.</p>
          <div v-else class="divide-y divide-gray-100">
            <button
              v-for="ord in o.para_entregar.lista" :key="ord.id" @click="irAOrden(ord.id)"
              class="w-full px-4 py-2.5 flex items-center gap-2 text-left hover:bg-gray-50"
            >
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ ord.referencia }} · {{ ord.cliente }}</p>
                <p class="text-[11px] text-gray-400 truncate">
                  {{ ord.tienda }} · {{ ord.unidades_listas }} de {{ ord.unidades }} listas
                </p>
              </div>
              <span :class="['text-[10px] font-semibold rounded-full px-2 py-0.5', ord.todo_listo ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700']">
                {{ ord.todo_listo ? 'Completa' : 'Parcial' }}
              </span>
              <span :class="['text-[10px] font-semibold rounded-full px-2 py-0.5 whitespace-nowrap', claseDias(ord.dias)]">{{ textoDias(ord.dias) }}</span>
            </button>
          </div>
        </div>

        <!-- Sin arrancar -->
        <div v-if="o.sin_arrancar.length" class="bg-white rounded-xl shadow-sm">
          <div class="px-4 pt-3 pb-2">
            <p class="text-sm font-semibold text-gray-700">Órdenes esperando al taller: {{ o.sin_arrancar.length }}</p>
            <p class="text-[11px] text-gray-400">Tienen piezas creadas a las que nadie les ha puesto pasos.</p>
          </div>
          <div class="divide-y divide-gray-100">
            <button
              v-for="ord in o.sin_arrancar" :key="ord.id" @click="irAOrden(ord.id)"
              class="w-full px-4 py-2.5 flex items-center gap-2 text-left hover:bg-gray-50"
            >
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">{{ ord.referencia }} · {{ ord.cliente }}</p>
                <p class="text-[11px] text-gray-400 truncate">{{ ord.tienda }} · {{ plural(ord.piezas_sin_arrancar, 'pieza sin arrancar', 'piezas sin arrancar') }}</p>
              </div>
              <span :class="['text-[10px] font-semibold rounded-full px-2 py-0.5 whitespace-nowrap', claseDias(ord.dias)]">{{ textoDias(ord.dias) }}</span>
            </button>
          </div>
        </div>

        <!-- Por tienda -->
        <div v-if="o.por_tienda.length > 1" class="bg-white rounded-xl shadow-sm p-4">
          <p class="text-sm font-semibold text-gray-700 mb-2">Por tienda</p>
          <div class="overflow-x-auto -mx-1">
            <table class="w-full text-xs">
              <thead>
                <tr class="text-gray-400 text-left">
                  <th class="font-medium px-1 py-1">Tienda</th>
                  <th class="font-medium px-1 py-1 text-right">Abiertas</th>
                  <th class="font-medium px-1 py-1 text-right">Entregar</th>
                  <th class="font-medium px-1 py-1 text-right">Fabricar</th>
                  <th class="font-medium px-1 py-1 text-right">Vencidas</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in o.por_tienda" :key="t.tienda_id ?? 0" class="border-t border-gray-100 text-gray-700">
                  <td class="px-1 py-1.5 truncate max-w-[7rem]">{{ t.nombre }}</td>
                  <td class="px-1 py-1.5 text-right tabular-nums font-semibold">{{ t.abiertas }}</td>
                  <td class="px-1 py-1.5 text-right tabular-nums">{{ t.para_entregar }}</td>
                  <td class="px-1 py-1.5 text-right tabular-nums">{{ t.con_taller }}</td>
                  <td :class="['px-1 py-1.5 text-right tabular-nums', t.atrasadas ? 'text-red-600 font-semibold' : '']">{{ t.atrasadas }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- ══════════════════ TALLER ══════════════════ -->
      <template v-else-if="tab === 'taller'">
        <div class="grid grid-cols-3 gap-2">
          <div class="bg-white rounded-xl shadow-sm p-3 text-center">
            <p class="text-[11px] text-gray-500">Sin arrancar</p>
            <p :class="['text-xl font-bold', p.sin_arrancar.piezas ? 'text-amber-600' : 'text-gray-800']">{{ p.sin_arrancar.piezas }}</p>
            <p class="text-[10px] text-gray-400">{{ plural(p.sin_arrancar.unidades, 'unidad', 'unidades') }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-3 text-center">
            <p class="text-[11px] text-gray-500">En proceso</p>
            <p class="text-xl font-bold text-blue-600">{{ p.en_proceso.piezas }}</p>
            <p class="text-[10px] text-gray-400">{{ plural(p.en_proceso.unidades, 'unidad', 'unidades') }}</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-3 text-center">
            <p class="text-[11px] text-gray-500">En despacho</p>
            <p class="text-xl font-bold text-purple-600">{{ p.en_despacho.piezas }}</p>
            <p class="text-[10px] text-gray-400">{{ plural(p.en_despacho.unidades, 'unidad', 'unidades') }}</p>
          </div>
        </div>

        <!-- De qué es -->
        <div v-if="Object.keys(p.por_tipo).length" class="flex flex-wrap gap-1.5">
          <span
            v-for="(v, k) in p.por_tipo" :key="k"
            class="text-xs font-semibold rounded-full px-2.5 py-1 bg-gray-100 text-gray-600"
          >{{ TIPOS_PIEZA[k] ?? k }} · {{ v.piezas }}</span>
        </div>

        <!-- Filtro -->
        <div class="flex bg-gray-100 rounded-xl p-1">
          <button
            v-for="f in FILTROS_PIEZA" :key="f.id"
            @click="filtroPieza = f.id"
            :class="['flex-1 py-1.5 text-xs font-medium rounded-lg transition-colors',
              filtroPieza === f.id ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
          >{{ f.label }} <span class="text-gray-400">{{ cuentaFiltro(f.id) }}</span></button>
        </div>
        <div v-if="pasoFiltro" class="flex items-center gap-2 text-xs text-gray-600">
          Solo las que están en <b>{{ nombrePaso(pasoFiltro) }}</b>
          <button @click="pasoFiltro = ''" class="text-blue-600 font-semibold">Quitar</button>
        </div>

        <p v-if="!piezasFiltradas.length" class="text-sm text-gray-400 text-center py-6">No hay piezas aquí.</p>

        <!-- Piezas -->
        <div
          v-for="pz in piezasFiltradas" :key="pz.id"
          class="bg-white rounded-xl shadow-sm p-3 space-y-2"
        >
          <div class="flex items-start gap-2">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-gray-800">
                {{ pz.producto }}<span v-if="pz.cantidad > 1" class="text-gray-500 font-normal"> × {{ pz.cantidad }}</span>
              </p>
              <p v-if="pz.detalle" class="text-[11px] text-gray-500 truncate">{{ pz.detalle }}</p>
              <button
                v-if="pz.orden" @click="irAOrden(pz.orden.id)"
                class="text-[11px] text-blue-600 truncate max-w-full text-left"
              >{{ pz.orden.referencia }} · {{ pz.orden.cliente }} · {{ pz.orden.tienda }}</button>
              <p v-else class="text-[11px] text-purple-600">Para la Reserva de Fábrica</p>
            </div>
            <span :class="['text-[10px] font-semibold rounded-full px-2 py-0.5 whitespace-nowrap', claseDias(pz.dias_restantes)]">
              {{ textoDias(pz.dias_restantes) }}
            </span>
          </div>

          <!-- Sin arrancar -->
          <div v-if="pz.etapa === 'sin_arrancar'" class="flex items-center gap-1.5 text-xs text-amber-700">
            <PauseCircleIcon class="w-4 h-4" />
            Sin arrancar
            <template v-if="pz.dias_esperando !== null">· esperando hace {{ plural(pz.dias_esperando, 'día', 'días') }}</template>
          </div>

          <!-- Empezada: paso actual y barra de pasos -->
          <template v-else>
            <div class="flex items-center gap-1.5 flex-wrap">
              <span :class="['text-xs font-semibold rounded-full px-2 py-0.5', clasesDeColor(colorPaso(pz.paso_actual.clave))]">
                {{ pz.paso_actual.nombre }}
              </span>
              <span class="text-[11px] text-gray-500">
                hace {{ dias1(pz.paso_actual.dias) }}
                · paso {{ pz.hechos + 1 }} de {{ pz.pasos.length }}
              </span>
            </div>
            <div class="flex gap-0.5">
              <div
                v-for="(x, i) in pz.pasos" :key="i"
                :class="['h-1.5 flex-1 rounded-full', clasePuntoPaso(x.estado)]"
                :title="`${nombrePaso(x.clave)}: ${x.estado}`"
              ></div>
            </div>
            <p class="text-[11px]" :class="pz.paso_actual.responsables.length || pz.paso_actual.clave === 'despacho' ? 'text-gray-500' : 'text-amber-700'">
              <UserGroupIcon class="w-3.5 h-3.5 inline -mt-0.5" />
              {{ pz.paso_actual.responsables.length ? pz.paso_actual.responsables.join(', ') : (pz.paso_actual.clave === 'despacho' ? 'Despacho' : 'Nadie apuntado') }}
              <template v-if="pz.pasos.some(x => x.estado === 'pendiente')">
                · sigue {{ nombrePaso(pz.pasos.find(x => x.estado === 'pendiente').clave) }}
              </template>
            </p>
          </template>
        </div>

        <button
          v-if="puedeVerProduccion"
          @click="irAProduccion(filtroPieza === 'sin_arrancar' ? 'pendiente' : 'en_proceso')"
          class="w-full text-sm text-blue-600 font-semibold py-2"
        >Abrir en Producción</button>
      </template>

      <!-- ══════════════════ PASOS ══════════════════ -->
      <template v-else-if="tab === 'pasos'">
        <p class="text-[11px] text-gray-500 flex gap-1.5">
          <InformationCircleIcon class="w-4 h-4 shrink-0" />
          <span>
            <b>En proceso</b>: piezas que están en ese paso ahora. <b>Le llegan</b>: piezas que ya lo
            tienen puesto en su flujo y todavía no llegan. El ritmo y lo que tarda salen de lo que cada
            paso cerró en las últimas semanas.
          </span>
        </p>

        <p v-if="!p.pasos.length" class="text-sm text-gray-400 text-center py-6">No hay procesos configurados.</p>

        <div
          v-for="x in p.pasos" :key="x.clave"
          :class="['bg-white rounded-xl shadow-sm p-4 space-y-3', cuelloDeBotella?.clave === x.clave ? 'ring-2 ring-red-300' : '']"
        >
          <div class="flex items-center gap-2">
            <span :class="['text-xs font-semibold rounded-full px-2.5 py-0.5', clasesDeColor(x.color)]">{{ x.nombre }}</span>
            <span v-if="cuelloDeBotella?.clave === x.clave" class="text-[10px] font-semibold rounded-full px-2 py-0.5 bg-red-100 text-red-700">El más cargado</span>
            <span class="flex-1"></span>
            <span v-if="x.personas" class="text-[11px] text-gray-400">{{ plural(x.personas, 'persona', 'personas') }}</span>
          </div>

          <!-- Barra de carga: en proceso + le llegan -->
          <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden flex">
            <div class="h-full bg-blue-500" :style="{ width: (x.en_proceso / maxCargaPaso * 100) + '%' }"></div>
            <div class="h-full bg-blue-200" :style="{ width: (x.en_cola / maxCargaPaso * 100) + '%' }"></div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <button
              @click="x.en_proceso && verPiezas('empezadas', x.clave)"
              :class="['rounded-lg border border-gray-100 p-2 text-left', x.en_proceso ? 'hover:bg-gray-50' : 'cursor-default']"
            >
              <p class="text-[11px] text-gray-500">En proceso</p>
              <p class="text-lg font-bold text-blue-600 leading-tight">{{ x.en_proceso }}</p>
              <p class="text-[10px] text-gray-400">{{ plural(x.unidades_en_proceso, 'unidad', 'unidades') }}</p>
            </button>
            <div class="rounded-lg border border-gray-100 p-2">
              <p class="text-[11px] text-gray-500">Le llegan</p>
              <p class="text-lg font-bold text-gray-700 leading-tight">{{ x.en_cola }}</p>
              <p class="text-[10px] text-gray-400">{{ x.siguiente }} {{ x.siguiente === 1 ? 'llega' : 'llegan' }} apenas terminen el paso actual</p>
            </div>
          </div>

          <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
            <dt class="text-gray-500">Ritmo</dt>
            <dd class="text-right text-gray-700">{{ x.hechos_recientes ? `${String(x.ritmo_semana).replace('.', ',')} por semana` : 'Sin datos' }}</dd>
            <dt class="text-gray-500">Normalmente tarda</dt>
            <dd class="text-right text-gray-700">{{ dias1(x.dias_normales) }}</dd>
            <template v-if="x.en_proceso">
              <dt class="text-gray-500">Llevan en el paso</dt>
              <dd :class="['text-right', x.dias_normales && x.dias_promedio_actual > x.dias_normales * 1.5 ? 'text-amber-700 font-semibold' : 'text-gray-700']">
                {{ dias1(x.dias_promedio_actual) }} prom. · {{ dias1(x.dias_max_actual) }} máx.
              </dd>
            </template>
            <dt class="text-gray-500">Para ponerse al día</dt>
            <dd class="text-right font-semibold text-gray-800">
              {{ x.dias_para_ponerse_al_dia ? `≈ ${Math.round(x.dias_para_ponerse_al_dia)} días` : (x.en_proceso + x.en_cola ? 'Sin ritmo para estimar' : 'Al día') }}
            </dd>
          </dl>

          <p v-if="x.sin_responsable" class="text-[11px] text-amber-700 flex items-center gap-1">
            <ExclamationTriangleIcon class="w-3.5 h-3.5" />
            {{ plural(x.sin_responsable, 'pieza sin nadie apuntado', 'piezas sin nadie apuntado') }}
          </p>
        </div>
      </template>

      <!-- Cada pestaña lleva a su tema de la Ayuda. -->
      <button
        @click="router.push({ name: 'carga-trabajo-ayuda', query: { tema: tab } })"
        class="w-full flex items-center justify-center gap-1 text-xs font-semibold text-gray-500 py-2"
      >
        <QuestionMarkCircleIcon class="w-4 h-4" />
        ¿Qué significa cada cosa de esta pestaña?
      </button>
    </template>
  </div>
</template>
