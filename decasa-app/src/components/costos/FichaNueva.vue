<script setup>
/**
 * Ficha técnica nueva. Los materiales salen del catálogo (para que su precio se
 * mantenga al día) y la mano de obra de Tarifas (para que siga el incentivo del
 * oficio). Las secciones se pueden armar después, al editar la ficha.
 */
import { ref, computed } from 'vue'
import { PhotoIcon, PlusIcon, CheckIcon } from '@heroicons/vue/24/outline'
import api from '@/api'
import { crearFicha } from '@/api/fichas'
import { useToast } from '@/composables/useToast'
import { comprimirImagen } from '@/utils/comprimirImagen'
import IconoS from '@/components/common/IconoS.vue'
import PanelCostos from './PanelCostos.vue'
import CostoBarra from './CostoBarra.vue'
import FichaItemFila from './FichaItemFila.vue'
import PickerMaterial from './PickerMaterial.vue'
import PickerTarifa from './PickerTarifa.vue'
import { useCostos } from './useCostos'

defineProps({ categorias: { type: Array, default: () => [] } })
const emit = defineEmits(['cerrar', 'creada'])

const toast = useToast()
const { formatPeso, tarifaHoraDe, nombreProceso } = useCostos()

const nombre     = ref('')
const categoria  = ref('')
const items      = ref([])
const foto       = ref(null)
const fotoUrl    = ref('')
const creando    = ref(false)
const pickerMaterial = ref(false)
const pickerTarifa   = ref(false)
let tmp = 0

const totalMat = computed(() => items.value.filter(i => !i.es_mano_obra).reduce((s, i) => s + i.subtotal, 0))
const totalMO  = computed(() => items.value.filter(i =>  i.es_mano_obra).reduce((s, i) => s + i.subtotal, 0))
const hayAlgo  = computed(() => nombre.value.trim() || items.value.length)

function agregar(campos) {
  const item = { _tmp: ++tmp, cantidad: 1, unidad: null, precio_unitario: 0, subtotal: 0, es_mano_obra: false, material_id: null, tarifa_proceso_id: null, ...campos }
  item.subtotal = Math.round(item.cantidad * item.precio_unitario * 100) / 100
  items.value.push(item)
}

function cambiar(item, campos) {
  Object.assign(item, campos)
  item.subtotal = Math.round((parseFloat(item.cantidad) || 0) * (parseFloat(item.precio_unitario) || 0) * 100) / 100
}

function elegirMaterial(m) {
  agregar({ descripcion: m.nombre, unidad: m.unidad, precio_unitario: parseFloat(m.precio_unitario) || 0, material_id: m.id })
  pickerMaterial.value = false
}

function elegirTarifa(p) {
  agregar({
    descripcion: nombreProceso(p), unidad: 'horas', cantidad: parseFloat(p._horas) || 0,
    precio_unitario: Math.round(tarifaHoraDe(p.cargo)), es_mano_obra: true, tarifa_proceso_id: p.id,
  })
  pickerTarifa.value = false
}

function onFoto(e) {
  const file = e.target.files?.[0]
  if (!file) return
  foto.value    = file
  fotoUrl.value = URL.createObjectURL(file)
}

function cerrar() {
  if (hayAlgo.value && !confirm('¿Descartar la ficha nueva?')) return
  emit('cerrar')
}

async function crear() {
  if (!nombre.value.trim())    { toast.error('Ponle un nombre a la ficha.'); return }
  if (!categoria.value.trim()) { toast.error('Elige o escribe una categoría.'); return }
  if (!items.value.length)     { toast.error('Agrega al menos un material o una mano de obra.'); return }

  creando.value = true
  try {
    let urlFoto
    if (foto.value) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(foto.value), 'foto.jpg')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      urlFoto = up.url
    }
    const { data } = await crearFicha({
      nombre:    nombre.value.trim().toUpperCase(),
      categoria: categoria.value.trim().toUpperCase(),
      foto_url:  urlFoto,
      items: items.value.map((i, orden) => ({
        seccion:           null,
        descripcion:       i.descripcion,
        cantidad:          parseFloat(i.cantidad) || 0,
        unidad:            i.unidad,
        precio_unitario:   parseFloat(i.precio_unitario) || 0,
        es_mano_obra:      i.es_mano_obra,
        material_id:       i.material_id,
        tarifa_proceso_id: i.tarifa_proceso_id,
        orden,
      })),
    })
    toast.success('Ficha creada')
    emit('creada', data.id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo crear la ficha.')
  } finally {
    creando.value = false
  }
}
</script>

<template>
  <PanelCostos titulo="Nueva ficha técnica" subtitulo="Lo que cuesta fabricar un mueble" @cerrar="cerrar">
    <template #acciones>
      <button @click="crear" :disabled="creando"
        class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50">
        <IconoS v-if="creando" class="w-3.5 h-3.5" />
        <CheckIcon v-else class="w-3.5 h-3.5" />
        {{ creando ? 'Creando…' : 'Crear ficha' }}
      </button>
    </template>

    <div class="p-4 space-y-5 pb-10">
      <!-- Datos -->
      <div class="flex gap-3">
        <label class="relative w-20 h-20 flex-shrink-0 rounded-xl border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center cursor-pointer overflow-hidden hover:bg-gray-100"
          title="Foto del mueble (opcional)">
          <img v-if="fotoUrl" :src="fotoUrl" alt="" class="absolute inset-0 w-full h-full object-cover" />
          <span v-else class="flex flex-col items-center text-gray-400">
            <PhotoIcon class="w-6 h-6" /><span class="text-[11px] mt-0.5">Foto</span>
          </span>
          <input type="file" accept="image/*" class="hidden" @change="onFoto" />
        </label>
        <div class="flex-1 min-w-0 space-y-2">
          <div>
            <label for="ficha-nombre" class="block text-xs font-medium text-gray-600 mb-1">Nombre del mueble</label>
            <input id="ficha-nombre" v-model="nombre" type="text" placeholder="Sofá moderno 3 puestos"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label for="ficha-categoria" class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
            <input id="ficha-categoria" v-model="categoria" list="nueva-categorias" type="text" placeholder="Sofás"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <datalist id="nueva-categorias"><option v-for="c in categorias" :key="c" :value="c" /></datalist>
          </div>
        </div>
      </div>

      <!-- Costo hasta ahora -->
      <div v-if="items.length" class="space-y-2">
        <div class="flex items-baseline justify-between">
          <p class="text-xs text-gray-500">Costo de fabricación</p>
          <p class="text-xl font-bold text-gray-900 tabular-nums">{{ formatPeso(totalMat + totalMO) }}</p>
        </div>
        <CostoBarra :materiales="totalMat" :mano-obra="totalMO" />
      </div>

      <!-- Ítems -->
      <div>
        <h3 class="text-sm font-semibold text-gray-700 mb-1.5 px-1">Materiales y mano de obra</h3>
        <div class="rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
          <FichaItemFila v-for="item in items" :key="item._tmp" :item="item" editando
            @cambio="c => cambiar(item, c)" @quitar="items.splice(items.indexOf(item), 1)" />
          <p v-if="!items.length" class="px-3 py-4 text-sm text-gray-400 text-center">
            Agrega lo que lleva el mueble: los materiales del catálogo y las horas de cada oficio.
          </p>
          <div class="flex gap-4 px-3 py-2 bg-gray-50">
            <button @click="pickerMaterial = true" class="flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800">
              <PlusIcon class="w-3.5 h-3.5" />Material
            </button>
            <button @click="pickerTarifa = true" class="flex items-center gap-1 text-xs font-semibold text-orange-600 hover:text-orange-800">
              <PlusIcon class="w-3.5 h-3.5" />Mano de obra
            </button>
          </div>
        </div>
      </div>
    </div>
  </PanelCostos>

  <PickerMaterial v-if="pickerMaterial" titulo="Agregar material" permite-libre
    @elegir="elegirMaterial" @cerrar="pickerMaterial = false" />
  <PickerTarifa v-if="pickerTarifa" @elegir="elegirTarifa" @cerrar="pickerTarifa = false" />
</template>
