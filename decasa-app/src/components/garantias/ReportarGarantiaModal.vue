<script setup>
/**
 * Reportar una garantía: el cliente llamó porque algo de lo que ya recibió se
 * dañó.
 *
 * Lo llena quien atiende al cliente (casi siempre el vendedor de la tienda).
 * No decide nada: deja escrito qué pasó, de qué es el daño —eso dice cuánto
 * dura la garantía según el anexo—, dónde está el mueble y qué pide el
 * cliente. El sistema calcula la vigencia y el plazo legal para responder, y
 * le avisa a quien decide.
 */
import { ref, computed } from 'vue'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import { reportarGarantia } from '@/api/garantias'
import { comprimirImagen, comprimirAlTomar } from '@/utils/comprimirImagen'
import { TIPOS_DANO, LINEAS, PREFERENCIAS, fechaCorta } from './garantias'
import { ShieldCheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  item:  { type: Object, required: true },
  orden: { type: Object, required: true },
})
const emit = defineEmits(['cerrar', 'guardada'])
const toast = useToast()

const entregadas = computed(() => Number(props.item.cantidad_entregada || 0))
const nombre     = computed(() => props.item.producto?.nombre ?? props.item.nombre_custom ?? 'Producto')

const cantidad   = ref(1)
const tipoDano   = ref('')
const linea      = ref('elite_promocional')
const motivo     = ref('')
const dondeEsta  = ref('casa_cliente')
const preferencia = ref('')
const fotos      = ref([])   // [{ file, preview }]
const guardando  = ref(false)

async function agregarFotos(e) {
  const archivos = Array.from(e.target.files ?? [])
  e.target.value = ''
  for (const original of archivos.slice(0, 6 - fotos.value.length)) {
    // Achicada al tomarla: la original de la cámara llena la memoria del teléfono.
    const file = await comprimirAlTomar(original)
    fotos.value.push({ file, preview: URL.createObjectURL(file) })
  }
}
function quitarFoto(i) {
  URL.revokeObjectURL(fotos.value[i].preview)
  fotos.value.splice(i, 1)
}

const puedeGuardar = computed(() => tipoDano.value && motivo.value.trim().length >= 3 && !guardando.value)

async function guardar() {
  if (!puedeGuardar.value) return
  guardando.value = true
  try {
    const urls = []
    for (const f of fotos.value) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(f.file), 'garantia.jpg')
      fd.append('folder', 'garantias')
      const { data } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      urls.push(data.url)
    }
    const { data } = await reportarGarantia({
      orden_item_id: props.item.id,
      cantidad: Number(cantidad.value) || 1,
      tipo_dano: tipoDano.value,
      linea: tipoDano.value === 'madera' ? linea.value : null,
      motivo: motivo.value.trim(),
      fotos: urls,
      donde_esta: dondeEsta.value,
      preferencia_cliente: preferencia.value || null,
    })
    if (data.dentro_de_garantia === false) {
      toast.info(`Registrada. Ojo: la garantía venció el ${fechaCorta(data.vence_el)}; quien decide lo valora.`, 6000)
    } else {
      toast.success(`Registrada. Hay que responderle al cliente antes del ${fechaCorta(data.responder_antes_de)}.`)
    }
    fotos.value.forEach(f => URL.revokeObjectURL(f.preview))
    emit('guardada', data)
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo registrar la garantía.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl max-h-[92vh] flex flex-col">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
          <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center shrink-0">
            <ShieldCheckIcon class="w-5 h-5 text-blue-700" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-800 truncate">Garantía</p>
            <p class="text-[11px] text-gray-400 truncate">{{ nombre }} · {{ orden.referencia }}</p>
          </div>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4 overflow-y-auto">
          <p class="text-xs text-gray-500 border border-gray-200 rounded-xl px-3 py-2 leading-snug">
            Para lo que el cliente ya recibió y se dañó después. Queda en la orden y le llega a quien
            decide: arreglarlo en el taller o en la casa, cambiarlo, o si no procede.
          </p>

          <div v-if="entregadas > 1">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuántas unidades?</label>
            <div class="flex items-center gap-2">
              <button type="button" @click="cantidad = Math.max(1, cantidad - 1)" class="w-9 h-9 rounded-xl border border-gray-200 text-gray-600 font-bold">−</button>
              <span class="w-10 text-center text-sm font-bold text-gray-800">{{ cantidad }}</span>
              <button type="button" @click="cantidad = Math.min(entregadas, cantidad + 1)" class="w-9 h-9 rounded-xl border border-gray-200 text-gray-600 font-bold">+</button>
              <span class="text-[11px] text-gray-400 ml-1">de {{ entregadas }} entregadas</span>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿De qué es el daño? <span class="text-red-500">*</span></label>
            <div class="grid grid-cols-1 gap-1.5">
              <button
                v-for="t in TIPOS_DANO" :key="t.v" type="button" @click="tipoDano = t.v"
                :class="['text-left rounded-xl px-3 py-2 border-2 transition-colors',
                  tipoDano === t.v ? 'border-blue-500' : 'border-gray-200 hover:border-gray-300']"
              >
                <span class="block text-sm font-semibold text-gray-800">{{ t.t }}</span>
                <span class="block text-[11px] text-gray-500 leading-snug">{{ t.d }}</span>
              </button>
            </div>
            <div v-if="tipoDano === 'madera'" class="flex gap-1 bg-gray-100 rounded-xl p-1 mt-2">
              <button
                v-for="l in LINEAS" :key="l.v" type="button" @click="linea = l.v"
                :class="['flex-1 text-xs font-semibold rounded-lg px-2 py-1.5 transition-colors',
                  linea === l.v ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
              >{{ l.t }}</button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué se dañó? <span class="text-red-500">*</span></label>
            <textarea
              v-model="motivo" rows="3"
              placeholder="Ej. a la semana se le despegó el espaldar; el cliente dice que no lo ha movido"
              class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Fotos <span class="font-normal text-gray-400">— ayudan a dar el dictamen</span></label>
            <div class="flex flex-wrap gap-2">
              <div v-for="(f, i) in fotos" :key="f.preview" class="relative">
                <img :src="f.preview" class="w-16 h-16 rounded-lg object-cover border border-gray-200" />
                <button type="button" @click="quitarFoto(i)" class="absolute -top-1.5 -right-1.5 bg-red-500 text-white rounded-full p-1 shadow">
                  <XMarkIcon class="w-3 h-3" />
                </button>
              </div>
              <label v-if="fotos.length < 6" class="w-16 h-16 flex items-center justify-center border-2 border-dashed border-gray-300 rounded-lg cursor-pointer text-xl">
                📷
                <input type="file" accept="image/*" multiple @change="agregarFotos" class="hidden" />
              </label>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Dónde está el mueble?</label>
            <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
              <button type="button" @click="dondeEsta = 'casa_cliente'"
                :class="['flex-1 text-xs font-semibold rounded-lg px-2 py-1.5', dondeEsta === 'casa_cliente' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
                En la casa del cliente
              </button>
              <button type="button" @click="dondeEsta = 'tienda'"
                :class="['flex-1 text-xs font-semibold rounded-lg px-2 py-1.5', dondeEsta === 'tienda' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']">
                Lo trajo a la tienda
              </button>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué pide el cliente? <span class="font-normal text-gray-400">— opcional</span></label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="p in PREFERENCIAS" :key="p.v" type="button"
                @click="preferencia = preferencia === p.v ? '' : p.v"
                :class="['text-xs font-semibold rounded-full px-3 py-1 border transition-colors',
                  preferencia === p.v ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600']"
              >{{ p.t }}</button>
            </div>
            <p class="text-[11px] text-gray-400 mt-1.5">Es una sugerencia: decide quien gestiona el taller o un supervisor.</p>
          </div>
        </div>

        <div class="flex gap-2.5 p-5 pt-3 border-t border-gray-100">
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5">Cancelar</button>
          <button
            @click="guardar" :disabled="!puedeGuardar"
            class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 disabled:opacity-50"
          >{{ guardando ? 'Guardando...' : 'Registrar garantía' }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
