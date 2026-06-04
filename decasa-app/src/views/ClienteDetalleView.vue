<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  PhoneIcon,
  IdentificationIcon,
  EnvelopeIcon,
  MapPinIcon,
  CalendarIcon,
  ChatBubbleLeftRightIcon,
  DevicePhoneMobileIcon,
  GlobeAltIcon,
  QuestionMarkCircleIcon,
  UserGroupIcon,
  ArrowPathIcon,
  PencilSquareIcon,
  XMarkIcon,
  CheckIcon,
} from '@heroicons/vue/24/outline'
import { getCliente, getClienteOrdenes, updateCliente } from '@/api/clientes'
import { CATEGORIAS_DISPONIBLES } from '@/api/clientes'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const route = useRoute()
const router = useRouter()

const cliente = ref(null)
const ordenes = ref([])
const loading = ref(true)
const loadingOrdenes = ref(true)
const error = ref('')
const tieneMasOrdenes = ref(true)
const paginaOrdenes = ref(1)
const convirtiendo = ref(false)
const mostrarFormConversion = ref(false)
const formConversion = ref({ cedula: '', telefono: '', email: '', direccion: '' })
const errorConversion = ref('')

const canalIcon = computed(() => {
  const map = {
    fisica: DevicePhoneMobileIcon,
    whatsapp: ChatBubbleLeftRightIcon,
    red_social: GlobeAltIcon,
  }
  return map[cliente.value?.canal_frecuente] ?? QuestionMarkCircleIcon
})

const canalLabel = computed(() => {
  const map = {
    fisica: 'Física',
    whatsapp: 'WhatsApp',
    red_social: 'Red social',
    otro: 'Otro',
  }
  return map[cliente.value?.canal_frecuente] ?? cliente.value?.canal_frecuente ?? 'Sin definir'
})

async function cargarCliente() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getCliente(route.params.id)
    cliente.value = data
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar el cliente.'
  } finally {
    loading.value = false
  }
}

async function cargarOrdenes(page = 1, append = false) {
  loadingOrdenes.value = true
  try {
    const { data } = await getClienteOrdenes(route.params.id, { page })
    if (append) {
      ordenes.value = [...ordenes.value, ...data.data]
    } else {
      ordenes.value = data.data ?? []
    }
    tieneMasOrdenes.value = data.current_page < data.last_page
    paginaOrdenes.value = data.current_page
  } catch {
    if (!append) ordenes.value = []
  } finally {
    loadingOrdenes.value = false
  }
}

function goToOrden(id) {
  router.push({ name: 'orden-detalle', params: { id } })
}

function formatFecha(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatFechaCorta(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' })
}

function formatMoney(n) {
  return Number(n).toLocaleString('es-CO')
}

async function loadMoreOrdenes() {
  if (loadingOrdenes.value || !tieneMasOrdenes.value) return
  await cargarOrdenes(paginaOrdenes.value + 1, true)
}

function abrirFormConversion() {
  formConversion.value = {
    cedula:    cliente.value?.cedula    ?? '',
    telefono:  cliente.value?.telefono  ?? '',
    email:     cliente.value?.email     ?? '',
    direccion: cliente.value?.direccion ?? '',
  }
  errorConversion.value = ''
  mostrarFormConversion.value = true
}

async function convertirAOficial() {
  errorConversion.value = ''
  if (!formConversion.value.cedula.trim()) {
    errorConversion.value = 'La cédula es obligatoria para clientes oficiales.'
    return
  }
  if (!formConversion.value.telefono.trim()) {
    errorConversion.value = 'El teléfono es obligatorio para clientes oficiales.'
    return
  }
  convirtiendo.value = true
  try {
    const payload = { tipo: 'oficial' }
    if (formConversion.value.cedula.trim())    payload.cedula    = formConversion.value.cedula.trim()
    if (formConversion.value.telefono.trim())  payload.telefono  = formConversion.value.telefono.trim()
    if (formConversion.value.email.trim())     payload.email     = formConversion.value.email.trim()
    if (formConversion.value.direccion.trim()) payload.direccion = formConversion.value.direccion.trim()
    await updateCliente(cliente.value.id, payload)
    mostrarFormConversion.value = false
    await cargarCliente()
  } catch (e) {
    errorConversion.value = e.response?.data?.message ?? 'Error al convertir cliente.'
  } finally {
    convirtiendo.value = false
  }
}

// ── Edición de datos del cliente ─────────────────────────────────────────
const editando  = ref(false)
const guardando = ref(false)
const errorEdit = ref('')
const formEdit  = ref({ nombre: '', cedula: '', telefono: '', email: '', direccion: '' })

function abrirEdicion() {
  formEdit.value = {
    nombre:    cliente.value?.nombre    ?? '',
    cedula:    cliente.value?.cedula    ?? '',
    telefono:  cliente.value?.telefono  ?? '',
    email:     cliente.value?.email     ?? '',
    direccion: cliente.value?.direccion ?? '',
  }
  errorEdit.value = ''
  editando.value  = true
}

async function guardarEdicion() {
  errorEdit.value = ''
  if (!formEdit.value.nombre.trim()) {
    errorEdit.value = 'El nombre es obligatorio.'
    return
  }
  guardando.value = true
  try {
    await updateCliente(cliente.value.id, {
      nombre:    formEdit.value.nombre.trim(),
      cedula:    formEdit.value.cedula.trim()    || null,
      telefono:  formEdit.value.telefono.trim()  || null,
      email:     formEdit.value.email.trim()     || null,
      direccion: formEdit.value.direccion.trim() || null,
    })
    editando.value = false
    await cargarCliente()
  } catch (e) {
    errorEdit.value = e.response?.data?.message ?? 'Error al guardar los cambios.'
  } finally {
    guardando.value = false
  }
}

const sentinel = ref(null)
let observer = null

function setupObserver() {
  if (observer) observer.disconnect()
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && tieneMasOrdenes.value && !loadingOrdenes.value) {
      loadMoreOrdenes()
    }
  }, { rootMargin: '200px' })

  setTimeout(() => {
    if (sentinel.value) observer.observe(sentinel.value)
  }, 500)
}

onMounted(async () => {
  await cargarCliente()
  if (cliente.value) {
    await cargarOrdenes(1)
    setupObserver()
  }
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1 truncate">
        {{ cliente?.nombre ?? 'Cargando...' }}
      </h2>
      <span
        v-if="cliente?.tipo === 'interesado' && !editando"
        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700"
      >
        <UserGroupIcon class="w-3.5 h-3.5" />
        Interesado
      </span>
      <button
        v-if="cliente && !editando"
        @click="abrirEdicion"
        class="flex items-center gap-1.5 text-xs text-blue-600 border border-blue-200 rounded-lg px-2.5 py-1.5 hover:bg-blue-50 transition-colors"
      >
        <PencilSquareIcon class="w-3.5 h-3.5" />
        Editar
      </button>
    </div>

    <!-- Loading -->
    <AppSpinner v-if="loading" />

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">
      {{ error }}
    </div>

    <template v-else-if="cliente">
      <!-- Canal badge -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <component :is="canalIcon" class="w-5 h-5 text-gray-400" />
          <span class="text-sm text-gray-500 capitalize">{{ canalLabel }}</span>
        </div>
        <span class="text-xs text-gray-400">Registrado {{ formatFecha(cliente.created_at) }}</span>
      </div>

      <!-- Badge de tipo de cliente -->
      <div
        v-if="cliente.tipo === 'interesado'"
        class="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-3"
      >
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-amber-800">Cliente Interesado</p>
          <button
            v-if="!mostrarFormConversion"
            @click="abrirFormConversion"
            class="text-xs bg-amber-600 text-white px-3 py-1.5 rounded-lg hover:bg-amber-700 flex items-center gap-1"
          >
            Convertir a oficial
          </button>
          <button
            v-else
            @click="mostrarFormConversion = false"
            class="text-xs text-amber-700 underline"
          >
            Cancelar
          </button>
        </div>

        <!-- Formulario de conversión -->
        <div v-if="mostrarFormConversion" class="space-y-2.5 pt-1">
          <p class="text-xs text-amber-700">Completa los datos del cliente oficial antes de confirmar.</p>

          <div class="space-y-2">
            <div>
              <label class="text-xs font-medium text-amber-800 mb-0.5 block">Cédula <span class="text-red-500">*</span></label>
              <input
                v-model="formConversion.cedula"
                type="text"
                placeholder="Número de cédula"
                class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400"
              />
            </div>
            <div>
              <label class="text-xs font-medium text-amber-800 mb-0.5 block">Teléfono <span class="text-red-500">*</span></label>
              <input
                v-model="formConversion.telefono"
                type="tel"
                placeholder="Número de teléfono"
                class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400"
              />
            </div>
            <div>
              <label class="text-xs font-medium text-amber-800 mb-0.5 block">Email</label>
              <input
                v-model="formConversion.email"
                type="email"
                placeholder="correo@ejemplo.com (opcional)"
                class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400"
              />
            </div>
            <div>
              <label class="text-xs font-medium text-amber-800 mb-0.5 block">Dirección</label>
              <input
                v-model="formConversion.direccion"
                type="text"
                placeholder="Dirección (opcional)"
                class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400"
              />
            </div>
          </div>

          <p v-if="errorConversion" class="text-xs text-red-600 font-medium">{{ errorConversion }}</p>

          <button
            @click="convertirAOficial"
            :disabled="convirtiendo"
            class="w-full bg-amber-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-amber-700 disabled:opacity-50 flex items-center justify-center gap-2 transition-colors"
          >
            <ArrowPathIcon v-if="convirtiendo" class="w-4 h-4 animate-spin" />
            {{ convirtiendo ? 'Convirtiendo...' : 'Confirmar conversión a oficial' }}
          </button>
        </div>

        <!-- Categorías de interés -->
        <div v-if="!mostrarFormConversion && cliente.categorias_interes?.length > 0">
          <p class="text-xs font-medium text-amber-700 mb-1.5">Categorías de interés</p>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="cat in cliente.categorias_interes"
              :key="cat"
              class="px-2.5 py-1 rounded-full text-xs font-medium bg-white text-amber-700 border border-amber-200"
            >
              {{ cat }}
            </span>
          </div>
        </div>

        <!-- Notas de interés -->
        <div v-if="!mostrarFormConversion && cliente.notas_interes">
          <p class="text-xs font-medium text-amber-700 mb-1">Notas</p>
          <p class="text-sm text-amber-800 bg-white rounded-lg p-2.5 border border-amber-200">
            {{ cliente.notas_interes }}
          </p>
        </div>
      </div>

      <!-- Info del cliente — modo vista -->
      <div v-if="!editando" class="bg-white rounded-xl shadow-sm p-4 space-y-2 text-sm">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Información</p>
        <div v-if="cliente.cedula" class="flex items-center gap-3">
          <IdentificationIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Cédula</p>
            <p class="font-medium text-gray-800">{{ cliente.cedula }}</p>
          </div>
        </div>
        <div v-if="cliente.telefono" class="flex items-center gap-3">
          <PhoneIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Teléfono</p>
            <p class="font-medium text-gray-800">{{ cliente.telefono }}</p>
          </div>
        </div>
        <div v-if="cliente.email" class="flex items-center gap-3">
          <EnvelopeIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
          <div>
            <p class="text-xs text-gray-400">Email</p>
            <p class="font-medium text-gray-800">{{ cliente.email }}</p>
          </div>
        </div>
        <div v-if="cliente.direccion" class="flex items-start gap-3">
          <MapPinIcon class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5" />
          <div>
            <p class="text-xs text-gray-400">Dirección</p>
            <p class="font-medium text-gray-800">{{ cliente.direccion }}</p>
          </div>
        </div>
        <p v-if="!cliente.cedula && !cliente.telefono && !cliente.email && !cliente.direccion" class="text-xs text-gray-400 italic">
          Sin datos de contacto registrados.
        </p>
      </div>

      <!-- Info del cliente — modo edición -->
      <div v-else class="bg-white rounded-xl shadow-sm p-4 space-y-3 text-sm">
        <div class="flex items-center justify-between mb-1">
          <p class="text-xs font-semibold text-gray-500 uppercase">Editar información</p>
          <button @click="editando = false" class="text-gray-400 hover:text-gray-600">
            <XMarkIcon class="w-4 h-4" />
          </button>
        </div>

        <div>
          <label class="text-xs font-medium text-gray-600 mb-1 block">Nombre <span class="text-red-500">*</span></label>
          <input
            v-model="formEdit.nombre"
            type="text"
            placeholder="Nombre completo"
            class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
          />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 mb-1 block">Cédula</label>
          <input
            v-model="formEdit.cedula"
            type="text"
            placeholder="Número de cédula"
            class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
          />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 mb-1 block">Teléfono</label>
          <input
            v-model="formEdit.telefono"
            type="tel"
            placeholder="Número de teléfono"
            class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
          />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 mb-1 block">Email</label>
          <input
            v-model="formEdit.email"
            type="email"
            placeholder="correo@ejemplo.com"
            class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
          />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-600 mb-1 block">Dirección</label>
          <input
            v-model="formEdit.direccion"
            type="text"
            placeholder="Dirección"
            class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
          />
        </div>

        <p v-if="errorEdit" class="text-xs text-red-600 font-medium">{{ errorEdit }}</p>

        <div class="flex gap-2 pt-1">
          <button
            @click="editando = false"
            class="flex-1 border border-gray-200 text-gray-600 rounded-lg py-2.5 text-sm font-medium hover:bg-gray-50 transition-colors"
          >
            Cancelar
          </button>
          <button
            @click="guardarEdicion"
            :disabled="guardando"
            class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 flex items-center justify-center gap-2 transition-colors"
          >
            <ArrowPathIcon v-if="guardando" class="w-4 h-4 animate-spin" />
            <CheckIcon v-else class="w-4 h-4" />
            {{ guardando ? 'Guardando...' : 'Guardar cambios' }}
          </button>
        </div>
      </div>

      <!-- KPIs -->
      <div class="grid grid-cols-3 gap-3">
        <div class="bg-white rounded-xl shadow-sm p-3 text-center">
          <p class="text-xl font-bold text-gray-800">{{ cliente.total_ordenes }}</p>
          <p class="text-xs text-gray-400 mt-0.5">Órdenes</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3 text-center">
          <p
            class="text-xl font-bold"
            :class="cliente.saldo_pendiente_total > 0 ? 'text-red-600' : 'text-green-600'"
          >
            ${{ formatMoney(cliente.saldo_pendiente_total) }}
          </p>
          <p class="text-xs text-gray-400 mt-0.5">Saldo pend.</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-3 text-center">
          <p class="text-xl font-bold text-gray-800">{{ formatFechaCorta(cliente.ultima_compra) }}</p>
          <p class="text-xs text-gray-400 mt-0.5">Última compra</p>
        </div>
      </div>

      <!-- Órdenes del cliente -->
      <div class="space-y-3">
        <div class="flex items-center gap-2">
          <CalendarIcon class="w-5 h-5 text-gray-400" />
          <h3 class="text-sm font-semibold text-gray-600 uppercase">Historial de órdenes ({{ cliente.total_ordenes }})</h3>
        </div>

        <!-- Loading órdenes -->
        <div v-if="loadingOrdenes && ordenes.length === 0" class="text-center py-8 text-gray-400 text-sm">
          Cargando órdenes...
        </div>

        <!-- Empty -->
        <EmptyState
          v-else-if="ordenes.length === 0"
          message="Este cliente aún no tiene órdenes."
        />

        <!-- Lista -->
        <template v-else>
          <ul class="space-y-2">
            <li
              v-for="o in ordenes"
              :key="o.id"
              @click="goToOrden(o.id)"
              class="bg-white rounded-xl shadow-sm p-4 cursor-pointer hover:bg-blue-50 transition-colors active:bg-blue-100"
            >
              <div class="flex justify-between items-start gap-2">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <span class="font-semibold text-sm text-gray-800">#{{ o.id }}</span>
                    <BadgeEstado :estado="o.estado" />
                  </div>
                  <p class="text-xs text-gray-400">{{ o.tienda?.nombre }} · {{ o.vendedor?.nombre }}</p>
                  <p class="text-xs text-gray-300 mt-0.5">{{ formatFechaCorta(o.created_at) }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-sm font-semibold text-gray-700"><MoneyDisplay :amount="o.valor_total" /></p>
                  <p
                    v-if="o.saldo_pendiente > 0"
                    class="text-xs font-medium text-red-500 mt-0.5"
                  >
                    Resta ${{ formatMoney(o.saldo_pendiente) }}
                  </p>
                  <p v-else class="text-xs font-medium text-green-500 mt-0.5">Pagada</p>
                </div>
              </div>
            </li>
          </ul>

          <!-- Sentinel scroll infinito -->
          <div ref="sentinel" class="py-4 text-center">
            <div v-if="loadingOrdenes" class="text-sm text-gray-400">Cargando más...</div>
            <div v-else-if="!tieneMasOrdenes" class="text-xs text-gray-300">No hay más órdenes.</div>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
