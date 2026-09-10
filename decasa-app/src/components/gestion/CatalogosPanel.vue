<script setup>
/**
 * Catálogos visuales: el PDF de diseño, hoja por hoja, dentro del sistema.
 *
 * Cada catálogo es una categoría con sus páginas (imágenes) ordenadas. Se
 * comparte por un link público (/c/su-slug) que abre un visor tipo revista sin
 * que el cliente descargue nada.
 *
 * Las imágenes se suben a Cloudinary una por una y aquí solo se guarda su URL
 * y su posición.
 */
import { ref, computed, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import {
  listarCatalogos, crearCatalogo, actualizarCatalogo, eliminarCatalogo,
  verCatalogo, agregarPaginas, reordenarPaginas, actualizarPagina, eliminarPagina,
  subirImagenCatalogo,
} from '@/api/catalogos'
import { cloudinaryOpt } from '@/utils/cloudinary'
import {
  PlusIcon, PencilIcon, TrashIcon, EyeIcon, EyeSlashIcon, XMarkIcon,
  LinkIcon, ArrowTopRightOnSquareIcon, ArrowUpIcon, ArrowDownIcon,
  PhotoIcon, StarIcon, ArrowUpTrayIcon,
} from '@heroicons/vue/24/outline'
import { StarIcon as StarSolid } from '@heroicons/vue/24/solid'

const toast = useToast()

const lista    = ref([])
const cargando = ref(true)

const creando   = ref(false)
const formNuevo = ref({ nombre: '', descripcion: '' })
const guardandoNuevo = ref(false)

// Catálogo abierto en el editor
const editor       = ref(null)   // { id, nombre, slug, descripcion, activo, portada_url, paginas: [] }
const guardandoMeta = ref(false)
const subiendo     = ref(null)   // { hechas, total } mientras se suben imágenes
const porBorrar    = ref(null)   // catálogo pendiente de confirmación de borrado
const archivoRef   = ref(null)

const origen = computed(() => window.location.origin)
const linkDe = (slug) => `${origen.value}/c/${slug}`

async function cargar() {
  cargando.value = true
  try {
    const { data } = await listarCatalogos()
    lista.value = data
  } catch {
    toast.error('No se pudieron cargar los catálogos.')
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

async function guardarNuevo() {
  const nombre = formNuevo.value.nombre.trim()
  if (!nombre) { toast.error('Ponle un nombre al catálogo.'); return }
  guardandoNuevo.value = true
  try {
    const { data } = await crearCatalogo({
      nombre,
      descripcion: formNuevo.value.descripcion.trim() || null,
    })
    creando.value = false
    formNuevo.value = { nombre: '', descripcion: '' }
    await cargar()
    abrirEditor(data.id)
    toast.success('Catálogo creado. Ahora súbele las páginas.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo crear.')
  } finally {
    guardandoNuevo.value = false
  }
}

async function abrirEditor(id) {
  try {
    const { data } = await verCatalogo(id)
    editor.value = data
  } catch {
    toast.error('No se pudo abrir el catálogo.')
  }
}
function cerrarEditor() {
  editor.value = null
  cargar()
}

async function guardarMeta() {
  const e = editor.value
  const nombre = e.nombre.trim()
  if (!nombre) { toast.error('El nombre no puede quedar vacío.'); return }
  guardandoMeta.value = true
  try {
    const { data } = await actualizarCatalogo(e.id, {
      nombre,
      descripcion: (e.descripcion || '').trim() || null,
    })
    editor.value = { ...editor.value, ...data }
    toast.success('Guardado.')
  } catch (err) {
    toast.error(err.response?.data?.message ?? 'No se pudo guardar.')
  } finally {
    guardandoMeta.value = false
  }
}

async function alternarActivo(cat) {
  const antes = cat.activo
  cat.activo = !antes
  try {
    await actualizarCatalogo(cat.id, { activo: cat.activo })
  } catch {
    cat.activo = antes
    toast.error('No se pudo cambiar.')
  }
}

async function confirmarBorrado() {
  const cat = porBorrar.value
  if (!cat) return
  try {
    await eliminarCatalogo(cat.id)
    porBorrar.value = null
    if (editor.value?.id === cat.id) editor.value = null
    await cargar()
    toast.success('Catálogo eliminado.')
  } catch {
    toast.error('No se pudo eliminar.')
  }
}

async function onArchivos(e) {
  const files = [...(e.target.files ?? [])].filter(f => f.type.startsWith('image/'))
  if (archivoRef.value) archivoRef.value.value = ''
  if (!files.length) return

  subiendo.value = { hechas: 0, total: files.length }
  const urls = []
  try {
    for (const file of files) {
      urls.push(await subirImagenCatalogo(file))
      subiendo.value.hechas++
    }
    const { data } = await agregarPaginas(editor.value.id, urls)
    editor.value = { ...editor.value, ...data }
    toast.success(`${urls.length} página${urls.length > 1 ? 's' : ''} agregada${urls.length > 1 ? 's' : ''}.`)
  } catch (err) {
    if (urls.length) {
      try {
        const { data } = await agregarPaginas(editor.value.id, urls)
        editor.value = { ...editor.value, ...data }
      } catch { /* nada */ }
    }
    toast.error(err.response?.data?.message ?? 'Algunas imágenes no se pudieron subir.')
  } finally {
    subiendo.value = null
  }
}

async function mover(idx, dir) {
  const paginas = [...editor.value.paginas]
  const destino = idx + dir
  if (destino < 0 || destino >= paginas.length) return
  ;[paginas[idx], paginas[destino]] = [paginas[destino], paginas[idx]]
  editor.value.paginas = paginas
  try {
    const { data } = await reordenarPaginas(editor.value.id, paginas.map(p => p.id))
    editor.value = { ...editor.value, ...data }
  } catch {
    toast.error('No se pudo reordenar.')
    abrirEditor(editor.value.id)
  }
}

async function quitarPagina(pagina) {
  try {
    const { data } = await eliminarPagina(editor.value.id, pagina.id)
    editor.value = { ...editor.value, ...data }
  } catch {
    toast.error('No se pudo quitar la página.')
  }
}

async function ponerPortada(pagina) {
  const nueva = editor.value.portada_url === pagina.imagen_url ? null : pagina.imagen_url
  try {
    const { data } = await actualizarCatalogo(editor.value.id, { portada_url: nueva })
    editor.value = { ...editor.value, ...data }
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo fijar la portada.')
  }
}

let notaTimer = null
function guardarNota(pagina) {
  clearTimeout(notaTimer)
  notaTimer = setTimeout(async () => {
    try {
      await actualizarPagina(editor.value.id, pagina.id, (pagina.nota || '').trim() || null)
    } catch { /* silencioso: es una nota */ }
  }, 700)
}

async function copiarLink(slug) {
  try {
    await navigator.clipboard.writeText(linkDe(slug))
    toast.success('Link copiado ✅')
  } catch {
    toast.info?.('Copia manual: ' + linkDe(slug))
  }
}

const thumb = (url) => cloudinaryOpt(url, 400)
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-start gap-2">
      <p class="text-xs text-gray-500 flex-1">
        El catálogo de diseño, hoja por hoja, dentro del sistema. Cada uno es
        una categoría con sus páginas; se comparte por un link que abre un visor
        tipo revista, sin que el cliente descargue nada.
      </p>
      <button
        @click="creando = true"
        class="shrink-0 bg-blue-600 text-white rounded-lg px-3 py-1.5 text-sm font-semibold hover:bg-blue-700 flex items-center gap-1"
      >
        <PlusIcon class="w-4 h-4" /> Nuevo
      </button>
    </div>

    <div v-if="!cargando && lista.length" class="flex items-center justify-between text-[11px] text-gray-400">
      <span>Portada pública con todas las categorías:</span>
      <button @click="copiarLink('')" class="text-blue-600 font-medium flex items-center gap-1">
        <LinkIcon class="w-3.5 h-3.5" /> {{ origen }}/c
      </button>
    </div>

    <AppSpinner v-if="cargando" />

    <template v-else>
      <div
        v-for="cat in lista" :key="cat.id"
        :class="['bg-white rounded-xl shadow-sm border flex items-stretch gap-3 overflow-hidden',
          cat.activo ? 'border-gray-100' : 'border-dashed border-gray-300 opacity-70']"
      >
        <div class="w-20 sm:w-24 bg-gray-100 shrink-0 flex items-center justify-center">
          <img v-if="cat.portada_url" :src="thumb(cat.portada_url)" alt="" class="w-full h-full object-cover" />
          <PhotoIcon v-else class="w-6 h-6 text-gray-300" />
        </div>

        <div class="flex-1 min-w-0 py-2.5">
          <p class="text-sm font-semibold text-gray-800 truncate">{{ cat.nombre }}</p>
          <p class="text-xs text-gray-400">
            {{ cat.paginas_count }} página{{ cat.paginas_count === 1 ? '' : 's' }}
            <span v-if="!cat.activo" class="text-amber-600 font-medium"> · oculto</span>
          </p>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5">
            <button @click="abrirEditor(cat.id)" class="text-xs text-blue-600 font-medium flex items-center gap-1">
              <PencilIcon class="w-3.5 h-3.5" /> Páginas
            </button>
            <button @click="copiarLink(cat.slug)" class="text-xs text-gray-500 font-medium flex items-center gap-1">
              <LinkIcon class="w-3.5 h-3.5" /> Copiar link
            </button>
            <a :href="linkDe(cat.slug)" target="_blank" rel="noopener" class="text-xs text-gray-500 font-medium flex items-center gap-1">
              <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" /> Ver
            </a>
          </div>
        </div>

        <div class="flex flex-col gap-1 shrink-0 py-2.5 pr-2.5">
          <button
            @click="alternarActivo(cat)"
            :class="['w-8 h-8 rounded-lg flex items-center justify-center', cat.activo ? 'text-green-600 hover:bg-green-50' : 'text-gray-400 hover:bg-gray-100']"
            :title="cat.activo ? 'Visible' : 'Oculto'"
          >
            <component :is="cat.activo ? EyeIcon : EyeSlashIcon" class="w-4 h-4" />
          </button>
          <button @click="porBorrar = cat" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:bg-red-50 hover:text-red-500" title="Eliminar">
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

      <p v-if="!lista.length" class="text-xs text-gray-400 text-center py-6">
        Todavía no hay catálogos. Crea el primero con el botón de arriba.
      </p>
    </template>

    <!-- Crear -->
    <Transition name="fade">
      <div v-if="creando" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="creando = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">Nuevo catálogo</h3>
            <button @click="creando = false" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="w-5 h-5" /></button>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Nombre de la categoría</label>
            <input
              v-model="formNuevo.nombre" maxlength="120" placeholder="Salas, Comedores, Relojes..."
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              @keyup.enter="guardarNuevo"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Descripción (opcional)</label>
            <input
              v-model="formNuevo.descripcion" maxlength="300" placeholder="Una línea que se ve arriba del catálogo"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div class="flex gap-3 pt-1">
            <button @click="creando = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="guardarNuevo" :disabled="guardandoNuevo" class="flex-[2] bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
              {{ guardandoNuevo ? 'Creando...' : 'Crear' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Editor de páginas -->
    <Transition name="slide-up">
      <div v-if="editor" class="fixed inset-0 z-[60] bg-gray-50 flex flex-col">
        <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-200 bg-white">
          <button @click="cerrarEditor" class="text-gray-400 hover:text-gray-600 -ml-1"><XMarkIcon class="w-6 h-6" /></button>
          <div class="flex-1 min-w-0">
            <input
              v-model="editor.nombre" maxlength="120"
              class="w-full font-bold text-gray-800 bg-transparent focus:outline-none focus:bg-gray-50 rounded px-1 -ml-1"
              @blur="guardarMeta"
            />
            <p class="text-[11px] text-gray-400 px-1">{{ origen }}/c/{{ editor.slug }}</p>
          </div>
          <button @click="copiarLink(editor.slug)" class="shrink-0 text-xs text-blue-600 font-semibold flex items-center gap-1 px-2 py-1.5 hover:bg-blue-50 rounded-lg">
            <LinkIcon class="w-4 h-4" /> Link
          </button>
        </div>

        <div class="flex-1 overflow-y-auto p-4 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Descripción</label>
            <input
              v-model="editor.descripcion" maxlength="300" placeholder="Una línea que se ve arriba del catálogo"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              @blur="guardarMeta"
            />
          </div>

          <!-- Subir páginas -->
          <label
            class="block border-2 border-dashed border-blue-300 rounded-xl p-4 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50/40 transition-colors"
            :class="{ 'pointer-events-none opacity-60': subiendo }"
          >
            <input ref="archivoRef" type="file" accept="image/*" multiple class="hidden" @change="onArchivos" />
            <ArrowUpTrayIcon class="w-6 h-6 text-blue-400 mx-auto" />
            <p v-if="!subiendo" class="text-sm text-blue-600 font-semibold mt-1">Agregar páginas</p>
            <p v-else class="text-sm text-blue-600 font-semibold mt-1">Subiendo {{ subiendo.hechas }} / {{ subiendo.total }}…</p>
            <p class="text-[11px] text-gray-400 mt-0.5">
              Elige varias imágenes a la vez (JPG o PNG). Entran en el orden que las selecciones.
            </p>
          </label>

          <!-- Grilla de páginas -->
          <div v-if="editor.paginas.length" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div
              v-for="(p, idx) in editor.paginas" :key="p.id"
              class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden flex flex-col"
            >
              <div class="relative bg-gray-100 aspect-[3/4]">
                <img :src="thumb(p.imagen_url)" alt="" class="w-full h-full object-cover" />
                <span class="absolute top-1 left-1 text-[10px] font-bold text-white bg-black/50 rounded px-1.5 py-0.5">
                  {{ idx + 1 }}
                </span>
                <button
                  @click="ponerPortada(p)"
                  class="absolute top-1 right-1 w-7 h-7 rounded-full bg-black/40 flex items-center justify-center text-white hover:bg-black/60"
                  :title="editor.portada_url === p.imagen_url ? 'Es la portada' : 'Usar de portada'"
                >
                  <component :is="editor.portada_url === p.imagen_url ? StarSolid : StarIcon" class="w-4 h-4" :class="editor.portada_url === p.imagen_url ? 'text-amber-300' : ''" />
                </button>
              </div>
              <div class="p-2 space-y-1.5">
                <input
                  v-model="p.nota" maxlength="200" placeholder="Nota (opcional)"
                  class="w-full text-xs rounded border border-gray-200 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-400"
                  @input="guardarNota(p)"
                />
                <div class="flex items-center gap-1">
                  <button @click="mover(idx, -1)" :disabled="idx === 0" class="flex-1 h-7 rounded bg-gray-100 text-gray-500 flex items-center justify-center disabled:opacity-30 hover:bg-gray-200">
                    <ArrowUpIcon class="w-3.5 h-3.5" />
                  </button>
                  <button @click="mover(idx, 1)" :disabled="idx === editor.paginas.length - 1" class="flex-1 h-7 rounded bg-gray-100 text-gray-500 flex items-center justify-center disabled:opacity-30 hover:bg-gray-200">
                    <ArrowDownIcon class="w-3.5 h-3.5" />
                  </button>
                  <button @click="quitarPagina(p)" class="flex-1 h-7 rounded bg-red-50 text-red-500 flex items-center justify-center hover:bg-red-100">
                    <TrashIcon class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <p v-else class="text-xs text-gray-400 text-center py-6">
            Este catálogo todavía no tiene páginas. Súbelas con el botón de arriba.
          </p>

          <button
            @click="porBorrar = editor"
            class="text-xs text-red-500 font-medium flex items-center gap-1 mx-auto pt-2"
          >
            <TrashIcon class="w-3.5 h-3.5" /> Eliminar este catálogo
          </button>
        </div>
      </div>
    </Transition>

    <!-- Confirmar borrado -->
    <Transition name="fade">
      <div v-if="porBorrar" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="porBorrar = null">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
          <h3 class="text-base font-bold text-gray-800">¿Eliminar "{{ porBorrar.nombre }}"?</h3>
          <p class="text-xs text-gray-500">
            Se borran el catálogo y todas sus páginas. El link que hayas
            compartido deja de funcionar. Si solo quieres esconderlo, apágalo
            con el ojo.
          </p>
          <div class="flex gap-3">
            <button @click="porBorrar = null" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="confirmarBorrado" class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-red-700">Eliminar</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-up-enter-active, .slide-up-leave-active { transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.28s ease; }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(2%); opacity: 0; }
</style>
