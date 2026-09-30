<script setup>
/**
 * "Este cambio necesita aprobación": lo ve el vendedor cuando lo que quiere
 * guardar mueve dinero (precios, cantidades, productos, descuentos, abonos).
 *
 * Muestra qué cambia —antes y después, como lo armó el servidor—, pide el
 * motivo y al menos una foto de soporte, y manda la solicitud. A los
 * supervisores les llega una notificación; hasta que uno la apruebe, la orden
 * sigue como estaba.
 */
import { ref, computed, watch } from 'vue'
import api from '@/api'
import { crearSolicitudCambio } from '@/api/ordenes'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { ShieldCheckIcon, PhotoIcon, XMarkIcon, ArrowRightIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show:    { type: Boolean, default: false },
  ordenId: { type: [Number, String], required: true },
  // Filas que devolvió el servidor al revisar: [{label, antes, despues, tipo}]
  cambios: { type: Array, default: () => [] },
  // Lo que se va a pedir: { cambios_orden?, pago? }
  pedido:  { type: Object, default: () => ({}) },
  // Si además se guardó algo que no era dinero, se le dice al vendedor.
  seGuardoLoDemas: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'enviada'])
const toast = useToast()

const motivo    = ref('')
const soportes  = ref([])   // urls ya subidas
const subiendo  = ref(false)
const enviando  = ref(false)
const error     = ref('')

watch(() => props.show, (v) => {
  if (v) { motivo.value = ''; soportes.value = []; error.value = '' }
})

const listo = computed(() => motivo.value.trim().length >= 5 && soportes.value.length > 0 && !subiendo.value)

function valor(v, fila) {
  if (v === null || v === undefined || v === '') return '—'
  if (fila.tipo === 'plata' && typeof v === 'number') return '$' + Math.round(v).toLocaleString('es-CO')
  return String(v)
}

async function subirFotos(e) {
  const archivos = [...(e.target.files ?? [])]
  e.target.value = ''
  if (!archivos.length) return
  subiendo.value = true
  try {
    for (const f of archivos.slice(0, 6 - soportes.value.length)) {
      const fd = new FormData()
      fd.append('foto', await comprimirImagen(f), 'soporte.jpg')
      fd.append('folder', 'soportes')
      const { data } = await api.post('/upload/foto', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      soportes.value.push(data.url)
    }
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo subir la foto. Intenta de nuevo.')
  } finally {
    subiendo.value = false
  }
}

async function enviar() {
  if (!listo.value || enviando.value) return
  enviando.value = true
  error.value = ''
  try {
    await crearSolicitudCambio(props.ordenId, {
      ...props.pedido,
      motivo:   motivo.value.trim(),
      soportes: soportes.value,
    })
    toast.success('Solicitud enviada. Te avisamos cuando un supervisor la responda.')
    emit('enviada')
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo enviar la solicitud.'
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show" class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center" @click.self="emit('close')">
        <div class="absolute inset-0 bg-black/50" @click="emit('close')" />

        <div class="relative w-full sm:max-w-md max-h-[92dvh] bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col">
          <!-- Encabezado -->
          <div class="flex items-start gap-3 px-5 pt-5 pb-4 border-b border-gray-100 flex-shrink-0">
            <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
              <ShieldCheckIcon class="w-5 h-5" />
            </div>
            <div class="min-w-0 flex-1">
              <h3 class="text-base font-bold text-gray-800">Este cambio necesita aprobación</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                Los cambios de dinero los aprueba un supervisor. Hasta entonces la orden sigue como estaba.
              </p>
            </div>
            <button @click="emit('close')" aria-label="Cerrar" class="text-gray-400 text-2xl leading-none w-9 h-9 -mr-2 -mt-1">&times;</button>
          </div>

          <div class="overflow-y-auto px-5 py-4 space-y-4 flex-1 min-h-0">
            <p v-if="seGuardoLoDemas" class="text-xs text-green-800 bg-green-50 border border-green-200 rounded-lg px-3 py-2">
              Lo demás que cambiaste (fotos, notas, fechas…) ya quedó guardado.
            </p>

            <!-- Qué cambia -->
            <div>
              <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Lo que se va a cambiar</p>
              <ul class="rounded-xl border border-gray-200 divide-y divide-gray-100">
                <li v-for="(c, i) in cambios" :key="i" class="px-3 py-2.5">
                  <p class="text-sm font-medium text-gray-800">{{ c.label }}</p>
                  <p class="flex items-center gap-2 text-sm mt-0.5">
                    <span class="text-gray-400 line-through">{{ valor(c.antes, c) }}</span>
                    <ArrowRightIcon class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" />
                    <span class="font-semibold text-gray-900">{{ valor(c.despues, c) }}</span>
                  </p>
                </li>
              </ul>
            </div>

            <!-- Motivo -->
            <div>
              <label for="sol-motivo" class="block text-sm font-medium text-gray-700 mb-1">
                ¿Por qué se necesita? <span class="text-red-500">*</span>
              </label>
              <textarea
                id="sol-motivo"
                v-model="motivo"
                rows="3"
                maxlength="1000"
                placeholder="Ej: el cliente pagó por transferencia y quedó marcado como efectivo; se le dio el descuento de la promoción…"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"
              />
            </div>

            <!-- Soporte -->
            <div>
              <p class="text-sm font-medium text-gray-700 mb-1">
                Soporte <span class="text-red-500">*</span>
                <span class="text-xs font-normal text-gray-400">— foto del comprobante, chat con el cliente, factura…</span>
              </p>
              <div class="flex flex-wrap gap-2">
                <div v-for="(url, i) in soportes" :key="url" class="relative w-20 h-20">
                  <img :src="cloudinaryOpt(url, 160)" alt="Soporte" class="w-full h-full rounded-lg object-cover border border-gray-200" />
                  <button type="button" @click="soportes.splice(i, 1)" :aria-label="`Quitar soporte ${i + 1}`"
                    class="absolute -top-1.5 -right-1.5 bg-white rounded-full shadow p-0.5 text-red-500">
                    <XMarkIcon class="w-4 h-4" />
                  </button>
                </div>
                <label v-if="soportes.length < 6"
                  class="w-20 h-20 rounded-lg border-2 border-dashed border-gray-300 flex flex-col items-center justify-center gap-1 text-gray-400 cursor-pointer hover:border-amber-400 hover:text-amber-600">
                  <PhotoIcon class="w-6 h-6" />
                  <span class="text-[10px] font-semibold">{{ subiendo ? 'Subiendo…' : 'Agregar' }}</span>
                  <input type="file" accept="image/*" multiple class="hidden" @change="subirFotos" :disabled="subiendo" />
                </label>
              </div>
            </div>
          </div>

          <!-- Pie -->
          <div class="px-5 pt-3 pb-5 border-t border-gray-100 space-y-3 flex-shrink-0">
            <p v-if="error" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ error }}</p>
            <p v-else-if="!listo" class="text-xs text-gray-400 text-center">
              Escribe el motivo y agrega al menos una foto para enviarla.
            </p>
            <div class="flex gap-3">
              <button @click="emit('close')" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
              <button
                @click="enviar"
                :disabled="!listo || enviando"
                class="flex-1 bg-amber-700 text-white rounded-lg py-2.5 text-sm font-bold hover:bg-amber-800 disabled:opacity-50"
              >
                {{ enviando ? 'Enviando…' : 'Enviar para aprobación' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
