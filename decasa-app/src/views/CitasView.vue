<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/api'
import {
  CalendarDaysIcon,
  MapPinIcon,
  UserIcon,
  ClockIcon,
  CheckCircleIcon,
  XCircleIcon,
  ChatBubbleLeftRightIcon,
  ArrowTopRightOnSquareIcon,
  PencilSquareIcon,
} from '@heroicons/vue/24/outline'

const auth    = useAuthStore()
const items   = ref([])
const tab     = ref('activas') // activas | completadas | canceladas
const cargando = ref(false)
const editando = ref(null)   // { id, notas }

const filtrados = computed(() => {
  if (tab.value === 'activas')     return items.value.filter(c => ['pendiente', 'confirmada'].includes(c.estado))
  if (tab.value === 'completadas') return items.value.filter(c => c.estado === 'completada')
  if (tab.value === 'canceladas')  return items.value.filter(c => c.estado === 'cancelada')
  return items.value
})

const badges = computed(() => ({
  activas:     items.value.filter(c => ['pendiente', 'confirmada'].includes(c.estado)).length,
  completadas: items.value.filter(c => c.estado === 'completada').length,
  canceladas:  items.value.filter(c => c.estado === 'cancelada').length,
}))

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/citas')
    items.value = data
  } finally {
    cargando.value = false
  }
}

async function cambiarEstado(id, estado) {
  const cita = items.value.find(c => c.id === id)
  if (!cita) return
  const prev = cita.estado
  cita.estado = estado
  try {
    const { data } = await api.patch(`/citas/${id}`, { estado })
    const idx = items.value.findIndex(c => c.id === id)
    if (idx !== -1) items.value[idx] = data
  } catch {
    cita.estado = prev
    alert('No se pudo actualizar el estado')
  }
}

async function guardarNotas(id) {
  if (!editando.value) return
  try {
    const { data } = await api.patch(`/citas/${id}`, { notas: editando.value.notas })
    const idx = items.value.findIndex(c => c.id === id)
    if (idx !== -1) items.value[idx] = data
    editando.value = null
  } catch {
    alert('No se pudo guardar las notas')
  }
}

function estadoBadge(estado) {
  return {
    pendiente:   { label: 'Pendiente',   cls: 'bg-yellow-100 text-yellow-700' },
    confirmada:  { label: 'Confirmada',  cls: 'bg-blue-100 text-blue-700' },
    completada:  { label: 'Completada',  cls: 'bg-green-100 text-green-700' },
    cancelada:   { label: 'Cancelada',   cls: 'bg-red-100 text-red-700' },
  }[estado] ?? { label: estado, cls: 'bg-gray-100 text-gray-600' }
}

function fuenteIcon(fuente) {
  return fuente === 'instagram' ? '📸' : '💬'
}

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(cargar)
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4">
    <h1 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
      <CalendarDaysIcon class="w-6 h-6 text-blue-600" />
      Mis citas
    </h1>

    <!-- Tabs -->
    <div class="flex rounded-xl bg-gray-100 p-1 mb-4 gap-1">
      <button
        v-for="t in [
          { key: 'activas',     label: 'Activas' },
          { key: 'completadas', label: 'Completadas' },
          { key: 'canceladas',  label: 'Canceladas' },
        ]"
        :key="t.key"
        @click="tab = t.key"
        :class="[
          'flex-1 py-1.5 rounded-lg text-xs font-semibold transition-colors relative',
          tab === t.key ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'
        ]"
      >
        {{ t.label }}
        <span
          v-if="badges[t.key] > 0"
          :class="[
            'absolute -top-1 -right-1 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5',
            t.key === 'activas' ? 'bg-blue-500' : 'bg-gray-400'
          ]"
        >{{ badges[t.key] > 9 ? '9+' : badges[t.key] }}</span>
      </button>
    </div>

    <!-- Estado -->
    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>
    <div v-else-if="filtrados.length === 0" class="text-center py-14">
      <CalendarDaysIcon class="w-12 h-12 text-gray-300 mx-auto mb-2" />
      <p class="text-gray-400 text-sm">No hay citas en esta sección</p>
    </div>

    <!-- Lista -->
    <div v-else class="space-y-3">
      <div
        v-for="cita in filtrados"
        :key="cita.id"
        class="bg-white rounded-xl shadow-sm border border-gray-100 p-4"
      >
        <!-- Header -->
        <div class="flex items-start justify-between gap-2 mb-3">
          <div class="min-w-0">
            <p class="font-semibold text-gray-800 text-sm truncate">
              {{ fuenteIcon(cita.fuente) }} {{ cita.nombre_cliente || cita.telefono || 'Cliente' }}
            </p>
            <p v-if="cita.asesor && auth.isSupervisor" class="text-xs text-gray-400 flex items-center gap-1 mt-0.5">
              <UserIcon class="w-3 h-3" /> {{ cita.asesor.nombre }}
            </p>
          </div>
          <span :class="['text-[11px] font-semibold px-2 py-0.5 rounded-full flex-shrink-0', estadoBadge(cita.estado).cls]">
            {{ estadoBadge(cita.estado).label }}
          </span>
        </div>

        <!-- Fecha, hora y sede -->
        <div class="bg-blue-50 rounded-lg p-3 mb-3 space-y-1 text-xs">
          <p class="text-blue-800 font-medium flex items-center gap-1">
            <ClockIcon class="w-3.5 h-3.5" />
            {{ cita.dia }} a las {{ cita.hora }}
          </p>
          <p v-if="cita.tienda" class="text-blue-700 flex items-center gap-1">
            <MapPinIcon class="w-3 h-3" /> {{ cita.tienda.nombre }}
          </p>
          <p v-if="cita.motivo" class="text-blue-600 italic">"{{ cita.motivo }}"</p>
        </div>

        <!-- Notas -->
        <div v-if="editando?.id === cita.id" class="mb-3">
          <textarea
            v-model="editando.notas"
            rows="3"
            placeholder="Agrega notas sobre esta cita..."
            class="w-full text-xs border border-gray-200 rounded-lg p-2 resize-none focus:outline-none focus:border-blue-400"
          />
          <div class="flex gap-2 mt-1">
            <button @click="guardarNotas(cita.id)" class="text-xs bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700">Guardar</button>
            <button @click="editando = null" class="text-xs text-gray-500 hover:text-gray-700">Cancelar</button>
          </div>
        </div>
        <p v-else-if="cita.notas" class="text-xs text-gray-500 italic mb-3 bg-gray-50 rounded-lg p-2">
          📝 {{ cita.notas }}
        </p>

        <!-- Acciones -->
        <div class="flex items-center justify-between gap-2 flex-wrap">
          <div class="flex items-center gap-2">
            <!-- Contactar -->
            <a
              v-if="cita.contacto_url"
              :href="cita.contacto_url"
              target="_blank"
              :class="[
                'flex items-center gap-1 text-xs font-medium',
                cita.fuente === 'instagram' ? 'text-purple-600 hover:text-purple-700' : 'text-green-600 hover:text-green-700'
              ]"
            >
              <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
              {{ cita.fuente === 'instagram' ? 'Abrir IG' : 'Abrir WA' }}
            </a>
            <!-- Editar notas -->
            <button
              @click="editando = { id: cita.id, notas: cita.notas || '' }"
              class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600"
            >
              <PencilSquareIcon class="w-3.5 h-3.5" />
              Notas
            </button>
          </div>

          <!-- Botones de estado -->
          <div v-if="['pendiente', 'confirmada'].includes(cita.estado)" class="flex items-center gap-2">
            <button
              v-if="cita.estado === 'pendiente'"
              @click="cambiarEstado(cita.id, 'confirmada')"
              class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition-colors"
            >
              Confirmar
            </button>
            <button
              v-if="cita.estado === 'confirmada'"
              @click="cambiarEstado(cita.id, 'completada')"
              class="flex items-center gap-1 text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition-colors"
            >
              <CheckCircleIcon class="w-3.5 h-3.5" /> Completada
            </button>
            <button
              @click="cambiarEstado(cita.id, 'cancelada')"
              class="flex items-center gap-1 text-xs px-3 py-1.5 bg-red-100 text-red-600 rounded-lg font-semibold hover:bg-red-200 transition-colors"
            >
              <XCircleIcon class="w-3.5 h-3.5" /> Cancelar
            </button>
          </div>

          <p v-else class="text-xs text-gray-400">{{ formatFecha(cita.updated_at) }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
