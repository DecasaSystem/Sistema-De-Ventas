<script setup>
import IconoS from '@/components/common/IconoS.vue'
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { seVolvioAtras } from '@/router'
import { useAuthStore } from '@/stores/auth'
import { useTiposProceso } from '@/composables/useTiposProceso'
import { useRouter } from 'vue-router'
import { MagnifyingGlassIcon, Cog6ToothIcon, ArrowDownTrayIcon, MapPinIcon, CalendarIcon } from '@heroicons/vue/24/outline'
import { XMarkIcon, MapPinIcon as MapPinSolid } from '@heroicons/vue/24/solid'
import { getOrdenes, getTiendas, fijarOrden, quitarFijada } from '@/api/ordenes'
import { useRealtime } from '@/composables/useRealtime'
import { useToast } from '@/composables/useToast'
import { exportarExcel } from '@/utils/exportarExcel'
import { sinPerderElSitio, tamanoParaRecargar } from '@/utils/scroll'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

/**
 * Dónde iba el usuario la última vez que estuvo en esta lista.
 *
 * Vive fuera del componente a propósito: al entrar a una orden esta pantalla
 * se destruye, así que un `ref` de adentro se perdería. Antes, al volver, la
 * lista arrancaba en la primera página y en el tope, y si la orden estaba
 * abajo tocaba bajar otra vez todo.
 *
 * Solo se restaura si se volvió con el botón "atrás". Si el usuario entra a
 * Órdenes por el menú, espera la lista fresca y desde arriba.
 */
let dondeIba = null

const ordenes = ref([])
const loading = ref(true)
const loadingMore = ref(false)
const hasMore = ref(true)
const currentPage = ref(1)
const exportando = ref(false)

const showFilters = ref(false)

// Ordenar por lo que se entrega primero, de lo más próximo a lo más lejano.
// Solo lo que sigue pendiente: lo entregado ya no se espera.
const porEntregar = ref(false)

function alternarPorEntregar() {
  porEntregar.value = !porEntregar.value
  fetchOrdenes(1)
}
const tiendas = ref([])
const busqueda = ref('')

const filtros = ref({
  estado: '',
  tienda_id: '',
  desde: '',
  hasta: '',
  serie: '',          // '' todas · 'normales' · 'FV2' descuentos especiales
})

// Apartados: lo que lleva serie propia no gasta consecutivo de venta.
// "Normales" son las que sí lo gastan, o sea las que no tienen serie.
const APARTADOS = [
  { value: '',         label: 'Todas'         },
  { value: 'normales', label: 'Normales'      },
  { value: 'FV2',      label: 'FV2'           },
  { value: 'R',        label: 'Restauración'  },
]

// Cada apartado con su color, el mismo que ya usa esa serie en el resto
const COLOR_APARTADO = {
  FV2: 'bg-white shadow-sm text-amber-700',
  R:   'bg-white shadow-sm text-indigo-700',
}

function seleccionarApartado(serie) {
  filtros.value.serie = serie
  currentPage.value = 1
  fetchOrdenes(1)
}

const estadosOpts = [
  { value: '', label: 'Todos' },
  { value: 'borrador', label: 'Borrador' },
  { value: 'pendiente_cotizacion', label: 'Pendiente costo' },
  { value: 'pendiente_anticipo', label: 'En espera' },
  { value: 'en_produccion', label: 'En producción' },
  { value: 'listo_entrega', label: 'Listo entrega' },
  { value: 'en_camino', label: 'En camino' },
  { value: 'entregado', label: 'Entregado' },
  { value: 'cancelado', label: 'Cancelado' },
]

const sentinel = ref(null)
let observer = null

async function loadTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
}

/**
 * @param {number}  porPagina  Cuántas traer; más de 20 sirve para rehacer de
 *   una vez lo que el usuario ya había bajado con el scroll.
 */
async function fetchOrdenes(page = 1, append = false, porPagina = 20) {
  // Sin lista que mostrar, el spinner. Con lista, se recarga por debajo: si se
  // ocultara, la página se quedaría sin altura y el navegador devolvería el
  // scroll a cero.
  if (page === 1 && ! ordenes.value.length) {
    loading.value = true
  } else if (page > 1) {
    loadingMore.value = true
  }

  try {
    const params = { page }
    if (porPagina !== 20) params.per_page = porPagina
    if (filtros.value.estado) params.estado = filtros.value.estado
    if (filtros.value.tienda_id) params.tienda_id = filtros.value.tienda_id
    if (filtros.value.desde) params.desde = filtros.value.desde
    if (filtros.value.hasta) params.hasta = filtros.value.hasta
    if (filtros.value.serie) params.serie = filtros.value.serie
    if (busqueda.value) params.search = busqueda.value
    // Por lo que se entrega primero. El backend deja fuera lo entregado y lo
    // cancelado: la pregunta es qué falta por salir.
    if (porEntregar.value) params.orden = 'entrega'

    const { data } = await getOrdenes(params)

    const list = data.data ?? []
    if (append) {
      ordenes.value = [...ordenes.value, ...list]
    } else {
      ordenes.value = list
    }

    hasMore.value = data.current_page < data.last_page
    // Con varias páginas de golpe, la actual es la última que ya se tiene: si
    // no, el scroll infinito volvería a pedir las que acaba de traer.
    currentPage.value = Math.ceil(ordenes.value.length / 20) || 1
  } catch (e) {
    if (page === 1 && ! ordenes.value.length) ordenes.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

/**
 * Volver a pedir la lista sin sacar al usuario de donde estaba.
 *
 * Se piden todas las páginas que ya había bajado: devolverle solo las primeras
 * 20 haría desaparecer el sitio donde iba.
 */
async function refrescarEnElSitio() {
  await sinPerderElSitio(() =>
    fetchOrdenes(1, false, tamanoParaRecargar(currentPage.value))
  )
  setupObserver()
}

function applyFilters() {
  showFilters.value = false
  currentPage.value = 1
  fetchOrdenes(1, false)
}

function clearFilters() {
  filtros.value = { estado: '', tienda_id: '', desde: '', hasta: '', serie: '' }
  busqueda.value = ''
  showFilters.value = false
  currentPage.value = 1
  fetchOrdenes(1, false)
  setupObserver()
}

function seleccionarTienda(id) {
  filtros.value.tienda_id = id
  currentPage.value = 1
  fetchOrdenes(1, false)
  setupObserver()
}

function buscar() {
  currentPage.value = 1
  fetchOrdenes(1, false)
  setupObserver()
}

// Búsqueda en vivo: espera a que el usuario deje de escribir (debounce) para
// no disparar una petición por cada tecla.
let searchTimer = null
watch(busqueda, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(buscar, 350)
})

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  await fetchOrdenes(currentPage.value + 1, true)
}

const estadoLabel = (estado) => estadosOpts.find(e => e.value === estado)?.label ?? estado

// Trae TODAS las páginas que coincidan con los filtros actuales y las exporta.
async function exportarExcelOrdenes() {
  if (exportando.value) return
  exportando.value = true
  try {
    const baseParams = {}
    if (filtros.value.estado)    baseParams.estado    = filtros.value.estado
    if (filtros.value.tienda_id) baseParams.tienda_id = filtros.value.tienda_id
    if (filtros.value.desde)     baseParams.desde     = filtros.value.desde
    if (filtros.value.hasta)     baseParams.hasta     = filtros.value.hasta
    if (filtros.value.serie)     baseParams.serie     = filtros.value.serie
    if (busqueda.value)          baseParams.search    = busqueda.value

    let page = 1
    let lastPage = 1
    const todas = []
    do {
      const { data } = await getOrdenes({ ...baseParams, page })
      todas.push(...(data.data ?? []))
      lastPage = data.last_page ?? 1
      page++
    } while (page <= lastPage)

    if (!todas.length) {
      toast.error('No hay órdenes para exportar con los filtros actuales.')
      return
    }

    const filas = todas.map(o => ({
      'N° orden':         o.referencia ?? o.numero_orden ?? o.id,
      'Estado':           estadoLabel(o.estado),
      'Tipo':             o.tipo === 'restauracion' ? 'Restauración' : 'Venta',
      'Cliente':          o.cliente?.nombre ?? '',
      'Tienda':           o.tienda?.nombre ?? '',
      'Fecha':            fechaVenta(o) ? new Date(fechaVenta(o)).toLocaleDateString('es-CO') : '',
      'Valor total':      Number(o.valor_total) || 0,
      'Saldo pendiente':  Number(o.saldo_pendiente) || 0,
      'Atrasado':         o.atrasado ? 'Sí' : 'No',
    }))
    exportarExcel(filas, { nombreArchivo: 'ordenes_decasa', hoja: 'Órdenes' })
  } catch (e) {
    toast.error('No se pudo generar el Excel. Intenta de nuevo.')
  } finally {
    exportando.value = false
  }
}

function setupObserver() {
  if (observer) observer.disconnect()

  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && hasMore.value && !loadingMore.value) {
      loadMore()
    }
  }, { rootMargin: '200px' })

  nextTick(() => {
    if (sentinel.value) observer.observe(sentinel.value)
  })
}

/**
 * Fijar o soltar una orden. Se pinta de una y se reordena al confirmar: en
 * la lista paginada una fijada puede venir de otra pagina, asi que hay que
 * volver a pedirla para que suba de verdad.
 */
async function toggleFijada(o) {
  const antes = !!o.fijada
  o.fijada = !antes
  try {
    if (antes) await quitarFijada(o.id)
    else       await fijarOrden(o.id)
    // La orden sube arriba, pero la pantalla se queda donde estaba: fijar algo
    // no es motivo para mandar al usuario al principio de la lista.
    await refrescarEnElSitio()
  } catch {
    o.fijada = antes
    toast.error('No se pudo cambiar la fijacion.')
  }
}

function goToDetalle(id) {
  router.push({ name: 'orden-detalle', params: { id } })
}

// La fecha de la venta. En un borrador `created_at` es el día en que el
// vendedor lo empezó, no el día en que se cerró: mostrar esa sería contradecir
// el lugar que la orden ocupa en la lista, que va por la de confirmación.
function fechaVenta(o) {
  return o?.confirmada_en || o?.created_at
}

function formatFecha(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })
}

/**
 * ¿Esta orden llegó por compartirla, y no porque la vendiera uno?
 *
 * A un supervisor le salen todas, y marcarlas todas seria ruido: el aviso es
 * para el vendedor, que solo ve las suyas y las que le tocan.
 */
function esDeOtro(o) {
  return auth.esVendedorLimitado && o.vendedor_id && o.vendedor_id !== auth.usuario?.id
}

// Los pasos del taller salen del catalogo; el de despacho es propio de aqui.
const { cargar: cargarTipos, nombre: nombreProceso, clases: clasesProceso } = useTiposProceso()
const PASO_FIJO = {
  pendiente_despachador: { text: 'Lista p/ despacho', cls: 'bg-purple-100 text-purple-700' },
}
const PASO_LABEL = new Proxy({}, {
  get: (_, k) => PASO_FIJO[k] ?? { text: nombreProceso(String(k)), cls: clasesProceso(String(k)) },
})

function pasoInfo(paso) {
  return PASO_LABEL[paso] ?? null
}

const { listen } = useRealtime()

onMounted(async () => {
  cargarTipos()   // nombres y colores de los procesos
  await loadTiendas()

  if (seVolvioAtras() && dondeIba) {
    // Se devuelve la pantalla tal como estaba, sin volver a pedir nada: la
    // lista entera, los filtros y la altura. Pedirla de nuevo la reordenaría
    // y el usuario perdería el sitio igual.
    ordenes.value     = dondeIba.ordenes
    currentPage.value = dondeIba.page
    hasMore.value     = dondeIba.hasMore
    filtros.value     = { ...dondeIba.filtros }
    busqueda.value    = dondeIba.busqueda
    loading.value     = false
    // Dos pasadas: con una sola, el navegador todavía no ha medido las
    // tarjetas y recorta el salto a la altura que tenía la página vacía.
    const y = dondeIba.scrollY
    await nextTick()
    requestAnimationFrame(() => {
      window.scrollTo({ top: y, behavior: 'instant' })
      requestAnimationFrame(() => window.scrollTo({ top: y, behavior: 'instant' }))
    })
  } else {
    dondeIba = null
    await fetchOrdenes(1, false)
  }

  setupObserver()

  // Que otra persona mueva una orden no puede mandarte al principio de la
  // lista mientras estás mirando: se refresca por debajo, sin moverte.
  listen('ordenes', 'orden.actualizada', () => refrescarEnElSitio())
})

onUnmounted(() => {
  if (observer) observer.disconnect()
  clearTimeout(searchTimer)

  // Se guarda todo lo que hace falta para dejar la pantalla igual: las órdenes
  // ya cargadas, por qué página iba, los filtros y la altura del scroll.
  dondeIba = {
    ordenes:  ordenes.value,
    page:     currentPage.value,
    hasMore:  hasMore.value,
    filtros:  { ...filtros.value },
    busqueda: busqueda.value,
    scrollY:  window.scrollY,
  }
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex flex-wrap items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Órdenes</h2>
      <button
        @click="exportarExcelOrdenes"
        :disabled="exportando"
        title="Descargar Excel (todas las órdenes del filtro actual)"
        class="text-sm text-white font-medium px-3 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-50 transition-colors"
      >
        <ArrowDownTrayIcon class="w-4 h-4 inline-block mr-1" />
        {{ exportando ? 'Generando...' : 'Excel' }}
      </button>
      <button
        @click="alternarPorEntregar"
        :title="porEntregar ? 'Volver al orden normal' : 'Ver primero lo que se entrega antes'"
        :class="['text-sm font-medium px-3 py-1.5 rounded-lg border transition-colors',
          porEntregar
            ? 'bg-amber-500 text-white border-amber-500 hover:bg-amber-600'
            : 'text-amber-700 border-amber-200 hover:bg-amber-50']"
      >
        <CalendarIcon class="w-4 h-4 inline-block mr-1" />
        Por entregar
      </button>
      <button
        @click="showFilters = !showFilters"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors"
      >
        <XMarkIcon v-if="showFilters" class="w-4 h-4 inline-block mr-1" />
        <Cog6ToothIcon v-else class="w-4 h-4 inline-block mr-1" />
        {{ showFilters ? 'Cerrar' : 'Filtros' }}
      </button>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="buscar"
        placeholder="Buscar por cliente o N° de orden..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Apartados: normales vs órdenes con descuento especial -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        v-for="a in APARTADOS"
        :key="a.value"
        @click="seleccionarApartado(a.value)"
        :class="['flex-1 py-1.5 text-[13px] font-medium rounded-lg transition-colors whitespace-nowrap',
          filtros.serie === a.value
            ? (COLOR_APARTADO[a.value] ?? 'bg-white shadow-sm text-gray-800')
            : 'text-gray-500']"
      >{{ a.label }}</button>
    </div>

    <p v-if="filtros.serie === 'FV2'" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
      Órdenes con descuento especial. Llevan numeración FV2-N propia, no gastan
      consecutivo de orden y sí generan comisión.
    </p>

    <p v-if="filtros.serie === 'R'" class="text-xs text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-lg px-3 py-2">
      Restauraciones: trabajos sobre un mueble que ya es del cliente. Llevan
      numeración R-N propia, no gastan consecutivo de venta y sí generan
      comisión. Si la orden además lleva algo vendido, va con número normal.
    </p>

    <!-- Filtro rápido por tienda -->
    <div v-if="tiendas.length" class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
      <button
        @click="seleccionarTienda('')"
        :class="[
          'shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          filtros.tienda_id === ''
            ? 'bg-blue-600 text-white border-blue-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'
        ]"
      >
        Todas
      </button>
      <button
        v-for="t in tiendas"
        :key="t.id"
        @click="seleccionarTienda(t.id)"
        :class="[
          'shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
          filtros.tienda_id === t.id
            ? 'bg-blue-600 text-white border-blue-600'
            : 'bg-white text-gray-600 border-gray-300 hover:border-blue-400'
        ]"
      >
        {{ t.nombre }}
      </button>
    </div>

    <!-- Panel de filtros -->
    <div v-if="showFilters" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <!-- Estado -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
        <select
          v-model="filtros.estado"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option v-for="e in estadosOpts" :key="e.value" :value="e.value">{{ e.label }}</option>
        </select>
      </div>

      <!-- Tienda -->
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
        <select
          v-model="filtros.tienda_id"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
      </div>

      <!-- Fechas -->
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Desde</label>
          <input
            v-model="filtros.desde"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Hasta</label>
          <input
            v-model="filtros.hasta"
            type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
      </div>

      <!-- Botones -->
      <div class="flex gap-2">
        <button
          @click="clearFilters"
          class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2 text-sm font-semibold hover:bg-gray-200 transition-colors"
        >Limpiar</button>
        <button
          @click="applyFilters"
          class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 transition-colors"
        >Aplicar</button>
      </div>
    </div>

    <!-- Loading inicial -->
    <AppSpinner v-if="loading" />

    <!-- Empty state -->
    <EmptyState
      v-else-if="ordenes.length === 0"
      :message="busqueda ? 'No se encontraron órdenes.' : 'No hay órdenes registradas.'"
    />

    <!-- Lista de órdenes -->
    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="o in ordenes"
          :key="o.id"
          @click="goToDetalle(o.id)"
          :class="['rounded-xl shadow-sm p-4 cursor-pointer transition-colors active:bg-blue-100',
            o.fijada
              ? 'bg-amber-50 border-l-4 border-amber-400 hover:bg-amber-100/70'
              : 'bg-white hover:bg-blue-50']"
        >
          <div class="flex justify-between items-start gap-2">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1 flex-wrap">
                <button
                  type="button"
                  @click.stop="toggleFijada(o)"
                  :class="['flex-shrink-0 transition-colors', o.fijada ? 'text-amber-500' : 'text-gray-300 hover:text-amber-400']"
                  :title="o.fijada ? 'Quitar de arriba' : 'Fijar arriba'"
                >
                  <MapPinSolid v-if="o.fijada" class="w-4 h-4" />
                  <MapPinIcon v-else class="w-4 h-4" />
                </button>
                <span class="font-semibold text-sm text-gray-800">{{ o.referencia ?? ('#' + (o.numero_orden ?? o.id)) }}</span>
                <!-- Cada serie dice lo suyo: antes cualquiera ponía "Descuento",
                     y una restauración no lo es. -->
                <span
                  v-if="o.serie === 'R'"
                  class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700"
                >Restauración</span>
                <span
                  v-else-if="o.serie"
                  class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700"
                >Descuento</span>
                <BadgeEstado :estado="o.estado" />
                <span
                  v-if="o.atrasado"
                  class="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700"
                >⚠ Atrasado</span>
                <span
                  v-if="o.tipo === 'restauracion'"
                  class="text-xs font-semibold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700"
                >Restauración</span>
              </div>
              <p class="text-sm text-gray-600 truncate">{{ o.cliente?.nombre }}</p>
              <p class="text-xs text-gray-400 mt-0.5">{{ o.tienda?.nombre }} · {{ formatFecha(fechaVenta(o)) }}</p>
              <!-- Una venta compartida le sale a todos los que cobran por ella.
                   Sin decir de quién es, a uno le aparecen ordenes que no
                   vendió y no entiende por qué están ahí. -->
              <p v-if="esDeOtro(o)" class="text-xs text-emerald-600 mt-0.5 font-medium">
                🤝 Compartida por {{ o.vendedor?.nombre }}
              </p>
              <span
                v-if="o.paso_produccion_actual && pasoInfo(o.paso_produccion_actual)"
                :class="['inline-block mt-1.5 text-xs font-semibold px-2 py-0.5 rounded-full', pasoInfo(o.paso_produccion_actual).cls]"
              >
                En producción: {{ pasoInfo(o.paso_produccion_actual).text }}
              </span>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-sm font-semibold text-gray-700"><MoneyDisplay :amount="o.valor_total" /></p>
              <p
                v-if="o.saldo_pendiente > 0"
                class="text-xs font-medium text-red-500 mt-0.5"
              >
                Resta <MoneyDisplay :amount="o.saldo_pendiente" />
              </p>
              <p v-else class="text-xs font-medium text-green-500 mt-0.5">Pagada</p>
            </div>
          </div>
        </li>
      </ul>

      <!-- Sentinel para scroll infinito -->
      <div ref="sentinel" class="py-4 text-center">
        <div v-if="loadingMore" class="flex items-center gap-2 text-sm text-gray-400"><IconoS class="w-4 h-4" />Cargando más...</div>
        <div v-else-if="!hasMore" class="text-xs text-gray-300">No hay más órdenes.</div>
      </div>
    </template>
  </div>
</template>
