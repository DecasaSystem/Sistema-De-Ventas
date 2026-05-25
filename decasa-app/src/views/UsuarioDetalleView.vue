<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  EnvelopeIcon,
  MapPinIcon,
  CalendarIcon,
  CheckCircleIcon,
  XCircleIcon,
  KeyIcon,
  PencilIcon,
  BanknotesIcon,
} from '@heroicons/vue/24/outline'
import { getUsuario, toggleActivo, resetPassword, updateUsuario } from '@/api/usuarios'
import { getTiendas } from '@/api/ordenes'
import EmptyState from '@/components/common/EmptyState.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'

const route = useRoute()
const router = useRouter()

const usuario = ref(null)
const loading = ref(true)
const error = ref('')
const showResetModal = ref(false)
const showEditModal = ref(false)
const actionLoading = ref(false)
const actionError = ref('')
const tiendas = ref([])
const editLoading = ref(false)

// Reset password
const nuevaPassword = ref('')
const confirmacionPassword = ref('')

// Edit form
const editForm = ref({ nombre: '', email: '', rol: '', facturacion: false, es_tapicero: false, tienda_default_id: '' })
const rolesSinTienda = ['conductor', 'ebanista', 'despachador']

const ROL_LABELS = {
  supervisor: 'Supervisor',
  conductor:  'Conductor',
  ebanista:   'Ebanista',
  despachador: 'Despachador',
  vendedor:   'Vendedor',
}

const rolLabel = computed(() => ROL_LABELS[usuario.value?.rol] ?? 'Vendedor')

async function cargarUsuario() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getUsuario(route.params.id)
    usuario.value = data
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar el usuario.'
  } finally {
    loading.value = false
  }
}

async function toggleEstado() {
  actionLoading.value = true
  actionError.value = ''
  try {
    await toggleActivo(usuario.value.id)
    await cargarUsuario()
  } catch (e) {
    actionError.value = e.response?.data?.message ?? 'Error al cambiar el estado.'
  } finally {
    actionLoading.value = false
  }
}

function openResetModal() {
  nuevaPassword.value = ''
  confirmacionPassword.value = ''
  actionError.value = ''
  showResetModal.value = true
}

async function doResetPassword() {
  actionError.value = ''
  if (!nuevaPassword.value || nuevaPassword.value.length < 8) {
    actionError.value = 'La contraseña debe tener al menos 8 caracteres.'
    return
  }
  if (nuevaPassword.value !== confirmacionPassword.value) {
    actionError.value = 'Las contraseñas no coinciden.'
    return
  }

  actionLoading.value = true
  try {
    await resetPassword(usuario.value.id, nuevaPassword.value)
    showResetModal.value = false
    actionError.value = ''
  } catch (e) {
    actionError.value = e.response?.data?.message ?? 'Error al resetear la contraseña.'
  } finally {
    actionLoading.value = false
  }
}

function openEditModal() {
  editForm.value = {
    nombre: usuario.value.nombre,
    email: usuario.value.email,
    rol: usuario.value.rol,
    facturacion: usuario.value.facturacion ?? false,
    es_tapicero: usuario.value.es_tapicero ?? false,
    tienda_default_id: usuario.value.tienda_default_id,
  }
  actionError.value = ''
  showEditModal.value = true
}

async function submitEdit() {
  actionError.value = ''
  if (!editForm.value.nombre.trim()) {
    actionError.value = 'El nombre es obligatorio.'
    return
  }
  if (!editForm.value.email.trim()) {
    actionError.value = 'El email es obligatorio.'
    return
  }
  editLoading.value = true
  try {
    const sinTienda = rolesSinTienda.includes(editForm.value.rol)
    await updateUsuario(usuario.value.id, {
      nombre: editForm.value.nombre.trim(),
      email: editForm.value.email.trim(),
      rol: editForm.value.rol,
      facturacion: editForm.value.rol === 'vendedor' ? editForm.value.facturacion : false,
      es_tapicero: editForm.value.rol === 'supervisor' ? editForm.value.es_tapicero : false,
      tienda_default_id: sinTienda ? null : editForm.value.tienda_default_id,
    })
    showEditModal.value = false
    await cargarUsuario()
  } catch (e) {
    const data = e.response?.data
    if (data?.errors) {
      actionError.value = Object.values(data.errors).flat().join(' ')
    } else {
      actionError.value = data?.message ?? 'Error al actualizar el usuario.'
    }
  } finally {
    editLoading.value = false
  }
}

function formatFecha(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

onMounted(async () => {
  cargarUsuario()
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1 truncate">
        {{ usuario?.nombre ?? 'Cargando...' }}
      </h2>
      <span
        v-if="usuario"
        :class="[
          'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium',
          usuario.rol === 'supervisor'  ? 'bg-blue-100 text-blue-700' :
          usuario.rol === 'conductor'   ? 'bg-amber-100 text-amber-700' :
          usuario.rol === 'ebanista'    ? 'bg-orange-100 text-orange-700' :
          usuario.rol === 'despachador' ? 'bg-purple-100 text-purple-700' :
          'bg-gray-100 text-gray-600'
        ]"
      >
        {{ rolLabel }}
      </span>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">
      {{ error }}
    </div>

    <template v-else-if="usuario">
      <!-- Info del usuario -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3 text-sm">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Información</p>
        <div class="flex items-center gap-3">
          <EnvelopeIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Email</p>
            <p class="font-medium text-gray-800">{{ usuario.email }}</p>
          </div>
        </div>
        <div v-if="usuario.facturacion && usuario.rol === 'vendedor'" class="flex items-center gap-3">
          <BanknotesIcon class="w-5 h-5 text-green-500 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Función especial</p>
            <p class="font-medium text-green-700">Facturación habilitada</p>
          </div>
        </div>
        <div v-if="usuario.es_tapicero && usuario.rol === 'supervisor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🪡</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Especialidad</p>
            <p class="font-medium text-gray-800">Encargado de tapicería y laca</p>
          </div>
        </div>
        <div v-if="usuario.tienda_default && !rolesSinTienda.includes(usuario.rol)" class="flex items-center gap-3">
          <MapPinIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Tienda predeterminada</p>
            <p class="font-medium text-gray-800">{{ usuario.tienda_default.nombre }}</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <CalendarIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Fecha de registro</p>
            <p class="font-medium text-gray-800">{{ formatFecha(usuario.created_at) }}</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <component
            :is="usuario.activo ? CheckCircleIcon : XCircleIcon"
            class="w-5 h-5 flex-shrink-0"
            :class="usuario.activo ? 'text-green-500' : 'text-red-500'"
          />
          <div>
            <p class="text-xs text-gray-400">Estado</p>
            <p class="font-medium" :class="usuario.activo ? 'text-green-600' : 'text-red-600'">
              {{ usuario.activo ? 'Activo' : 'Inactivo' }}
            </p>
          </div>
        </div>
      </div>

      <!-- Acciones -->
      <div class="space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Acciones</p>

        <!-- Toggle activo -->
        <button
          @click="toggleEstado"
          :disabled="actionLoading"
          :class="[
            'w-full rounded-xl py-3 text-sm font-semibold transition-colors flex items-center justify-center gap-2',
            usuario.activo
              ? 'bg-red-50 text-red-600 hover:bg-red-100'
              : 'bg-green-50 text-green-600 hover:bg-green-100'
          ]"
        >
          <component :is="usuario.activo ? XCircleIcon : CheckCircleIcon" class="w-5 h-5" />
          {{ actionLoading ? 'Procesando...' : (usuario.activo ? 'Desactivar usuario' : 'Activar usuario') }}
        </button>

        <!-- Reset password -->
        <button
          @click="openResetModal"
          class="w-full bg-amber-50 text-amber-700 rounded-xl py-3 text-sm font-semibold hover:bg-amber-100 transition-colors flex items-center justify-center gap-2"
        >
          <KeyIcon class="w-5 h-5" />
          Resetear contraseña
        </button>

        <!-- Editar -->
        <button
          @click="openEditModal"
          class="w-full bg-gray-100 text-gray-700 rounded-xl py-3 text-sm font-semibold hover:bg-gray-200 transition-colors flex items-center justify-center gap-2"
        >
          <PencilIcon class="w-5 h-5" />
          Editar usuario
        </button>

        <!-- Action error -->
        <p v-if="actionError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ actionError }}</p>
      </div>

      <!-- Si es vendedor: estadísticas embebidas -->
      <div v-if="usuario.rol === 'vendedor' && usuario.stats" class="space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Estadísticas del vendedor</p>

        <!-- KPIs -->
        <div class="grid grid-cols-2 gap-3">
          <div class="bg-white rounded-xl shadow-sm p-3 text-center">
            <p class="text-xl font-bold text-gray-800">{{ usuario.stats.total_ordenes }}</p>
            <p class="text-xs text-gray-400 mt-0.5">Órdenes</p>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-3 text-center">
            <p
              class="text-xl font-bold"
              :class="usuario.stats.saldo_pendiente > 0 ? 'text-red-600' : 'text-green-600'"
            >
              ${{ usuario.stats.saldo_pendiente?.toLocaleString('es-CO') ?? '0' }}
            </p>
            <p class="text-xs text-gray-400 mt-0.5">Saldo pend.</p>
          </div>
        </div>

        <p v-if="!usuario.stats.total_ordenes" class="text-sm text-gray-400 text-center py-4">
          Este vendedor aún no tiene órdenes.
        </p>
      </div>
    </template>

    <!-- Modal: Resetear contraseña -->
    <Transition name="fade">
      <div v-if="showResetModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showResetModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Resetear contraseña</h3>
            <button @click="showResetModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <p class="text-sm text-gray-500">Para: <strong>{{ usuario?.nombre }}</strong></p>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nueva contraseña</label>
            <input
              v-model="nuevaPassword"
              type="password"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Mínimo 8 caracteres"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar contraseña</label>
            <input
              v-model="confirmacionPassword"
              type="password"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Repetir contraseña"
            />
          </div>
          <p v-if="actionError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ actionError }}</p>
          <div class="flex gap-3">
            <button @click="showResetModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="doResetPassword" :disabled="actionLoading" class="flex-1 bg-amber-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-amber-700 disabled:opacity-50">
              {{ actionLoading ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal: Editar usuario -->
    <Transition name="fade">
      <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="showEditModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Editar usuario</h3>
            <button @click="showEditModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
            <input v-model="editForm.nombre" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input v-model="editForm.email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
            <select v-model="editForm.rol" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option value="vendedor">Vendedor</option>
              <option value="supervisor">Supervisor</option>
              <option value="conductor">Conductor</option>
              <option value="ebanista">Ebanista</option>
              <option value="despachador">Despachador</option>
            </select>
          </div>
          <div v-if="editForm.rol === 'vendedor'" class="flex items-start gap-3 py-2">
            <input
              id="edit-facturacion"
              type="checkbox"
              v-model="editForm.facturacion"
              class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <div>
              <label for="edit-facturacion" class="text-sm font-medium text-gray-700 cursor-pointer">Facturación</label>
              <p class="text-xs text-gray-500 mt-0.5">Podrá ver órdenes entregadas de toda la tienda para facturación externa.</p>
            </div>
          </div>
          <div v-if="editForm.rol === 'supervisor'" class="flex items-start gap-3 py-2">
            <input
              id="edit-tapicero"
              type="checkbox"
              v-model="editForm.es_tapicero"
              class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <div>
              <label for="edit-tapicero" class="text-sm font-medium text-gray-700 cursor-pointer">Encargado de tapicería</label>
              <p class="text-xs text-gray-500 mt-0.5">Puede completar pasos de <strong>tapizado</strong> y <strong>laca</strong>.</p>
            </div>
          </div>
          <div v-if="!rolesSinTienda.includes(editForm.rol)">
            <label class="block text-sm font-medium text-gray-700 mb-1">Tienda</label>
            <select v-model="editForm.tienda_default_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option value="">Seleccionar...</option>
              <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
            </select>
          </div>
          <p v-if="actionError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ actionError }}</p>
          <div class="flex gap-3">
            <button @click="showEditModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button @click="submitEdit" :disabled="editLoading" class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50">
              {{ editLoading ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
