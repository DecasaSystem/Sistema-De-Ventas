<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import {
  BuildingStorefrontIcon,
  PlusIcon,
  PhoneIcon,
  ChatBubbleLeftRightIcon,
  MapPinIcon,
  PencilSquareIcon,
  TrashIcon,
  XMarkIcon,
  MagnifyingGlassIcon,
  UserIcon,
  EnvelopeIcon,
  AtSymbolIcon,
  ClipboardDocumentIcon,
} from '@heroicons/vue/24/outline'

const auth  = useAuthStore()
const toast = useToast()

const proveedores = ref([])
const cargando     = ref(true)
const busqueda      = ref('')

const vacio = () => ({
  nombre: '', contacto: '', telefono: '', usuario_whatsapp: '', correo: '',
  productos: '', direccion: '', notas: '',
})
const mostrarForm = ref(false)
const editando     = ref(null)   // id del que se está editando, o null si es nuevo
const form         = ref(vacio())
const guardando     = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/proveedores')
    proveedores.value = data
  } catch {
    toast.error('No se pudo cargar la lista de proveedores')
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

const filtrados = computed(() => {
  const q = busqueda.value.trim().toLowerCase()
  if (!q) return proveedores.value
  return proveedores.value.filter(p =>
    [p.nombre, p.contacto, p.telefono, p.usuario_whatsapp, p.correo, p.productos, p.direccion]
      .filter(Boolean).some(campo => campo.toLowerCase().includes(q)))
})

function abrirNuevo() {
  editando.value = null
  form.value = vacio()
  mostrarForm.value = true
}

function abrirEditar(p) {
  editando.value = p.id
  form.value = {
    nombre: p.nombre, contacto: p.contacto ?? '', telefono: p.telefono ?? '',
    usuario_whatsapp: p.usuario_whatsapp ?? '', correo: p.correo ?? '',
    productos: p.productos ?? '', direccion: p.direccion ?? '', notas: p.notas ?? '',
  }
  mostrarForm.value = true
}

function cerrarForm() {
  mostrarForm.value = false
}

async function guardar() {
  if (!form.value.nombre.trim()) {
    toast.error('El nombre es obligatorio')
    return
  }
  guardando.value = true
  try {
    const payload = {
      nombre:            form.value.nombre.trim(),
      contacto:          form.value.contacto.trim()          || null,
      telefono:          form.value.telefono.trim()          || null,
      usuario_whatsapp:  form.value.usuario_whatsapp.trim()   || null,
      correo:            form.value.correo.trim()             || null,
      productos:         form.value.productos.trim()          || null,
      direccion:         form.value.direccion.trim()          || null,
      notas:             form.value.notas.trim()              || null,
    }
    if (editando.value) {
      const { data } = await api.patch(`/proveedores/${editando.value}`, payload)
      const i = proveedores.value.findIndex(p => p.id === editando.value)
      if (i !== -1) proveedores.value[i] = data
      toast.success('Proveedor actualizado')
    } else {
      const { data } = await api.post('/proveedores', payload)
      proveedores.value.push(data)
      toast.success('Proveedor agregado')
    }
    proveedores.value.sort((a, b) => a.nombre.localeCompare(b.nombre))
    mostrarForm.value = false
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

async function eliminar(p) {
  if (!confirm(`¿Quitar a "${p.nombre}" de la lista de proveedores?`)) return
  try {
    await api.delete(`/proveedores/${p.id}`)
    proveedores.value = proveedores.value.filter(x => x.id !== p.id)
    toast.success('Proveedor eliminado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

// wa.me necesita el indicativo del país; los números guardados son locales.
function linkWhatsapp(telefono) {
  const digitos = String(telefono ?? '').replace(/\D/g, '')
  const conIndicativo = digitos.startsWith('57') ? digitos : `57${digitos}`
  return `https://wa.me/${conIndicativo}`
}

// Un usuario de WhatsApp (@algo) no tiene un enlace público confiable como el
// del teléfono, así que se deja copiar para pegarlo dentro de la app.
async function copiarUsuario(usuario) {
  try {
    await navigator.clipboard.writeText(usuario)
    toast.success('Usuario copiado')
  } catch {
    toast.info('No se pudo copiar automáticamente')
  }
}
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-xl font-bold text-gray-800 flex items-center gap-2">
        <BuildingStorefrontIcon class="w-6 h-6 text-blue-600" />
        Proveedores
      </h1>
      <button
        v-if="auth.puedeProveedores"
        @click="abrirNuevo"
        class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm"
      >
        <PlusIcon class="w-4 h-4" /> Nuevo
      </button>
    </div>

    <p class="text-xs text-gray-400 mb-4">
      Quiénes son, cómo contactarlos y qué proveen.
    </p>

    <div class="relative mb-4">
      <MagnifyingGlassIcon class="w-4 h-4 text-gray-300 absolute left-3 top-1/2 -translate-y-1/2" />
      <input
        v-model="busqueda"
        placeholder="Buscar por nombre, producto, teléfono..."
        class="w-full rounded-xl border border-gray-200 bg-white pl-9 pr-3 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
      />
    </div>

    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="!filtrados.length" class="text-center py-12 text-gray-400 text-sm">
      {{ busqueda ? 'Ningún proveedor coincide con la búsqueda.' : 'Todavía no hay proveedores registrados.' }}
    </div>

    <div v-else class="space-y-2.5">
      <div
        v-for="p in filtrados"
        :key="p.id"
        class="bg-white rounded-xl shadow-sm p-4"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="font-semibold text-sm text-gray-800 truncate">{{ p.nombre }}</p>
            <p v-if="p.contacto" class="text-xs text-gray-500 flex items-center gap-1 mt-0.5">
              <UserIcon class="w-3.5 h-3.5 text-gray-300" /> {{ p.contacto }}
            </p>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <button
              v-if="auth.puedeProveedores"
              @click="abrirEditar(p)"
              class="p-1.5 text-gray-300 hover:text-blue-600 transition-colors"
              aria-label="Editar"
            >
              <PencilSquareIcon class="w-4 h-4" />
            </button>
            <button
              v-if="auth.isSupervisor"
              @click="eliminar(p)"
              class="p-1.5 text-gray-300 hover:text-red-600 transition-colors"
              aria-label="Eliminar"
            >
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </div>

        <p v-if="p.productos" class="text-xs text-gray-600 mt-2">{{ p.productos }}</p>

        <p v-if="p.direccion" class="text-xs text-gray-400 flex items-center gap-1 mt-1.5">
          <MapPinIcon class="w-3.5 h-3.5 text-gray-300 shrink-0" /> {{ p.direccion }}
        </p>

        <p v-if="p.notas" class="text-xs text-gray-400 italic mt-1.5">{{ p.notas }}</p>

        <div
          v-if="p.telefono || p.usuario_whatsapp || p.correo"
          class="flex items-center gap-2 flex-wrap mt-3 pt-3 border-t border-gray-50"
        >
          <a
            v-if="p.telefono"
            :href="`tel:${p.telefono}`"
            class="flex items-center gap-1.5 text-xs font-semibold text-blue-600 bg-blue-50 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors"
          >
            <PhoneIcon class="w-3.5 h-3.5" /> {{ p.telefono }}
          </a>
          <a
            v-if="p.telefono"
            :href="linkWhatsapp(p.telefono)"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg hover:bg-emerald-100 transition-colors"
          >
            <ChatBubbleLeftRightIcon class="w-3.5 h-3.5" /> WhatsApp
          </a>
          <!-- Sin teléfono no hay un enlace de WhatsApp confiable para un
               usuario (@algo), así que se deja copiar en vez de inventar uno. -->
          <button
            v-if="!p.telefono && p.usuario_whatsapp"
            @click="copiarUsuario(p.usuario_whatsapp)"
            class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg hover:bg-emerald-100 transition-colors"
          >
            <AtSymbolIcon class="w-3.5 h-3.5" /> {{ p.usuario_whatsapp }}
            <ClipboardDocumentIcon class="w-3.5 h-3.5 text-emerald-400" />
          </button>
          <a
            v-if="p.correo"
            :href="`mailto:${p.correo}`"
            class="flex items-center gap-1.5 text-xs font-semibold text-gray-600 bg-gray-100 px-3 py-1.5 rounded-lg hover:bg-gray-200 transition-colors"
          >
            <EnvelopeIcon class="w-3.5 h-3.5" /> {{ p.correo }}
          </a>
        </div>
      </div>
    </div>

    <!-- Nuevo / editar -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150"
        leave-to-class="opacity-0"
      >
        <div
          v-if="mostrarForm"
          class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center"
          @click.self="cerrarForm"
        >
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm rounded-t-3xl sm:rounded-t-2xl">
              <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                  <BuildingStorefrontIcon class="w-5 h-5 text-blue-600" />
                </div>
                <p class="font-semibold text-gray-800">{{ editando ? 'Editar proveedor' : 'Nuevo proveedor' }}</p>
              </div>
              <button
                @click="cerrarForm"
                class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
              >
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-4">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre *</label>
                <input
                  v-model="form.nombre"
                  placeholder="Espuma Santa Fé"
                  class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                />
              </div>

              <!-- Es lo primero que alguien busca de un proveedor, asi que va
                   de segundo, justo debajo del nombre — no enterrado despues
                   de contacto, telefono, whatsapp y correo. -->
              <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Qué provee</label>
                <textarea
                  v-model="form.productos"
                  rows="2"
                  placeholder="Espuma, herrajes, tela..."
                  class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                />
                <p class="text-[11px] text-gray-400 mt-1">Esto es lo que se ve en la tarjeta de la lista.</p>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Contacto</label>
                  <input
                    v-model="form.contacto"
                    placeholder="Silvio"
                    class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                  />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Teléfono</label>
                  <input
                    v-model="form.telefono"
                    placeholder="3158937683"
                    inputmode="numeric"
                    class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                  />
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Usuario de WhatsApp</label>
                  <input
                    v-model="form.usuario_whatsapp"
                    placeholder="@nombre"
                    class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                  />
                  <p class="text-[11px] text-gray-400 mt-1">Si en WhatsApp solo se le encuentra por usuario, no por número.</p>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Correo</label>
                  <input
                    v-model="form.correo"
                    type="email"
                    placeholder="Opcional"
                    class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                  />
                </div>
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Dirección</label>
                <input
                  v-model="form.direccion"
                  placeholder="Opcional"
                  class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas</label>
                <textarea
                  v-model="form.notas"
                  rows="2"
                  placeholder="Opcional"
                  class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                />
              </div>
            </div>

            <div class="flex gap-2.5 p-5 pt-2">
              <button
                @click="cerrarForm"
                class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 active:bg-gray-300 transition-colors"
              >Cancelar</button>
              <button
                @click="guardar"
                :disabled="guardando"
                class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 active:bg-blue-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5"
              >
                <span v-if="guardando" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                {{ guardando ? 'Guardando...' : 'Guardar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
