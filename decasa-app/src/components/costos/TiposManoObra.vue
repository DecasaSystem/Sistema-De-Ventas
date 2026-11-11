<script setup>
/**
 * Mano de obra sin costo fijo (Esqueletería, Tapicería…). Aquí solo se crean los tipos; el
 * costo se escribe en cada ficha al agregarla (usuario, 2026-11-11: "no tienen un costo fijo
 * ni calculable"). Renombrar cambia el nombre en las fichas; archivar deja de ofrecerlo sin
 * tocar las fichas que ya lo tienen.
 */
import { ref, computed, onMounted } from 'vue'
import { PlusIcon, PencilSquareIcon, ArchiveBoxIcon, ArrowUturnLeftIcon, TrashIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { crearTipoManoObra, editarTipoManoObra, borrarTipoManoObra } from '@/api/configuracion'
import { useToast } from '@/composables/useToast'
import { useCostos } from './useCostos'

const toast = useToast()
const { tiposManoObra, cargarTiposManoObra } = useCostos()

onMounted(() => cargarTiposManoObra(true))

const activos    = computed(() => tiposManoObra.value.filter(t => t.activo))
const archivados = computed(() => tiposManoObra.value.filter(t => !t.activo))
const verArchivados = ref(false)

const nuevo     = ref('')
const editando  = ref(null)   // { id, nombre }
const ocupado   = ref(null)

async function crear() {
  const nombre = nuevo.value.trim()
  if (!nombre) return
  ocupado.value = 'nuevo'
  try {
    await crearTipoManoObra(nombre)
    nuevo.value = ''
    await cargarTiposManoObra(true)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo crear.')
  } finally {
    ocupado.value = null
  }
}

async function guardarNombre() {
  const { id, nombre } = editando.value
  if (!nombre.trim()) return
  ocupado.value = id
  try {
    const { data } = await editarTipoManoObra(id, { nombre: nombre.trim() })
    editando.value = null
    await cargarTiposManoObra(true)
    if (data.usos) toast.success(`Renombrado también en ${data.usos} ${data.usos === 1 ? 'ficha' : 'fichas'}`)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo renombrar.')
  } finally {
    ocupado.value = null
  }
}

async function archivar(t, activo) {
  ocupado.value = t.id
  try {
    await editarTipoManoObra(t.id, { activo })
    await cargarTiposManoObra(true)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo cambiar.')
  } finally {
    ocupado.value = null
  }
}

async function borrar(t) {
  if (!confirm(`¿Borrar «${t.nombre}»?`)) return
  ocupado.value = t.id
  try {
    await borrarTipoManoObra(t.id)
    await cargarTiposManoObra(true)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo borrar.')
  } finally {
    ocupado.value = null
  }
}
</script>

<template>
  <section class="bg-white rounded-xl shadow-sm p-4 space-y-3">
    <div>
      <h2 class="text-sm font-semibold text-gray-800">Mano de obra por trabajo</h2>
      <p class="text-xs text-gray-500 mt-0.5">
        Para la que no tiene un costo fijo (esqueletería, tapicería…). Al agregar mano de obra a una ficha
        escoges uno de estos y escribes lo que cuesta en ese mueble.
      </p>
    </div>

    <ul v-if="activos.length" class="rounded-xl border border-gray-200 divide-y divide-gray-100">
      <li v-for="t in activos" :key="t.id" class="flex items-center gap-2 px-3 py-2">
        <template v-if="editando?.id === t.id">
          <input v-model="editando.nombre" @keyup.enter="guardarNombre" @keyup.esc="editando = null" type="text" maxlength="100"
            :aria-label="`Nuevo nombre de ${t.nombre}`" autofocus
            class="flex-1 min-w-0 rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          <button @click="guardarNombre" :disabled="ocupado === t.id" class="p-1.5 text-green-600 hover:bg-gray-100 rounded" aria-label="Guardar nombre"><CheckIcon class="w-4 h-4" /></button>
          <button @click="editando = null" class="p-1.5 text-gray-400 hover:bg-gray-100 rounded" aria-label="Cancelar"><XMarkIcon class="w-4 h-4" /></button>
        </template>
        <template v-else>
          <span class="flex-1 min-w-0">
            <span class="block text-sm text-gray-800 truncate">{{ t.nombre }}</span>
            <span class="block text-xs text-gray-400">{{ t.usos ? `En ${t.usos} ${t.usos === 1 ? 'ficha' : 'fichas'}` : 'Sin usar' }}</span>
          </span>
          <button @click="editando = { id: t.id, nombre: t.nombre }" class="p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded"
            :aria-label="`Renombrar ${t.nombre}`" title="Renombrar"><PencilSquareIcon class="w-4 h-4" /></button>
          <button v-if="t.usos" @click="archivar(t, false)" :disabled="ocupado === t.id" class="p-1.5 text-gray-400 hover:text-amber-700 hover:bg-gray-100 rounded"
            :aria-label="`Archivar ${t.nombre}`" title="Archivar: deja de ofrecerse, las fichas siguen igual"><ArchiveBoxIcon class="w-4 h-4" /></button>
          <button v-else @click="borrar(t)" :disabled="ocupado === t.id" class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-gray-100 rounded"
            :aria-label="`Borrar ${t.nombre}`" title="Borrar"><TrashIcon class="w-4 h-4" /></button>
        </template>
      </li>
    </ul>
    <p v-else class="text-xs text-gray-400">Todavía no hay ninguno.</p>

    <form @submit.prevent="crear" class="flex gap-2">
      <input v-model="nuevo" type="text" maxlength="100" placeholder="Esqueletería, Tapicería…" aria-label="Nuevo tipo de mano de obra"
        class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      <button type="submit" :disabled="!nuevo.trim() || ocupado === 'nuevo'"
        class="flex items-center gap-1 px-3 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 disabled:opacity-40">
        <PlusIcon class="w-4 h-4" />Agregar
      </button>
    </form>

    <div v-if="archivados.length">
      <button @click="verArchivados = !verArchivados" class="text-xs font-semibold text-gray-500 hover:text-gray-700">
        {{ verArchivados ? 'Ocultar' : 'Ver' }} archivados ({{ archivados.length }})
      </button>
      <ul v-if="verArchivados" class="mt-2 rounded-xl border border-gray-200 divide-y divide-gray-100">
        <li v-for="t in archivados" :key="t.id" class="flex items-center gap-2 px-3 py-2">
          <span class="flex-1 min-w-0 text-sm text-gray-500 truncate">{{ t.nombre }}</span>
          <button @click="archivar(t, true)" :disabled="ocupado === t.id"
            class="flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800">
            <ArrowUturnLeftIcon class="w-3.5 h-3.5" />Activar
          </button>
        </li>
      </ul>
    </div>
  </section>
</template>
