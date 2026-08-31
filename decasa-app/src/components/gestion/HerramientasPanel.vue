<script setup>
/**
 * Lo que el asesor tiene a mano para copiar mientras atiende.
 *
 * Cada empresa arma la suya: una mueblería pone sus sedes y su política de
 * envíos, una de tecnología pondrá garantías y fichas técnicas. Por eso la
 * sección es texto libre —agrupar es cosa de cada negocio— y el tipo sólo
 * decide qué se puede hacer además de copiar: abrir el mapa o abrir el enlace.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import { iconoPorNombre } from '@/constants/iconos'
import IconoPicker from '@/components/common/IconoPicker.vue'
import {
  Squares2X2Icon, PlusIcon, PencilIcon, TrashIcon,
  EyeIcon, EyeSlashIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()

const lista    = ref([])
const cargando = ref(true)

const TIPOS = [
  { valor: 'texto',     label: 'Texto',      ayuda: 'Se copia tal cual (un horario, una garantía)' },
  { valor: 'direccion', label: 'Dirección',  ayuda: 'Se copia y se abre en el mapa' },
  { valor: 'enlace',    label: 'Enlace',     ayuda: 'Se copia y se abre en otra pestaña' },
]

const vacia = () => ({
  id: null, seccion: '', titulo: '', tipo: 'texto',
  contenido: '', subtitulo: '', icono: 'Squares2X2Icon', activo: true,
})

const form          = ref(vacia())
const mostrarForm   = ref(false)
const guardando     = ref(false)
const pickerAbierto = ref(false)
const porBorrar     = ref(null)

/** Las secciones que ya existen, para no tener que escribirlas de nuevo. */
const secciones = computed(() => [...new Set(lista.value.map(h => h.seccion))].sort())

const agrupadas = computed(() => {
  const mapa = new Map()
  for (const h of lista.value) {
    if (!mapa.has(h.seccion)) mapa.set(h.seccion, [])
    mapa.get(h.seccion).push(h)
  }
  return [...mapa.entries()].map(([nombre, items]) => ({ nombre, items }))
})

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/herramientas', { params: { todas: 1 } })
    lista.value = data
  } catch {
    toast.error('No se pudieron cargar las herramientas.')
  } finally {
    cargando.value = false
  }
}

function abrirNueva(seccion = '') {
  form.value = { ...vacia(), seccion }
  mostrarForm.value = true
}

function abrirEditar(h) {
  form.value = { ...h, subtitulo: h.subtitulo ?? '', icono: h.icono ?? 'Squares2X2Icon' }
  mostrarForm.value = true
}

async function guardar() {
  const f = form.value
  if (!f.seccion.trim() || !f.titulo.trim() || !f.contenido.trim()) {
    toast.error('Faltan la sección, el título o el contenido.')
    return
  }
  guardando.value = true
  try {
    const cuerpo = {
      seccion: f.seccion.trim(), titulo: f.titulo.trim(), tipo: f.tipo,
      contenido: f.contenido.trim(), subtitulo: f.subtitulo.trim() || null,
      icono: f.icono, activo: f.activo,
    }
    if (f.id) await api.patch(`/herramientas/${f.id}`, cuerpo)
    else      await api.post('/herramientas', cuerpo)

    mostrarForm.value = false
    await cargar()
    toast.success(f.id ? 'Guardado.' : 'Herramienta creada.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
  } finally {
    guardando.value = false
  }
}

/** Apagar en vez de borrar: deja de salirle al asesor sin perder el texto. */
async function alternarActivo(h) {
  const antes = h.activo
  h.activo = !antes
  try {
    await api.patch(`/herramientas/${h.id}`, { activo: h.activo })
  } catch {
    h.activo = antes
    toast.error('No se pudo cambiar.')
  }
}

async function borrar() {
  const h = porBorrar.value
  if (!h) return
  try {
    await api.delete(`/herramientas/${h.id}`)
    porBorrar.value = null
    await cargar()
    toast.success('Eliminada.')
  } catch {
    toast.error('No se pudo eliminar.')
  }
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-start gap-2">
      <p class="text-xs text-gray-500 flex-1">
        Lo que el asesor copia mientras atiende: direcciones, horarios, formas de
        pago, enlaces. Se agrupa por secciones que tú mismo nombras.
      </p>
      <button
        @click="abrirNueva()"
        class="shrink-0 bg-blue-600 text-white rounded-lg px-3 py-1.5 text-sm font-semibold hover:bg-blue-700 flex items-center gap-1"
      >
        <PlusIcon class="w-4 h-4" /> Nueva
      </button>
    </div>

    <AppSpinner v-if="cargando" />

    <template v-else>
      <section v-for="g in agrupadas" :key="g.nombre" class="space-y-2">
        <div class="flex items-center gap-2">
          <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wide flex-1">{{ g.nombre }}</h4>
          <button @click="abrirNueva(g.nombre)" class="text-xs text-blue-600 font-medium hover:text-blue-700">
            + Agregar aquí
          </button>
        </div>

        <div
          v-for="h in g.items" :key="h.id"
          :class="['bg-white rounded-xl p-3 shadow-sm border flex items-start gap-3',
            h.activo ? 'border-gray-100' : 'border-dashed border-gray-300 opacity-60']"
        >
          <component :is="iconoPorNombre(h.icono) ?? Squares2X2Icon" class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" />

          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800">{{ h.titulo }}</p>
            <p class="text-xs text-gray-500 mt-0.5 line-clamp-2 whitespace-pre-line break-words">{{ h.contenido }}</p>
            <span class="inline-block mt-1 text-[10px] font-semibold text-gray-400 uppercase">{{ h.tipo }}</span>
          </div>

          <div class="flex flex-col gap-1 shrink-0">
            <button @click="abrirEditar(h)" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-500 hover:bg-gray-100" title="Editar">
              <PencilIcon class="w-4 h-4" />
            </button>
            <button
              @click="alternarActivo(h)"
              :class="['w-8 h-8 rounded-lg flex items-center justify-center', h.activo ? 'text-green-600 hover:bg-green-50' : 'text-gray-400 hover:bg-gray-100']"
              :title="h.activo ? 'Se está mostrando' : 'Está apagada'"
            >
              <component :is="h.activo ? EyeIcon : EyeSlashIcon" class="w-4 h-4" />
            </button>
            <button @click="porBorrar = h" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:bg-red-50 hover:text-red-500" title="Eliminar">
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </div>
      </section>

      <p v-if="!agrupadas.length" class="text-xs text-gray-400 text-center py-6">
        Todavía no hay herramientas. Crea la primera con el botón de arriba.
      </p>
    </template>

    <!-- Crear / editar -->
    <Transition name="fade">
      <div v-if="mostrarForm" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="mostrarForm = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-3 max-h-[90vh] overflow-y-auto">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">{{ form.id ? 'Editar' : 'Nueva herramienta' }}</h3>
            <button @click="mostrarForm = false" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="w-5 h-5" /></button>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Sección</label>
            <input
              v-model="form.seccion" list="secciones-existentes" maxlength="60"
              placeholder="Sedes, Textos rápidos, Garantías..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <datalist id="secciones-existentes">
              <option v-for="s in secciones" :key="s" :value="s" />
            </datalist>
          </div>

          <div class="flex gap-2">
            <button
              type="button" @click="pickerAbierto = true"
              class="w-11 h-11 rounded-xl border border-gray-300 flex items-center justify-center text-gray-600 hover:bg-gray-50 shrink-0 mt-5"
              title="Elegir icono"
            >
              <component :is="iconoPorNombre(form.icono) ?? Squares2X2Icon" class="w-6 h-6" />
            </button>
            <div class="flex-1">
              <label class="block text-xs font-semibold text-gray-600 mb-1">Título</label>
              <input
                v-model="form.titulo" maxlength="120" placeholder="Horario de atención"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Qué es</label>
            <div class="grid grid-cols-3 gap-2">
              <button
                v-for="t in TIPOS" :key="t.valor"
                type="button" @click="form.tipo = t.valor"
                :class="['rounded-lg border px-2 py-1.5 text-xs font-semibold transition-colors',
                  form.tipo === t.valor ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50']"
              >
                {{ t.label }}
              </button>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">{{ TIPOS.find(t => t.valor === form.tipo)?.ayuda }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">
              {{ form.tipo === 'direccion' ? 'Dirección' : form.tipo === 'enlace' ? 'Enlace' : 'Texto que se copia' }}
            </label>
            <textarea
              v-model="form.contenido" :rows="form.tipo === 'texto' ? 4 : 2" maxlength="2000"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
            />
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Nota pequeña (opcional)</label>
            <input
              v-model="form.subtitulo" maxlength="200"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div class="flex gap-3 pt-1">
            <button @click="mostrarForm = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">
              Cancelar
            </button>
            <button
              @click="guardar" :disabled="guardando"
              class="flex-[2] bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
            >
              {{ guardando ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Confirmar borrado -->
    <Transition name="fade">
      <div v-if="porBorrar" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="porBorrar = null">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <h3 class="text-base font-bold text-gray-800">¿Eliminar "{{ porBorrar.titulo }}"?</h3>
          <p class="text-xs text-gray-500">
            Se borra para siempre. Si sólo quieres que deje de salirle al asesor,
            apágala con el ojo y el texto se queda guardado.
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

    <IconoPicker
      :abierto="pickerAbierto"
      :elegido="form.icono"
      @cerrar="pickerAbierto = false"
      @elegir="n => form.icono = n"
    />
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
