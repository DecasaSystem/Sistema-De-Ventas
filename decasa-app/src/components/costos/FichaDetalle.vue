<script setup>
/**
 * Detalle de una ficha técnica: cuánto cuesta fabricar el mueble, contra qué
 * precio se vende, qué hay que revisar y de qué está hecho.
 *
 * Se edita sobre una copia (`borrador`): Cancelar la descarta sin volver a
 * pedir la ficha, y Guardar manda todo de una vez (ítems nuevos, cambiados y
 * quitados, nombre, categoría y foto).
 */
import { ref, computed, onMounted } from 'vue'
import {
  PencilSquareIcon, DocumentDuplicateIcon, TrashIcon, PhotoIcon, PlusIcon,
  LinkIcon, ExclamationTriangleIcon, CheckIcon, ArrowsRightLeftIcon,
} from '@heroicons/vue/24/outline'
import api from '@/api'
import { getFicha, actualizarFicha, eliminarFicha, duplicarFicha, vincularProducto } from '@/api/fichas'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { comprimirImagen } from '@/utils/comprimirImagen'
import AppSpinner from '@/components/common/AppSpinner.vue'
import IconoS from '@/components/common/IconoS.vue'
import PanelCostos from './PanelCostos.vue'
import CostoBarra from './CostoBarra.vue'
import FichaItemFila from './FichaItemFila.vue'
import PickerMaterial from './PickerMaterial.vue'
import PickerProducto from './PickerProducto.vue'
import PickerTarifa from './PickerTarifa.vue'
import { useCostos } from './useCostos'

const props = defineProps({
  fichaId:    { type: Number, required: true },
  categorias: { type: Array, default: () => [] },
})
const emit = defineEmits(['cerrar', 'actualizada', 'eliminada', 'abrir'])

const auth  = useAuthStore()
const toast = useToast()
const { formatPeso, formatFactor, margenDe, COLOR_MARGEN, TEXTO_MARGEN, tarifaHoraDe, nombreProceso } = useCostos()

const ficha    = ref(null)
const cargando = ref(true)

function normalizar(data) {
  data.items = (data.items ?? []).map(i => ({
    ...i,
    cantidad:        parseFloat(i.cantidad) || 0,
    precio_unitario: parseFloat(i.precio_unitario) || 0,
    subtotal:        parseFloat(i.subtotal) || 0,
  }))
  return data
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getFicha(props.fichaId)
    ficha.value = normalizar(data)
  } catch {
    toast.error('No se pudo abrir la ficha.')
    emit('cerrar')
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

// ── Edición ──────────────────────────────────────────────────────────────────
const editando   = ref(false)
const borrador   = ref(null)
const eliminados = ref([])
const seccionesNuevas = ref([])   // secciones vacías recién creadas, aún sin ítems
const guardando  = ref(false)
const hayCambios = ref(false)
const fotoArchivo = ref(null)     // foto nueva elegida, se sube al guardar
let tmp = 0

// Lo que se muestra: el borrador mientras se edita, la ficha guardada si no
const vista = computed(() => editando.value ? borrador.value : ficha.value)

function empezarEdicion() {
  borrador.value   = JSON.parse(JSON.stringify(ficha.value))
  eliminados.value = []
  seccionesNuevas.value = []
  fotoArchivo.value = null
  hayCambios.value = false
  editando.value   = true
}

function cancelarEdicion() {
  if (hayCambios.value && !confirm('¿Descartar los cambios sin guardar?')) return
  editando.value = false
  borrador.value = null
}

function marcar() {
  hayCambios.value = true
  recalcular()
}

function recalcular() {
  const b = borrador.value
  for (const i of b.items) i.subtotal = Math.round((parseFloat(i.cantidad) || 0) * (parseFloat(i.precio_unitario) || 0) * 100) / 100
  b.costo_materiales = b.items.filter(i => !i.es_mano_obra).reduce((s, i) => s + i.subtotal, 0)
  b.costo_mano_obra  = b.items.filter(i =>  i.es_mano_obra).reduce((s, i) => s + i.subtotal, 0)
  b.costo_total      = b.costo_materiales + b.costo_mano_obra
}

function cambiarItem(item, campos) {
  Object.assign(item, campos)
  marcar()
}

function quitarItem(item) {
  const items = borrador.value.items
  if (items.length <= 1) { toast.error('La ficha debe quedar con al menos un ítem.'); return }
  if (item.id) eliminados.value.push(item.id)
  items.splice(items.indexOf(item), 1)
  marcar()
}

function agregarItem(campos) {
  const item = { _tmp: ++tmp, id: null, cantidad: 1, unidad: null, precio_unitario: 0, subtotal: 0, es_mano_obra: false, material_id: null, tarifa_proceso_id: null, ...campos }
  // Al final de su sección, para que no salte de lugar
  const items = borrador.value.items
  let idx = -1
  items.forEach((i, n) => { if ((i.seccion ?? null) === (item.seccion ?? null)) idx = n })
  items.splice(idx === -1 ? items.length : idx + 1, 0, item)
  seccionesNuevas.value = seccionesNuevas.value.filter(s => s !== item.seccion)
  marcar()
}

function nuevaSeccion() {
  const nombre = prompt('Nombre de la sección (ej: Base, Espaldar, Tapicería):')?.trim().toUpperCase()
  if (!nombre) return
  if (secciones.value.some(s => s.clave === nombre)) { toast.info('Esa sección ya existe.'); return }
  seccionesNuevas.value.push(nombre)
}

function onFoto(e) {
  const file = e.target.files?.[0]
  if (!file) return
  fotoArchivo.value      = file
  borrador.value.foto_url = URL.createObjectURL(file)
  hayCambios.value       = true
}

function quitarFoto() {
  fotoArchivo.value       = null
  borrador.value.foto_url = null
  hayCambios.value        = true
}

async function guardar() {
  const b = borrador.value
  if (!b.nombre?.trim())    { toast.error('La ficha necesita un nombre.'); return }
  if (!b.categoria?.trim()) { toast.error('La ficha necesita una categoría.'); return }

  guardando.value = true
  try {
    let fotoUrl
    if (fotoArchivo.value) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(fotoArchivo.value), 'foto.jpg')
      const { data: up } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      fotoUrl = up.url
    } else if (b.foto_url !== ficha.value.foto_url) {
      fotoUrl = b.foto_url   // la quitaron
    }

    const { data } = await actualizarFicha(b.id, {
      nombre:    b.nombre,
      categoria: b.categoria,
      ...(fotoUrl !== undefined ? { foto_url: fotoUrl } : {}),
      eliminar:  eliminados.value,
      items: b.items.map(i => ({
        id:                i.id || null,
        seccion:           i.seccion ?? null,
        descripcion:       i.descripcion,
        unidad:            i.unidad,
        cantidad:          parseFloat(i.cantidad) || 0,
        precio_unitario:   parseFloat(i.precio_unitario) || 0,
        es_mano_obra:      !!i.es_mano_obra,
        material_id:       i.material_id ?? null,
        tarifa_proceso_id: i.tarifa_proceso_id ?? null,
      })),
    })
    ficha.value    = normalizar(data)
    editando.value = false
    borrador.value = null
    toast.success('Ficha guardada')
    emit('actualizada', ficha.value)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudieron guardar los cambios.')
  } finally {
    guardando.value = false
  }
}

function cerrar() {
  if (editando.value && hayCambios.value && !confirm('Hay cambios sin guardar. ¿Cerrar de todas formas?')) return
  emit('cerrar')
}

// ── Secciones ────────────────────────────────────────────────────────────────
const secciones = computed(() => {
  if (!vista.value) return []
  const mapa = new Map()
  for (const item of vista.value.items) {
    const clave = item.seccion ?? null
    if (!mapa.has(clave)) mapa.set(clave, [])
    mapa.get(clave).push(item)
  }
  if (editando.value) for (const s of seccionesNuevas.value) if (!mapa.has(s)) mapa.set(s, [])
  return [...mapa.entries()].map(([clave, items]) => ({
    clave,
    nombre:   clave || 'General',
    items,
    subtotal: items.reduce((s, i) => s + (Number(i.subtotal) || 0), 0),
  }))
})

// ── Revisar ──────────────────────────────────────────────────────────────────
const revisar = computed(() => {
  const items = ficha.value?.items ?? []
  return {
    sinPrecio:     items.filter(i => !(i.precio_unitario > 0) || !String(i.descripcion ?? '').trim()).length,
    fueraCatalogo: items.filter(i => !i.es_mano_obra && !i.material_id && String(i.descripcion ?? '').trim()).length,
  }
})

// ── Buscadores ───────────────────────────────────────────────────────────────
const pickerMaterial = ref(null)   // { item } para cambiar, { seccion } para agregar
const pickerTarifa   = ref(null)   // { seccion }
const pickerProducto = ref(false)

function elegirMaterial(m) {
  const p = pickerMaterial.value
  const campos = { descripcion: m.nombre, unidad: m.unidad, precio_unitario: parseFloat(m.precio_unitario) || 0, material_id: m.id }
  if (p.item) cambiarItem(p.item, campos)
  else agregarItem({ seccion: p.seccion, ...campos })
  pickerMaterial.value = null
}

function elegirTarifa(p) {
  agregarItem({
    seccion:           pickerTarifa.value.seccion,
    descripcion:       nombreProceso(p),
    unidad:            'horas',
    cantidad:          parseFloat(p._horas) || 0,
    precio_unitario:   Math.round(tarifaHoraDe(p.cargo)),
    es_mano_obra:      true,
    tarifa_proceso_id: p.id,
  })
  pickerTarifa.value = null
}

// ── Producto y margen ────────────────────────────────────────────────────────
const vinculando = ref(false)
const margen = computed(() => ficha.value?.producto ? margenDe(ficha.value.costo_total, ficha.value.producto.precio_base) : null)

async function vincular(productoId) {
  vinculando.value = true
  try {
    const { data } = await vincularProducto(ficha.value.id, productoId)
    ficha.value.producto_id = data.producto_id
    ficha.value.producto    = data.producto
    pickerProducto.value    = false
    toast.success(productoId ? 'Producto vinculado' : 'Producto desvinculado')
    emit('actualizada', ficha.value)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo vincular el producto.')
  } finally {
    vinculando.value = false
  }
}

// ── Duplicar / eliminar ──────────────────────────────────────────────────────
const ocupado = ref(false)

async function duplicar() {
  const nombre = prompt('Nombre de la copia:', `COPIA DE ${ficha.value.nombre}`)
  if (nombre === null) return
  ocupado.value = true
  try {
    const { data } = await duplicarFicha(ficha.value.id, nombre.trim())
    toast.success('Ficha duplicada')
    emit('abrir', data.id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo duplicar la ficha.')
  } finally {
    ocupado.value = false
  }
}

async function eliminar() {
  if (!confirm(`¿Eliminar la ficha "${ficha.value.nombre}"? El cotizador dejará de usarla como referencia. No se puede deshacer.`)) return
  ocupado.value = true
  try {
    await eliminarFicha(ficha.value.id)
    toast.success('Ficha eliminada')
    emit('eliminada', ficha.value.id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo eliminar la ficha.')
  } finally {
    ocupado.value = false
  }
}

const fotoGrande = ref('')
</script>

<template>
  <PanelCostos @cerrar="cerrar">
    <template #titulo>
      <template v-if="editando">
        <p class="text-sm font-semibold text-gray-800">Editando ficha</p>
        <p class="text-xs text-gray-400">{{ hayCambios ? 'Hay cambios sin guardar' : 'Sin cambios' }}</p>
      </template>
      <template v-else>
        <p class="text-sm font-semibold text-gray-800 truncate">{{ ficha?.nombre ?? 'Cargando…' }}</p>
        <p class="text-xs text-gray-400 truncate capitalize">{{ ficha?.categoria?.toLowerCase() }}</p>
      </template>
    </template>

    <template v-if="ficha" #acciones>
      <template v-if="!editando">
        <button @click="duplicar" :disabled="ocupado" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 disabled:opacity-40"
          title="Duplicar ficha" aria-label="Duplicar ficha">
          <DocumentDuplicateIcon class="w-5 h-5" />
        </button>
        <button v-if="auth.isSupervisor" @click="eliminar" :disabled="ocupado"
          class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-gray-100 disabled:opacity-40"
          title="Eliminar ficha" aria-label="Eliminar ficha">
          <TrashIcon class="w-5 h-5" />
        </button>
        <button @click="empezarEdicion"
          class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">
          <PencilSquareIcon class="w-3.5 h-3.5" />Editar
        </button>
      </template>
      <template v-else>
        <button @click="cancelarEdicion" class="px-2 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-700">Cancelar</button>
        <button @click="guardar" :disabled="guardando || !hayCambios"
          class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50">
          <IconoS v-if="guardando" class="w-3.5 h-3.5" />
          <CheckIcon v-else class="w-3.5 h-3.5" />
          {{ guardando ? 'Guardando…' : 'Guardar' }}
        </button>
      </template>
    </template>

    <AppSpinner v-if="cargando" />

    <div v-else-if="vista" class="p-4 space-y-4 pb-10">
      <!-- Nombre y categoría, editables -->
      <div v-if="editando" class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label for="ficha-nombre-ed" class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
          <input id="ficha-nombre-ed" v-model="borrador.nombre" @input="hayCambios = true" type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label for="ficha-cat-ed" class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
          <input id="ficha-cat-ed" v-model="borrador.categoria" @input="hayCambios = true" list="costos-categorias" type="text"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          <datalist id="costos-categorias"><option v-for="c in categorias" :key="c" :value="c" /></datalist>
        </div>
      </div>

      <!-- Resumen: costo, de qué está hecho y contra qué precio se vende -->
      <section class="space-y-3">
        <div class="flex items-start gap-3">
          <div class="relative flex-shrink-0">
            <button v-if="vista.foto_url" @click="!editando && (fotoGrande = vista.foto_url)" :disabled="editando"
              class="block" aria-label="Ver foto en grande">
              <img :src="fotoArchivo ? vista.foto_url : cloudinaryOpt(vista.foto_url, 160)" alt=""
                class="w-16 h-16 rounded-xl object-cover border border-gray-200" />
            </button>
            <div v-else class="w-16 h-16 rounded-xl bg-gray-100 flex items-center justify-center text-gray-300">
              <PhotoIcon class="w-7 h-7" />
            </div>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-xs text-gray-500">Costo de fabricación</p>
            <p class="text-2xl font-bold text-gray-900 tabular-nums leading-tight">{{ formatPeso(vista.costo_total) }}</p>
            <div v-if="editando" class="flex items-center gap-3 mt-1">
              <label class="text-xs font-medium text-blue-600 cursor-pointer hover:text-blue-800">
                {{ vista.foto_url ? 'Cambiar foto' : 'Agregar foto' }}
                <input type="file" accept="image/*" class="hidden" @change="onFoto" />
              </label>
              <button v-if="vista.foto_url" @click="quitarFoto" class="text-xs font-medium text-gray-400 hover:text-red-600">Quitar foto</button>
            </div>
          </div>
        </div>

        <CostoBarra :materiales="vista.costo_materiales" :mano-obra="vista.costo_mano_obra"
          :precio="ficha.producto?.precio_base ?? null" />

        <!-- Producto del catálogo -->
        <div v-if="ficha.producto" class="flex items-center gap-3 rounded-xl border border-gray-200 px-3 py-2.5">
          <div class="flex-1 min-w-0">
            <p class="text-xs text-gray-500">Se vende como</p>
            <p class="text-sm font-medium text-gray-800 truncate">{{ ficha.producto.nombre }}</p>
            <p class="text-xs text-gray-500">
              a {{ formatPeso(ficha.producto.precio_base) }}<template v-if="margen"> · {{ TEXTO_MARGEN[margen.nivel].toLowerCase() }}</template>
            </p>
          </div>
          <span v-if="margen" :class="['text-sm font-bold rounded-lg px-2 py-1 tabular-nums', COLOR_MARGEN[margen.nivel]]">
            {{ formatFactor(margen.factor, 2) }}
          </span>
          <div class="flex flex-col items-end gap-1">
            <button @click="pickerProducto = true" :disabled="vinculando" class="text-xs font-medium text-blue-600 hover:text-blue-800">Cambiar</button>
            <button @click="vincular(null)" :disabled="vinculando" class="text-xs font-medium text-gray-400 hover:text-red-600">Quitar</button>
          </div>
        </div>
        <button v-else @click="pickerProducto = true" :disabled="vinculando"
          class="w-full flex items-center gap-3 rounded-xl border border-dashed border-gray-300 px-3 py-2.5 text-left hover:bg-gray-50">
          <LinkIcon class="w-5 h-5 text-blue-600 flex-shrink-0" />
          <span class="flex-1 min-w-0">
            <span class="block text-sm font-medium text-gray-800">Vincular con un producto</span>
            <span class="block text-xs text-gray-500">Para comparar este costo con el precio al que se vende</span>
          </span>
        </button>
      </section>

      <!-- Para revisar -->
      <div v-if="!editando && (revisar.sinPrecio || revisar.fueraCatalogo)"
        class="flex gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
        <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
        <div class="space-y-0.5">
          <p v-if="revisar.sinPrecio">
            {{ revisar.sinPrecio }} {{ revisar.sinPrecio === 1 ? 'ítem no tiene' : 'ítems no tienen' }} precio, así que el costo se queda corto.
          </p>
          <p v-if="revisar.fueraCatalogo">
            {{ revisar.fueraCatalogo }} {{ revisar.fueraCatalogo === 1 ? 'material no está' : 'materiales no están' }} en el catálogo.
            Edita la ficha y usa <ArrowsRightLeftIcon class="inline w-3.5 h-3.5 -mt-0.5" aria-label="cambiar material" /> para elegirlo del catálogo, o créalo en Materiales.
          </p>
        </div>
      </div>

      <!-- Ítems por sección -->
      <section v-for="s in secciones" :key="s.clave ?? '__general'">
        <div class="flex items-baseline justify-between mb-1.5 px-1">
          <h3 class="text-sm font-semibold text-gray-700 capitalize">{{ s.nombre.toLowerCase() }}</h3>
          <span class="text-sm font-semibold text-gray-700 tabular-nums">{{ formatPeso(s.subtotal) }}</span>
        </div>
        <div class="rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
          <FichaItemFila v-for="item in s.items" :key="item.id ?? `n${item._tmp}`" :item="item" :editando="editando"
            @cambio="c => cambiarItem(item, c)" @quitar="quitarItem(item)"
            @cambiar-material="pickerMaterial = { item }" />
          <p v-if="!s.items.length" class="px-3 py-3 text-xs text-gray-400">Sección vacía: agrégale un material o mano de obra.</p>
          <div v-if="editando" class="flex gap-4 px-3 py-2 bg-gray-50">
            <button @click="pickerMaterial = { seccion: s.clave }" class="flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800">
              <PlusIcon class="w-3.5 h-3.5" />Material
            </button>
            <button @click="pickerTarifa = { seccion: s.clave }" class="flex items-center gap-1 text-xs font-semibold text-orange-600 hover:text-orange-800">
              <PlusIcon class="w-3.5 h-3.5" />Mano de obra
            </button>
          </div>
        </div>
      </section>

      <button v-if="editando" @click="nuevaSeccion"
        class="w-full flex items-center justify-center gap-1 rounded-xl border border-dashed border-gray-300 py-2.5 text-xs font-semibold text-gray-500 hover:bg-gray-50">
        <PlusIcon class="w-3.5 h-3.5" />Nueva sección
      </button>
    </div>
  </PanelCostos>

  <PickerMaterial v-if="pickerMaterial"
    :titulo="pickerMaterial.item ? 'Cambiar material' : 'Agregar material'"
    :subtitulo="pickerMaterial.item ? `Ahora: ${pickerMaterial.item.descripcion}` : `En: ${(pickerMaterial.seccion || 'General').toLowerCase()}`"
    :inicial="pickerMaterial.item?.descripcion ?? ''"
    :excluir="pickerMaterial.item?.material_id ?? null"
    :permite-libre="!pickerMaterial.item"
    @elegir="elegirMaterial" @cerrar="pickerMaterial = null" />

  <PickerTarifa v-if="pickerTarifa" :subtitulo="`En: ${(pickerTarifa.seccion || 'General').toLowerCase()}`"
    @elegir="elegirTarifa" @cerrar="pickerTarifa = null" />

  <PickerProducto v-if="pickerProducto && ficha" :ficha="ficha" :ocupado="vinculando"
    @elegir="p => vincular(p.id)" @cerrar="pickerProducto = false" />

  <Teleport to="body">
    <div v-if="fotoGrande" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4" @click="fotoGrande = ''">
      <img :src="cloudinaryOpt(fotoGrande, 1200)" alt="" class="max-w-full max-h-full object-contain rounded-lg" />
    </div>
  </Teleport>
</template>
