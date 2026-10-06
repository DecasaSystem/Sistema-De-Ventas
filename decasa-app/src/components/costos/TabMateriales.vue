<script setup>
/** Catálogo de materiales: lo que se compra para fabricar, a qué precio y en cuántas fichas se usa. */
import { ref, computed, onMounted } from 'vue'
import * as XLSX from 'xlsx'
import { MagnifyingGlassIcon, PlusIcon, ArrowDownTrayIcon, TrashIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import { getMateriales } from '@/api/materiales'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import AppSpinner from '@/components/common/AppSpinner.vue'
import MaterialForm from './MaterialForm.vue'
import MaterialEliminar from './MaterialEliminar.vue'
import { useCostos } from './useCostos'

// Cuando cambian precios, cambian los costos de las fichas
const emit = defineEmits(['fichas-cambiaron'])

const auth  = useAuthStore()
const toast = useToast()
const { formatPeso } = useCostos()

const materiales = ref([])
const cargando   = ref(false)
const busqueda   = ref('')
const filtro     = ref('todos')   // 'todos' | 'sin_uso'
let debounce = null

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getMateriales(busqueda.value)
    materiales.value = data
  } catch {
    toast.error('No se pudieron cargar los materiales.')
  } finally {
    cargando.value = false
  }
}

function onBuscar() {
  clearTimeout(debounce)
  debounce = setTimeout(cargar, 350)
}

const sinUso = computed(() => materiales.value.filter(m => !Number(m.usos)).length)
const lista  = computed(() => filtro.value === 'sin_uso' ? materiales.value.filter(m => !Number(m.usos)) : materiales.value)

// ── Formularios ──────────────────────────────────────────────────────────────
const editando   = ref(undefined)   // undefined = cerrado, null = nuevo, objeto = editar
const eliminando = ref(null)

function onGuardado({ afectados }) {
  editando.value = undefined
  cargar()
  if (afectados) emit('fichas-cambiaron')
}

function onEliminado({ afectados }) {
  eliminando.value = null
  cargar()
  if (afectados) emit('fichas-cambiaron')
}

function exportarExcel() {
  const filas = lista.value.map(m => ({
    'Material':        m.nombre,
    'Se compra por':   m.unidad ?? '',
    'Precio unitario': Math.round(Number(m.precio_unitario) || 0),
    'Fichas que lo usan': Number(m.usos) || 0,
    'Descripción':     m.descripcion ?? '',
  }))
  const hoja  = XLSX.utils.json_to_sheet(filas)
  hoja['!cols'] = [{ wch: 40 }, { wch: 14 }, { wch: 16 }, { wch: 18 }, { wch: 40 }]
  const libro = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(libro, hoja, 'Materiales')
  XLSX.writeFile(libro, 'materiales_decasa.xlsx')
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-3">
    <div class="flex gap-2">
      <div class="relative flex-1">
        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        <input v-model="busqueda" @input="onBuscar" type="search" placeholder="Buscar material…"
          class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <button @click="exportarExcel" :disabled="!lista.length" title="Descargar Excel" aria-label="Descargar Excel"
        class="flex items-center gap-1 px-3 rounded-xl bg-green-600 text-white text-xs font-semibold hover:bg-green-700 disabled:opacity-40">
        <ArrowDownTrayIcon class="w-4 h-4" /><span class="hidden sm:inline">Excel</span>
      </button>
      <button @click="editando = null"
        class="flex items-center gap-1 px-3 rounded-xl bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">
        <PlusIcon class="w-4 h-4" />Material
      </button>
    </div>

    <div class="flex items-center justify-between gap-2">
      <p class="text-xs text-gray-400">{{ lista.length }} materiales. Toca uno para cambiar su precio.</p>
      <div v-if="sinUso" class="flex gap-1 bg-gray-100 rounded-lg p-0.5 flex-shrink-0">
        <button @click="filtro = 'todos'"
          :class="['px-2 py-1 rounded-md text-xs font-semibold', filtro === 'todos' ? 'bg-white text-gray-800 shadow-sm' : 'text-gray-500']">Todos</button>
        <button @click="filtro = 'sin_uso'"
          :class="['px-2 py-1 rounded-md text-xs font-semibold', filtro === 'sin_uso' ? 'bg-white text-gray-800 shadow-sm' : 'text-gray-500']">Sin uso · {{ sinUso }}</button>
      </div>
    </div>

    <AppSpinner v-if="cargando && !materiales.length" />

    <p v-else-if="!lista.length" class="text-center py-12 text-sm text-gray-400">
      {{ busqueda ? 'Ningún material coincide con la búsqueda.' : 'El catálogo está vacío. Crea el primer material.' }}
    </p>

    <div v-else class="bg-white rounded-xl shadow-sm divide-y divide-gray-100 overflow-hidden">
      <div v-for="m in lista" :key="m.id" class="flex items-center">
        <button @click="editando = m" class="flex-1 min-w-0 flex items-center gap-3 px-3 py-3 hover:bg-gray-50 text-left">
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-800 truncate">{{ m.nombre }}</p>
            <p class="text-xs text-gray-400 truncate">
              <span :class="Number(m.usos) ? '' : 'text-amber-700'">{{ Number(m.usos) ? `En ${m.usos} ${Number(m.usos) === 1 ? 'ficha' : 'fichas'}` : 'Sin uso' }}</span>
              <template v-if="m.descripcion"> · {{ m.descripcion }}</template>
            </p>
          </div>
          <div class="text-right flex-shrink-0">
            <p class="text-sm font-semibold text-gray-900 tabular-nums">{{ formatPeso(m.precio_unitario) }}</p>
            <p class="text-xs text-gray-400 lowercase">por {{ m.unidad || 'unidad' }}</p>
          </div>
          <ChevronRightIcon class="w-4 h-4 text-gray-300 flex-shrink-0" />
        </button>
        <button v-if="auth.isSupervisor" @click="eliminando = m" title="Eliminar material" :aria-label="`Eliminar ${m.nombre}`"
          class="self-stretch px-3 text-gray-300 hover:text-red-600 hover:bg-gray-50">
          <TrashIcon class="w-4 h-4" />
        </button>
      </div>
    </div>
  </div>

  <MaterialForm v-if="editando !== undefined" :material="editando" @cerrar="editando = undefined" @guardado="onGuardado" />
  <MaterialEliminar v-if="eliminando" :material="eliminando" @cerrar="eliminando = null" @eliminado="onEliminado" />
</template>
