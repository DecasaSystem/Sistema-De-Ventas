<script setup>
/**
 * Productos: lo que hay en Inventario, cada uno con su receta (cuánto cuesta fabricarlo).
 *
 * El producto se crea una sola vez, en Inventario, y aparece aquí con su foto, su
 * categoría y su precio. Los que todavía no tienen materiales quedan "en espera". Las
 * fichas que vinieron del Excel y no se han enlazado a un producto salen en "Sin
 * producto", con una sugerencia del producto al que se parecen.
 */
import { ref, computed, onMounted, onActivated } from 'vue'
import { MagnifyingGlassIcon, ChevronRightIcon, ExclamationTriangleIcon, PhotoIcon, SparklesIcon, LinkIcon } from '@heroicons/vue/24/outline'
import { getRecetasProductos, vincularProducto } from '@/api/fichas'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import AppSpinner from '@/components/common/AppSpinner.vue'
import IconoS from '@/components/common/IconoS.vue'
import { useCostos } from './useCostos'

const emit = defineEmits(['abrir', 'producto', 'cargado'])

const toast = useToast()
const { formatPeso, formatFactor, margenDe, COLOR_MARGEN, TEXTO_MARGEN } = useCostos()

const datos     = ref({ productos: [], sueltas: [], resumen: {} })
const cargando  = ref(false)
const busqueda  = ref('')
const categoria = ref('')
const filtro    = ref('todos')   // 'todos' | 'sin_receta' | 'revisar' | 'sueltas'

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getRecetasProductos()
    datos.value = data
    emit('cargado', data)
  } catch {
    toast.error('No se pudieron cargar los productos.')
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
// Vuelve de otra pestaña (vive en KeepAlive): pudieron cambiar precios o tarifas
let primeraVez = true
onActivated(() => { if (primeraVez) { primeraVez = false; return } cargar() })
defineExpose({ cargar })

const resumen = computed(() => datos.value.resumen ?? {})

// Solo las categorías que tienen algo que mostrar con el filtro de arriba
const categorias = computed(() => [...new Set(datos.value.productos
  .filter(p => (filtro.value === 'no_fabricado') === (p.estado === 'no_fabricado'))
  .map(p => p.categoria).filter(Boolean))].sort())

function coincide(texto) {
  const q = busqueda.value.trim().toLowerCase()
  return !q || String(texto ?? '').toLowerCase().includes(q)
}

const grupos = computed(() => {
  // "En espera" junta los que no tienen nada y los que tienen medidas sin receta
  // Los importados y comprados no se fabrican: solo salen en su propio filtro
  const enFiltro = p => (filtro.value === 'todos' && p.estado !== 'no_fabricado') || p.estado === filtro.value
    || (filtro.value === 'sin_receta' && p.estado === 'incompleta')
  const lista = datos.value.productos.filter(p =>
    enFiltro(p)
    && (!categoria.value || p.categoria === categoria.value)
    && (coincide(p.nombre) || coincide(p.categoria)))
  const mapa = new Map()
  for (const p of lista) {
    const c = p.categoria || 'Sin categoría'
    if (!mapa.has(c)) mapa.set(c, [])
    mapa.get(c).push(p)
  }
  return [...mapa.entries()]
})

const sueltas = computed(() => datos.value.sueltas.filter(f => coincide(f.nombre) || coincide(f.categoria)))

// Lo que se muestra a la derecha de un producto: el costo de su receta; con medidas, el
// de la medida más barata que ya tiene receta ("desde"). La receta general de un producto
// con medidas no es el costo de ninguna, así que no se muestra aquí.
function costoDe(p) {
  if (!p.versiones.length) return p.fichas[0] ? { ficha: p.fichas[0], precio: p.precio_base } : null
  const conReceta = p.versiones.filter(v => v.fichas[0]).sort((a, b) => a.fichas[0].costo_total - b.fichas[0].costo_total)
  return conReceta[0] ? { ficha: conReceta[0].fichas[0], precio: conReceta[0].precio, desde: p.versiones.length > 1 } : null
}

function versionesConReceta(p) {
  return p.versiones.filter(v => v.fichas.length).length
}

// Un producto sin versiones con una sola receta abre la ficha directo; los demás, su panel
function abrir(p) {
  if (!p.no_fabricado && !p.versiones.length && p.fichas.length === 1) emit('abrir', p.fichas[0].id)
  else emit('producto', p.id)
}

// ── Enlazar una ficha suelta con lo que sugiere ──────────────────────────────
const enlazando = ref(null)

async function enlazar(f, s) {
  enlazando.value = f.id
  try {
    await vincularProducto(f.id, s.producto_id, s.config_id)
    toast.success(`Enlazada a ${s.nombre}${s.version ? ' · ' + s.version : ''}`)
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo enlazar la ficha.')
  } finally {
    enlazando.value = null
  }
}

const ESTADO = {
  sin_receta: { texto: 'En espera', clase: 'bg-gray-100 text-gray-500' },
  revisar:    { texto: 'Revisar',   clase: 'bg-amber-100 text-amber-800' },
}
const ORIGEN = { importado: 'Importado', comprado: 'Comprado' }
</script>

<template>
  <div class="space-y-3">
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
      <input v-model="busqueda" type="search" placeholder="Buscar producto o categoría…"
        class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
    </div>

    <!-- Cuántos hay en cada estado: tocar uno filtra -->
    <div v-if="resumen.productos" class="grid grid-cols-3 gap-2">
      <button @click="filtro = filtro === 'sin_receta' ? 'todos' : 'sin_receta'"
        :class="['bg-white rounded-xl shadow-sm p-3 text-left border-2', filtro === 'sin_receta' ? 'border-blue-500' : 'border-transparent']">
        <p class="text-lg font-bold text-gray-800 tabular-nums">{{ (resumen.sin_receta ?? 0) + (resumen.incompleta ?? 0) }}</p>
        <p class="text-xs text-gray-500">En espera</p>
      </button>
      <button @click="filtro = filtro === 'revisar' ? 'todos' : 'revisar'"
        :class="['bg-white rounded-xl shadow-sm p-3 text-left border-2', filtro === 'revisar' ? 'border-amber-500' : 'border-transparent']">
        <p class="text-lg font-bold text-amber-700 tabular-nums">{{ resumen.revisar }}</p>
        <p class="text-xs text-gray-500">Por revisar</p>
      </button>
      <button @click="filtro = filtro === 'lista' ? 'todos' : 'lista'"
        :class="['bg-white rounded-xl shadow-sm p-3 text-left border-2', filtro === 'lista' ? 'border-green-500' : 'border-transparent']">
        <p class="text-lg font-bold text-green-700 tabular-nums">{{ resumen.lista }}</p>
        <p class="text-xs text-gray-500">Con receta</p>
      </button>
    </div>

    <div class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4 [scrollbar-width:none]">
      <button v-if="resumen.sueltas" @click="filtro = filtro === 'sueltas' ? 'todos' : 'sueltas'"
        :class="['flex-shrink-0 flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-colors',
          filtro === 'sueltas' ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800 hover:bg-blue-200']">
        <LinkIcon class="w-3.5 h-3.5" />Fichas sin producto · {{ resumen.sueltas }}
      </button>
      <button v-if="resumen.no_fabricados" @click="filtro = filtro === 'no_fabricado' ? 'todos' : 'no_fabricado'"
        :class="['flex-shrink-0 px-3 py-1 rounded-full text-xs font-semibold transition-colors',
          filtro === 'no_fabricado' ? 'bg-purple-600 text-white' : 'bg-purple-100 text-purple-700 hover:bg-purple-200']">
        Importados y comprados · {{ resumen.no_fabricados }}
      </button>
      <template v-if="filtro !== 'sueltas'">
        <button @click="categoria = ''"
          :class="['flex-shrink-0 px-3 py-1 rounded-full text-xs font-semibold transition-colors', categoria === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
          Todas
        </button>
        <button v-for="c in categorias" :key="c" @click="categoria = c"
          :class="['flex-shrink-0 px-3 py-1 rounded-full text-xs font-semibold transition-colors capitalize', categoria === c ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
          {{ c.toLowerCase() }}
        </button>
      </template>
    </div>

    <AppSpinner v-if="cargando && !datos.productos.length && !datos.sueltas.length" />

    <!-- Fichas sin producto -->
    <template v-else-if="filtro === 'sueltas'">
      <p class="text-xs text-gray-500 px-1">
        Fichas que no son la receta de ningún producto de Inventario (casi todas vinieron del Excel).
        Enlázalas para que su costo aparezca en el producto. El cotizador las sigue usando igual.
      </p>
      <p v-if="!sueltas.length" class="text-center py-12 text-sm text-gray-400">Ninguna ficha sin producto coincide.</p>
      <div v-else class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
        <div v-for="f in sueltas" :key="f.id" class="px-3 py-3 space-y-2">
          <button @click="emit('abrir', f.id)" class="w-full flex items-center gap-3 text-left">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ f.nombre }}</p>
              <p class="text-xs text-gray-400 truncate capitalize">
                {{ f.categoria?.toLowerCase() }}<template v-if="f.producto_apagado"> · su producto está apagado</template>
              </p>
            </div>
            <span class="text-sm font-bold text-gray-900 tabular-nums flex-shrink-0">{{ formatPeso(f.costo_total) }}</span>
            <ChevronRightIcon class="w-4 h-4 text-gray-300 flex-shrink-0" />
          </button>
          <div v-for="s in f.sugerencias.slice(0, 2)" :key="`${s.producto_id}-${s.config_id}`"
            class="flex items-center gap-2 rounded-lg bg-gray-50 px-2.5 py-1.5">
            <SparklesIcon class="w-3.5 h-3.5 text-blue-500 flex-shrink-0" />
            <p class="flex-1 min-w-0 text-xs text-gray-600 truncate">
              ¿Es <strong class="text-gray-800">{{ s.nombre }}<template v-if="s.version"> · {{ s.version }}</template></strong>?
            </p>
            <button @click="enlazar(f, s)" :disabled="enlazando === f.id"
              class="flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 disabled:opacity-50 flex-shrink-0">
              <IconoS v-if="enlazando === f.id" class="w-3.5 h-3.5" />Enlazar
            </button>
          </div>
        </div>
      </div>
    </template>

    <div v-else-if="!grupos.length" class="text-center py-12 text-sm text-gray-400">
      <template v-if="!datos.productos.length">No hay productos activos en Inventario.</template>
      <template v-else>Ningún producto coincide con el filtro.</template>
    </div>

    <!-- Productos de Inventario -->
    <template v-else>
      <section v-for="[cat, lista] in grupos" :key="cat">
        <h2 class="text-sm font-semibold text-gray-500 mb-1.5 px-1 capitalize">
          {{ cat.toLowerCase() }} <span class="font-normal text-gray-400">{{ lista.length }}</span>
        </h2>
        <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
          <button v-for="p in lista" :key="p.id" @click="abrir(p)"
            class="w-full flex items-center gap-3 px-3 py-3 hover:bg-gray-50 text-left">
            <img v-if="p.foto_url" :src="cloudinaryOpt(p.foto_url, 96)" alt=""
              class="w-11 h-11 rounded-lg object-cover flex-shrink-0 border border-gray-100" />
            <div v-else class="w-11 h-11 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 text-gray-300">
              <PhotoIcon class="w-5 h-5" />
            </div>

            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ p.nombre }}</p>
              <p class="text-xs text-gray-400 truncate">
                <template v-if="p.versiones.length">
                  {{ p.versiones.length }} {{ p.versiones.length === 1 ? 'medida' : 'medidas' }}
                  · <span :class="p.faltan ? 'text-amber-700 font-medium' : 'text-green-700'">{{ versionesConReceta(p) }} con receta</span>
                </template>
                <template v-else>venta {{ formatPeso(p.precio_base) }}</template>
              </p>
            </div>

            <div class="text-right flex-shrink-0">
              <template v-if="costoDe(p) && !p.no_fabricado">
                <!-- Costo de fabricación (de la receta); el precio de venta es el de Inventario, a la izquierda -->
                <p class="text-[11px] text-gray-400 leading-none">{{ costoDe(p).desde ? 'fabricar desde' : 'fabricar' }}</p>
                <p class="text-sm font-bold text-gray-900 tabular-nums">{{ formatPeso(costoDe(p).ficha.costo_total) }}</p>
                <div class="flex justify-end gap-1 mt-0.5">
                  <span v-if="p.faltan" class="text-[11px] font-semibold rounded-full px-1.5 bg-gray-100 text-gray-600"
                    :title="`Faltan ${p.faltan} medidas por su receta`">
                    faltan {{ p.faltan }}
                  </span>
                  <span v-if="p.estado === 'revisar' || p.repetida" class="inline-flex items-center gap-0.5 text-[11px] font-semibold rounded-full px-1.5 bg-amber-100 text-amber-800"
                    :title="p.repetida ? 'Tiene más de una receta' : 'Tiene ítems para revisar'">
                    <ExclamationTriangleIcon class="w-3 h-3" />
                  </span>
                  <span v-if="margenDe(costoDe(p).ficha.costo_total, costoDe(p).precio)"
                    :class="['text-[11px] font-semibold rounded-full px-1.5 tabular-nums', COLOR_MARGEN[margenDe(costoDe(p).ficha.costo_total, costoDe(p).precio).nivel]]"
                    :title="TEXTO_MARGEN[margenDe(costoDe(p).ficha.costo_total, costoDe(p).precio).nivel]">
                    {{ formatFactor(margenDe(costoDe(p).ficha.costo_total, costoDe(p).precio).factor) }}
                  </span>
                </div>
              </template>
              <span v-else-if="p.no_fabricado" class="text-[11px] font-semibold rounded-full px-2 py-0.5 bg-purple-100 text-purple-700">
                {{ ORIGEN[p.no_fabricado.origen] }}
              </span>
              <span v-else :class="['text-[11px] font-semibold rounded-full px-2 py-0.5', ESTADO.sin_receta.clase]">{{ ESTADO.sin_receta.texto }}</span>
            </div>
            <ChevronRightIcon class="w-4 h-4 text-gray-300 flex-shrink-0" />
          </button>
        </div>
      </section>
    </template>
  </div>
</template>
