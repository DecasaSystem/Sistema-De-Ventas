<script setup>
import { ref, computed, reactive, onMounted, watch } from 'vue'
import api from '@/api'
import { getBalance, getMovimientos, registrarMovimiento, eliminarMovimiento } from '@/api/caja'
import { useAuthStore } from '@/stores/auth'
import {
  BanknotesIcon,
  ArrowUpCircleIcon,
  ArrowDownCircleIcon,
  ShoppingCartIcon,
  PhotoIcon,
  XMarkIcon,
  TrashIcon,
  ChevronDownIcon,
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()

// ── Tienda ────────────────────────────────────────────────────────────────────
const tiendas       = ref([])
const tiendaSelecId = ref(auth.usuario?.tienda_default_id ?? null)

async function cargarTiendas() {
  if (!auth.isSupervisor) return
  const { data } = await api.get('/tiendas')
  tiendas.value = data.filter(t => !t.es_fabrica)
  if (!tiendaSelecId.value && tiendas.value.length) {
    tiendaSelecId.value = tiendas.value[0].id
  }
}

// ── Balance ───────────────────────────────────────────────────────────────────
const balance  = ref({ balance: 0, ingreso_ventas: 0, ingreso_manual: 0, egresos: 0 })
const cargando = ref(true)

async function cargarBalance() {
  const { data } = await getBalance(auth.isSupervisor ? tiendaSelecId.value : null)
  balance.value = data
}

// ── Movimientos ───────────────────────────────────────────────────────────────
const movimientos  = ref([])
const cargandoMovs = ref(false)
const mostrarTodos = ref(false)
const expandidos   = reactive({})

const movimientosMostrados = computed(() =>
  mostrarTodos.value ? movimientos.value : movimientos.value.slice(0, 20)
)

function toggleExpand(id) {
  expandidos[id] = !expandidos[id]
}

async function cargarMovimientos() {
  cargandoMovs.value = true
  try {
    const { data } = await getMovimientos(auth.isSupervisor ? tiendaSelecId.value : null)
    movimientos.value = data
  } finally {
    cargandoMovs.value = false
  }
}

async function cargarTodo() {
  cargando.value = true
  try {
    await Promise.all([cargarBalance(), cargarMovimientos()])
  } finally {
    cargando.value = false
  }
}

watch(tiendaSelecId, () => { if (auth.isSupervisor) cargarTodo() })

onMounted(async () => {
  await cargarTiendas()
  await cargarTodo()
})

// ── Egreso modal ──────────────────────────────────────────────────────────────
const abrirEgreso = ref(false)
const guardandoE  = ref(false)
const subiendo    = ref(false)
const egresoForm  = ref({ concepto: '', monto: '', descripcion: '', comprobante_url: '' })

function resetEgreso() {
  egresoForm.value = { concepto: '', monto: '', descripcion: '', comprobante_url: '' }
}

async function onFotoChange(e) {
  const file = e.target.files[0]
  if (!file) return
  subiendo.value = true
  try {
    const fd = new FormData()
    fd.append('foto', file)
    fd.append('folder', 'comprobantes')
    const { data } = await api.post('/upload/foto', fd)
    egresoForm.value.comprobante_url = data.url
  } finally {
    subiendo.value = false
    e.target.value = ''
  }
}

function quitarFoto() {
  egresoForm.value.comprobante_url = ''
}

const puedeGuardarEgreso = computed(() =>
  egresoForm.value.concepto.trim() &&
  Number(egresoForm.value.monto) > 0 &&
  !subiendo.value
)

async function guardarEgreso() {
  if (!puedeGuardarEgreso.value) return
  guardandoE.value = true
  try {
    await registrarMovimiento({
      tipo:            'egreso',
      concepto:        egresoForm.value.concepto.trim(),
      monto:           Number(egresoForm.value.monto),
      descripcion:     egresoForm.value.descripcion.trim() || null,
      comprobante_url: egresoForm.value.comprobante_url || null,
      ...(auth.isSupervisor && tiendaSelecId.value ? { tienda_id: tiendaSelecId.value } : {}),
    })
    abrirEgreso.value = false
    resetEgreso()
    await cargarTodo()
  } finally {
    guardandoE.value = false
  }
}

// ── Ingreso manual modal ──────────────────────────────────────────────────────
const abrirIngreso = ref(false)
const guardandoI   = ref(false)
const ingresoForm  = ref({ concepto: '', monto: '', descripcion: '' })

function resetIngreso() {
  ingresoForm.value = { concepto: '', monto: '', descripcion: '' }
}

const puedeGuardarIngreso = computed(() =>
  ingresoForm.value.concepto.trim() && Number(ingresoForm.value.monto) > 0
)

async function guardarIngreso() {
  if (!puedeGuardarIngreso.value) return
  guardandoI.value = true
  try {
    await registrarMovimiento({
      tipo:        'ingreso_manual',
      concepto:    ingresoForm.value.concepto.trim(),
      monto:       Number(ingresoForm.value.monto),
      descripcion: ingresoForm.value.descripcion.trim() || null,
      ...(auth.isSupervisor && tiendaSelecId.value ? { tienda_id: tiendaSelecId.value } : {}),
    })
    abrirIngreso.value = false
    resetIngreso()
    await cargarTodo()
  } finally {
    guardandoI.value = false
  }
}

// ── Eliminar movimiento ───────────────────────────────────────────────────────
const eliminando = ref(null)

async function eliminar(mov) {
  if (!mov.id.startsWith('mov_')) return
  if (!confirm(`¿Eliminar "${mov.concepto}"?`)) return
  eliminando.value = mov.id
  try {
    await eliminarMovimiento(mov.id.replace('mov_', ''))
    await cargarTodo()
  } finally {
    eliminando.value = null
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function formatMonto(n) {
  return Number(n).toLocaleString('es-CO')
}

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const diffMin = Math.floor((Date.now() - d) / 60000)
  if (diffMin < 1)  return 'Ahora'
  if (diffMin < 60) return `Hace ${diffMin} min`
  const h = Math.floor(diffMin / 60)
  if (h < 24) return `Hace ${h} h`
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
}

function tipoLabel(tipo) {
  return { ingreso_venta: 'Venta', ingreso_manual: 'Ingreso', egreso: 'Egreso' }[tipo] ?? tipo
}

function tipoBadgeClass(tipo) {
  return {
    ingreso_venta:  'bg-green-100 text-green-700',
    ingreso_manual: 'bg-blue-100 text-blue-700',
    egreso:         'bg-red-100 text-red-700',
  }[tipo] ?? 'bg-gray-100 text-gray-600'
}

function tipoIcono(tipo) {
  return {
    ingreso_venta:  ShoppingCartIcon,
    ingreso_manual: ArrowUpCircleIcon,
    egreso:         ArrowDownCircleIcon,
  }[tipo] ?? BanknotesIcon
}

const balancePositivo = computed(() => balance.value.balance >= 0)
</script>

<template>
  <div class="max-w-2xl mx-auto px-4 py-6 space-y-4">

    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <BanknotesIcon class="w-6 h-6 text-blue-600" />
        <h1 class="text-xl font-bold text-gray-900">Caja</h1>
      </div>

      <div v-if="auth.isSupervisor && tiendas.length > 1" class="relative">
        <select
          v-model="tiendaSelecId"
          class="appearance-none bg-white border border-gray-200 rounded-lg pl-3 pr-8 py-1.5 text-sm font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
        <ChevronDownIcon class="w-4 h-4 text-gray-400 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" />
      </div>
    </div>

    <!-- Skeleton -->
    <div v-if="cargando" class="space-y-3 animate-pulse">
      <div class="bg-white rounded-xl h-44 border border-gray-200" />
      <div class="bg-white rounded-xl h-14 border border-gray-200" />
      <div v-for="i in 5" :key="i" class="bg-white rounded-xl h-16 border border-gray-200" />
    </div>

    <template v-else>

      <!-- Tarjeta de balance -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Balance actual</p>
        <div :class="balancePositivo ? 'text-green-600' : 'text-red-600'" class="text-4xl font-bold mb-5">
          ${{ formatMonto(balance.balance) }}
        </div>
        <div class="grid grid-cols-3 gap-2 text-center">
          <div class="bg-green-50 rounded-xl p-3">
            <p class="text-[11px] text-gray-500 font-medium mb-1">Ventas</p>
            <p class="text-sm font-bold text-green-700">+${{ formatMonto(balance.ingreso_ventas) }}</p>
          </div>
          <div class="bg-blue-50 rounded-xl p-3">
            <p class="text-[11px] text-gray-500 font-medium mb-1">Ingresos</p>
            <p class="text-sm font-bold text-blue-700">+${{ formatMonto(balance.ingreso_manual) }}</p>
          </div>
          <div class="bg-red-50 rounded-xl p-3">
            <p class="text-[11px] text-gray-500 font-medium mb-1">Egresos</p>
            <p class="text-sm font-bold text-red-700">-${{ formatMonto(balance.egresos) }}</p>
          </div>
        </div>
      </div>

      <!-- Botones de acción -->
      <div class="grid grid-cols-2 gap-3">
        <button
          @click="abrirEgreso = true"
          class="bg-red-500 hover:bg-red-600 active:bg-red-700 text-white rounded-xl py-3 font-semibold text-sm flex items-center justify-center gap-2 transition-colors"
        >
          <ArrowDownCircleIcon class="w-5 h-5" />
          Registrar Egreso
        </button>
        <button
          @click="abrirIngreso = true"
          class="bg-green-500 hover:bg-green-600 active:bg-green-700 text-white rounded-xl py-3 font-semibold text-sm flex items-center justify-center gap-2 transition-colors"
        >
          <ArrowUpCircleIcon class="w-5 h-5" />
          Registrar Ingreso
        </button>
      </div>

      <!-- Lista de movimientos -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <h2 class="text-sm font-semibold text-gray-600">Movimientos recientes</h2>
          <span class="text-xs text-gray-400">{{ movimientos.length }} entradas</span>
        </div>

        <div v-if="cargandoMovs" class="text-center py-6 text-gray-400 text-sm">Cargando...</div>

        <div v-else-if="movimientos.length === 0" class="bg-white rounded-xl border border-gray-200 p-8 text-center">
          <BanknotesIcon class="w-10 h-10 text-gray-300 mx-auto mb-2" />
          <p class="text-sm text-gray-400">Sin movimientos aún</p>
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="mov in movimientosMostrados"
            :key="mov.id"
            class="bg-white rounded-xl border border-gray-200 overflow-hidden"
          >
            <!-- Fila principal — clic para expandir -->
            <button
              class="w-full flex items-center gap-3 p-4 text-left hover:bg-gray-50 transition-colors"
              @click="toggleExpand(mov.id)"
            >
              <!-- Ícono tipo -->
              <div
                :class="{
                  'bg-green-100': mov.tipo === 'ingreso_venta',
                  'bg-blue-100':  mov.tipo === 'ingreso_manual',
                  'bg-red-100':   mov.tipo === 'egreso',
                }"
                class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
              >
                <component
                  :is="tipoIcono(mov.tipo)"
                  :class="{
                    'text-green-600': mov.tipo === 'ingreso_venta',
                    'text-blue-600':  mov.tipo === 'ingreso_manual',
                    'text-red-600':   mov.tipo === 'egreso',
                  }"
                  class="w-4 h-4"
                />
              </div>

              <!-- Info -->
              <div class="flex-1 min-w-0 text-left">
                <div class="flex items-center gap-1.5 mb-0.5">
                  <span :class="tipoBadgeClass(mov.tipo)" class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full">
                    {{ tipoLabel(mov.tipo) }}
                  </span>
                  <span v-if="mov.metodo" class="text-[10px] text-gray-400">· {{ mov.metodo }}</span>
                  <!-- Dot si tiene comprobante -->
                  <PhotoIcon v-if="mov.comprobante_url" class="w-3 h-3 text-gray-300" title="Tiene comprobante" />
                </div>
                <p class="text-sm font-medium text-gray-900 truncate">{{ mov.concepto }}</p>
                <p class="text-xs text-gray-400">{{ mov.usuario }} · {{ formatFecha(mov.fecha) }}</p>
              </div>

              <!-- Monto + chevron -->
              <div class="flex items-center gap-2 flex-shrink-0">
                <span
                  :class="mov.tipo === 'egreso' ? 'text-red-600' : 'text-green-600'"
                  class="font-bold text-sm min-w-[80px] text-right"
                >
                  {{ mov.tipo === 'egreso' ? '-' : '+' }}${{ formatMonto(mov.monto) }}
                </span>
                <ChevronDownIcon
                  class="w-4 h-4 text-gray-400 transition-transform duration-200 flex-shrink-0"
                  :class="expandidos[mov.id] ? 'rotate-180' : ''"
                />
              </div>
            </button>

            <!-- Sección expandida -->
            <Transition name="expand">
              <div v-if="expandidos[mov.id]" class="border-t border-gray-100 px-4 pb-4 pt-3 space-y-3">

                <!-- Descripción -->
                <div>
                  <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Descripción</p>
                  <p class="text-sm text-gray-700">
                    {{ mov.descripcion || '—' }}
                  </p>
                </div>

                <!-- Comprobante -->
                <div v-if="mov.comprobante_url">
                  <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Comprobante</p>
                  <a :href="mov.comprobante_url" target="_blank" class="block">
                    <img
                      :src="mov.comprobante_url"
                      class="w-full rounded-xl object-contain max-h-64 border border-gray-200 bg-gray-50"
                      alt="Comprobante"
                    />
                    <p class="text-xs text-blue-500 text-center mt-1">Toca para ver en tamaño completo</p>
                  </a>
                </div>

                <!-- Eliminar (solo supervisor, solo movimientos manuales) -->
                <button
                  v-if="auth.isSupervisor && mov.id.startsWith('mov_')"
                  @click="eliminar(mov)"
                  :disabled="eliminando === mov.id"
                  class="flex items-center gap-1.5 text-xs text-red-500 hover:text-red-700 disabled:opacity-40 transition-colors"
                >
                  <TrashIcon class="w-3.5 h-3.5" />
                  {{ eliminando === mov.id ? 'Eliminando...' : 'Eliminar movimiento' }}
                </button>

              </div>
            </Transition>
          </div>

          <button
            v-if="movimientos.length > 20 && !mostrarTodos"
            @click="mostrarTodos = true"
            class="w-full py-2 text-xs text-blue-600 font-medium hover:underline"
          >
            Ver todos ({{ movimientos.length }})
          </button>
        </div>
      </div>

    </template>

    <!-- ── Modal Egreso ──────────────────────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="abrirEgreso"
          class="fixed inset-0 z-50 bg-black/40 flex items-end sm:items-center justify-center p-4"
          @click.self="abrirEgreso = false"
        >
          <div class="bg-white rounded-2xl w-full max-w-sm shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 sticky top-0 bg-white z-10">
              <h3 class="font-bold text-gray-900 flex items-center gap-2">
                <ArrowDownCircleIcon class="w-5 h-5 text-red-500" />
                Registrar Egreso
              </h3>
              <button @click="abrirEgreso = false; resetEgreso()" class="text-gray-400 hover:text-gray-600">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-3">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Concepto *</label>
                <input
                  v-model="egresoForm.concepto"
                  type="text"
                  placeholder="Ej: Arriendo, Servicios públicos..."
                  maxlength="255"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Monto *</label>
                <input
                  v-model="egresoForm.monto"
                  type="number"
                  step="100"
                  min="1"
                  placeholder="0"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Descripción</label>
                <textarea
                  v-model="egresoForm.descripcion"
                  rows="2"
                  placeholder="Detalles adicionales..."
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 resize-none"
                />
              </div>

              <!-- Foto comprobante -->
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Foto comprobante</label>

                <!-- Sin foto: botón de subir -->
                <label
                  v-if="!egresoForm.comprobante_url && !subiendo"
                  class="flex items-center gap-2 border border-dashed border-gray-300 rounded-lg px-3 py-3 cursor-pointer hover:border-red-400 hover:bg-red-50 transition-colors"
                >
                  <PhotoIcon class="w-5 h-5 text-gray-400" />
                  <span class="text-sm text-gray-500">Adjuntar comprobante</span>
                  <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoChange" />
                </label>

                <!-- Subiendo: spinner -->
                <div
                  v-else-if="subiendo"
                  class="flex items-center justify-center gap-2 border border-dashed border-blue-300 rounded-lg px-3 py-4 bg-blue-50"
                >
                  <div class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                  <span class="text-sm text-blue-600 font-medium">Subiendo foto...</span>
                </div>

                <!-- Preview con foto subida -->
                <div v-else-if="egresoForm.comprobante_url">
                  <div class="relative">
                    <img
                      :src="egresoForm.comprobante_url"
                      class="w-full rounded-xl object-contain max-h-52 border border-gray-200 bg-gray-50"
                      alt="Vista previa del comprobante"
                    />
                    <!-- Botón quitar -->
                    <button
                      @click="quitarFoto"
                      class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white rounded-full p-1 shadow-md transition-colors"
                      title="Quitar foto"
                    >
                      <XMarkIcon class="w-3.5 h-3.5" />
                    </button>
                  </div>
                  <!-- Cambiar foto -->
                  <label class="mt-2 flex items-center justify-center gap-1.5 text-xs text-blue-600 cursor-pointer hover:underline">
                    <PhotoIcon class="w-3.5 h-3.5" />
                    Cambiar foto
                    <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoChange" />
                  </label>
                </div>
              </div>
            </div>

            <div class="flex gap-2 px-5 pb-5 sticky bottom-0 bg-white pt-2 border-t border-gray-100">
              <button
                @click="abrirEgreso = false; resetEgreso()"
                class="flex-1 bg-gray-100 hover:bg-gray-200 rounded-xl py-2.5 text-sm font-semibold text-gray-700 transition-colors"
              >
                Cancelar
              </button>
              <button
                @click="guardarEgreso"
                :disabled="!puedeGuardarEgreso || guardandoE"
                class="flex-1 bg-red-500 hover:bg-red-600 text-white rounded-xl py-2.5 text-sm font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {{ guardandoE ? 'Guardando...' : 'Registrar Egreso' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Modal Ingreso Manual ──────────────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="abrirIngreso"
          class="fixed inset-0 z-50 bg-black/40 flex items-end sm:items-center justify-center p-4"
          @click.self="abrirIngreso = false"
        >
          <div class="bg-white rounded-2xl w-full max-w-sm shadow-xl">
            <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100">
              <h3 class="font-bold text-gray-900 flex items-center gap-2">
                <ArrowUpCircleIcon class="w-5 h-5 text-green-500" />
                Registrar Ingreso
              </h3>
              <button @click="abrirIngreso = false; resetIngreso()" class="text-gray-400 hover:text-gray-600">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-3">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Concepto *</label>
                <input
                  v-model="ingresoForm.concepto"
                  type="text"
                  placeholder="Ej: Efectivo del jefe, Saldo inicial..."
                  maxlength="255"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Monto *</label>
                <input
                  v-model="ingresoForm.monto"
                  type="number"
                  step="100"
                  min="1"
                  placeholder="0"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Descripción</label>
                <textarea
                  v-model="ingresoForm.descripcion"
                  rows="2"
                  placeholder="Detalles adicionales..."
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-400 resize-none"
                />
              </div>
            </div>

            <div class="flex gap-2 px-5 pb-5">
              <button
                @click="abrirIngreso = false; resetIngreso()"
                class="flex-1 bg-gray-100 hover:bg-gray-200 rounded-xl py-2.5 text-sm font-semibold text-gray-700 transition-colors"
              >
                Cancelar
              </button>
              <button
                @click="guardarIngreso"
                :disabled="!puedeGuardarIngreso || guardandoI"
                class="flex-1 bg-green-500 hover:bg-green-600 text-white rounded-xl py-2.5 text-sm font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {{ guardandoI ? 'Guardando...' : 'Registrar Ingreso' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.expand-enter-active,
.expand-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
