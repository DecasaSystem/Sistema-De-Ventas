<script setup>
/**
 * Los procesos del taller, mantenidos por el supervisor.
 *
 * Crear uno, cambiarle el nombre, el color, el orden en que se ofrece o quién
 * lo hace. Sin tocar código y sin esperar un despliegue.
 */
import { ref, computed, watch } from 'vue'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import { useTiposProceso } from '@/composables/useTiposProceso'
import IconoS from '@/components/common/IconoS.vue'
import EncargadosProceso from '@/components/produccion/EncargadosProceso.vue'
import { XMarkIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'

const props = defineProps({ show: Boolean })
const emit  = defineEmits(['close', 'cambiado'])

const toast = useToast()
const { tipos, trabajadores, colores, separaRestauraciones, cargar, clasesDeColor } = useTiposProceso()

const cargando = ref(false)
const guardando = ref(null)   // id o 'nuevo'
const nuevo = ref(null)

watch(() => props.show, async (abierto) => {
  if (!abierto) return
  cargando.value = true
  // Con inactivos: aquí es donde se vuelven a encender
  try { await recargar() } finally { cargando.value = false }
  nuevo.value = null
})

/**
 * La línea de cada trabajador llega como lista de pares; la pantalla la
 * maneja como un mapa id → línea, que es lo que necesita para pintar el
 * reparto sin recorrerla en cada clic.
 */
async function recargar() {
  await cargar(true, true)
  for (const t of tipos.value) {
    t._lineas = Object.fromEntries((t.lineas ?? []).map(l => [l.usuario_id, l.linea]))
  }
}

// ── Llevar las restauraciones aparte ──────────────────────────────────────────
// Encenderlo no mueve a nadie: todos arrancan en "las dos" y el taller sigue
// igual hasta que aquí se reparta proceso por proceso.
const cambiandoAjuste = ref(false)

async function alternarSeparacion() {
  cambiandoAjuste.value = true
  try {
    const { data } = await api.patch('/tipos-proceso/ajustes', {
      separa_restauraciones: !separaRestauraciones.value,
    })
    await recargar()
    toast.success(data.message ?? 'Listo.')
    for (const f of data.sin_cubrir ?? []) {
      toast.error(`"${f.proceso}" no tiene encargado para ${f.linea === 'restauracion' ? 'las restauraciones' : 'los muebles nuevos'}.`)
    }
    emit('cambiado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo cambiar.')
  } finally { cambiandoAjuste.value = false }
}

const ordenados = computed(() =>
  [...tipos.value].sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0) || a.nombre.localeCompare(b.nombre, 'es'))
)

function empezarNuevo() {
  nuevo.value = { nombre: '', descripcion: '', color: 'slate', trabajador_ids: [], _lineas: {} }
}

/**
 * ¿Hay alguien que pueda CONFIRMAR el paso, en cada línea?
 *
 * La gente de fábrica se encasilla para poder anotarla como que hizo el
 * trabajo, pero no entra al programa: un proceso donde solo hay fábrica deja
 * sus pasos invisibles y las piezas paradas. Con las restauraciones aparte,
 * la cuenta va por línea — es la misma que hace el servidor.
 */
function sinCubrir(obj) {
  const ids = obj.trabajador_ids ?? []
  const conAcceso = trabajadores.value.filter(w => ids.includes(w.id) && !w.no_usa_programa)

  if (!separaRestauraciones.value) return conAcceso.length ? [] : ['ambas']

  return ['normal', 'restauracion'].filter(linea =>
    !conAcceso.some(w => [linea, 'ambas'].includes(obj._lineas?.[w.id] ?? 'ambas'))
  )
}

function nadieAsignado(obj) {
  return !(obj.trabajador_ids ?? []).length
}

/** Lo que espera el servidor: cada persona con la línea que le tocó. */
function trabajadoresParaGuardar(obj) {
  return (obj.trabajador_ids ?? []).map(id => ({
    id,
    linea: obj._lineas?.[id] ?? 'ambas',
  }))
}

async function crear() {
  const n = nuevo.value
  if (!n.nombre.trim())  { toast.error('Ponle un nombre al proceso.'); return }
  if (nadieAsignado(n))  { toast.error('Elige al menos un trabajador que haga este proceso.'); return }
  if (sinCubrir(n).length) { toast.error(faltaEncargado(n)); return }
  guardando.value = 'nuevo'
  try {
    await api.post('/tipos-proceso', {
      nombre: n.nombre.trim(),
      descripcion: n.descripcion.trim() || undefined,
      color: n.color,
      trabajadores: trabajadoresParaGuardar(n),
    })
    await recargar()
    nuevo.value = null
    toast.success('Proceso creado.')
    emit('cambiado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo crear el proceso.')
  } finally { guardando.value = null }
}

async function guardar(t) {
  if (!t.nombre.trim())  { toast.error('El nombre no puede quedar vacío.'); return }
  if (nadieAsignado(t))  { toast.error('Elige al menos un trabajador que haga este proceso.'); return }
  if (sinCubrir(t).length) { toast.error(faltaEncargado(t)); return }
  guardando.value = t.id
  try {
    await api.patch(`/tipos-proceso/${t.id}`, {
      nombre: t.nombre.trim(),
      descripcion: t.descripcion?.trim() ?? null,
      color: t.color,
      trabajadores: trabajadoresParaGuardar(t),
      orden: Number(t.orden) || 0,
      activo: !!t.activo,
    })
    await recargar()
    toast.success('Guardado.')
    emit('cambiado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
    await recargar()
  } finally { guardando.value = null }
}

/** El aviso, diciendo cuál de las dos líneas se quedó sin nadie. */
function faltaEncargado(obj) {
  const faltan = sinCubrir(obj)
  if (!separaRestauraciones.value || faltan.includes('ambas')) {
    return 'Falta un encargado con acceso al programa: los de fábrica no ven el paso.'
  }
  const texto = faltan
    .map(l => l === 'restauracion' ? 'las restauraciones' : 'los muebles nuevos')
    .join(' y ')
  return `Falta un encargado con acceso al programa para ${texto}.`
}

async function quitar(t) {
  if (!confirm(`¿Quitar "${t.nombre}"?\n\nSi ya se usó en algún paso no se borra: queda desactivado y deja de ofrecerse, pero el trabajo hecho se conserva.`)) return
  guardando.value = t.id
  try {
    const { data } = await api.delete(`/tipos-proceso/${t.id}`)
    await recargar()
    toast.success(data.message ?? 'Listo.')
    emit('cambiado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo quitar.')
  } finally { guardando.value = null }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show" class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center" @click.self="emit('close')">
        <div class="absolute inset-0 bg-black/50" @click="emit('close')" />

        <div class="relative w-full sm:max-w-2xl max-h-[90vh] bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col">
          <div class="sticky top-0 bg-white z-10 flex items-center justify-between px-5 py-4 border-b border-gray-100 rounded-t-2xl">
            <div>
              <h3 class="font-bold text-gray-900">Procesos del taller</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                Los pasos que se pueden asignar a una producción
              </p>
            </div>
            <button @click="emit('close')" class="p-1.5 rounded-lg hover:bg-gray-100">
              <XMarkIcon class="w-5 h-5 text-gray-500" />
            </button>
          </div>

          <div class="p-5 space-y-3 overflow-y-auto">
            <div v-if="cargando" class="flex justify-center py-8"><IconoS class="w-8 h-8" /></div>

            <template v-else>
              <!-- Restauraciones aparte: un interruptor, no una estructura
                   nueva. Esto puede cambiar, y volver atrás cuesta un clic. -->
              <div class="rounded-xl border border-gray-200 p-3">
                <div class="flex items-start gap-3">
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-800">Llevar las restauraciones aparte</p>
                    <p class="text-[11px] text-gray-500 mt-0.5 leading-snug">
                      Los pasos son los mismos, pero cada proceso puede tener un
                      encargado para el mueble del cliente y otro para los nuevos.
                      Cada quien ve en "Mis pasos" solo lo suyo.
                    </p>
                  </div>
                  <button
                    type="button" @click="alternarSeparacion" :disabled="cambiandoAjuste"
                    :class="['relative w-11 h-6 rounded-full transition-colors flex-shrink-0 disabled:opacity-50',
                      separaRestauraciones ? 'bg-blue-600' : 'bg-gray-300']"
                    :title="separaRestauraciones ? 'Desactivar' : 'Activar'"
                  >
                    <span :class="['absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all',
                      separaRestauraciones ? 'left-[22px]' : 'left-0.5']" />
                  </button>
                </div>
                <p v-if="separaRestauraciones" class="text-[11px] text-blue-700 bg-blue-50 border border-blue-100 rounded-lg px-2.5 py-1.5 mt-2">
                  Reparte abajo, en cada proceso. Quien quede en "las dos" sigue
                  viéndolo todo, como antes.
                </p>
              </div>

              <div
                v-for="t in ordenados"
                :key="t.id"
                :class="['rounded-xl border p-3 space-y-2.5', t.activo ? 'border-gray-200' : 'border-gray-200 bg-gray-50 opacity-70']"
              >
                <div class="flex items-center gap-2">
                  <span :class="['text-[11px] font-semibold px-2 py-0.5 rounded', clasesDeColor(t.color)]">
                    {{ t.nombre || '—' }}
                  </span>
                  <span v-if="!t.activo" class="text-[11px] text-gray-500">desactivado</span>
                  <span class="ml-auto text-[11px] text-gray-400">{{ t.clave }}</span>
                  <button
                    type="button" @click="quitar(t)" :disabled="guardando === t.id"
                    class="p-1 rounded text-red-500 hover:bg-red-50 disabled:opacity-40" title="Quitar"
                  ><TrashIcon class="w-4 h-4" /></button>
                </div>

                <div class="grid grid-cols-2 gap-2">
                  <div>
                    <label class="block text-[11px] text-gray-500 mb-1">Nombre</label>
                    <input v-model="t.nombre" type="text" maxlength="60"
                      class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-gray-500 mb-1">Orden en la lista</label>
                    <input v-model.number="t.orden" type="number" min="0"
                      class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                </div>

                <div>
                  <label class="block text-[11px] text-gray-500 mb-1">Descripción (opcional)</label>
                  <input v-model="t.descripcion" type="text" maxlength="160"
                    class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>


                <EncargadosProceso
                  :ids="t.trabajador_ids ?? []"
                  :lineas="t._lineas ?? {}"
                  :trabajadores="trabajadores"
                  :separa="separaRestauraciones"
                  @update:ids="v => t.trabajador_ids = v"
                  @update:lineas="v => t._lineas = v"
                />

                <div>
                  <label class="block text-[11px] text-gray-500 mb-1">Color</label>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="c in colores" :key="c" type="button" @click="t.color = c"
                      :class="['w-7 h-7 rounded-lg border-2 transition-transform', clasesDeColor(c),
                        t.color === c ? 'border-gray-800 scale-110' : 'border-transparent']"
                      :title="c"
                    />
                  </div>
                </div>

                <div class="flex items-center gap-3 pt-0.5">
                  <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" v-model="t.activo" /> Se puede asignar
                  </label>
                  <button
                    type="button" @click="guardar(t)" :disabled="guardando === t.id"
                    class="ml-auto px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-50"
                  >{{ guardando === t.id ? 'Guardando...' : 'Guardar' }}</button>
                </div>
              </div>

              <!-- Nuevo -->
              <div v-if="nuevo" class="rounded-xl border-2 border-dashed border-blue-300 bg-blue-50/40 p-3 space-y-2.5">
                <p class="text-xs font-semibold text-blue-700">Proceso nuevo</p>
                <input v-model="nuevo.nombre" type="text" maxlength="60" placeholder="Nombre (ej: Pulir)"
                  class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <input v-model="nuevo.descripcion" type="text" maxlength="160" placeholder="Descripción (opcional)"
                  class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <EncargadosProceso
                  :ids="nuevo.trabajador_ids ?? []"
                  :lineas="nuevo._lineas ?? {}"
                  :trabajadores="trabajadores"
                  :separa="separaRestauraciones"
                  @update:ids="v => nuevo.trabajador_ids = v"
                  @update:lineas="v => nuevo._lineas = v"
                />
                <div>
                  <label class="block text-[11px] text-gray-500 mb-1">Color</label>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="c in colores" :key="c" type="button" @click="nuevo.color = c"
                      :class="['w-7 h-7 rounded-lg border-2', clasesDeColor(c),
                        nuevo.color === c ? 'border-gray-800 scale-110' : 'border-transparent']"
                      :title="c"
                    />
                  </div>
                </div>
                <div class="flex gap-2">
                  <button @click="nuevo = null" class="flex-1 py-2 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold">Cancelar</button>
                  <button @click="crear" :disabled="guardando === 'nuevo'"
                    class="flex-1 py-2 rounded-lg bg-green-600 text-white text-xs font-semibold hover:bg-green-700 disabled:opacity-50"
                  >{{ guardando === 'nuevo' ? 'Creando...' : 'Crear proceso' }}</button>
                </div>
              </div>

              <button
                v-else @click="empezarNuevo"
                class="w-full py-2.5 rounded-xl border-2 border-dashed border-gray-300 text-sm font-medium text-gray-500 hover:border-blue-400 hover:text-blue-600 flex items-center justify-center gap-1.5"
              ><PlusIcon class="w-4 h-4" /> Agregar proceso</button>

              <p class="text-[11px] text-gray-500 leading-snug pt-1">
                El nombre se puede cambiar cuando quieras: los pasos ya registrados
                lo siguen. Un proceso que ya se usó no se borra — se desactiva y
                deja de ofrecerse, pero el trabajo hecho se conserva.
              </p>
            </template>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
