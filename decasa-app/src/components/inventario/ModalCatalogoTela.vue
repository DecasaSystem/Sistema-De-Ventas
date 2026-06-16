<script setup>
import { ref, computed } from 'vue'
import { XMarkIcon, PlusIcon, ChevronDownIcon, ChevronRightIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela, cargarCatalogoDB } from '@/data/telasCatalogo'
import api from '@/api'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['close'])
const toast = useToast()

// ── Formulario para agregar ───────────────────────────────────────────────────
const form = ref({ marca: '', tipo: '' })
const coloresChips = ref([])   // lista de colores que se van a guardar
const colorInput   = ref('')   // texto del input actual
const colorInputEl = ref(null) // ref al <input>
const guardando    = ref(false)
const error        = ref('')

const marcasSugeridas = computed(() => marcasOrdenadas.value)
const tiposSugeridos  = computed(() => form.value.marca ? tiposTelaDeM(form.value.marca) : [])

// Colores existentes en el catálogo para esa marca+tipo (para sugerencias y validación)
const coloresExistentes = computed(() =>
  form.value.marca && form.value.tipo
    ? new Set(coloresDeTela(form.value.marca, form.value.tipo).map(c => c.toLowerCase()))
    : new Set()
)

// Colores del input actual que ya existen (estático+DB) o están duplicados en chips
const colorInputDuplicado = computed(() => {
  const v = colorInput.value.trim().toLowerCase()
  if (!v) return false
  return coloresExistentes.value.has(v) || coloresChips.value.map(c => c.toLowerCase()).includes(v)
})

// Chips que no van a poder guardarse (ya existen)
const chipsDuplicados = computed(() =>
  new Set(coloresChips.value.filter(c => coloresExistentes.value.has(c.toLowerCase())))
)

const puedeGuardar = computed(() =>
  form.value.marca.trim() && form.value.tipo.trim() &&
  coloresChips.value.length > 0 &&
  coloresChips.value.some(c => !coloresExistentes.value.has(c.toLowerCase()))
)

function agregarChip() {
  const v = colorInput.value.trim()
  if (!v) return
  if (!coloresChips.value.map(c => c.toLowerCase()).includes(v.toLowerCase())) {
    coloresChips.value.push(v)
  }
  colorInput.value = ''
}

function onColorKeydown(e) {
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault()
    agregarChip()
  } else if (e.key === 'Backspace' && colorInput.value === '' && coloresChips.value.length) {
    coloresChips.value.pop()
  }
}

function quitarChip(i) {
  coloresChips.value.splice(i, 1)
}

async function guardar() {
  // Agregar lo que quede en el input antes de guardar
  agregarChip()
  error.value = ''

  const { marca, tipo } = form.value
  if (!marca.trim() || !tipo.trim()) {
    error.value = 'Completa marca y tipo.'
    return
  }

  // Filtrar los que ya existen
  const nuevos = coloresChips.value.filter(c => !coloresExistentes.value.has(c.toLowerCase()))
  if (nuevos.length === 0) {
    error.value = 'Todos los colores ya existen en el catálogo.'
    return
  }

  guardando.value = true
  try {
    await api.post('/catalogo-telas/batch', { marca: marca.trim(), tipo: tipo.trim(), colores: nuevos })
    await cargarCatalogoDB(api)
    await cargarDB()
    toast.success(`${nuevos.length} color${nuevos.length > 1 ? 'es' : ''} agregado${nuevos.length > 1 ? 's' : ''}: ${marca} → ${tipo}`)
    coloresChips.value = []
    colorInput.value   = ''
    marcaAbierta.value = marca.trim()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Error al guardar.'
  } finally {
    guardando.value = false
  }
}

// ── Catálogo DB navegable ─────────────────────────────────────────────────────
const marcaAbierta = ref(null)
const tipoAbierto  = ref(null)

function toggleMarca(marca) {
  marcaAbierta.value = marcaAbierta.value === marca ? null : marca
  tipoAbierto.value  = null
}
function toggleTipo(marca, tipo) {
  const key = `${marca}::${tipo}`
  tipoAbierto.value = tipoAbierto.value === key ? null : key
}

const dbEntradas  = ref([])
const loadingDB   = ref(true)

async function cargarDB() {
  loadingDB.value = true
  try {
    const { data } = await api.get('/catalogo-telas')
    dbEntradas.value = data
  } catch {}
  loadingDB.value = false
}
cargarDB()

async function eliminar(id, marcaN, tipoN, colorN) {
  try {
    await api.delete(`/catalogo-telas/${id}`)
    await cargarDB()
    await cargarCatalogoDB(api)
    toast.success(`Eliminado: ${marcaN} → ${tipoN} → ${colorN}`)
  } catch {
    toast.error('No se pudo eliminar.')
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl max-h-[90vh] flex flex-col">

      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-4 border-b shrink-0">
        <h3 class="text-base font-bold text-gray-800">Catálogo de telas</h3>
        <button @click="emit('close')" class="text-gray-400 hover:text-gray-600 p-1"><XMarkIcon class="w-5 h-5" /></button>
      </div>

      <div class="overflow-y-auto flex-1 px-5 py-4 space-y-5">

        <!-- Formulario agregar -->
        <div class="bg-blue-50 rounded-xl p-4 space-y-3">
          <p class="text-sm font-semibold text-blue-800">Agregar al catálogo</p>

          <div class="grid grid-cols-2 gap-2">
            <!-- Marca -->
            <div>
              <label class="text-xs font-medium text-gray-600 mb-1 block">Marca</label>
              <input
                v-model="form.marca"
                list="dl-marcas"
                placeholder="Ej: Visual"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                @change="form.tipo = ''; coloresChips = []"
              />
              <datalist id="dl-marcas">
                <option v-for="m in marcasSugeridas" :key="m" :value="m" />
              </datalist>
            </div>

            <!-- Tipo -->
            <div>
              <label class="text-xs font-medium text-gray-600 mb-1 block">Tipo de tela</label>
              <input
                v-model="form.tipo"
                list="dl-tipos"
                placeholder="Ej: Bistro"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                @change="coloresChips = []"
              />
              <datalist id="dl-tipos">
                <option v-for="t in tiposSugeridos" :key="t" :value="t" />
              </datalist>
            </div>
          </div>

          <!-- Chip input para colores -->
          <div>
            <label class="text-xs font-medium text-gray-600 mb-1 block">
              Colores
              <span class="font-normal text-gray-400">— Enter o coma para agregar</span>
            </label>
            <div
              class="min-h-[42px] w-full border border-gray-300 rounded-lg px-2 py-1.5 bg-white flex flex-wrap gap-1.5 items-center cursor-text focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500"
              @click="colorInputEl?.focus()"
            >
              <span
                v-for="(chip, i) in coloresChips"
                :key="i"
                :class="[
                  'inline-flex items-center gap-1 text-xs rounded-full px-2.5 py-1 font-medium',
                  chipsDuplicados.has(chip)
                    ? 'bg-amber-100 text-amber-700 border border-amber-300'
                    : 'bg-blue-100 text-blue-800 border border-blue-200'
                ]"
              >
                {{ chip }}
                <button type="button" @click.stop="quitarChip(i)" class="hover:text-red-500 transition-colors">
                  <XMarkIcon class="w-3 h-3" />
                </button>
              </span>
              <input
                ref="colorInputEl"
                v-model="colorInput"
                list="dl-colores"
                placeholder="Escribe un color…"
                class="flex-1 min-w-[120px] text-sm outline-none bg-transparent py-0.5"
                :class="colorInputDuplicado ? 'text-amber-600' : 'text-gray-800'"
                @keydown="onColorKeydown"
                @blur="agregarChip"
              />
              <datalist id="dl-colores">
                <option
                  v-for="c in coloresDeTela(form.marca, form.tipo)"
                  :key="c"
                  :value="c"
                />
              </datalist>
            </div>
            <p v-if="chipsDuplicados.size > 0" class="text-xs text-amber-600 mt-1">
              Los chips en naranja ya existen y se omitirán al guardar.
            </p>
            <p v-else-if="colorInputDuplicado" class="text-xs text-amber-600 mt-1">
              Este color ya existe en el catálogo.
            </p>
          </div>

          <p v-if="error" class="text-xs text-red-600">{{ error }}</p>

          <button
            @click="guardar"
            :disabled="guardando || !puedeGuardar"
            class="w-full flex items-center justify-center gap-1.5 bg-blue-600 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            <PlusIcon class="w-4 h-4" />
            {{ guardando ? 'Guardando…' : `Guardar ${coloresChips.length > 1 ? coloresChips.length + ' colores' : 'color'}` }}
          </button>
        </div>

        <!-- Entradas DB navegables -->
        <div>
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Entradas agregadas</p>

          <div v-if="loadingDB" class="space-y-2">
            <div v-for="i in 3" :key="i" class="h-10 bg-gray-100 rounded-lg animate-pulse" />
          </div>

          <div v-else-if="dbEntradas.length === 0" class="text-sm text-gray-400 text-center py-4">
            No hay entradas nuevas aún.
          </div>

          <div v-else class="space-y-1">
            <div v-for="mg in dbEntradas" :key="mg.marca" class="border border-gray-200 rounded-xl overflow-hidden">
              <button
                @click="toggleMarca(mg.marca)"
                class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 hover:bg-gray-100 transition-colors text-left"
              >
                <span class="text-sm font-semibold text-gray-800">{{ mg.marca }}</span>
                <component :is="marcaAbierta === mg.marca ? ChevronDownIcon : ChevronRightIcon" class="w-4 h-4 text-gray-400" />
              </button>

              <div v-if="marcaAbierta === mg.marca" class="divide-y divide-gray-100">
                <div v-for="tg in mg.tipos" :key="tg.tipo">
                  <button
                    @click="toggleTipo(mg.marca, tg.tipo)"
                    class="w-full flex items-center justify-between px-4 py-2 hover:bg-gray-50 transition-colors text-left"
                  >
                    <span class="text-sm text-gray-700 pl-2">{{ tg.tipo }}</span>
                    <span class="text-xs text-gray-400">{{ tg.colores.length }} colores</span>
                  </button>

                  <div v-if="tipoAbierto === `${mg.marca}::${tg.tipo}`" class="px-4 pb-3 flex flex-wrap gap-1.5">
                    <span
                      v-for="c in tg.colores"
                      :key="c.id"
                      class="flex items-center gap-1 text-xs bg-white border border-gray-200 rounded-full px-2.5 py-1"
                    >
                      {{ c.color }}
                      <button @click.stop="eliminar(c.id, mg.marca, tg.tipo, c.color)" class="text-gray-300 hover:text-red-500 transition-colors">
                        <TrashIcon class="w-3 h-3" />
                      </button>
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>
