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
import { XMarkIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'

const props = defineProps({ show: Boolean })
const emit  = defineEmits(['close', 'cambiado'])

const toast = useToast()
const { tipos, perfiles, colores, cargar, clasesDeColor } = useTiposProceso()

const cargando = ref(false)
const guardando = ref(null)   // id o 'nuevo'
const nuevo = ref(null)

watch(() => props.show, async (abierto) => {
  if (!abierto) return
  cargando.value = true
  // Con inactivos: aquí es donde se vuelven a encender
  try { await cargar(true, true) } finally { cargando.value = false }
  nuevo.value = null
})

const ordenados = computed(() =>
  [...tipos.value].sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0) || a.nombre.localeCompare(b.nombre, 'es'))
)

function empezarNuevo() {
  nuevo.value = { nombre: '', descripcion: '', color: 'slate', perfiles: [] }
}

function alternarPerfil(obj, perfil) {
  const i = obj.perfiles.indexOf(perfil)
  if (i === -1) obj.perfiles.push(perfil)
  else obj.perfiles.splice(i, 1)
}

async function crear() {
  const n = nuevo.value
  if (!n.nombre.trim())   { toast.error('Ponle un nombre al proceso.'); return }
  if (!n.perfiles.length) { toast.error('Elige quién hace este proceso.'); return }
  guardando.value = 'nuevo'
  try {
    await api.post('/tipos-proceso', {
      nombre: n.nombre.trim(),
      descripcion: n.descripcion.trim() || undefined,
      color: n.color,
      perfiles: n.perfiles,
    })
    await cargar(true, true)
    nuevo.value = null
    toast.success('Proceso creado.')
    emit('cambiado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo crear el proceso.')
  } finally { guardando.value = null }
}

async function guardar(t) {
  if (!t.nombre.trim())   { toast.error('El nombre no puede quedar vacío.'); return }
  if (!t.perfiles.length) { toast.error('Elige quién hace este proceso.'); return }
  guardando.value = t.id
  try {
    await api.patch(`/tipos-proceso/${t.id}`, {
      nombre: t.nombre.trim(),
      descripcion: t.descripcion?.trim() ?? null,
      color: t.color,
      perfiles: t.perfiles,
      orden: Number(t.orden) || 0,
      activo: !!t.activo,
    })
    await cargar(true, true)
    toast.success('Guardado.')
    emit('cambiado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
    await cargar(true, true)
  } finally { guardando.value = null }
}

async function quitar(t) {
  if (!confirm(`¿Quitar "${t.nombre}"?\n\nSi ya se usó en algún paso no se borra: queda desactivado y deja de ofrecerse, pero el trabajo hecho se conserva.`)) return
  guardando.value = t.id
  try {
    const { data } = await api.delete(`/tipos-proceso/${t.id}`)
    await cargar(true, true)
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

                <div>
                  <label class="block text-[11px] text-gray-500 mb-1">Quién lo hace</label>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="p in perfiles" :key="p.clave" type="button" @click="alternarPerfil(t, p.clave)"
                      :class="['px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors',
                        (t.perfiles ?? []).includes(p.clave)
                          ? 'border-blue-500 bg-blue-50 text-blue-700'
                          : 'border-gray-200 bg-white text-gray-500 hover:border-blue-300']"
                    >{{ p.nombre }}</button>
                  </div>
                </div>

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
                <div>
                  <label class="block text-[11px] text-gray-500 mb-1">Quién lo hace</label>
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="p in perfiles" :key="p.clave" type="button" @click="alternarPerfil(nuevo, p.clave)"
                      :class="['px-2.5 py-1 rounded-lg border text-xs font-medium',
                        nuevo.perfiles.includes(p.clave)
                          ? 'border-blue-500 bg-blue-50 text-blue-700'
                          : 'border-gray-200 bg-white text-gray-500']"
                    >{{ p.nombre }}</button>
                  </div>
                </div>
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
