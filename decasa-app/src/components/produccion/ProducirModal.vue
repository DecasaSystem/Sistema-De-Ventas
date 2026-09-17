<script setup>
import { ref, computed, watch } from 'vue'
import { XMarkIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { producir } from '@/api/produccion'
import { getReservaInventario, getReservaInfo } from '@/api/reserva'
import { getVariantes } from '@/api/inventario'
import api from '@/api'
import { marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'
import { SPECS_TEMPLATES, resolverCategoria } from '@/constants/specsConfig'
import { useTiposProceso } from '@/composables/useTiposProceso'
import { useToast } from '@/composables/useToast'
import ComboInput from '@/components/common/ComboInput.vue'
import InputPesos from '@/components/common/InputPesos.vue'

const props = defineProps({ show: Boolean })
const emit  = defineEmits(['close', 'creada'])

const toast = useToast()
const { tipos: tiposProceso, cargar: cargarTipos, nombre: nombreProceso } = useTiposProceso()
cargarTipos()

const PROCESOS = computed(() =>
  [...tiposProceso.value]
    .filter(t => t.activo && t.clave !== 'despacho')
    .sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0))
    .map(t => ({ tipo: t.clave, label: t.nombre, desc: t.descripcion ?? '' }))
)

const fabricaId = ref(null)
getReservaInfo().then(({ data }) => { fabricaId.value = data.id }).catch(() => {})

// ── Estado del formulario ────────────────────────────────────────────────────
const modo = ref('catalogo')          // 'catalogo' | 'nuevo'
const guardando = ref(false)
const error = ref('')

// Catálogo
const busqueda   = ref('')
const resultados = ref([])
const buscando   = ref(false)
const productoSel = ref(null)         // fila de /reserva/inventario

// Nuevo producto
const nuevo = ref({ nombre: '', categoria: '', precio_base: '', es_tapizado: false, tiene_tallas: false })
const categorias = ref([])
api.get('/productos/categorias').then(({ data }) => { categorias.value = data.filter(Boolean) }).catch(() => {})

const cantidad = ref(1)
const fechaCompromiso = ref('')

// Variante de tela / talla
const variantesCat = ref([])          // telas ya registradas (catálogo)
const varianteSel  = ref('')          // '' | id | 'nueva'
const telaNueva    = ref({ marca: '', marca_tela: '', nombre_color: '', medida: '', precio_variante: '' })

// Medidas configurables (solo catálogo con variante-configs)
const configGrupos  = ref([])
const comboConfigId = ref(null)

// Especificaciones
const specs = ref({})
const specsNotas = ref('')

// Pasos
const pasosSel = ref([])              // [{ tipo_proceso, orden }]

// ── Derivados ────────────────────────────────────────────────────────────────
const esTapizado = computed(() =>
  modo.value === 'nuevo' ? nuevo.value.es_tapizado : !!productoSel.value?.producto?.es_tapizado)
const tieneTallas = computed(() =>
  modo.value === 'nuevo' ? nuevo.value.tiene_tallas : !!productoSel.value?.producto?.tiene_tallas)

const nombreProd = computed(() =>
  modo.value === 'nuevo' ? nuevo.value.nombre : (productoSel.value?.producto?.nombre ?? ''))
const categoriaProd = computed(() =>
  modo.value === 'nuevo' ? nuevo.value.categoria : (productoSel.value?.producto?.categoria ?? ''))

const template = computed(() =>
  SPECS_TEMPLATES[resolverCategoria(nombreProd.value, categoriaProd.value)] ?? SPECS_TEMPLATES.generico)

const tiposTelaOpc = computed(() =>
  telaNueva.value.marca && telaNueva.value.marca !== 'Otro' ? tiposTelaDeM(telaNueva.value.marca) : [])
const coloresOpc = computed(() =>
  telaNueva.value.marca && telaNueva.value.marca_tela &&
  telaNueva.value.marca !== 'Otro' && telaNueva.value.marca_tela !== 'Otro'
    ? coloresDeTela(telaNueva.value.marca, telaNueva.value.marca_tela) : [])

// ── Tela contra inventario ───────────────────────────────────────────────────
// La tela con la que se va a producir: la variante elegida del catálogo o la
// nueva que se está escribiendo. Marca · tipo · color, como está en Telas.
const telaElegida = computed(() => {
  if (!esTapizado.value) return null
  let t = null
  if (varianteSel.value === 'nueva') {
    t = { marca: telaNueva.value.marca, tipo: telaNueva.value.marca_tela, color: telaNueva.value.nombre_color }
  } else if (varianteSel.value) {
    const v = variantesCat.value.find(x => x.id === varianteSel.value)
    if (v) t = { marca: v.marca, tipo: v.marca_tela, color: v.nombre_color }
  }
  if (!t || !t.marca || !t.tipo || !t.color || [t.marca, t.tipo, t.color].includes('Otro')) return null
  return t
})

// { metros_necesarios, metros, suficiente } o null si el servidor no sabe
// cuánto gasta el producto (función apagada, producto nuevo o sin metros).
const necesidadTela = ref(null)
let pedidoTela = 0

watch(
  () => [telaElegida.value, productoSel.value?.producto_id, comboConfigId.value, cantidad.value, modo.value],
  async () => {
    const t = telaElegida.value
    const productoId = modo.value === 'catalogo' ? productoSel.value?.producto_id : null
    if (!t || !productoId) { necesidadTela.value = null; return }
    const n = ++pedidoTela
    try {
      const { data } = await api.get('/inventario-telas/validar', {
        params: {
          marca: t.marca, tipo: t.tipo, color: t.color,
          producto_id: productoId, config_id: comboConfigId.value || undefined,
          cantidad: Math.max(1, Number(cantidad.value) || 1),
        },
      })
      if (n !== pedidoTela) return
      necesidadTela.value = data.metros_necesarios != null ? data : null
    } catch {
      if (n === pedidoTela) necesidadTela.value = null
    }
  },
  { deep: true },
)

const listoParaProducir = computed(() => {
  if (modo.value === 'catalogo' && !productoSel.value) return false
  if (modo.value === 'nuevo' && (!nuevo.value.nombre.trim() || nuevo.value.precio_base === '')) return false
  if (!cantidad.value || cantidad.value < 1) return false
  // Los pasos no son obligatorios: sin ellos la pieza queda pendiente y se
  // le arma el flujo después desde el tablero.
  return true
})

// ── Búsqueda de catálogo ─────────────────────────────────────────────────────
let buscarTimer
watch(busqueda, () => {
  clearTimeout(buscarTimer)
  buscarTimer = setTimeout(buscarCatalogo, 300)
})

async function buscarCatalogo() {
  const q = busqueda.value.trim()
  if (q.length < 2) { resultados.value = []; return }
  buscando.value = true
  try {
    const { data } = await getReservaInventario(q, 1, '')
    resultados.value = data.data ?? []
  } catch { resultados.value = [] }
  finally { buscando.value = false }
}

async function elegirProducto(fila) {
  productoSel.value = fila
  resultados.value = []
  busqueda.value = fila.producto?.nombre ?? ''
  varianteSel.value = ''
  comboConfigId.value = null
  variantesCat.value = []
  configGrupos.value = []
  if (fila.producto?.es_tapizado || fila.producto?.tiene_tallas) {
    try {
      const { data } = await getVariantes(fila.producto_id, fabricaId.value, true)
      variantesCat.value = data ?? []
    } catch {}
  }
  try {
    const { data } = await api.get(`/productos/${fila.producto_id}/variante-configs`, {
      params: fabricaId.value ? { tienda_id: fabricaId.value } : {}, silencioso: true,
    })
    configGrupos.value = (data ?? []).filter(g => g.items?.length)
  } catch {}
}

function limpiarProducto() {
  productoSel.value = null
  busqueda.value = ''
  variantesCat.value = []
  configGrupos.value = []
  varianteSel.value = ''
  comboConfigId.value = null
}

// ── Pasos ────────────────────────────────────────────────────────────────────
function togglePaso(tipo) {
  const i = pasosSel.value.findIndex(p => p.tipo_proceso === tipo)
  if (i !== -1) {
    pasosSel.value.splice(i, 1)
    pasosSel.value = pasosSel.value.map((p, idx) => ({ ...p, orden: idx + 1 }))
  } else {
    pasosSel.value.push({ tipo_proceso: tipo, orden: pasosSel.value.length + 1 })
  }
}
const ordenDe = (tipo) => pasosSel.value.find(p => p.tipo_proceso === tipo)?.orden ?? null
const labelProceso = (t) => nombreProceso(String(t)) ?? t

// ── Etiqueta legible de la variante elegida ──────────────────────────────────
function detalleVariante() {
  const partes = []
  if (varianteSel.value === 'nueva') {
    partes.push(telaNueva.value.marca, telaNueva.value.marca_tela, telaNueva.value.nombre_color, telaNueva.value.medida)
  } else if (varianteSel.value) {
    const v = variantesCat.value.find(x => x.id === varianteSel.value)
    if (v) partes.push(v.marca, v.marca_tela, v.nombre_color, v.medida)
  }
  if (comboConfigId.value) {
    for (const g of configGrupos.value) {
      const it = g.items.find(i => i.id === comboConfigId.value)
      if (it) { partes.push(it.opcion_nombre); break }
    }
  }
  return partes.filter(Boolean).join(' · ') || null
}

// ── Enviar ───────────────────────────────────────────────────────────────────
async function enviar() {
  error.value = ''
  if (!listoParaProducir.value) { error.value = 'Completa el producto y la cantidad.'; return }

  const specsLimpias = {}
  for (const [k, v] of Object.entries(specs.value)) {
    if (v !== null && v !== undefined && String(v).trim() !== '') specsLimpias[k] = v
  }
  if (specsNotas.value.trim()) specsLimpias.notas = specsNotas.value.trim()

  const payload = {
    modo: modo.value,
    cantidad: cantidad.value,
    fecha_compromiso: fechaCompromiso.value || null,
    pasos: pasosSel.value,
    specs: Object.keys(specsLimpias).length ? specsLimpias : null,
    variante_detalle: detalleVariante(),
  }

  if (modo.value === 'catalogo') {
    payload.producto_id = productoSel.value.producto_id
  } else {
    payload.nuevo = {
      nombre: nuevo.value.nombre.trim(),
      categoria: nuevo.value.categoria?.trim() || null,
      precio_base: Number(nuevo.value.precio_base) || 0,
      es_tapizado: nuevo.value.es_tapizado,
      tiene_tallas: nuevo.value.tiene_tallas,
    }
  }

  if (varianteSel.value === 'nueva') {
    const t = telaNueva.value
    if ((t.marca_tela && t.nombre_color) || t.medida) {
      payload.variante_nueva = {
        marca: t.marca || null,
        marca_tela: t.marca_tela || null,
        nombre_color: t.nombre_color || null,
        medida: t.medida || null,
        precio_variante: t.precio_variante || null,
      }
    }
  } else if (varianteSel.value) {
    payload.variante_id = varianteSel.value
  }
  if (comboConfigId.value) payload.combo_config_id = comboConfigId.value

  guardando.value = true
  try {
    const { data } = await producir(payload)
    toast.success(pasosSel.value.length
      ? 'Fabricación creada y arrancada en el taller.'
      : 'Fabricación creada. Queda pendiente: asígnale los pasos desde "Cambiar estado".')
    emit('creada', data)
    cerrar()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo crear la producción.'
  } finally {
    guardando.value = false
  }
}

function resetear() {
  modo.value = 'catalogo'
  busqueda.value = ''
  resultados.value = []
  productoSel.value = null
  nuevo.value = { nombre: '', categoria: '', precio_base: '', es_tapizado: false, tiene_tallas: false }
  cantidad.value = 1
  fechaCompromiso.value = ''
  variantesCat.value = []
  varianteSel.value = ''
  telaNueva.value = { marca: '', marca_tela: '', nombre_color: '', medida: '', precio_variante: '' }
  configGrupos.value = []
  comboConfigId.value = null
  specs.value = {}
  specsNotas.value = ''
  pasosSel.value = []
  error.value = ''
}

function cerrar() {
  emit('close')
  setTimeout(resetear, 200)
}

watch(() => props.show, (v) => { if (v) resetear() })
</script>

<template>
  <Transition name="fade">
    <div v-if="show" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="cerrar">
      <div class="absolute inset-0 bg-black/40" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg p-5 space-y-4 max-h-[92vh] overflow-y-auto pb-8">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-gray-800">Producir</h3>
            <p class="text-xs text-gray-500">Fabricación interna, sin orden. Al terminar el último paso se elige si va a la Reserva de Fábrica o a despacho.</p>
          </div>
          <button @click="cerrar" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <!-- Modo: catálogo / nuevo -->
        <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm font-medium">
          <button @click="modo = 'catalogo'; limpiarProducto()"
            :class="['flex-1 py-2 transition-colors', modo === 'catalogo' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
            Del catálogo
          </button>
          <button @click="modo = 'nuevo'; limpiarProducto()"
            :class="['flex-1 py-2 transition-colors', modo === 'nuevo' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50']">
            Producto nuevo
          </button>
        </div>

        <!-- Catálogo -->
        <div v-if="modo === 'catalogo'" class="space-y-2">
          <div v-if="!productoSel" class="relative">
            <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
            <input v-model="busqueda" placeholder="Buscar producto por nombre o categoría..."
              class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <div v-if="buscando" class="text-xs text-gray-400 mt-1">Buscando...</div>
            <ul v-if="resultados.length" class="mt-1 border border-gray-200 rounded-lg divide-y max-h-56 overflow-y-auto">
              <li v-for="r in resultados" :key="r.producto_id">
                <button @click="elegirProducto(r)" class="w-full text-left px-3 py-2 hover:bg-blue-50 text-sm">
                  <span class="font-medium text-gray-800">{{ r.producto?.nombre }}</span>
                  <span class="text-gray-400"> · {{ r.producto?.categoria || 'sin categoría' }}</span>
                  <span v-if="r.producto?.es_tapizado" class="ml-1 text-[11px] text-purple-600">tapizado</span>
                  <span v-if="r.producto?.tiene_tallas" class="ml-1 text-[11px] text-amber-600">tallas</span>
                </button>
              </li>
            </ul>
          </div>
          <div v-else class="flex items-center justify-between bg-blue-50 rounded-lg px-3 py-2">
            <div class="text-sm">
              <p class="font-semibold text-blue-800">{{ productoSel.producto?.nombre }}</p>
              <p class="text-xs text-blue-600">{{ productoSel.producto?.categoria }}</p>
            </div>
            <button @click="limpiarProducto" class="text-xs text-blue-700 underline">Cambiar</button>
          </div>
        </div>

        <!-- Nuevo -->
        <div v-else class="space-y-2">
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Nombre del producto *</label>
            <input v-model="nuevo.nombre" placeholder="Ej: SOFA MONACO 3 puestos"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Categoría</label>
              <ComboInput :model-value="nuevo.categoria" :options="categorias" placeholder="Elige o escribe..."
                @update:model-value="v => nuevo.categoria = v" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Precio base *</label>
              <InputPesos v-model="nuevo.precio_base" placeholder="Ej: 1.200.000"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
          </div>
          <div class="flex gap-4 text-sm">
            <label class="flex items-center gap-1.5"><input type="checkbox" v-model="nuevo.es_tapizado" /> Lleva tela</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" v-model="nuevo.tiene_tallas" /> Tiene tallas / medidas</label>
          </div>
        </div>

        <!-- Cantidad + fecha -->
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Cantidad *</label>
            <input v-model.number="cantidad" type="number" min="1"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Fecha objetivo (opcional)</label>
            <input v-model="fechaCompromiso" type="date"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
        </div>

        <!-- Tela / talla -->
        <div v-if="esTapizado || tieneTallas" class="space-y-2 border-t border-gray-100 pt-3">
          <p class="text-sm font-semibold text-gray-800">{{ esTapizado ? 'Tela / color' : 'Talla / medida' }}</p>

          <!-- Telas del catálogo ya registradas -->
          <div v-if="modo === 'catalogo' && variantesCat.length" class="flex flex-wrap gap-1.5">
            <button v-for="v in variantesCat" :key="v.id"
              @click="varianteSel = (varianteSel === v.id ? '' : v.id)"
              :class="['px-2.5 py-1 rounded-full text-xs font-medium border',
                varianteSel === v.id ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-gray-300 text-gray-600']">
              {{ [v.marca_tela, v.nombre_color, v.medida].filter(Boolean).join(' · ') || 'variante' }}
            </button>
          </div>

          <button @click="varianteSel = (varianteSel === 'nueva' ? '' : 'nueva')"
            :class="['text-xs font-medium', varianteSel === 'nueva' ? 'text-blue-700' : 'text-blue-600 hover:text-blue-800']">
            {{ varianteSel === 'nueva' ? '− Cancelar nueva' : '+ Nueva ' + (esTapizado ? 'tela' : 'talla') }}
          </button>

          <div v-if="varianteSel === 'nueva'" class="space-y-2 bg-gray-50 rounded-lg p-3">
            <template v-if="esTapizado">
              <ComboInput :model-value="telaNueva.marca" :options="[...marcasOrdenadas, 'Otro']" placeholder="Marca fabricante..."
                @update:model-value="v => { telaNueva.marca = v; telaNueva.marca_tela = ''; telaNueva.nombre_color = '' }" />
              <ComboInput v-if="tiposTelaOpc.length" :model-value="telaNueva.marca_tela" :options="[...tiposTelaOpc, 'Otro']" placeholder="Tipo de tela..."
                @update:model-value="v => { telaNueva.marca_tela = v; telaNueva.nombre_color = '' }" />
              <input v-else v-model="telaNueva.marca_tela" placeholder="Tipo de tela..."
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
              <ComboInput v-if="coloresOpc.length" :model-value="telaNueva.nombre_color" :options="[...coloresOpc, 'Otro']" placeholder="Color..."
                @update:model-value="v => telaNueva.nombre_color = v" />
              <input v-else v-model="telaNueva.nombre_color" placeholder="Color..."
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </template>
            <template v-if="tieneTallas">
              <input v-model="telaNueva.medida" placeholder="Talla / medida (ej: 1.40 x 1.90)"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
              <InputPesos v-model="telaNueva.precio_variante" placeholder="Precio de esta talla (opcional)"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </template>
          </div>

          <!-- Medidas configurables (catálogo) -->
          <template v-if="modo === 'catalogo' && configGrupos.length">
            <div v-for="g in configGrupos" :key="g.tipo_variante_id">
              <p class="text-xs font-medium text-gray-500 mb-1 mt-1">{{ g.tipo?.nombre }}</p>
              <div class="flex flex-wrap gap-1.5">
                <button v-for="opt in g.items" :key="opt.id"
                  @click="comboConfigId = (comboConfigId === opt.id ? null : opt.id)"
                  :class="['px-2.5 py-1 rounded-full text-xs font-medium border',
                    comboConfigId === opt.id ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-gray-300 text-gray-600']">
                  {{ opt.opcion_nombre }}
                </button>
              </div>
            </div>
          </template>

          <!-- Cuánta tela gasta contra lo que hay (solo con el descuento
               automático encendido en Telas y el consumo del producto cargado).
               Se dice aquí, al elegir, no cuando el servidor rechace producir. -->
          <p
            v-if="necesidadTela"
            :class="['text-xs font-semibold rounded-lg px-2.5 py-1.5 border', necesidadTela.suficiente
              ? 'bg-green-50 border-green-200 text-green-700'
              : 'bg-red-50 border-red-200 text-red-700']"
          >
            <template v-if="necesidadTela.suficiente">
              ✓ Necesita {{ necesidadTela.metros_necesarios }} m y hay {{ necesidadTela.metros }} m libres.
            </template>
            <template v-else>
              ✕ No alcanza la tela: necesita {{ necesidadTela.metros_necesarios }} m y solo hay {{ necesidadTela.metros }} m libres.
              Elige otra tela o recarga el inventario de telas.
            </template>
          </p>

          <p class="text-[11px] text-gray-400">
            Si no eliges tela/medida, las unidades entran solo al stock base y el supervisor las reparte luego en Reserva.
          </p>
        </div>

        <!-- Especificaciones -->
        <div class="space-y-2 border-t border-gray-100 pt-3">
          <p class="text-sm font-semibold text-gray-800">Especificaciones <span class="text-gray-400 font-normal">— {{ template.titulo }}</span></p>
          <div class="grid grid-cols-2 gap-2">
            <template v-for="campo in template.campos" :key="campo.key">
              <div :class="campo.type === 'text' ? 'col-span-2' : ''">
                <label class="text-xs text-gray-500">{{ campo.label }}{{ campo.unit ? ' (' + campo.unit + ')' : '' }}</label>
                <ComboInput v-if="campo.type === 'select'" :model-value="specs[campo.key] ?? ''" :options="campo.options ?? []"
                  :placeholder="campo.placeholder || 'Elige o escribe...'" @update:model-value="v => specs[campo.key] = v" />
                <input v-else v-model="specs[campo.key]" :type="campo.type" :placeholder="campo.placeholder"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
            </template>
          </div>
          <textarea v-model="specsNotas" rows="2" placeholder="Notas adicionales (opcional)"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>

        <!-- Pasos -->
        <div class="space-y-2 border-t border-gray-100 pt-3">
          <p class="text-sm font-semibold text-gray-800">Pasos de producción <span class="text-gray-400 font-normal">(opcional)</span></p>
          <p class="text-xs text-gray-400">
            Toca los procesos en el orden en que se hacen. Si no eliges ninguno, la pieza queda
            <strong>pendiente</strong> y le asignas los pasos después desde "Cambiar estado → En proceso".
          </p>
          <div class="space-y-2">
            <button v-for="proc in PROCESOS" :key="proc.tipo" type="button" @click="togglePaso(proc.tipo)"
              :class="['w-full flex items-center gap-3 px-3 py-2.5 rounded-xl border-2 transition-all text-left',
                ordenDe(proc.tipo) ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300']">
              <span :class="['w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0',
                ordenDe(proc.tipo) ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-400']">
                {{ ordenDe(proc.tipo) ?? '+' }}
              </span>
              <div>
                <p class="text-sm font-semibold text-gray-800">{{ proc.label }}</p>
                <p class="text-xs text-gray-500">{{ proc.desc }}</p>
              </div>
            </button>
          </div>
          <p v-if="pasosSel.length" class="text-xs text-blue-600">
            {{ pasosSel.map(p => labelProceso(p.tipo_proceso)).join(' → ') }}
          </p>
          <p v-else class="text-xs text-amber-600">Sin pasos: se crea pendiente, sin arrancar el taller.</p>
        </div>

        <p v-if="error" class="text-xs text-red-600">{{ error }}</p>

        <div class="flex gap-3">
          <button @click="cerrar" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
          <button @click="enviar" :disabled="guardando || !listoParaProducir"
            class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
            {{ guardando ? 'Creando...' : (pasosSel.length ? 'Producir' : 'Crear pendiente') }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
