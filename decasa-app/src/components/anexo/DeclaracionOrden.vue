<script setup>
/**
 * La declaración de diseños especiales en el detalle de una orden: qué
 * productos la piden, cómo va la ficha de cada uno (lo que el cliente aprueba)
 * y si ya está firmada —en el sistema, con su PDF o imagen, o en papel—.
 *
 * Aquí se llena o corrige la ficha de una orden ya creada (la que se creó
 * esperando el precio del taller, por ejemplo) antes de mandarla a firmar.
 * Si se cambia una ficha ya firmada, deja de valer: el servidor lo dice y hay
 * que firmar otra vez (la firmada se queda guardada como estaba).
 */
import { ref, computed } from 'vue'
import { useToast } from '@/composables/useToast'
import { guardarFichaEspecial, pdfDeclaracion } from '@/api/anexos'
import { faltaEnFicha } from '@/utils/declaracionEspeciales'
import { pdfAImagen, sePuedeCompartir, compartirImagen, descargarImagen } from '@/utils/pdfAImagen'
import FichaEspecialEditor from '@/components/anexo/FichaEspecialEditor.vue'
import { DocumentCheckIcon, ArrowDownTrayIcon, PhotoIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  orden:       { type: Object, required: true },
  puedeEditar: { type: Boolean, default: false },
})
const emit = defineEmits(['cambio'])
const toast = useToast()

const decl = computed(() => props.orden.declaracion)
const items = computed(() => (props.orden.items ?? []).filter(i => decl.value?.items?.includes(i.id)))
// El anexo firmado que trae la declaración (el último firmado de la orden).
const firmada = computed(() => !!props.orden.anexo_garantia?.especiales_hash)
const vigente = computed(() => firmada.value && !decl.value?.falta)
const sePuedeEditar = computed(() => props.puedeEditar && !['entregado', 'cancelado', 'devuelto'].includes(props.orden.estado))

function nombre(i) { return i.producto?.nombre ?? i.nombre_custom ?? 'Producto' }

// ── Documento ────────────────────────────────────────────────────────────────
const bajando = ref('')
const imagenLista = ref(null)
async function documento(formato) {
  if (bajando.value) return
  bajando.value = formato
  try {
    const { data } = await pdfDeclaracion(props.orden.anexo_garantia.id)
    const pdf = new Blob([data], { type: 'application/pdf' })
    const base = `declaracion-${props.orden.referencia ?? props.orden.id}`
    if (formato === 'pdf') {
      window.open(URL.createObjectURL(pdf), '_blank')
    } else {
      const img = await pdfAImagen(pdf)
      if (sePuedeCompartir(img, `${base}.jpg`)) imagenLista.value = { blob: img, nombre: `${base}.jpg` }
      else descargarImagen(img, `${base}.jpg`)
    }
  } catch {
    toast.error('No se pudo abrir la declaración.')
  } finally {
    bajando.value = ''
  }
}
async function compartirLista() {
  const i = imagenLista.value
  imagenLista.value = null
  if (!i) return
  try { await compartirImagen(i.blob, i.nombre) } catch { descargarImagen(i.blob, i.nombre) }
}

// ── Editar la ficha ──────────────────────────────────────────────────────────
const editando  = ref(null)   // el ítem
const borrador  = ref({})
const guardando = ref(false)
function abrir(i) {
  editando.value = i
  borrador.value = { ...(i.ficha_especial ?? {}) }
}
async function guardar() {
  if (guardando.value || !editando.value) return
  guardando.value = true
  try {
    const { data } = await guardarFichaEspecial(editando.value.id, borrador.value)
    toast.success(data.falta_en_ficha ? `Guardada. Todavía falta ${data.falta_en_ficha}.` : 'Ficha guardada.')
    editando.value = null
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar la ficha.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div v-if="decl" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
    <div class="flex items-center justify-between gap-2">
      <p class="text-xs font-semibold text-gray-500 uppercase flex items-center gap-1.5">
        <DocumentCheckIcon class="w-4 h-4 text-blue-600" /> Declaración de diseños especiales
      </p>
      <span v-if="vigente" class="text-xs bg-green-100 text-green-700 font-semibold px-2 py-0.5 rounded-full">✓ Firmada</span>
      <span v-else-if="orden.declaracion_foto_url" class="text-xs bg-green-100 text-green-700 font-semibold px-2 py-0.5 rounded-full">✓ En papel</span>
      <span v-else class="text-xs bg-amber-100 text-amber-800 font-semibold px-2 py-0.5 rounded-full">Pendiente</span>
    </div>

    <p v-if="decl.falta && !orden.declaracion_foto_url" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
      {{ decl.falta }}
      <span v-if="!decl.sin_ficha?.length" class="block mt-1">Mándasela con "Enviar para revisar y firmar" (abajo) o súbela en papel al confirmar.</span>
    </p>

    <!-- Cada producto y su ficha -->
    <ul class="divide-y divide-gray-100 -mx-1">
      <li v-for="i in items" :key="i.id" class="flex items-center gap-2 px-1 py-2">
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-gray-800 truncate">{{ nombre(i) }}</p>
          <p v-if="faltaEnFicha(i.ficha_especial)" class="text-[11px] text-amber-700">Ficha incompleta: falta {{ faltaEnFicha(i.ficha_especial) }}</p>
          <p v-else class="text-[11px] text-gray-500 truncate">
            {{ [i.ficha_especial.tipo_mueble, i.ficha_especial.ancho, i.ficha_especial.alto].filter(Boolean).join(' · ') }}
          </p>
        </div>
        <button
          v-if="sePuedeEditar" type="button" @click="abrir(i)"
          class="flex-shrink-0 flex items-center gap-1 text-xs font-semibold text-blue-600"
        >
          <PencilSquareIcon class="w-3.5 h-3.5" /> {{ i.ficha_especial ? 'Ficha' : 'Llenar ficha' }}
        </button>
      </li>
    </ul>

    <!-- Firmada en el sistema -->
    <template v-if="firmada">
      <p class="text-xs text-gray-500">
        La aprobó {{ orden.anexo_garantia.nombre_firmante }} con el anexo
        ({{ orden.anexo_garantia.modo === 'remoto' ? 'desde su teléfono' : 'en la tienda' }}).
        <span v-if="!vigente" class="text-amber-700">La ficha cambió después: esa firma quedó guardada pero ya no es lo que se fabrica.</span>
      </p>
      <div class="grid grid-cols-2 gap-2">
        <button type="button" @click="documento('pdf')" :disabled="!!bajando"
          class="flex items-center justify-center gap-1 text-xs font-semibold border border-gray-300 rounded-lg py-2 hover:bg-gray-50 disabled:opacity-50">
          <ArrowDownTrayIcon class="w-3.5 h-3.5" /> {{ bajando === 'pdf' ? 'Abriendo…' : 'PDF' }}
        </button>
        <button type="button" @click="documento('imagen')" :disabled="!!bajando"
          class="flex items-center justify-center gap-1 text-xs font-semibold border border-gray-300 rounded-lg py-2 hover:bg-gray-50 disabled:opacity-50">
          <PhotoIcon class="w-3.5 h-3.5" /> {{ bajando === 'imagen' ? 'Preparando…' : 'Imagen' }}
        </button>
      </div>
      <button v-if="imagenLista" type="button" @click="compartirLista"
        class="w-full rounded-lg bg-blue-600 py-2 text-xs font-semibold text-white">Compartir o guardar la imagen</button>
    </template>

    <!-- En papel -->
    <a v-if="orden.declaracion_foto_url" :href="orden.declaracion_foto_url" target="_blank" rel="noopener"
      class="flex items-center gap-1 text-xs text-blue-600 font-medium">
      <ArrowDownTrayIcon class="w-3.5 h-3.5" /> Ver la declaración firmada en papel
    </a>

    <!-- Hoja para llenar o corregir la ficha -->
    <div v-if="editando" class="fixed inset-0 z-50 bg-black/40 flex items-end sm:items-center justify-center" @click.self="editando = null">
      <div class="w-full max-w-lg max-h-[92vh] overflow-y-auto bg-white rounded-t-2xl sm:rounded-2xl p-4 space-y-3">
        <p class="text-sm font-bold text-gray-800">{{ nombre(editando) }}</p>
        <FichaEspecialEditor
          v-model="borrador"
          :specs="editando.specs_personalizacion"
          :nombre="nombre(editando)"
          :bocetos="editando.bocetos_list?.length ?? 0"
          :firmada="vigente"
        />
        <div class="grid grid-cols-2 gap-2">
          <button type="button" @click="editando = null" class="rounded-lg border border-gray-300 py-2.5 text-xs font-semibold text-gray-600">Cancelar</button>
          <button type="button" @click="guardar" :disabled="guardando"
            class="rounded-lg bg-blue-600 py-2.5 text-xs font-semibold text-white disabled:opacity-50">{{ guardando ? 'Guardando…' : 'Guardar ficha' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
