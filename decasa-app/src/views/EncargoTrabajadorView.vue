<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import {
  getTrabajador, entregar, cerrarEncargo, borrarEncargo,
  guardarRevision, getRevision, guardarConfig,
} from '@/api/encargos'
import {
  BriefcaseIcon, PlusIcon, XMarkIcon, TrashIcon, CheckCircleIcon,
  ClipboardDocumentCheckIcon, ExclamationTriangleIcon, ArrowUturnLeftIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const toast  = useToast()

const cargando   = ref(true)
const trabajador = ref(null)
const encargos   = ref([])
const revisiones = ref([])
const puedeAdministrar = ref(false)

const esMiFicha = computed(() => Number(route.params.id) === auth.usuario?.id)

const aCargo   = computed(() => encargos.value.filter(e => e.estado === 'a_cargo'))
const cerrados = computed(() => encargos.value.filter(e => e.estado !== 'a_cargo'))

const ESTADOS = {
  vencida:      { label: 'Toca revisar',  clase: 'bg-red-100 text-red-700' },
  pronto:       { label: 'Está por caer', clase: 'bg-amber-100 text-amber-700' },
  al_dia:       { label: 'Al día',        clase: 'bg-green-100 text-green-700' },
  sin_encargos: { label: 'Sin nada a cargo', clase: 'bg-gray-100 text-gray-500' },
}
const CIERRE = {
  devuelto: { label: 'Devuelto', clase: 'bg-green-50 text-green-700' },
  perdido:  { label: 'Perdido',  clase: 'bg-red-50 text-red-700' },
  baja:     { label: 'De baja',  clase: 'bg-gray-100 text-gray-500' },
}

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

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getTrabajador(route.params.id)
    trabajador.value = data.trabajador
    encargos.value   = data.encargos
    revisiones.value = data.revisiones
    puedeAdministrar.value = data.puede_administrar
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo cargar la ficha')
    router.back()
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

// ── Entregar algo más ──────────────────────────────────────────────────────
const mostrarEntrega = ref(false)
const guardandoEntrega = ref(false)
const formEntrega = ref({ nombre: '', cantidad: 1, serial: '', valor_unitario: 0, fecha_entrega: '', notas: '' })

function abrirEntrega() {
  formEntrega.value = { nombre: '', cantidad: 1, serial: '', valor_unitario: 0, fecha_entrega: hoyISO(), notas: '' }
  mostrarEntrega.value = true
}

async function guardarEntrega() {
  if (!formEntrega.value.nombre.trim()) return toast.error('Escribe qué se le entrega')
  guardandoEntrega.value = true
  try {
    await entregar({
      usuario_id: Number(route.params.id),
      nombre: formEntrega.value.nombre.trim(),
      cantidad: Number(formEntrega.value.cantidad) || 1,
      serial: formEntrega.value.serial.trim() || null,
      valor_unitario: Number(formEntrega.value.valor_unitario) || null,
      fecha_entrega: formEntrega.value.fecha_entrega,
      notas: formEntrega.value.notas.trim() || null,
    })
    toast.success('Entregado y anotado')
    mostrarEntrega.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoEntrega.value = false
  }
}

// ── Cerrar un encargo (devolver / perdido / baja) ──────────────────────────
const mostrarCierre = ref(false)
const encargoACerrar = ref(null)
const formCierre = ref({ estado: 'devuelto', fecha: '', notas: '' })
const guardandoCierre = ref(false)

function abrirCierre(e) {
  encargoACerrar.value = e
  formCierre.value = { estado: 'devuelto', fecha: hoyISO(), notas: '' }
  mostrarCierre.value = true
}

async function confirmarCierre() {
  guardandoCierre.value = true
  try {
    await cerrarEncargo(encargoACerrar.value.id, {
      estado: formCierre.value.estado,
      fecha: formCierre.value.fecha,
      notas: formCierre.value.notas.trim() || null,
    })
    toast.success('Listo')
    mostrarCierre.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoCierre.value = false
  }
}

async function borrar(e) {
  if (!confirm(`¿Quitar "${e.nombre}"? Esto es solo para lo que se anotó por error.`)) return
  try {
    await borrarEncargo(e.id)
    toast.success('Quitado')
    await cargar()
  } catch (err) {
    toast.error(err.response?.data?.message || 'No se pudo quitar')
  }
}

// ── La revista ─────────────────────────────────────────────────────────────
const mostrarRevista = ref(false)
const guardandoRevista = ref(false)
const revista = ref({ fecha: '', notas: '', descontar: true, items: [] })

function abrirRevista() {
  revista.value = {
    fecha: hoyISO(),
    notas: '',
    // Se propone descontar lo perdido: si estuviera apagado por defecto, la
    // pérdida se registraría y la plata no aparecería por ningún lado.
    descontar: true,
    // Todo arranca en "bien": la revista es ir marcando las excepciones, que
    // en un día normal son una o ninguna.
    items: aCargo.value.map(e => ({
      encargo_id: e.id,
      nombre: e.nombre,
      serial: e.serial,
      total: e.cantidad,
      valor_unitario: e.valor_unitario ?? 0,
      cantidad_ok: e.cantidad,
      cantidad_danada: 0,
      cantidad_perdida: 0,
      descuento: 0,
      notas: '',
    })),
  }
  mostrarRevista.value = true
}

/**
 * Lo de una sola unidad se marca con un botón, no con tres casillas: para
 * "¿está el martillo?" escribir un 1 en una de tres columnas es absurdo.
 */
function marcarUnica(item, campo) {
  item.cantidad_ok = campo === 'ok' ? 1 : 0
  item.cantidad_danada = campo === 'danada' ? 1 : 0
  item.cantidad_perdida = campo === 'perdida' ? 1 : 0
  recalcularDescuento(item)
}

function estadoUnica(item) {
  if (item.cantidad_perdida) return 'perdida'
  if (item.cantidad_danada) return 'danada'
  return 'ok'
}

function recalcularDescuento(item) {
  item.descuento = Math.round((Number(item.cantidad_perdida) || 0) * (Number(item.valor_unitario) || 0))
}

function contadas(item) {
  return (Number(item.cantidad_ok) || 0) + (Number(item.cantidad_danada) || 0) + (Number(item.cantidad_perdida) || 0)
}

const revistaCuadra = computed(() => revista.value.items.every(i => contadas(i) === i.total))
const descuentoRevista = computed(() =>
  revista.value.items.reduce((s, i) => s + (Number(i.descuento) || 0), 0)
)
const hayPerdidas = computed(() => revista.value.items.some(i => Number(i.cantidad_perdida) > 0))

async function enviarRevista() {
  if (!revistaCuadra.value) {
    return toast.error('En alguna línea las cantidades no dan con lo que tiene a cargo')
  }
  guardandoRevista.value = true
  try {
    const { data } = await guardarRevision({
      usuario_id: Number(route.params.id),
      fecha: revista.value.fecha,
      notas: revista.value.notas.trim() || null,
      descontar: revista.value.descontar,
      items: revista.value.items.map(i => ({
        encargo_id: i.encargo_id,
        cantidad_ok: Number(i.cantidad_ok) || 0,
        cantidad_danada: Number(i.cantidad_danada) || 0,
        cantidad_perdida: Number(i.cantidad_perdida) || 0,
        descuento: Number(i.descuento) || 0,
        notas: i.notas.trim() || null,
      })),
    })
    mostrarRevista.value = false
    // El aviso es cuando el descuento no se pudo aplicar (ese ciclo de nómina
    // ya se pagó). Se muestra más tiempo: hay que hacer algo con eso.
    if (data.aviso) toast.error(data.aviso, 9000)
    else toast.success('Revisión guardada')
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar la revisión', 7000)
  } finally {
    guardandoRevista.value = false
  }
}

// ── Ver una revista vieja ──────────────────────────────────────────────────
const revisionVista = ref(null)
async function verRevision(r) {
  try {
    const { data } = await getRevision(r.id)
    revisionVista.value = data
  } catch {
    toast.error('No se pudo abrir la revisión')
  }
}

// ── Su propio ritmo de revisión ────────────────────────────────────────────
const mostrarRitmo = ref(false)
const diasPropios  = ref('')
const guardandoRitmo = ref(false)

function abrirRitmo() {
  diasPropios.value = trabajador.value.encargo_revision_dias ?? ''
  mostrarRitmo.value = true
}

async function guardarRitmo() {
  guardandoRitmo.value = true
  try {
    await guardarConfig({
      usuario_id: Number(route.params.id),
      // Vacío = vuelve a seguir el ritmo general.
      dias_usuario: diasPropios.value === '' ? null : Number(diasPropios.value),
    })
    toast.success('Listo')
    mostrarRitmo.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoRitmo.value = false
  }
}
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center gap-3 mb-4">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h1 class="text-lg font-bold text-gray-800 flex items-center gap-2 flex-1 truncate">
        <BriefcaseIcon class="w-5 h-5 text-teal-600 shrink-0" />
        {{ esMiFicha ? 'Mis encargos' : (trabajador?.nombre ?? 'Cargando...') }}
      </h1>
    </div>

    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-6 h-6 border-2 border-teal-500 border-t-transparent rounded-full animate-spin" />
    </div>

    <template v-else-if="trabajador">
      <!-- Estado de revista -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-3">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p v-if="!esMiFicha" class="text-xs text-gray-400">{{ trabajador.cargo }}</p>
            <p class="text-sm font-semibold text-gray-800 mt-0.5">
              {{ trabajador.articulos }} {{ trabajador.articulos === 1 ? 'cosa' : 'cosas' }} a cargo
              <span class="text-gray-400 font-normal">· {{ trabajador.piezas }} {{ trabajador.piezas === 1 ? 'pieza' : 'piezas' }}</span>
            </p>
            <p v-if="trabajador.valor_total" class="text-xs text-gray-500 mt-0.5">
              Vale {{ formatoPesos(trabajador.valor_total) }} reponerlo todo
            </p>
          </div>
          <span :class="['text-[10px] font-semibold rounded-full px-2 py-0.5 shrink-0', ESTADOS[trabajador.revision.estado].clase]">
            {{ ESTADOS[trabajador.revision.estado].label }}
          </span>
        </div>
        <p class="text-xs text-gray-400 mt-2">
          <template v-if="trabajador.revision.ultima">Última revisión: {{ formatoFecha(trabajador.revision.ultima) }}.</template>
          <template v-else>Nunca se le ha revisado.</template>
          <template v-if="trabajador.revision.proxima">
            {{ trabajador.revision.estado === 'vencida' ? ' Tocaba el' : ' Toca el' }} {{ formatoFecha(trabajador.revision.proxima) }}
            (cada {{ trabajador.revision.dias }} días).
          </template>
        </p>
        <button
          v-if="puedeAdministrar"
          @click="abrirRitmo"
          class="text-xs text-teal-700 font-medium mt-1 hover:text-teal-800"
        >
          {{ trabajador.encargo_revision_dias ? 'Cambiar su ritmo de revisión' : 'Ponerle otro ritmo de revisión' }}
        </button>
      </div>

      <!-- Acciones -->
      <div v-if="puedeAdministrar" class="flex gap-2 mb-4">
        <button
          @click="abrirRevista"
          :disabled="!aCargo.length"
          class="flex-1 bg-teal-600 text-white text-xs font-semibold rounded-xl px-3 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-40 flex items-center justify-center gap-1.5"
        >
          <ClipboardDocumentCheckIcon class="w-4 h-4" /> Hacer inventario
        </button>
        <button
          @click="abrirEntrega"
          class="flex-1 bg-gray-100 text-gray-700 text-xs font-semibold rounded-xl px-3 py-2.5 hover:bg-gray-200 transition-colors flex items-center justify-center gap-1.5"
        >
          <PlusIcon class="w-4 h-4" /> Entregar algo
        </button>
      </div>

      <!-- Lo que tiene a cargo -->
      <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Responde por</p>

      <div v-if="!aCargo.length" class="text-center py-8 px-6 bg-white rounded-xl shadow-sm mb-4">
        <p class="text-gray-500 text-sm">{{ esMiFicha ? 'No tienes nada a cargo.' : 'No tiene nada a cargo.' }}</p>
      </div>

      <div v-else class="space-y-2.5 mb-4">
        <div v-for="e in aCargo" :key="e.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800">
                <span v-if="e.cantidad > 1" class="text-teal-700">{{ e.cantidad }}×</span>
                {{ e.nombre }}
              </p>
              <p v-if="e.serial" class="text-[11px] text-gray-400 mt-0.5">Serial {{ e.serial }}</p>
              <p class="text-[11px] text-gray-400 mt-0.5">
                Desde {{ formatoFecha(e.fecha_entrega) }}
                <span v-if="e.entregado_por">· entregó {{ e.entregado_por }}</span>
              </p>
              <p v-if="e.cantidad_danada" class="text-[11px] text-amber-700 font-medium mt-1">
                {{ e.cantidad_danada }} de {{ e.cantidad }} {{ e.cantidad_danada === 1 ? 'está dañada' : 'están dañadas' }}
              </p>
              <p v-if="e.notas" class="text-xs text-gray-500 mt-1">{{ e.notas }}</p>
            </div>
            <div class="text-right shrink-0">
              <p v-if="e.valor_unitario" class="text-xs font-semibold text-gray-700">{{ formatoPesos(e.valor_total) }}</p>
              <p v-if="e.valor_unitario && e.cantidad > 1" class="text-[10px] text-gray-400">{{ formatoPesos(e.valor_unitario) }} c/u</p>
            </div>
          </div>
          <div v-if="puedeAdministrar" class="flex gap-2 mt-3">
            <button
              @click="abrirCierre(e)"
              class="flex-1 bg-gray-100 text-gray-700 text-[11px] font-semibold rounded-lg px-2 py-1.5 hover:bg-gray-200 transition-colors flex items-center justify-center gap-1"
            >
              <ArrowUturnLeftIcon class="w-3.5 h-3.5" /> Lo devolvió / se perdió
            </button>
            <button
              @click="borrar(e)"
              class="p-1.5 text-gray-300 hover:text-red-600 transition-colors"
              aria-label="Quitar"
            >
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Lo que ya no tiene -->
      <template v-if="cerrados.length">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Ya no lo tiene</p>
        <div class="space-y-2 mb-4">
          <div v-for="e in cerrados" :key="e.id" class="bg-white/60 rounded-xl p-3 flex items-center justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm text-gray-600 truncate">
                <span v-if="e.cantidad > 1">{{ e.cantidad }}× </span>{{ e.nombre }}
              </p>
              <p class="text-[11px] text-gray-400">{{ formatoFecha(e.cerrado_en) }}</p>
            </div>
            <span :class="['text-[10px] font-semibold rounded-full px-2 py-0.5 shrink-0', CIERRE[e.estado].clase]">
              {{ CIERRE[e.estado].label }}
            </span>
          </div>
        </div>
      </template>

      <!-- Historial de revistas -->
      <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Revisiones</p>
      <p v-if="!revisiones.length" class="text-sm text-gray-400 bg-white rounded-xl shadow-sm p-4">
        Todavía no se le ha hecho ninguna.
      </p>
      <div v-else class="space-y-2">
        <button
          v-for="r in revisiones" :key="r.id"
          @click="verRevision(r)"
          class="w-full bg-white rounded-xl shadow-sm p-3 text-left hover:bg-gray-50 transition-colors flex items-center justify-between gap-2"
        >
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-800">{{ formatoFecha(r.fecha) }}</p>
            <p class="text-[11px] text-gray-400">Revisó {{ r.revisado_por ?? '—' }}</p>
          </div>
          <div class="text-right shrink-0">
            <p v-if="r.descuento_total > 0" class="text-sm font-semibold text-red-600">−{{ formatoPesos(r.descuento_total) }}</p>
            <p v-else class="text-xs text-green-600 font-medium">Todo bien</p>
            <p v-if="r.descuento_total > 0" class="text-[10px]" :class="r.descontado ? 'text-gray-400' : 'text-amber-600'">
              {{ r.descontado ? 'descontado' : 'sin descontar' }}
            </p>
          </div>
        </button>
      </div>
    </template>

    <!-- ═══════════ Modal: hacer inventario ═══════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarRevista" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarRevista = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] flex flex-col shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 shrink-0">
              <div class="min-w-0">
                <p class="font-semibold text-gray-800">Inventario de {{ trabajador?.nombre }}</p>
                <p class="text-[11px] text-gray-400">Marca cómo está cada cosa.</p>
              </div>
              <button @click="mostrarRevista = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Fecha de la revisión</label>
                <input v-model="revista.fecha" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>

              <div v-for="item in revista.items" :key="item.encargo_id" class="border border-gray-100 rounded-xl p-3">
                <p class="text-sm font-medium text-gray-800">
                  <span v-if="item.total > 1" class="text-teal-700">{{ item.total }}×</span>
                  {{ item.nombre }}
                  <span v-if="item.serial" class="text-[11px] text-gray-400 font-normal">· {{ item.serial }}</span>
                </p>

                <!-- Una sola unidad: un botón, no tres casillas -->
                <div v-if="item.total === 1" class="flex gap-1.5 mt-2">
                  <button
                    v-for="op in [
                      { k: 'ok',      label: 'Bien',    on: 'bg-green-600 text-white', off: 'bg-green-50 text-green-700' },
                      { k: 'danada',  label: 'Dañada',  on: 'bg-amber-500 text-white', off: 'bg-amber-50 text-amber-700' },
                      { k: 'perdida', label: 'Perdida', on: 'bg-red-600 text-white',   off: 'bg-red-50 text-red-700' },
                    ]"
                    :key="op.k"
                    @click="marcarUnica(item, op.k)"
                    :class="['flex-1 text-xs font-semibold rounded-lg py-2 transition-colors',
                      estadoUnica(item) === op.k ? op.on : op.off]"
                  >{{ op.label }}</button>
                </div>

                <!-- Varias: se cuentan una por una -->
                <div v-else class="grid grid-cols-3 gap-2 mt-2">
                  <div>
                    <label class="block text-[10px] font-semibold text-green-700 mb-1">Bien</label>
                    <input v-model="item.cantidad_ok" type="number" min="0" @input="recalcularDescuento(item)"
                      class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-green-500" />
                  </div>
                  <div>
                    <label class="block text-[10px] font-semibold text-amber-700 mb-1">Dañadas</label>
                    <input v-model="item.cantidad_danada" type="number" min="0" @input="recalcularDescuento(item)"
                      class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-amber-500" />
                  </div>
                  <div>
                    <label class="block text-[10px] font-semibold text-red-700 mb-1">Perdidas</label>
                    <input v-model="item.cantidad_perdida" type="number" min="0" @input="recalcularDescuento(item)"
                      class="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-red-500" />
                  </div>
                </div>

                <p v-if="contadas(item) !== item.total" class="text-[11px] text-red-600 mt-1.5">
                  Contaste {{ contadas(item) }} y tiene {{ item.total }}: entre las tres tienen que dar {{ item.total }}.
                </p>

                <!-- Lo que se le cobraría por lo perdido -->
                <div v-if="item.cantidad_perdida > 0" class="mt-2">
                  <label class="block text-[10px] font-semibold text-gray-500 mb-1">Se le descuenta</label>
                  <InputPesos v-model="item.descuento" class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500" />
                  <p class="text-[10px] text-gray-400 mt-0.5">
                    Sugerido por lo que vale reponerlo. Ponlo en 0 si no se le cobra.
                  </p>
                </div>

                <input
                  v-if="item.cantidad_danada > 0 || item.cantidad_perdida > 0"
                  v-model="item.notas" placeholder="Qué pasó"
                  class="w-full mt-2 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas de la revisión</label>
                <textarea v-model="revista.notas" rows="2" placeholder="Lo que valga la pena dejar dicho..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>

              <div v-if="descuentoRevista > 0" class="bg-red-50 border border-red-200 rounded-xl p-3 space-y-2">
                <p class="text-sm font-semibold text-red-800">Se le descuenta {{ formatoPesos(descuentoRevista) }}</p>
                <label class="flex items-start gap-2 cursor-pointer">
                  <input type="checkbox" v-model="revista.descontar" class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500" />
                  <span class="text-xs text-red-800">
                    Mandarlo a Nómina como descuento. Si lo dejas sin marcar, la pérdida queda
                    registrada pero no se le cobra nada.
                  </span>
                </label>
              </div>

              <div v-else-if="hayPerdidas" class="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl p-3">
                <ExclamationTriangleIcon class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                <p class="text-xs text-amber-800">
                  Hay cosas perdidas sin valor de descuento. Se van a dar de baja de lo que tiene a
                  cargo, pero no se le cobra nada.
                </p>
              </div>
            </div>

            <div class="flex gap-2.5 p-5 pt-3 border-t border-gray-100 shrink-0">
              <button @click="mostrarRevista = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="enviarRevista" :disabled="guardandoRevista || !revistaCuadra"
                class="flex-1 bg-teal-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <span v-if="guardandoRevista" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                {{ guardandoRevista ? 'Guardando...' : 'Guardar revisión' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ═══════════ Modal: entregar algo ═══════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarEntrega" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarEntrega = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm">
              <p class="font-semibold text-gray-800 truncate">Entregarle a {{ trabajador?.nombre }}</p>
              <button @click="mostrarEntrega = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="p-5 space-y-4">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué se le entrega? *</label>
                <input v-model="formEntrega.nombre" placeholder="Taladro Bosch" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuántas? *</label>
                  <input v-model="formEntrega.cantidad" type="number" min="1" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Fecha *</label>
                  <input v-model="formEntrega.fecha_entrega" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                </div>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Serial o placa</label>
                <input v-model="formEntrega.serial" placeholder="Para distinguirlo si hay varios iguales" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuánto vale reponer una?</label>
                <InputPesos v-model="formEntrega.valor_unitario" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas</label>
                <textarea v-model="formEntrega.notas" rows="2" placeholder="Estado en que se entrega, accesorios..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
            </div>
            <div class="flex gap-2.5 p-5 pt-2">
              <button @click="mostrarEntrega = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="guardarEntrega" :disabled="guardandoEntrega"
                class="flex-1 bg-teal-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-50"
              >
                {{ guardandoEntrega ? 'Guardando...' : 'Entregar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ═══════════ Modal: cerrar un encargo ═══════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarCierre" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarCierre = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
              <p class="font-semibold text-gray-800 truncate">{{ encargoACerrar?.nombre }}</p>
              <button @click="mostrarCierre = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="p-5 space-y-4">
              <div class="flex gap-1.5">
                <button
                  v-for="op in [
                    { k: 'devuelto', label: 'Lo devolvió', on: 'bg-green-600 text-white', off: 'bg-green-50 text-green-700' },
                    { k: 'perdido',  label: 'Se perdió',   on: 'bg-red-600 text-white',   off: 'bg-red-50 text-red-700' },
                    { k: 'baja',     label: 'De baja',     on: 'bg-gray-600 text-white',  off: 'bg-gray-100 text-gray-600' },
                  ]"
                  :key="op.k"
                  @click="formCierre.estado = op.k"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-2 transition-colors', formCierre.estado === op.k ? op.on : op.off]"
                >{{ op.label }}</button>
              </div>
              <p class="text-[11px] text-gray-400">
                "De baja" es para lo que se acabó de tanto usarlo: deja de estar a su cargo y no se le cobra.
              </p>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuándo?</label>
                <input v-model="formCierre.fecha" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas</label>
                <textarea v-model="formCierre.notas" rows="2" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 resize-none focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
              </div>
              <p v-if="formCierre.estado === 'perdido'" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl p-3">
                Por acá no se le descuenta nada. Si hay que cobrárselo, hazlo en una revisión — así
                queda el conteo completo de ese día, o anótalo como ajuste en Nómina.
              </p>
            </div>
            <div class="flex gap-2.5 p-5 pt-0">
              <button @click="mostrarCierre = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="confirmarCierre" :disabled="guardandoCierre"
                class="flex-1 bg-teal-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-50"
              >
                {{ guardandoCierre ? 'Guardando...' : 'Confirmar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ═══════════ Modal: ver una revisión ═══════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="revisionVista" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="revisionVista = null">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm">
              <div>
                <p class="font-semibold text-gray-800">Revisión del {{ formatoFecha(revisionVista.fecha) }}</p>
                <p class="text-[11px] text-gray-400">Revisó {{ revisionVista.revisado_por ?? '—' }}</p>
              </div>
              <button @click="revisionVista = null" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="p-5 space-y-2">
              <div v-for="i in revisionVista.items" :key="i.id" class="flex items-start justify-between gap-2 border-b border-gray-50 pb-2">
                <div class="min-w-0">
                  <p class="text-sm text-gray-800">{{ i.nombre }}</p>
                  <p class="text-[11px] text-gray-500">
                    <span v-if="i.cantidad_ok" class="text-green-700">{{ i.cantidad_ok }} bien</span>
                    <span v-if="i.cantidad_danada" class="text-amber-700"> · {{ i.cantidad_danada }} dañada(s)</span>
                    <span v-if="i.cantidad_perdida" class="text-red-700"> · {{ i.cantidad_perdida }} perdida(s)</span>
                  </p>
                  <p v-if="i.notas" class="text-[11px] text-gray-400 mt-0.5">{{ i.notas }}</p>
                </div>
                <p v-if="i.descuento > 0" class="text-sm font-semibold text-red-600 shrink-0">−{{ formatoPesos(i.descuento) }}</p>
              </div>
              <p v-if="revisionVista.notas" class="text-xs text-gray-500 pt-2">{{ revisionVista.notas }}</p>
              <div v-if="revisionVista.descuento_total > 0" class="flex items-center justify-between pt-3">
                <p class="text-sm font-semibold text-gray-700">Total descontado</p>
                <div class="text-right">
                  <p class="text-sm font-bold text-red-600">−{{ formatoPesos(revisionVista.descuento_total) }}</p>
                  <p class="text-[10px]" :class="revisionVista.descontado ? 'text-gray-400' : 'text-amber-600'">
                    {{ revisionVista.descontado ? 'se le descontó en nómina' : 'no se le cobró' }}
                  </p>
                </div>
              </div>
              <p v-else class="text-sm text-green-700 font-medium pt-2 flex items-center gap-1.5">
                <CheckCircleIcon class="w-4 h-4" /> Ese día estaba todo en orden.
              </p>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ═══════════ Modal: su propio ritmo ═══════════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarRitmo" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarRitmo = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
              <p class="font-semibold text-gray-800">¿Cada cuánto se le revisa?</p>
              <button @click="mostrarRitmo = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="p-5 space-y-3">
              <div class="flex items-center gap-2">
                <span class="text-sm text-gray-600">Cada</span>
                <input v-model="diasPropios" type="number" min="1" max="730" placeholder="—" class="w-24 rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 text-center focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
                <span class="text-sm text-gray-600">días</span>
              </div>
              <p class="text-xs text-gray-500">
                Déjalo vacío para que siga el ritmo general de todos.
              </p>
            </div>
            <div class="flex gap-2.5 p-5 pt-0">
              <button @click="mostrarRitmo = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="guardarRitmo" :disabled="guardandoRitmo"
                class="flex-1 bg-teal-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-50"
              >
                {{ guardandoRitmo ? 'Guardando...' : 'Guardar' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
