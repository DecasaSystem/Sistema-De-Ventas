<script setup>
import { ref, computed } from 'vue'
import { XMarkIcon, PlusIcon, ChevronDownIcon, ChevronRightIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela, cargarCatalogoDB } from '@/data/telasCatalogo'
import api from '@/api'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['close'])
const toast = useToast()

// ── Vista del catálogo ────────────────────────────────────────────────────────
const marcaAbierta = ref(null)
const tipoAbierto  = ref(null) // "Marca::Tipo"

function toggleMarca(marca) {
  marcaAbierta.value = marcaAbierta.value === marca ? null : marca
  tipoAbierto.value = null
}
function toggleTipo(marca, tipo) {
  const key = `${marca}::${tipo}`
  tipoAbierto.value = tipoAbierto.value === key ? null : key
}

// ── Formulario para agregar ───────────────────────────────────────────────────
const form = ref({ marca: '', tipo: '', color: '' })
const guardando = ref(false)
const error = ref('')

// Sugerencias para datalists
const marcasSugeridas = computed(() => marcasOrdenadas.value)
const tiposSugeridos  = computed(() => form.value.marca ? tiposTelaDeM(form.value.marca) : [])
const coloresSugeridos = computed(() =>
  form.value.marca && form.value.tipo ? coloresDeTela(form.value.marca, form.value.tipo) : []
)

// Validación previa: combo ya existe en el catálogo (estático + DB)
const yaExiste = computed(() => {
  if (!form.value.marca || !form.value.tipo || !form.value.color) return false
  const colores = coloresDeTela(form.value.marca, form.value.tipo)
  return colores.map(c => c.toLowerCase()).includes(form.value.color.trim().toLowerCase())
})

async function guardar() {
  error.value = ''
  const { marca, tipo, color } = form.value
  if (!marca.trim() || !tipo.trim() || !color.trim()) {
    error.value = 'Completa todos los campos.'
    return
  }
  if (yaExiste.value) {
    error.value = 'Esta combinación ya existe en el catálogo.'
    return
  }
  guardando.value = true
  try {
    await api.post('/catalogo-telas', { marca: marca.trim(), tipo: tipo.trim(), color: color.trim() })
    await cargarCatalogoDB(api) // merge the new entry into reactive catalog
    toast.success(`Tela agregada: ${marca} → ${tipo} → ${color}`)
    form.value = { marca: '', tipo: '', color: '' }
    marcaAbierta.value = marca.trim()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Error al guardar.'
  } finally {
    guardando.value = false
  }
}

// ── Eliminar entrada DB ───────────────────────────────────────────────────────
// Solo entradas del catálogo DB tienen id; el catálogo estático no tiene ids
const dbEntradas = ref([]) // loaded on mount
const loadingDB = ref(true)

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

          <div class="space-y-2">
            <div>
              <label class="text-xs font-medium text-gray-600 mb-1 block">Marca</label>
              <input
                v-model="form.marca"
                list="dl-marcas"
                placeholder="Ej: Visual"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                @change="form.tipo = ''; form.color = ''"
              />
              <datalist id="dl-marcas">
                <option v-for="m in marcasSugeridas" :key="m" :value="m" />
              </datalist>
            </div>

            <div>
              <label class="text-xs font-medium text-gray-600 mb-1 block">Tipo de tela</label>
              <input
                v-model="form.tipo"
                list="dl-tipos"
                placeholder="Ej: Bistro"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                @change="form.color = ''"
              />
              <datalist id="dl-tipos">
                <option v-for="t in tiposSugeridos" :key="t" :value="t" />
              </datalist>
            </div>

            <div>
              <label class="text-xs font-medium text-gray-600 mb-1 block">Color</label>
              <input
                v-model="form.color"
                list="dl-colores"
                placeholder="Ej: Beige"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <datalist id="dl-colores">
                <option v-for="c in coloresSugeridos" :key="c" :value="c" />
              </datalist>
            </div>
          </div>

          <p v-if="yaExiste" class="text-xs text-amber-600">Esta combinación ya existe en el catálogo.</p>
          <p v-if="error" class="text-xs text-red-600">{{ error }}</p>

          <button
            @click="guardar"
            :disabled="guardando || yaExiste || !form.marca || !form.tipo || !form.color"
            class="w-full flex items-center justify-center gap-1.5 bg-blue-600 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            <PlusIcon class="w-4 h-4" />
            {{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>

        <!-- Catálogo navegable (solo entradas DB) -->
        <div>
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Entradas agregadas</p>

          <div v-if="loadingDB" class="space-y-2">
            <div v-for="i in 3" :key="i" class="h-10 bg-gray-100 rounded-lg animate-pulse" />
          </div>

          <div v-else-if="dbEntradas.length === 0" class="text-sm text-gray-400 text-center py-4">
            No hay entradas nuevas aún. El catálogo usa los datos predeterminados.
          </div>

          <div v-else class="space-y-1">
            <div v-for="marcaGroup in dbEntradas" :key="marcaGroup.marca" class="border border-gray-200 rounded-xl overflow-hidden">
              <!-- Marca row -->
              <button
                @click="toggleMarca(marcaGroup.marca)"
                class="w-full flex items-center justify-between px-4 py-2.5 bg-gray-50 hover:bg-gray-100 transition-colors text-left"
              >
                <span class="text-sm font-semibold text-gray-800">{{ marcaGroup.marca }}</span>
                <ChevronDownIcon v-if="marcaAbierta === marcaGroup.marca" class="w-4 h-4 text-gray-500" />
                <ChevronRightIcon v-else class="w-4 h-4 text-gray-500" />
              </button>

              <div v-if="marcaAbierta === marcaGroup.marca" class="divide-y divide-gray-100">
                <div v-for="tipoGroup in marcaGroup.tipos" :key="tipoGroup.tipo">
                  <!-- Tipo row -->
                  <button
                    @click="toggleTipo(marcaGroup.marca, tipoGroup.tipo)"
                    class="w-full flex items-center justify-between px-4 py-2 hover:bg-gray-50 transition-colors text-left"
                  >
                    <span class="text-sm text-gray-700 pl-2">{{ tipoGroup.tipo }}</span>
                    <span class="text-xs text-gray-400">{{ tipoGroup.colores.length }} colores</span>
                  </button>

                  <!-- Colores -->
                  <div v-if="tipoAbierto === `${marcaGroup.marca}::${tipoGroup.tipo}`" class="px-4 pb-2 flex flex-wrap gap-1.5">
                    <span
                      v-for="c in tipoGroup.colores"
                      :key="c.id"
                      class="group flex items-center gap-1 text-xs bg-white border border-gray-200 rounded-full px-2.5 py-1"
                    >
                      {{ c.color }}
                      <button
                        @click.stop="eliminar(c.id, marcaGroup.marca, tipoGroup.tipo, c.color)"
                        class="text-gray-300 hover:text-red-500 transition-colors"
                      >
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
