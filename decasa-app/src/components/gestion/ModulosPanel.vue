<script setup>
/**
 * Cómo se llama cada módulo en esta empresa.
 *
 * El programa hace lo mismo en todos lados —vender, surtir, trasladar,
 * producir— pero cada negocio le dice distinto. Aquí se le cambia el nombre y
 * el icono a cada uno, y se apaga el que no se use.
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
import { Squares2X2Icon, EyeIcon, EyeSlashIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline'

const toast   = useToast()
const modulos = useModulosStore()

const lista     = ref([])
const original  = ref({})     // clave → { nombre, icono, visible }, para saber qué cambió
const cargando  = ref(true)
const guardando = ref(false)
const busqueda  = ref('')

const pickerAbierto = ref(false)
const editandoIcono = ref(null)   // la fila a la que se le está eligiendo icono

const filtrados = computed(() => {
  const term = busqueda.value.trim().toLowerCase()
  if (!term) return lista.value
  return lista.value.filter(m =>
    m.nombre.toLowerCase().includes(term) || m.clave.includes(term)
  )
})

const cambiados = computed(() =>
  lista.value.filter(m => {
    const antes = original.value[m.clave]
    return antes && (antes.nombre !== m.nombre || antes.icono !== m.icono || antes.visible !== m.visible)
  })
)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/modulos')
    lista.value = data
    original.value = Object.fromEntries(
      data.map(m => [m.clave, { nombre: m.nombre, icono: m.icono, visible: m.visible }])
    )
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

onMounted(cargar)
</script>

<template>
  <div class="space-y-3">
    <p class="text-xs text-gray-500">
      Cámbiale el nombre y el icono a cada módulo para que hable como habla tu
      empresa. Apagar uno lo esconde de las pantallas; no le quita el permiso a nadie.
    </p>

    <input
      v-model="busqueda" type="text" placeholder="Buscar módulo..."
      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
    />

    <AppSpinner v-if="cargando" />

    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="m in filtrados" :key="m.clave"
          :class="['bg-white rounded-xl p-3 flex items-center gap-3 shadow-sm border',
            m.visible ? 'border-gray-100' : 'border-dashed border-gray-300 opacity-60']"
        >
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
            <p class="text-[10px] text-gray-400 mt-0.5 truncate">{{ m.clave }}</p>
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
            v-if="original[m.clave] && (original[m.clave].nombre !== m.nombre || original[m.clave].icono !== m.icono || original[m.clave].visible !== m.visible)"
            type="button"
            @click="deshacer(m)"
            class="shrink-0 w-9 h-9 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100"
            title="Dejarlo como estaba"
          >
            <ArrowUturnLeftIcon class="w-4 h-4" />
          </button>
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
  </div>
</template>
