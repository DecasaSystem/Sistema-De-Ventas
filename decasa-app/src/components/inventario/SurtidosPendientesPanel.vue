<script setup>
import { ref, onMounted } from 'vue'
import { useSurtidosStore } from '@/stores/surtidos'
import { aceptarSurtido, rechazarSurtido } from '@/api/surtidos'
import { useToast } from '@/composables/useToast'
import {
  CheckCircleIcon,
  XCircleIcon,
  ArchiveBoxArrowDownIcon,
  ChevronDownIcon,
  ChevronUpIcon,
} from '@heroicons/vue/24/outline'

const emit     = defineEmits(['aceptado'])
const surtidos = useSurtidosStore()
const toast    = useToast()

const abiertos      = ref({})
const rechazando    = ref({})
const modalRechazar = ref(null)
const notasRechazar = ref('')
const rechazarLoad  = ref(false)

// Modal aceptar con cantidades por item
const modalAceptar  = ref(null)   // SurtidoTienda que se acepta
const cantidades    = ref({})     // { item.id: cantidad_aceptada }
const notasAceptar  = ref('')
const aceptarLoad   = ref(false)

onMounted(() => surtidos.cargarPendientes())

function toggleAbierto(id) {
  abiertos.value[id] = !abiertos.value[id]
}

function abrirAceptar(st) {
  modalAceptar.value = st
  notasAceptar.value = ''
  const map = {}
  for (const item of st.items ?? []) {
    map[item.id] = item.cantidad
  }
  cantidades.value = map
}

async function confirmarAceptar() {
  const st = modalAceptar.value
  if (!st) return
  if (!notasAceptar.value.trim()) {
    toast.error('La nota de recepción es obligatoria.')
    return
  }
  aceptarLoad.value = true
  try {
    const items = (st.items ?? []).map(item => ({
      id: item.id,
      cantidad_aceptada: cantidades.value[item.id] ?? item.cantidad,
    }))
    await aceptarSurtido(st.id, { items, notas_vendedor: notasAceptar.value.trim() })
    surtidos.quitarPendiente(st.id)
    emit('aceptado')
    toast.success(`Surtido #${st.surtido_id} aceptado. Inventario actualizado.`)
    modalAceptar.value = null
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al aceptar el surtido.')
  } finally {
    aceptarLoad.value = false
  }
}

function abrirRechazar(st) {
  modalRechazar.value = st
  notasRechazar.value = ''
}

async function confirmarRechazar() {
  if (!modalRechazar.value) return
  rechazarLoad.value = true
  try {
    await rechazarSurtido(modalRechazar.value.id, notasRechazar.value)
    surtidos.quitarPendiente(modalRechazar.value.id)
    toast.info(`Surtido #${modalRechazar.value.surtido_id} rechazado.`)
    modalRechazar.value = null
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al rechazar el surtido.')
  } finally {
    rechazarLoad.value = false
  }
}

function fmtEspecificaciones(esp) {
  if (!esp) return ''
  return Object.entries(esp)
    .filter(([, v]) => v)
    .map(([k, v]) => `${k}: ${v}`)
    .join(' · ')
}
</script>

<template>
  <div v-if="surtidos.pendientes.length > 0" class="space-y-3">

    <div class="flex items-center gap-2">
      <ArchiveBoxArrowDownIcon class="w-5 h-5 text-amber-500" />
      <h3 class="text-sm font-bold text-gray-800">
        Surtidos pendientes ({{ surtidos.pendientes.length }})
      </h3>
    </div>

    <div
      v-for="st in surtidos.pendientes"
      :key="st.id"
      class="bg-amber-50 border border-amber-200 rounded-xl overflow-hidden shadow-sm"
    >
      <!-- Cabecera -->
      <button
        @click="toggleAbierto(st.id)"
        class="w-full flex items-center justify-between px-4 py-3 text-left"
      >
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-gray-800">
            Surtido #{{ st.surtido_id }}
            <span class="ml-1 text-xs font-normal text-gray-500">de {{ st.surtido?.supervisor?.nombre }}</span>
          </p>
          <p class="text-xs text-gray-500 mt-0.5">
            {{ st.items?.length ?? 0 }} producto(s) · {{ st.tienda?.nombre }}
          </p>
        </div>
        <component
          :is="abiertos[st.id] ? ChevronUpIcon : ChevronDownIcon"
          class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2"
        />
      </button>

      <!-- Lista de productos (expandible) -->
      <Transition name="slide">
        <div v-if="abiertos[st.id]" class="border-t border-amber-100 px-4 pb-3 pt-2 space-y-2">
          <div
            v-for="item in st.items"
            :key="item.id"
            class="flex items-start gap-3 bg-white rounded-lg px-3 py-2"
          >
            <img
              v-if="item.producto?.foto_url"
              :src="item.producto.foto_url"
              class="w-9 h-9 rounded-lg object-cover flex-shrink-0"
            />
            <div class="w-9 h-9 rounded-lg bg-gray-100 flex-shrink-0" v-else />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ item.producto?.nombre }}</p>
              <p class="text-xs text-gray-500">
                {{ item.producto?.categoria }}
                <span v-if="fmtEspecificaciones(item.especificaciones)" class="ml-1 text-blue-600">
                  · {{ fmtEspecificaciones(item.especificaciones) }}
                </span>
              </p>
            </div>
            <span class="text-sm font-bold text-green-700 flex-shrink-0">+{{ item.cantidad }}</span>
          </div>
        </div>
      </Transition>

      <!-- Acciones -->
      <div class="flex gap-2 px-4 pb-3" :class="{ 'border-t border-amber-100 pt-3': !abiertos[st.id] }">
        <button
          @click="abrirAceptar(st)"
          class="flex-1 flex items-center justify-center gap-1.5 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 transition-colors"
        >
          <CheckCircleIcon class="w-4 h-4" />
          Confirmar recepción
        </button>
        <button
          @click="abrirRechazar(st)"
          class="px-4 flex items-center gap-1.5 border border-red-300 text-red-600 rounded-lg py-2.5 text-sm font-semibold hover:bg-red-50 transition-colors"
        >
          <XCircleIcon class="w-4 h-4" />
          Rechazar
        </button>
      </div>
    </div>
  </div>

  <!-- Modal confirmar recepción de surtido (por item) -->
  <Transition name="fade">
    <div
      v-if="modalAceptar"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
      @click.self="modalAceptar = null"
    >
      <div class="absolute inset-0 bg-black/40" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4 max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between flex-shrink-0">
          <h3 class="text-base font-bold text-gray-800">Confirmar recepción #{{ modalAceptar.surtido_id }}</h3>
          <button @click="modalAceptar = null" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>
        <p class="text-xs text-gray-500 flex-shrink-0">Ajusta la cantidad recibida. Pon 0 si un item no llegó o fue rechazado.</p>
        <div class="overflow-y-auto flex-1 space-y-2">
          <div
            v-for="item in modalAceptar.items"
            :key="item.id"
            class="flex items-center gap-3 bg-gray-50 rounded-lg px-3 py-2"
          >
            <img v-if="item.producto?.foto_url" :src="item.producto.foto_url" class="w-9 h-9 rounded-lg object-cover flex-shrink-0" />
            <div class="w-9 h-9 rounded-lg bg-gray-100 flex-shrink-0" v-else />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ item.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">
                Enviado: {{ item.cantidad }}
                <span v-if="fmtEspecificaciones(item.especificaciones)" class="text-blue-500 ml-1">
                  · {{ fmtEspecificaciones(item.especificaciones) }}
                </span>
              </p>
            </div>
            <input
              type="number"
              :min="0"
              :max="item.cantidad"
              v-model.number="cantidades[item.id]"
              class="w-16 rounded-lg border border-gray-300 text-center text-sm font-bold py-1.5 focus:outline-none focus:ring-2 focus:ring-green-500"
              :class="{ 'text-red-600 border-red-300': cantidades[item.id] < item.cantidad }"
            />
          </div>
        </div>
        <div class="flex-shrink-0 space-y-1">
          <label class="block text-sm font-semibold text-gray-700">
            Nota de recepción <span class="text-red-500">*</span>
          </label>
          <textarea
            v-model="notasAceptar"
            rows="3"
            placeholder="Describe el estado de los productos recibidos, novedades, cantidades, etc."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"
            :class="{ 'border-red-400': !notasAceptar.trim() && aceptarLoad === false }"
          />
          <p class="text-xs text-gray-400">Obligatoria — el supervisor verá esta nota.</p>
        </div>
        <div class="flex gap-2 flex-shrink-0">
          <button
            @click="modalAceptar = null"
            class="flex-1 border border-gray-300 text-gray-600 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-50"
          >
            Cancelar
          </button>
          <button
            @click="confirmarAceptar"
            :disabled="aceptarLoad || !notasAceptar.trim()"
            class="flex-1 bg-green-600 text-white rounded-lg py-2.5 text-sm font-bold hover:bg-green-700 disabled:opacity-50"
          >
            {{ aceptarLoad ? 'Guardando...' : 'Confirmar' }}
          </button>
        </div>
      </div>
    </div>
  </Transition>

  <!-- Modal rechazar -->
  <Transition name="fade">
    <div
      v-if="modalRechazar"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
      @click.self="modalRechazar = null"
    >
      <div class="absolute inset-0 bg-black/40" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-base font-bold text-gray-800">Rechazar surtido #{{ modalRechazar.surtido_id }}</h3>
          <button @click="modalRechazar = null" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
          <textarea
            v-model="notasRechazar"
            rows="3"
            placeholder="Ej: Los productos no llegaron completos..."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 resize-none"
          />
        </div>
        <div class="flex gap-2">
          <button
            @click="modalRechazar = null"
            class="flex-1 border border-gray-300 text-gray-600 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-50"
          >
            Cancelar
          </button>
          <button
            @click="confirmarRechazar"
            :disabled="rechazarLoad"
            class="flex-1 bg-red-600 text-white rounded-lg py-2.5 text-sm font-bold hover:bg-red-700 disabled:opacity-50"
          >
            {{ rechazarLoad ? 'Rechazando...' : 'Confirmar rechazo' }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: all 0.18s ease; }
.slide-enter-from, .slide-leave-to       { opacity: 0; transform: translateY(-6px); }
.fade-enter-active, .fade-leave-active   { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to         { opacity: 0; }
</style>
