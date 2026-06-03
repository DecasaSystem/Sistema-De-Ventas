<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { getConsulta, guardarItem, enviarConsulta, getMensajes, enviarMensaje } from '@/api/consultas'
import { getMateriales } from '@/api/materiales'
import {
  SparklesIcon, PlusIcon, TrashIcon, CheckCircleIcon,
  ArrowDownTrayIcon, PaperAirplaneIcon,
  MagnifyingGlassIcon, ChatBubbleLeftEllipsisIcon,
} from '@heroicons/vue/24/outline'

const route     = useRoute()
const router    = useRouter()
const toast     = useToast()
const authStore = useAuthStore()

// ── Chat ─────────────────────────────────────────────────────────────────────
const mensajes        = ref([])
const nuevoMensaje    = ref('')
const enviandoMensaje = ref(false)

async function cargarMensajes() {
  try {
    const { data } = await getMensajes(route.params.id)
    mensajes.value = Array.isArray(data) ? data : []
  } catch { mensajes.value = [] }
}

async function doEnviarMensaje() {
  const texto = nuevoMensaje.value.trim()
  if (!texto || enviandoMensaje.value) return
  enviandoMensaje.value = true
  try {
    const { data } = await enviarMensaje(route.params.id, texto)
    mensajes.value.push(data)
    nuevoMensaje.value = ''
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al enviar el mensaje.')
  } finally {
    enviandoMensaje.value = false
  }
}

function onMensajeKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doEnviarMensaje() }
}

const consulta    = ref(null)
const loading     = ref(true)
const error       = ref('')
const guardando   = ref({})
const enviando    = ref(false)

// Por cada consulta_item, mantenemos un formulario local
const formularios = ref({})

const esReceptor = computed(() =>
  consulta.value && consulta.value.asignado_a_id === authStore.usuario?.id
)

const todosCalculados = computed(() => {
  if (!consulta.value) return false
  return consulta.value.items.every(i => i.estado === 'calculado')
})

const TIPO_LABEL = {
  material:   'Material',
  carpintero: 'Carpintero',
  tapicero:   'Tapicero',
  laquero:    'Laquero',
}
const TIPO_COLOR = {
  material:   'bg-orange-50 text-orange-700',
  carpintero: 'bg-blue-50 text-blue-700',
  tapicero:   'bg-teal-50 text-teal-700',
  laquero:    'bg-indigo-50 text-indigo-700',
}

// ── Catálogo de materiales ────────────────────────────────────────────────────
const materialesCatalogo = ref([])

function materialesFiltrados(busqueda) {
  if (!busqueda?.trim()) return materialesCatalogo.value.slice(0, 10)
  const term = busqueda.toLowerCase()
  return materialesCatalogo.value
    .filter(m => m.nombre.toLowerCase().includes(term))
    .slice(0, 10)
}

function seleccionarMaterial(fila, material) {
  fila.nombre          = material.nombre
  fila.precio_unitario = parseFloat(material.precio_unitario)
  fila._busqueda       = material.nombre
  fila._abierto        = false
}

function abrirCatalogo(fila) {
  fila._abierto = true
}

function cerrarCatalogo(fila) {
  // Pequeño delay para permitir que el click en una opción se registre primero
  setTimeout(() => { fila._abierto = false }, 150)
}

// ── Formularios por ítem ─────────────────────────────────────────────────────

function crearFila(tipo = 'material') {
  return {
    tipo,
    nombre:          '',
    cantidad:        1,
    precio_unitario: 0,
    // Solo para tipo material
    _busqueda: '',
    _abierto:  false,
  }
}

function inicializarFormulario(item) {
  const desglose = (item.desglose ?? []).map(d => ({
    tipo:            d.tipo,
    nombre:          d.nombre,
    cantidad:        parseFloat(d.cantidad),
    precio_unitario: parseFloat(d.precio_unitario),
    _busqueda:       d.tipo === 'material' ? d.nombre : '',
    _abierto:        false,
  }))

  // Si no tiene desglose pero tiene precio, se asume modo manual
  const modoInicial = (!desglose.length && item.precio_final) ? 'manual' : 'calcular'

  formularios.value[item.id] = {
    modo:                modoInicial,
    precio_manual:       modoInicial === 'manual' ? parseFloat(item.precio_final ?? 0) : 0,
    desglose:            desglose.length ? desglose : [],
    margen_ganancia_pct: item.margen_ganancia_pct ?? 0,
  }
}

function agregarFila(itemId, tipo = 'material') {
  formularios.value[itemId].desglose.push(crearFila(tipo))
}

function quitarFila(itemId, idx) {
  formularios.value[itemId].desglose.splice(idx, 1)
}

function subtotalFila(fila) {
  return Math.round(fila.cantidad * fila.precio_unitario * 100) / 100
}

function precioBase(itemId) {
  const form = formularios.value[itemId]
  if (!form) return 0
  return form.desglose.reduce((sum, f) => sum + subtotalFila(f), 0)
}

function precioFinal(itemId) {
  const base   = precioBase(itemId)
  const margen = formularios.value[itemId]?.margen_ganancia_pct ?? 0
  return Math.round(base * (1 + margen / 100) * 100) / 100
}

function formatMoney(val) {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(val ?? 0)
}

function formatFecha(str) {
  if (!str) return '—'
  const d = new Date(str)
  const hoy = new Date()
  const mismodia = d.toDateString() === hoy.toDateString()
  if (mismodia) return d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

async function cargar() {
  loading.value = true
  error.value   = ''
  try {
    const { data } = await getConsulta(route.params.id)
    consulta.value = data
    for (const item of data.items ?? []) {
      inicializarFormulario(item)
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar la consulta.'
  } finally {
    loading.value = false
  }
}

async function guardar(item) {
  const form = formularios.value[item.id]

  if (form.modo === 'manual') {
    if (!form.precio_manual || form.precio_manual <= 0) {
      toast.error('Ingresa un precio manual mayor a 0.')
      return
    }
  } else {
    if (!form || form.desglose.length === 0) {
      toast.error('Agrega al menos una fila al desglose.')
      return
    }
    for (const fila of form.desglose) {
      if (fila.tipo === 'material' && !fila.nombre) {
        toast.error('Selecciona un material del catálogo en todas las filas de material.')
        return
      }
      if (fila.tipo !== 'material' && !fila.nombre.trim()) {
        toast.error('Completa el nombre del trabajador en todas las filas.')
        return
      }
    }
  }

  guardando.value[item.id] = true
  try {
    const payload = form.modo === 'manual'
      ? { precio_manual: form.precio_manual }
      : { margen_ganancia_pct: form.margen_ganancia_pct, desglose: form.desglose }

    const { data } = await guardarItem(consulta.value.id, item.id, payload)
    // Actualizar el item en la consulta local
    const idx = consulta.value.items.findIndex(i => i.id === item.id)
    if (idx >= 0) {
      consulta.value.items[idx] = { ...consulta.value.items[idx], ...data }
    }
    toast.success('Cálculo guardado.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al guardar.')
  } finally {
    guardando.value[item.id] = false
  }
}

async function enviar() {
  enviando.value = true
  try {
    await enviarConsulta(consulta.value.id)
    toast.success('Precios enviados al vendedor.')
    router.back()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al enviar.')
  } finally {
    enviando.value = false
  }
}

function descargarBoceto(url) {
  const a = document.createElement('a')
  a.href = url
  a.target = '_blank'
  a.click()
}

onMounted(() => {
  cargar()
  cargarMensajes()
  getMateriales('').then(r => { materialesCatalogo.value = r.data ?? [] }).catch(() => {})
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        Cotización — Orden #{{ consulta?.orden_id ?? '...' }}
      </h2>
      <span
        v-if="consulta"
        :class="[
          'text-xs font-semibold px-2.5 py-1 rounded-full',
          consulta.estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'
        ]"
      >
        {{ consulta.estado === 'pendiente' ? 'Pendiente' : 'Respondida' }}
      </span>
    </div>

    <AppSpinner v-if="loading" />

    <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">{{ error }}</div>

    <template v-else-if="consulta">      <!-- Info de la orden -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-2 text-sm">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Información de la orden</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
          <div>
            <p class="text-gray-400">Cliente</p>
            <p class="font-medium text-gray-700">{{ consulta.orden?.cliente?.nombre ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-400">Teléfono</p>
            <p class="font-medium text-gray-700">{{ consulta.orden?.cliente?.telefono ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-400">Vendedor</p>
            <p class="font-medium text-gray-700">{{ consulta.orden?.vendedor?.nombre ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-400">Tienda</p>
            <p class="font-medium text-gray-700">{{ consulta.orden?.tienda?.nombre ?? '—' }}</p>
          </div>
          <div v-if="consulta.notas_adicionales" class="col-span-2 mt-1">
            <p class="text-gray-400">Notas adicionales del vendedor</p>
            <p class="font-medium text-gray-700 whitespace-pre-wrap">{{ consulta.notas_adicionales }}</p>
          </div>
        </div>
      </div>

      <!-- Un card por cada ítem personalizado -->
      <div
        v-for="item in consulta.items"
        :key="item.id"
        class="bg-white rounded-xl shadow-sm p-4 space-y-4"
      >
        <!-- Header del ítem -->
        <div class="flex items-start gap-3">
          <img
            v-if="item.orden_item?.producto?.foto_url"
            :src="item.orden_item.producto.foto_url"
            class="w-16 h-16 rounded-xl object-cover border border-gray-100 flex-shrink-0"
          />
          <div v-else class="w-16 h-16 rounded-xl bg-violet-50 flex-shrink-0 flex items-center justify-center">
            <SparklesIcon class="w-7 h-7 text-violet-300" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="font-semibold text-sm text-gray-800">
                {{ item.orden_item?.nombre_custom ?? item.orden_item?.producto?.nombre ?? 'Ítem personalizado' }}
              </p>
              <span
                :class="[
                  'inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-full',
                  item.estado === 'calculado' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
                ]"
              >
                {{ item.estado === 'calculado' ? '✓ Calculado' : 'Pendiente' }}
              </span>
            </div>
            <p class="text-xs text-gray-400">{{ item.orden_item?.categoria_custom ?? item.orden_item?.producto?.categoria ?? 'Personalizado' }}</p>
          </div>
        </div>

        <!-- Specs del ítem -->
        <div
          v-if="item.orden_item?.specs_personalizacion && Object.keys(item.orden_item.specs_personalizacion).length"
          class="bg-violet-50 rounded-lg px-3 py-2 text-xs text-gray-700 space-y-0.5"
        >
          <p v-for="(val, key) in item.orden_item.specs_personalizacion" :key="key">
            <span class="text-gray-400 capitalize">{{ key }}:</span> {{ val }}
          </p>
        </div>

        <!-- Boceto -->
        <div v-if="item.orden_item?.boceto_url">
          <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-gray-400 font-semibold uppercase">Boceto</p>
            <button
              @click="descargarBoceto(item.orden_item.boceto_url)"
              class="flex items-center gap-1 text-xs text-blue-600"
            >
              <ArrowDownTrayIcon class="w-3.5 h-3.5" />
              Ver / Descargar
            </button>
          </div>
          <img
            :src="item.orden_item.boceto_url"
            class="w-full max-h-48 object-contain rounded-lg border border-violet-200 bg-white"
          />
        </div>

        <!-- Formulario de cálculo — solo para el receptor y mientras está pendiente -->
        <template v-if="esReceptor && consulta.estado === 'pendiente'">
          <div class="border-t border-gray-100 pt-3 space-y-3">

            <!-- Toggle modo -->
            <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
              <button
                @click="formularios[item.id].modo = 'calcular'"
                :class="['flex-1 py-1.5 text-xs font-semibold rounded-lg transition-colors',
                  formularios[item.id]?.modo === 'calcular' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
              >Calcular desglose</button>
              <button
                @click="() => {
                  formularios[item.id].modo = 'manual'
                  if (!formularios[item.id].precio_manual && item.orden_item?.producto?.precio_base) {
                    formularios[item.id].precio_manual = parseFloat(item.orden_item.producto.precio_base)
                  }
                }"
                :class="['flex-1 py-1.5 text-xs font-semibold rounded-lg transition-colors',
                  formularios[item.id]?.modo === 'manual' ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
              >Precio manual</button>
            </div>

            <!-- Modo manual -->
            <div v-if="formularios[item.id]?.modo === 'manual'" class="space-y-2">
              <!-- Precio base del catálogo como referencia -->
              <div
                v-if="item.orden_item?.producto?.precio_base"
                class="flex items-center justify-between bg-blue-50 border border-blue-100 rounded-lg px-3 py-2"
              >
                <div>
                  <p class="text-xs text-blue-600 font-medium">Precio base en catálogo</p>
                  <p class="text-sm font-bold text-blue-800">{{ formatMoney(item.orden_item.producto.precio_base) }}</p>
                </div>
                <button
                  @click="formularios[item.id].precio_manual = parseFloat(item.orden_item.producto.precio_base)"
                  class="text-xs bg-blue-600 text-white px-2.5 py-1.5 rounded-lg hover:bg-blue-700 transition-colors font-medium"
                >Usar este</button>
              </div>

              <label class="block text-xs font-semibold text-gray-600 uppercase">Precio final</label>
              <input
                v-model.number="formularios[item.id].precio_manual"
                type="number"
                min="0"
                step="1000"
                placeholder="Ej: 850000"
                class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400"
              />
              <p class="text-xs text-gray-400">El precio se envía tal cual, sin desglose de materiales.</p>
            </div>

            <!-- Modo desglose -->
            <template v-else>
            <p class="text-xs font-semibold text-gray-600 uppercase">Desglose de costos</p>

            <!-- Tabla de filas -->
            <div v-if="formularios[item.id]?.desglose.length" class="space-y-2">
              <div
                v-for="(fila, idx) in formularios[item.id].desglose"
                :key="idx"
                class="bg-gray-50 rounded-xl p-3 space-y-2"
              >
                <div class="flex items-center gap-2">
                  <select
                    v-model="fila.tipo"
                    class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400"
                  >
                    <option value="material">Material</option>
                    <option value="carpintero">Carpintero</option>
                    <option value="tapicero">Tapicero</option>
                    <option value="laquero">Laquero</option>
                  </select>
                  <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', TIPO_COLOR[fila.tipo]]">
                    {{ TIPO_LABEL[fila.tipo] }}
                  </span>
                  <button @click="quitarFila(item.id, idx)" class="ml-auto text-red-400 hover:text-red-600 transition-colors">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
                <div class="grid grid-cols-3 gap-2">
                  <!-- Material: búsqueda del catálogo -->
                  <div v-if="fila.tipo === 'material'" class="col-span-3 relative">
                    <div class="relative">
                      <MagnifyingGlassIcon class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" />
                      <input
                        v-model="fila._busqueda"
                        @input="fila.nombre = ''; fila._abierto = true"
                        @focus="abrirCatalogo(fila)"
                        @blur="cerrarCatalogo(fila)"
                        type="text"
                        placeholder="Buscar material del catálogo..."
                        class="w-full text-sm border border-gray-200 rounded-lg pl-8 pr-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-violet-400"
                      />
                    </div>
                    <!-- Dropdown de resultados -->
                    <div
                      v-if="fila._abierto && materialesFiltrados(fila._busqueda).length"
                      class="absolute z-30 top-full left-0 right-0 bg-white border border-gray-200 rounded-xl shadow-lg mt-0.5 max-h-44 overflow-y-auto"
                    >
                      <button
                        v-for="m in materialesFiltrados(fila._busqueda)"
                        :key="m.id"
                        @mousedown.prevent="seleccionarMaterial(fila, m)"
                        class="w-full text-left px-3 py-2 text-xs hover:bg-violet-50 transition-colors flex items-center justify-between border-b border-gray-50 last:border-0"
                      >
                        <div>
                          <p class="font-medium text-gray-800">{{ m.nombre }}</p>
                          <p v-if="m.unidad" class="text-gray-400">{{ m.unidad }}</p>
                        </div>
                        <span class="text-violet-700 font-semibold ml-2 flex-shrink-0">{{ formatMoney(m.precio_unitario) }}</span>
                      </button>
                    </div>
                    <!-- Chip del material seleccionado -->
                    <div v-if="fila.nombre" class="flex items-center gap-1 mt-1">
                      <span class="text-xs text-violet-700 font-medium">✓ {{ fila.nombre }}</span>
                      <button
                        @click.prevent="fila.nombre = ''; fila._busqueda = ''"
                        class="text-gray-400 hover:text-red-500 text-xs leading-none"
                      >×</button>
                    </div>
                    <p v-else-if="fila._busqueda && !fila._abierto" class="text-xs text-amber-600 mt-0.5">Selecciona un material del catálogo</p>
                  </div>

                  <!-- Trabajador: texto libre -->
                  <div v-else class="col-span-3">
                    <input
                      v-model="fila.nombre"
                      type="text"
                      placeholder="Nombre del trabajador"
                      class="w-full text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-violet-400"
                    />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-400 mb-0.5">Cant.</label>
                    <input
                      v-model.number="fila.cantidad"
                      type="number"
                      min="0.001"
                      step="0.001"
                      class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-violet-400"
                    />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-400 mb-0.5">Precio unit.</label>
                    <input
                      v-model.number="fila.precio_unitario"
                      type="number"
                      min="0"
                      step="1000"
                      class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-violet-400"
                    />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-400 mb-0.5">Subtotal</label>
                    <p class="text-sm font-semibold text-gray-700 px-2 py-1.5">
                      {{ formatMoney(subtotalFila(fila)) }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Botones para agregar fila -->
            <div class="flex flex-wrap gap-2">
              <button
                v-for="tipo in ['material', 'carpintero', 'tapicero', 'laquero']"
                :key="tipo"
                @click="agregarFila(item.id, tipo)"
                :class="['inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg border transition-colors font-medium', TIPO_COLOR[tipo], 'border-current opacity-80 hover:opacity-100']"
              >
                <PlusIcon class="w-3.5 h-3.5" />
                {{ TIPO_LABEL[tipo] }}
              </button>
            </div>

            <!-- Resumen de costos -->
            <div class="bg-gray-50 rounded-xl p-3 space-y-2">
              <div class="flex justify-between text-sm">
                <span class="text-gray-500">Costo base</span>
                <span class="font-semibold text-gray-700">{{ formatMoney(precioBase(item.id)) }}</span>
              </div>
              <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500">Ganancia</span>
                <div class="flex items-center gap-1 flex-1">
                  <input
                    v-model.number="formularios[item.id].margen_ganancia_pct"
                    type="number"
                    min="0"
                    max="500"
                    class="w-20 text-sm border border-gray-200 rounded-lg px-2 py-1 focus:outline-none focus:ring-2 focus:ring-violet-400 text-center"
                  />
                  <span class="text-sm text-gray-400">%</span>
                </div>
                <span class="text-xs text-gray-400">
                  + {{ formatMoney(precioFinal(item.id) - precioBase(item.id)) }}
                </span>
              </div>
              <div class="flex justify-between items-center pt-1 border-t border-gray-200">
                <span class="text-sm font-bold text-gray-700">Precio final</span>
                <span class="text-lg font-bold text-violet-700">{{ formatMoney(precioFinal(item.id)) }}</span>
              </div>
            </div>

            </template><!-- /modo desglose -->

            <button
              @click="guardar(item)"
              :disabled="guardando[item.id]"
              class="w-full bg-violet-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-violet-700 disabled:opacity-40 transition-colors flex items-center justify-center gap-2"
            >
              <CheckCircleIcon class="w-4 h-4" />
              {{ guardando[item.id] ? 'Guardando...' : 'Guardar precio' }}
            </button>
          </div>
        </template>

        <!-- Vista de solo lectura cuando ya está calculado -->
        <template v-else-if="item.estado === 'calculado'">
          <div class="border-t border-gray-100 pt-3 space-y-1.5">
            <p class="text-xs font-semibold text-gray-500 uppercase">Desglose calculado</p>
            <div class="space-y-1">
              <div
                v-for="d in item.desglose"
                :key="d.id"
                class="flex items-center justify-between text-xs text-gray-600"
              >
                <div class="flex items-center gap-1.5">
                  <span :class="['px-1.5 py-0.5 rounded font-medium', TIPO_COLOR[d.tipo]]">{{ TIPO_LABEL[d.tipo] }}</span>
                  <span>{{ d.nombre }}</span>
                  <span v-if="d.cantidad != 1" class="text-gray-400">× {{ d.cantidad }}</span>
                </div>
                <span class="font-medium text-gray-700">{{ formatMoney(d.subtotal) }}</span>
              </div>
            </div>
            <div class="flex justify-between text-sm pt-1 border-t border-gray-100">
              <span class="text-gray-500">Costo base</span>
              <span class="font-medium">{{ formatMoney(Number(item.precio_base)) }}</span>
            </div>
            <div class="flex justify-between text-sm">
              <span class="text-gray-500">Ganancia ({{ item.margen_ganancia_pct }}%)</span>
              <span class="font-medium">{{ formatMoney(Number(item.precio_final) - Number(item.precio_base)) }}</span>
            </div>
            <div class="flex justify-between text-sm font-bold">
              <span>Precio final</span>
              <span class="text-violet-700">{{ formatMoney(Number(item.precio_final)) }}</span>
            </div>
          </div>
        </template>
      </div>

      <!-- Botón enviar — solo receptor, todos calculados, consulta pendiente -->
      <div
        v-if="esReceptor && consulta.estado === 'pendiente'"
        class="bg-white rounded-xl shadow-sm p-4"
      >
        <p v-if="!todosCalculados" class="text-xs text-amber-600 bg-amber-50 rounded-lg px-3 py-2 mb-3">
          Calcula y guarda todos los ítems antes de enviar.
        </p>
        <button
          @click="enviar"
          :disabled="!todosCalculados || enviando"
          class="w-full bg-green-600 text-white rounded-xl py-3 text-sm font-bold hover:bg-green-700 disabled:opacity-40 transition-colors flex items-center justify-center gap-2"
        >
          <PaperAirplaneIcon class="w-5 h-5" />
          {{ enviando ? 'Enviando...' : 'Enviar precios al vendedor' }}
        </button>
      </div>

      <!-- Estado respondida para el vendedor -->
      <div v-if="consulta.estado === 'respondida'" class="bg-green-50 rounded-xl p-4 text-sm text-green-800 space-y-3">
        <div class="flex items-start gap-2">
          <CheckCircleIcon class="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" />
          <div>
            <p class="font-semibold">Cotización respondida</p>
            <p class="text-xs text-green-600 mt-0.5">
              Los precios fueron calculados. Ve a la orden para confirmar si el cliente acepta y registrar el anticipo.
            </p>
          </div>
        </div>
        <button
          v-if="consulta.solicitado_por_id === authStore.usuario?.id"
          @click="router.push({ name: 'orden-detalle', params: { id: consulta.orden_id } })"
          class="w-full bg-green-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-green-700 transition-colors flex items-center justify-center gap-2"
        >
          <CheckCircleIcon class="w-4 h-4" />
          Ir a la orden — confirmar con el cliente
        </button>
      </div>

      <!-- Chat -->
      <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-100">
          <ChatBubbleLeftEllipsisIcon class="w-4 h-4 text-gray-400" />
          <p class="text-xs font-semibold text-gray-600 uppercase">Mensajes</p>
        </div>

        <!-- Historial de mensajes -->
        <div class="px-4 py-3 space-y-3 max-h-72 overflow-y-auto">
          <p v-if="!mensajes.length" class="text-xs text-gray-400 text-center py-4">
            Sin mensajes aún. Usa el chat para aclarar dudas.
          </p>
          <div
            v-for="m in mensajes"
            :key="m.id"
            :class="['flex gap-2', m.usuario_id === authStore.usuario?.id ? 'flex-row-reverse' : '']"
          >
            <div
              :class="[
                'max-w-[75%] rounded-2xl px-3 py-2 text-sm',
                m.usuario_id === authStore.usuario?.id
                  ? 'bg-violet-600 text-white rounded-tr-sm'
                  : 'bg-gray-100 text-gray-800 rounded-tl-sm'
              ]"
            >
              <p v-if="m.usuario_id !== authStore.usuario?.id" class="text-xs font-semibold mb-0.5 text-violet-700">
                {{ m.usuario?.nombre }}
              </p>
              <p class="whitespace-pre-wrap break-words">{{ m.mensaje }}</p>
              <p :class="['text-[10px] mt-0.5', m.usuario_id === authStore.usuario?.id ? 'text-violet-200' : 'text-gray-400']">
                {{ formatFecha(m.created_at) }}
              </p>
            </div>
          </div>
        </div>

        <!-- Input -->
        <div class="px-4 py-3 border-t border-gray-100 flex gap-2">
          <textarea
            v-model="nuevoMensaje"
            @keydown="onMensajeKeydown"
            rows="1"
            placeholder="Escribe un mensaje… (Enter para enviar)"
            class="flex-1 rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 resize-none"
          />
          <button
            @click="doEnviarMensaje"
            :disabled="!nuevoMensaje.trim() || enviandoMensaje"
            class="bg-violet-600 text-white rounded-xl px-3 py-2 hover:bg-violet-700 disabled:opacity-40 transition-colors flex-shrink-0"
          >
            <PaperAirplaneIcon class="w-4 h-4" />
          </button>
        </div>
      </div>

    </template>
  </div>
</template>
