<script setup>
import { ref, computed, nextTick } from 'vue'
import {
  XMarkIcon, PlusIcon, TrashIcon, PencilIcon, CheckIcon,
  ChevronDownIcon, ChevronRightIcon, MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'
import api from '@/api'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['close'])
const toast = useToast()

const tipos      = ref([])
const loadingTipos = ref(true)
const tipoAbierto  = ref(null)

// ── Búsqueda ──────────────────────────────────────────────────────────────────
const busqueda = ref('')

function normalizar(txt) {
  return (txt ?? '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

function opcionesFiltradas(tipo) {
  const q = normalizar(busqueda.value.trim())
  if (!q || normalizar(tipo.nombre).includes(q)) return tipo.opciones
  return tipo.opciones.filter(op => normalizar(op.nombre).includes(q))
}

const tiposFiltrados = computed(() => {
  const q = normalizar(busqueda.value.trim())
  if (!q) return tipos.value
  return tipos.value.filter(tipo => normalizar(tipo.nombre).includes(q) || opcionesFiltradas(tipo).length)
})

function estaAbierto(tipo) {
  return tipoAbierto.value === tipo.id || !!busqueda.value.trim()
}

async function cargarTipos() {
  loadingTipos.value = true
  try {
    const { data } = await api.get('/tipos-variante')
    tipos.value = data
  } catch {}
  loadingTipos.value = false
}
cargarTipos()

// ── Crear tipo ────────────────────────────────────────────────────────────────
const nuevoTipoNombre     = ref('')
const nuevoTipoAfectaPrecio = ref(true)
const creandoTipo         = ref(false)
const errorTipo           = ref('')

async function crearTipo() {
  const nombre = nuevoTipoNombre.value.trim()
  if (!nombre) return
  errorTipo.value = ''
  creandoTipo.value = true
  try {
    await api.post('/tipos-variante', { nombre, afecta_precio: nuevoTipoAfectaPrecio.value })
    nuevoTipoNombre.value = ''
    nuevoTipoAfectaPrecio.value = true
    await cargarTipos()
    toast.success(`Tipo "${nombre}" creado`)
  } catch (e) {
    errorTipo.value = e.response?.data?.message ?? 'Error al crear'
  } finally {
    creandoTipo.value = false
  }
}

async function eliminarTipo(id, nombre) {
  try {
    await api.delete(`/tipos-variante/${id}`)
    await cargarTipos()
    toast.success(`Tipo "${nombre}" eliminado`)
  } catch {
    toast.error('No se pudo eliminar')
  }
}

// ── Opciones de un tipo ───────────────────────────────────────────────────────
const opcionInput    = ref({})  // { [tipoId]: string }
const opcionChips    = ref({})  // { [tipoId]: string[] }
const opcionInputEl  = ref({})
const guardandoOpc   = ref({})

function getChips(tipoId) {
  return opcionChips.value[tipoId] ?? []
}
function getInput(tipoId) {
  return opcionInput.value[tipoId] ?? ''
}

function onOpcionKey(e, tipoId) {
  const chips = opcionChips.value[tipoId] ?? []
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault()
    const v = (opcionInput.value[tipoId] ?? '').trim()
    if (v && !chips.map(c => c.toLowerCase()).includes(v.toLowerCase())) {
      opcionChips.value[tipoId] = [...chips, v]
    }
    opcionInput.value[tipoId] = ''
  } else if (e.key === 'Backspace' && !(opcionInput.value[tipoId] ?? '').length && chips.length) {
    opcionChips.value[tipoId] = chips.slice(0, -1)
  }
}

function quitarChipOpcion(tipoId, i) {
  opcionChips.value[tipoId] = (opcionChips.value[tipoId] ?? []).filter((_, idx) => idx !== i)
}

async function guardarOpciones(tipo) {
  const v = (opcionInput.value[tipo.id] ?? '').trim()
  if (v) {
    const chips = opcionChips.value[tipo.id] ?? []
    if (!chips.map(c => c.toLowerCase()).includes(v.toLowerCase())) {
      opcionChips.value[tipo.id] = [...chips, v]
    }
    opcionInput.value[tipo.id] = ''
  }

  const opciones = (opcionChips.value[tipo.id] ?? [])
    .filter(c => !tipo.opciones.some(o => o.nombre.toLowerCase() === c.toLowerCase()))

  if (!opciones.length) {
    toast.warning('No hay opciones nuevas para agregar')
    return
  }

  guardandoOpc.value[tipo.id] = true
  try {
    const { data } = await api.post(`/tipos-variante/${tipo.id}/opciones`, { opciones })
    const idx = tipos.value.findIndex(t => t.id === tipo.id)
    if (idx !== -1) tipos.value[idx] = data
    opcionChips.value[tipo.id] = []
    toast.success(`${opciones.length} opción${opciones.length > 1 ? 'es' : ''} agregada${opciones.length > 1 ? 's' : ''}`)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al guardar')
  } finally {
    guardandoOpc.value[tipo.id] = false
  }
}

// ── Renombrar tipo u opción ───────────────────────────────────────────────────
// Una sola edición a la vez: { clase: 'tipo' | 'opcion', id, nombre }
const edicion        = ref(null)
const guardandoEdic  = ref(false)
const edicionInput   = ref(null)
// El campo vive dentro de un v-for: con ref de función queda uno solo, no una lista.
const fijarEdicionInput = el => { if (el) edicionInput.value = el }

function editar(clase, item) {
  edicion.value = { clase, id: item.id, nombre: item.nombre, original: item.nombre }
  nextTick(() => { edicionInput.value?.focus(); edicionInput.value?.select() })
}

function editando(clase, id) {
  return edicion.value?.clase === clase && edicion.value.id === id
}

async function guardarEdicion() {
  const e = edicion.value
  if (!e || guardandoEdic.value) return
  const nombre = e.nombre.trim()
  if (!nombre || nombre === e.original) { edicion.value = null; return }

  guardandoEdic.value = true
  try {
    const url = e.clase === 'tipo' ? `/tipos-variante/${e.id}` : `/tipos-variante/opciones/${e.id}`
    const { data } = await api.patch(url, { nombre })
    // Las dos rutas devuelven el tipo completo con sus opciones.
    const idx = tipos.value.findIndex(t => t.id === data.id)
    if (idx !== -1) tipos.value[idx] = data
    if (e.clase === 'tipo') tipos.value.sort((a, b) => a.nombre.localeCompare(b.nombre))
    edicion.value = null
    toast.success('Nombre actualizado')
  } catch (err) {
    toast.error(err.response?.data?.errors?.nombre?.[0] ?? err.response?.data?.message ?? 'No se pudo renombrar')
  } finally {
    guardandoEdic.value = false
  }
}

async function eliminarOpcion(tipo, opcion) {
  try {
    await api.delete(`/tipos-variante/opciones/${opcion.id}`)
    const t = tipos.value.find(t => t.id === tipo.id)
    if (t) t.opciones = t.opciones.filter(o => o.id !== opcion.id)
    toast.success(`Opción "${opcion.nombre}" eliminada`)
  } catch {
    toast.error('No se pudo eliminar')
  }
}


</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl max-h-[90vh] flex flex-col">

      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-4 border-b shrink-0">
        <h3 class="text-base font-bold text-gray-800">Configuración de variantes</h3>
        <button @click="emit('close')" class="text-gray-400 hover:text-gray-600 p-1"><XMarkIcon class="w-5 h-5" /></button>
      </div>

      <div class="overflow-y-auto flex-1 px-5 py-4 space-y-5">

          <!-- Crear tipo nuevo -->
          <div class="bg-blue-50 rounded-xl p-4 space-y-3">
            <p class="text-sm font-semibold text-blue-800">Nuevo tipo de variante</p>

            <input
              v-model="nuevoTipoNombre"
              placeholder="Nombre (ej: Alerones, Color, Tipo de madera)"
              @keyup.enter="crearTipo"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
            />

            <label class="flex items-center gap-3 cursor-pointer select-none">
              <span class="text-sm text-gray-700">¿Afecta el precio?</span>
              <button
                type="button"
                @click="nuevoTipoAfectaPrecio = !nuevoTipoAfectaPrecio"
                :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  nuevoTipoAfectaPrecio ? 'bg-blue-600' : 'bg-gray-300']"
              >
                <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                  nuevoTipoAfectaPrecio ? 'translate-x-6' : 'translate-x-1']" />
              </button>
              <span class="text-xs text-gray-500">{{ nuevoTipoAfectaPrecio ? 'Sí, cambia el precio' : 'No, solo diferencia visual' }}</span>
            </label>

            <p v-if="errorTipo" class="text-xs text-red-600">{{ errorTipo }}</p>

            <button
              @click="crearTipo"
              :disabled="creandoTipo || !nuevoTipoNombre.trim()"
              class="w-full flex items-center justify-center gap-1.5 bg-blue-600 text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >
              <PlusIcon class="w-4 h-4" />
              {{ creandoTipo ? 'Creando…' : 'Crear tipo' }}
            </button>
          </div>

          <!-- Listado de tipos existentes -->
          <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Tipos existentes</p>

            <div v-if="!loadingTipos && tipos.length" class="relative mb-3">
              <MagnifyingGlassIcon class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                v-model="busqueda"
                type="text"
                placeholder="Buscar tipo u opción…"
                class="w-full border border-gray-300 rounded-lg pl-9 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
              />
              <button
                v-if="busqueda"
                @click="busqueda = ''"
                class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-300 hover:text-gray-500"
              >
                <XMarkIcon class="w-4 h-4" />
              </button>
            </div>

            <div v-if="loadingTipos" class="space-y-2">
              <div v-for="i in 3" :key="i" class="h-10 bg-gray-100 rounded-lg animate-pulse" />
            </div>

            <div v-else-if="!tipos.length" class="text-sm text-gray-400 text-center py-4">
              No hay tipos creados aún.
            </div>

            <div v-else-if="!tiposFiltrados.length" class="text-sm text-gray-400 text-center py-4">
              Sin resultados para "{{ busqueda }}".
            </div>

            <div v-else class="space-y-2">
              <div v-for="tipo in tiposFiltrados" :key="tipo.id" class="border border-gray-200 rounded-xl overflow-hidden">

                <!-- Cabecera del tipo -->
                <!-- Renombrando el tipo -->
                <form v-if="editando('tipo', tipo.id)" @submit.prevent="guardarEdicion" class="flex items-center gap-2 px-3 py-2 bg-gray-50">
                  <input
                    :ref="fijarEdicionInput"
                    v-model="edicion.nombre"
                    maxlength="100"
                    @keydown.esc="edicion = null"
                    class="flex-1 min-w-0 border border-gray-300 rounded-lg px-3 py-1.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                  />
                  <button type="submit" :disabled="guardandoEdic || !edicion.nombre.trim()" title="Guardar"
                    class="p-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">
                    <CheckIcon class="w-4 h-4" />
                  </button>
                  <button type="button" @click="edicion = null" title="Cancelar" class="p-1.5 text-gray-400 hover:text-gray-600">
                    <XMarkIcon class="w-4 h-4" />
                  </button>
                </form>

                <div v-else class="flex items-center gap-1 pl-3 pr-2 py-2.5 bg-gray-50">
                  <button @click="tipoAbierto = tipoAbierto === tipo.id ? null : tipo.id" class="flex-1 min-w-0 flex items-center gap-2 text-left">
                    <component :is="estaAbierto(tipo) ? ChevronDownIcon : ChevronRightIcon" class="w-4 h-4 text-gray-400 shrink-0" />
                    <span class="text-sm font-semibold text-gray-800 truncate">{{ tipo.nombre }}</span>
                    <span :class="['text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap flex-shrink-0',
                      tipo.afecta_precio ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500']">
                      {{ tipo.afecta_precio ? 'Afecta precio' : 'Solo diferencia' }}
                    </span>
                    <span class="text-xs text-gray-400 ml-auto whitespace-nowrap flex-shrink-0">{{ tipo.opciones.length }} opc.</span>
                  </button>
                  <button @click="editar('tipo', tipo)" class="text-gray-400 hover:text-blue-600 transition-colors p-1" title="Cambiar nombre">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button @click="eliminarTipo(tipo.id, tipo.nombre)" class="text-gray-300 hover:text-red-500 transition-colors p-1" title="Eliminar tipo">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>

                <!-- Cuerpo: opciones -->
                <div v-if="estaAbierto(tipo)" class="px-4 pb-4 pt-3 space-y-3">

                  <!-- Opciones existentes -->
                  <div v-if="opcionesFiltradas(tipo).length" class="flex flex-wrap gap-1.5">
                    <template v-for="op in opcionesFiltradas(tipo)" :key="op.id">
                      <!-- Renombrando la opción -->
                      <form
                        v-if="editando('opcion', op.id)"
                        @submit.prevent="guardarEdicion"
                        class="flex items-center gap-1 border border-blue-400 rounded-full pl-2.5 pr-1 py-0.5 bg-white"
                      >
                        <input
                          :ref="fijarEdicionInput"
                          v-model="edicion.nombre"
                          maxlength="100"
                          @keydown.esc="edicion = null"
                          class="w-32 text-xs outline-none bg-transparent py-0.5"
                        />
                        <button type="submit" :disabled="guardandoEdic || !edicion.nombre.trim()" title="Guardar"
                          class="p-0.5 rounded-full text-blue-600 hover:bg-blue-50 disabled:opacity-50">
                          <CheckIcon class="w-3.5 h-3.5" />
                        </button>
                        <button type="button" @click="edicion = null" title="Cancelar" class="p-0.5 text-gray-400 hover:text-gray-600">
                          <XMarkIcon class="w-3.5 h-3.5" />
                        </button>
                      </form>
                      <span v-else class="flex items-center gap-1 text-xs bg-white border border-gray-200 rounded-full pl-2.5 pr-1.5 py-1">
                        <button @click="editar('opcion', op)" class="flex items-center gap-1 hover:text-blue-600" title="Cambiar nombre">
                          {{ op.nombre }}
                          <PencilIcon class="w-3 h-3 text-gray-300" />
                        </button>
                        <button @click="eliminarOpcion(tipo, op)" class="text-gray-300 hover:text-red-500 transition-colors" title="Eliminar opción">
                          <TrashIcon class="w-3 h-3" />
                        </button>
                      </span>
                    </template>
                  </div>
                  <p v-else-if="busqueda.trim()" class="text-xs text-gray-400">Sin opciones que coincidan con la búsqueda.</p>
                  <p v-else class="text-xs text-gray-400">Sin opciones aún.</p>

                  <!-- Chip input para agregar opciones -->
                  <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">
                      Agregar opciones <span class="font-normal text-gray-400">— Enter o coma para agregar</span>
                    </label>
                    <div
                      class="min-h-[40px] w-full border border-gray-300 rounded-lg px-2 py-1.5 bg-white flex flex-wrap gap-1.5 items-center cursor-text focus-within:ring-2 focus-within:ring-blue-500"
                      @click="$refs['opcionInputEl_' + tipo.id]?.[0]?.focus()"
                    >
                      <span
                        v-for="(chip, ci) in getChips(tipo.id)"
                        :key="ci"
                        class="inline-flex items-center gap-1 text-xs bg-blue-100 text-blue-800 border border-blue-200 rounded-full px-2.5 py-1 font-medium"
                      >
                        {{ chip }}
                        <button type="button" @click.stop="quitarChipOpcion(tipo.id, ci)" class="hover:text-red-500">
                          <XMarkIcon class="w-3 h-3" />
                        </button>
                      </span>
                      <input
                        :ref="'opcionInputEl_' + tipo.id"
                        v-model="opcionInput[tipo.id]"
                        placeholder="Escribe una opción…"
                        class="flex-1 min-w-[120px] text-sm outline-none bg-transparent py-0.5"
                        @keydown="onOpcionKey($event, tipo.id)"
                        @blur="() => { const v = (opcionInput[tipo.id]??'').trim(); if(v){ const ch=opcionChips.value[tipo.id]??[]; if(!ch.map(c=>c.toLowerCase()).includes(v.toLowerCase())) opcionChips.value[tipo.id]=[...ch,v]; opcionInput.value[tipo.id]='' } }"
                      />
                    </div>
                  </div>

                  <button
                    @click="guardarOpciones(tipo)"
                    :disabled="guardandoOpc[tipo.id] || (!getChips(tipo.id).length && !(opcionInput[tipo.id]??'').trim())"
                    class="w-full flex items-center justify-center gap-1.5 bg-blue-600 text-white text-sm font-semibold px-4 py-1.5 rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
                  >
                    <PlusIcon class="w-4 h-4" />
                    {{ guardandoOpc[tipo.id] ? 'Guardando…' : 'Agregar opciones' }}
                  </button>
                </div>
              </div>
            </div>
          </div>

      </div>
    </div>
  </div>
</template>
