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
import { getPerfilesProduccion } from '@/api/perfilesProduccion'
import { getRoles } from '@/api/roles'
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
const perfiles = ref([])
const roles = ref([])
const editLoading = ref(false)

// Reset password
const nuevaPassword = ref('')
const confirmacionPassword = ref('')

// Edit form
const editForm = ref({
  nombre: '', email: '', rol_id: '', facturacion: false, es_tapicero: false, independiente: false,
  notif_asignar_fecha: true, notif_stock: false, acceso_redes: false, acceso_comisiones: false,
  recarga_telas: false, acceso_surtir: false, acceso_costos: false, acceso_proveedores: false,
  acceso_despacho: false, acceso_produccion: false, acceso_nomina: false, acceso_reserva: false,
  ve_todas_ordenes: false, perfil_produccion_id: '', tienda_default_id: '',
})
const arquetiposSinTienda = ['conductor', 'despachador', 'taller']

// Arquetipo del rol elegido en el formulario de edición.
const editArquetipo = computed(() => roles.value.find(r => r.id === editForm.value.rol_id)?.arquetipo ?? '')

// Solo un vendedor puede ir por su cuenta, y entonces no pertenece a ninguna tienda.
const editEsIndependiente = computed(() => editArquetipo.value === 'vendedor' && editForm.value.independiente)
// El selector se muestra para cualquiera que no la tenga oculta por completo.
const editMostrarTienda = computed(() =>
  !arquetiposSinTienda.includes(editArquetipo.value) && !editEsIndependiente.value
)
// Pero solo es obligatorio elegir una si el arquetipo es vendedor: los demás
// (incluido supervisor, donde varios son jefes sin tienda) no la necesitan.
const editRequiereTienda = computed(() => editMostrarTienda.value && editArquetipo.value === 'vendedor')

const rolLabel = computed(() => usuario.value?.rol_nombre ?? 'Vendedor')

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
    rol_id: usuario.value.rol_id,
    facturacion: usuario.value.facturacion ?? false,
    es_tapicero: usuario.value.es_tapicero ?? false,
    independiente: usuario.value.independiente ?? false,
    notif_asignar_fecha: usuario.value.notif_asignar_fecha ?? true,
    notif_stock: usuario.value.notif_stock ?? false,
    acceso_redes: usuario.value.acceso_redes ?? false,
    acceso_comisiones: usuario.value.acceso_comisiones ?? false,
    recarga_telas: usuario.value.recarga_telas ?? false,
    acceso_surtir: usuario.value.acceso_surtir ?? false,
    acceso_costos: usuario.value.acceso_costos ?? false,
    acceso_proveedores: usuario.value.acceso_proveedores ?? false,
    acceso_despacho: usuario.value.acceso_despacho ?? false,
    acceso_produccion: usuario.value.acceso_produccion ?? false,
    acceso_nomina: usuario.value.acceso_nomina ?? false,
    acceso_reserva: usuario.value.acceso_reserva ?? false,
    ve_todas_ordenes: usuario.value.ve_todas_ordenes ?? false,
    perfil_produccion_id: usuario.value.perfil_produccion_id ?? '',
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
    await updateUsuario(usuario.value.id, {
      nombre: editForm.value.nombre.trim(),
      email: editForm.value.email.trim(),
      rol_id: editForm.value.rol_id,
      facturacion: editArquetipo.value === 'vendedor' ? editForm.value.facturacion : false,
      es_tapicero: editArquetipo.value === 'supervisor' ? editForm.value.es_tapicero : false,
      independiente: editEsIndependiente.value,
      notif_asignar_fecha: editArquetipo.value === 'supervisor' ? editForm.value.notif_asignar_fecha : false,
      notif_stock: editArquetipo.value === 'supervisor' ? editForm.value.notif_stock : false,
      acceso_redes: ['vendedor', 'supervisor'].includes(editArquetipo.value) ? editForm.value.acceso_redes : false,
      acceso_comisiones: editArquetipo.value === 'supervisor' ? editForm.value.acceso_comisiones : false,
      recarga_telas: ['vendedor', 'supervisor'].includes(editArquetipo.value) ? editForm.value.recarga_telas : false,
      acceso_surtir: editForm.value.acceso_surtir,
      acceso_costos: editForm.value.acceso_costos,
      acceso_proveedores: editForm.value.acceso_proveedores,
      acceso_despacho: editArquetipo.value === 'supervisor' ? editForm.value.acceso_despacho : false,
      acceso_produccion: editArquetipo.value === 'supervisor' ? editForm.value.acceso_produccion : false,
      acceso_nomina: editArquetipo.value === 'supervisor' ? editForm.value.acceso_nomina : false,
      acceso_reserva: editForm.value.acceso_reserva,
      ve_todas_ordenes: editArquetipo.value === 'vendedor' ? editForm.value.ve_todas_ordenes : false,
      perfil_produccion_id: editForm.value.perfil_produccion_id || null,
      tienda_default_id: editMostrarTienda.value ? (editForm.value.tienda_default_id || null) : null,
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
  try {
    const { data } = await getPerfilesProduccion()
    perfiles.value = data
  } catch {}
  try {
    const { data } = await getRoles()
    roles.value = data
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
          usuario.arquetipo === 'supervisor'  ? 'bg-blue-100 text-blue-700' :
          usuario.arquetipo === 'conductor'   ? 'bg-amber-100 text-amber-700' :
          usuario.arquetipo === 'taller'      ? 'bg-orange-100 text-orange-700' :
          usuario.arquetipo === 'despachador' ? 'bg-purple-100 text-purple-700' :
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
        <div v-if="usuario.independiente" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🧭</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Vinculación</p>
            <p class="font-medium text-amber-700">Vendedor independiente</p>
            <p class="text-xs text-gray-500">No pertenece a ninguna tienda · lleva su propia caja</p>
          </div>
        </div>
        <div v-if="usuario.facturacion && usuario.arquetipo === 'vendedor'" class="flex items-center gap-3">
          <BanknotesIcon class="w-5 h-5 text-green-500 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Función especial</p>
            <p class="font-medium text-green-700">Facturación habilitada</p>
          </div>
        </div>
        <div v-if="usuario.es_tapicero && usuario.arquetipo === 'supervisor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🪡</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Especialidad</p>
            <p class="font-medium text-gray-800">Encargado de tapicería, esqueletería, costura y pintura</p>
          </div>
        </div>
        <div v-if="usuario.perfil_produccion" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🛠️</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Perfil de producción</p>
            <p class="font-medium text-gray-800">{{ usuario.perfil_produccion.nombre }}</p>
          </div>
        </div>
        <div v-if="usuario.arquetipo === 'supervisor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">{{ usuario.notif_asignar_fecha ? '🔔' : '🔕' }}</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Notif. asignación de fecha</p>
            <p class="font-medium" :class="usuario.notif_asignar_fecha ? 'text-gray-800' : 'text-gray-400'">
              {{ usuario.notif_asignar_fecha ? 'Habilitada' : 'Deshabilitada' }}
            </p>
          </div>
        </div>
        <div v-if="usuario.arquetipo === 'supervisor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">{{ usuario.notif_stock ? '📦' : '🔕' }}</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Avisos de producto agotado</p>
            <p class="font-medium" :class="usuario.notif_stock ? 'text-gray-800' : 'text-gray-400'">
              {{ usuario.notif_stock ? 'Habilitados — es quien surte' : 'Deshabilitados' }}
            </p>
          </div>
        </div>
        <div v-if="usuario.acceso_redes && ['vendedor', 'supervisor'].includes(usuario.arquetipo)" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">📱</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Módulo de redes</p>
            <p class="font-medium text-blue-700">Acceso habilitado</p>
          </div>
        </div>
        <div v-if="usuario.recarga_telas && ['vendedor', 'supervisor'].includes(usuario.arquetipo)" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🧵</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Módulo de telas</p>
            <p class="font-medium text-pink-700">Puede recargar telas</p>
          </div>
        </div>
        <div v-if="usuario.acceso_surtir" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">📦</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Módulo de Surtir</p>
            <p class="font-medium text-purple-700">Acceso habilitado</p>
          </div>
        </div>
        <div v-if="usuario.acceso_costos" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🧮</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Módulo de Costos</p>
            <p class="font-medium text-blue-700">Acceso habilitado</p>
          </div>
        </div>
        <div v-if="usuario.acceso_proveedores" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🏭</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Proveedores</p>
            <p class="font-medium text-blue-700">Puede crear y editar</p>
          </div>
        </div>
        <div v-if="usuario.acceso_reserva" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🏗️</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Reserva / Fábrica</p>
            <p class="font-medium text-blue-700">Acceso habilitado</p>
          </div>
        </div>
        <div v-if="usuario.ve_todas_ordenes && usuario.arquetipo === 'vendedor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">👁️</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Visibilidad de órdenes</p>
            <p class="font-medium text-blue-700">Ve todas las órdenes</p>
          </div>
        </div>
        <div v-if="usuario.acceso_despacho && usuario.arquetipo === 'supervisor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🚚</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Despacho (logística de entregas)</p>
            <p class="font-medium text-blue-700">Acceso habilitado</p>
          </div>
        </div>
        <div v-if="usuario.acceso_produccion && usuario.arquetipo === 'supervisor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">🛠️</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Producción</p>
            <p class="font-medium text-blue-700">Acceso habilitado</p>
          </div>
        </div>
        <div v-if="usuario.acceso_nomina && usuario.arquetipo === 'supervisor'" class="flex items-center gap-3">
          <div class="w-5 h-5 flex-shrink-0 flex items-center justify-center">
            <span class="text-sm">💵</span>
          </div>
          <div>
            <p class="text-xs text-gray-400">Nómina</p>
            <p class="font-medium text-blue-700">Acceso habilitado</p>
          </div>
        </div>
        <div v-if="usuario.tienda_default && !usuario.independiente && !arquetiposSinTienda.includes(usuario.arquetipo)" class="flex items-center gap-3">
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
      <div v-if="usuario.arquetipo === 'vendedor' && usuario.stats" class="space-y-3">
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
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md flex flex-col max-h-[90vh]">

          <!-- Cabecera fija -->
          <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
            <h3 class="text-lg font-bold text-gray-800">Editar usuario</h3>
            <button @click="showEditModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <!-- Cuerpo scrollable -->
          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">
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
              <select v-model="editForm.rol_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
              </select>
            </div>
            <div v-if="editArquetipo === 'vendedor'" class="flex items-start gap-3 py-2 px-3 bg-amber-50 border border-amber-200 rounded-xl">
              <input
                id="edit-independiente"
                type="checkbox"
                v-model="editForm.independiente"
                class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
              />
              <div>
                <label for="edit-independiente" class="text-sm font-medium text-gray-800 cursor-pointer">Vendedor independiente</label>
                <p class="text-xs text-gray-600 mt-0.5">
                  No pertenece a ninguna tienda. Su plata va a su propia caja, no a la de una tienda,
                  y aparece con su nombre en las estadísticas.
                </p>
              </div>
            </div>
            <div v-if="editArquetipo === 'vendedor'" class="flex items-start gap-3 py-1">
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
            <template v-if="editArquetipo === 'supervisor'">
              <div class="flex items-start gap-3 py-1">
                <input
                  id="edit-tapicero"
                  type="checkbox"
                  v-model="editForm.es_tapicero"
                  class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <div>
                  <label for="edit-tapicero" class="text-sm font-medium text-gray-700 cursor-pointer">Encargado de tapicería</label>
                  <p class="text-xs text-gray-500 mt-0.5">Puede completar pasos de <strong>tapizado</strong>, <strong>laca</strong>, <strong>esqueletería</strong>, <strong>costura</strong> y <strong>pintura</strong>.</p>
                </div>
              </div>
              <div class="flex items-start gap-3 py-1">
                <input
                  id="edit-notif-fecha"
                  type="checkbox"
                  v-model="editForm.notif_asignar_fecha"
                  class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <div>
                  <label for="edit-notif-fecha" class="text-sm font-medium text-gray-700 cursor-pointer">Recibe notificaciones de asignación de fecha</label>
                  <p class="text-xs text-gray-500 mt-0.5">Recibirá una alerta cada vez que se cree una orden nueva para asignar fecha de entrega.</p>
                </div>
              </div>
              <div class="flex items-start gap-3 py-1">
                <input
                  id="edit-notif-stock"
                  type="checkbox"
                  v-model="editForm.notif_stock"
                  class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <div>
                  <label for="edit-notif-stock" class="text-sm font-medium text-gray-700 cursor-pointer">Recibe avisos de producto agotado</label>
                  <p class="text-xs text-gray-500 mt-0.5">Para quien surte: le llega cuando se vende la última unidad de un producto en una tienda.</p>
                </div>
              </div>
            </template>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Perfil de producción</label>
              <select v-model="editForm.perfil_produccion_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">Sin perfil</option>
                <option v-for="p in perfiles" :key="p.id" :value="p.id">{{ p.nombre }}</option>
              </select>
              <p class="text-xs text-gray-500 mt-0.5">Define qué pasos de producción puede ver y completar en "Mis pasos".</p>
            </div>
            <div v-if="['vendedor', 'supervisor'].includes(editArquetipo)" class="flex items-start gap-3 py-1">
              <input
                id="edit-acceso-redes"
                type="checkbox"
                v-model="editForm.acceso_redes"
                class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <div>
                <label for="edit-acceso-redes" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de redes</label>
                <p class="text-xs text-gray-500 mt-0.5">Podrá acceder al módulo de redes sociales y seguimiento digital. Incluye Métricas: no es un permiso aparte.</p>
              </div>
            </div>
            <div v-if="editArquetipo === 'supervisor'" class="flex items-start gap-3 py-1">
              <input
                id="edit-acceso-comisiones"
                type="checkbox"
                v-model="editForm.acceso_comisiones"
                class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500"
              />
              <div>
                <label for="edit-acceso-comisiones" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de comisiones</label>
                <p class="text-xs text-gray-500 mt-0.5">Podrá ver, gestionar y marcar como pagadas las comisiones de los vendedores.</p>
              </div>
            </div>
            <div v-if="['vendedor', 'supervisor'].includes(editArquetipo)" class="flex items-start gap-3 py-1">
              <input
                id="edit-recarga-telas"
                type="checkbox"
                v-model="editForm.recarga_telas"
                class="mt-0.5 rounded border-gray-300 text-pink-600 focus:ring-pink-500"
              />
              <div>
                <label for="edit-recarga-telas" class="text-sm font-medium text-gray-700 cursor-pointer">Puede recargar telas</label>
                <p class="text-xs text-gray-500 mt-0.5">Tendrá acceso al módulo de telas para agregar metros cuando llegue nueva mercancía.</p>
              </div>
            </div>
            <div class="flex items-start gap-3 py-1">
              <input
                id="edit-acceso-surtir"
                type="checkbox"
                v-model="editForm.acceso_surtir"
                class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
              />
              <div>
                <label for="edit-acceso-surtir" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de Surtir</label>
                <p class="text-xs text-gray-500 mt-0.5">Podrá enviar surtidos desde fábrica y hacer traslados entre tiendas.</p>
              </div>
            </div>
            <div v-if="['vendedor', 'supervisor'].includes(editArquetipo)" class="flex items-start gap-3 py-1">
              <input
                id="edit-acceso-costos"
                type="checkbox"
                v-model="editForm.acceso_costos"
                class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <div>
                <label for="edit-acceso-costos" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de Costos</label>
                <p class="text-xs text-gray-500 mt-0.5">Podrá ver fichas técnicas y configuración de costos de producción.</p>
              </div>
            </div>
            <div v-if="editArquetipo === 'vendedor'" class="flex items-start gap-3 py-1">
              <input
                id="edit-acceso-proveedores"
                type="checkbox"
                v-model="editForm.acceso_proveedores"
                class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <div>
                <label for="edit-acceso-proveedores" class="text-sm font-medium text-gray-700 cursor-pointer">Puede crear y editar proveedores</label>
                <p class="text-xs text-gray-500 mt-0.5">Ver la lista ya está disponible para todos; esto habilita agregar o modificar.</p>
              </div>
            </div>
            <div v-if="['vendedor', 'supervisor'].includes(editArquetipo)" class="flex items-start gap-3 py-1">
              <input
                id="edit-acceso-reserva"
                type="checkbox"
                v-model="editForm.acceso_reserva"
                class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <div>
                <label for="edit-acceso-reserva" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Reserva / Fábrica</label>
                <p class="text-xs text-gray-500 mt-0.5">Podrá consultar y mover el inventario que está en fábrica/reserva.</p>
              </div>
            </div>
            <div v-if="editArquetipo === 'vendedor'" class="flex items-start gap-3 py-1">
              <input
                id="edit-ve-todas-ordenes"
                type="checkbox"
                v-model="editForm.ve_todas_ordenes"
                class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <div>
                <label for="edit-ve-todas-ordenes" class="text-sm font-medium text-gray-700 cursor-pointer">Puede ver todas las órdenes</label>
                <p class="text-xs text-gray-500 mt-0.5">Sin esto solo ve las suyas. No hace falta volverlo supervisor para darle esta visibilidad.</p>
              </div>
            </div>
            <template v-if="editArquetipo === 'supervisor'">
              <div class="flex items-start gap-3 py-1">
                <input
                  id="edit-acceso-despacho"
                  type="checkbox"
                  v-model="editForm.acceso_despacho"
                  class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <div>
                  <label for="edit-acceso-despacho" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Despacho (logística de entregas)</label>
                  <p class="text-xs text-gray-500 mt-0.5">
                    Asignar conductores, armar rutas y ver el historial de entregas a domicilio.
                    <strong>No es el paso de producción</strong> (cuando un producto termina y pasa a listo para entrega) —
                    eso ya lo tiene automático quien sea despachador o encargado de tapicería, sin necesitar esto.
                  </p>
                </div>
              </div>
              <div class="flex items-start gap-3 py-1">
                <input
                  id="edit-acceso-produccion"
                  type="checkbox"
                  v-model="editForm.acceso_produccion"
                  class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <div>
                  <label for="edit-acceso-produccion" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Producción</label>
                  <p class="text-xs text-gray-500 mt-0.5">Podrá ver y gestionar el tablero completo de producción del taller.</p>
                </div>
              </div>
              <div class="flex items-start gap-3 py-1">
                <input
                  id="edit-acceso-nomina"
                  type="checkbox"
                  v-model="editForm.acceso_nomina"
                  class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <div>
                  <label for="edit-acceso-nomina" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Nómina</label>
                  <p class="text-xs text-gray-500 mt-0.5">Podrá gestionar el pago quincenal de los trabajadores del taller.</p>
                </div>
              </div>
            </template>
            <div v-if="editMostrarTienda">
              <label class="block text-sm font-medium text-gray-700 mb-1">Tienda{{ editRequiereTienda ? '' : ' (opcional)' }}</label>
              <select v-model="editForm.tienda_default_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">{{ editArquetipo === 'supervisor' ? 'Sin tienda (jefe)' : 'Seleccionar...' }}</option>
                <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
              </select>
              <p v-if="editArquetipo === 'supervisor'" class="text-xs text-gray-500 mt-1">
                "Sin tienda" es para un jefe que no pertenece a ninguna en particular — no le toca el reparto de comisiones de ninguna tienda.
              </p>
            </div>
            <p v-else-if="editEsIndependiente" class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
              No se elige tienda: al ser independiente no pertenece a ninguna.
            </p>
            <p v-if="actionError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ actionError }}</p>
          </div>

          <!-- Pie fijo -->
          <div class="px-5 pb-5 pt-3 border-t border-gray-100 flex-shrink-0 flex gap-3">
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
