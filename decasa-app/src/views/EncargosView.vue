<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { getTrabajadores, entregar, guardarConfig } from '@/api/encargos'
import {
  BriefcaseIcon, PlusIcon, XMarkIcon, MagnifyingGlassIcon,
  ExclamationTriangleIcon, Cog6ToothIcon, ChevronRightIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast  = useToast()

const cargando     = ref(true)
const trabajadores = ref([])
const asignables   = ref([])
const diasGenerales = ref(30)
const valorTotal   = ref(0)
const vencidas     = ref(0)
const busqueda     = ref('')
const verInactivos = ref(false)

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}
function formatoFecha(f) {
  if (!f) return ''
  return new Date(f + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
}
function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Cómo se pinta cada estado de revista. El texto dice qué hacer, no qué pasó:
// "Toca revisar" mueve más que "vencida hace 4 días".
const ESTADOS = {
  vencida:      { label: 'Toca revisar',  clase: 'bg-red-100 text-red-700' },
  pronto:       { label: 'Está por caer', clase: 'bg-amber-100 text-amber-700' },
  al_dia:       { label: 'Al día',        clase: 'bg-green-100 text-green-700' },
  sin_encargos: { label: 'Sin nada',      clase: 'bg-gray-100 text-gray-500' },
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getTrabajadores(verInactivos.value)
    trabajadores.value  = data.trabajadores
    asignables.value    = data.asignables
    diasGenerales.value = data.dias_generales
    valorTotal.value    = data.valor_total
    vencidas.value      = data.vencidas
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo cargar la lista')
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

// Los que toca revisar suben: es la única razón por la que se abre esta
// pantalla un día cualquiera.
const ORDEN = { vencida: 0, pronto: 1, al_dia: 2, sin_encargos: 3 }
const lista = computed(() => {
  const q = busqueda.value.trim().toLowerCase()
  return trabajadores.value
    .filter(t => !q || t.nombre.toLowerCase().includes(q) || (t.cargo ?? '').toLowerCase().includes(q))
    .slice()
    .sort((a, b) => (ORDEN[a.revision.estado] - ORDEN[b.revision.estado]) || a.nombre.localeCompare(b.nombre))
})

// ── Entregar algo ──────────────────────────────────────────────────────────
const mostrarEntrega = ref(false)
const guardando      = ref(false)
const form = ref({ usuario_id: '', nombre: '', cantidad: 1, serial: '', valor_unitario: 0, fecha_entrega: '', notas: '' })

function abrirEntrega() {
  form.value = {
    usuario_id: '', nombre: '', cantidad: 1, serial: '',
    valor_unitario: 0, fecha_entrega: hoyISO(), notas: '',
  }
  mostrarEntrega.value = true
}

async function guardarEntrega() {
  if (!form.value.usuario_id) return toast.error('Elige a quién se le entrega')
  if (!form.value.nombre.trim()) return toast.error('Escribe qué se le entrega')
  if (!form.value.cantidad || form.value.cantidad < 1) return toast.error('La cantidad tiene que ser al menos 1')

  guardando.value = true
  try {
    await entregar({
      usuario_id: form.value.usuario_id,
      nombre: form.value.nombre.trim(),
      cantidad: Number(form.value.cantidad),
      serial: form.value.serial.trim() || null,
      // Sin valor no se puede sugerir cuánto descontar si se pierde, pero se
      // permite: hay cosas que no se le cobran a nadie.
      valor_unitario: Number(form.value.valor_unitario) || null,
      fecha_entrega: form.value.fecha_entrega,
      notas: form.value.notas.trim() || null,
    })
    toast.success('Entregado y anotado')
    mostrarEntrega.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

// ── Cada cuánto se revisa ──────────────────────────────────────────────────
const mostrarConfig = ref(false)
const diasEditados  = ref(30)
const guardandoConfig = ref(false)

function abrirConfig() {
  diasEditados.value = diasGenerales.value
  mostrarConfig.value = true
}

async function guardarDias() {
  const dias = Number(diasEditados.value)
  if (!dias || dias < 1) return toast.error('Tiene que ser al menos un día')
  guardandoConfig.value = true
  try {
    await guardarConfig({ dias_generales: dias })
    toast.success(`Ahora se revisa cada ${dias} días`)
    mostrarConfig.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoConfig.value = false
  }
}
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center gap-3 mb-4">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h1 class="text-lg font-bold text-gray-800 flex items-center gap-2 flex-1">
        <BriefcaseIcon class="w-5 h-5 text-teal-600" />
        Encargos
      </h1>
      <button @click="abrirConfig" class="p-1.5 text-gray-400 hover:text-gray-600" aria-label="Cada cuánto se revisa">
        <Cog6ToothIcon class="w-5 h-5" />
      </button>
    </div>

    <!-- Resumen -->
    <div class="grid grid-cols-2 gap-3 mb-3">
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-xs text-gray-400">Repartido por ahí</p>
        <p class="text-xl font-bold text-gray-800">{{ formatoPesos(valorTotal) }}</p>
      </div>
      <div class="bg-white rounded-xl shadow-sm p-4">
        <p class="text-xs text-gray-400">Toca revisar</p>
        <p class="text-xl font-bold" :class="vencidas ? 'text-red-600' : 'text-green-600'">
          {{ vencidas }}
        </p>
      </div>
    </div>

    <p class="text-xs text-gray-400 mb-3">
      Se revisa cada {{ diasGenerales }} días, salvo a quien se le haya puesto otro ritmo.
    </p>

    <div class="flex items-center justify-between gap-2 mb-3">
      <div class="relative flex-1">
        <MagnifyingGlassIcon class="w-4 h-4 text-gray-300 absolute left-3 top-1/2 -translate-y-1/2" />
        <input
          v-model="busqueda" placeholder="Buscar por nombre o cargo..."
          class="w-full rounded-xl border border-gray-200 pl-9 pr-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent transition-shadow"
        />
      </div>
      <button
        @click="abrirEntrega"
        class="flex items-center gap-1.5 bg-teal-600 text-white text-xs font-semibold px-3 py-2.5 rounded-xl hover:bg-teal-700 transition-colors shadow-sm shrink-0"
      >
        <PlusIcon class="w-4 h-4" /> Entregar
      </button>
    </div>

    <label class="flex items-center gap-2 text-xs text-gray-500 mb-3">
      <input type="checkbox" v-model="verInactivos" @change="cargar" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
      Incluir a los que ya no trabajan aquí
      <span class="text-gray-400">— para saber qué se quedó sin devolver</span>
    </label>

    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-6 h-6 border-2 border-teal-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <div v-else-if="!trabajadores.length" class="text-center py-12 px-6">
      <BriefcaseIcon class="w-10 h-10 text-gray-300 mx-auto mb-3" />
      <p class="text-gray-500 text-sm font-medium">Todavía no se le ha entregado nada a nadie.</p>
      <p class="text-gray-400 text-xs mt-1">Con "Entregar" queda anotado quién responde por qué.</p>
    </div>

    <p v-else-if="!lista.length" class="text-center py-12 text-gray-400 text-sm">Nadie coincide con "{{ busqueda }}".</p>

    <div v-else class="space-y-2.5">
      <button
        v-for="t in lista" :key="t.id"
        @click="router.push({ name: 'encargo-trabajador', params: { id: t.id } })"
        class="w-full bg-white rounded-xl shadow-sm p-4 text-left hover:bg-gray-50 transition-colors"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="font-semibold text-sm text-gray-800 truncate">
              {{ t.nombre }}
              <span v-if="!t.activo" class="text-[10px] font-normal text-red-600 bg-red-50 rounded px-1.5 py-0.5 ml-1">ya no trabaja aquí</span>
            </p>
            <p class="text-xs text-gray-400">{{ t.cargo }}</p>
            <p class="text-xs text-gray-600 mt-1">
              {{ t.articulos }} {{ t.articulos === 1 ? 'cosa' : 'cosas' }}
              <span class="text-gray-400">· {{ t.piezas }} {{ t.piezas === 1 ? 'pieza' : 'piezas' }}</span>
              <span v-if="t.valor_total" class="text-gray-400"> · {{ formatoPesos(t.valor_total) }}</span>
            </p>
            <p v-if="t.danados" class="text-[11px] text-amber-700 mt-0.5">
              {{ t.danados }} {{ t.danados === 1 ? 'dañada' : 'dañadas' }}
            </p>
          </div>
          <div class="text-right shrink-0 flex items-center gap-1">
            <div>
              <span :class="['inline-block text-[10px] font-semibold rounded-full px-2 py-0.5', ESTADOS[t.revision.estado].clase]">
                {{ ESTADOS[t.revision.estado].label }}
              </span>
              <p v-if="t.revision.proxima" class="text-[10px] text-gray-400 mt-1">
                {{ t.revision.estado === 'vencida' ? 'venció el' : 'toca el' }} {{ formatoFecha(t.revision.proxima) }}
              </p>
              <p v-else-if="t.revision.ultima" class="text-[10px] text-gray-400 mt-1">
                última: {{ formatoFecha(t.revision.ultima) }}
              </p>
            </div>
            <ChevronRightIcon class="w-4 h-4 text-gray-300" />
          </div>
        </div>
      </button>
    </div>

    <!-- Entregar algo -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarEntrega" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarEntrega = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm">
              <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-teal-50 flex items-center justify-center shrink-0">
                  <BriefcaseIcon class="w-5 h-5 text-teal-600" />
                </div>
                <p class="font-semibold text-gray-800">Entregar algo</p>
              </div>
              <button @click="mostrarEntrega = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-4">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿A quién? *</label>
                <select v-model="form.usuario_id" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                  <option value="">Elegir trabajador...</option>
                  <option v-for="a in asignables" :key="a.id" :value="a.id">{{ a.nombre }} — {{ a.cargo }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué se le entrega? *</label>
                <input v-model="form.nombre" placeholder="Taladro Bosch" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuántas? *</label>
                  <input v-model="form.cantidad" type="number" min="1" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Fecha *</label>
                  <input v-model="form.fecha_entrega" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                </div>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Serial o placa</label>
                <input v-model="form.serial" placeholder="Para distinguirlo cuando hay varios iguales" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuánto vale reponer una?</label>
                <InputPesos v-model="form.valor_unitario" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                <p class="text-[11px] text-gray-400 mt-1">Es lo que se le sugiere descontar si se pierde. Se puede dejar vacío.</p>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas</label>
                <textarea v-model="form.notas" rows="2" placeholder="Estado en que se entrega, accesorios que lleva..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
            </div>

            <div class="flex gap-2.5 p-5 pt-2">
              <button @click="mostrarEntrega = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="guardarEntrega" :disabled="guardando"
                class="flex-1 bg-teal-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <span v-if="guardando" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                {{ guardando ? 'Guardando...' : 'Entregar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Cada cuánto se revisa -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarConfig" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarConfig = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
              <p class="font-semibold text-gray-800">¿Cada cuánto se revisa?</p>
              <button @click="mostrarConfig = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="p-5 space-y-3">
              <div class="flex items-center gap-2">
                <span class="text-sm text-gray-600">Cada</span>
                <input v-model="diasEditados" type="number" min="1" max="730" class="w-24 rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 text-center focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                <span class="text-sm text-gray-600">días</span>
              </div>
              <p class="text-xs text-gray-500">
                Aplica a todos. A quien necesite otro ritmo se le pone el suyo desde su ficha —
                al del portátil se le puede mirar cada seis meses y al del taller cada mes.
              </p>
              <div class="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl p-3">
                <ExclamationTriangleIcon class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                <p class="text-xs text-amber-800">
                  Se cuenta desde la última revisión de cada quien, así que al cambiarlo puede que a
                  varios les toque de una vez.
                </p>
              </div>
            </div>
            <div class="flex gap-2.5 p-5 pt-0">
              <button @click="mostrarConfig = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="guardarDias" :disabled="guardandoConfig"
                class="flex-1 bg-teal-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-50"
              >
                {{ guardandoConfig ? 'Guardando...' : 'Guardar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
