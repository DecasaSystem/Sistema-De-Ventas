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
const aceptando     = ref({})
const rechazando    = ref({})
const modalRechazar = ref(null)   // { stId, notasRechazar }
const notasRechazar = ref('')
const rechazarLoad  = ref(false)

onMounted(() => surtidos.cargarPendientes())

function toggleAbierto(id) {
  abiertos.value[id] = !abiertos.value[id]
}

async function aceptar(st) {
  aceptando.value[st.id] = true
  try {
    await aceptarSurtido(st.id)
    surtidos.quitarPendiente(st.id)
    emit('aceptado')
    toast.success(`Surtido #${st.surtido_id} aceptado. Inventario actualizado.`)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Error al aceptar el surtido.')
  } finally {
    aceptando.value[st.id] = false
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
          @click="aceptar(st)"
          :disabled="aceptando[st.id]"
          class="flex-1 flex items-center justify-center gap-1.5 bg-green-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-green-700 disabled:opacity-50 transition-colors"
        >
          <CheckCircleIcon class="w-4 h-4" />
          {{ aceptando[st.id] ? 'Aceptando...' : 'Aceptar todo' }}
        </button>
        <button
          @click="abrirRechazar(st)"
          :disabled="aceptando[st.id]"
          class="px-4 flex items-center gap-1.5 border border-red-300 text-red-600 rounded-lg py-2.5 text-sm font-semibold hover:bg-red-50 disabled:opacity-50 transition-colors"
        >
          <XCircleIcon class="w-4 h-4" />
          Rechazar
        </button>
      </div>
    </div>
  </div>

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
