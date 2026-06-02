<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { getConsulta, guardarItem, enviarConsulta } from '@/api/consultas'
import {
  SparklesIcon, PlusIcon, TrashIcon, CheckCircleIcon,
  ClipboardDocumentCheckIcon, ArrowDownTrayIcon, PaperAirplaneIcon,
} from '@heroicons/vue/24/outline'

const route     = useRoute()
const router    = useRouter()
const toast     = useToast()
const authStore = useAuthStore()

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

function inicializarFormulario(item) {
  const desglose = (item.desglose ?? []).map(d => ({
    tipo:            d.tipo,
    nombre:          d.nombre,
    cantidad:        parseFloat(d.cantidad),
    precio_unitario: parseFloat(d.precio_unitario),
  }))

  formularios.value[item.id] = {
    desglose:            desglose.length ? desglose : [],
    margen_ganancia_pct: item.margen_ganancia_pct ?? 0,
  }
}

function agregarFila(itemId, tipo = 'material') {
  formularios.value[itemId].desglose.push({
    tipo,
    nombre:          '',
    cantidad:        1,
    precio_unitario: 0,
  })
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
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
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
  if (!form || form.desglose.length === 0) {
    toast.error('Agrega al menos una fila al desglose.')
    return
  }

  for (const fila of form.desglose) {
    if (!fila.nombre.trim()) {
      toast.error('Completa el nombre en todas las filas.')
      return
    }
  }

  guardando.value[item.id] = true
  try {
    const { data } = await guardarItem(consulta.value.id, item.id, {
      margen_ganancia_pct: form.margen_ganancia_pct,
      desglose: form.desglose,
    })
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

onMounted(cargar)
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

    <template v-else-if="consulta">

      <!-- Info de la orden -->
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
                  <div class="col-span-3">
                    <input
                      v-model="fila.nombre"
                      type="text"
                      :placeholder="fila.tipo === 'material' ? 'Nombre del material' : 'Nombre del trabajador'"
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

            <button
              @click="guardar(item)"
              :disabled="guardando[item.id] || formularios[item.id]?.desglose.length === 0"
              class="w-full bg-violet-600 text-white rounded-xl py-2.5 text-sm font-bold hover:bg-violet-700 disabled:opacity-40 transition-colors flex items-center justify-center gap-2"
            >
              <CheckCircleIcon class="w-4 h-4" />
              {{ guardando[item.id] ? 'Guardando...' : 'Guardar cálculo' }}
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
              <span class="font-medium">{{ formatMoney(item.precio_base) }}</span>
            </div>
            <div class="flex justify-between text-sm">
              <span class="text-gray-500">Ganancia ({{ item.margen_ganancia_pct }}%)</span>
              <span class="font-medium">{{ formatMoney(item.precio_final - item.precio_base) }}</span>
            </div>
            <div class="flex justify-between text-sm font-bold">
              <span>Precio final</span>
              <span class="text-violet-700">{{ formatMoney(item.precio_final) }}</span>
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
      <div v-if="consulta.estado === 'respondida'" class="bg-green-50 rounded-xl p-4 text-sm text-green-800 flex items-start gap-2">
        <CheckCircleIcon class="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" />
        <div>
          <p class="font-semibold">Cotización respondida</p>
          <p class="text-xs text-green-600 mt-0.5">
            Los precios fueron actualizados en la orden.
            Puedes revisar y finalizar la orden cuando quieras.
          </p>
        </div>
      </div>

    </template>
  </div>
</template>
