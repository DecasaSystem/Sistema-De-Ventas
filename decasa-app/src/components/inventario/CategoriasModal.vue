<script setup>
/**
 * Inventario → Categorías (supervisor).
 *
 * La categoría es el texto que lleva cada producto; quien se equivocaba al
 * escribirla solo podía arreglarlo producto por producto. Aquí se renombra de
 * una vez en todos sus productos (también los apagados) y, si el nombre nuevo
 * ya existe, se juntan las dos.
 *
 * Se muestra el nombre REAL: varias pastillas de Inventario ponen uno bonito
 * ("Bases comedor" es `comedores`) y sin esto no se sabe qué se está cambiando.
 * Los links ya mandados a clientes y Elena siguen encontrando la categoría con
 * el nombre viejo (lo anota el backend, CategoriasProducto).
 */
import { ref, computed, watch, nextTick } from 'vue'
import { getGestionCategorias, renombrarCategoria } from '@/api/inventario'
import { useToast } from '@/composables/useToast'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { PencilSquareIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show:      { type: Boolean, default: false },
  // El nombre bonito de las pastillas, para decir "se ve como …".
  etiquetas: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['close', 'cambio'])
const toast = useToast()

const categorias = ref([])
const cargando   = ref(false)
const filtro     = ref('')

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getGestionCategorias()
    categorias.value = data ?? []
  } catch {
    toast.error('No se pudieron cargar las categorías')
  } finally {
    cargando.value = false
  }
}

watch(() => props.show, (v) => {
  if (v) { cargar(); cancelar(); filtro.value = '' }
})

// Misma comparación que el backend: sin tildes, mayúsculas ni separadores.
const clave = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '')
  .toLowerCase().replace(/[^a-z0-9]/g, '')

const visibles = computed(() => {
  const f = clave(filtro.value)
  if (!f) return categorias.value
  return categorias.value.filter(c => clave(c.nombre).includes(f) || clave(props.etiquetas[c.nombre]).includes(f))
})

// ── Renombrar ────────────────────────────────────────────────────────────────
const editando  = ref(null)   // nombre de la categoría que se está renombrando
const nuevo     = ref('')
const guardando = ref(false)
const choca     = ref(null)   // { existe } cuando el backend avisa que ya hay otra
const input     = ref(null)

function empezar(cat) {
  editando.value = cat.nombre
  nuevo.value    = cat.nombre
  choca.value    = null
  nextTick(() => { const el = Array.isArray(input.value) ? input.value[0] : input.value; el?.focus(); el?.select() })
}

function cancelar() {
  editando.value = null
  nuevo.value    = ''
  choca.value    = null
}

const actual = computed(() => categorias.value.find(c => c.nombre === editando.value))

// Lo que el link de la sección cambia: si solo cambian tildes o mayúsculas,
// el link es el mismo y no hace falta decir nada.
const cambiaElLink = computed(() =>
  editando.value && clave(nuevo.value) && clave(nuevo.value) !== clave(editando.value))

const puedeGuardar = computed(() =>
  !guardando.value && clave(nuevo.value) !== '' && nuevo.value.trim() !== editando.value)

async function guardar(juntar = false) {
  if (!puedeGuardar.value) return
  guardando.value = true
  try {
    const { data } = await renombrarCategoria({ de: editando.value, a: nuevo.value.trim(), juntar })
    const n = data.productos
    toast.success(data.juntada_con
      ? `Juntadas en "${data.a}" (${n} producto${n === 1 ? '' : 's'})`
      : `Ahora se llama "${data.a}" (${n} producto${n === 1 ? '' : 's'})`)
    emit('cambio', { de: data.de, a: data.a, juntadaCon: data.juntada_con })
    cancelar()
    await cargar()
  } catch (e) {
    if (e?.response?.status === 409) {
      choca.value = { existe: e.response.data?.existe }
    } else {
      const errores = e?.response?.data?.errors
      toast.error(errores ? Object.values(errores).flat()[0] : (e?.response?.data?.message ?? 'No se pudo renombrar'))
    }
  } finally {
    guardando.value = false
  }
}

const productosDe = (nombre) => categorias.value.find(c => c.nombre === nombre)?.productos ?? 0
</script>

<template>
  <Transition name="fade">
    <!-- z-[60]: por encima del botón del asistente (z-50). -->
    <div v-if="show" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="emit('close')">
      <div class="absolute inset-0 bg-black/40" @click="emit('close')" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg max-h-[90vh] flex flex-col">
        <!-- Encabezado -->
        <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
          <div class="min-w-0">
            <h3 class="text-lg font-bold text-gray-800">Categorías</h3>
            <p class="text-xs text-gray-500 mt-0.5">
              Corrige un nombre y cambia en todos sus productos. Si ya existe, se juntan.
            </p>
          </div>
          <button @click="emit('close')" class="text-gray-400 text-2xl leading-none ml-3">&times;</button>
        </div>

        <div v-if="categorias.length > 8" class="px-5 pt-3 flex-shrink-0">
          <div class="relative">
            <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
            <input
              v-model="filtro"
              placeholder="Buscar categoría..."
              class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>

        <div class="overflow-y-auto px-5 py-3 space-y-2">
          <div v-if="cargando && !categorias.length" class="py-8 flex justify-center"><AppSpinner /></div>
          <p v-else-if="!visibles.length" class="text-sm text-gray-400 text-center py-8">
            {{ filtro ? 'Ninguna categoría con ese nombre.' : 'Todavía no hay categorías.' }}
          </p>

          <div
            v-for="cat in visibles" :key="cat.nombre"
            :class="['rounded-xl border px-3 py-2.5', editando === cat.nombre ? 'border-blue-400' : 'border-gray-200']"
          >
            <!-- Fila normal -->
            <div v-if="editando !== cat.nombre" class="flex items-center gap-3">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-800 break-words">{{ cat.nombre }}</p>
                <p class="text-xs text-gray-400">
                  {{ cat.productos }} producto{{ cat.productos === 1 ? '' : 's' }}
                  <template v-if="cat.activos < cat.productos"> · {{ cat.productos - cat.activos }} apagado{{ cat.productos - cat.activos === 1 ? '' : 's' }}</template>
                  <template v-if="etiquetas[cat.nombre] && clave(etiquetas[cat.nombre]) !== clave(cat.nombre)"> · se ve como “{{ etiquetas[cat.nombre] }}”</template>
                </p>
              </div>
              <button
                type="button" @click="empezar(cat)"
                class="flex-shrink-0 flex items-center gap-1 text-xs font-semibold text-blue-600 px-2 py-1.5 rounded-lg hover:bg-gray-100"
              >
                <PencilSquareIcon class="w-4 h-4" /> Renombrar
              </button>
            </div>

            <!-- Renombrando -->
            <div v-else class="space-y-2">
              <label class="block text-xs font-medium text-gray-500">
                Nuevo nombre de “{{ cat.nombre }}” ({{ cat.productos }} producto{{ cat.productos === 1 ? '' : 's' }})
              </label>
              <input
                ref="input"
                v-model="nuevo"
                maxlength="80"
                @input="choca = null"
                @keyup.enter="guardar(false)"
                @keyup.esc="cancelar"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />

              <p v-if="cambiaElLink && !choca" class="text-xs text-gray-500">
                Los links de esta sección que ya mandaste siguen abriendo, y Elena la sigue reconociendo.
              </p>

              <!-- Ya existe otra con ese nombre -->
              <div v-if="choca" class="rounded-lg border border-amber-300 px-3 py-2 space-y-2">
                <p class="text-xs text-amber-700">
                  Ya existe <strong>“{{ choca.existe }}”</strong> con {{ productosDe(choca.existe) }} producto{{ productosDe(choca.existe) === 1 ? '' : 's' }}.
                  Si las juntas, quedan las dos en una sola llamada <strong>“{{ nuevo.trim() }}”</strong>
                  ({{ (actual?.productos ?? 0) + productosDe(choca.existe) }} productos). Después no se pueden separar de un solo paso:
                  habría que cambiar producto por producto.
                </p>
                <div class="flex gap-2">
                  <button
                    type="button" @click="guardar(true)" :disabled="guardando"
                    class="bg-blue-600 text-white rounded-lg text-xs font-semibold px-3 py-2 hover:bg-blue-700 disabled:opacity-50"
                  >
                    {{ guardando ? 'Juntando…' : 'Sí, juntarlas' }}
                  </button>
                  <button type="button" @click="choca = null" class="text-xs font-semibold text-gray-600 px-3 py-2 rounded-lg hover:bg-gray-100">
                    Cambiar el nombre
                  </button>
                </div>
              </div>

              <div v-if="!choca" class="flex justify-end gap-2">
                <button type="button" @click="cancelar" class="text-xs font-semibold text-gray-600 px-3 py-2 rounded-lg hover:bg-gray-100">
                  Cancelar
                </button>
                <button
                  type="button" @click="guardar(false)" :disabled="!puedeGuardar"
                  class="bg-blue-600 text-white rounded-lg text-xs font-semibold px-3 py-2 hover:bg-blue-700 disabled:opacity-50"
                >
                  {{ guardando ? 'Guardando…' : 'Guardar' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>
