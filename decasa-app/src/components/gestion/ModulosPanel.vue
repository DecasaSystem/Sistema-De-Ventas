<script setup>
/**
 * Cómo se llama cada módulo en esta empresa.
 *
 * El programa hace lo mismo en todos lados —vender, surtir, trasladar,
 * producir— pero cada negocio le dice distinto. Aquí se le cambia el nombre y
 * el icono a cada uno, se apaga el que no se use, y se crean módulos nuevos
 * a partir de los que sirven de plantilla: Espumas a partir de Telas, con su
 * propia unidad y sus propios ítems.
 *
 * Apagar no es quitar el permiso: esconde el acceso de las pantallas, pero
 * quien tenga el permiso puede seguir entrando si llega por su cuenta. Quitar
 * permisos se hace en la ficha del trabajador, que es donde se busca.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import { useModulosStore } from '@/stores/modulos'
import { iconoPorNombre } from '@/constants/iconos'
import IconoPicker from '@/components/common/IconoPicker.vue'
import {
  Squares2X2Icon, EyeIcon, EyeSlashIcon, ArrowUturnLeftIcon, PlusIcon, TrashIcon, DocumentDuplicateIcon,
} from '@heroicons/vue/24/outline'

const toast   = useToast()
const modulos = useModulosStore()

/**
 * De qué módulos se puede sacar copia y qué le pide la copia a la empresa.
 * Tiene que coincidir con `Modulo::PLANTILLAS` en el servidor.
 */
const PLANTILLAS = [
  {
    clave:       'telas',
    nombre:      'Inventario por cantidad',
    descripcion: 'Como Telas: marca, tipo, color, foto, y una cantidad que se recarga y se descuenta. Sirve para espumas, hilos, láminas, pinturas...',
    ejemplo:     { nombre: 'Espumas', singular: 'espuma', unidad: 'láminas', decimales: 0, icono: 'CubeIcon' },
  },
]

const lista     = ref([])
const original  = ref({})     // clave → { nombre, icono, visible, config }, para saber qué cambió
const cargando  = ref(true)
const guardando = ref(false)
const busqueda  = ref('')

const pickerAbierto = ref(false)
const editandoIcono = ref(null)   // la fila (o el formulario de crear) a la que se le está eligiendo icono

const filtrados = computed(() => {
  const term = busqueda.value.trim().toLowerCase()
  if (!term) return lista.value
  return lista.value.filter(m =>
    m.nombre.toLowerCase().includes(term) || m.clave.includes(term)
  )
})

function cambio(m) {
  const antes = original.value[m.clave]
  if (!antes) return false
  return antes.nombre !== m.nombre || antes.icono !== m.icono || antes.visible !== m.visible
    || (m.plantilla && JSON.stringify(antes.config) !== JSON.stringify(m.config))
}

const cambiados = computed(() => lista.value.filter(cambio))

function foto(m) {
  return {
    nombre: m.nombre, icono: m.icono, visible: m.visible,
    config: m.config ? { ...m.config } : null,
  }
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/modulos')
    lista.value = data
    original.value = Object.fromEntries(data.map(m => [m.clave, foto(m)]))
  } catch {
    toast.error('No se pudieron cargar los módulos.')
  } finally {
    cargando.value = false
  }
}

function abrirIcono(m) {
  editandoIcono.value = m
  pickerAbierto.value = true
}

function ponerIcono(nombre) {
  if (editandoIcono.value) editandoIcono.value.icono = nombre
}

function deshacer(m) {
  const antes = original.value[m.clave]
  if (!antes) return
  m.nombre  = antes.nombre
  m.icono   = antes.icono
  m.visible = antes.visible
  if (antes.config) m.config = { ...antes.config }
}

async function guardar() {
  if (!cambiados.value.length) return
  // Un módulo sin nombre dejaría un botón mudo en el inicio de todo el mundo.
  const vacio = cambiados.value.find(m => !m.nombre.trim())
  if (vacio) {
    toast.error('Ningún módulo puede quedarse sin nombre.')
    return
  }

  guardando.value = true
  try {
    await api.patch('/modulos', {
      modulos: cambiados.value.map(m => ({
        clave: m.clave, nombre: m.nombre.trim(), icono: m.icono, visible: m.visible,
        ...(m.plantilla ? { config: m.config } : {}),
      })),
    })
    // Que el cambio se vea de una en el menú y en el inicio, sin recargar.
    await modulos.cargar()
    await cargar()
    toast.success('Listo, así se llaman ahora.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
  } finally {
    guardando.value = false
  }
}

// ── Crear un módulo a partir de otro ─────────────────────────────────────────
const abrirCrear = ref(false)
const creando    = ref(false)
const crearError = ref('')
const nuevo      = ref(formularioNuevo(PLANTILLAS[0]))

function formularioNuevo(plantilla) {
  return {
    plantilla: plantilla.clave,
    nombre:    '',
    icono:     plantilla.ejemplo.icono,
    config:    { singular: '', unidad: '', decimales: plantilla.ejemplo.decimales },
  }
}

const plantillaElegida = computed(() => PLANTILLAS.find(p => p.clave === nuevo.value.plantilla) ?? PLANTILLAS[0])

function empezarCrear() {
  nuevo.value      = formularioNuevo(PLANTILLAS[0])
  crearError.value = ''
  abrirCrear.value = true
}

async function crear() {
  crearError.value = ''
  if (!nuevo.value.nombre.trim()) { crearError.value = 'Ponle un nombre al módulo.'; return }

  creando.value = true
  try {
    await api.post('/modulos', {
      plantilla: nuevo.value.plantilla,
      nombre:    nuevo.value.nombre.trim(),
      icono:     nuevo.value.icono,
      config:    nuevo.value.config,
    })
    abrirCrear.value = false
    await modulos.cargar()
    await cargar()
    toast.success(`"${nuevo.value.nombre.trim()}" ya está en el inicio de quien usa ${plantillaNombre(nuevo.value.plantilla)}.`)
  } catch (e) {
    crearError.value = e.response?.data?.message ?? 'No se pudo crear el módulo.'
  } finally {
    creando.value = false
  }
}

/** Cómo se llama en esta empresa el módulo del que nace una copia. */
function plantillaNombre(clave) {
  return lista.value.find(m => m.clave === clave)?.nombre ?? clave
}

// ── Borrar una copia ──────────────────────────────────────────────────────────
const porBorrar = ref(null)

async function borrar() {
  const m = porBorrar.value
  if (!m) return
  try {
    await api.delete(`/modulos/${m.id}`)
    porBorrar.value = null
    await modulos.cargar()
    await cargar()
    toast.success('Eliminado.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo eliminar.')
  }
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-3">
    <p class="text-xs text-gray-500">
      Cámbiale el nombre y el icono a cada módulo para que hable como habla tu
      empresa. Apagar uno lo esconde de las pantallas; no le quita el permiso a nadie.
    </p>

    <div class="flex gap-2">
      <input
        v-model="busqueda" type="text" placeholder="Buscar módulo..."
        class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
      <button
        type="button" @click="empezarCrear"
        class="flex items-center gap-1 px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 whitespace-nowrap"
      >
        <PlusIcon class="w-4 h-4" />
        Crear módulo
      </button>
    </div>

    <AppSpinner v-if="cargando" />

    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="m in filtrados" :key="m.clave"
          :class="['bg-white rounded-xl p-3 shadow-sm border',
            m.visible ? 'border-gray-100' : 'border-dashed border-gray-300 opacity-60']"
        >
          <div class="flex items-center gap-3">
            <button
              type="button"
              @click="abrirIcono(m)"
              class="w-11 h-11 rounded-xl border border-gray-200 flex items-center justify-center text-gray-600 hover:bg-gray-50 shrink-0"
              title="Cambiar el icono"
            >
              <component :is="iconoPorNombre(m.icono) ?? Squares2X2Icon" class="w-6 h-6" />
            </button>

            <div class="flex-1 min-w-0">
              <input
                v-model="m.nombre" type="text" maxlength="60"
                class="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <p class="text-[10px] text-gray-400 mt-0.5 truncate">
                {{ m.clave }}
                <span v-if="m.plantilla" class="text-blue-500"> · a partir de {{ plantillaNombre(m.plantilla) }}</span>
              </p>
            </div>

            <button
              type="button"
              @click="m.visible = !m.visible"
              :class="['shrink-0 w-9 h-9 rounded-lg flex items-center justify-center transition-colors',
                m.visible ? 'text-green-600 hover:bg-green-50' : 'text-gray-400 hover:bg-gray-100']"
              :title="m.visible ? 'Se está mostrando' : 'Está apagado'"
            >
              <component :is="m.visible ? EyeIcon : EyeSlashIcon" class="w-5 h-5" />
            </button>

            <button
              v-if="cambio(m)"
              type="button"
              @click="deshacer(m)"
              class="shrink-0 w-9 h-9 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100"
              title="Dejarlo como estaba"
            >
              <ArrowUturnLeftIcon class="w-4 h-4" />
            </button>

            <!-- Sólo lo que la empresa creó se puede borrar; lo de siempre se apaga. -->
            <button
              v-else-if="m.plantilla"
              type="button"
              @click="porBorrar = m"
              class="shrink-0 w-9 h-9 rounded-lg flex items-center justify-center text-gray-400 hover:bg-red-50 hover:text-red-600"
              title="Eliminar este módulo"
            >
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>

          <!-- Cómo habla la copia: en qué unidad cuenta y cómo se llama una sola cosa -->
          <div v-if="m.plantilla && m.config" class="mt-2 pl-14 grid grid-cols-3 gap-2">
            <label class="block">
              <span class="text-[10px] text-gray-400">Una sola es una...</span>
              <input
                v-model="m.config.singular" type="text" maxlength="40" placeholder="espuma"
                class="w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </label>
            <label class="block">
              <span class="text-[10px] text-gray-400">Se cuenta en</span>
              <input
                v-model="m.config.unidad" type="text" maxlength="12" placeholder="m, unid, kg"
                class="w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </label>
            <label class="block">
              <span class="text-[10px] text-gray-400">Decimales</span>
              <select
                v-model.number="m.config.decimales"
                class="w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option :value="0">0 (enteros)</option>
                <option :value="1">1</option>
                <option :value="2">2</option>
              </select>
            </label>
          </div>
        </li>
      </ul>

      <p v-if="!filtrados.length" class="text-xs text-gray-400 text-center py-6">
        Ningún módulo con ese nombre.
      </p>
    </template>

    <!-- Barra de guardar: sólo aparece si hay algo que guardar -->
    <div v-if="cambiados.length" class="sticky bottom-4 bg-blue-600 text-white rounded-xl px-4 py-3 flex items-center gap-3 shadow-lg">
      <p class="text-sm font-semibold flex-1">
        {{ cambiados.length }} cambio{{ cambiados.length === 1 ? '' : 's' }} sin guardar
      </p>
      <button
        @click="guardar" :disabled="guardando"
        class="bg-white text-blue-700 rounded-lg px-4 py-1.5 text-sm font-bold disabled:opacity-60"
      >
        {{ guardando ? 'Guardando...' : 'Guardar' }}
      </button>
    </div>

    <IconoPicker
      :abierto="pickerAbierto"
      :elegido="editandoIcono?.icono ?? ''"
      @cerrar="pickerAbierto = false"
      @elegir="ponerIcono"
    />

    <!-- Crear un módulo a partir de otro -->
    <Transition name="fade">
      <div v-if="abrirCrear" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="abrirCrear = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4 max-h-[88vh] overflow-y-auto">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">Crear un módulo</h3>
            <button @click="abrirCrear = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <!-- De qué se copia -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Igual a...</label>
            <div class="space-y-2">
              <button
                v-for="p in PLANTILLAS" :key="p.clave"
                type="button"
                @click="nuevo = formularioNuevo(p)"
                :class="['w-full text-left rounded-xl border-2 p-3 transition-colors',
                  nuevo.plantilla === p.clave ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300']"
              >
                <p class="text-sm font-semibold text-gray-800 flex items-center gap-2">
                  <DocumentDuplicateIcon class="w-4 h-4 text-blue-600" />
                  {{ plantillaNombre(p.clave) }} <span class="text-gray-400 font-normal">· {{ p.nombre }}</span>
                </p>
                <p class="text-xs text-gray-500 mt-1">{{ p.descripcion }}</p>
              </button>
            </div>
          </div>

          <!-- Nombre e icono -->
          <div class="flex items-start gap-3">
            <button
              type="button"
              @click="abrirIcono(nuevo)"
              class="w-12 h-12 rounded-xl border border-gray-200 flex items-center justify-center text-gray-600 hover:bg-gray-50 shrink-0"
              title="Elegir o dibujar el icono"
            >
              <component :is="iconoPorNombre(nuevo.icono) ?? Squares2X2Icon" class="w-7 h-7" />
            </button>
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
              <input
                v-model="nuevo.nombre" type="text" maxlength="60"
                :placeholder="`Ej: ${plantillaElegida.ejemplo.nombre}`"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <p class="text-[11px] text-gray-400 mt-1">Toca el cuadro para elegir un icono de la lista o dibujar el tuyo.</p>
            </div>
          </div>

          <!-- Cómo cuenta -->
          <div class="grid grid-cols-3 gap-2">
            <label class="block">
              <span class="text-xs font-medium text-gray-700">Una sola es una...</span>
              <input
                v-model="nuevo.config.singular" type="text" maxlength="40"
                :placeholder="plantillaElegida.ejemplo.singular"
                class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-gray-700">Se cuenta en</span>
              <input
                v-model="nuevo.config.unidad" type="text" maxlength="12"
                :placeholder="plantillaElegida.ejemplo.unidad"
                class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-gray-700">Decimales</span>
              <select
                v-model.number="nuevo.config.decimales"
                class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option :value="0">0 (enteros)</option>
                <option :value="1">1</option>
                <option :value="2">2</option>
              </select>
            </label>
          </div>
          <p class="text-[11px] text-gray-400">
            Lo que dejes vacío lo hereda de {{ plantillaNombre(nuevo.plantilla) }}. Todo esto se puede cambiar después.
          </p>

          <p class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
            Entra a este módulo quien entra a {{ plantillaNombre(nuevo.plantilla) }}, con los mismos permisos.
            Sus ítems son suyos: no se mezclan con los de {{ plantillaNombre(nuevo.plantilla) }}.
          </p>

          <p v-if="crearError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ crearError }}</p>

          <div class="flex gap-3">
            <button @click="abrirCrear = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button
              @click="crear" :disabled="creando"
              class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
            >
              {{ creando ? 'Creando...' : 'Crear módulo' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Confirmar borrado -->
    <Transition name="fade">
      <div v-if="porBorrar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="porBorrar = null">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <h3 class="text-base font-bold text-gray-800">¿Eliminar "{{ porBorrar.nombre }}"?</h3>
          <p class="text-xs text-gray-500">
            Se borra el módulo con todo lo que tiene adentro, y no hay cómo
            recuperarlo. Si sólo quieres que deje de salir, apágalo con el ojo
            y sus ítems se quedan guardados.
          </p>
          <div class="flex gap-3">
            <button @click="porBorrar = null" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button @click="borrar" class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-red-700">
              Eliminar
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
