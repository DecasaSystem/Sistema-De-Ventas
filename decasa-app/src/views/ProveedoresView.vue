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
} from '@heroicons/vue/24/outline'

const auth  = useAuthStore()
const toast = useToast()

const proveedores = ref([])
const cargando     = ref(true)
const busqueda      = ref('')

const vacio = () => ({ nombre: '', contacto: '', telefono: '', productos: '', direccion: '', notas: '' })
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
    [p.nombre, p.contacto, p.telefono, p.productos, p.direccion]
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
      nombre:     form.value.nombre.trim(),
      contacto:   form.value.contacto.trim()   || null,
      telefono:   form.value.telefono.trim()   || null,
      productos:  form.value.productos.trim()  || null,
      direccion:  form.value.direccion.trim()  || null,
      notas:      form.value.notas.trim()      || null,
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
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-xl font-bold text-gray-800 flex items-center gap-2">
        <BuildingStorefrontIcon class="w-6 h-6 text-blue-600" />
        Proveedores
      </h1>
      <button
        @click="abrirNuevo"
        class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm"
      >
        <PlusIcon class="w-4 h-4" /> Nuevo
      </button>
    </div>

    <p class="text-xs text-gray-400 mb-4">
      Quiénes son, cómo contactarlos y qué proveen. Cualquiera puede sumar uno nuevo o corregir un dato.
    </p>

    <div class="relative mb-4">
      <MagnifyingGlassIcon class="w-4 h-4 text-gray-300 absolute left-3 top-1/2 -translate-y-1/2" />
      <input
        v-model="busqueda"
        placeholder="Buscar por nombre, producto, teléfono..."
        class="input w-full pl-9"
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

        <div v-if="p.telefono" class="flex items-center gap-2 mt-3 pt-3 border-t border-gray-50">
          <a
            :href="`tel:${p.telefono}`"
            class="flex items-center gap-1.5 text-xs font-semibold text-blue-600 bg-blue-50 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors"
          >
            <PhoneIcon class="w-3.5 h-3.5" /> {{ p.telefono }}
          </a>
          <a
            :href="linkWhatsapp(p.telefono)"
            target="_blank"
            rel="noopener"
            class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-lg hover:bg-emerald-100 transition-colors"
          >
            <ChatBubbleLeftRightIcon class="w-3.5 h-3.5" /> WhatsApp
          </a>
        </div>
      </div>
    </div>

    <!-- Nuevo / editar -->
    <Teleport to="body">
      <div v-if="mostrarForm" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[90vh] overflow-y-auto">
          <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 sticky top-0 bg-white">
            <p class="font-semibold text-gray-800">{{ editando ? 'Editar proveedor' : 'Nuevo proveedor' }}</p>
            <button @click="cerrarForm" class="text-gray-300 hover:text-gray-600">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <div class="p-4 space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Nombre *</label>
              <input v-model="form.nombre" class="input w-full" placeholder="Espuma Santa Fé" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Contacto</label>
              <input v-model="form.contacto" class="input w-full" placeholder="Silvio" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Teléfono</label>
              <input v-model="form.telefono" class="input w-full" placeholder="3158937683" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Qué provee</label>
              <textarea v-model="form.productos" rows="2" class="input w-full resize-none" placeholder="Espuma, herrajes, tela..." />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Dirección</label>
              <input v-model="form.direccion" class="input w-full" placeholder="Opcional" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Notas</label>
              <textarea v-model="form.notas" rows="2" class="input w-full resize-none" placeholder="Opcional" />
            </div>
          </div>

          <div class="flex gap-2 p-4 border-t border-gray-100">
            <button @click="cerrarForm" class="btn-secondary flex-1 text-sm">Cancelar</button>
            <button
              @click="guardar"
              :disabled="guardando"
              class="btn-primary flex-1 text-sm disabled:opacity-50"
            >{{ guardando ? 'Guardando...' : 'Guardar' }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
