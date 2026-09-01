<script setup>
/**
 * Quién hizo el paso, cuánto tardó y cómo quedó.
 *
 * Un solo formulario para los dos momentos:
 *  - "empezar": sólo se elige quién lo va a hacer. Horas y calidad quedan
 *    pendientes, porque todavía no se saben.
 *  - "terminar": se eligen las personas y se les pone horas y estrellas.
 *
 * Existe el modo "empezar" porque al cerrar el paso el encargado muchas veces
 * ya no se acuerda de quién lo hizo. Si se apunta al arrancar, al final sólo
 * queda llenar el tiempo y la calificación.
 */
import { ref, computed, watch } from 'vue'
import { XMarkIcon, StarIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { StarIcon as StarSolid } from '@heroicons/vue/24/solid'
import { getTrabajadoresTaller } from '@/api/produccion'
import { aHoras, enUnidad, unidadSugerida } from '@/utils/duracion'

const props = defineProps({
  abierto: { type: Boolean, default: false },
  paso:    { type: Object, default: null },
  /** 'empezar' = sólo asignar · 'terminar' = asignar + calificar */
  modo:    { type: String, default: 'terminar' },
  procesoLabel: { type: String, default: '' },
  guardando:    { type: Boolean, default: false },
})
const emit = defineEmits(['cerrar', 'guardar'])

const catalogo  = ref([])
const cargando  = ref(false)
const busqueda  = ref('')
/**
 * Por defecto solo salen los encasillados en ESTE proceso: en el taller un paso
 * lo hace su gente, y buscar entre treinta y cinco nombres para dar con el
 * tapicero de siempre es lo que hacía que se escribiera cualquier cosa.
 *
 * Queda la salida de "ver todos" porque entra a ayudar quien esté libre, y
 * mandarte a Procesos a encasillarlo en mitad del cierre sería peor.
 */
const verTodos  = ref(false)
/** Lo elegido: [{ usuario_id, nombre, tiempo, unidad, calidad, comentario }] */
const elegidos  = ref([])

const esTerminar = computed(() => props.modo === 'terminar')

async function cargarCatalogo() {
  cargando.value = true
  try {
    const { data } = await getTrabajadoresTaller(props.paso?.tipo_proceso, props.paso?.linea)
    catalogo.value = Array.isArray(data) ? data : []
  } catch {
    catalogo.value = []
  } finally {
    cargando.value = false
  }
}

// Al abrir se arranca de lo que el paso ya tenga apuntado: si alguien quedó
// asignado al empezar, no hay que volver a buscarlo al cerrar.
watch(() => props.abierto, async (abierto) => {
  if (!abierto) return
  busqueda.value = ''
  verTodos.value = false
  elegidos.value = (props.paso?.participantes ?? []).map(p => {
    // Lo guardado son horas; si son días redondos se vuelve a mostrar en días.
    const unidad = p.horas != null ? unidadSugerida(p.horas) : 'hora'
    return {
      usuario_id: p.usuario_id,
      nombre:     p.nombre ?? p.usuario?.nombre ?? '',
      tiempo:     p.horas != null ? enUnidad(p.horas, unidad) : '',
      unidad,
      calidad:    p.calidad ?? null,
      comentario: p.comentario ?? '',
    }
  })
  await cargarCatalogo()
})

const delProceso = computed(() => catalogo.value.filter(t => t.del_proceso))

const disponibles = computed(() => {
  const yaEsta = new Set(elegidos.value.map(e => e.usuario_id))
  const term = busqueda.value.trim().toLowerCase()
  // Escribir un nombre busca siempre en todos: si uno lo escribe es porque sabe
  // a quién quiere, y no tiene por qué acordarse de si está encasillado.
  const base = (verTodos.value || term || !delProceso.value.length)
    ? catalogo.value
    : delProceso.value
  return base
    .filter(t => !yaEsta.has(t.id))
    .filter(t => !term || t.nombre.toLowerCase().includes(term))
})

/** Cuántos quedan escondidos por no ser de este proceso. */
const ocultos = computed(() => {
  if (verTodos.value || busqueda.value.trim() || !delProceso.value.length) return 0
  const yaEsta = new Set(elegidos.value.map(e => e.usuario_id))
  return catalogo.value.filter(t => !t.del_proceso && !yaEsta.has(t.id)).length
})

function agregar(t) {
  elegidos.value.push({
    usuario_id: t.id, nombre: t.nombre,
    tiempo: '', unidad: 'hora', calidad: null, comentario: '',
  })
  busqueda.value = ''
}

/** "= 24 h" bajo el campo, para que se vea qué se va a guardar. */
function equivalencia(e) {
  if (e.unidad !== 'dia' || e.tiempo === '') return ''
  const horas = aHoras(e.tiempo, 'dia')
  return horas === null ? '' : `= ${horas} h`
}

function quitar(usuarioId) {
  elegidos.value = elegidos.value.filter(e => e.usuario_id !== usuarioId)
}

function guardar() {
  if (!elegidos.value.length) return
  emit('guardar', elegidos.value.map(e => ({
    usuario_id: e.usuario_id,
    // En "empezar" no se manda nada de esto: todavía no se sabe.
    // El número va tal como se escribió y la unidad aparte: quien pasa días a
    // horas es el servidor, para que no dependa de la versión de la app.
    tiempo:     esTerminar.value && e.tiempo !== '' ? Number(e.tiempo) : null,
    unidad:     esTerminar.value ? e.unidad : null,
    calidad:    esTerminar.value ? e.calidad : null,
    comentario: esTerminar.value ? (e.comentario || null) : null,
  })))
}

/** Estrellas ya puestas, para mostrar el ranking de cada quien en la lista. */
function estrellasDe(promedio) {
  return Math.round(Number(promedio) || 0)
}
</script>

<template>
  <Transition name="fade">
    <div v-if="abierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="absolute inset-0 bg-black/40" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto">

        <div class="sticky top-0 bg-white/95 backdrop-blur-sm px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-3">
          <div>
            <h3 class="text-lg font-bold text-gray-800">
              {{ esTerminar ? 'Terminar paso' : 'Empezar paso' }}
            </h3>
            <p class="text-xs text-gray-500 mt-0.5">
              {{ procesoLabel }} — {{ paso?.produccion?.orden_item?.producto?.nombre ?? 'Producto' }}
            </p>
          </div>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100 shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4">
          <!-- Elegidos -->
          <div v-if="elegidos.length" class="space-y-2.5">
            <div
              v-for="e in elegidos" :key="e.usuario_id"
              class="border border-gray-200 rounded-xl p-3 space-y-2.5"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="font-semibold text-sm text-gray-800">{{ e.nombre }}</span>
                <button @click="quitar(e.usuario_id)" class="text-gray-400 hover:text-red-500">
                  <XMarkIcon class="w-4 h-4" />
                </button>
              </div>

              <div v-if="esTerminar" class="grid grid-cols-2 gap-2.5">
                <div>
                  <label class="block text-[11px] font-semibold text-gray-500 mb-1">Se demoró</label>
                  <div class="flex gap-1">
                    <input
                      v-model="e.tiempo" type="number" min="0" step="0.5" inputmode="decimal"
                      placeholder="0"
                      class="w-full min-w-0 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <select
                      v-model="e.unidad"
                      class="shrink-0 rounded-lg border border-gray-300 px-1.5 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                      <option value="hora">h</option>
                      <option value="dia">días</option>
                    </select>
                  </div>
                  <p v-if="equivalencia(e)" class="text-[10px] text-gray-400 mt-0.5">{{ equivalencia(e) }}</p>
                </div>
                <div>
                  <label class="block text-[11px] font-semibold text-gray-500 mb-1">Calidad</label>
                  <div class="flex gap-0.5 pt-1">
                    <button
                      v-for="n in 5" :key="n" type="button"
                      @click="e.calidad = e.calidad === n ? null : n"
                      class="text-amber-400 hover:scale-110 transition-transform"
                    >
                      <component :is="(e.calidad ?? 0) >= n ? StarSolid : StarIcon" class="w-5 h-5" />
                    </button>
                  </div>
                </div>
              </div>

              <input
                v-if="esTerminar"
                v-model="e.comentario" type="text" maxlength="300"
                placeholder="Opinión sobre su trabajo (opcional)"
                class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <p v-if="esTerminar && elegidos.length" class="text-[11px] text-gray-400">
            El tiempo y la calidad son opcionales, pero es lo que arma la puntuación
            del trabajador y lo que decide a quién conviene darle más trabajo.
            Un día de taller cuenta como 8 horas.
          </p>

          <!-- Buscador del catálogo -->
          <div>
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1.5">
              {{ elegidos.length ? 'Agregar a alguien más' : 'Quién hizo este paso' }}
            </label>
            <div class="relative">
              <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                v-model="busqueda" type="text" placeholder="Buscar trabajador..."
                class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            <div v-if="cargando" class="flex justify-center py-4">
              <div class="w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
            </div>

            <div v-else class="mt-2 max-h-52 overflow-y-auto divide-y divide-gray-100 border border-gray-100 rounded-xl">
              <button
                v-for="t in disponibles" :key="t.id" type="button"
                @click="agregar(t)"
                class="w-full flex items-center justify-between gap-2 px-3 py-2.5 hover:bg-blue-50 text-left transition-colors"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium text-gray-800 truncate">
                    {{ t.nombre }}
                    <span v-if="t.del_proceso" class="ml-1 text-[10px] font-semibold text-purple-600 bg-purple-50 rounded px-1 py-0.5">
                      del proceso
                    </span>
                  </p>
                  <p class="text-[11px] text-gray-400 truncate">{{ t.rol }}</p>
                </div>
                <div class="shrink-0 text-right">
                  <div v-if="t.calidad_promedio" class="flex items-center gap-0.5 justify-end">
                    <StarSolid v-for="n in estrellasDe(t.calidad_promedio)" :key="n" class="w-3 h-3 text-amber-400" />
                    <span class="text-[11px] text-gray-500 ml-0.5">{{ t.calidad_promedio }}</span>
                  </div>
                  <p v-else class="text-[11px] text-gray-300">sin calificar</p>
                  <p class="text-[10px] text-gray-400">{{ t.pasos }} paso(s)</p>
                </div>
              </button>
              <p v-if="!disponibles.length" class="text-xs text-gray-400 text-center py-4">
                {{ busqueda ? 'Nadie con ese nombre.' : 'No queda nadie por agregar.' }}
              </p>
            </div>

            <button
              v-if="ocultos" type="button" @click="verTodos = true"
              class="mt-1.5 w-full text-[11px] text-gray-500 hover:text-gray-700 py-1.5"
            >
              Trabajó alguien más — ver los otros {{ ocultos }} del taller
            </button>
            <p v-else-if="verTodos && delProceso.length" class="mt-1.5 text-[11px] text-gray-400 text-center">
              Viendo a todo el taller.
              <button type="button" @click="verTodos = false" class="underline hover:text-gray-600">
                Volver a los de este paso
              </button>
            </p>
          </div>
        </div>

        <div class="sticky bottom-0 bg-white/95 backdrop-blur-sm px-5 py-4 border-t border-gray-100 flex gap-3">
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
            Cancelar
          </button>
          <button
            @click="guardar"
            :disabled="!elegidos.length || guardando"
            :class="['flex-1 text-white rounded-lg py-2.5 text-sm font-semibold disabled:opacity-40 transition-colors',
              esTerminar ? 'bg-green-600 hover:bg-green-700' : 'bg-blue-600 hover:bg-blue-700']"
          >
            {{ guardando ? 'Guardando...' : (esTerminar ? 'Confirmar listo' : 'Guardar') }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
