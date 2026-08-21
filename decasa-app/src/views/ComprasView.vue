<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { comprimirImagen } from '@/utils/comprimirImagen'
import { getCompras, crearCompra, marcarComprado, eliminarCompra } from '@/api/compras'
import {
  ShoppingCartIcon, PlusIcon, XMarkIcon, TrashIcon, CheckCircleIcon,
  CameraIcon, UserIcon, CalendarIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const auth   = useAuthStore()
const toast  = useToast()

const tab = ref('pendientes')

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}
function formatoFecha(fecha) {
  if (!fecha) return ''
  return new Date(fecha + 'T00:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
}
function formatoFechaHora(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('es-CO', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// ── Pendientes ────────────────────────────────────────────────────────────
const pendientes       = ref([])
const cargandoPend     = ref(true)

async function cargarPendientes() {
  cargandoPend.value = true
  try {
    const { data } = await getCompras('pendiente')
    pendientes.value = data
  } catch {
    toast.error('No se pudo cargar la lista de pendientes')
  } finally {
    cargandoPend.value = false
  }
}
onMounted(cargarPendientes)

// ── Historial ─────────────────────────────────────────────────────────────
const historial         = ref([])
const cargandoHistorial = ref(false)
let historialCargado    = false

async function cargarHistorial() {
  cargandoHistorial.value = true
  try {
    const { data } = await getCompras('comprado')
    historial.value = data
  } catch {
    toast.error('No se pudo cargar el historial')
  } finally {
    cargandoHistorial.value = false
  }
}

watch(tab, (t) => {
  if (t === 'historial' && !historialCargado) {
    historialCargado = true
    cargarHistorial()
  }
})

const totalGastadoHistorial = computed(() =>
  historial.value.reduce((s, c) => s + (Number(c.precio) || 0), 0)
)

// ── Pedir algo nuevo ──────────────────────────────────────────────────────
const mostrarFormPedido = ref(false)
const formPedido = ref({ item: '', cantidad: '', notas: '' })
const guardandoPedido = ref(false)

function abrirNuevoPedido() {
  formPedido.value = { item: '', cantidad: '', notas: '' }
  mostrarFormPedido.value = true
}

async function guardarPedido() {
  if (!formPedido.value.item.trim()) {
    toast.error('Escribe qué hace falta comprar')
    return
  }
  guardandoPedido.value = true
  try {
    await crearCompra({
      item: formPedido.value.item.trim(),
      cantidad: formPedido.value.cantidad.trim() || null,
      notas: formPedido.value.notas.trim() || null,
    })
    toast.success('Pedido agregado a la lista')
    mostrarFormPedido.value = false
    await cargarPendientes()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo agregar')
  } finally {
    guardandoPedido.value = false
  }
}

async function borrarPendiente(c) {
  if (!confirm(`¿Quitar "${c.item}" de la lista? Ya no se va a comprar.`)) return
  try {
    await eliminarCompra(c.id)
    toast.success('Quitado de la lista')
    await cargarPendientes()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo quitar')
  }
}

// ── Marcar como comprado ─────────────────────────────────────────────────
const mostrarFormComprado = ref(false)
const compraActual        = ref(null)
const formComprado = ref({ comprador_nombre: '', precio: 0, fecha_compra: '', factura_foto_url: '' })
const guardandoComprado = ref(false)
const subiendoFactura    = ref(false)

function abrirMarcarComprado(c) {
  compraActual.value = c
  formComprado.value = {
    comprador_nombre: auth.usuario?.nombre ?? '',
    precio: 0,
    fecha_compra: hoyISO(),
    factura_foto_url: '',
  }
  mostrarFormComprado.value = true
}

async function onFotoFactura(e) {
  const file = (e.target.files || [])[0]
  if (!file) return
  subiendoFactura.value = true
  try {
    const token = localStorage.getItem('token')
    const fd = new FormData()
    fd.append('foto', await comprimirImagen(file), 'factura.jpg')
    fd.append('folder', 'compras')
    const res = await fetch('/api/upload/foto', {
      method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: fd,
    })
    const data = await res.json()
    if (data.url) {
      formComprado.value.factura_foto_url = data.url
    } else {
      toast.error('No se pudo subir la foto')
    }
  } catch {
    toast.error('No se pudo subir la foto')
  } finally {
    subiendoFactura.value = false
    e.target.value = ''
  }
}

async function guardarComprado() {
  if (!formComprado.value.comprador_nombre.trim()) {
    toast.error('Falta quién lo compró')
    return
  }
  if (!formComprado.value.precio || Number(formComprado.value.precio) <= 0) {
    toast.error('Falta cuánto costó')
    return
  }
  if (!formComprado.value.fecha_compra) {
    toast.error('Falta la fecha de compra')
    return
  }
  if (!formComprado.value.factura_foto_url) {
    toast.error('Falta la foto de la factura')
    return
  }
  guardandoComprado.value = true
  try {
    await marcarComprado(compraActual.value.id, {
      comprador_nombre: formComprado.value.comprador_nombre.trim(),
      precio: formComprado.value.precio,
      fecha_compra: formComprado.value.fecha_compra,
      factura_foto_url: formComprado.value.factura_foto_url,
    })
    toast.success('Marcado como comprado')
    mostrarFormComprado.value = false
    await cargarPendientes()
    if (historialCargado) await cargarHistorial()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoComprado.value = false
  }
}
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center gap-3 mb-4">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h1 class="text-lg font-bold text-gray-800 flex items-center gap-2 flex-1">
        <ShoppingCartIcon class="w-5 h-5 text-blue-600" />
        Compras
      </h1>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-4 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'pendientes'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'pendientes' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Por comprar
        <span v-if="pendientes.length" class="ml-1 text-[10px] bg-amber-500 text-white rounded-full px-1.5 py-0.5">{{ pendientes.length }}</span>
      </button>
      <button
        @click="tab = 'historial'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'historial' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Historial
      </button>
    </div>

    <!-- ═══════════ PENDIENTES ═══════════ -->
    <template v-if="tab === 'pendientes'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">Lo que hace falta comprar.</p>
        <button
          @click="abrirNuevoPedido"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Necesito comprar algo
        </button>
      </div>

      <div v-if="cargandoPend" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else-if="!pendientes.length" class="text-center py-12 px-6">
        <CheckCircleIcon class="w-10 h-10 text-green-400 mx-auto mb-3" />
        <p class="text-gray-500 text-sm font-medium">No hay nada pendiente por comprar.</p>
      </div>

      <div v-else class="space-y-2.5">
        <div v-for="c in pendientes" :key="c.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">
                {{ c.item }}
                <span v-if="c.cantidad" class="text-gray-400 font-normal">· {{ c.cantidad }}</span>
              </p>
              <p v-if="c.notas" class="text-xs text-gray-500 mt-0.5">{{ c.notas }}</p>
              <p class="text-[11px] text-gray-400 mt-1 flex items-center gap-1">
                <UserIcon class="w-3 h-3" /> Pidió {{ c.solicitado_por }} · {{ formatoFechaHora(c.solicitado_en) }}
              </p>
            </div>
            <button
              v-if="auth.isSupervisor"
              @click="borrarPendiente(c)"
              class="p-1.5 text-gray-300 hover:text-red-600 transition-colors shrink-0"
              aria-label="Quitar"
            >
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
          <button
            @click="abrirMarcarComprado(c)"
            class="w-full mt-3 bg-green-600 text-white text-xs font-semibold rounded-lg px-3 py-2 hover:bg-green-700 transition-colors flex items-center justify-center gap-1.5"
          >
            <CheckCircleIcon class="w-4 h-4" /> Ya lo compré
          </button>
        </div>
      </div>

      <!-- Nuevo pedido -->
      <Teleport to="body">
        <Transition
          enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
          <div v-if="mostrarFormPedido" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormPedido = false">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md shadow-2xl">
              <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                    <ShoppingCartIcon class="w-5 h-5 text-blue-600" />
                  </div>
                  <p class="font-semibold text-gray-800">Necesito comprar algo</p>
                </div>
                <button @click="mostrarFormPedido = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>
              <div class="p-5 space-y-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Qué hace falta? *</label>
                  <input v-model="formPedido.item" placeholder="Taladros" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Cantidad</label>
                  <input v-model="formPedido.cantidad" placeholder="4" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Notas</label>
                  <textarea v-model="formPedido.notas" rows="2" placeholder="Para qué es, alguna preferencia..." class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 resize-none focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
              </div>
              <div class="flex gap-2.5 p-5 pt-2">
                <button @click="mostrarFormPedido = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
                <button
                  @click="guardarPedido" :disabled="guardandoPedido"
                  class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
                >
                  <span v-if="guardandoPedido" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                  {{ guardandoPedido ? 'Guardando...' : 'Agregar' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>

    <!-- ═══════════ HISTORIAL ═══════════ -->
    <template v-else>
      <div class="bg-white rounded-xl shadow-sm p-4 mb-3">
        <p class="text-xs text-gray-400">Total comprado</p>
        <p class="text-2xl font-bold text-gray-800">{{ formatoPesos(totalGastadoHistorial) }}</p>
      </div>

      <div v-if="cargandoHistorial" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <p v-else-if="!historial.length" class="text-center py-12 text-gray-400 text-sm">Todavía no hay compras registradas.</p>

      <div v-else class="space-y-2.5">
        <div v-for="c in historial" :key="c.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">
                {{ c.item }}
                <span v-if="c.cantidad" class="text-gray-400 font-normal">· {{ c.cantidad }}</span>
              </p>
              <p class="text-xs text-gray-600 mt-0.5 flex items-center gap-1">
                <UserIcon class="w-3 h-3" /> Compró {{ c.comprador_nombre }}
              </p>
              <p class="text-[11px] text-gray-400 mt-0.5 flex items-center gap-1">
                <CalendarIcon class="w-3 h-3" /> {{ formatoFecha(c.fecha_compra) }}
                <span v-if="c.solicitado_por" class="text-gray-300">· pidió {{ c.solicitado_por }}</span>
              </p>
            </div>
            <div class="text-right shrink-0">
              <p class="font-bold text-sm text-gray-800">{{ formatoPesos(c.precio) }}</p>
              <a v-if="c.factura_foto_url" :href="c.factura_foto_url" target="_blank" class="text-[11px] text-blue-600 hover:text-blue-700 flex items-center gap-0.5 justify-end mt-1">
                <CameraIcon class="w-3 h-3" /> Factura
              </a>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Marcar como comprado -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
      >
        <div v-if="mostrarFormComprado" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormComprado = false">
          <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm rounded-t-3xl sm:rounded-t-2xl">
              <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-9 h-9 rounded-xl bg-green-50 flex items-center justify-center shrink-0">
                  <CheckCircleIcon class="w-5 h-5 text-green-600" />
                </div>
                <p class="font-semibold text-gray-800 truncate">{{ compraActual?.item }}</p>
              </div>
              <button @click="mostrarFormComprado = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>

            <div class="p-5 space-y-4">
              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Quién lo compró? *</label>
                <input v-model="formComprado.comprador_nombre" placeholder="Nombre de quien hizo la compra" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuánto costó? *</label>
                  <InputPesos v-model="formComprado.precio" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuándo? *</label>
                  <input v-model="formComprado.fecha_compra" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
              </div>

              <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1.5">Foto de la factura *</label>
                <label
                  class="flex items-center justify-center gap-2 w-full rounded-xl border-2 border-dashed border-gray-200 px-3.5 py-4 text-sm text-gray-500 cursor-pointer hover:border-blue-300 hover:bg-blue-50/30 transition-colors"
                  :class="formComprado.factura_foto_url ? 'border-green-300 bg-green-50/40' : ''"
                >
                  <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotoFactura" />
                  <span v-if="subiendoFactura" class="flex items-center gap-2">
                    <span class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" /> Subiendo...
                  </span>
                  <span v-else-if="formComprado.factura_foto_url" class="flex items-center gap-2 text-green-700 font-medium">
                    <CheckCircleIcon class="w-4 h-4" /> Foto lista — toca para cambiarla
                  </span>
                  <span v-else class="flex items-center gap-2">
                    <CameraIcon class="w-4 h-4" /> Tomar o elegir foto
                  </span>
                </label>
                <img v-if="formComprado.factura_foto_url" :src="formComprado.factura_foto_url" class="mt-2 w-full max-h-40 object-contain rounded-lg border border-gray-100" />
              </div>
            </div>

            <div class="flex gap-2.5 p-5 pt-2">
              <button @click="mostrarFormComprado = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
              <button
                @click="guardarComprado" :disabled="guardandoComprado || subiendoFactura"
                class="flex-1 bg-green-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-green-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
              >
                <span v-if="guardandoComprado" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                {{ guardandoComprado ? 'Guardando...' : 'Confirmar compra' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
