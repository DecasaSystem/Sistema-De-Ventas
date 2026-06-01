<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
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
  ChevronLeftIcon,
  ChevronRightIcon,
  ListBulletIcon,
  PlusIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

const auth     = useAuthStore()
const toast    = useToast()
const items    = ref([])
const tab      = ref('activas') // activas | completadas | canceladas
const cargando = ref(false)
const editando = ref(null)   // { id, notas }

// Formulario nueva cita manual
const mostrarForm = ref(false)
const guardando   = ref(false)
const form        = ref({ nombre: '', telefono: '', fecha: '', hora: '', motivo: '' })

function abrirForm() {
  form.value = { nombre: '', telefono: '', fecha: '', hora: '', motivo: '' }
  mostrarForm.value = true
}

async function crearCita() {
  if (!form.value.nombre || !form.value.fecha || !form.value.hora) return
  guardando.value = true
  try {
    const { data } = await api.post('/citas', {
      nombre_cliente: form.value.nombre,
      telefono:       form.value.telefono || undefined,
      fecha_cita:     form.value.fecha,
      hora:           form.value.hora,
      motivo:         form.value.motivo || undefined,
    })
    items.value.unshift(data)
    mostrarForm.value = false
    tab.value = 'activas'
    toast.success('Cita agendada correctamente')
  } catch {
    toast.error('No se pudo agendar la cita')
  } finally {
    guardando.value = false
  }
}

const fechaMinima = new Date().toISOString().split('T')[0]

// Vista
const vista            = ref('lista') // 'lista' | 'calendario'
const calMes           = ref(new Date().getMonth())    // 0-11
const calAnio          = ref(new Date().getFullYear())
const diaSeleccionado  = ref(null)   // "YYYY-MM-DD"

const MESES      = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
const DIAS_SEM   = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom']

// ── Tabs ──────────────────────────────────────────────────────────────────────

const filtrados = computed(() => {
  if (tab.value === 'activas')     return items.value.filter(c => ['pendiente', 'confirmada'].includes(c.estado))
  if (tab.value === 'completadas') return items.value.filter(c => c.estado === 'completada')
  if (tab.value === 'canceladas')  return items.value.filter(c => c.estado === 'cancelada')
  return items.value
})

const citasMostradas = computed(() => {
  if (vista.value === 'calendario' && diaSeleccionado.value) {
    return filtrados.value.filter(c => c.fecha_cita?.substring(0, 10) === diaSeleccionado.value)
  }
  return filtrados.value
})

const badges = computed(() => ({
  activas:     items.value.filter(c => ['pendiente', 'confirmada'].includes(c.estado)).length,
  completadas: items.value.filter(c => c.estado === 'completada').length,
  canceladas:  items.value.filter(c => c.estado === 'cancelada').length,
}))

// ── Calendario ────────────────────────────────────────────────────────────────

// Map: "YYYY-MM-DD" → citas[]
const diasConCitas = computed(() => {
  const map = {}
  items.value.forEach(c => {
    if (c.fecha_cita) {
      const key = c.fecha_cita.substring(0, 10)
      if (!map[key]) map[key] = []
      map[key].push(c)
    }
  })
  return map
})

const celdas = computed(() => {
  const primer  = new Date(calAnio.value, calMes.value, 1)
  const ultimo  = new Date(calAnio.value, calMes.value + 1, 0)
  const result  = []
  const offset  = (primer.getDay() + 6) % 7   // Monday = 0
  for (let i = 0; i < offset; i++) result.push(null)
  for (let d = 1; d <= ultimo.getDate(); d++) {
    const fecha = `${calAnio.value}-${String(calMes.value + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    result.push({ dia: d, fecha })
  }
  return result
})

const hoy = new Date()
const fechaHoy = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`

function prevMes() {
  if (calMes.value === 0) { calMes.value = 11; calAnio.value-- }
  else calMes.value--
  diaSeleccionado.value = null
}
function nextMes() {
  if (calMes.value === 11) { calMes.value = 0; calAnio.value++ }
  else calMes.value++
  diaSeleccionado.value = null
}
function seleccionarDia(fecha) {
  diaSeleccionado.value = diaSeleccionado.value === fecha ? null : fecha
}

function labelDiaSeleccionado() {
  if (!diaSeleccionado.value) return ''
  const d = new Date(diaSeleccionado.value + 'T12:00:00')
  return d.toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' })
}

// ── API ───────────────────────────────────────────────────────────────────────

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/citas')
    items.value = data
  } catch (e) {
    if (e.response?.status !== 401) {
      toast.error('No se pudieron cargar las citas')
    }
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
    toast.error('No se pudo actualizar el estado')
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
    toast.error('No se pudo guardar las notas')
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function estadoBadge(estado) {
  return {
    pendiente:  { label: 'Pendiente',  cls: 'bg-yellow-100 text-yellow-700' },
    confirmada: { label: 'Confirmada', cls: 'bg-blue-100 text-blue-700' },
    completada: { label: 'Completada', cls: 'bg-green-100 text-green-700' },
    cancelada:  { label: 'Cancelada',  cls: 'bg-red-100 text-red-700' },
  }[estado] ?? { label: estado, cls: 'bg-gray-100 text-gray-600' }
}

function fuenteIcon(fuente) {
  return fuente === 'instagram' ? '📸' : '💬'
}

async function abrirContacto(cita) {
  if (cita.fuente === 'instagram') {
    const asesor = auth.usuario?.nombre || 'tu asesor'
    const saludo = cita.nombre_cliente ? `Hola ${cita.nombre_cliente}, ` : 'Hola, '
    let msg = `${saludo}soy ${asesor} de DeCasa Muebles y Decoración 🛋️ Me da mucho gusto atenderte.`
    if (cita.dia) msg += `\n\nYa tengo el detalle de tu cita para el ${cita.dia} a las ${cita.hora}.`
    if (cita.motivo) msg += ` (${cita.motivo})`
    try {
      await navigator.clipboard.writeText(msg)
      toast.success('Saludo copiado — pégalo al abrir el chat de Instagram')
    } catch {
      toast.info('Abriendo Instagram...')
    }
  }
  window.open(cita.contacto_url, '_blank', 'noopener')
}

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(cargar)
</script>

<template>
  <div>
  <div class="max-w-lg mx-auto px-4 py-4">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-xl font-bold text-gray-800 flex items-center gap-2">
        <CalendarDaysIcon class="w-6 h-6 text-blue-600" />
        Mis citas
      </h1>
      <button
        @click="abrirForm"
        class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm"
      >
        <PlusIcon class="w-4 h-4" /> Nueva cita
      </button>
    </div>

    <!-- Vista toggle -->
    <div class="flex rounded-xl bg-gray-100 p-1 mb-4 gap-1">
      <button
        @click="vista = 'lista'; diaSeleccionado = null"
        :class="['flex-1 py-1.5 rounded-lg text-xs font-semibold transition-colors flex items-center justify-center gap-1',
          vista === 'lista' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700']"
      >
        <ListBulletIcon class="w-4 h-4" /> Lista
      </button>
      <button
        @click="vista = 'calendario'"
        :class="['flex-1 py-1.5 rounded-lg text-xs font-semibold transition-colors flex items-center justify-center gap-1',
          vista === 'calendario' ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700']"
      >
        <CalendarDaysIcon class="w-4 h-4" /> Calendario
      </button>
    </div>

    <!-- Tabs (estado) -->
    <div class="flex rounded-xl bg-gray-100 p-1 mb-4 gap-1">
      <button
        v-for="t in [
          { key: 'activas',     label: 'Activas' },
          { key: 'completadas', label: 'Completadas' },
          { key: 'canceladas',  label: 'Canceladas' },
        ]"
        :key="t.key"
        @click="tab = t.key; diaSeleccionado = null"
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

    <!-- ── Calendario ───────────────────────────────────────────────────────── -->
    <div v-if="vista === 'calendario'" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
      <!-- Navegación de mes -->
      <div class="flex items-center justify-between mb-3">
        <button @click="prevMes" class="p-1.5 hover:bg-gray-100 rounded-lg transition-colors">
          <ChevronLeftIcon class="w-5 h-5 text-gray-600" />
        </button>
        <span class="font-semibold text-sm text-gray-800">{{ MESES[calMes] }} {{ calAnio }}</span>
        <button @click="nextMes" class="p-1.5 hover:bg-gray-100 rounded-lg transition-colors">
          <ChevronRightIcon class="w-5 h-5 text-gray-600" />
        </button>
      </div>

      <!-- Cabecera días -->
      <div class="grid grid-cols-7 mb-1">
        <div
          v-for="d in DIAS_SEM"
          :key="d"
          class="text-center text-[10px] font-medium text-gray-400 py-1"
        >{{ d }}</div>
      </div>

      <!-- Celdas -->
      <div class="grid grid-cols-7 gap-0.5">
        <div v-for="(celda, i) in celdas" :key="i" class="aspect-square">
          <button
            v-if="celda"
            @click="seleccionarDia(celda.fecha)"
            :class="[
              'w-full h-full flex flex-col items-center justify-center rounded-lg text-xs font-medium transition-colors',
              diaSeleccionado === celda.fecha
                ? 'bg-blue-600 text-white'
                : diasConCitas[celda.fecha]
                  ? 'bg-blue-50 text-blue-700 hover:bg-blue-100'
                  : 'text-gray-600 hover:bg-gray-100',
              celda.fecha === fechaHoy && diaSeleccionado !== celda.fecha
                ? 'ring-1 ring-blue-400' : ''
            ]"
          >
            {{ celda.dia }}
            <span
              v-if="diasConCitas[celda.fecha]"
              :class="['block w-1.5 h-1.5 rounded-full', diaSeleccionado === celda.fecha ? 'bg-white' : 'bg-blue-500']"
            />
          </button>
          <div v-else />
        </div>
      </div>

      <!-- Leyenda -->
      <div class="flex items-center gap-3 mt-3 text-[10px] text-gray-400">
        <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-500 inline-block" /> Con citas</span>
        <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-sm ring-1 ring-blue-400 inline-block" /> Hoy</span>
      </div>
    </div>

    <!-- Filtro por día seleccionado -->
    <div v-if="diaSeleccionado" class="flex items-center gap-2 text-xs text-gray-500 mb-3">
      <CalendarDaysIcon class="w-3.5 h-3.5 text-blue-500" />
      <span class="capitalize">{{ labelDiaSeleccionado() }}</span>
      <button @click="diaSeleccionado = null" class="ml-auto text-blue-500 hover:underline">Ver todas</button>
    </div>

    <!-- Estado carga -->
    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
    </div>
    <div v-else-if="citasMostradas.length === 0" class="text-center py-14">
      <CalendarDaysIcon class="w-12 h-12 text-gray-300 mx-auto mb-2" />
      <p class="text-gray-400 text-sm">
        {{ diaSeleccionado ? 'No hay citas este día' : 'No hay citas en esta sección' }}
      </p>
    </div>

    <!-- Lista -->
    <div v-else class="space-y-3">
      <div
        v-for="cita in citasMostradas"
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
            <button
              v-if="cita.contacto_url"
              @click="abrirContacto(cita)"
              :class="[
                'flex items-center gap-1 text-xs font-medium',
                cita.fuente === 'instagram' ? 'text-purple-600 hover:text-purple-700' : 'text-green-600 hover:text-green-700'
              ]"
            >
              <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
              {{ cita.fuente === 'instagram' ? 'Abrir IG' : 'Abrir WA' }}
            </button>
            <button
              @click="editando = { id: cita.id, notas: cita.notas || '' }"
              class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600"
            >
              <PencilSquareIcon class="w-3.5 h-3.5" />
              Notas
            </button>
          </div>

          <div v-if="['pendiente', 'confirmada'].includes(cita.estado)" class="flex items-center gap-2">
            <!-- "Confirmar" solo para citas manuales (sin origen en Redes) -->
            <button
              v-if="cita.estado === 'pendiente' && !cita.conversacion_wa_id"
              @click="cambiarEstado(cita.id, 'confirmada')"
              class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition-colors"
            >
              Confirmar
            </button>
            <!-- "Terminar" para citas de Redes, "Completada" para manuales -->
            <button
              v-if="cita.estado === 'confirmada'"
              @click="cambiarEstado(cita.id, 'completada')"
              class="flex items-center gap-1 text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition-colors"
            >
              <CheckCircleIcon class="w-3.5 h-3.5" />
              {{ cita.conversacion_wa_id ? 'Terminar' : 'Completada' }}
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

  <!-- ── Panel nueva cita manual ─────────────────────────────────────────────── -->
  <div v-if="mostrarForm" class="fixed inset-0 z-50 flex flex-col justify-end">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/40" @click="mostrarForm = false" />

    <!-- Sheet -->
    <div class="relative bg-white rounded-t-2xl shadow-xl px-4 pt-4 pb-8 max-h-[90vh] overflow-y-auto">
      <!-- Handle + header -->
      <div class="w-10 h-1 bg-gray-200 rounded-full mx-auto mb-4" />
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-base font-bold text-gray-800">Nueva cita manual</h2>
        <button @click="mostrarForm = false" class="p-1 text-gray-400 hover:text-gray-600">
          <XMarkIcon class="w-5 h-5" />
        </button>
      </div>

      <!-- Formulario -->
      <form @submit.prevent="crearCita" class="space-y-4">

        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Nombre del cliente *</label>
          <input
            v-model="form.nombre"
            type="text"
            required
            placeholder="Ej: María López"
            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-blue-400"
          />
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Teléfono <span class="font-normal text-gray-400">(opcional)</span></label>
          <input
            v-model="form.telefono"
            type="tel"
            placeholder="Ej: 3001234567"
            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-blue-400"
          />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Fecha *</label>
            <input
              v-model="form.fecha"
              type="date"
              required
              :min="fechaMinima"
              class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-blue-400"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Hora *</label>
            <input
              v-model="form.hora"
              type="time"
              required
              class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-blue-400"
            />
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Motivo <span class="font-normal text-gray-400">(opcional)</span></label>
          <input
            v-model="form.motivo"
            type="text"
            placeholder="Ej: Ver sala de estar, comedor..."
            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-blue-400"
          />
        </div>

        <button
          type="submit"
          :disabled="guardando || !form.nombre || !form.fecha || !form.hora"
          class="w-full py-3 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ guardando ? 'Agendando...' : 'Agendar cita' }}
        </button>
      </form>
    </div>
  </div>
  </div>
</template>
