<script setup>
/**
 * Devolver al taller una pieza que ya salió de él y todavía no se entrega.
 *
 * Se olvidó una manija, la tela no era esa, se rayó al cargarla. Lo que hay
 * que evitar es que arreglar eso cueste fabricar el mueble otra vez, así que
 * el formulario pide las dos decisiones por separado:
 *
 *   1. A qué paso vuelve — dónde se retoma el trabajo.
 *   2. Cuáles de los pasos siguientes hay que rehacer.
 *
 * Lo que no se marca se queda hecho y el taller lo salta. Despacho va siempre
 * marcado y no se puede quitar: la pieza entró otra vez y tiene que volver a
 * salir por la misma puerta.
 */
import { ref, computed, watch } from 'vue'
import api from '@/api'
import { opcionesRetorno, regresarAlTaller } from '@/api/produccion'
import { useToast } from '@/composables/useToast'
import {
  ArrowUturnLeftIcon, ExclamationTriangleIcon, CameraIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  abierto:       { type: Boolean, default: false },
  produccionId:  { type: [Number, String], default: null },
  /** Para el encabezado, mientras carga lo demás. */
  productoNombre: { type: String, default: '' },
})
const emit = defineEmits(['cerrar', 'devuelto'])

const toast = useToast()

const cargando    = ref(false)
const enviando    = ref(false)
const sePuede     = ref(false)
const impedimento = ref(null)
const pasos       = ref([])
const producto    = ref('')

const destinoId = ref(null)
const rehacer   = ref(new Set())
const motivo    = ref('')
const fotoUrl   = ref(null)
const subiendo  = ref(false)

const destino = computed(() => pasos.value.find(p => p.id === destinoId.value) ?? null)

/** De lo que se puede rehacer solo se ofrece lo que viene del destino en adelante. */
const siguientes = computed(() => {
  if (!destino.value) return []
  return pasos.value.filter(p => p.orden > destino.value.orden)
})

/** Despacho y el destino se rehacen sí o sí; no tiene sentido desmarcarlos. */
function esObligatorio(p) {
  return p.es_despacho || p.id === destinoId.value
}

function alternar(p) {
  if (esObligatorio(p)) return
  const n = new Set(rehacer.value)
  n.has(p.id) ? n.delete(p.id) : n.add(p.id)
  rehacer.value = n
}

function marcado(p) {
  return esObligatorio(p) || rehacer.value.has(p.id)
}

/** El recorrido que va a hacer la pieza, tal como lo va a ver el taller. */
const recorrido = computed(() => {
  if (!destino.value) return ''
  return pasos.value
    .filter(p => p.orden >= destino.value.orden && marcado(p))
    .map(p => p.label)
    .join(' → ')
})

const saltados = computed(() => {
  if (!destino.value) return []
  return siguientes.value.filter(p => !marcado(p)).map(p => p.label)
})

function elegirDestino(p) {
  destinoId.value = p.id
  // Al cambiar de destino, lo marcado de antes puede haber quedado fuera de
  // rango; se empieza limpio y el obligatorio se marca solo.
  rehacer.value = new Set()
}

async function cargar() {
  if (!props.produccionId) return
  cargando.value = true
  destinoId.value = null
  rehacer.value = new Set()
  motivo.value = ''
  fotoUrl.value = null
  try {
    const { data } = await opcionesRetorno(props.produccionId)
    sePuede.value     = data.se_puede
    impedimento.value = data.impedimento
    pasos.value       = data.pasos ?? []
    producto.value    = data.producto ?? props.productoNombre
    // Lo más común es que vuelva al último paso de taller (el anterior a
    // despacho): es donde se arregla casi todo lo que aparece en la bodega.
    const deTaller = pasos.value.filter(p => !p.es_despacho)
    if (deTaller.length) destinoId.value = deTaller[deTaller.length - 1].id
    else if (pasos.value.length) destinoId.value = pasos.value[0].id
  } catch (e) {
    impedimento.value = e.response?.data?.message ?? 'No se pudo cargar la pieza.'
    sePuede.value = false
  } finally {
    cargando.value = false
  }
}

async function subirFoto(ev) {
  const file = ev.target.files?.[0]
  if (!file) return
  subiendo.value = true
  try {
    const fd = new FormData()
    fd.append('foto', file)
    fd.append('folder', 'produccion')
    const { data } = await api.post('/upload/foto', fd)
    fotoUrl.value = data.url
  } catch {
    toast.error('No se pudo subir la foto.')
  } finally {
    subiendo.value = false
    ev.target.value = ''
  }
}

async function confirmar() {
  if (!destinoId.value || motivo.value.trim().length < 3) return
  enviando.value = true
  try {
    const { data } = await regresarAlTaller(props.produccionId, {
      paso_destino_id: destinoId.value,
      pasos_rehacer:   siguientes.value.filter(p => marcado(p)).map(p => p.id),
      motivo:          motivo.value.trim(),
      foto_url:        fotoUrl.value,
    })
    toast.success(data.message ?? 'La pieza volvió al taller.')
    emit('devuelto', data)
    emit('cerrar')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo devolver la pieza.')
  } finally {
    enviando.value = false
  }
}

watch(() => props.abierto, (v) => { if (v) cargar() })
</script>

<template>
  <Transition name="fade">
    <div
      v-if="abierto"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
      @click.self="emit('cerrar')"
    >
      <div class="absolute inset-0 bg-black/40" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4 max-h-[90vh] overflow-y-auto">

        <div class="flex items-start justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <ArrowUturnLeftIcon class="w-5 h-5 text-amber-600 shrink-0" />
            <div class="min-w-0">
              <h3 class="text-lg font-bold text-gray-800 leading-tight">Devolver al taller</h3>
              <p class="text-xs text-gray-500 truncate">{{ producto || productoNombre }}</p>
            </div>
          </div>
          <button @click="emit('cerrar')" class="p-1 text-gray-400 hover:text-gray-600 shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div v-if="cargando" class="py-8 text-center text-sm text-gray-400">Cargando la pieza...</div>

        <!-- No se puede: se dice por qué, en vez de esconder el botón -->
        <div
          v-else-if="!sePuede"
          class="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-3"
        >
          <ExclamationTriangleIcon class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
          <p class="text-sm text-amber-800">{{ impedimento }}</p>
        </div>

        <template v-else>
          <p class="text-sm text-gray-600">
            Elige dónde se retoma el trabajo y qué hay que rehacer.
            <span class="text-gray-500">Lo que no marques se queda hecho — no se repite.</span>
          </p>

          <!-- 1. A qué paso vuelve -->
          <div class="space-y-1.5">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">
              1 · ¿A qué paso vuelve?
            </label>
            <div class="space-y-2">
              <label
                v-for="p in pasos"
                :key="p.id"
                :class="['flex items-center gap-3 rounded-xl border p-3 cursor-pointer transition-colors',
                  destinoId === p.id ? 'border-amber-400 bg-amber-50' : 'border-gray-200 hover:border-gray-300']"
              >
                <input
                  type="radio"
                  :value="p.id"
                  :checked="destinoId === p.id"
                  @change="elegirDestino(p)"
                  class="accent-amber-500"
                />
                <span class="text-sm font-semibold text-gray-800">{{ p.label }}</span>
                <span class="text-xs text-gray-400">Paso {{ p.orden }}</span>
              </label>
            </div>
          </div>

          <!-- 2. Qué más se rehace -->
          <div v-if="siguientes.length" class="space-y-1.5">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">
              2 · ¿Qué más hay que rehacer?
            </label>
            <div class="space-y-2">
              <label
                v-for="p in siguientes"
                :key="p.id"
                :class="['flex items-center gap-3 rounded-xl border p-2.5 transition-colors',
                  esObligatorio(p) ? 'border-gray-200 bg-gray-50 cursor-default'
                    : marcado(p) ? 'border-amber-300 bg-amber-50 cursor-pointer'
                    : 'border-gray-200 hover:border-gray-300 cursor-pointer']"
              >
                <input
                  type="checkbox"
                  :checked="marcado(p)"
                  :disabled="esObligatorio(p)"
                  @change="alternar(p)"
                  class="rounded border-gray-300 text-amber-600 focus:ring-amber-500 disabled:opacity-60"
                />
                <span class="flex-1 min-w-0 text-sm text-gray-800 truncate">{{ p.label }}</span>
                <span v-if="p.es_despacho" class="text-[10px] text-gray-400 shrink-0">siempre</span>
              </label>
            </div>
          </div>

          <!-- El recorrido que queda -->
          <div class="bg-gray-50 rounded-xl px-3 py-2.5 space-y-1">
            <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Le queda por hacer</p>
            <p class="text-sm font-medium text-gray-800">{{ recorrido || '—' }}</p>
            <p v-if="saltados.length" class="text-xs text-emerald-700">
              No se repite: {{ saltados.join(', ') }}
            </p>
          </div>

          <!-- Motivo -->
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide">
              ¿Qué pasó?
            </label>
            <textarea
              v-model="motivo"
              rows="3"
              placeholder="Ej: le falta la manija derecha, se rayó el costado al bajarla de la estantería..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"
            />
            <p class="text-[11px] text-gray-400">Es lo que va a leer quien lo arregle.</p>
          </div>

          <!-- Foto del daño -->
          <div class="flex items-center gap-3">
            <label
              class="flex items-center gap-1.5 text-xs font-semibold text-gray-600 border border-gray-300 rounded-lg px-3 py-2 cursor-pointer hover:bg-gray-50"
            >
              <CameraIcon class="w-4 h-4" />
              {{ subiendo ? 'Subiendo...' : fotoUrl ? 'Cambiar foto' : 'Foto (opcional)' }}
              <input type="file" accept="image/*" class="hidden" @change="subirFoto" :disabled="subiendo" />
            </label>
            <img v-if="fotoUrl" :src="fotoUrl" class="w-12 h-12 rounded-lg object-cover border border-gray-200" />
          </div>

          <div class="flex gap-3 pt-1">
            <button
              @click="emit('cerrar')"
              class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold"
            >
              Cancelar
            </button>
            <button
              @click="confirmar"
              :disabled="!destinoId || motivo.trim().length < 3 || enviando"
              class="flex-1 bg-amber-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-amber-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            >
              {{ enviando ? 'Devolviendo...' : 'Devolver al taller' }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </Transition>
</template>
